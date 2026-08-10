<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Service\Template\EmailTemplateLibrary;
use MauticPlugin\WittyBundle\Service\Template\PageTemplateLibrary;
use MauticPlugin\WittyBundle\Service\Template\TemplateManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section "Templates" : les templates d'email (HTML) et de landing page
 * (HTML) que create_email_from_template / create_page_from_template mettent
 * a disposition de l'agent (cf. Service/Tool/Tools/*TemplatesTool.php).
 * Partages entre tous les utilisateurs de l'instance, comme les Skills.
 *
 * Le "code" est toujours du HTML final : PHP ne sait pas compiler du MJML
 * (cf. Entity/WittyTemplate.php), donc un template email s'ecrit ici
 * directement en HTML, comme un template de landing page.
 */
class TemplateController extends CommonController
{
    public function indexAction(): Response
    {
        return $this->delegateView([
            'viewParameters'  => [],
            'contentTemplate' => '@Witty/Template/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#witty_templates',
                'mauticContent' => 'wittyTemplates',
                'route'         => $this->generateUrl('witty_templates'),
            ],
        ]);
    }

    public function dataAction(TemplateManager $templates): JsonResponse
    {
        return new JsonResponse([
            'status'    => true,
            'templates' => array_map(
                static fn (WittyTemplate $template): array => [
                    'id'           => $template->getId(),
                    'type'         => $template->getType(),
                    'key'          => $template->getKey(),
                    'name'         => $template->getName(),
                    'description'  => $template->getDescription(),
                    'goal'         => $template->getGoal(),
                    'rules'        => $template->getRules(),
                    'placeholders' => $template->getPlaceholders(),
                    'html'         => $template->getHtml(),
                    'createdBy'    => $template->getCreatedBy()?->getName(),
                    'dateModified' => $template->getDateModified()->format('c'),
                ],
                $templates->listAll(),
            ),
        ]);
    }

    public function saveAction(Request $request, TemplateManager $templates): JsonResponse
    {
        $id          = (int) $request->request->get('id', 0);
        $type        = WittyTemplate::TYPE_PAGE === $request->request->get('type') ? WittyTemplate::TYPE_PAGE : WittyTemplate::TYPE_EMAIL;
        $key         = trim((string) $request->request->get('key', ''));
        $name        = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));
        $goal        = trim((string) $request->request->get('goal', ''));
        $html        = (string) $request->request->get('html', '');
        $rulesRaw    = (string) $request->request->get('rules', '');
        $placeholdersRaw = trim((string) $request->request->get('placeholders', ''));

        if ('' === $name) {
            return new JsonResponse(['status' => false, 'msg' => 'Le nom est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if ('' === trim($html)) {
            return new JsonResponse(['status' => false, 'msg' => 'Le code (HTML) est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $rules = array_values(array_filter(
            array_map('trim', (array) preg_split('/\r\n|\r|\n/', $rulesRaw)),
            static fn (string $line): bool => '' !== $line,
        ));

        $placeholders = [];

        if ('' !== $placeholdersRaw) {
            $decoded = json_decode($placeholdersRaw, true);

            if (!is_array($decoded)) {
                return new JsonResponse(['status' => false, 'msg' => 'Les emplacements doivent etre un JSON valide (tableau d objets).'], Response::HTTP_BAD_REQUEST);
            }

            foreach ($decoded as $placeholder) {
                if (!is_array($placeholder) || '' === trim((string) ($placeholder['key'] ?? ''))) {
                    return new JsonResponse(['status' => false, 'msg' => 'Chaque emplacement doit avoir une cle ("key").'], Response::HTTP_BAD_REQUEST);
                }

                $placeholder['key'] = strtoupper(trim((string) $placeholder['key']));
                $placeholders[]     = $placeholder;
            }
        }

        $template = $id > 0 ? $templates->find($id) : null;

        if ($id > 0 && null === $template) {
            return new JsonResponse(['status' => false, 'msg' => 'Template introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $template ??= new WittyTemplate();
        $template->setType($type);

        // Cree sans cle : TemplateManager en derive une du nom a
        // l'enregistrement. En edition, ne l'ecrase que si explicitement
        // fournie : c'est cette cle que l'agent utilise pour retrouver le
        // template (list_email_templates / create_email_from_template et
        // leurs equivalents page), la changer casserait un prompt existant.
        if ('' !== $key) {
            $template->setKey($key);
        }

        $template->setName($name);
        $template->setDescription('' !== $description ? $description : mb_substr($name, 0, 190));
        $template->setGoal($goal);
        $template->setRules($rules);
        $template->setPlaceholders($placeholders);
        $template->setHtml($html);

        $templates->save($template);

        return new JsonResponse(['status' => true, 'id' => $template->getId(), 'key' => $template->getKey()]);
    }

    public function deleteAction(Request $request, TemplateManager $templates): JsonResponse
    {
        $id       = (int) $request->request->get('id', 0);
        $template = $id > 0 ? $templates->find($id) : null;

        if (null === $template) {
            return new JsonResponse(['status' => false, 'msg' => 'Template introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $templates->delete($template);

        return new JsonResponse(['status' => true, 'deleted' => $id]);
    }

    /**
     * Apercu HTML brut, servi pour etre charge dans une iframe : les
     * emplacements sont remplis avec leur valeur par defaut (ou, a defaut,
     * leur exemple) pour donner une idee realiste du rendu sans exiger de
     * saisir chaque valeur au prealable.
     */
    public function previewAction(int $id, TemplateManager $templates): Response
    {
        $template = $templates->find($id);

        if (null === $template) {
            throw $this->createNotFoundException('Template introuvable.');
        }

        $values = [];

        foreach ($template->getPlaceholders() as $placeholder) {
            $key = strtoupper(trim((string) ($placeholder['key'] ?? '')));

            if ('' === $key) {
                continue;
            }

            $values[$key] = (string) ($placeholder['default'] ?? $placeholder['example'] ?? '');
        }

        $rendered = WittyTemplate::TYPE_PAGE === $template->getType()
            ? PageTemplateLibrary::render($template, $values)
            : EmailTemplateLibrary::render($template, $values);

        return new Response($rendered['html'], Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
