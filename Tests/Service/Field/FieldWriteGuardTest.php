<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use PHPUnit\Framework\TestCase;

/**
 * Reproduit trois problemes constates en production, tous silencieux ou
 * bloquants avant ce garde-fou : un alias de champ qui n existe pas
 * (linkedin_url au lieu de linkedin) disparaissait sans erreur, un code pays
 * ISO (le format renvoye par QuickEnrich) ecrit dans un champ de type
 * `country` n apparaissait plus dans la fiche contact, et une valeur plus
 * longue que la colonne MySQL reelle (ex. un intitule de poste QuickEnrich de
 * plus de 191 caracteres dans `position`) faisait echouer purement et
 * simplement la requete (SQLSTATE[22001], job d import bloque en boucle sur
 * le meme element a chaque passage de cron).
 */
class FieldWriteGuardTest extends TestCase
{
    public function testUnknownAliasIsReportedAndValueIsDropped(): void
    {
        $guard = $this->guard([
            ['alias' => 'firstname', 'type' => 'text'],
        ]);

        $result = $guard->prepare(['firstname' => 'Jane', 'linkedin_url' => 'https://linkedin.com/in/jane']);

        $this->assertSame(['linkedin_url'], $result['unknown']);
        $this->assertSame(['firstname' => 'Jane', 'linkedin_url' => 'https://linkedin.com/in/jane'], $result['fields']);
    }

    public function testKnownAliasIsNeverReportedAsUnknown(): void
    {
        $guard = $this->guard([
            ['alias' => 'linkedin', 'type' => 'text'],
        ]);

        $result = $guard->prepare(['linkedin' => 'https://linkedin.com/in/jane']);

        $this->assertSame([], $result['unknown']);
    }

    public function testIsoCountryCodeIsNormalizedToTheFullEnglishName(): void
    {
        $guard = $this->guard([
            ['alias' => 'country', 'type' => 'country'],
        ]);

        $result = $guard->prepare(['country' => 'FR']);

        $this->assertSame('France', $result['fields']['country']);
    }

    public function testAlreadyFullCountryNameIsLeftUntouched(): void
    {
        $guard = $this->guard([
            ['alias' => 'country', 'type' => 'country'],
        ]);

        $result = $guard->prepare(['country' => 'France']);

        $this->assertSame('France', $result['fields']['country']);
    }

    public function testNonCountryTextFieldIsNeverAltered(): void
    {
        $guard = $this->guard([
            ['alias' => 'company', 'type' => 'text'],
        ]);

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

        $guard = new FieldWriteGuard($fieldModel, $this->em([]));

        $result = $guard->prepareMany([
            ['firstname' => 'Jane', 'country' => 'US'],
            ['firstname' => 'John', 'bogus_alias' => 'x'],
        ], 'lead');

        $this->assertSame(['bogus_alias'], $result['unknown']);
        $this->assertSame('United States', $result['rows'][0]['country']);
    }

    /**
     * Bug de production reel : `position` est un varchar(191) reel en base,
     * mais LeadField::$charLengthLimit vaut 64 pour ce champ dans lead_fields
     * (constate en session, jamais synchronise avec la vraie colonne pour un
     * champ par defaut) — se fier a cette metadonnee aurait soit tronque trop
     * tot, soit pas assez. La largeur reelle (191) est lue directement en
     * base ici (INFORMATION_SCHEMA), jamais depuis lead_fields.
     */
    public function testValueLongerThanTheRealColumnIsTruncatedRatherThanLeftToCrash(): void
    {
        $guard = $this->guard(
            [['alias' => 'position', 'type' => 'text']],
            ['position' => 191],
        );

        $result = $guard->prepare(['position' => str_repeat('a', 250)]);

        $this->assertSame(191, mb_strlen($result['fields']['position']));
        $this->assertSame(str_repeat('a', 191), $result['fields']['position']);
    }

    public function testValueWithinTheColumnLimitIsNeverTouched(): void
    {
        $guard = $this->guard(
            [['alias' => 'position', 'type' => 'text']],
            ['position' => 191],
        );

        $result = $guard->prepare(['position' => 'Chief Executive Officer']);

        $this->assertSame('Chief Executive Officer', $result['fields']['position']);
    }

    public function testFieldWithoutAKnownColumnLengthIsNeverTruncated(): void
    {
        // Colonne TEXT/LONGTEXT (pas de CHARACTER_MAXIMUM_LENGTH pertinent,
        // filtre par la requete INFORMATION_SCHEMA elle-meme) : aucune
        // troncature ne doit s y declencher, quelle que soit la longueur.
        $guard = $this->guard(
            [['alias' => 'description', 'type' => 'textarea']],
            [],
        );

        $long = str_repeat('a', 5000);
        $result = $guard->prepare(['description' => $long]);

        $this->assertSame($long, $result['fields']['description']);
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

    /**
     * @param array<int, array<string, string>> $definitions
     * @param array<string, int>                $maxLengths alias -> longueur max simulee
     */
    private function guard(array $definitions, array $maxLengths = []): FieldWriteGuard
    {
        return new FieldWriteGuard($this->fieldModel($definitions), $this->em($maxLengths));
    }

    /**
     * @param array<string, int> $maxLengths
     */
    private function em(array $maxLengths): EntityManagerInterface
    {
        $rows = [];
        foreach ($maxLengths as $column => $length) {
            $rows[] = ['COLUMN_NAME' => $column, 'CHARACTER_MAXIMUM_LENGTH' => $length];
        }

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getTableName')->willReturn('leads');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        return $em;
    }
}
