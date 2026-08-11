<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Psr\Log\LoggerInterface;

/**
 * Remplace une chaine exacte (ex. une URL de logo placeholder) dans le HTML
 * d'un email ou d'une landing page, QUEL QUE SOIT son mode (code source ou
 * theme visuel/MJML) — contrairement a update_entity_content, qui refuse un
 * remplacement integral hors mode code source.
 *
 * Cette restriction n'a pas lieu d'etre pour un remplacement chirurgical :
 * `Email::customHtml` est ce qui part reellement au destinataire dans TOUS
 * les cas (MailHelper::setEmail() ne retombe sur le rendu theme+blocs que si
 * customHtml est vide, un cas devenu quasi inexistant — voir la meme logique
 * pour Page). Le seul risque residuel d'un remplacement integral (cf.
 * update_entity_content) est de diverger fortement de ce qu'un humain
 * reverrait en rouvrant l'editeur visuel/MJML ; un remplacement ponctuel de
 * quelques caracteres est un risque nettement plus faible, et directement
 * utile (ex. remplacer une URL de logo placeholder dans plusieurs emails
 * issus de create_email_from_template avant l'ajout d'update_entity_content).
 *
 * Chaque appel remplace TOUTES les occurrences de `search` (str_replace est
 * global), pas une seule : c'est ce qui rend cet outil capable d'une refonte
 * visuelle COMPLETE d'un email/page en theme visuel/MJML, pas seulement d'une
 * micro-retouche — le HTML compile par MJML repete ses styles en inline sur
 * chaque element plutot que dans une seule feuille <style> centralisee
 * (contrairement au mode code source), donc une refonte s'obtient en
 * plusieurs appels d'affilee, un par valeur de design distincte (l'ancienne
 * couleur -> la nouvelle, l'ancien font-family -> le nouveau, etc.), chacun
 * touchant d'un coup toutes les occurrences de cette valeur dans le document.
 *
 * Pour un email avec une source MJML enregistree (plugin GrapesJS,
 * Entity/GrapesJsBuilder, cf. l'historique de create_email_from_template) : le
 * meme remplacement est aussi applique a cette source, pour que le builder
 * MJML ne redevienne pas perime a la prochaine ouverture. Best effort : si le
 * plugin GrapesJS n'est pas installe ou qu'aucune source n'est enregistree
 * pour cet email, ignore silencieusement cette partie (le HTML reel, ce qui
 * part au destinataire, est deja corrige).
 */
class ReplaceEntityContentTextTool extends AbstractTool
{
    private const TYPES = ['email', 'page'];

    public function __construct(
        private EntityCatalog $catalog,
        private WittyConfig $config,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'replace_entity_content_text';
    }

    public function getDescription(): string
    {
        return 'Remplace une chaine exacte (ex. une URL, une couleur, un font-family, un border-radius) dans le HTML '
            .'d un email ou d une landing page existant, quel que soit son mode (code source ou theme visuel/MJML) — '
            .'contrairement a update_entity_content, qui ne fonctionne qu en mode code source. Chaque appel remplace '
            .'TOUTES les occurrences de la chaine. Utile pour une retouche ponctuelle (une URL, une date) mais aussi '
            .'pour une refonte visuelle complete d un email/page en theme visuel/MJML : plusieurs appels d affilee, un '
            .'par valeur de design distincte (couleur, police, rayon, espacement...), plutot qu un seul remplacement '
            .'integral impossible dans ce mode. Appeler d abord read_entity_content pour reperer les chaines exactes '
            .'a remplacer.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'entity';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'type'    => ['type' => 'string', 'enum' => self::TYPES],
            'id'      => ['type' => 'integer', 'description' => 'Identifiant de l objet.'],
            'search'  => ['type' => 'string', 'description' => 'Chaine exacte a rechercher dans le HTML actuel.'],
            'replace' => ['type' => 'string', 'description' => 'Chaine de remplacement (vide pour supprimer search).'],
        ], ['type', 'id', 'search']);
    }

    public function execute(array $arguments): array
    {
        $type    = (string) ($arguments['type'] ?? '');
        $id      = (int) ($arguments['id'] ?? 0);
        $search  = (string) ($arguments['search'] ?? '');
        $replace = (string) ($arguments['replace'] ?? '');

        if (!in_array($type, self::TYPES, true)) {
            return ['status' => 'error', 'error' => sprintf('Type inconnu : %s. Types acceptes : %s', $type, implode(', ', self::TYPES))];
        }

        if ('' === $search) {
            return ['status' => 'error', 'error' => 'search est obligatoire.'];
        }

        $model  = $this->catalog->getModel($type);
        $entity = $model?->getEntity($id);

        if (null === $entity || !method_exists($entity, 'getCustomHtml') || !method_exists($entity, 'setCustomHtml')) {
            return ['status' => 'error', 'error' => sprintf('%s #%d introuvable.', $type, $id)];
        }

        if (!$this->catalog->isAllowed($type, 'edit', $entity)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de modification refusee sur %s #%d.', $type, $id)];
        }

        $html        = (string) $entity->getCustomHtml();
        $occurrences = substr_count($html, $search);

        if (0 === $occurrences) {
            return [
                'status' => 'error',
                'error'  => 'Aucune occurrence de search dans le HTML actuel. Relire avec read_entity_content pour verifier la chaine exacte.',
            ];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'        => $type,
                'id'          => $id,
                'objet'       => $this->catalog->describe($entity),
                'occurrences' => $occurrences,
                'search'      => $search,
                'replace'     => $replace,
            ]);
        }

        $entity->setCustomHtml(str_replace($search, $replace, $html));
        $model->saveEntity($entity);

        $mjmlSynced = 'email' === $type ? $this->syncMjmlSource($entity, $search, $replace) : false;

        return $this->ok([
            'id'          => $id,
            'type'        => $type,
            'occurrences' => $occurrences,
            'url'         => $this->catalog->getUrl($type, $id),
            'mjml_synced' => $mjmlSynced,
        ]);
    }

    /**
     * Meme remplacement dans Entity/GrapesJsBuilder::customMjml si une source
     * MJML est enregistree pour cet email : evite que le builder MJML rouvre
     * sur une version perimee apres un remplacement fait ici. Best effort,
     * jamais bloquant (le HTML reel est deja corrige a ce stade).
     */
    private function syncMjmlSource(object $email, string $search, string $replace): bool
    {
        $entityClass = 'MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder';

        if (!class_exists($entityClass)) {
            return false;
        }

        try {
            $builder = $this->entityManager->getRepository($entityClass)->findOneBy(['email' => $email]);

            if (null === $builder) {
                return false;
            }

            $mjml = (string) $builder->getCustomMjml();

            if (!str_contains($mjml, $search)) {
                return false;
            }

            $builder->setCustomMjml(str_replace($search, $replace, $mjml));
            $this->entityManager->persist($builder);
            $this->entityManager->flush();

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Witty : source MJML non synchronisee apres remplacement', ['exception' => $e]);

            return false;
        }
    }
}
