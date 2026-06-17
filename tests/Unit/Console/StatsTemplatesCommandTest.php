<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\Command\StatsTemplatesCommand;
use Arshavinel\PadelMiniTour\Console\Command\StatsTemplatesFixedTeamsCommand;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__ . '/../../../vendor/autoload.php';

final class StatsTemplatesCommandTest extends TestCase
{
    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-stats-test-'
            . bin2hex(random_bytes(4));
        if (!mkdir($this->tempBaseDir, 0775, true) && !is_dir($this->tempBaseDir)) {
            $this->fail("Could not create temp dir: {$this->tempBaseDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempBaseDir);
    }

    public function test_mixed_stats_reports_missing_templates_with_nonzero_exit(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $tester->execute([]);

        $this->assertGreaterThan(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('missing', $output);
        $this->assertStringContainsString('templates:regenerate', $output);
    }

    public function test_mixed_stats_renders_table_for_present_template(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save(
            TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION,
            $this->makeTemplate(4, 1, 1, false)
        );

        $tester = $this->makeMixedTester($repository);
        $tester->execute(['--combinations' => '4:1']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Players', $output);
        $this->assertStringContainsString('4', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_unified_table_renders_operational_columns_for_present_template(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save(
            TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION,
            $this->makeTemplate(4, 1, 1, false)
        );

        $tester = $this->makeMixedTester($repository);
        $tester->execute(['--combinations' => '4:1']);

        $output = $tester->getDisplay();
        foreach (['TEAMS', 'PAIRING STATS', 'SORTING STATS'] as $group) {
            $this->assertStringContainsString($group, $output, "Group header missing: {$group}");
        }
        // Mixed runs render with (repeat=1, fixed=no) in the TEAMS group label.
        $this->assertStringContainsString('repeat: 1', $output);
        $this->assertStringContainsString('fixed: no', $output);
        foreach (['Players', 'Partners Var.', 'Min Met', 'Max Met', 'Min Break', 'Max Break', 'Pairing Idx.', 'Sorting Idx.'] as $col) {
            $this->assertStringContainsString($col, $output, "Detail column missing: {$col}");
        }
        $this->assertStringContainsString('exhausted', $output);
    }

    public function test_fixed_stats_default_combinations_is_eight_two_three(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save(TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $this->makeTemplate(8, 2, 1, true));
        $repository->save(TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $this->makeTemplate(8, 3, 1, true));

        $tester = $this->makeFixedTester($repository);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_unified_table_renders_repeat_and_fixed_columns_for_fixed_run(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save(TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $this->makeTemplate(8, 2, 1, true));
        $repository->save(TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $this->makeTemplate(8, 3, 1, true));

        $tester = $this->makeFixedTester($repository);
        $tester->execute([]);

        $output = $tester->getDisplay();
        // Fixed-teams runs annotate the TEAMS group with `fixed: yes`.
        $this->assertStringContainsString('TEAMS', $output);
        $this->assertStringContainsString('fixed: yes', $output);
    }

    public function test_mixed_stats_renders_sorting_stats_when_matches_null(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save(
            TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION,
            new TemplateMatches(
                12,
                8,
                1,
                2,
                false,
                null,
                2.0,
                256,
                4,
                248,
                3,
                [8, 8, 8, 8, 8, 8, 8, 8, 8, 8, 8, 8],
                [],
                null,
                null,
                'DEADLINE',
                5.1,
                'DEADLINE',
                null,
                null,
                0,
                null,
                null,
                null,
                148.0
            )
        );

        $tester = $this->makeMixedTester($repository);
        $tester->execute(['--players' => '12', '--partners' => '8', '--courts' => '2']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('infeasible', $output);
        $this->assertMatchesRegularExpression('/\|\s+12\s+\|\s+8\s+\|\s+2\s+\|\s+-/', $output);
        $this->assertStringContainsString('deadline', $output);
        $this->assertStringContainsString('2m 28s', $output);
        $this->assertStringContainsString('- / 0', $output);
    }

    public function test_invalid_combinations_token_raises_exception(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['--combinations' => 'broken']);
    }

    public function test_mixed_stats_header_reports_in_use_version_by_default(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save(
            TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION,
            $this->makeTemplate(4, 1, 1, false)
        );
        $tester = $this->makeMixedTester($repository);

        $tester->execute(['--combinations' => '4:1']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Reading: v' . TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $output);
        $this->assertStringContainsString('(in use)', $output);
        $this->assertStringNotContainsString('(in use: v', $output);
    }

    public function test_mixed_stats_with_explicit_version_reads_from_that_directory(): void
    {
        $otherVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $repository = new TemplateMatchesRepository($this->tempBaseDir);

        $repository->save($otherVersion, $this->makeTemplate(4, 1, 1, false));

        $tester = $this->makeMixedTester($repository);
        $tester->execute([
            '--combinations' => '4:1',
            '--templates-version' => (string) $otherVersion,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Reading: v' . $otherVersion, $output);
        $this->assertStringContainsString('(in use: v' . TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION . ')', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_mixed_stats_with_unknown_version_reports_no_matching_files(): void
    {
        $otherVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save(TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $this->makeTemplate(4, 1, 1, false));

        $tester = $this->makeMixedTester($repository);
        $tester->execute([
            '--combinations' => '4:1',
            '--templates-version' => (string) $otherVersion,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Combos: 0', $output);
        $this->assertStringContainsString('No template files match the provided filters', $output);
        $this->assertStringNotContainsString('missing under v' . $otherVersion, $output);
    }

    public function test_mixed_stats_with_courts_filter_lists_only_matching_files_in_version(): void
    {
        $version = 5;
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save($version, $this->makeTemplate(12, 8, 1, false));
        $repository->save(
            $version,
            new TemplateMatches(
                12,
                8,
                1,
                2,
                false,
                null,
                0.0,
                2,
                1,
                2,
                1,
                [0 => 1, 1 => 1, 2 => 1, 3 => 1],
                [],
                0,
                1,
                'FACTORIAL_COMPLETE',
                0.04,
                'FACTORIAL_COMPLETE',
                0.95,
                0.97,
                10,
                3,
                0,
                0,
                0.08
            )
        );

        $tester = $this->makeMixedTester($repository);
        $tester->execute([
            '--templates-version' => (string) $version,
            '--courts' => '2',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Combos: 1', $output);
        $this->assertMatchesRegularExpression('/\|\s+12\s+\|\s+8\s+\|\s+2\s+\|/', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_mixed_stats_with_players_partners_filter_lists_only_existing_file(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION;
        $repository->save($version, $this->makeTemplate(4, 1, 1, false));
        $repository->save(
            $version,
            new TemplateMatches(
                4,
                1,
                1,
                2,
                false,
                [[[[0, 1], [2, 3]]]],
                0.0,
                2,
                1,
                2,
                1,
                [0 => 1, 1 => 1, 2 => 1, 3 => 1],
                [],
                0,
                1,
                'FACTORIAL_COMPLETE',
                0.04,
                'FACTORIAL_COMPLETE',
                0.95,
                0.97,
                10,
                3,
                0,
                0,
                0.08
            )
        );

        $tester = $this->makeMixedTester($repository);
        $tester->execute(['--players' => '4', '--partners' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Combos: 1', $output);
        $this->assertMatchesRegularExpression('/\|\s+4\s+\|\s+1\s+\|\s+1\s+\|/', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_mixed_stats_with_combinations_filter_and_no_file_reports_empty(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $tester->execute(['--combinations' => '4:1']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Combos: 0', $output);
        $this->assertStringContainsString('No template files match the provided filters', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_mixed_stats_rejects_non_positive_integer_version(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['--templates-version' => '0']);
    }

    public function test_mixed_stats_rejects_garbage_version(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['--templates-version' => 'latest']);
    }

    public function test_fixed_stats_explicit_version_routes_to_that_directory(): void
    {
        $otherVersion = TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION + 1;
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save($otherVersion, $this->makeTemplate(8, 2, 1, true));
        $repository->save($otherVersion, $this->makeTemplate(8, 3, 1, true));

        $tester = $this->makeFixedTester($repository);
        $tester->execute(['--templates-version' => (string) $otherVersion]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Reading: v' . $otherVersion, $output);
        $this->assertStringContainsString('(in use: v' . TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION . ')', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    private function makeMixedTester(TemplateMatchesRepository $repository): CommandTester
    {
        $command = new StatsTemplatesCommand($repository);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('templates:stats'));
    }

    private function makeFixedTester(TemplateMatchesRepository $repository): CommandTester
    {
        $command = new StatsTemplatesFixedTeamsCommand($repository);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('templates:stats-fixed-teams'));
    }

    private function makeTemplate(int $players, int $partners, int $repeat, bool $fixedTeams): TemplateMatches
    {
        return new TemplateMatches(
            $players,
            $partners,
            $repeat,
            1,
            $fixedTeams,
            [[[[0, 1], [2, 3]]]],
            0.0,
            2,
            1,
            2,
            1,
            [0 => 1, 1 => 1, 2 => 1, 3 => 1],
            [
                0 => [1 => 1, 2 => 1, 3 => 1],
                1 => [0 => 1, 2 => 1, 3 => 1],
                2 => [0 => 1, 1 => 1, 3 => 1],
                3 => [0 => 1, 1 => 1, 2 => 1],
            ],
            0,
            1,
            'FACTORIAL_COMPLETE',
            0.04,
            'FACTORIAL_COMPLETE',
            0.95,
            0.97,
            10,
            3,
            0,
            0,
            0.08
        );
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
