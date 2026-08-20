<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section "Fichiers" : bibliotheque personnelle des fichiers deja envoyes a
 * l'agent (cf. Service/Attachment/AttachmentManager.php), reutilisable au fil
 * de plusieurs conversations sans avoir a rejoindre le meme fichier a chaque
 * fois — voir list_attachments (Service/Tool/Tools/ListAttachmentsTool.php).
 *
 * Propre a chaque utilisateur, jamais partagee (meme scoping que le chat,
 * cf. AttachmentManager::requireUser()). Les fichiers uploades ici sont
 * "pinned" : contrairement a un upload depuis le trombone du chat, ils ne
 * sont jamais nettoyes automatiquement (cf. Entity/WittyAttachment.php).
 */
class FileController extends CommonController
{
    public function indexAction(): Response
    {
        return $this->delegateView([
            'viewParameters'  => [],
            'contentTemplate' => '@Witty/File/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#witty_files',
                'mauticContent' => 'wittyFiles',
                'route'         => $this->generateUrl('witty_files'),
            ],
        ]);
    }

    public function dataAction(AttachmentManager $attachments): JsonResponse
    {
        return new JsonResponse([
            'status' => true,
            'files'  => array_map(
                static fn (WittyAttachment $attachment): array => self::toArray($attachment, $attachments),
                $attachments->listForUser(),
            ),
        ]);
    }

    public function uploadAction(Request $request, AttachmentManager $attachments): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return new JsonResponse(['status' => false, 'msg' => 'Aucun fichier valide recu.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $attachment = $attachments->upload($file, null, pinned: true);
        } catch (AttachmentInvalidException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => true, 'file' => self::toArray($attachment, $attachments)]);
    }

    public function deleteAction(Request $request, AttachmentManager $attachments): JsonResponse
    {
        $id = (int) $request->request->get('id', 0);

        try {
            $attachment = $attachments->resolve($id);
        } catch (AttachmentNotFoundException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        $attachments->delete($attachment);

        return new JsonResponse(['status' => true, 'deleted' => $id]);
    }

    public function renameAction(Request $request, AttachmentManager $attachments): JsonResponse
    {
        $id       = (int) $request->request->get('id', 0);
        $filename = (string) $request->request->get('filename', '');

        try {
            $attachment = $attachments->resolve($id);
            $attachment = $attachments->rename($attachment, $filename);
        } catch (AttachmentNotFoundException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (AttachmentInvalidException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => true, 'file' => self::toArray($attachment, $attachments)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(WittyAttachment $attachment, AttachmentManager $attachments): array
    {
        return [
            'id'        => $attachment->getId(),
            'filename'  => $attachment->getOriginalFilename(),
            'kind'      => $attachment->getKind(),
            'size'      => $attachment->getSize(),
            'assetUrl'  => $attachments->assetUrl($attachment),
            'dateAdded' => $attachment->getDateAdded()->format('c'),
        ];
    }
}
