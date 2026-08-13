<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Company;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ApolloBulkEnrichCompaniesJobHandler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Contrairement a ApolloBulkEnrichPeopleJobHandler (qui recoit une segment_id
 * et interroge lui-meme la base), celui-ci recoit directement company_ids
 * (Mautic n a pas de notion de "segment d entreprises") et derive les
 * identifiants envoyes a Apollo des champs DEJA connus de chaque Company —
 * c est ce derivage, plus la meme correlation positionnelle stricte que la
 * version contacts, qui merite un test dedie ici.
 */
class ApolloBulkEnrichCompaniesJobHandlerTest extends TestCase
{
    public function testIdentifiersAreDerivedFromExistingCompanyFields(): void
    {
        $company = $this->company(10, 'Acme Inc', 'https://acme.test');

        $repository = $this->createMock(\Doctrine\Persistence\ObjectRepository::class);
        $repository->method('findBy')->with(['id' => [10]])->willReturn([$company]);

        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->once())->method('post')->with(
            '/organizations/bulk_enrich',
            $this->callback(static fn (array $body): bool => 'Acme Inc' === $body['details'][0]['name']),
        )->willReturn(['organizations' => [['name' => 'Acme Inc', 'industry' => 'Software']]]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $job = (new WittyBackgroundJob())->setParams(['company_ids' => [10]]);

        (new ApolloBulkEnrichCompaniesJobHandler($apollo, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
        $this->assertSame(1, $job->getSucceededItems());
    }

    public function testCompanyWithoutIdentifiersIsSkippedWithoutCallingApollo(): void
    {
        $company = $this->company(11, '', '');

        $repository = $this->createMock(\Doctrine\Persistence\ObjectRepository::class);
        $repository->method('findBy')->willReturn([$company]);

        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('post');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (WittyBackgroundJobItem $item): bool => WittyBackgroundJobItem::STATUS_SKIPPED === $item->getStatus(),
        ));

        $job = (new WittyBackgroundJob())->setParams(['company_ids' => [11]]);

        (new ApolloBulkEnrichCompaniesJobHandler($apollo, $em))->processChunk($job);
    }

    public function testUnknownCompanyIdIsSkippedNotFailed(): void
    {
        $repository = $this->createMock(\Doctrine\Persistence\ObjectRepository::class);
        $repository->method('findBy')->willReturn([]);

        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('post');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $job = (new WittyBackgroundJob())->setParams(['company_ids' => [999]]);

        (new ApolloBulkEnrichCompaniesJobHandler($apollo, $em))->processChunk($job);

        $this->assertSame(1, $job->getFailedItems(), 'skipped compte comme failedItems, meme convention que le reste du plugin.');
    }

    public function testMismatchedResultCountFailsTheJobExplicitly(): void
    {
        $company = $this->company(10, 'Acme Inc', 'https://acme.test');

        $repository = $this->createMock(\Doctrine\Persistence\ObjectRepository::class);
        $repository->method('findBy')->willReturn([$company]);

        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('post')->willReturn(['organizations' => []]); // 0 for 1 sent

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $job = (new WittyBackgroundJob())->setParams(['company_ids' => [10]]);

        (new ApolloBulkEnrichCompaniesJobHandler($apollo, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
    }

    public function testApolloExceptionFailsTheJob(): void
    {
        $company = $this->company(10, 'Acme Inc', 'https://acme.test');

        $repository = $this->createMock(\Doctrine\Persistence\ObjectRepository::class);
        $repository->method('findBy')->willReturn([$company]);

        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('post')->willThrowException(new ApolloException('Apollo (HTTP 429) : quota depasse'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $job = (new WittyBackgroundJob())->setParams(['company_ids' => [10]]);

        (new ApolloBulkEnrichCompaniesJobHandler($apollo, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
        $this->assertStringContainsString('quota depasse', (string) $job->getErrorMessage());
    }

    private function company(int $id, string $name, string $website): Company
    {
        $company = new Company();
        (new ReflectionProperty(Company::class, 'id'))->setValue($company, $id);
        $company->setName($name);
        $company->setWebsite($website);

        return $company;
    }
}
