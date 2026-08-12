<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Tool\Tools\EnrichPersonTool;
use PHPUnit\Framework\TestCase;

/**
 * Le cas qui merite un test dedie : la reponse Apollo est enorme (donnees CRM
 * internes, technologies detaillees...) et doit etre reduite aux champs
 * exploitables (ApolloResponseTrimmer) avant de revenir au modele — en
 * gardant neanmoins ce qui sert a qualifier un contact (employment_history
 * allege, seniority/departments, reseaux sociaux personne+entreprise) — plus
 * le refus d'un appel sans le moindre identifiant (Apollo ne matcherait rien).
 */
class EnrichPersonToolTest extends TestCase
{
    public function testNoIdentifierIsRejectedWithoutCallingApollo(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('get');

        $output = (new EnrichPersonTool($apollo))->execute([]);

        $this->assertSame('error', $output['status']);
    }

    public function testNoMatchIsReportedAsFoundFalse(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('get')->willReturn([]);

        $output = (new EnrichPersonTool($apollo))->execute(['name' => 'Nobody']);

        $this->assertSame('ok', $output['status']);
        $this->assertFalse($output['found']);
    }

    public function testResponseIsTrimmedToUsefulFieldsButKeepsQualificationData(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('get')->willReturn([
            'person' => [
                'id' => 'p1', 'name' => 'Tim Zheng', 'title' => 'Founder & CEO', 'email' => 'tim@apollo.io',
                'seniority' => 'founder', 'departments' => ['c_suite'],
                'linkedin_url' => 'http://www.linkedin.com/in/tim-zheng', 'twitter_url' => 'https://twitter.com/tim',
                'organization' => [
                    'name' => 'Apollo.io', 'primary_domain' => 'apollo.io',
                    'linkedin_url' => 'http://www.linkedin.com/company/apolloio',
                ],
                'employment_history' => [
                    [
                        '_id' => 'internal-noise', 'id' => 'internal-noise', 'key' => 'internal-noise',
                        'title' => 'Founder & CEO', 'organization_name' => 'Apollo', 'current' => true,
                        'start_date' => '2016-01-01', 'end_date' => null,
                        'degree' => null, 'major' => null, 'grade_level' => null, 'raw_address' => null,
                    ],
                ],
                'contact' => ['salesforce_id' => 'noise'],
            ],
        ]);

        $output = (new EnrichPersonTool($apollo))->execute(['name' => 'Tim Zheng']);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['found']);
        $this->assertSame('tim@apollo.io', $output['person']['email']);
        $this->assertSame('apollo.io', $output['person']['organization_domain']);
        // Champs CRM internes non pertinents : retires.
        $this->assertArrayNotHasKey('contact', $output['person']);
        // Qualification et reseaux sociaux (personne + entreprise) : conserves.
        $this->assertSame('founder', $output['person']['seniority']);
        $this->assertSame('http://www.linkedin.com/in/tim-zheng', $output['person']['linkedin_url']);
        $this->assertSame('http://www.linkedin.com/company/apolloio', $output['person']['organization_linkedin_url']);
        // employment_history conserve mais allege : poste/entreprise/dates,
        // pas les ids internes ni les champs toujours vides.
        $this->assertSame(
            ['title' => 'Founder & CEO', 'organization_name' => 'Apollo', 'start_date' => '2016-01-01', 'current' => true],
            $output['person']['employment_history'][0],
        );
    }

    public function testApolloErrorIsReportedNotThrown(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('get')->willThrowException(new ApolloException('Apollo (HTTP 429) : quota depasse'));

        $output = (new EnrichPersonTool($apollo))->execute(['name' => 'Tim Zheng']);

        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('quota depasse', $output['error']);
    }
}
