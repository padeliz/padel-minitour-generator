<?php

namespace Tests\Unit\Console;

use Arshavinel\PadelMiniTour\Console\Command\RecalculateQualityMetricsCommand;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\TemplateVersionTestTrait;

require_once __DIR__ . '/../../../vendor/autoload.php';

final class RecalculateQualityMetricsCommandTest extends TestCase
{
    use TemplateVersionTestTrait;

    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->resetAllocatedVersions();
        $this->tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-recalc-quality-test-'
            . bin2hex(random_bytes(4));
        if (!mkdir($this->tempBaseDir, 0775, true) && !is_dir($this->tempBaseDir)) {
            $this->fail("Could not create temp dir: {$this->tempBaseDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempBaseDir);
    }

    public function test_resolve_fails_when_no_directory_matches(): void
    {
        $tester = $this->makeTester();
        $tester->execute(['--templates-version' => '9']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No template directory matching v9', $tester->getDisplay());
    }

    public function test_resolve_fails_when_ambiguous_directories(): void
    {
        $version = $this->allocVersion();
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version);
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version . '-no-compatibility');

        $tester = $this->makeTester();
        $tester->execute(['--templates-version' => (string) $version]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Ambiguous template directories', $tester->getDisplay());
    }

    public function test_resolve_does_not_match_longer_version_prefix(): void
    {
        $version = $this->allocVersion();
        // e.g. v1 must not match v10 — use high versions to avoid collision with alloc
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version . '0');

        $tester = $this->makeTester();
        $tester->execute(['--templates-version' => (string) $version]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No template directory matching v' . $version, $tester->getDisplay());
    }

    public function test_filters_forbidden_on_suffix_directory(): void
    {
        $version = $this->allocVersion();
        $dir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version . '-legacy';
        mkdir($dir);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1.json',
            $this->legacyEligibleJson()
        );

        $tester = $this->makeTester();
        $tester->execute([
            '--templates-version' => (string) $version,
            '--players' => '4',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Filters are not allowed', $tester->getDisplay());
    }

    public function test_suffix_collision_with_clean_dir_fails(): void
    {
        $version = $this->allocVersion();
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version);
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version . '-legacy');

        $tester = $this->makeTester();
        $tester->execute(['--templates-version' => (string) $version]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Ambiguous template directories', $tester->getDisplay());
    }

    public function test_legacy_suffix_dir_migrates_renames_and_recalculates(): void
    {
        $version = $this->allocVersion();
        $suffixName = 'v' . $version . '-no-compatibility';
        $dir = $this->tempBaseDir . DIRECTORY_SEPARATOR . $suffixName;
        mkdir($dir);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1.json',
            $this->legacyEligibleJson()
        );

        $tester = $this->makeTester();
        $tester->execute(['--templates-version' => (string) $version]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($dir);
        $cleanDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        $this->assertDirectoryExists($cleanDir);

        $path = $cleanDir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json';
        $this->assertFileExists($path);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('metrics', $decoded);
        $this->assertSame(4, $decoded['players']);
        $this->assertSame(1, $decoded['courts']);
        $this->assertNotNull($decoded['metrics']['pairing']['quality']['minPartnersFairness']);
        $this->assertNotNull($decoded['metrics']['matchMaking']['quality']['meetingsVariation']);
        $this->assertSame(1, $decoded['metrics']['ordering']['quality']['roundsCount']);
        $this->assertSame(1, $decoded['metrics']['matchMaking']['quality']['matchesCount']);
        $this->assertSame(10.45, $decoded['metrics']['matchMaking']['stats']['time']);
        $this->assertArrayNotHasKey('generationTime', $decoded);
        $this->assertArrayNotHasKey('estimatedGenerationTime', $decoded);
    }

    public function test_matches_null_preserves_pairing_quality_on_clean_dir(): void
    {
        $version = $this->allocVersion();
        $dir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        mkdir($dir);

        $payload = [
            'players' => 8,
            'partners' => 4,
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
            'matches' => null,
            'metrics' => [
                'pairing' => [
                    'quality' => [
                        'minPartnersFairness' => 0.9,
                        'avgPartnersFairness' => 0.95,
                        'partnersCount' => [4, 4, 4, 4, 4, 4, 4, 4],
                        'partnersCountVariation' => 0,
                        'pairCount' => 16,
                    ],
                    'stats' => [
                        'stopReason' => 'DEADLINE',
                        'time' => 1.0,
                        'nodesExplored' => 10,
                        'seedIndex' => 1,
                        'seedsTotal' => 1,
                    ],
                ],
                'matchMaking' => [
                    'quality' => [
                        'meetingsVariation' => null,
                        'minOpponentsMet' => null,
                        'maxOpponentsMet' => null,
                        'playersMet' => null,
                        'matchesCount' => null,
                    ],
                    'stats' => [
                        'permutationsIterated' => 100,
                        'permutationIndex' => null,
                        'templatesGenerated' => 0,
                        'templateIndex' => null,
                        'nodesExplored' => null,
                        'stopReason' => 'DEADLINE',
                        'time' => 2.0,
                        'meetingsVariationLimit' => null,
                        'candidatesCollected' => null,
                        'candidatesDeduped' => null,
                        'candidateIndex' => null,
                        'relaxAttempts' => null,
                    ],
                ],
                'ordering' => [
                    'quality' => [
                        'minDistribution' => null,
                        'avgDistribution' => null,
                        'minBreak' => null,
                        'maxBreak' => null,
                        'consecutiveMinBreaks' => null,
                        'consecutiveMaxBreaks' => null,
                        'courtSwitches' => null,
                        'courtBalance' => null,
                        'roundsCount' => null,
                    ],
                    'stats' => [
                        'stopReason' => null,
                        'permutationsIterated' => null,
                        'permutationIndex' => null,
                        'nodesExplored' => null,
                        'seedIndex' => null,
                        'seedsTotal' => null,
                        'time' => null,
                        'relaxAttempts' => null,
                    ],
                ],
            ],
        ];

        $path = $dir . DIRECTORY_SEPARATOR . 'players-8-partners-4-repeat-1-courts-1.json';
        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT) . "\n"
        );

