<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\Command\GenerateTemplatesCommand;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../../vendor/autoload.php';

final class GenerateTemplatesCommandTest extends TestCase
{
    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-generate-test-'
            . bin2hex(random_bytes(4));
        if (!mkdir($this->tempBaseDir, 0775, true) && !is_dir($this->tempBaseDir)) {
            $this->fail("Could not create temp dir: {$this->tempBaseDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempBaseDir);
    }

    public function test_partial_options_are_rejected(): void
    {
        $tester = $this->makeTester();

        $tester->execute([
            '--players' => '8',
            '--partners' => '2',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('All-or-nothing', $output);
        $this->assertStringContainsString('--repeat', $output);
        $this->assertStringContainsString('--fixed-teams', $output);
    }

    public function test_single_combo_mode_writes_to_next_version_when_target_is_missing(): void
    {
        $tester = $this->makeTester();

        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $expected = $this->tempBaseDir
            . DIRECTORY_SEPARATOR . 'v' . $writeVersion
            . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1.json';
        $this->assertFileExists($expected);
    }

    public function test_single_combo_mode_is_noop_when_target_already_exists(): void
    {
        // Pre-populate the target file with a sentinel that the generator would never produce.
        // The command must detect it via hasAt(), log "already exists", and leave the file alone.
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $targetDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1.json';
        $sentinel = '{"sentinel": true}';
        file_put_contents($targetPath, $sentinel);

        $tester = $this->makeTester();
        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame($sentinel, file_get_contents($targetPath));

        $output = $tester->getDisplay();
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringContainsString('templates:regenerate to overwrite', $output);
    }

    public function test_bulk_mode_generates_only_missing_combos(): void
    {
        // Pre-populate one combo (4-1) so the bulk run can prove it filters that one out.
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $preservedPath = $writeDir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1.json';
        $sentinel = '{"sentinel": "preserved"}';
        file_put_contents($preservedPath, $sentinel);

        // Tight 300ms budgets so the bulk run stays under the unit-suite threshold. The exit code
        // is the failure count, which we tolerate -- the assertion is "every missing combo
        // produced a file", not "every combo found an optimum".
        $generator = new TemplateMatchesGenerator(
            null,
            300_000_000,
            300_000_000,
            0.0,
            0.0
        );
        $generator->setPerComboBudgetsNs([]);
        $repository = new TemplateMatchesRepository($this->tempBaseDir);

        $command = new GenerateTemplatesCommand($generator, $repository);
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('templates:generate'));

        $tester->execute([]);

        // The pre-existing file is untouched byte-for-byte.
        $this->assertSame($sentinel, file_get_contents($preservedPath));

        // The output reports the single skip.
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Skipped 1 combo', $output);

        // The remaining combos still produced a file each.
        $expectedTotal = 0;
        foreach (TemplateMatchesGenerator::COMBINATIONS as $partnersList) {
            $expectedTotal += count($partnersList);
        }
        $produced = glob($writeDir . DIRECTORY_SEPARATOR . 'players-*.json');
        $this->assertSame($expectedTotal, count($produced));
    }

    public function test_bulk_mode_is_fully_noop_when_all_combos_already_present(): void
    {
        // Pre-populate every combo in COMBINATIONS with a sentinel so the bulk run has nothing
        // left to do. The command must exit cleanly, report "Nothing to generate", and leave
        // every file unchanged.
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }

        $sentinels = [];
        foreach (TemplateMatchesGenerator::COMBINATIONS as $players => $partnersList) {
            foreach ($partnersList as $partners) {
                $path = $writeDir . DIRECTORY_SEPARATOR . sprintf(
                    'players-%d-partners-%d-repeat-1.json',
                    $players,
                    $partners
                );
                $bytes = sprintf('{"sentinel": "p%d-q%d"}', $players, $partners);
                file_put_contents($path, $bytes);
                $sentinels[$path] = $bytes;
            }
        }

        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Nothing to generate', $output);

        foreach ($sentinels as $path => $bytes) {
            $this->assertSame($bytes, file_get_contents($path), "File mutated: {$path}");
        }
    }

    public function test_bulk_mode_does_not_wipe_unrelated_sibling_files(): void
    {
        // Drop a README and a foreign players-* file under v{writeVersion}/; both must survive.
        // The foreign file has a combo not in COMBINATIONS so the bulk run won't claim it.
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $readmePath = $writeDir . DIRECTORY_SEPARATOR . 'README.md';
        $foreignPath = $writeDir . DIRECTORY_SEPARATOR . 'players-99-partners-3-repeat-1.json';
        file_put_contents($readmePath, '# notes');
        file_put_contents($foreignPath, '{"foreign": true}');

        $generator = new TemplateMatchesGenerator(
            null,
            300_000_000,
            300_000_000,
            0.0,
            0.0
        );
        $generator->setPerComboBudgetsNs([]);
        $repository = new TemplateMatchesRepository($this->tempBaseDir);

        $command = new GenerateTemplatesCommand($generator, $repository);
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('templates:generate'));

        $tester->execute([]);

        $this->assertFileExists($readmePath);
        $this->assertSame('# notes', file_get_contents($readmePath));
        $this->assertFileExists($foreignPath);
        $this->assertSame('{"foreign": true}', file_get_contents($foreignPath));
    }

    public function test_invalid_fixed_teams_value_throws(): void
    {
        $tester = $this->makeTester();

        $this->expectException(\InvalidArgumentException::class);

        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => 'maybe',
        ]);
    }

    private function makeTester(): CommandTester
    {
        $generator = new TemplateMatchesGenerator(
            null,
            10_000_000_000,
            10_000_000_000,
            0.0,
            0.0
        );
        $generator->setPerComboBudgetsNs([]);
        $repository = new TemplateMatchesRepository($this->tempBaseDir);

        $command = new GenerateTemplatesCommand($generator, $repository);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('templates:generate'));
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
