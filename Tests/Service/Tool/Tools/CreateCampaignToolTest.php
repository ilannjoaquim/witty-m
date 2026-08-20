<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Model\UserModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CreateCampaignTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Reecriture de create_campaign suite a un rapport concret : l'ancienne
 * version ne savait produire qu'une chaine lineaire d'actions, sans
 * decision/condition, sans embranchement oui/non, et sans aucune
 * planification horaire (un email pouvait partir un dimanche a 3h du
 * matin). Le comportement de bout en bout (structure reelle en base,
 * canvas_settings/anchors) est deja verifie contre la vraie base locale via
 * un harnais manuel de session (email -> attente 3 jours -> a-t-il repondu
 * (decision email.reply native Mautic) -> relance si non / tag si oui) :
 * ces tests couvrent ce que ce harnais ne couvre pas — le detail de la
 * validation (resolveGraph/validateSteps), plus rapide et plus systematique
 * a verifier ainsi que par un aller-retour base a chaque fois.
 */
class CreateCampaignToolTest extends TestCase
{
    private function segment(int $id): LeadList
    {
        $segment = new LeadList();
        (new \ReflectionProperty(LeadList::class, 'id'))->setValue($segment, $id);

        return $segment;
    }

    private function email(int $id): Email
    {
        $email = new Email();
        (new \ReflectionProperty(Email::class, 'id'))->setValue($email, $id);

        return $email;
    }

