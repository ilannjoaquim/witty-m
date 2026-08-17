<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Lead;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\LeadBundle\Field\FieldsWithUniqueIdentifier;
use MauticPlugin\WittyBundle\Service\Lead\DuplicateContactGroupFinder;
use PHPUnit\Framework\TestCase;

/**
 * Ne reexecute jamais les vraies requetes SQL (deja verifiees contre la base
 * locale reelle, cf. le harnais manuel de session -- une base reelle avec
 * trois contacts jetables partageant le meme email, fusionnes puis nettoyes) :
 * ce test isole la seule logique non couverte par cette verification
 * manuelle -- le rassemblement de groupes qui se chevauchent quand PLUSIEURS
 * champs sont marques identifiant unique, jamais exerce sur cette instance
 * (un seul champ, email, configure en session).
 */
class DuplicateContactGroupFinderTest extends TestCase
{
    private function finder(array $uniqueFields, array $rowsByColumn): DuplicateContactGroupFinder
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(function (string $sql) use ($uniqueFields, $rowsByColumn) {
            $result = $this->createMock(Result::class);

            if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                // Toutes les colonnes "identifiant unique" existent reellement.
                $result->method('fetchFirstColumn')->willReturn(array_keys($uniqueFields));

                return $result;
            }

            foreach ($rowsByColumn as $column => $rows) {
                if (str_contains($sql, "GROUP BY $column")) {
                    $result->method('fetchFirstColumn')->willReturn($rows);

                    return $result;
                }
            }

            $result->method('fetchFirstColumn')->willReturn([]);

            return $result;
        });

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getTableName')->willReturn('leads');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $fieldsWithUniqueIdentifier = $this->createMock(FieldsWithUniqueIdentifier::class);
        $fieldsWithUniqueIdentifier->method('getFieldsWithUniqueIdentifier')->with(['object' => 'lead'])->willReturn($uniqueFields);

        return new DuplicateContactGroupFinder($em, $fieldsWithUniqueIdentifier);
    }

    public function testOneGroupPerDuplicateClusterOnASingleUniqueField(): void
    {
        $finder = $this->finder(
            ['email' => []],
            ['email' => ['1,2,3']],
        );

        $groups = $finder->find();

        $this->assertCount(1, $groups);
        $this->assertSame([1, 2, 3], $groups[0]['ids']);
        $this->assertSame('email', $groups[0]['field']);
    }

    public function testNoDuplicateRowsMeansNoGroups(): void
    {
        $finder = $this->finder(['email' => []], ['email' => []]);

        $this->assertSame([], $finder->find());
    }

    public function testGroupsSharingAContactAcrossTwoUniqueFieldsAreMergedIntoOne(): void
    {
        // email regroupe 1,2,3 ; phone regroupe 3,4 -- le contact #3 est
        // commun aux deux : sans fusion, il serait fusionne deux fois (une
        // fois comme perdant du groupe email, une fois comme perdant/gagnant
        // du groupe phone), ce qui echouerait au second passage (deja parti).
        $finder = $this->finder(
            ['email' => [], 'phone' => []],
            ['email' => ['1,2,3'], 'phone' => ['3,4']],
        );

        $groups = $finder->find();

        $this->assertCount(1, $groups);
        $this->assertSame([1, 2, 3, 4], $groups[0]['ids']);
    }

    public function testUnrelatedGroupsOnDifferentFieldsStayIndependent(): void
    {
        $finder = $this->finder(
            ['email' => [], 'phone' => []],
            ['email' => ['1,2'], 'phone' => ['10,11']],
        );

        $groups = $finder->find();

        $this->assertCount(2, $groups);
        $ids = array_map(static fn (array $g): array => $g['ids'], $groups);
        $this->assertContains([1, 2], $ids);
        $this->assertContains([10, 11], $ids);
    }

    public function testAFieldMarkedUniqueIdentifierWithoutARealColumnIsIgnoredRatherThanCrashing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(function (string $sql) {
            $result = $this->createMock(Result::class);

            if (str_contains($sql, 'INFORMATION_SCHEMA')) {
                // Seul 'email' existe reellement comme colonne, pas 'ghost_field'.
                $result->method('fetchFirstColumn')->willReturn(['email']);

                return $result;
            }

            $result->method('fetchFirstColumn')->willReturn([]);

            return $result;
        });

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getTableName')->willReturn('leads');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $fieldsWithUniqueIdentifier = $this->createMock(FieldsWithUniqueIdentifier::class);
        $fieldsWithUniqueIdentifier->method('getFieldsWithUniqueIdentifier')->willReturn(['ghost_field' => []]);

        $finder = new DuplicateContactGroupFinder($em, $fieldsWithUniqueIdentifier);

        $this->assertSame([], $finder->find());
    }
}
