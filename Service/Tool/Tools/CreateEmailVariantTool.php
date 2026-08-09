<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Ajoute une variante (test A/B) a un email existant.
 *
 * create_email cree des emails independants : sans ce tool, "cree deux
 * variantes d'un email et teste-les" produisait deux emails sans aucun lien,
 * jamais le vrai test A/B natif de Mautic (Email::variantParent/variantChildren,
 * cf. Entity\VariantEntityTrait cote core) — l'onglet A/B Test de la fiche
 * email restait vide et l'envoi ne repartissait jamais le trafic entre elles.
 *
 * Reproduit exactement ce que fait EmailController::abtestAction() (bouton
 * "Creer un test A/B" de l'interface) : un nouvel Email dont variantParent
 * pointe vers l'email d'origine, avec son propre variantSettings (weight,
 * winnerCriteria).
 */
class CreateEmailVariantTool extends AbstractTool
{
    /**
     * Cote Mautic core, les criteres de determination du gagnant sont
     * enregistres par bundle sur l'evenement EMAIL_ON_BUILD (cf.
     * EmailBundle/EventListener/BuilderSubscriber.php,
     * AssetBundle/EventListener/EmailSubscriber.php,
     * FormBundle/EventListener/EmailSubscriber.php) : liste figee ici plutot
     * que decouverte en direct, ces evenements ne sont pas exposes en dehors
     * du cycle de build du formulaire d'edition.
     */
    private const WINNER_CRITERIA = ['email.openrate', 'email.clickthrough', 'asset.downloads', 'form.submissions'];

    public function __construct(
        private EmailModel $emailModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_email_variant';
    }

    public function getDescription(): string
    {
        return "Ajoute une variante de test A/B a un email existant (parent_email_id), via le vrai mecanisme "
            .'de test A/B de Mautic (pas un email independant). Toutes les variantes d un meme test doivent '
            .'partager le meme winner_criteria ; la somme des weight de toutes les variantes ne peut pas depasser 100 '
            .'(le reste revient a l email d origine). Pour un test simple 50/50, weight=50 sur l unique variante suffit. '
            .'Criteres acceptes : email.openrate, email.clickthrough, asset.downloads (email menant a un asset), '
            .'form.submissions (email menant a un formulaire).';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'email:emails:create';
    }

    public function getObjectType(): ?string
    {
        return 'email';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'parent_email_id' => ['type' => 'integer', 'description' => "Email d origine (l 'A'). Si cet id est deja une variante, la vraie racine du test est utilisee automatiquement."],
            'name'            => ['type' => 'string', 'description' => 'Nom interne de la variante.'],
            'subject'         => ['type' => 'string', 'description' => 'Objet de la variante.'],
            'html'            => ['type' => 'string', 'description' => 'Corps HTML complet de la variante.'],
            'weight'          => ['type' => 'integer', 'description' => 'Pourcentage du trafic pour cette variante (1-100).'],
            'winner_criteria' => ['type' => 'string', 'enum' => self::WINNER_CRITERIA, 'description' => 'Doit etre identique sur toutes les variantes du meme test.'],
            'from_name'       => ['type' => 'string'],
            'from_address'    => ['type' => 'string'],
            'is_published'    => ['type' => 'boolean', 'description' => 'Defaut false.'],
        ], ['parent_email_id', 'name', 'subject', 'html', 'weight', 'winner_criteria']);
    }

    public function execute(array $arguments): array
    {
        $name    = trim((string) ($arguments['name'] ?? ''));
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $html    = (string) ($arguments['html'] ?? '');
        $weight  = (int) ($arguments['weight'] ?? 0);
        $criteria = (string) ($arguments['winner_criteria'] ?? '');

        if ('' === $name || '' === $subject || '' === $html) {
            return ['status' => 'error', 'error' => 'name, subject et html sont obligatoires.'];
        }

        if ($weight < 1 || $weight > 100) {
            return ['status' => 'error', 'error' => 'weight doit etre compris entre 1 et 100.'];
        }

        if (!in_array($criteria, self::WINNER_CRITERIA, true)) {
            return ['status' => 'error', 'error' => sprintf('winner_criteria invalide. Valeurs acceptees : %s.', implode(', ', self::WINNER_CRITERIA))];
        }

        $requested = $this->emailModel->getEntity((int) ($arguments['parent_email_id'] ?? 0));

        if (!$requested instanceof Email) {
            return ['status' => 'error', 'error' => sprintf('Email #%d introuvable.', (int) ($arguments['parent_email_id'] ?? 0))];
        }

        // Meme resolution que Email::getVariants() : le vrai parent d'un test
        // est toujours l'email racine, jamais une de ses variantes (Mautic
        // core refuse meme de demarrer un test depuis une variante,
        // cf. EmailController::abtestAction()).
        $parent = $requested->getVariantParent() ?? $requested;

        $existingChildren     = $parent->getVariantChildren();
        $existingWeight       = 0;
        $existingCriteria     = null;

        foreach ($existingChildren as $child) {
            $settings         = $child->getVariantSettings();
            $existingWeight  += (int) ($settings['weight'] ?? 0);
            $existingCriteria ??= (string) ($settings['winnerCriteria'] ?? '') ?: null;
        }

        if (null !== $existingCriteria && $existingCriteria !== $criteria) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    "Les variantes existantes de ce test utilisent le critere '%s' : toutes les variantes d'un meme test doivent partager le meme critere.",
                    $existingCriteria,
                ),
            ];
        }

        if ($existingWeight + $weight > 100) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    'weight total depasse 100 (%d deja reparti sur %d variante(s) existante(s) + %d demande).',
                    $existingWeight,
                    $existingChildren->count(),
                    $weight,
                ),
            ];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'            => 'email_variant',
                'parent_email_id' => $parent->getId(),
                'parent_name'     => $parent->getName(),
                'name'            => $name,
                'subject'         => $subject,
                'weight'          => $weight,
                'winner_criteria' => $criteria,
                'html_excerpt'    => mb_substr(strip_tags($html), 0, 300),
            ]);
        }

        $variant = new Email();
        $variant->setName($name);
        $variant->setSubject($subject);
        $variant->setCustomHtml($html);
        $variant->setEmailType($parent->getEmailType());
        $variant->setTemplate('blank');
        $variant->setIsPublished((bool) ($arguments['is_published'] ?? false));
        $variant->setVariantParent($parent);
        $variant->setVariantSettings(['weight' => $weight, 'winnerCriteria' => $criteria]);

        if (!empty($arguments['from_name'])) {
            $variant->setFromName((string) $arguments['from_name']);
        }

        if (!empty($arguments['from_address'])) {
            $variant->setFromAddress((string) $arguments['from_address']);
        }

        $this->emailModel->saveEntity($variant);

        return $this->ok([
            'id'              => $variant->getId(),
            'name'            => $variant->getName(),
            'parent_email_id' => $parent->getId(),
            'weight'          => $weight,
            'winner_criteria' => $criteria,
            'url'             => '/s/emails/edit/'.$variant->getId(),
        ]);
    }
}
