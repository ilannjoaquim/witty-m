<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\ListFieldsTool;
use PHPUnit\Framework\TestCase;

class ListFieldsToolTest extends TestCase
{
    public function testUnknownObjectIsRejected(): void
    {
        $output = $this->tool([])->execute(['object' => 'lead']);

        $this->assertSame('error', $output['status']);
    }

    public function testContactMapsToTheLeadObjectInternally(): void
    {
        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->expects($this->once())->method('getPublishedFieldArrays')->with('lead')->willReturn([]);

        (new ListFieldsTool($fieldModel))->execute(['object' => 'contact']);
    }

    public function testSelectFieldExposesItsChoices(): void
    {
        $output = $this->tool([
            [
                'alias'      => 'companyindustry',
                'label'      => 'Industrie',
                'group'      => 'core',
                'type'       => 'select',
                'properties' => ['list' => [['label' => 'Finance', 'value' => 'Finance'], ['label' => 'Retail', 'value' => 'Retail']]],
            ],
        ])->execute(['object' => 'company']);

        $this->assertSame(['Finance', 'Retail'], $output['fields'][0]['choices']);
    }

    public function testCountryFieldCarriesANoteInsteadOfDumpingTheFullList(): void
    {
        $output = $this->tool([
            ['alias' => 'country', 'label' => 'Country', 'group' => 'core', 'type' => 'country', 'properties' => []],
        ])->execute(['object' => 'contact']);

        $this->assertArrayHasKey('note', $output['fields'][0]);
        $this->assertArrayNotHasKey('choices', $output['fields'][0]);
    }

    public function testSearchFiltersByAliasOrLabel(): void
    {
        $output = $this->tool([
            ['alias' => 'firstname', 'label' => 'Prenom', 'group' => 'core', 'type' => 'text', 'properties' => []],
            ['alias' => 'linkedin', 'label' => 'LinkedIn', 'group' => 'social', 'type' => 'text', 'properties' => []],
        ])->execute(['object' => 'contact', 'search' => 'linked']);

        $this->assertSame(1, $output['count']);
        $this->assertSame('linkedin', $output['fields'][0]['alias']);
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     */
    private function tool(array $definitions): ListFieldsTool
    {
        $fieldModel = $this->createMock(FieldModel::class);
        $fieldModel->method('getPublishedFieldArrays')->willReturn($definitions);

        return new ListFieldsTool($fieldModel);
    }
}
