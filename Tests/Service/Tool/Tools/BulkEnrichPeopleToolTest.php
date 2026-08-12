<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Tool\Tools\BulkEnrichPeopleTool;
use PHPUnit\Framework\TestCase;

/**
 * Le plafond de 10 profils (limite reelle d'Apollo, pas un choix arbitraire
 * du plugin) et le filtrage des entrees vides sont la logique propre a cet
 * outil ; le reste (transport, trimming) est deja couvert ailleurs
 * (ApolloClientTest, EnrichPersonToolTest).
 */
class BulkEnrichPeopleToolTest extends TestCase
{
    public function testEmptyListIsRejected(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('post');

        $output = (new BulkEnrichPeopleTool($apollo))->execute(['people' => []]);

        $this->assertSame('error', $output['status']);
    }

    public function testMoreThanTenPeopleIsRejected(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('post');

        $output = (new BulkEnrichPeopleTool($apollo))->execute(['people' => array_fill(0, 11, ['name' => 'x'])]);

        $this->assertSame('error', $output['status']);
    }

    public function testEmptyEntriesAreDroppedBeforeCallingApollo(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->once())->method('post')->with(
            '/people/bulk_match',
            $this->callback(static fn (array $body): bool => 1 === count($body['details'])),
            [],
        )->willReturn(['matches' => []]);

        $output = (new BulkEnrichPeopleTool($apollo))->execute(['people' => [['name' => 'Tim Zheng'], []]]);

        $this->assertSame('ok', $output['status']);
    }

    public function testRevealPersonalEmailsIsPassedAsAQueryParameter(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->once())->method('post')->with(
            '/people/bulk_match',
            $this->anything(),
            ['reveal_personal_emails' => 'true'],
        )->willReturn(['matches' => []]);

        (new BulkEnrichPeopleTool($apollo))->execute(['people' => [['name' => 'Tim Zheng']], 'reveal_personal_emails' => true]);
    }

    public function testMatchesAreTrimmedButKeepEmploymentHistoryForQualification(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('post')->willReturn([
            'credits_consumed' => 2,
            'missing_records'  => 0,
            'matches' => [
                [
                    'id' => 'p1', 'name' => 'Tim Zheng',
                    'employment_history' => [
                        ['_id' => 'noise', 'title' => 'Founder & CEO', 'organization_name' => 'Apollo', 'current' => true, 'start_date' => '2016-01-01'],
                    ],
                ],
                ['id' => 'p2', 'name' => 'Roy Chung'],
            ],
        ]);

        $output = (new BulkEnrichPeopleTool($apollo))->execute(['people' => [['name' => 'Tim Zheng'], ['name' => 'Roy Chung']]]);

        $this->assertSame('ok', $output['status']);
        $this->assertCount(2, $output['matches']);
        // Conserve pour la qualification (ex. "plus de 5 ans d'experience"),
        // mais allege : pas d'id interne.
        $this->assertArrayNotHasKey('_id', $output['matches'][0]['employment_history'][0]);
        $this->assertSame('Founder & CEO', $output['matches'][0]['employment_history'][0]['title']);
        $this->assertSame(2, $output['credits_consumed']);
    }
}
