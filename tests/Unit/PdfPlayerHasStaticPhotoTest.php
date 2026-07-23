<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\DTO\PdfPlayer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class PdfPlayerHasStaticPhotoTest extends TestCase
{
    private string $tempRoot;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: '.';
        $this->tempRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-pdfplayer-photo-test-'
            . bin2hex(random_bytes(4));

        $participants = $this->tempRoot
            . DIRECTORY_SEPARATOR . 'statics'
            . DIRECTORY_SEPARATOR . 'media'
            . DIRECTORY_SEPARATOR . 'MiniTour-participants';

        if (!mkdir($participants, 0775, true) && !is_dir($participants)) {
            $this->fail('Could not create temp participants dir');
        }

        if (!chdir($this->tempRoot)) {
            $this->fail('Could not chdir into temp root');
        }
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->removeDirRecursive($this->tempRoot);
    }

    public function test_missing_photo_returns_false(): void
    {
        $this->assertFalse(PdfPlayer::hasStaticPhoto('Andrei Traian Tudor'));
    }

    /**
     * @dataProvider extensionProvider
     */
    public function test_existing_photo_returns_true_for_extension(string $extension): void
    {
        $this->putPhoto('jane-doe', $extension);

        $this->assertTrue(PdfPlayer::hasStaticPhoto('Jane Doe'));
    }

    public function test_slug_must_match_photo_filename_exactly(): void
    {
        $this->putPhoto('andrei-t-tudor', 'jpg');

        $this->assertFalse(PdfPlayer::hasStaticPhoto('Andrei Traian Tudor'));
        $this->assertTrue(PdfPlayer::hasStaticPhoto('Andrei T Tudor'));
    }

    /**
     * @return list<array{0: string}>
     */
    public function extensionProvider(): array
    {
        return [
            ['png'],
            ['jpg'],
            ['jpeg'],
        ];
    }

    private function putPhoto(string $slug, string $extension): void
    {
        $path = 'statics/media/MiniTour-participants/' . $slug . '.' . $extension;
        file_put_contents($path, 'x');
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
