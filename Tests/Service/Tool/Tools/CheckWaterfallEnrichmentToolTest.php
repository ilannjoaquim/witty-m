<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyApolloWaterfallRequest;
use MauticPlugin\WittyBundle\Entity\WittyApolloWaterfallRequestRepository;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CheckWaterfallEnrichmentTool;
use PHPUnit\Framework\TestCase;

/**
 * Trois chemins distincts selon les arguments (cf. docblock de l'outil) :
 * request_id prime toujours, puis contact_id, puis a defaut l'historique de
 * l'utilisateur courant. Le point qui merite un test dedie est que
 * serialize() omet les cles sans valeur (result/date_completed/contact_id)
 * plutot que de renvoyer null explicitement : un modele lit plus surement
 * l absence d une cle qu une valeur null au milieu d un JSON.
 */
class CheckWaterfallEnrichmentToolTest extends TestCase
{
    public function testRequestIdTakesPriorityAndReturnsTheMatchingRequest(): void
    {
        $found = (new WittyApolloWaterfallRequest())
            ->setRequestId('req-1')
            ->setMode('both')
            ->setStatus(WittyApolloWaterfallRequest::STATUS_COMPLETED)
            ->setLabel('Jane Doe')
            ->setResult(['email' => 'jane@example.com']);

        $repository = $this->createMock(WittyApolloWaterfallRequestRepository::class);
        $repository->method('findOneByRequestId')->with('req-1')->willReturn($found);
        $repository->expects($this->never())->method('findForLead');
        $repository->expects($this->never())->method('findRecentForUser');

        $output = $this->tool($repository)->execute(['request_id' => 'req-1', 'contact_id' => 999]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('Jane Doe', $output['request']['label']);
        $this->assertSame('jane@example.com', $output['request']['result']['email']);
    }

    public function testUnknownRequestIdIsAnError(): void
    {
        $repository = $this->createMock(WittyApolloWaterfallRequestRepository::class);
        $repository->method('findOneByRequestId')->willReturn(null);

        $output = $this->tool($repository)->execute(['request_id' => 'nope']);

        $this->assertSame('error', $output['status']);
    }

    public function testContactIdListsThatContactsHistory(): void
    {
        $item = (new WittyApolloWaterfallRequest())->setRequestId('req-2')->setMode('email')->setLabel('X');

        $repository = $this->createMock(WittyApolloWaterfallRequestRepository::class);
        $repository->method('findForLead')->with(7, $this->anything())->willReturn([$item]);

        $output = $this->tool($repository)->execute(['contact_id' => 7]);

        $this->assertSame('ok', $output['status']);
        $this->assertCount(1, $output['requests']);
        $this->assertSame('req-2', $output['requests'][0]['request_id']);
    }

    public function testNoArgumentsListsRecentRequestsForTheCurrentUser(): void
    {
        $item = (new WittyApolloWaterfallRequest())->setRequestId('req-3')->setMode('phone')->setLabel('Y');

        $repository = $this->createMock(WittyApolloWaterfallRequestRepository::class);
        $repository->expects($this->once())->method('findRecentForUser')
            ->with($this->anything(), null, 20)
            ->willReturn([$item]);

        $output = $this->tool($repository)->execute([]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('req-3', $output['requests'][0]['request_id']);
    }

    public function testStatusFilterIsForwardedAndLimitIsClamped(): void
    {
        $repository = $this->createMock(WittyApolloWaterfallRequestRepository::class);
        $repository->expects($this->once())->method('findRecentForUser')
            ->with($this->anything(), 'pending', 50)
            ->willReturn([]);

        $this->tool($repository)->execute(['status' => 'pending', 'limit' => 500]);
    }

    public function testSerializeOmitsAbsentFieldsRatherThanReturningNull(): void
    {
        $pending = (new WittyApolloWaterfallRequest())->setRequestId('req-4')->setMode('email')->setLabel('Z');

        $repository = $this->createMock(WittyApolloWaterfallRequestRepository::class);
        $repository->method('findOneByRequestId')->willReturn($pending);

        $output = $this->tool($repository)->execute(['request_id' => 'req-4']);

        $this->assertArrayNotHasKey('result', $output['request']);
        $this->assertArrayNotHasKey('date_completed', $output['request']);
        $this->assertArrayNotHasKey('contact_id', $output['request']);
    }

    private function tool(WittyApolloWaterfallRequestRepository $repository): CheckWaterfallEnrichmentTool
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn(new User());

        return new CheckWaterfallEnrichmentTool($em, $userHelper);
    }
}
