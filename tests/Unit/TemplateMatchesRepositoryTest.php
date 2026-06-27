<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\Exception\TemplateMatchesNotFoundException;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class TemplateMatchesRepositoryTest extends TestCase
{
    use TemplateVersionTestTrait;

    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->resetAllocatedVersions();
        $this->tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-template-matches-test-'
            . bin2hex(random_bytes(4));
        if (!mkdir($this->tempBaseDir, 0775, true) && !is_dir($this->tempBaseDir)) {
            $this->fail("Could not create temp dir: {$this->tempBaseDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempBaseDir);
    }

    public function test_path_includes_combo_and_version(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();

        $path = $repo->path($version, 12, 8, 1, 1, false);
        $this->assertStringContainsString('v' . $version, $path);
        $this->assertStringContainsString('players-12-partners-8-repeat-1-courts-1.json', $path);
        $this->assertStringNotContainsString('fixedteams', $path);

        $fixedPath = $repo->path($version, 12, 8, 1, 1, true);
        $this->assertStringContainsString('players-12-partners-8-repeat-1-courts-1-fixedteams.json', $fixedPath);
    }

    public function test_find_throws_with_expected_path_when_file_is_missing(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version, 0775, true);

        $expectedPath = $repo->path($version, 8, 4, 1, 1, false);

        try {
            $repo->find(8, 4, 1, 1, false);
            $this->fail('Expected TemplateMatchesNotFoundException');
        } catch (TemplateMatchesNotFoundException $e) {
            $this->assertSame($expectedPath, $e->getExpectedPath());
            $this->assertStringContainsString('players=8', $e->getMessage());
            $this->assertStringContainsString('partners=4', $e->getMessage());
            $this->assertStringContainsString($expectedPath, $e->getMessage());
        }
    }

    public function test_find_throws_when_file_contains_invalid_json(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();

        $path = $repo->path($version, 8, 4, 1, 1, false);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, 'not-json{');

        $this->expectException(TemplateMatchesNotFoundException::class);
        $repo->find(8, 4, 1, 1, false);
    }

    public function test_save_then_find_at_round_trips_value_object(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $template = $this->makeTemplate(8, 4, 1, false);

        $repo->save($version, $template);
        $loaded = $repo->findAt($version, 8, 4, 1, 1, false);

        $this->assertSame($template->toArray(), $loaded->toArray());
        $this->assertTrue(is_file($repo->path($version, 8, 4, 1, 1, false)));
    }

    public function test_save_derives_path_from_template_identity(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();

        $mixed = $this->makeTemplate(4, 1, 1, false);
        $repo->save($version, $mixed);
        $this->assertFileExists(
            $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json'
        );

        $fixed = $this->makeTemplate(8, 2, 1, true);
        $repo->save($version, $fixed);
        $this->assertFileExists(
            $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version . DIRECTORY_SEPARATOR . 'players-8-partners-2-repeat-1-courts-1-fixedteams.json'
        );
    }

    public function test_save_creates_intermediate_directories(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $template = $this->makeTemplate(16, 12, 1, true);

        $repo->save($version, $template);

        $expectedPath = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version . DIRECTORY_SEPARATOR . 'players-16-partners-12-repeat-1-courts-1-fixedteams.json';
        $this->assertFileExists($expectedPath);
    }

    public function test_save_overwrites_existing_file(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();

        $first = $this->makeTemplate(4, 1, 1, false);
        $repo->save($version, $first);

        $second = new TemplateMatches(
            4,
            1,
            1,
            1,
            false,
            [[[[3, 0], [1, 2]]]],
            0.9,
            0.92,
            [0 => 1, 1 => 1, 2 => 1, 3 => 1],
            2,
            2,
            'DEADLINE',
            1.25,
            200,
            1,
            1,
            5.0,
            2,
            3,
            [],
            1,
            42,
            7,
            10,
            7,
            'DEADLINE',
            0.5,
            1,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'DEADLINE',
            42,
            7,
            100,
            1,
            1,
            0.75
        );
        $repo->save($version, $second);

        $loaded = $repo->findAt($version, 4, 1, 1, 1, false);
        $this->assertSame(5.0, $loaded->getMatchMakingQualityMeetingsVariation());
        $this->assertSame(2, $loaded->getPairingQualityPartnersCountVariation());
        $this->assertSame('DEADLINE', $loaded->getPairingStatsStopReason());
        $this->assertSame(1.25, $loaded->getPairingStatsTime());
        $this->assertSame('DEADLINE', $loaded->getOrderingStatsStopReason());
        $this->assertSame(42, $loaded->getOrderingStatsPermutationsIterated());
        $this->assertSame(7, $loaded->getOrderingStatsPermutationIndex());
        $this->assertSame(0.75, $loaded->getOrderingStatsTime());
    }

    public function test_find_at_throws_on_identity_mismatch_between_json_and_lookup(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();

        $foreign = $this->makeTemplate(8, 2, 1, false);
        $foreignPath = $repo->path($version, 12, 8, 1, 1, false);
        if (!is_dir(dirname($foreignPath))) {
            mkdir(dirname($foreignPath), 0775, true);
        }
        file_put_contents(
            $foreignPath,
            json_encode($foreign->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/identity mismatch/i');
        $repo->findAt($version, 12, 8, 1, 1, false);
    }

    public function test_find_at_throws_on_missing_identity_in_json(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();

        $legacyShape = [
            'players' => 8,
            'partners' => 4,
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
            'matches' => null,
            'pairing' => ['quality' => [], 'stats' => []],
            'sorting' => ['quality' => [], 'stats' => []],
        ];

        $path = $repo->path($version, 8, 4, 1, 1, false);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($legacyShape));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/legacy top-level key/');
        $repo->findAt($version, 8, 4, 1, 1, false);
    }

    public function test_clear_version_deletes_only_template_files_in_target_version(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $wipeVersion = $this->allocVersion();
        $siblingVersion = $this->allocVersion();

        $repo->save($wipeVersion, $this->makeTemplate(4, 1, 1, false));
        $repo->save($wipeVersion, $this->makeTemplate(8, 2, 1, true));

        $wipeDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $wipeVersion;
        file_put_contents($wipeDir . DIRECTORY_SEPARATOR . 'README.md', '# notes');
        file_put_contents($wipeDir . DIRECTORY_SEPARATOR . '.gitkeep', '');
        file_put_contents($wipeDir . DIRECTORY_SEPARATOR . 'other.json', '{"foo":1}');

        $repo->save($siblingVersion, $this->makeTemplate(4, 1, 1, false));

        $deleted = $repo->clearVersion($wipeVersion);

        $this->assertSame(2, $deleted);
        $this->assertFileDoesNotExist($repo->path($wipeVersion, 4, 1, 1, 1, false));
        $this->assertFileDoesNotExist($repo->path($wipeVersion, 8, 2, 1, 1, true));
        $this->assertFileExists($wipeDir . DIRECTORY_SEPARATOR . 'README.md');
        $this->assertFileExists($wipeDir . DIRECTORY_SEPARATOR . '.gitkeep');
        $this->assertFileExists($wipeDir . DIRECTORY_SEPARATOR . 'other.json');
        $this->assertFileExists($repo->path($siblingVersion, 4, 1, 1, 1, false));
    }

    public function test_clear_version_is_noop_when_directory_does_not_exist(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $missingVersion = $this->allocVersion();

        $deleted = $repo->clearVersion($missingVersion);

        $this->assertSame(0, $deleted);
        $this->assertFalse(is_dir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $missingVersion));
    }

    public function test_default_base_dir_resolves_to_repo_resources_folder(): void
    {
        $repo = $this->productionRepository();
        $expected = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'template-matches';
        $this->assertSame($expected, $repo->getBaseDir());
    }

    public function test_find_uses_latest_version_directory(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $olderVersion = $this->allocVersion();
        $latestVersion = $this->allocVersion();
        $template = $this->makeTemplate(8, 4, 1, false);

        $repo->save($olderVersion, $this->makeTemplate(4, 1, 1, false));
        $repo->save($latestVersion, $template);
        $loaded = $repo->find(8, 4, 1, 1, false);

        $this->assertSame($template->toArray(), $loaded->toArray());
    }

    public function test_ensure_version_directory_creates_missing_directory(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repo->ensureVersionDirectory($version);

        $this->assertDirectoryExists($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version);
    }

    public function test_list_versions_returns_empty_when_base_dir_has_no_subdirectories(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $this->assertSame([], $repo->listVersions());
    }

    public function test_list_versions_skips_top_level_files(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();

        file_put_contents($this->tempBaseDir . DIRECTORY_SEPARATOR . 'README.md', '# notes');
        file_put_contents($this->tempBaseDir . DIRECTORY_SEPARATOR . '.gitkeep', '');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version);

        $versions = $repo->listVersions();

        $this->assertCount(1, $versions);
        $this->assertSame('v' . $version, $versions[0]['directoryName']);
    }

    public function test_list_versions_classifies_bare_v_directories_as_compatible(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $lowerVersion = $this->allocVersion();
        $higherVersion = $this->allocVersion();

        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $lowerVersion);
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $higherVersion);

        $versions = $repo->listVersions();

        $this->assertCount(2, $versions);
        foreach ($versions as $entry) {
            $this->assertTrue($entry['isCompatible'], "Expected compatible: {$entry['directoryName']}");
            $this->assertIsInt($entry['version']);
        }
        $this->assertSame($lowerVersion, $versions[0]['version']);
        $this->assertSame($higherVersion, $versions[1]['version']);
    }

    public function test_latest_version_returns_highest_compatible_numeric_directory(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $lowerVersion = $this->allocVersion();
        $highestVersion = $this->allocVersion();

        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $lowerVersion);
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $highestVersion);
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v1-no-compatibility');

        $this->assertSame($highestVersion, $repo->latestVersion());
    }

    public function test_find_loads_from_highest_version(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $olderVersion = $this->allocVersion();
        $latestVersion = $this->allocVersion();

        $repo->save($olderVersion, $this->makeTemplate(4, 1, 1, false));
        $repo->save($latestVersion, $this->makeTemplate(8, 4, 1, false));

        $loaded = $repo->find(8, 4, 1, 1, false);

        $this->assertSame(8, $loaded->getPlayers());
        $this->assertSame(4, $loaded->getPartners());
    }

    public function test_list_versions_classifies_non_bare_directories_as_incompatible(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v1-no-compatibility');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v2-experimental');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'foo-bar');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'archived');

        $versions = $repo->listVersions();

        $this->assertCount(4, $versions);
        foreach ($versions as $entry) {
            $this->assertFalse($entry['isCompatible'], "Expected incompatible: {$entry['directoryName']}");
            $this->assertNull($entry['version']);
        }
    }

    public function test_list_versions_sorts_by_natural_directory_name_order(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $first = $this->allocVersion();
        $second = $this->allocVersion();
        $third = $this->allocVersion();

        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v1-no-compatibility');
        foreach (array_reverse([$first, $second, $third]) as $version) {
            mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version);
        }

        $versions = $repo->listVersions();
        $compatibleVersions = array_values(array_filter(
            $versions,
            static fn(array $entry): bool => $entry['isCompatible'] && $entry['version'] !== null
        ));

        $this->assertSame(
            [$first, $second, $third],
            array_column($compatibleVersions, 'version')
        );
    }

    public function test_list_versions_mixes_compatible_and_incompatible_rows(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $compatibleVersion = $this->allocVersion();

        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $compatibleVersion);
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v1-no-compatibility');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v2-old');

        $versions = $repo->listVersions();

        $byName = [];
        foreach ($versions as $entry) {
            $byName[$entry['directoryName']] = $entry;
        }
        $compatibleDir = 'v' . $compatibleVersion;
        $this->assertTrue($byName[$compatibleDir]['isCompatible']);
        $this->assertSame($compatibleVersion, $byName[$compatibleDir]['version']);
        $this->assertFalse($byName['v1-no-compatibility']['isCompatible']);
        $this->assertNull($byName['v1-no-compatibility']['version']);
        $this->assertFalse($byName['v2-old']['isCompatible']);
        $this->assertNull($byName['v2-old']['version']);
    }

    public function test_list_versions_returns_empty_when_base_dir_does_not_exist(): void
    {
        $missingBaseDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'does-not-exist';
        $repo = new TemplateMatchesRepository($missingBaseDir);

        $this->assertSame([], $repo->listVersions());
    }

    public function test_has_at_returns_true_when_template_file_exists(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repo->save($version, $this->makeTemplate(8, 4, 1, false));

        $this->assertTrue($repo->hasAt($version, 8, 4, 1, 1, false));
    }

    public function test_has_at_returns_false_when_template_file_is_missing(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();

        $this->assertFalse($repo->hasAt($version, 8, 4, 1, 1, false));
    }

    public function test_has_at_returns_false_when_version_directory_does_not_exist(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $missingVersion = $this->allocVersion();

        $this->assertFalse($repo->hasAt($missingVersion, 8, 4, 1, 1, false));
    }

    public function test_has_at_distinguishes_fixed_teams_variant(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $repo->save($version, $this->makeTemplate(8, 2, 1, true));

        $this->assertTrue($repo->hasAt($version, 8, 2, 1, 1, true));
        $this->assertFalse($repo->hasAt($version, 8, 2, 1, 1, false));
    }

    public function test_list_combo_identities_at_parses_filenames_and_filters_by_courts(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $version = $this->allocVersion();
        $versionDir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version;
        mkdir($versionDir, 0775, true);
        touch($versionDir . DIRECTORY_SEPARATOR . 'players-12-partners-8-repeat-1-courts-1.json');
        touch($versionDir . DIRECTORY_SEPARATOR . 'players-12-partners-8-repeat-1-courts-2.json');
        touch($versionDir . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json');
        touch($versionDir . DIRECTORY_SEPARATOR . 'players-8-partners-2-repeat-1-courts-1-fixedteams.json');

        $courtsTwo = $repo->listComboIdentitiesAt($version, [
            'repeat' => 1,
            'courts' => 2,
            'fixedTeams' => false,
        ]);
        $this->assertSame([
            ['players' => 12, 'partners' => 8, 'repeat' => 1, 'courts' => 2, 'fixedTeams' => false],
        ], $courtsTwo);

        $playersPartners = $repo->listComboIdentitiesAt($version, [
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
            'playersPartners' => [4 => [1], 12 => [8]],
        ]);
        $this->assertSame([
            ['players' => 4, 'partners' => 1, 'repeat' => 1, 'courts' => 1, 'fixedTeams' => false],
            ['players' => 12, 'partners' => 8, 'repeat' => 1, 'courts' => 1, 'fixedTeams' => false],
        ], $playersPartners);

        $fixedOnly = $repo->listComboIdentitiesAt($version, [
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => true,
        ]);
        $this->assertSame([
            ['players' => 8, 'partners' => 2, 'repeat' => 1, 'courts' => 1, 'fixedTeams' => true],
        ], $fixedOnly);
    }

    public function test_list_combo_identities_at_returns_empty_when_version_missing(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $missingVersion = $this->allocVersion();

        $this->assertSame([], $repo->listComboIdentitiesAt($missingVersion, [
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
        ]));
    }

    private function makeTemplate(int $players, int $partners, int $repeat, bool $fixedTeams): TemplateMatches
    {
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
            0,
            (int) ($players * $partners / 2),
            'FACTORIAL_COMPLETE',
            0.05,
            100,
            1,
            1,
            0.0,
            $partners,
            $partners,
            [],
            1,
            120,
            45,
            120,
            45,
            'FACTORIAL_COMPLETE',
            0.10,
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
            120,
            45,
            50,
            1,
            1,
            0.10
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
