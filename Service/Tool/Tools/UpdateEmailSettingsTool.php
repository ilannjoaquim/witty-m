<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Modifie les reglages d un email DEJA cree qu aucun autre outil ne couvre :
 * expediteur (from_name, from_address, reply_to_address, bcc_address,
 * use_owner_as_mailer), objet, pre-header, tags UTM, texte brut alternatif,
 * fenetre de publication (publish_up/publish_down). Volontairement un SEUL
 * outil pour tout ca plutot qu un par groupe de champs (ex. un
 * update_email_sender separe) : plusieurs petits outils aux frontieres
 * proches auraient ete une source de confusion supplementaire pour le
 * modele, pas une clarification.
 *
 * update_entity (generique, partage par tous les types du catalogue) ne
 * connait aucun de ces champs : setName()/setDescription()/setIsPublished()/
 * setCategory() existent sur tous les types, pas
 * setFromAddress()/setSubject()/setUtmTags()/etc., propres a Email. Le
 * contenu HTML reste hors de portee ici aussi : read_entity_content/
 * update_entity_content/replace_entity_content_text s en chargent deja.
 */
class UpdateEmailSettingsTool extends AbstractTool
{
    /** Champs texte simples : chaine vide => null (efface le champ), jamais une chaine vide litterale. */
    private const BLANKABLE_TEXT_FIELDS = [
        'from_name'        => ['setter' => 'setFromName', 'getter' => 'getFromName'],
        'from_address'      => ['setter' => 'setFromAddress', 'getter' => 'getFromAddress'],
        'reply_to_address'  => ['setter' => 'setReplyToAddress', 'getter' => 'getReplyToAddress'],
        'bcc_address'       => ['setter' => 'setBccAddress', 'getter' => 'getBccAddress'],
        'preheader_text'    => ['setter' => 'setPreheaderText', 'getter' => 'getPreheaderText'],
        'plain_text'        => ['setter' => 'setPlainText', 'getter' => 'getPlainText'],
    ];

    public function __construct(
        private EntityCatalog $catalog,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'update_email_settings';
    }

    public function getDescription(): string
    {
        return 'Modifie les reglages d un email deja cree, hors contenu HTML (read_entity_content/'
            .'update_entity_content/replace_entity_content_text pour ca) et hors nom/description/publication/'
            .'categorie (update_entity pour ca) : expediteur (from_name, from_address, reply_to_address, '
            .'bcc_address, use_owner_as_mailer — si actif, ignore from_name/from_address au profit du proprietaire '
            .'du contact), subject (objet, ne peut pas etre vide), preheader_text, utm_tags (objet cle/valeur, '
            .'remplace tous les tags existants — objet vide {} pour tous les retirer), plain_text, publish_up/'
            .'publish_down (fenetre de publication, format "YYYY-MM-DD HH:MM:SS", chaine vide pour retirer). '
            .'Utiliser list_entities(entity=email) au prealable pour recuperer l identifiant.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'email:emails:editown';
    }

