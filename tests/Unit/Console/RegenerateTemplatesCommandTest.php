<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\Command\RegenerateTemplatesCommand;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class RegenerateTemplatesCommandTest extends TestCase
{
    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-regen-test-'
            . bin2hex(random_bytes(4));
        if (!mkdir($this->tempBaseDir, 0775, true) && !is_dir($this->tempBaseDir)) {
            $this->fail("Could not create temp dir: {$this->tempBaseDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempBaseDir);
    }

    public function test_partial_filter_does_not_wipe_unrelated_files(): void
    {
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $unrelatedPath = $writeDir . DIRECTORY_SEPARATOR . 'players-8-partners-2-repeat-1-courts-1.json';
        file_put_contents($unrelatedPath, '{"sentinel":"keep"}');

        $tester = $this->makeTester(new RecordingGenerator());
        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $this->assertFileExists($unrelatedPath);
        $this->assertSame('{"sentinel":"keep"}', file_get_contents($unrelatedPath));
    }

    public function test_single_combo_mode_writes_to_next_version_directory(): void
    {
        $generator = new RecordingGenerator();
        $tester = $this->makeTester($generator);

        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertCount(1, $generator->calls);

        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $expected = $this->tempBaseDir
            . DIRECTORY_SEPARATOR . 'v' . $writeVersion
            . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json';
        $this->assertFileExists($expected);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Currently in use: v' . TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $output);
        $this->assertStringContainsString('Writing to: v' . $writeVersion, $output);
        $this->assertStringContainsString('DEFAULT_TEMPLATE_VERSION to ' . $writeVersion, $output);
    }

    public function test_buffered_output_contains_pairing_and_ordering_lines(): void
    {
        $tester = $this->makeTester(new RecordingGenerator());

        $tester->execute([
            '--players' => '4',
            '--partners' => '2',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('pairing', $output);
        $this->assertMatchesRegularExpression('/sorting|ordering/i', $output);
    }

    public function test_buffered_output_omits_seed_tag_for_single_seed(): void
    {
        $tester = $this->makeTester(new RecordingGenerator());

        $tester->execute([
            '--players' => '4',
            '--partners' => '2',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('pairing', $output);
        $this->assertStringNotContainsString('seed ', $output);
    }

    public function test_buffered_output_shows_seed_tag_when_multi_seed_active(): void
    {
        $generator = (new RecordingGenerator())->setPairingSeedContext(4, 4);
        $tester = $this->makeTester($generator);

        $tester->execute([
            '--players' => '4',
            '--partners' => '2',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('pairing', $output);
        $this->assertStringContainsString('seed 4/4', $output);
    }

    public function test_single_combo_mode_does_not_touch_in_use_directory(): void
    {
        $tester = $this->makeTester(new RecordingGenerator());

        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $inUseDir = $this->tempBaseDir
            . DIRECTORY_SEPARATOR . 'v' . TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION;
        $this->assertDirectoryDoesNotExist($inUseDir);
    }

    public function test_bulk_mode_iterates_combinations_and_writes_a_file_per_combo(): void
    {
        $generator = new RecordingGenerator();
        $tester = $this->makeTester($generator);
        $tester->execute([]);

        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;

        $expectedCount = 0;
        foreach (TemplateMatchesGenerator::COMBINATIONS as $partnersList) {
            $expectedCount += count($partnersList);
        }

        $this->assertCount($expectedCount, $generator->calls);
        $produced = glob($writeDir . DIRECTORY_SEPARATOR . 'players-*.json');
        $this->assertCount($expectedCount, $produced);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('DEFAULT_TEMPLATE_VERSION to ' . $writeVersion, $output);
    }

    public function test_buffered_fallback_emits_phase_done_lines(): void
    {
        $tester = $this->makeTester(new RecordingGenerator());

        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('pairing done', $output);
        $this->assertMatchesRegularExpression('/ordering done|trivial/i', $output);
    }

    public function test_unified_table_renders_after_run(): void
    {
        $tester = $this->makeTester(new RecordingGenerator());

        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $output = $tester->getDisplay();
        foreach (['TEAMS', 'PAIRING STATS', 'SORTING STATS'] as $group) {
            $this->assertStringContainsString($group, $output, "Group header missing: {$group}");
        }
    }

    public function test_bulk_mode_clears_stale_files_in_target_version_before_writing(): void
    {
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $stalePath = $writeDir . DIRECTORY_SEPARATOR . 'players-99-partners-3-repeat-1-courts-1.json';
        file_put_contents($stalePath, '{"stale": true}');

        $siblingPath = $writeDir . DIRECTORY_SEPARATOR . 'README.md';
        file_put_contents($siblingPath, '# notes');

        $tester = $this->makeTester(new RecordingGenerator());
        $tester->execute([]);

        $this->assertFileDoesNotExist($stalePath);
        $this->assertFileExists($siblingPath);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Cleared 1 stale file', $output);
    }

    public function test_single_combo_mode_does_not_clear_unrelated_files(): void
    {
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $unrelatedPath = $writeDir . DIRECTORY_SEPARATOR . 'players-8-partners-2-repeat-1-courts-1.json';
        file_put_contents($unrelatedPath, '{"preserved": true}');

        $tester = $this->makeTester(new RecordingGenerator());
        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($unrelatedPath);
        $this->assertSame('{"preserved": true}', file_get_contents($unrelatedPath));

        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('Cleared', $output);
    }

    private function makeTester(RecordingGenerator $generator): CommandTester
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $command = new RegenerateTemplatesCommand($generator, $repository);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('templates:regenerate'));
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach ($entries as $entry) {
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
