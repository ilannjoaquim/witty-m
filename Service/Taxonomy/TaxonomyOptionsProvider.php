<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Taxonomy;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Entity\CategoryRepository;
use Mautic\LeadBundle\Entity\Tag;
use Mautic\ProjectBundle\Entity\Project;

/**
 * Categorie, projets et tags suivent exactement le meme modele que
 * segments/emails/formulaires/contacts cote Mautic core (cf.
 * Mautic\LeadBundle\Entity\LeadList) : on reutilise directement les entites
 * CategoryBundle/ProjectBundle/Tag (meme pool que les contacts) plutot que de
 * reinventer un systeme de classification propre a Witty, pour que
 * Skills/Rooms s'integrent aux mecanismes existants (filtrage par
 * categorie/projet/tag ailleurs dans Mautic, etc.).
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

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function tagChoices(): array
    {
        $tags = $this->entityManager->getRepository(Tag::class)->findBy([], ['tag' => 'ASC']);

        return array_map(
            static fn (Tag $tag): array => ['id' => (int) $tag->getId(), 'name' => (string) $tag->getTag()],
            $tags,
        );
    }

    /**
     * Cree-a-la-volee des nouveaux tags : gere cote client par
     * Mautic.createLeadTag (widget Chosen, cf. gabarits Skill/Rooms), qui
     * appelle l'action ajax core "lead:addLeadTags" avant meme la soumission
     * de notre formulaire. A ce stade, $ids ne contient donc que des ids
     * numeriques de tags deja existants.
     *
     * @param int[] $ids
     */
    public function resolveTags(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ([] === $ids) {
            return new ArrayCollection();
        }

        return new ArrayCollection($this->entityManager->getRepository(Tag::class)->findBy(['id' => $ids]));
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
