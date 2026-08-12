<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyApolloWaterfallRequest;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Tool\Tools\EnrichPersonWaterfallTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ce que ce test couvre et qui n'est propre qu'a cet outil (pas a
 * ApolloClient, deja teste ailleurs) : le choix STRICT de mode (email/phone
 * les deux flags waterfall independamment, jamais "both" par defaut), le
 * refus sans identifiant ni contact_id, la resolution d'un contact_id en
 * identifiants Apollo (nom/email tires du Lead), et le fait qu'une demande
 * n'est persistee QUE si Apollo repond waterfall.status=accepted (sinon rien
 * a interroger plus tard, ca resterait une ligne "pending" fantome).
 */
class EnrichPersonWaterfallToolTest extends TestCase
{
    public function testInvalidModeIsRejectedWithoutCallingApollo(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('get');

        $output = $this->tool($apollo)->execute(['mode' => 'bogus', 'email' => 'a@b.com']);

        $this->assertSame('error', $output['status']);
    }

    public function testNoIdentifierAndNoContactIdIsRejected(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('get');

        $output = $this->tool($apollo)->execute(['mode' => 'email']);

        $this->assertSame('error', $output['status']);
    }

    /**
     * @dataProvider modeFlagsProvider
     */
    public function testModeControlsWaterfallFlagsIndependently(string $mode, string $expectEmail, string $expectPhone): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->once())->method('get')->with(
            '/people/match',
            $this->callback(function (array $query) use ($expectEmail, $expectPhone): bool {
                return $expectEmail === $query['run_waterfall_email']
                    && $expectPhone === $query['run_waterfall_phone'];
            }),
        )->willReturn(['request_id' => 'req-1', 'waterfall' => ['status' => 'accepted']]);

        $output = $this->tool($apollo)->execute(['mode' => $mode, 'email' => 'jane@example.com']);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('req-1', $output['request_id']);
    }

    public static function modeFlagsProvider(): array
    {
        return [
            'email only' => [WittyApolloWaterfallRequest::MODE_EMAIL, 'true', 'false'],
            'phone only' => [WittyApolloWaterfallRequest::MODE_PHONE, 'false', 'true'],
            'both'       => [WittyApolloWaterfallRequest::MODE_BOTH, 'true', 'true'],
        ];
    }

    public function testAcceptedResponsePersistsAPendingRequest(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('get')->willReturn(['request_id' => 'req-2', 'waterfall' => ['status' => 'accepted']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (WittyApolloWaterfallRequest $r): bool => 'req-2' === $r->getRequestId()
                && WittyApolloWaterfallRequest::STATUS_PENDING === $r->getStatus()
                && WittyApolloWaterfallRequest::MODE_BOTH === $r->getMode(),
        ));
        $em->expects($this->once())->method('flush');

        $this->tool($apollo, null, $em)->execute(['mode' => 'both', 'email' => 'jane@example.com']);
    }

    public function testNonAcceptedWaterfallStatusIsAnErrorAndNothingIsPersisted(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('get')->willReturn(['request_id' => 'req-3', 'waterfall' => ['status' => 'failed', 'message' => 'no valid identifier']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $output = $this->tool($apollo, null, $em)->execute(['mode' => 'email', 'email' => 'jane@example.com']);

        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('no valid identifier', $output['error']);
    }

    public function testApolloExceptionIsReportedNotThrown(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('get')->willThrowException(new ApolloException('Apollo (HTTP 429) : quota depasse'));

        $output = $this->tool($apollo)->execute(['mode' => 'email', 'email' => 'jane@example.com']);

        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('quota depasse', $output['error']);
    }

    public function testContactIdResolvesTheLeadAndUsesItsFieldsAsIdentifiers(): void
    {
        $lead = new Lead();
        $lead->setFirstname('John');
        $lead->setLastname('Doe');
        $lead->setEmail('john.doe@corp.com');

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(42)->willReturn($lead);

        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->once())->method('get')->with(
            '/people/match',
            $this->callback(static fn (array $query): bool => 'john.doe@corp.com' === $query['email'] && 'John' === $query['first_name']),
        )->willReturn(['request_id' => 'req-4', 'waterfall' => ['status' => 'accepted']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (WittyApolloWaterfallRequest $r): bool => $r->getLead() === $lead && 'John Doe' === $r->getLabel(),
        ));

        $this->tool($apollo, $leadModel, $em)->execute(['mode' => 'phone', 'contact_id' => 42]);
    }

    public function testUnknownContactIdIsRejectedWithoutCallingApollo(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn(null);

        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('get');

        $output = $this->tool($apollo, $leadModel)->execute(['mode' => 'email', 'contact_id' => 999]);

        $this->assertSame('error', $output['status']);
    }

    private function tool(
        ApolloClient $apollo,
        ?LeadModel $leadModel = null,
        ?EntityManagerInterface $em = null,
    ): EnrichPersonWaterfallTool {
        $leadModel ??= $this->createMock(LeadModel::class);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn(new User());

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('https://example.test/witty/apollo/waterfall/webhook/token123');

        $em ??= $this->createMock(EntityManagerInterface::class);

        $config = $this->createMock(WittyConfig::class);
        $config->method('getApolloWebhookToken')->willReturn('token123');

        return new EnrichPersonWaterfallTool($apollo, $leadModel, $userHelper, $router, $em, $config);
    }
}
