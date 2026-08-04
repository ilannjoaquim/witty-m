<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Taxonomy;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Entity\CategoryRepository;
use Mautic\ProjectBundle\Entity\Project;

/**
 * Categorie et projets suivent exactement le meme modele que
 * segments/emails/formulaires cote Mautic core (cf.
 * Mautic\LeadBundle\Entity\LeadList) : on reutilise directement les entites
 * CategoryBundle/ProjectBundle plutot que de reinventer un systeme de tags
 * propre a Witty, pour que Skills/Rooms s'integrent aux mecanismes de
 * classification existants (filtrage par categorie/projet ailleurs dans
 * Mautic, etc.).
 */
class TaxonomyOptionsProvider
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    public function categoryChoices(string $bundle): array
    {
        $rows = $this->categoryRepository()->getCategoryList($bundle, '', 0, 0);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title']],
            $rows,
        );
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function projectChoices(): array
    {
        $projects = $this->entityManager->getRepository(Project::class)->findBy([], ['name' => 'ASC']);

        return array_map(
            static fn (Project $project): array => ['id' => (int) $project->getId(), 'name' => (string) $project->getName()],
            $projects,
        );
    }

    public function resolveCategory(?int $id): ?Category
    {
        if (null === $id || $id <= 0) {
            return null;
        }

        return $this->entityManager->getRepository(Category::class)->find($id);
    }

    /**
     * @param int[] $ids
     */
    public function resolveProjects(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ([] === $ids) {
            return new ArrayCollection();
        }

        return new ArrayCollection($this->entityManager->getRepository(Project::class)->findBy(['id' => $ids]));
    }

    private function categoryRepository(): CategoryRepository
    {
        /** @var CategoryRepository $repository */
        $repository = $this->entityManager->getRepository(Category::class);

        return $repository;
    }
}