    public function getObjectType(): ?string
    {
        return 'email';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'id'                   => ['type' => 'integer', 'description' => 'Identifiant de l email.'],
            'from_name'            => ['type' => 'string'],
            'from_address'         => ['type' => 'string'],
            'reply_to_address'     => ['type' => 'string'],
            'bcc_address'          => ['type' => 'string'],
            'use_owner_as_mailer'  => ['type' => 'boolean', 'description' => 'Si actif, ignore from_name/from_address au profit du proprietaire assigne au contact.'],
            'subject'              => ['type' => 'string', 'description' => 'Objet de l email, ne peut pas etre vide.'],
            'preheader_text'       => ['type' => 'string'],
            'utm_tags'             => ['type' => 'object', 'description' => 'Remplace tous les tags UTM existants (objet vide {} pour tous les retirer).'],
            'plain_text'           => ['type' => 'string', 'description' => 'Alternative texte brut. Genere automatiquement par Mautic si jamais renseigne.'],
            'publish_up'           => ['type' => 'string', 'description' => 'Format "YYYY-MM-DD HH:MM:SS". Chaine vide pour retirer.'],
            'publish_down'         => ['type' => 'string', 'description' => 'Format "YYYY-MM-DD HH:MM:SS". Chaine vide pour retirer.'],
        ], ['id']);
    }

    public function execute(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);

        $model = $this->catalog->getModel('email');
        $email = $model?->getEntity($id);

        if (!$email instanceof Email) {
            return ['status' => 'error', 'error' => sprintf('Email #%d introuvable.', $id)];
        }

        if (!$this->catalog->isAllowed('email', 'edit', $email)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de modification refusee sur email #%d.', $id)];
        }

        $changes = [];

        foreach (self::BLANKABLE_TEXT_FIELDS as $key => $accessors) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }

            $value    = trim((string) $arguments[$key]);
            $previous = (string) ($email->{$accessors['getter']}() ?? '');

            if ($value !== $previous) {
                $changes[$key] = ['de' => $previous, 'vers' => $value];
            }
        }

        if (array_key_exists('subject', $arguments)) {
            $subject = trim((string) $arguments['subject']);

            if ('' === $subject) {
                return ['status' => 'error', 'error' => 'subject ne peut pas etre vide.'];
            }

            $previous = (string) ($email->getSubject() ?? '');

            if ($subject !== $previous) {
                $changes['subject'] = ['de' => $previous, 'vers' => $subject];
            }
        }

        if (array_key_exists('use_owner_as_mailer', $arguments)) {
            $value    = (bool) $arguments['use_owner_as_mailer'];
            $previous = (bool) $email->getUseOwnerAsMailer();

            if ($value !== $previous) {
                $changes['use_owner_as_mailer'] = ['de' => $previous, 'vers' => $value];
            }
        }

        if (array_key_exists('utm_tags', $arguments)) {
            $value = is_array($arguments['utm_tags']) ? $arguments['utm_tags'] : [];
            ksort($value);

            $previous = (array) ($email->getUtmTags() ?? []);
            ksort($previous);

            if ($value !== $previous) {
                $changes['utm_tags'] = ['de' => $previous, 'vers' => $value];
            }
        }

        foreach (['publish_up' => 'setPublishUp', 'publish_down' => 'setPublishDown'] as $key => $setter) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }

            $raw = trim((string) $arguments[$key]);

            if ('' === $raw) {
                $changes[$key] = ['vers' => null, 'setter' => $setter];
                continue;
            }

            try {
                $date = new \DateTime($raw);
            } catch (\Exception) {
                return ['status' => 'error', 'error' => sprintf('%s : date invalide (%s). Format attendu "YYYY-MM-DD HH:MM:SS".', $key, $raw)];
            }

            $changes[$key] = ['vers' => $date, 'setter' => $setter, 'label' => $date->format('Y-m-d H:i:s')];
        }

        if ([] === $changes) {
            return ['status' => 'error', 'error' => 'Aucune modification demandee ou les valeurs sont identiques aux actuelles.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'email',
                'id'      => $id,
                'objet'   => $this->catalog->describe($email),
                'changes' => array_map(
                    static fn (array $change): array => array_diff_key($change, ['setter' => true]),
                    $changes,
                ),
            ]);
        }

        foreach (self::BLANKABLE_TEXT_FIELDS as $key => $accessors) {
            if (isset($changes[$key])) {
                $email->{$accessors['setter']}('' !== $changes[$key]['vers'] ? $changes[$key]['vers'] : null);
            }
        }

        if (isset($changes['subject'])) {
            $email->setSubject($changes['subject']['vers']);
        }

        if (isset($changes['use_owner_as_mailer'])) {
            $email->setUseOwnerAsMailer($changes['use_owner_as_mailer']['vers']);
        }

        if (isset($changes['utm_tags'])) {
            $email->setUtmTags($changes['utm_tags']['vers']);
        }

        foreach (['publish_up', 'publish_down'] as $key) {
            if (isset($changes[$key])) {
                $email->{$changes[$key]['setter']}($changes[$key]['vers']);
            }
        }

        $model->saveEntity($email);

        return $this->ok([
            'id'      => $id,
            'name'    => $this->catalog->describe($email),
            'changes' => array_keys($changes),
            'url'     => $this->catalog->getUrl('email', $id),
        ]);
    }
}
