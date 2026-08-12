<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Revele l email professionnel (et les autres coordonnees connues) d un
 * employe precis via QuickEnrich (Email Search, `GET /employees/search`) —
 * contrairement a quickenrich_search_contacts qui ne renvoie jamais qu un
 * booleen has_email. A utiliser sur un profil deja identifie (via
 * quickenrich_search_contacts ou fourni par l utilisateur), pas pour
 * chercher par criteres.
 *
 * Necessite soit linkedin_url seul, soit le trio company_url+first_name+
 * last_name. Si les 4 sont fournis, QuickEnrich essaie d abord linkedin_url
 * puis retombe sur le trio.
 */
class QuickenrichFindEmployeeEmailTool extends AbstractTool
{
    public function __construct(private QuickenrichClient $quickenrich)
    {
    }

    public function getName(): string
    {
        return 'quickenrich_find_employee_email';
    }

    public function getDescription(): string
    {
        return 'Revele l email (et les coordonnees connues) d un employe precis via QuickEnrich — contrairement a '
            .'quickenrich_search_contacts qui ne renvoie qu un booleen has_email. Fournir soit linkedin_url, soit '
            .'company_url+first_name+last_name (les 4 fournis : linkedin_url essaye d abord, sinon repli sur le trio).';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'linkedin_url' => ['type' => 'string', 'description' => 'URL de profil LinkedIn (correspondance exacte).'],
            'company_url'  => ['type' => 'string', 'description' => 'URL du site de l entreprise (correspondance exacte).'],
            'first_name'   => ['type' => 'string'],
            'last_name'    => ['type' => 'string'],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $linkedinUrl = trim((string) ($arguments['linkedin_url'] ?? ''));
        $companyUrl  = trim((string) ($arguments['company_url'] ?? ''));
        $firstName   = trim((string) ($arguments['first_name'] ?? ''));
        $lastName    = trim((string) ($arguments['last_name'] ?? ''));

        if ('' === $linkedinUrl && !('' !== $companyUrl && '' !== $firstName && '' !== $lastName)) {
            return ['status' => 'error', 'error' => 'Fournir linkedin_url, ou company_url+first_name+last_name.'];
        }

        $query = array_filter([
            'linkedin_url' => $linkedinUrl,
            'company_url'  => $companyUrl,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
        ], static fn (string $value): bool => '' !== $value);

        try {
            $response = $this->quickenrich->get('/employees/search', $query);
        } catch (QuickenrichException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        if ([] === $data) {
            return $this->ok(['found' => false]);
        }

        return $this->ok(['found' => true, 'employee' => $data]);
    }
}
