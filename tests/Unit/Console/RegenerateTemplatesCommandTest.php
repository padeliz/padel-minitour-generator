<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\Command\RegenerateTemplatesCommand;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../../vendor/autoload.php';

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

    public function test_single_combo_mode_writes_to_next_version_directory(): void
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

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Currently in use: v' . TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $output);
        $this->assertStringContainsString('Writing to: v' . $writeVersion, $output);
        $this->assertStringContainsString('DEFAULT_TEMPLATE_VERSION to ' . $writeVersion, $output);
    }

    public function test_buffered_output_contains_pairing_and_ordering_lines_for_mixed(): void
    {
        $tester = $this->makeTester();

        $tester->execute([
            '--players' => '4',
            '--partners' => '2',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('pairing', $output);
        $this->assertStringContainsString('ordering', $output);
    }

    public function test_buffered_output_contains_pairing_and_ordering_lines_for_fixed(): void
    {
        $tester = $this->makeTester();

        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '1',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('pairing', $output);
        $this->assertStringContainsString('ordering', $output);
    }

    public function test_buffered_output_omits_seed_tag_for_below_threshold_combos(): void
    {
        // 4/2 has 4 pairs, well below the multi-seed threshold (12), so the renderer must show no
        // "seed N/K" prefix at all in the pairing line.
        $tester = $this->makeTester();

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
        // Force multi-seed on a small combo so the test stays fast: threshold=2 makes any combo
        // multi-seed, count=4 gives a "seed 4/4" final.
        $generator = new TemplateMatchesGenerator(
            null,
            10_000_000_000,
            10_000_000_000,
            4,
            2
        );
        $generator->setPerComboBudgetsNs([]);
        $repository = new TemplateMatchesRepository($this->tempBaseDir);

        $command = new RegenerateTemplatesCommand($generator, $repository);
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('templates:regenerate'));

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
        $tester = $this->makeTester();

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
        // Tight budgets so the bulk run stays under the unit-suite threshold even when each combo
        // bumps against its outer deadline. The exit code is the failure count, which we tolerate
        // here: the assertion is "every combo produced a file", not "every combo found an optimum".
        $generator = new TemplateMatchesGenerator(
            null,
            300_000_000,
            300_000_000,
            0.0,
            0.0
        );
        // Wipe the per-combo budget map so the constructor-injected 300ms applies uniformly. The
        // production map gives the hard combos 30 minutes each, which would balloon this test from
        // a few seconds to several hours.
        $generator->setPerComboBudgetsNs([]);
        $repository = new TemplateMatchesRepository($this->tempBaseDir);

        $command = new RegenerateTemplatesCommand($generator, $repository);
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('templates:regenerate'));

        $tester->execute([]);

        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;

        $expectedCount = 0;
        foreach (TemplateMatchesGenerator::COMBINATIONS as $partnersList) {
            $expectedCount += count($partnersList);
        }

        $produced = glob($writeDir . DIRECTORY_SEPARATOR . 'players-*.json');
        $this->assertNotEmpty($produced);
        $this->assertSame($expectedCount, count($produced));

        $output = $tester->getDisplay();
        $this->assertStringContainsString('DEFAULT_TEMPLATE_VERSION to ' . $writeVersion, $output);
    }

    public function test_buffered_fallback_emits_phase_done_lines(): void
    {
        // The non-TTY fallback (CommandTester's BufferedOutput) writes one compact "phase done"
        // line per isFinal event. This guards the buffered path against accidental removal of the
        // text trail downstream tooling / CI logs depend on.
        $tester = $this->makeTester();

        $tester->execute([
            '--players' => '4',
            '--partners' => '1',
            '--repeat' => '1',
            '--fixed-teams' => '0',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('pairing done', $output);
        $this->assertStringContainsString('ordering done', $output);
    }

    public function test_unified_table_renders_after_run(): void
    {
        $tester = $this->makeTester();

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
        $this->assertStringContainsString('repeat: 1', $output);
        $this->assertStringContainsString('fixed: no', $output);
        foreach (['Players', 'Partners Var.', 'Min Met', 'Max Met', 'Min Break', 'Max Break', 'Pairing Idx.', 'Sorting Idx.'] as $col) {
            $this->assertStringContainsString($col, $output, "Detail column missing: {$col}");
        }
    }

    public function test_bulk_mode_clears_stale_files_in_target_version_before_writing(): void
    {
        // Seed v{writeVersion}/ with a stale template that is NOT in COMBINATIONS (e.g. left over
        // from a prior schema or an interrupted run). Bulk mode must delete it before regenerating.
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $stalePath = $writeDir . DIRECTORY_SEPARATOR . 'players-99-partners-3-repeat-1.json';
        file_put_contents($stalePath, '{"stale": true}');

        // Also drop a non-template sibling: it must survive the wipe.
        $siblingPath = $writeDir . DIRECTORY_SEPARATOR . 'README.md';
        file_put_contents($siblingPath, '# notes');

        $generator = new TemplateMatchesGenerator(
            null,
            300_000_000,
            300_000_000,
            0.0,
            0.0
        );
        $generator->setPerComboBudgetsNs([]);
        $repository = new TemplateMatchesRepository($this->tempBaseDir);

        $command = new RegenerateTemplatesCommand($generator, $repository);
        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('templates:regenerate'));

        $tester->execute([]);

        $this->assertFileDoesNotExist($stalePath);
        $this->assertFileExists($siblingPath);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Cleared 1 stale file', $output);
    }

    public function test_single_combo_mode_does_not_clear_unrelated_files(): void
    {
        // Single-combo mode must surgically overwrite only its own file - other templates that
        // already exist under v{writeVersion}/ from prior runs must survive.
        $writeVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $writeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $writeVersion;
        if (!is_dir($writeDir)) {
            mkdir($writeDir, 0775, true);
        }
        $unrelatedPath = $writeDir . DIRECTORY_SEPARATOR . 'players-8-partners-2-repeat-1.json';
        file_put_contents($unrelatedPath, '{"preserved": true}');

        $tester = $this->makeTester();
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
