<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\Exception\TemplateMatchesNotFoundException;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class TemplateMatchesRepositoryTest extends TestCase
{
    private string $tempBaseDir;

    protected function setUp(): void
    {
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

        $path = $repo->path(7, 12, 8, 1, 1, false);
        $this->assertStringContainsString('v7', $path);
        $this->assertStringContainsString('players-12-partners-8-repeat-1-courts-1.json', $path);
        $this->assertStringNotContainsString('fixedteams', $path);

        $fixedPath = $repo->path(7, 12, 8, 1, 1, true);
        $this->assertStringContainsString('players-12-partners-8-repeat-1-courts-1-fixedteams.json', $fixedPath);
    }

    public function test_find_throws_with_expected_path_when_file_is_missing(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $expectedPath = $repo->path(TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, 8, 4, 1, 1, false);

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

        $path = $repo->path(TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, 8, 4, 1, 1, false);
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
        $template = $this->makeTemplate(8, 4, 1, false);

        $repo->save(7, $template);
        $loaded = $repo->findAt(7, 8, 4, 1, 1, false);

        $this->assertSame($template->toArray(), $loaded->toArray());
        $this->assertTrue(is_file($repo->path(7, 8, 4, 1, 1, false)));
    }

    public function test_save_derives_path_from_template_identity(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $mixed = $this->makeTemplate(4, 1, 1, false);
        $repo->save(3, $mixed);
        $this->assertFileExists($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v3' . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json');

        $fixed = $this->makeTemplate(8, 2, 1, true);
        $repo->save(3, $fixed);
        $this->assertFileExists($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v3' . DIRECTORY_SEPARATOR . 'players-8-partners-2-repeat-1-courts-1-fixedteams.json');
    }

    public function test_save_creates_intermediate_directories(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $template = $this->makeTemplate(16, 12, 1, true);

        $repo->save(11, $template);

        $expectedPath = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v11' . DIRECTORY_SEPARATOR . 'players-16-partners-12-repeat-1-courts-1-fixedteams.json';
        $this->assertFileExists($expectedPath);
    }

    public function test_save_overwrites_existing_file(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $first = $this->makeTemplate(4, 1, 1, false);
        $repo->save(2, $first);

        $second = new TemplateMatches(
            4,
            1,
            1,
            1,
            false,
            [[[[3, 0], [1, 2]]]],
            5.0,
            10,
            7,
            10,
            7,
            [0 => 1, 1 => 1, 2 => 1, 3 => 1],
            [],
            2,
            1,
            'DEADLINE',
            1.25,
            'DEADLINE',
            null,
            null,
            42,
            7,
            null,
            null,
            0.75
        );
        $repo->save(2, $second);

        $loaded = $repo->findAt(2, 4, 1, 1, 1, false);
        $this->assertSame(5.0, $loaded->getPairingMeetingsVariation());
        $this->assertSame(2, $loaded->getPairingPartnersCountVariation());
        $this->assertSame('DEADLINE', $loaded->getPairingStopReason());
        $this->assertSame(1.25, $loaded->getPairingTime());
        $this->assertSame('DEADLINE', $loaded->getSortingStopReason());
        $this->assertSame(42, $loaded->getSortingPermutationsIterated());
        $this->assertSame(7, $loaded->getSortingPermutationIndex());
        $this->assertSame(0.75, $loaded->getSortingTime());
    }

    public function test_find_at_throws_on_identity_mismatch_between_json_and_lookup(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        // Persist a template whose JSON identity says (8, 2, 1, false), then re-write its bytes
        // into the file the repository would generate for the (12, 8, 1, false) lookup.
        $foreign = $this->makeTemplate(8, 2, 1, false);
        $foreignPath = $repo->path(5, 12, 8, 1, 1, false);
        if (!is_dir(dirname($foreignPath))) {
            mkdir(dirname($foreignPath), 0775, true);
        }
        file_put_contents(
            $foreignPath,
            json_encode($foreign->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/identity mismatch/i');
        $repo->findAt(5, 12, 8, 1, 1, false);
    }

    public function test_find_at_throws_on_missing_identity_in_json(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $legacyShape = [
            // No `players`/`partners`/`repeat`/`fixedTeams` keys at all - legacy v1 shape.
            'matches' => null,
            'meetingsVariation' => 0.0,
            'pairing' => [],
            'sorting' => [],
        ];

        $path = $repo->path(4, 8, 4, 1, 1, false);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($legacyShape));

        $this->expectException(\InvalidArgumentException::class);
        $repo->findAt(4, 8, 4, 1, 1, false);
    }

    public function test_clear_version_deletes_only_template_files_in_target_version(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        // Two templates under v4: bulk wipe should remove both of them.
        $repo->save(4, $this->makeTemplate(4, 1, 1, false));
        $repo->save(4, $this->makeTemplate(8, 2, 1, true));

        // Sibling non-template files under v4 must survive (README, .gitkeep, foreign artefact).
        $v4Dir = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v4';
        file_put_contents($v4Dir . DIRECTORY_SEPARATOR . 'README.md', '# notes');
        file_put_contents($v4Dir . DIRECTORY_SEPARATOR . '.gitkeep', '');
        file_put_contents($v4Dir . DIRECTORY_SEPARATOR . 'other.json', '{"foo":1}');

        // A template under v5 must not be touched.
        $repo->save(5, $this->makeTemplate(4, 1, 1, false));

        $deleted = $repo->clearVersion(4);

        $this->assertSame(2, $deleted);
        $this->assertFileDoesNotExist($repo->path(4, 4, 1, 1, 1, false));
        $this->assertFileDoesNotExist($repo->path(4, 8, 2, 1, 1, true));
        $this->assertFileExists($v4Dir . DIRECTORY_SEPARATOR . 'README.md');
        $this->assertFileExists($v4Dir . DIRECTORY_SEPARATOR . '.gitkeep');
        $this->assertFileExists($v4Dir . DIRECTORY_SEPARATOR . 'other.json');
        $this->assertFileExists($repo->path(5, 4, 1, 1, 1, false));
    }

    public function test_clear_version_is_noop_when_directory_does_not_exist(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $deleted = $repo->clearVersion(99);

        $this->assertSame(0, $deleted);
        $this->assertFalse(is_dir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v99'));
    }

    public function test_default_base_dir_resolves_to_repo_resources_folder(): void
    {
        $repo = new TemplateMatchesRepository();
        $expected = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'template-matches';
        $this->assertSame($expected, $repo->getBaseDir());
    }

    public function test_find_uses_in_use_version_constant(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $template = $this->makeTemplate(8, 4, 1, false);

        $repo->save(TemplateMatchesRepository::DEFAULT_TEMPLATE_VERSION, $template);
        $loaded = $repo->find(8, 4, 1, 1, false);

        $this->assertSame($template->toArray(), $loaded->toArray());
    }

    public function test_list_versions_returns_empty_when_base_dir_has_no_subdirectories(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $this->assertSame([], $repo->listVersions());
    }

    public function test_list_versions_skips_top_level_files(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        file_put_contents($this->tempBaseDir . DIRECTORY_SEPARATOR . 'README.md', '# notes');
        file_put_contents($this->tempBaseDir . DIRECTORY_SEPARATOR . '.gitkeep', '');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v3');

        $versions = $repo->listVersions();

        $this->assertCount(1, $versions);
        $this->assertSame('v3', $versions[0]['directoryName']);
    }

    public function test_list_versions_classifies_bare_v_directories_as_compatible(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v3');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v4');

        $versions = $repo->listVersions();

        $this->assertCount(2, $versions);
        foreach ($versions as $entry) {
            $this->assertTrue($entry['isCompatible'], "Expected compatible: {$entry['directoryName']}");
            $this->assertIsInt($entry['version']);
        }
        $this->assertSame(3, $versions[0]['version']);
        $this->assertSame(4, $versions[1]['version']);
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

        // Create out of order; v10 must sort AFTER v2/v3 (natural sort, not lex sort).
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v10');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v2');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v3');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v1-no-compatibility');

        $versions = $repo->listVersions();
        $names = array_column($versions, 'directoryName');

        $this->assertSame(['v1-no-compatibility', 'v2', 'v3', 'v10'], $names);
    }

    public function test_list_versions_mixes_compatible_and_incompatible_rows(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v3');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v1-no-compatibility');
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v2-old');

        $versions = $repo->listVersions();

        $byName = [];
        foreach ($versions as $entry) {
            $byName[$entry['directoryName']] = $entry;
        }
        $this->assertTrue($byName['v3']['isCompatible']);
        $this->assertSame(3, $byName['v3']['version']);
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
        $repo->save(3, $this->makeTemplate(8, 4, 1, false));

        $this->assertTrue($repo->hasAt(3, 8, 4, 1, 1, false));
    }

    public function test_has_at_returns_false_when_template_file_is_missing(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $this->assertFalse($repo->hasAt(3, 8, 4, 1, 1, false));
    }

    public function test_has_at_returns_false_when_version_directory_does_not_exist(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);

        $this->assertFalse($repo->hasAt(99, 8, 4, 1, 1, false));
    }

    public function test_has_at_distinguishes_fixed_teams_variant(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $repo->save(3, $this->makeTemplate(8, 2, 1, true));

        $this->assertTrue($repo->hasAt(3, 8, 2, 1, 1, true));
        $this->assertFalse($repo->hasAt(3, 8, 2, 1, 1, false));
    }

    public function test_list_combo_identities_at_parses_filenames_and_filters_by_courts(): void
    {
        $repo = new TemplateMatchesRepository($this->tempBaseDir);
        $v5 = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'v5';
        mkdir($v5, 0775, true);
        touch($v5 . DIRECTORY_SEPARATOR . 'players-12-partners-8-repeat-1-courts-1.json');
        touch($v5 . DIRECTORY_SEPARATOR . 'players-12-partners-8-repeat-1-courts-2.json');
        touch($v5 . DIRECTORY_SEPARATOR . 'players-4-partners-1-repeat-1-courts-1.json');
        touch($v5 . DIRECTORY_SEPARATOR . 'players-8-partners-2-repeat-1-courts-1-fixedteams.json');

        $courtsTwo = $repo->listComboIdentitiesAt(5, [
            'repeat' => 1,
            'courts' => 2,
            'fixedTeams' => false,
        ]);
        $this->assertSame([
            ['players' => 12, 'partners' => 8, 'repeat' => 1, 'courts' => 2, 'fixedTeams' => false],
        ], $courtsTwo);

        $playersPartners = $repo->listComboIdentitiesAt(5, [
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
            'playersPartners' => [4 => [1], 12 => [8]],
        ]);
        $this->assertSame([
            ['players' => 4, 'partners' => 1, 'repeat' => 1, 'courts' => 1, 'fixedTeams' => false],
            ['players' => 12, 'partners' => 8, 'repeat' => 1, 'courts' => 1, 'fixedTeams' => false],
        ], $playersPartners);

        $fixedOnly = $repo->listComboIdentitiesAt(5, [
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

        $this->assertSame([], $repo->listComboIdentitiesAt(99, [
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
        ]));
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
            0.05,
            'FACTORIAL_COMPLETE',
            0.95,
            0.97,
            120,
            45,
            0,
            0,
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
