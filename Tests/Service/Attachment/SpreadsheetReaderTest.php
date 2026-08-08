<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Attachment;

use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Attachment\SpreadsheetReader;
use PHPUnit\Framework\TestCase;

/**
 * Wrapper mince autour de PhpSpreadsheet : ce qui merite un test ici, c'est la
 * mise en forme (premiere ligne = en-tetes, lignes vides ecartees, troncature
 * de preview), pas PhpSpreadsheet lui-meme.
 */
class SpreadsheetReaderTest extends TestCase
{
    private string $csvPath;

    protected function setUp(): void
    {
        $this->csvPath = tempnam(sys_get_temp_dir(), 'witty_csv_').'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csvPath);
    }

    public function testReadAllUsesFirstRowAsHeadersAndDropsBlankRows(): void
    {
        file_put_contents($this->csvPath, "Email,Prenom\njane@example.test,Jane\n\ndoe@example.test,Doe\n");

        $data = (new SpreadsheetReader())->readAll($this->csvPath);

        $this->assertSame(['Email', 'Prenom'], $data['headers']);
        $this->assertSame([
            ['jane@example.test', 'Jane'],
            ['doe@example.test', 'Doe'],
        ], $data['rows'], 'La ligne entierement vide ne doit pas compter comme une ligne de donnees.');
    }

    public function testPreviewCapsRowsButReportsTheRealTotal(): void
    {
        $lines = ['Email'];
        for ($i = 0; $i < 30; ++$i) {
            $lines[] = sprintf('lead%d@example.test', $i);
        }
        file_put_contents($this->csvPath, implode("\n", $lines));

        $preview = (new SpreadsheetReader())->preview($this->csvPath, 5);

        $this->assertCount(5, $preview['rows']);
        $this->assertSame(30, $preview['totalRows']);
    }

    public function testEmptyFileHasNoDataRows(): void
    {
        file_put_contents($this->csvPath, '');

        $data = (new SpreadsheetReader())->readAll($this->csvPath);

        // PhpSpreadsheet cree toujours au moins une cellule (dimension par
        // defaut A1) meme pour un fichier vide : ce qui compte est qu'aucune
        // ligne de donnees n'en resulte.
        $this->assertSame([], $data['rows']);
    }

    public function testUnreadableFileThrowsAttachmentInvalidException(): void
    {
        $this->expectException(AttachmentInvalidException::class);

        (new SpreadsheetReader())->readAll($this->csvPath.'.does-not-exist');
    }
}
