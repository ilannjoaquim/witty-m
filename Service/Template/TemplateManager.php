<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Entity\WittyTemplateRepository;

/**
 * Proprietaire des templates d'email et de landing page geres depuis la
 * section Witty > Templates (cf. Controller/TemplateController.php). Source
 * unique consommee par EmailTemplateLibrary/PageTemplateLibrary : plus aucune
 * lecture de Templates/Email|Page/ a l'execution (cf. BuiltInTemplateLoader,
 * utilise uniquement par la migration d'installation et les tests).
 *
 * Partages entre tous les utilisateurs de l'instance, comme WittySkill.
 */
class TemplateManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
    ) {
    }

    /**
     * @return WittyTemplate[]
     */
    public function listAll(): array
    {
        return $this->getRepository()->findAllOrdered();
    }

    /**
     * @return WittyTemplate[]
     */
    public function listByType(string $type): array
    {
        return $this->getRepository()->findByTypeOrdered($type);
    }

    public function find(int $id): ?WittyTemplate
    {
        return $this->getRepository()->find($id);
    }

    public function findByTypeAndKey(string $type, string $key): ?WittyTemplate
    {
        return $this->getRepository()->findOneByTypeAndKey($type, $key);
    }

    /**
     * Attribue une cle si absente (creation depuis l'UI, ou template dont
     * l'utilisateur n'a pas renseigne la cle) : derivee du nom, en garantissant
     * l'unicite au sein du type puisque c'est cette paire (type, cle) que
     * l'agent utilise pour retrouver le template (cf. list_email_templates /
     * create_email_from_template et leurs equivalents page).
     */
    public function save(WittyTemplate $template): void
    {
        $isNew = null === $template->getId();

        if ('' === $template->getKey()) {
            $template->setKey($this->uniqueKey($template->getType(), $this->slugify($template->getName())));
        }

        if ($isNew) {
            $user = $this->userHelper->getUser();

            if (null !== $user && null !== $user->getId()) {
                $template->setCreatedBy($user);
            }
        } else {
            $template->touch();
        }

        $this->entityManager->persist($template);
        $this->entityManager->flush();
    }

    public function delete(WittyTemplate $template): void
    {
        $this->entityManager->remove($template);
        $this->entityManager->flush();
    }

    /**
     * Nettoie une liste de regles (une ligne = une regle) : partage entre
     * CreateTemplateTool/UpdateTemplateTool (rules deja en tableau, cote agent)
     * et Controller/TemplateController.php (texte multi-lignes cote UI,
     * decoupe avant d'appeler ceci).
     *
     * @param array<int, mixed> $rules
     *
     * @return array<int, string>
     */
    public static function normalizeRules(array $rules): array
    {
        return array_values(array_filter(
            array_map(static fn ($rule): string => trim((string) $rule), $rules),
            static fn (string $rule): bool => '' !== $rule,
        ));
    }

    /** Champs reellement stockes pour un emplacement — tout le reste est ignore a l'ecriture. */
    private const PLACEHOLDER_FIELDS = ['key', 'label', 'guidance', 'example', 'default', 'context'];

    /**
     * Normalise (cle en majuscules) et valide les emplacements. Meme usage
     * partage que normalizeRules().
     *
     * Ne garde QUE les champs de PLACEHOLDER_FIELDS, meme si l'appelant en
     * fournit d'autres : defense en profondeur contre un aller-retour
     * lecture/ecriture qui recopierait tel quel un champ calcule a la
     * lecture (ex. 'required', renvoye par WittyTemplate::describePlaceholders()
     * mais jamais stocke — le persister tel quel finirait par diverger de sa
     * vraie valeur des qu'un 'default' est ajoute/retire ensuite, puisque
     * rien ne le relit jamais depuis le stockage). 'context' reste explicite
     * ('html' si absent) plutot qu'omis, pour la meme raison que
     * describePlaceholders() l'inclut desormais toujours : un contexte
     * js/html_br qui redeviendrait implicite se perdrait au prochain aller-retour.
     *
     * @param array<int, mixed> $placeholders
     *
     * @return array<int, array<string, mixed>>|string un message d erreur si un emplacement n a pas de cle
     */
    public static function normalizePlaceholders(array $placeholders): array|string
    {
        $normalized = [];

        foreach ($placeholders as $placeholder) {
            if (!is_array($placeholder) || '' === trim((string) ($placeholder['key'] ?? ''))) {
                return 'Chaque emplacement doit avoir une cle (key).';
            }

            $clean = array_intersect_key($placeholder, array_flip(self::PLACEHOLDER_FIELDS));

            $context = (string) ($placeholder['context'] ?? 'html');

            $clean['key']     = strtoupper(trim((string) $placeholder['key']));
            $clean['context'] = in_array($context, ['html', 'html_br', 'js'], true) ? $context : 'html';

            $normalized[] = $clean;
        }

        return $normalized;
    }

    private function uniqueKey(string $type, string $base): string
    {
        $base = '' !== $base ? $base : 'template';
        $key  = $base;
        $i    = 2;

        while ($this->getRepository()->keyExists($type, $key)) {
            $key = $base.'-'.$i;
            ++$i;
        }

        return $key;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }

    private function getRepository(): WittyTemplateRepository
    {
        /** @var WittyTemplateRepository $repository */
        $repository = $this->entityManager->getRepository(WittyTemplate::class);

        return $repository;
    }
}
