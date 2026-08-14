<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Field;

use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use PHPUnit\Framework\TestCase;

/**
 * Reproduit les deux problemes constates en production, tous les deux
 * silencieux avant ce garde-fou : un alias de champ qui n existe pas
 * (linkedin_url au lieu de linkedin) disparait sans erreur, et un code pays
 * ISO (le format renvoye par QuickEnrich) ecrit dans un champ de type
 * `country` n apparait plus dans la fiche contact car il ne correspond a
 * aucun choix du select (qui attend le nom complet anglais).
 */
class FieldWriteGuardTest extends TestCase
{
    public function testUnknownAliasIsReportedAndValueIsDropped(): void
    {
        $guard = new FieldWriteGuard($this->fieldModel([
            ['alias' => 'firstname', 'type' => 'text'],
        ]));

        $result = $guard->prepare(['firstname' => 'Jane', 'linkedin_url' => 'https://linkedin.com/in/jane']);

        $this->assertSame(['linkedin_url'], $result['unknown']);
        $this->assertSame(['firstname' => 'Jane', 'linkedin_url' => 'https://linkedin.com/in/jane'], $result['fields']);
    }

    public function testKnownAliasIsNeverReportedAsUnknown(): void
    {
        $guard = new FieldWriteGuard($this->fieldModel([
            ['alias' => 'linkedin', 'type' => 'text'],
        ]));

        $result = $guard->prepare(['linkedin' => 'https://linkedin.com/in/jane']);

        $this->assertSame([], $result['unknown']);
    }

    public function testIsoCountryCodeIsNormalizedToTheFullEnglishName(): void
    {
        $guard = new FieldWriteGuard($this->fieldModel([
            ['alias' => 'country', 'type' => 'country'],
        ]));

        $result = $guard->prepare(['country' => 'FR']);

        $this->assertSame('France', $result['fields']['country']);
    }

    public function testAlreadyFullCountryNameIsLeftUntouched(): void
    {
        $guard = new FieldWriteGuard($this->fieldModel([
            ['alias' => 'country', 'type' => 'country'],
        ]));

        $result = $guard->prepare(['country' => 'France']);

        $this->assertSame('France', $result['fields']['country']);
    }

    public function testNonCountryTextFieldIsNeverAltered(): void
    {
        $guard = new FieldWriteGuard($this->fieldModel([
            ['alias' => 'company', 'type' => 'text'],
        ]));

        $result = $guard->prepare(['company' => 'US']);

        $this->assertSame('US', $result['fields']['company']);
    }

    public function testPrepareManyChecksAllRowsAtOnceAndFetchesDefinitionsOnce(): void
    {
        $fieldModel = $this->fieldModel([
            ['alias' => 'firstname', 'type' => 'text'],
            ['alias' => 'country', 'type' => 'country'],
        ]);
        $fieldModel->expects($this->once())->method('getPublishedFieldArrays');

        $guard = new FieldWriteGuard($fieldModel);

        $result = $guard->prepareMany([
            ['firstname' => 'Jane', 'country' => 'US'],
            ['firstname' => 'John', 'bogus_alias' => 'x'],
        ], 'lead');

        $this->assertSame(['bogus_alias'], $result['unknown']);
        $this->assertSame('United States', $result['rows'][0]['country']);
    }

    /**
     * @param array<int, array<string, string>> $definitions
     */
    private function fieldModel(array $definitions): FieldModel
    {
        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->method('getPublishedFieldArrays')->willReturn($definitions);

        return $fieldModel;
    }
}
