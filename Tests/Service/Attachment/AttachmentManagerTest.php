<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Attachment;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Entity\WittyAttachmentRepository;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use MauticPlugin\WittyBundle\Service\Attachment\SpreadsheetReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Couvre surtout le chemin tableur/texte (validation, detection de type,
 * stockage sur disque), qui ne touche aucun modele Mautic. Le chemin
 * image/document ecrit via AssetModel::saveEntity() (mocke ici, comme
 * CreateAssetTool ailleurs) reste verifie manuellement contre une instance
 * reelle pour tout ce qui depend vraiment du modele — la persistance, la
 * generation d'URL. L'ecriture physique du fichier, elle (Asset::upload()),
 * n'a besoin d'aucun modele et se verifie ici : voir
 * testUploadOfAnImageStoresTheFileWhereMauticWillLookForIt().
 */
class AttachmentManagerTest extends TestCase
{
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->mediaDir = sys_get_temp_dir().'/witty_media_test_'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->mediaDir)) {
            $this->removeDir($this->mediaDir);
        }
    }

    public function testUploadRejectsAnExtensionNotOnTheAllowlist(): void
    {
        $manager = $this->manager();

        $this->expectException(AttachmentInvalidException::class);

        $manager->upload($this->uploadedFile('script.exe'));
    }

    public function testUploadRejectsAnExtensionDisabledBySitePolicy(): void
    {
        // "csv" fait partie de l'allowlist Witty mais l'administrateur Mautic
        // l'a retire de la politique globale du site : ca doit rester bloquant.
        $manager = $this->manager(['allowed_extensions' => ['jpg', 'png']]);

        $this->expectException(AttachmentInvalidException::class);

        $manager->upload($this->uploadedFile('leads.csv', "Email\na@b.test\n"));
    }

    public function testUploadRejectsAFileLargerThanTheSiteMaxSize(): void
    {
        // max_size en Mo ; un fichier de quelques octets doit deja depasser 0.0001 Mo.
        $manager = $this->manager(['max_size' => 0.0001]);

        $this->expectException(AttachmentInvalidException::class);

        $manager->upload($this->uploadedFile('notes.txt', str_repeat('a', 1000)));
    }

    public function testUploadDetectsSpreadsheetKindAndMovesFileToDisk(): void
    {
        $manager    = $this->manager();
        $attachment = $manager->upload($this->uploadedFile('leads.csv', "Email\na@b.test\n"));

        $this->assertSame(WittyAttachment::KIND_SPREADSHEET, $attachment->getKind());
        $this->assertSame('csv', $attachment->getExtension());
        $this->assertNotSame('', $attachment->getStoredFilename());
        $this->assertFileExists($this->mediaDir.'/witty/uploads/'.$attachment->getStoredFilename());
    }

    public function testUploadDetectsTextKind(): void
    {
        $manager    = $this->manager();
        $attachment = $manager->upload($this->uploadedFile('brief.md', '# Brief'));

        $this->assertSame(WittyAttachment::KIND_TEXT, $attachment->getKind());
    }

    public function testUploadIsNotPinnedByDefault(): void
    {
        $manager    = $this->manager();
        $attachment = $manager->upload($this->uploadedFile('brief.md', '# Brief'));

        $this->assertFalse($attachment->isPinned(), 'Un upload depuis le chat ne doit pas survivre au nettoyage automatique par defaut.');
    }

    public function testUploadCanBePinnedForTheFilesLibrary(): void
    {
        $manager    = $this->manager();
        $attachment = $manager->upload($this->uploadedFile('brief.md', '# Brief'), null, true);

        $this->assertTrue($attachment->isPinned());
    }

    public function testDeleteRemovesBothTheEntityAndThePhysicalFile(): void
    {
        $manager    = $this->manager();
        $attachment = $manager->upload($this->uploadedFile('leads.csv', "Email\na@b.test\n"), null, true);
        $path       = $this->mediaDir.'/witty/uploads/'.$attachment->getStoredFilename();

        $this->assertFileExists($path);

        $manager->delete($attachment);

        $this->assertFileDoesNotExist($path);
    }

    public function testUploadOfAnImageDoesNotCrash(): void
    {
        // Asset::$tempId n'a pas de valeur par defaut (null). Sans
        // Asset::setTempId() avant preUpload()/upload() — comme le fait
        // toujours AssetController (core), meme hors upload par morceaux —
        // Asset::upload() plante en sortie : getAbsoluteTempDir() renvoie null
        // (tempId absent), passe tel quel a Filesystem::remove(), qui n'accepte
        // plus null (TypeError). Chaque import d'image/document echouait donc
        // purement et simplement avant meme la question de savoir ou le
        // fichier atterrit (cf. testUploadOfAnImageStoresTheFileWhereMauticWillLookForIt).
        $manager = $this->manager();

        $attachment = $manager->upload($this->uploadedFile('photo.png', 'fake-png-bytes'));

        $this->assertSame(WittyAttachment::KIND_IMAGE, $attachment->getKind());
    }

    public function testUploadOfAnImageStoresTheFileWhereMauticWillLookForIt(): void
    {
        // Asset::getUploadDir() retombe sur 'media/files' (chemin RELATIF,
        // fige dans Mautic core) si personne n'appelle setUploadDir()
        // explicitement avant preUpload()/upload(). C'est exactement ce que
        // PublicController::localDownloadResponse() (core) fait avant de lire
        // le fichier pour servir l'URL de l'asset : sans le meme appel ici,
        // l'upload "reussit" sans erreur mais l'asset devient introuvable (404)
        // des qu'on essaie de l'utiliser — upload_dir configure explicitement
        // ici (different de mediaDir) pour que le test echoue si jamais
        // AttachmentManager arretait d'appeler setUploadDir().
        $uploadDir = $this->mediaDir.'/assets';
        $manager   = $this->manager(['upload_dir' => $uploadDir]);

        $attachment = $manager->upload($this->uploadedFile('photo.png', 'fake-png-bytes'));

        $this->assertSame(WittyAttachment::KIND_IMAGE, $attachment->getKind());
        $this->assertNotSame('', $attachment->getStoredFilename());
        $this->assertFileExists(
            $uploadDir.'/'.$attachment->getStoredFilename(),
            'Le fichier doit atterrir dans upload_dir (celui que Mautic sert), pas dans un chemin relatif par defaut.',
        );
    }

    public function testResolveThrowsWhenAttachmentDoesNotBelongToTheCurrentUser(): void
    {
        $repository = $this->createMock(WittyAttachmentRepository::class);
        $repository->method('findOneForUser')->with(42, 1)->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $manager = $this->manager([], $entityManager);

        $this->expectException(AttachmentNotFoundException::class);

        $manager->resolve(42);
    }

    /**
     * @param array<string, mixed> $siteParameters
     */
    private function manager(array $siteParameters = [], ?EntityManagerInterface $entityManager = null): AttachmentManager
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn($user);

        $pathsHelper = $this->createMock(PathsHelper::class);
        $pathsHelper->method('getMediaPath')->willReturn($this->mediaDir);

        $coreParameters = $this->createMock(CoreParametersHelper::class);
        $coreParameters->method('get')->willReturnCallback(
            static fn (string $name, mixed $default = null): mixed => $siteParameters[$name] ?? $default,
        );

        return new AttachmentManager(
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $userHelper,
            $pathsHelper,
            $coreParameters,
            $this->createMock(AssetModel::class),
            new SpreadsheetReader(),
        );
    }

    private function uploadedFile(string $originalName, string $content = 'contenu'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'witty_upload_');
        file_put_contents($path, $content);

        return new UploadedFile($path, $originalName, null, null, true);
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir) ?: [];

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
