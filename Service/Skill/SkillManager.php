<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Skill;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittySkill;
use MauticPlugin\WittyBundle\Entity\WittySkillRepository;

/**
 * Proprietaire des skills : playbooks/strategies texte libre qu'une entreprise
 * enseigne a son agent (cf. Service/Tool/Tools/ReadSkillTool.php et
 * Service/Agent/PromptBuilder.php). Partages entre tous les utilisateurs de
 * l'instance (pas de filtrage par utilisateur, contrairement aux conversations).
 */
class SkillManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
    ) {
    }

    /**
     * @return WittySkill[]
     */
    public function listAll(): array
    {
        return $this->getRepository()->findAllOrdered();
    }

    /**
     * Nom + description uniquement : c'est ce qui reste en permanence dans le
     * prompt systeme, jamais le contenu complet (cf. PromptBuilder::build()).
     *
     * @return array<int, array{name: string, description: string}>
     */
    public function listForPrompt(): array
    {
        return array_map(
            static fn (WittySkill $skill): array => [
                'name'        => $skill->getName(),
                'description' => $skill->getDescription(),
            ],
            $this->listAll(),
        );
    }

    public function find(int $id): ?WittySkill
    {
        return $this->getRepository()->find($id);
    }

    public function findByName(string $name): ?WittySkill
    {
        return $this->getRepository()->findOneByNameCaseInsensitive($name);
    }

    public function save(WittySkill $skill): void
    {
        $isNew = null === $skill->getId();

        if ($isNew) {
            $user = $this->userHelper->getUser();

            if (null !== $user && null !== $user->getId()) {
                $skill->setCreatedBy($user);
            }
        } else {
            $skill->touch();
        }

        $this->entityManager->persist($skill);
        $this->entityManager->flush();
    }

    public function delete(WittySkill $skill): void
    {
        $this->entityManager->remove($skill);
        $this->entityManager->flush();
    }

    private function getRepository(): WittySkillRepository
    {
        /** @var WittySkillRepository $repository */
        $repository = $this->entityManager->getRepository(WittySkill::class);

        return $repository;
    }
}
