<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CreateEmailVariantTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Le seul comportement propre a cet outil (le reste — persistance, weight,
 * winnerCriteria — vient directement de Mautic\CoreBundle\Entity\VariantEntityTrait,
 * deja fiable) : resoudre le vrai email racine d'un test quand on lui donne
 * l'id d'une variante, et faire respecter les deux regles que Mautic core
 * valide seulement a l'affichage (somme des poids, meme critere partout) des
 * la creation plutot que de laisser un test A/B incoherent se creer.
 */
class CreateEmailVariantToolTest extends TestCase
{
    public function testRejectsWeightOutOfRange(): void
    {
        $output = $this->tool($this->emailModel(new Email()), true)->execute($this->args(['weight' => 0]));

        $this->assertSame('error', $output['status']);
    }

    public function testRejectsAnUnknownWinnerCriteria(): void
    {
        $output = $this->tool($this->emailModel(new Email()), true)->execute($this->args(['winner_criteria' => 'made_up']));

        $this->assertSame('error', $output['status']);
    }

    public function testRejectsWhenTheParentEmailIsMissing(): void
    {
        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn(null);

        $output = $this->tool($emailModel, true)->execute($this->args());

        $this->assertSame('error', $output['status']);
    }

    public function testResolvesToTheTrueRootWhenGivenAnExistingVariantId(): void
    {
        $root  = new Email();
        $child = new Email();
        $child->setVariantParent($root);

        $output = $this->tool($this->emailModel($child), false)->execute($this->args());

        $this->assertSame('ok', $output['status']);
        // $root n'a pas d'id (jamais persiste dans ce test) : null des deux cotes,
        // ce qui suffit a prouver que c'est bien $root qui a ete utilise, pas $child.
        $this->assertSame($root->getId(), $output['parent_email_id']);
    }

    public function testRejectsWhenTotalWeightWouldExceed100(): void
    {
        $parent = new Email();

        $existing = new Email();
        $existing->setVariantParent($parent);
        $existing->setVariantSettings(['weight' => 70, 'winnerCriteria' => 'email.openrate']);
        $parent->addVariantChild($existing);

        $output = $this->tool($this->emailModel($parent), true)->execute($this->args(['weight' => 40]));

        $this->assertSame('error', $output['status']);
    }

    public function testRejectsAWinnerCriteriaThatDiffersFromExistingVariants(): void
    {
        $parent = new Email();

        $existing = new Email();
        $existing->setVariantParent($parent);
        $existing->setVariantSettings(['weight' => 30, 'winnerCriteria' => 'email.openrate']);
        $parent->addVariantChild($existing);

        $output = $this->tool($this->emailModel($parent), true)->execute($this->args(['weight' => 30, 'winner_criteria' => 'email.clickthrough']));

        $this->assertSame('error', $output['status']);
    }

    public function testRequiresConfirmationBeforeCreating(): void
    {
        $output = $this->tool($this->emailModel(new Email()), true)->execute($this->args());

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame(50, $output['preview']['weight']);
    }

    public function testCreatesTheVariantLinkedToItsParentOnceConfirmed(): void
    {
        $parent = new Email();
        $parent->setName('Campagne ete');
        $parent->setEmailType('list');

        $emailModel = $this->createMock(EmailModel::class);
        $emailModel->method('getEntity')->willReturn($parent);

        $savedVariant = null;
        $emailModel->expects($this->once())->method('saveEntity')->with($this->callback(
            static function (Email $variant) use ($parent, &$savedVariant): bool {
                $savedVariant = $variant;

                return $variant->getVariantParent() === $parent
                    && ['weight' => 50, 'winnerCriteria' => 'email.openrate'] === $variant->getVariantSettings()
                    && 'list' === $variant->getEmailType();
            },
        ));

        $output = $this->tool($emailModel, false)->execute($this->args());

        $this->assertSame('ok', $output['status']);
        $this->assertNotNull($savedVariant);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function args(array $overrides = []): array
    {
        return $overrides + [
            'parent_email_id' => 1,
            'name'            => 'Variante B',
            'subject'         => 'Objet B',
            'html'            => '<html><body>B</body></html>',
            'weight'          => 50,
            'winner_criteria' => 'email.openrate',
        ];
    }

    private function emailModel(Email $entity): EmailModel
    {
        $model = $this->createMock(EmailModel::class);
        $model->method('getEntity')->willReturn($entity);

        return $model;
    }

    private function tool(EmailModel $emailModel, bool $requiresConfirmation): CreateEmailVariantTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new CreateEmailVariantTool($emailModel, $config);
    }
}
