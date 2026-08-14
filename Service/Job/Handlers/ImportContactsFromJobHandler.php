<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Service\Contact\ContactImporter;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;
use MauticPlugin\WittyBundle\Service\Job\JobItemFilter;

/**
 * Convertit les resultats d'un job de recherche DEJA TERMINE (Apollo,
 * QuickEnrich, MCP...) en contacts Mautic, par lots, sans jamais faire
 * repasser les donnees brutes par le modele — c'est precisement ce qui
 * permet de traiter des volumes que start_bulk_create_contacts ne pourrait
 * pas absorber (l agent devrait sinon recopier chaque profil en argument
 * d appel, hors de portee au-dela de quelques centaines d entrees).
 *
 * `mapping` et `filters` sont DECLARATIFS (cf. JobItemFilter) plutot que du
 * code fourni par l agent : un choix de securite assume, pas une limitation
 * technique — voir la discussion produit qui a mene a cet outil plutot qu a
 * un "generateur de script PHP execute en tache de fond".
 *
 * Ne lit que les elements STATUS_SUCCEEDED du job source : un element deja
 * en echec cote recherche (ex. aucune correspondance Apollo) n a rien a
 * apporter a un import de contacts.
 *
 * Ne lit que les elements PAS ENCORE consommes (`consumedAt IS NULL`) : un
 * meme job source peut faire l objet de PLUSIEURS imports successifs (ex.
 * import d abord les 10 000 premiers resultats obtenus avant un plantage
 * fournisseur, puis resume_bulk_job fait grossir le job source de 13 600
 * resultats supplementaires, puis un second import cible seulement ceux-la).
 * Sans ce marquage, un deuxieme import du meme job source relirait TOUS ses
 * elements succeeded depuis le debut (offset 0), y compris ceux deja
 * importes — inoffensif pour un rapprochement par id (updateById() re-ecrit
 * juste les memes valeurs) mais createur de VRAIS doublons pour un
 * rapprochement par email sans email (importOne() cree systematiquement
 * quand aucun email n est fourni a dedoublonner). Marque consomme UNIQUEMENT
 * au moment ou l element est reellement transmis a l importeur (pas pour un
 * element ecarte par un filtre ou sans champ mappable : un futur import avec
 * un mapping/des filtres differents doit encore pouvoir le reconsiderer).
 *
 * Deux modes de rapprochement, choisis AUTOMATIQUEMENT selon le type du job
 * source (jamais laisse a l appreciation de l agent, pour eviter l erreur) :
 * - job de RECHERCHE (Prospeo/QuickEnrich/MCP) : les resultats sont des
 *   profils externes, pas encore des contacts Mautic -> dedoublonnage par
 *   email, creation si aucun match (ContactImporter::importOne()).
 * - job d ENRICHISSEMENT sur un segment existant
 *   (ApolloBulkEnrichPeopleJobHandler::TYPE) : `external_ref` porte deja
 *   l id exact du contact Mautic concerne -> mise a jour PAR ID, jamais de
 *   recherche par email ni de creation (ContactImporter::updateById()) — un
 *   enrichissement met a jour un contact qui existe deja, par definition.
 */
class ImportContactsFromJobHandler implements JobHandlerInterface
{
    public const TYPE = 'import_contacts_from_job';

    private const BATCH_SIZE = 50;

    /** Types de job source dont external_ref est un id de contact Mautic, pas un profil externe. */
    private const CONTACT_ID_MATCHED_SOURCE_TYPES = [ApolloBulkEnrichPeopleJobHandler::TYPE];

