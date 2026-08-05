<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WittyBundle\Entity\WittySkill;
use MauticPlugin\WittyBundle\Service\Skill\SkillManager;
use MauticPlugin\WittyBundle\Service\Taxonomy\TaxonomyOptionsProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section "Skills" : playbooks/strategies texte libre qu'une entreprise
 * enseigne a son agent (cf. Service/Tool/Tools/ReadSkillTool.php). Partages
 * entre tous les utilisateurs de l'instance.
 */
class SkillController extends CommonController
{
    public function indexAction(): Response
    {
        return $this->delegateView([
            'viewParameters'  => [],
            'contentTemplate' => '@Witty/Skill/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#witty_skills',
                'mauticContent' => 'wittySkills',
                'route'         => $this->generateUrl('witty_skills'),
            ],
        ]);
    }

    public function dataAction(SkillManager $skills, TaxonomyOptionsProvider $taxonomy): JsonResponse
    {
        return new JsonResponse([
            'status'  => true,
            'skills'  => array_map(
                static fn (WittySkill $skill): array => [
                    'id'            => $skill->getId(),
                    'name'          => $skill->getName(),
                    'description'   => $skill->getDescription(),
                    'content'       => $skill->getContent(),
                    'category'      => $skill->getCategory()?->getId(),
                    'categoryTitle' => $skill->getCategory()?->getTitle(),
                    'projects'      => array_values(array_map(
                        static fn ($project): array => ['id' => $project->getId(), 'name' => $project->getName()],
                        $skill->getProjects()->toArray(),
                    )),
                    'tags'          => array_values(array_map(
                        static fn ($tag): array => ['id' => $tag->getId(), 'name' => $tag->getTag()],
                        $skill->getTags()->toArray(),
                    )),
                    'tokens'        => $skill->getEstimatedTokens(),
                    'createdBy'     => $skill->getCreatedBy()?->getName(),
                    'dateModified'  => $skill->getDateModified()->format('c'),
                ],
                $skills->listAll(),
            ),
            'options' => [
                'categories' => $taxonomy->categoryChoices('witty_skill'),
                'projects'   => $taxonomy->projectChoices(),
                'tags'       => $taxonomy->tagChoices(),
            ],
        ]);
    }

    public function saveAction(Request $request, SkillManager $skills, TaxonomyOptionsProvider $taxonomy): JsonResponse
    {
        $id          = (int) $request->request->get('id', 0);
        $name        = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));
        $content     = (string) $request->request->get('content', '');
        $categoryId  = (int) $request->request->get('category_id', 0);
        $projectIds  = (array) $request->request->all('project_ids');
        $tagIds      = (array) $request->request->all('tag_ids');

        if ('' === $name) {
            return new JsonResponse(['status' => false, 'msg' => 'Le nom est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === trim($content)) {
            return new JsonResponse(['status' => false, 'msg' => 'Le contenu est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $skill = $id > 0 ? $skills->find($id) : null;

        if ($id > 0 && null === $skill) {
            return new JsonResponse(['status' => false, 'msg' => 'Skill introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $skill ??= new WittySkill();
        $skill->setName($name);
        $skill->setDescription('' !== $description ? $description : mb_substr($content, 0, 160));
        $skill->setContent($content);
        $skill->setCategory($taxonomy->resolveCategory($categoryId > 0 ? $categoryId : null));
        $skill->setProjects($taxonomy->resolveProjects($projectIds));
        $skill->setTags($taxonomy->resolveTags($tagIds));

        $skills->save($skill);

        return new JsonResponse(['status' => true, 'id' => $skill->getId()]);
    }

    public function deleteAction(Request $request, SkillManager $skills): JsonResponse
    {
        $id    = (int) $request->request->get('id', 0);
        $skill = $id > 0 ? $skills->find($id) : null;

        if (null === $skill) {
            return new JsonResponse(['status' => false, 'msg' => 'Skill introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $skills->delete($skill);

        return new JsonResponse(['status' => true, 'deleted' => $id]);
    }
}
