<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Job\Handlers\QuickenrichBulkEnrichPeopleJobHandler;
use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `linkedin` n'etant pas mappe par Doctrine (seuls title/firstname/lastname/
 * company/position/email/phone/mobile/address1/address2/city/state/zipcode/
 * timezone/country le sont, cf. Lead::loadMetadata()), le point le plus
 * fragile de ce handler est sa lecture en SQL natif — c'est ce que ces tests
 * couvrent en priorite (lu correctement, contact sans LinkedIn ecarte
 * proprement), plus la logique a deux endpoints (email/phone independants,
 * reveal choisit lesquels) et "rien trouve" -> failed sans casser le job
 * entier, contrairement a une vraie erreur fournisseur qui le fait echouer.
 */
class QuickenrichBulkEnrichPeopleJobHandlerTest extends TestCase
{
    public function testDoesNotAllowMultiplePassesPerTick(): void
    {
        $handler = new QuickenrichBulkEnrichPeopleJobHandler($this->createMock(QuickenrichClient::class), $this->createMock(EntityManagerInterface::class));

        $this->assertFalse($handler->allowsMultiplePassesPerTick());
    }

    public function testLeadWithoutLinkedinIsSkippedWithoutAnyApiCall(): void
    {
        $lead = $this->leadWithId(10);

        $quickenrich = $this->createMock(QuickenrichClient::class);
        $quickenrich->expects($this->never())->method('get');

        $recorded = [];
        $em       = $this->em([10], [$lead], ['10' => ''], $recorded);

        $job = (new WittyBackgroundJob())->setParams(['segment_id' => 1, 'reveal' => ['email', 'phone']]);

        (new QuickenrichBulkEnrichPeopleJobHandler($quickenrich, $em))->processChunk($job);

        $this->assertCount(1, $recorded);
        $this->assertSame(WittyBackgroundJobItem::STATUS_SKIPPED, $recorded[0]->getStatus());
    }

    public function testFoundEmailAndPhoneRecordsSucceededWithBoth(): void
    {
        $lead = $this->leadWithId(10);

        $quickenrich = $this->createMock(QuickenrichClient::class);
        $quickenrich->method('get')->willReturnMap([
            ['/employees/search', ['linkedin_url' => 'https://linkedin.com/in/x'], ['data' => ['email' => 'jane@acme.test']]],
            ['/employees/phone-search', ['linkedin_url' => 'https://linkedin.com/in/x'], ['data' => ['phone' => '+15550000']]],
        ]);

        $recorded = [];
        $em       = $this->em([10], [$lead], ['10' => 'https://linkedin.com/in/x'], $recorded);

        $job = (new WittyBackgroundJob())->setParams(['segment_id' => 1, 'reveal' => ['email', 'phone']]);

        (new QuickenrichBulkEnrichPeopleJobHandler($quickenrich, $em))->processChunk($job);

        $this->assertCount(1, $recorded);
        $this->assertSame(WittyBackgroundJobItem::STATUS_SUCCEEDED, $recorded[0]->getStatus());
        $this->assertSame('jane@acme.test', $recorded[0]->getData()['email']);
        $this->assertSame('+15550000', $recorded[0]->getData()['phone']);
    }

    public function testRevealEmailOnlyNeverCallsThePhoneEndpoint(): void
    {
        $lead = $this->leadWithId(10);

        $quickenrich = $this->createMock(QuickenrichClient::class);
        $quickenrich->expects($this->once())->method('get')
            ->with('/employees/search', $this->anything())
            ->willReturn(['data' => ['email' => 'jane@acme.test']]);

        $recorded = [];
        $em       = $this->em([10], [$lead], ['10' => 'https://linkedin.com/in/x'], $recorded);

        $job = (new WittyBackgroundJob())->setParams(['segment_id' => 1, 'reveal' => ['email']]);

        (new QuickenrichBulkEnrichPeopleJobHandler($quickenrich, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJobItem::STATUS_SUCCEEDED, $recorded[0]->getStatus());
        $this->assertArrayNotHasKey('phone', $recorded[0]->getData());
    }

    public function testNothingFoundRecordsFailedButJobKeepsRunning(): void
    {
        $lead = $this->leadWithId(10);

        $quickenrich = $this->createMock(QuickenrichClient::class);
        $quickenrich->method('get')->willReturn(['data' => []]);

        $recorded = [];
        $em       = $this->em([10], [$lead], ['10' => 'https://linkedin.com/in/x'], $recorded);

        $job = (new WittyBackgroundJob())->setParams(['segment_id' => 1, 'reveal' => ['email', 'phone']]);

        (new QuickenrichBulkEnrichPeopleJobHandler($quickenrich, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJobItem::STATUS_FAILED, $recorded[0]->getStatus());
        // "Rien trouve" pour CET element n'est pas une erreur fournisseur :
        // le job continue normalement (ici, se termine puisque le lot est
        // plus petit que BATCH_SIZE), il n'echoue jamais dans son ensemble
        // pour un simple contact sans resultat.
        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }

    public function testProviderErrorFailsTheWholeJob(): void
    {
        $lead = $this->leadWithId(10);

        $quickenrich = $this->createMock(QuickenrichClient::class);
        $quickenrich->method('get')->willThrowException(new QuickenrichException('HTTP 500'));

        $recorded = [];
        $em       = $this->em([10], [$lead], ['10' => 'https://linkedin.com/in/x'], $recorded);

        $job = (new WittyBackgroundJob())->setParams(['segment_id' => 1, 'reveal' => ['email', 'phone']]);

        (new QuickenrichBulkEnrichPeopleJobHandler($quickenrich, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
        $this->assertSame('HTTP 500', $job->getErrorMessage());
    }

    private function leadWithId(int $id): Lead
    {
        $lead = new Lead();
        (new ReflectionProperty(Lead::class, 'id'))->setValue($lead, $id);

        return $lead;
    }

    /**
     * @param int[]                 $leadIds       resultat simule de nextLeadIds()
     * @param Lead[]                $leads         resultat simule de getRepository(Lead::class)->findBy()
     * @param array<string, string> $linkedinById  cle = id de lead en STRING (comme renvoye par une ligne SQL), valeur = linkedin
     * @param WittyBackgroundJobItem[] $recorded    rempli par reference a chaque persist() d un item
     */
    private function em(array $leadIds, array $leads, array $linkedinById, array &$recorded): EntityManagerInterface
    {
        $scalarRows = array_map(static fn (int $id): array => ['leadId' => $id], $leadIds);

        $query = $this->createMock(Query::class);
        $query->method('getScalarResult')->willReturn($scalarRows);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $leadRepository = $this->createMock(LeadRepository::class);
        $leadRepository->method('findBy')->willReturn($leads);

        $rows   = [];
        foreach ($linkedinById as $id => $linkedin) {
            $rows[] = ['id' => $id, 'linkedin' => $linkedin];
        }

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getTableName')->willReturn('leads');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('getRepository')->willReturn($leadRepository);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($classMetadata);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$recorded): void {
            if ($entity instanceof WittyBackgroundJobItem) {
                $recorded[] = $entity;
            }
        });

        return $em;
    }
}