    private function user(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function tool(
        ?EmailModel $emailModel = null,
        ?ListModel $listModel = null,
        ?UserModel $userModel = null,
        ?FieldWriteGuard $fieldWriteGuard = null,
        bool $requiresConfirmation = false,
    ): CreateCampaignTool {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        $guard = $fieldWriteGuard ?? $this->createMock(FieldWriteGuard::class);
        if (null === $fieldWriteGuard) {
            $guard->method('unknownAliases')->willReturn([]);
            $guard->method('prepare')->willReturnCallback(fn (array $fields) => ['fields' => $fields, 'unknown' => []]);
        }

        return new CreateCampaignTool(
            $this->createMock(CampaignModel::class),
            $emailModel ?? $this->createMock(EmailModel::class),
            $listModel ?? $this->createMock(ListModel::class),
            $userModel ?? $this->createMock(UserModel::class),
            $guard,
            $config,
        );
    }

    private function withSegment(int $id): ListModel
    {
        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->willReturnCallback(fn (int $sid) => $sid === $id ? $this->segment($id) : null);

        return $listModel;
    }

    public function testDecisionMustFollowASendEmailStep(): void
    {
        $listModel  = $this->withSegment(1);
        $tool       = $this->tool(null, $listModel);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [
                ['type' => 'add_tag', 'tags' => ['a']],
                ['type' => 'email_replied', 'after_step' => 0],
            ],
        ]);

        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('send_email', $output['error']);
    }

    public function testBranchIsForbiddenAfterAPlainAction(): void
    {
        $listModel = $this->withSegment(1);
        $tool      = $this->tool(null, $listModel);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [
                ['type' => 'add_tag', 'tags' => ['a']],
                ['type' => 'add_points', 'points' => 5, 'after_step' => 0, 'branch' => 'yes'],
            ],
        ]);

        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('branch ne doit pas', $output['error']);
    }

    public function testBranchIsRequiredAfterADecisionOrCondition(): void
    {
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($this->email(1));

        $listModel = $this->withSegment(1);
        $tool      = $this->tool($emailModel, $listModel);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [
                ['type' => 'send_email', 'email_id' => 1],
                ['type' => 'email_replied', 'after_step' => 0],
                ['type' => 'add_tag', 'tags' => ['a'], 'after_step' => 1],
            ],
        ]);

        $this->assertSame('error', $output['status']);
        $this->assertStringContainsString('branch', $output['error']);
    }

    public function testAfterStepCannotReferenceItselfOrAFutureStep(): void
    {
        $listModel = $this->withSegment(1);
        $tool      = $this->tool(null, $listModel);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [
                ['type' => 'add_tag', 'tags' => ['a'], 'after_step' => 0],
            ],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testDefaultAfterStepChainsToThePreviousArrayEntry(): void
    {
        // Comportement historique preserve : sans after_step, chaque etape
        // s'enchaine simplement a la precedente du tableau (chaine lineaire
        // simple), pour ne pas casser l'usage existant qui ne se sert jamais
        // de branches.
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($this->email(1));

        $listModel     = $this->withSegment(1);
        $campaignModel = $this->createMock(CampaignModel::class);
        $campaignModel->expects($this->once())->method('saveEntity')->with($this->callback(function (Campaign $campaign): bool {
            $events = array_values($campaign->getEvents()->toArray());

            return null === $events[0]->getParent() && $events[1]->getParent() === $events[0];
        }));

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('unknownAliases')->willReturn([]);

        $tool = new CreateCampaignTool($campaignModel, $emailModel, $listModel, $this->createMock(UserModel::class), $guard, $config);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [
                ['type' => 'send_email', 'email_id' => 1],
                ['type' => 'add_tag', 'tags' => ['a']],
            ],
        ]);

        $this->assertSame('ok', $output['status']);
    }

    public function testSendEmailStepSetsChannelAndChannelId(): void
    {
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($this->email(7));

        $listModel     = $this->withSegment(1);
        $campaignModel = $this->createMock(CampaignModel::class);
        $campaignModel->expects($this->once())->method('saveEntity')->with($this->callback(function (Campaign $campaign): bool {
            $event = array_values($campaign->getEvents()->toArray())[0];

            return 'email' === $event->getChannel() && '7' === $event->getChannelId();
        }));

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $tool = new CreateCampaignTool($campaignModel, $emailModel, $listModel, $this->createMock(UserModel::class), $this->createMock(FieldWriteGuard::class), $config);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [['type' => 'send_email', 'email_id' => 7]],
        ]);

        $this->assertSame('ok', $output['status']);
    }

    public function testBusinessHoursAndRestrictedDaysAreAppliedToTheEvent(): void
    {
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($this->email(1));

        $listModel     = $this->withSegment(1);
        $campaignModel = $this->createMock(CampaignModel::class);
        $campaignModel->expects($this->once())->method('saveEntity')->with($this->callback(function (Campaign $campaign): bool {
            $event = array_values($campaign->getEvents()->toArray())[0];

            return '08:00' === $event->getTriggerHour()->format('H:i')
                && '08:00' === $event->getTriggerRestrictedStartHour()->format('H:i')
                && '18:00' === $event->getTriggerRestrictedStopHour()->format('H:i')
                && [1, 2, 3, 4, 5] === $event->getTriggerRestrictedDaysOfWeek();
        }));

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $tool = new CreateCampaignTool($campaignModel, $emailModel, $listModel, $this->createMock(UserModel::class), $this->createMock(FieldWriteGuard::class), $config);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [[
                'type' => 'send_email', 'email_id' => 1,
                'send_hour' => '08:00', 'restricted_start_hour' => '08:00', 'restricted_stop_hour' => '18:00',
                'restricted_days_of_week' => [1, 2, 3, 4, 5],
            ]],
        ]);

        $this->assertSame('ok', $output['status']);
    }

    public function testUnknownContactFieldAliasIsRejectedForUpdateContactField(): void
    {
        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('unknownAliases')->willReturn(['bogus_alias']);

        $listModel = $this->withSegment(1);
        $tool      = $this->tool(null, $listModel, null, $guard);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [['type' => 'update_contact_field', 'fields' => ['bogus_alias' => 'x']]],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testChangeOwnerRequiresAnExistingUser(): void
    {
        $userModel = $this->createMock(UserModel::class);
        $userModel->method('getEntity')->willReturn(null);

        $listModel = $this->withSegment(1);
        $tool      = $this->tool(null, $listModel, $userModel);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [['type' => 'change_owner', 'owner_user_id' => 999]],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testConfirmationIsRequiredBeforeSaving(): void
    {
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($this->email(1));

        $listModel = $this->withSegment(1);
        $tool      = $this->tool($emailModel, $listModel, null, null, true);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [['type' => 'send_email', 'email_id' => 1]],
        ]);

        $this->assertSame('confirmation_required', $output['status']);
    }

    public function testForkingTwoBranchesFromTheSameConditionBothPersist(): void
    {
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($this->email(1));

        $listModel     = $this->withSegment(1);
        $campaignModel = $this->createMock(CampaignModel::class);
        $campaignModel->expects($this->once())->method('saveEntity')->with($this->callback(function (Campaign $campaign): bool {
            $events = array_values($campaign->getEvents()->toArray());
            // events[0]=send_email, [1]=in_segment condition, [2]=yes branch, [3]=no branch
            return $events[2]->getParent() === $events[1] && 'yes' === $events[2]->getDecisionPath()
                && $events[3]->getParent() === $events[1] && 'no' === $events[3]->getDecisionPath();
        }));

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $tool = new CreateCampaignTool($campaignModel, $emailModel, $listModel, $this->createMock(UserModel::class), $this->createMock(FieldWriteGuard::class), $config);

        $output = $tool->execute([
            'name' => 'x', 'segment_ids' => [1],
            'steps' => [
                ['type' => 'send_email', 'email_id' => 1],
                ['type' => 'in_segment', 'segment_ids' => [1], 'after_step' => 0],
                ['type' => 'add_points', 'points' => 10, 'after_step' => 1, 'branch' => 'yes'],
                ['type' => 'add_points', 'points' => -10, 'after_step' => 1, 'branch' => 'no'],
            ],
        ]);

        $this->assertSame('ok', $output['status']);
    }
}
