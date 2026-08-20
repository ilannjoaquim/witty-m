<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Attachment;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\AssetBundle\Entity\Asset;
use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Entity\WittyAttachmentRepository;
use MauticPlugin\WittyBundle\Entity\WittyConversation;
use MauticPlugin\WittyBundle\Entity\WittyMessage;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload, stockage et lecture des pieces jointes du chat.
 *
 * Deux destinations selon le type de fichier :
 * - image/document : deviennent un Asset Mautic local (Asset::setFile() +
 *   preUpload() + upload(), meme brique que CreateAssetTool mais en mode
 *   'local' plutot que 'remote') — donne une URL stable, exploitable dans un
 *   email ou une landing page.
 * - tableur/texte : simple fichier de travail sous media/witty/uploads/, lu
 *   a la demande par l'agent (read_attachment / import_leads_from_file), pas
 *   d'Asset : ce ne sont pas des fichiers destines a etre partages tels quels.
 */
class AttachmentManager
{
    /** @var array<int, string> */
    private const ALLOWED_EXTENSIONS = [
        'csv', 'xls', 'xlsx', 'txt', 'md',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'woff', 'woff2', 'ttf', 'otf',
    ];

    /** @var array<string, string> */
    private const KIND_BY_EXTENSION = [
        'jpg'  => WittyAttachment::KIND_IMAGE,
        'jpeg' => WittyAttachment::KIND_IMAGE,
        'png'  => WittyAttachment::KIND_IMAGE,
        'gif'  => WittyAttachment::KIND_IMAGE,
        'webp' => WittyAttachment::KIND_IMAGE,
        'csv'  => WittyAttachment::KIND_SPREADSHEET,
        'xls'  => WittyAttachment::KIND_SPREADSHEET,
        'xlsx' => WittyAttachment::KIND_SPREADSHEET,
        'txt'  => WittyAttachment::KIND_TEXT,
        'md'   => WittyAttachment::KIND_TEXT,
        'woff'  => WittyAttachment::KIND_FONT,
        'woff2' => WittyAttachment::KIND_FONT,
        'ttf'   => WittyAttachment::KIND_FONT,
        'otf'   => WittyAttachment::KIND_FONT,
    ];

    private const MAX_SIZE_BYTES = 15 * 1024 * 1024;