    public function __construct(
        private ContactImporter $importer,
        private ListModel $listModel,
        private EntityManagerInterface $em,
        private FieldWriteGuard $fieldWriteGuard,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function allowsMultiplePassesPerTick(): bool
    {
        // Aucun appel API externe ici (donnees deja stockees en base par le
        // job de recherche source) : uniquement des ecritures Mautic
        // internes. Command/ProcessBackgroundJobsCommand.php peut donc
        // enchainer plusieurs lots de 50 sur ce meme job tant qu il reste du
        // budget de temps, comme Mautic le fait lui-meme pour un import CSV
        // (LeadBundle\Model\ImportModel::process(), aucune coupure de temps).
        return true;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $params       = $job->getParams();
        $sourceJobId  = (int) ($params['source_job_id'] ?? 0);
        $mapping      = (array) ($params['mapping'] ?? []);
        $filters      = (array) ($params['filters'] ?? []);
        $segmentId    = (int) ($params['segment_id'] ?? 0);

        /** @var WittyBackgroundJobItemRepository $sourceRepository */
        $sourceRepository = $this->em->getRepository(WittyBackgroundJobItem::class);
        // Toujours offset 0 : "pas encore consomme" EST le curseur de reprise
        // ici, un element traite ne repasse jamais dans le lot suivant, que ce
        // soit au sein de ce job ou d un import ulterieur du meme job source.
        $sourceItems = $sourceRepository->findForJob($sourceJobId, WittyBackgroundJobItem::STATUS_SUCCEEDED, self::BATCH_SIZE, 0, true);

        if ([] === $sourceItems) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

            return;
        }

        $sourceJob      = $this->em->getRepository(WittyBackgroundJob::class)->find($sourceJobId);
        $matchByLeadId  = $sourceJob instanceof WittyBackgroundJob && in_array($sourceJob->getType(), self::CONTACT_ID_MATCHED_SOURCE_TYPES, true);
        $segment        = $segmentId > 0 ? $this->listModel->getEntity($segmentId) : null;

        foreach ($sourceItems as $sourceItem) {
            $data = $sourceItem->getData() ?? [];

            if (!JobItemFilter::matchesAll($data, $filters)) {
                $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Ecarte par les filtres.');
                continue;
            }

            $fields = $this->applyMapping($data, $mapping);
            $fields = $this->fieldWriteGuard->prepare($fields, 'lead')['fields'];

            if ([] === $fields) {
                $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Aucun champ mappable (mapping/donnees incompatibles).');
                continue;
            }

            $segmentArg = $segment instanceof LeadList ? $segment : null;

            // A partir d ici, l element est reellement transmis a l importeur :
            // marque consomme quoi qu il arrive, pour qu il ne soit plus jamais
            // repropose a un futur import de ce meme job source.
            $sourceItem->setConsumedAt(new \DateTimeImmutable());
            $this->em->persist($sourceItem);

            if ($matchByLeadId) {
                $leadId = (int) $sourceItem->getExternalRef();
                $lead   = $this->importer->updateById($leadId, $fields, $segmentArg);

                if (null === $lead) {
                    $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_FAILED, null, sprintf('Contact #%d introuvable (supprime depuis ?).', $leadId));
                    continue;
                }

                $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_SUCCEEDED, [
                    'contact_id' => $lead->getId(),
                    'created'    => false,
                ]);
                continue;
            }

            $result = $this->importer->importOne($fields, $segmentArg);

            $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_SUCCEEDED, [
                'contact_id' => $result['lead']->getId(),
                'created'    => $result['created'],
            ]);
        }

        $job->setProcessedItems($job->getProcessedItems() + count($sourceItems));

        if (count($sourceItems) < self::BATCH_SIZE) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $mapping alias de champ contact Mautic -> chemin (notation pointee) dans $data
     *
     * @return array<string, string>
     */
    private function applyMapping(array $data, array $mapping): array
    {
        $fields = [];

        foreach ($mapping as $alias => $path) {
            $value = JobItemFilter::resolvePath($data, (string) $path);

            if (null === $value) {
                continue;
            }

            $value = trim((string) $value);

            if ('' !== $value) {
                $fields[(string) $alias] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function recordItem(WittyBackgroundJob $job, string $externalRef, string $status, ?array $data, ?string $error = null): void
    {
        $item = (new WittyBackgroundJobItem())
            ->setJob($job)
            ->setExternalRef($externalRef)
            ->setStatus($status)
            ->setData($data)
            ->setErrorMessage($error);

        $this->em->persist($item);

        if (WittyBackgroundJobItem::STATUS_SUCCEEDED === $status) {
            $job->setSucceededItems($job->getSucceededItems() + 1);
        } else {
            $job->setFailedItems($job->getFailedItems() + 1);
        }
    }
}