        $tester = $this->makeTester();
        $tester->execute(['--templates-version' => (string) $version]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $decoded = json_decode(file_get_contents($path), true);
        $this->assertNull($decoded['matches']);
        $this->assertSame(0.9, $decoded['metrics']['pairing']['quality']['minPartnersFairness']);
        $this->assertSame(16, $decoded['metrics']['pairing']['quality']['pairCount']);
        $this->assertNull($decoded['metrics']['matchMaking']['quality']['minPlayingFairness']);
        $this->assertSame(100, $decoded['metrics']['matchMaking']['stats']['permutationsIterated']);
    }

    public function test_filters_work_on_clean_directory(): void
    {
        $version = $this->allocVersion();
        $dir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        mkdir($dir);

        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json',
            $this->currentEligibleJson(4, 1)
        );
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'players-4-partners-2-repeat-1-courts-1.json',
            $this->currentEligibleJson(4, 2)
        );

        $tester = $this->makeTester();
        $tester->execute([
            '--templates-version' => (string) $version,
            '--partners' => '1',
        ]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Processed 1 file', $tester->getDisplay());
    }

    public function test_two_court_fixture_rounds_count_is_time_slots(): void
    {
        $version = $this->allocVersion();
        $dir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        mkdir($dir);

        $payload = json_decode($this->currentEligibleJson(8, 1), true);
        $payload['players'] = 8;
        $payload['partners'] = 1;
        $payload['courts'] = 2;
        $payload['matches'] = [
            [
                [[0, 1], [2, 3]],
                [[0, 2], [1, 3]],
            ],
            [
                [[4, 5], [6, 7]],
                [[4, 6], [5, 7]],
            ],
        ];
        // Intentionally wrong roundsCount (sum) to prove recalc fixes it
        $payload['metrics']['ordering']['quality']['roundsCount'] = 4;
        $payload['metrics']['matchMaking']['quality']['matchesCount'] = 99;

        $path = $dir . DIRECTORY_SEPARATOR . 'players-8-partners-1-repeat-1-courts-2.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT) . "\n");

        $tester = $this->makeTester();
        $tester->execute(['--templates-version' => (string) $version]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $decoded = json_decode(file_get_contents($path), true);
        $this->assertSame(2, $decoded['metrics']['ordering']['quality']['roundsCount']);
        $this->assertSame(4, $decoded['metrics']['matchMaking']['quality']['matchesCount']);
    }

    private function makeTester(): CommandTester
    {
        $command = new RecalculateQualityMetricsCommand(new TemplateMatchesRepository($this->tempBaseDir));
        $application = new Application();
        $application->setAutoExit(false);
        $application->add($command);
        $command = $application->find('templates:recalculate-quality-metrics');

        return new CommandTester($command);
    }

    private function legacyEligibleJson(): string
    {
        return json_encode([
            0,
            [
                'matches' => [
                    [[0, 3], [1, 2]],
                ],
                'meetingsVariation' => 0,
                'partnersCount' => [1, 1, 1, 1],
                'playersMet' => [
                    '0' => ['3' => 1, '1' => 1, '2' => 1],
                    '3' => ['0' => 1, '1' => 1, '2' => 1],
                    '1' => ['0' => 1, '3' => 1, '2' => 1],
                    '2' => ['0' => 1, '3' => 1, '1' => 1],
                ],
                'hasDifferentPartnersNumber' => false,
                'permutationsIterated' => 1,
                'permutationIndex' => 1,
                'templatesGenerated' => 1,
                'templateIndex' => 1,
                'estimatedGenerationTime' => 1,
                'generationTime' => '10.45',
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function currentEligibleJson(int $players, int $partners): string
    {
        $matches = [
            [
                [[0, 3], [1, 2]],
            ],
        ];

        return json_encode([
            'players' => $players,
            'partners' => $partners,
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
            'matches' => $matches,
            'metrics' => [
                'pairing' => [
                    'quality' => [
                        'minPartnersFairness' => null,
                        'avgPartnersFairness' => null,
                        'partnersCount' => null,
                        'partnersCountVariation' => null,
                        'pairCount' => null,
                    ],
                    'stats' => [
                        'stopReason' => null,
                        'time' => null,
                        'nodesExplored' => null,
                        'seedIndex' => null,
                        'seedsTotal' => null,
                    ],
                ],
                'matchMaking' => [
                    'quality' => [
                        'meetingsVariation' => null,
                        'minOpponentsMet' => null,
                        'maxOpponentsMet' => null,
                        'playersMet' => null,
                        'matchesCount' => null,
                        'minPlayingFairness' => null,
                        'avgPlayingFairness' => null,
                        'maxPlayingFairnessPenalty' => null,
                    ],
                    'stats' => [
                        'permutationsIterated' => null,
                        'permutationIndex' => null,
                        'templatesGenerated' => null,
                        'templateIndex' => null,
                        'nodesExplored' => null,
                        'stopReason' => null,
                        'time' => null,
                        'meetingsVariationLimit' => null,
                        'candidatesCollected' => null,
                        'candidatesDeduped' => null,
                        'candidateIndex' => null,
                        'relaxAttempts' => null,
                    ],
                ],
                'ordering' => [
                    'quality' => [
                        'minDistribution' => null,
                        'avgDistribution' => null,
                        'minBreak' => null,
                        'maxBreak' => null,
                        'consecutiveMinBreaks' => null,
                        'consecutiveMaxBreaks' => null,
                        'courtSwitches' => null,
                        'courtBalance' => null,
                        'roundsCount' => null,
                    ],
                    'stats' => [
                        'stopReason' => null,
                        'permutationsIterated' => null,
                        'permutationIndex' => null,
                        'nodesExplored' => null,
                        'seedIndex' => null,
                        'seedsTotal' => null,
                        'time' => null,
                        'relaxAttempts' => null,
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT) . "\n";
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