    private const TEXT_PREVIEW_LIMIT = 20_000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
        private PathsHelper $pathsHelper,
        private CoreParametersHelper $coreParametersHelper,
        private AssetModel $assetModel,
        private SpreadsheetReader $spreadsheetReader,
    ) {
    }

    /**
     * @param bool $pinned true pour un upload fait depuis la bibliotheque
     *                     "Fichiers" (Controller/FileController.php) : ignore
     *                     du nettoyage automatique des uploads orphelins, cf.
     *                     WittyAttachment::$pinned. false (defaut) pour un
     *                     upload depuis le trombone du chat.
     *
     * @throws AttachmentInvalidException extension refusee ou fichier trop lourd
     */
    public function upload(UploadedFile $file, ?WittyConversation $conversation = null, bool $pinned = false): WittyAttachment
    {
        $user      = $this->requireUser();
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        $this->assertExtensionAllowed($extension);
        $this->assertSizeAllowed((int) $file->getSize());

        $kind = self::KIND_BY_EXTENSION[$extension] ?? WittyAttachment::KIND_DOCUMENT;

        $attachment = new WittyAttachment();
        $attachment->setUser($user)
            ->setConversation($conversation)
            ->setPinned($pinned)
            ->setOriginalFilename($file->getClientOriginalName())
            ->setMimeType((string) ($file->getMimeType() ?: 'application/octet-stream'))
            ->setExtension($extension)
            ->setKind($kind)
            ->setSize((int) $file->getSize());

        if (in_array($kind, [WittyAttachment::KIND_IMAGE, WittyAttachment::KIND_DOCUMENT, WittyAttachment::KIND_FONT], true)) {
            // Une police a besoin d'une URL stable et publique exactement comme
            // une image : un @font-face dans un email/une page reference cette
            // URL depuis le HTML final, pas le fichier tel quel.
            $asset = $this->createLocalAsset($file);
            $attachment->setAssetId($asset->getId())
                ->setStoredFilename((string) $asset->getPath());
        } else {
            $attachment->setStoredFilename($this->moveToUploadsDir($file, $extension));
        }

        // A la difference de attachToConversation() (appele au milieu du tour
        // de l'agent, qui ne flush qu'une fois a la fin), upload() est tout le
        // travail d'une requete HTTP autonome (POST /witty/upload) : le front
        // a besoin de l'id tout de suite pour l'inclure dans attachment_ids.
        $this->entityManager->persist($attachment);
        $this->entityManager->flush();

        return $attachment;
    }

    /**
     * @throws AttachmentNotFoundException id inconnu ou appartenant a un autre utilisateur
     */
    public function resolve(int $id): WittyAttachment
    {
        $user       = $this->requireUser();
        $attachment = $this->repository()->findOneForUser($id, (int) $user->getId());

        if (null === $attachment) {
            throw new AttachmentNotFoundException(sprintf('Piece jointe %d introuvable.', $id));
        }

        return $attachment;
    }

    /**
     * Bibliotheque "Fichiers" de l'utilisateur courant (page Files et outil
     * list_attachments) : tout ce qu'il a deja envoye a l'agent, rattache a
     * une conversation ou non.
     *
     * @return WittyAttachment[]
     */
    public function listForUser(?string $search = null, int $limit = 100): array
    {
        $user = $this->requireUser();

        return $this->repository()->findAllForUser((int) $user->getId(), $search, $limit);
    }

    /**
     * Rattache un upload deja en base (fait a la selection du fichier) au tour
     * de conversation qui vient d'etre soumis. Ne flush pas : suit la meme
     * discipline que ConversationManager (un seul flush, en fin de tour).
     */
    public function attachToConversation(WittyAttachment $attachment, WittyConversation $conversation, WittyMessage $message): void
    {
        $attachment->setConversation($conversation);
        $message->addAttachment($attachment);
        $this->entityManager->persist($attachment);
    }

    /**
     * Contenu exploitable par l'agent, different selon le type de fichier.
     *
     * @return array<string, mixed>
     */
    public function readPreview(WittyAttachment $attachment): array
    {
        return match ($attachment->getKind()) {
            WittyAttachment::KIND_TEXT       => $this->previewText($attachment),
            WittyAttachment::KIND_SPREADSHEET => $this->previewSpreadsheet($attachment),
            WittyAttachment::KIND_FONT       => $this->previewFont($attachment),
            default                          => $this->previewBinary($attachment),
        };
    }

    /**
     * Lecture complete (pas de plafond de lignes ici : c'est a l'appelant,
     * ex. ImportLeadsFromFileTool, de decider ce qu'il fait d'un fichier trop
     * gros pour un traitement synchrone).
     *
     * @return array{headers: string[], rows: array<int, array<int, string>>}
     *
     * @throws AttachmentInvalidException si l'attachment n'est pas un tableur
     */
    public function readSpreadsheetAll(WittyAttachment $attachment): array
    {
        if (WittyAttachment::KIND_SPREADSHEET !== $attachment->getKind()) {
            throw new AttachmentInvalidException(sprintf(
                "La piece jointe #%d n'est pas un tableur (%s).",
                $attachment->getId() ?? 0,
                $attachment->getKind(),
            ));
        }

        return $this->spreadsheetReader->readAll($this->absolutePath($attachment));
    }

    public function assetUrl(WittyAttachment $attachment): ?string
    {
        if (null === $attachment->getAssetId()) {
            return null;
        }

        $asset = $this->assetModel->getEntity($attachment->getAssetId());

        return $asset instanceof Asset ? $this->assetModel->generateUrl($asset) : null;
    }

    /**
     * Suppression manuelle d'une piece jointe (page Fichiers) : fichier ou
     * Asset physique, puis la ligne en base. Autonome (flush inclus), a la
     * difference de attachToConversation() — ce n'est jamais appele au milieu
     * du tour de l'agent.
     */
    public function delete(WittyAttachment $attachment): void
    {
        $this->deleteFile($attachment);
        $this->entityManager->remove($attachment);
        $this->entityManager->flush();
    }

    /**
     * Renomme une piece jointe (page Fichiers). Autonome (flush inclus), meme
     * discipline que delete().
     *
     * L'extension reelle du fichier stocke (`extension`, fixee une fois pour
     * toutes a l'upload) est TOUJOURS reappliquee au nom final, quoi que
     * l'utilisateur ait tape : ni ce service ni le reste du plugin
     * (readSpreadsheetAll(), previewFont()...) ne se fient au nom affiche
     * pour deduire le type reel d'un fichier, seulement a `extension`/`kind` —
     * un renommage ne doit donc jamais pouvoir laisser croire qu'un fichier a
     * change de type (ex. taper "rapport.pdf" sur un CSV reste "rapport.csv").
     *
     * Pour un fichier adosse a un Asset Mautic (image/document/police, cf.
     * upload()) : renomme aussi l'Asset (`title` ET `originalFileName`), pas
     * seulement la piece jointe elle-meme. Necessaire pour de vrai, pas par
     * souci de coherence cosmetique : `PublicController::localDownloadResponse()`
     * (coeur Mautic) sert le fichier avec un en-tete
     * `Content-Disposition: attachment;filename="{Asset::getOriginalFileName()}"` —
     * sans ce second renommage, l'URL publique continuerait de proposer
     * l'ANCIEN nom au telechargement alors que la page Fichiers en afficherait
     * un nouveau, un ecart trompeur.
     *
     * @throws AttachmentInvalidException nom vide apres nettoyage
     */
    public function rename(WittyAttachment $attachment, string $newName): WittyAttachment
    {
        $extension = $attachment->getExtension();
        $base      = trim((string) pathinfo(trim($newName), PATHINFO_FILENAME));

        if ('' === $base) {
            throw new AttachmentInvalidException('Le nouveau nom ne peut pas etre vide.');
        }

        // 191 : largeur reelle de original_filename (witty_attachments) ET de
        // title (assets) — verifiee contre la vraie base, jamais supposee
        // (meme piege que celui documente pour FieldWriteGuard : ne jamais se
        // fier a une longueur devinee). Tronque le nom plutot que de laisser
        // MySQL en mode strict rejeter purement et simplement l'ecriture.
        $maxBaseLength = 191 - ('' !== $extension ? mb_strlen($extension) + 1 : 0);
        $base          = mb_substr($base, 0, max(1, $maxBaseLength));

        $newFilename = '' !== $extension ? $base.'.'.$extension : $base;

        $attachment->setOriginalFilename($newFilename);

        if (null !== $attachment->getAssetId()) {
            $asset = $this->assetModel->getEntity($attachment->getAssetId());

            if ($asset instanceof Asset) {
                $asset->setOriginalFileName($newFilename);
                $asset->setTitle($newFilename);
                $this->assetModel->saveEntity($asset);
            }
        }

        $this->entityManager->persist($attachment);
        $this->entityManager->flush();

        return $attachment;
    }

    /**
     * Supprime les uploads jamais rattaches a une conversation (fichier joint
     * puis jamais envoye) et non "pinned" (cf. WittyAttachment::$pinned).
     * Autonome (flush inclus) : ce n'est pas appele depuis le tour de
     * l'agent, seulement depuis la commande de nettoyage periodique (voir
     * Command/PruneOrphanAttachmentsCommand).
     */
    public function pruneOrphans(\DateTimeInterface $before): int
    {
        $orphans = $this->repository()->findOrphans($before);

        foreach ($orphans as $attachment) {
            $this->deleteFile($attachment);
            $this->entityManager->remove($attachment);
        }

        $this->entityManager->flush();

        return count($orphans);
    }

    /**
     * Supprime le fichier physique ou l'Asset Mautic d'une piece jointe, sans
     * toucher a la ligne en base ni flusher (fait par l'appelant). Partage
     * entre delete() (une seule piece jointe) et pruneOrphans() (plusieurs,
     * un seul flush pour tout le lot).
     */
    private function deleteFile(WittyAttachment $attachment): void
    {
        if (null !== $attachment->getAssetId()) {
            $asset = $this->assetModel->getEntity($attachment->getAssetId());

            if ($asset instanceof Asset) {
                // Entite fraichement chargee depuis la base : uploadDir n'est
                // jamais persiste (voir createLocalAsset()), donc de nouveau a
                // zero ici. AssetSubscriber::onAssetDelete() supprime bien le
                // fichier physique automatiquement (evenement ASSET_POST_DELETE,
                // pas besoin de le faire nous-memes), mais via
                // Asset::removeUpload() -> getAbsolutePath(), qui depend du meme
                // getUploadDir() : sans ce rappel, il chercherait le fichier au
                // mauvais endroit et ne supprimerait rien (silencieusement,
                // fichier orphelin laisse sur disque).
                $asset->setUploadDir((string) $this->coreParametersHelper->get('upload_dir'));
                $this->assetModel->deleteEntity($asset);
            }

            return;
        }

        $path = $this->absolutePath($attachment);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function previewText(WittyAttachment $attachment): array
    {
        $content = @file_get_contents($this->absolutePath($attachment));

        if (false === $content) {
            return ['type' => 'text', 'error' => 'Fichier introuvable sur le disque.'];
        }

        return [
            'type'      => 'text',
            'content'   => mb_substr($content, 0, self::TEXT_PREVIEW_LIMIT),
            'truncated' => mb_strlen($content) > self::TEXT_PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewSpreadsheet(WittyAttachment $attachment): array
    {
        $data = $this->spreadsheetReader->preview($this->absolutePath($attachment));

        return [
            'type'       => 'spreadsheet',
            'headers'    => $data['headers'],
            'sample_rows' => $data['rows'],
            'total_rows'  => $data['totalRows'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewBinary(WittyAttachment $attachment): array
    {
        return [
            'type'      => $attachment->getKind(),
            'note'      => "Contenu binaire : pas de lecture textuelle disponible. Utilise l'URL de l'asset pour le referencer (email, landing page, asset).",
            'asset_url' => $this->assetUrl($attachment),
        ];
    }

    /**
     * A la difference d'une image (juste une URL a mettre dans un src), une
     * police demande une regle @font-face pour devenir utilisable : la
     * note fournit un exemple pret a adapter, avec le bon `format()` pour
     * l'extension. Rappelle aussi une limite reelle, pas une limite du
     * plugin : le support des polices auto-hebergees (comme les Google Fonts
     * d'ailleurs) est tres inegal selon les clients email (Outlook desktop et
     * la plupart des webmails ignorent @font-face), contrairement a une
     * landing page, rendue par un vrai navigateur, ou ca fonctionne comme sur
     * n'importe quel site.
     *
     * @return array<string, mixed>
     */
    private function previewFont(WittyAttachment $attachment): array
    {
        $format = match ($attachment->getExtension()) {
            'woff2' => 'woff2',
            'woff'  => 'woff',
            'ttf'   => 'truetype',
            'otf'   => 'opentype',
            default => $attachment->getExtension(),
        };

        $family = pathinfo($attachment->getOriginalFilename(), PATHINFO_FILENAME);
        $url    = $this->assetUrl($attachment);

        return [
            'type'      => WittyAttachment::KIND_FONT,
            'asset_url' => $url,
            'note'      => "Police auto-hebergee, PAS une Google Font : a declarer via @font-face avant de l'utiliser, "
                ."en referencant l'URL de l'asset. Toujours prevoir une police de repli (ex. Arial, sans-serif), le "
                .'support de @font-face est tres inegal en email (Outlook desktop et la plupart des webmails '
                .'l ignorent silencieusement, la police de repli s applique alors) — fiable en revanche sur une '
                .'landing page, rendue par un vrai navigateur.',
            'css_example' => sprintf(
                "@font-face { font-family: '%s'; src: url('%s') format('%s'); }\nbody { font-family: '%s', Arial, sans-serif; }",
                $family,
                $url,
                $format,
                $family,
            ),
        ];
    }

    private function createLocalAsset(UploadedFile $file): Asset
    {
        $asset = new Asset();
        $asset->setStorageLocation('local');
        $asset->setTitle($file->getClientOriginalName());
        $asset->setOriginalFileName($file->getClientOriginalName());
        // Slug simple et unique : pas besoin d'etre joli, seulement stable et
        // sans collision (l'alias sert de repli si l'entite n'a pas d'uuid).
        $asset->setAlias(bin2hex(random_bytes(8)));
        $asset->setFile($file);
        // uploadDir n'est PAS une colonne mappee (Entity/Asset.php:$uploadDir,
        // simple propriete PHP) : sans cet appel, Asset::getUploadDir() retombe
        // sur le defaut fige 'media/files' (chemin RELATIF, resolu au CWD du
        // process PHP au moment du move() plus bas) au lieu du dossier reellement
        // configure. Le fichier atterrit alors ailleurs que la ou
        // PublicController::localDownloadResponse() ira le chercher pour servir
        // l'URL (il refait le meme setUploadDir() avant de lire) : l'upload
        // "reussit" sans erreur, mais l'asset resultant est introuvable (404) des
        // qu'on essaie de l'utiliser. Meme appel que AssetController core avant
        // tout preUpload()/upload().
        $asset->setUploadDir((string) $this->coreParametersHelper->get('upload_dir'));
        // tempId n'a pas de valeur par defaut (null) : AssetController (core) le
        // renseigne toujours avant upload(), meme hors flux d'upload par morceaux
        // (uniqid('tmp_') si le formulaire n'en a pas fourni). Sans lui,
        // Asset::upload() plante en sortie (Filesystem::remove(null), l'appel
        // getAbsoluteTempDir() renvoyant null) : chaque import d'image/document
        // echouait purement et simplement, avant meme la question de savoir ou le
        // fichier atterrit. Aucun repertoire temp n'est reellement cree par notre
        // flux (pas de widget d'upload par morceaux) : ce tempId ne sert donc
        // qu'a ce que le nettoyage de fin d'upload() trouve un chemin absent
        // (no-op silencieux) plutot qu'un null qui fait planter Filesystem::remove().
        $asset->setTempId(uniqid('tmp_', true));
        $asset->preUpload();
        $asset->upload();

        // A la difference des objets crees par l'agent (toujours non publies,
        // en attente de revue), un fichier joint vient explicitement de
        // l'utilisateur : le laisser non publie casserait silencieusement le
        // cas d'usage demande (image utilisable tout de suite dans un email).
        $asset->setIsPublished(true);

        $this->assetModel->saveEntity($asset);

        return $asset;
    }

    private function moveToUploadsDir(UploadedFile $file, string $extension): string
    {
        $dir = $this->uploadsDir();
        $storedFilename = bin2hex(random_bytes(16)).'.'.$extension;
        $file->move($dir, $storedFilename);

        return $storedFilename;
    }

    private function uploadsDir(): string
    {
        $dir = rtrim($this->pathsHelper->getMediaPath(), '/').'/witty/uploads';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function absolutePath(WittyAttachment $attachment): string
    {
        return $this->uploadsDir().'/'.$attachment->getStoredFilename();
    }

    private function assertExtensionAllowed(string $extension): void
    {
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new AttachmentInvalidException(sprintf(
                "Type de fichier non pris en charge (%s). Formats acceptes : %s.",
                $extension,
                implode(', ', self::ALLOWED_EXTENSIONS),
            ));
        }

        // Ne jamais depasser la politique d'upload globale du site, si elle en
        // restreint une (liste vide = pas de restriction supplementaire cote Mautic).
        $siteAllowed = array_map('strtolower', (array) $this->coreParametersHelper->get('allowed_extensions', []));

        if ([] !== $siteAllowed && !in_array($extension, $siteAllowed, true)) {
            throw new AttachmentInvalidException(sprintf(
                "Type de fichier desactive par la configuration Mautic (%s).",
                $extension,
            ));
        }
    }

    private function assertSizeAllowed(int $size): void
    {
        $siteMaxMb = (float) $this->coreParametersHelper->get('max_size', 0);
        $maxBytes  = self::MAX_SIZE_BYTES;

        if ($siteMaxMb > 0) {
            $maxBytes = min($maxBytes, (int) round($siteMaxMb * 1024 * 1024));
        }

        if ($size > $maxBytes) {
            throw new AttachmentInvalidException(sprintf(
                'Fichier trop volumineux (%.1f Mo, maximum %.1f Mo).',
                $size / 1024 / 1024,
                $maxBytes / 1024 / 1024,
            ));
        }
    }

    private function requireUser(): User
    {
        $user = $this->userHelper->getUser();

        if (!$user instanceof User || null === $user->getId()) {
            throw new AttachmentNotFoundException('Utilisateur courant indeterminable.');
        }

        return $user;
    }

    private function repository(): WittyAttachmentRepository
    {
        /** @var WittyAttachmentRepository $repository */
        $repository = $this->entityManager->getRepository(WittyAttachment::class);

        return $repository;
    }
}
