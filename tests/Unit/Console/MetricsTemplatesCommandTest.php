<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\Command\MetricsTemplatesCommand;
use Arshavinel\PadelMiniTour\Console\Command\MetricsTemplatesFixedTeamsCommand;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\TemplateVersionTestTrait;

require_once __DIR__ . '/../../../vendor/autoload.php';

final class MetricsTemplatesCommandTest extends TestCase
{
    use TemplateVersionTestTrait;

    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->resetAllocatedVersions();
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

    public function test_missing_templates_version_exits_one(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--templates-version', $tester->getDisplay());
    }

    public function test_mixed_stats_reports_missing_templates_with_nonzero_exit(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $this->metricsExecute($tester, $repository);

        $this->assertGreaterThan(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('missing', $output);
        $this->assertStringContainsString('templates:regenerate', $output);
    }

    public function test_mixed_stats_renders_table_for_present_template(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repository->save(
            $version,
            $this->makeTemplate(4, 1, 1, false)
        );

        $tester = $this->makeMixedTester($repository);
        $this->metricsExecute($tester, $repository, ['--combinations' => '4:1']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Players', $output);
        $this->assertStringContainsString('4', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_unified_table_renders_operational_columns_for_present_template(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repository->save(
            $version,
            $this->makeTemplate(4, 1, 1, false)
        );

        $tester = $this->makeMixedTester($repository);
        $this->metricsExecute($tester, $repository, ['--combinations' => '4:1']);

        $output = $tester->getDisplay();
        foreach (['TEAMS', 'PAIRING STATS', 'ORDERING STATS'] as $group) {
            $this->assertStringContainsString($group, $output, "Group header missing: {$group}");
        }
        // Uniform filters appear on the context line above the table.
        $this->assertStringContainsString('repeat: 1', $output);
        $this->assertStringContainsString('fixed: no', $output);
        $this->assertStringContainsString('courts: 1', $output);
        foreach (['Players', 'Min Opponents', 'Max Opponents', 'Matches', 'Min Break', 'Max Break', 'Perm. Idx.', 'Templates'] as $col) {
            $this->assertStringContainsString($col, $output, "Detail column missing: {$col}");
        }
        $this->assertStringNotContainsString('Partners Var.', $output);
        $this->assertStringNotContainsString('Court Sw.', $output);
        $this->assertStringNotContainsString('Pair Count', $output);
        $this->assertStringNotContainsString('Best Matches', $output);
        $this->assertStringNotContainsString('Scheduled', $output);
        $this->assertStringContainsString('exhausted', $output);
    }

    public function test_fixed_stats_default_combinations_is_eight_two_three(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repository->save($version, $this->makeTemplate(8, 2, 1, true));
        $repository->save($version, $this->makeTemplate(8, 3, 1, true));

        $tester = $this->makeFixedTester($repository);
        $this->fixedMetricsExecute($tester, $repository);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_unified_table_renders_repeat_and_fixed_columns_for_fixed_run(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repository->save($version, $this->makeTemplate(8, 2, 1, true));
        $repository->save($version, $this->makeTemplate(8, 3, 1, true));

        $tester = $this->makeFixedTester($repository);
        $this->fixedMetricsExecute($tester, $repository);

        $output = $tester->getDisplay();
        // Fixed-teams runs annotate the TEAMS group with `fixed: yes`.
        $this->assertStringContainsString('TEAMS', $output);
        $this->assertStringContainsString('fixed: yes', $output);
    }

    public function test_mixed_metrics_renders_ordering_stats_when_matches_null(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repository->save(
            $version,
            new TemplateMatches(
                12,
                8,
                1,
                2,
                false,
                null,
                0.9,
                0.92,
                array_fill(0, 12, 8),
                0,
                48,
                'DEADLINE',
                5.1,
                5000,
                1,
                1,
                2.0,
                6,
                8,
                [],
                null,
                256,
                248,
                4,
                248,
                'DEADLINE',
                148.0,
                3,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                'DEADLINE',
                0,
                null,
                0,
                1,
                1,
                148.0
            )
        );

        $tester = $this->makeMixedTester($repository);
        $this->metricsExecute($tester, $repository, ['--players' => '12', '--partners' => '8', '--courts' => '2']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('infeasible', $output);
        $this->assertMatchesRegularExpression('/\|\s+12\s+\|\s+8\s+\|/', $output);
        $this->assertStringNotContainsString('Scheduled', $output);
        $this->assertStringContainsString('courts: 2', $output);
        $this->assertStringContainsString('Court Sw.', $output);
        $this->assertStringNotContainsString('Partners Var.', $output);
        $this->assertStringContainsString('deadline', $output);
        $this->assertStringContainsString('2m 28s', $output);
        $this->assertStringContainsString('- / 0', $output);
    }

    public function test_unified_table_includes_courts_column_when_courts_vary_across_rows(): void
    {
        $harness = new class {
            use \Arshavinel\PadelMiniTour\Console\MetricsFormatterTrait;

            /**
             * @param list<array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool}> $combos
             * @param list<TemplateMatches|null> $templates
             * @return array{includeCourtsColumn: bool, includePartnersVarColumn: bool, includeCourtSwitchesColumn: bool, contextParts: list<string>}
             */
            public function layout(array $combos, array $templates = []): array
            {
                return $this->resolveUnifiedTableLayout($combos, $templates);
            }
        };

        $layout = $harness->layout([
            ['players' => 12, 'partners' => 8, 'repeat' => 1, 'courts' => 1, 'fixedTeams' => false],
            ['players' => 12, 'partners' => 8, 'repeat' => 1, 'courts' => 2, 'fixedTeams' => false],
        ]);

        $this->assertTrue($layout['includeCourtsColumn']);
        $this->assertFalse($layout['includePartnersVarColumn']);
        $this->assertTrue($layout['includeCourtSwitchesColumn']);
        $this->assertNotContains('courts: 1', $layout['contextParts']);
        $this->assertNotContains('courts: 2', $layout['contextParts']);

        $uniformCourts = $harness->layout([
            ['players' => 4, 'partners' => 1, 'repeat' => 1, 'courts' => 1, 'fixedTeams' => false],
        ], [$this->makeTemplate(4, 1, 1, false)]);

        $this->assertFalse($uniformCourts['includeCourtsColumn']);
        $this->assertFalse($uniformCourts['includePartnersVarColumn']);
        $this->assertFalse($uniformCourts['includeCourtSwitchesColumn']);
    }

    public function test_unified_table_includes_partners_var_column_when_any_template_has_variation(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $template = $this->makeTemplate(4, 1, 1, false, 1);
        $repository->save($version, $template);

        $tester = $this->makeMixedTester($repository);
        $this->metricsExecute($tester, $repository, ['--combinations' => '4:1']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Partners Var.', $output);
        $this->assertStringNotContainsString('Court Sw.', $output);
    }

    public function test_unified_table_includes_court_switches_column_when_any_combo_has_multiple_courts(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repository->save(
            $version,
            new TemplateMatches(
                12,
                8,
                1,
                2,
                false,
                null,
                0.95,
                0.97,
                array_fill(0, 12, 8),
                0,
                48,
                'FACTORIAL_COMPLETE',
                0.04,
                100,
                1,
                1,
                0.0,
                8,
                8,
                [],
                1,
                10,
                3,
                10,
                3,
                'FACTORIAL_COMPLETE',
                0.08,
                1,
                null,
                0.95,
                0.97,
                0,
                0,
                0,
                null,
                1,
                'FACTORIAL_COMPLETE',
                10,
                3,
                50,
                1,
                1,
                0.08
            )
        );

        $tester = $this->makeMixedTester($repository);
        $this->metricsExecute($tester, $repository, ['--players' => '12', '--partners' => '8', '--courts' => '2']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Court Sw.', $output);
        $this->assertStringNotContainsString('Partners Var.', $output);
    }

    public function test_invalid_combinations_token_raises_exception(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $this->expectException(\InvalidArgumentException::class);
        $this->metricsExecute($tester, $repository, ['--combinations' => 'broken']);
    }

    public function test_mixed_stats_header_reports_latest_when_reading_latest_version(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repository->save(
            $version,
            $this->makeTemplate(4, 1, 1, false)
        );
        $tester = $this->makeMixedTester($repository);

        $this->metricsExecute($tester, $repository, ['--combinations' => '4:1']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Reading: v' . $version, $output);
        $this->assertStringContainsString('(latest)', $output);
        $this->assertStringNotContainsString('(latest: v', $output);
    }

    public function test_mixed_stats_with_explicit_version_reads_from_that_directory(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $older = $this->allocVersion();
        $latest = $this->allocVersion();
        $repository->save($older, $this->makeTemplate(4, 1, 1, false));
        $repository->save($latest, $this->makeTemplate(4, 1, 1, false));

        $tester = $this->makeMixedTester($repository);
        $this->metricsExecute($tester, $repository, [
            '--combinations' => '4:1',
            '--templates-version' => (string) $older,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Reading: v' . $older, $output);
        $this->assertStringContainsString('(latest: v' . $latest . ')', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_mixed_stats_with_unknown_version_reports_no_matching_files(): void
    {
        $seeded = $this->allocVersion();
        $unknown = $this->allocVersion();
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $repository->save($seeded, $this->makeTemplate(4, 1, 1, false));

        $tester = $this->makeMixedTester($repository);
        $tester->execute([
            '--combinations' => '4:1',
            '--templates-version' => (string) $unknown,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Combos: 0', $output);
        $this->assertStringContainsString('No template files match the provided filters', $output);
        $this->assertStringNotContainsString('missing under v' . $unknown, $output);
    }

    public function test_mixed_stats_with_courts_filter_lists_only_matching_files_in_version(): void
    {
        $version = $this->allocVersion();
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
                0.95,
                0.97,
                array_fill(0, 12, 8),
                0,
                48,
                'FACTORIAL_COMPLETE',
                0.04,
                100,
                1,
                1,
                0.0,
                8,
                8,
                [],
                1,
                10,
                3,
                10,
                3,
                'FACTORIAL_COMPLETE',
                0.08,
                1,
                null,
                0.95,
                0.97,
                0,
                0,
                0,
                null,
                null,
                'FACTORIAL_COMPLETE',
                10,
                3,
                50,
                1,
                1,
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
        $this->assertStringContainsString('courts: 2', $output);
        $this->assertMatchesRegularExpression('/\|\s+12\s+\|\s+8\s+\|/', $output);
        $this->assertStringNotContainsString('Scheduled', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_mixed_stats_with_players_partners_filter_lists_only_existing_file(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
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
                0.95,
                0.97,
                [0 => 1, 1 => 1, 2 => 1, 3 => 1],
                0,
                2,
                'FACTORIAL_COMPLETE',
                0.04,
                100,
                1,
                1,
                0.0,
                1,
                3,
                [],
                1,
                10,
                3,
                10,
                3,
                'FACTORIAL_COMPLETE',
                0.08,
                1,
                null,
                0.95,
                0.97,
                0,
                0,
                0,
                null,
                1,
                'FACTORIAL_COMPLETE',
                10,
                3,
                50,
                1,
                1,
                0.08
            )
        );

        $tester = $this->makeMixedTester($repository);
        $this->metricsExecute($tester, $repository, ['--players' => '4', '--partners' => '1']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Combos: 1', $output);
        $this->assertMatchesRegularExpression('/\|\s+4\s+\|\s+1\s+\|/', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    public function test_mixed_stats_with_combinations_filter_and_no_file_reports_empty(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $this->metricsExecute($tester, $repository, ['--combinations' => '4:1']);

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

        $tester->execute(['--templates-version' => '0']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--templates-version', $tester->getDisplay());
    }

    public function test_mixed_stats_rejects_garbage_version(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $tester = $this->makeMixedTester($repository);

        $tester->execute(['--templates-version' => 'latest']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--templates-version', $tester->getDisplay());
    }

    public function test_fixed_stats_explicit_version_routes_to_that_directory(): void
    {
        $repository = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repository->save($version, $this->makeTemplate(8, 2, 1, true));
        $repository->save($version, $this->makeTemplate(8, 3, 1, true));

        $tester = $this->makeFixedTester($repository);
        $tester->execute(['--templates-version' => (string) $version]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Reading: v' . $version, $output);
        $this->assertStringContainsString('(latest)', $output);
        $this->assertStringNotContainsString('missing', $output);
    }

    private function metricsExecute(
        CommandTester $tester,
        TemplateMatchesRepository $repository,
        array $args = []
    ): void {
        if (!array_key_exists('--templates-version', $args)) {
            try {
                $version = $repository->latestVersion();
            } catch (\RuntimeException $e) {
                $version = $this->allocVersion();
                $repository->ensureVersionDirectory($version);
            }
            $args['--templates-version'] = (string) $version;
        }
        $tester->execute($args);
    }

    private function fixedMetricsExecute(
        CommandTester $tester,
        TemplateMatchesRepository $repository,
        array $args = []
    ): void {
        if (!array_key_exists('--templates-version', $args)) {
            try {
                $version = $repository->latestVersion();
            } catch (\RuntimeException $e) {
                $version = $this->allocVersion();
                $repository->ensureVersionDirectory($version);
            }
            $args['--templates-version'] = (string) $version;
        }
        $tester->execute($args);
    }

    private function makeMixedTester(TemplateMatchesRepository $repository): CommandTester
    {
        $command = new MetricsTemplatesCommand($repository);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('templates:metrics'));
    }

    private function makeFixedTester(TemplateMatchesRepository $repository): CommandTester
    {
        $command = new MetricsTemplatesFixedTeamsCommand($repository);
        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('templates:metrics-fixed-teams'));
    }

    private function makeTemplate(
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        int $partnersCountVariation = 0
    ): TemplateMatches {
        $partnersCount = [];
        for ($i = 0; $i < $players; $i++) {
            $partnersCount[$i] = $partners;
        }

        return new TemplateMatches(
            $players,
            $partners,
            $repeat,
            1,
            $fixedTeams,
            [[[[0, 1], [2, 3]]]],
            0.95,
            0.97,
            $partnersCount,
            $partnersCountVariation,
            (int) ($players * $partners / 2),
            'FACTORIAL_COMPLETE',
            0.04,
            100,
            1,
            1,
            0.0,
            $partners,
            $partners,
            [],
            1,
            10,
            3,
            10,
            3,
            'FACTORIAL_COMPLETE',
            0.08,
            1,
            null,
            0.95,
            0.97,
            0,
            0,
            0,
            null,
            1,
            'FACTORIAL_COMPLETE',
            10,
            3,
            50,
            1,
            1,
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
