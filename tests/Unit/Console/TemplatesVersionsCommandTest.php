<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\Command\TemplatesVersionsCommand;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\TemplateVersionTestTrait;

final class TemplatesVersionsCommandTest extends TestCase
{
    use TemplateVersionTestTrait;

    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->resetAllocatedVersions();
        $this->tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-templates-versions-test-'
            . bin2hex(random_bytes(4));

        if (!mkdir($this->tempBaseDir, 0775, true) && !is_dir($this->tempBaseDir)) {
            $this->fail("Could not create temp dir: {$this->tempBaseDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempBaseDir);
    }

    public function test_empty_base_dir_exits_zero_with_informative_message(): void
    {
        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No template version directories found.', $tester->getDisplay());
    }

    public function test_incompatible_dir_is_shown_but_catalog_is_suppressed(): void
    {
        $incompatibleDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v1-no-compatibility';
        mkdir($incompatibleDir, 0775, true);
        file_put_contents(
            $incompatibleDir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json',
            '{"sentinel": "x"}'
        );

        $tester = $this->makeTester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('v1-no-compatibility', $output);
        $this->assertStringContainsString('—', $output);
        $this->assertStringContainsString('| no |', $this->normalizeForContains($output));
    }

    public function test_single_compatible_dir_reports_expected_catalog_coverage(): void
    {
        $version = $this->allocVersion();
        $this->mkdirVersion($version);

        // Two expected combos: (4,1) and (4,2) with repeat=1/courts=1/fixedTeams=false.
        $this->putTemplateFile($version, 4, 1, 1, 1);
        $this->putTemplateFile($version, 4, 2, 1, 1);

        // One extra identity not in the expected catalog (courts=2).
        $this->putTemplateFile($version, 4, 1, 1, 2);

        $expectedCount = $this->expectedCatalogCount();
        $expectedPresent = 2;
        $expectedMissing = $expectedCount - $expectedPresent;
        $expectedExtra = 1;
        $expectedCatalogCell = sprintf('%d/%d', $expectedPresent, $expectedCount);

        $tester = $this->makeTester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('v' . $version, $output);
        $this->assertStringContainsString($expectedCatalogCell, $output);
        $this->assertStringContainsString((string) $expectedMissing, $output);
        $this->assertStringContainsString((string) $expectedExtra, $output);

        // Only one latest marker exists.
        $this->assertSame(1, substr_count($output, '*'));
    }

    public function test_latest_marker_is_assigned_to_highest_compatible_version(): void
    {
        $older = $this->allocVersion();
        $latest = $this->allocVersion();

        $this->mkdirVersion($older);
        $this->mkdirVersion($latest);

        $this->putTemplateFile($older, 4, 1, 1, 1);
        $this->putTemplateFile($latest, 4, 2, 1, 1);

        $expectedCount = $this->expectedCatalogCount();
        $expectedCatalogCell = sprintf('1/%d', $expectedCount);

        $tester = $this->makeTester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('v' . $older, $output);
        $this->assertStringContainsString('v' . $latest, $output);
        $this->assertSame(1, substr_count($output, '*'));
        $this->assertMatchesRegularExpression(
            '/v' . preg_quote((string) $latest, '/') . '[^\n]*\*/',
            $output
        );
        $this->assertStringContainsString($expectedCatalogCell, $output);
    }

    private function expectedCatalogCount(): int
    {
        $count = 0;
        foreach (TemplateMatchesGenerator::COMBINATIONS as $partnersList) {
            $count += count($partnersList);
        }

        return $count;
    }

    private function makeTester(): CommandTester
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $command = new TemplatesVersionsCommand($repository);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('templates:versions'));
    }

    private function mkdirVersion(int $version): void
    {
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version, 0775, true);
    }

    private function putTemplateFile(
        int $version,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams = false
    ): void {
        $dir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        $filename = sprintf(
            'players-%d-partners-%d-repeat-%d-courts-%d%s.json',
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams ? '-fixedteams' : ''
        );

        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, '{"sentinel": true}');
    }

    private function normalizeForContains(string $s): string
    {
        // Table output may include varying whitespace; normalize to make substring checks stable.
        return trim(preg_replace('/\\s+/', ' ', $s) ?? $s);
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

