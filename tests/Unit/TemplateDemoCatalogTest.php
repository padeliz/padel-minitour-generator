<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\TemplateDemoCatalog;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class TemplateDemoCatalogTest extends TestCase
{
    use TemplateVersionTestTrait;

    private string $tempBaseDir;

    protected function setUp(): void
    {
        $this->resetAllocatedVersions();
        $this->tempBaseDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'padel-templates-demo-catalog-test-'
            . bin2hex(random_bytes(4));

        if (!mkdir($this->tempBaseDir, 0775, true) && !is_dir($this->tempBaseDir)) {
            $this->fail("Could not create temp dir: {$this->tempBaseDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tempBaseDir);
    }

    public function test_parse_filters_from_query(): void
    {
        $catalog = new TemplateDemoCatalog(new TemplateMatchesRepository($this->tempBaseDir));

        $filters = $catalog->parseFiltersFromQuery([
            'players' => '8',
            'partners' => '4',
            'repeat' => '1',
            'courts' => '2',
            'fixed-teams' => '1',
            'ignored' => 'x',
        ]);

        $this->assertSame([
            'players' => 8,
            'partners' => 4,
            'repeat' => 1,
            'fixedTeams' => true,
            'courts' => 2,
        ], $filters);
    }

    public function test_parse_filters_ignores_empty_strings_and_arrays(): void
    {
        $catalog = $this->makeCatalog();

        $this->assertSame([], $catalog->parseFiltersFromQuery([
            'players' => '',
            'partners' => ['4'],
            'repeat' => '',
            'courts' => '',
            'fixed-teams' => '',
        ]));
    }

    public function test_parse_filters_courts_zero_becomes_one(): void
    {
        $filters = $this->makeCatalog()->parseFiltersFromQuery(['courts' => '0']);

        $this->assertSame(['courts' => 1], $filters);
    }

    public function test_parse_filters_invalid_fixed_teams_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid boolean value');

        $this->makeCatalog()->parseFiltersFromQuery(['fixed-teams' => 'maybe']);
    }

    public function test_unresolvable_version_throws(): void
    {
        $catalog = $this->makeCatalog();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No template directory matching v9');

        $catalog->buildCatalog(9, [], [1, 2, 3, 4]);
    }

    public function test_empty_directory_returns_empty_rows(): void
    {
        $version = $this->allocVersion();
        $this->mkdirVersion($version);

        $result = $this->makeCatalog()->buildCatalog($version, [], [1, 2, 3, 4]);

        $this->assertSame('v' . $version, $result['sourceDirectory']);
        $this->assertTrue($result['isClean']);
        $this->assertSame(4, $result['playerPoolSize']);
        $this->assertSame([], $result['rows']);
    }

    public function test_build_catalog_eligible_usable_and_open_query(): void
    {
        $version = $this->allocVersion();
        $this->mkdirVersion($version);
        $this->putUsableTemplate($version, 4, 1, 1, 1);
        $this->putNullMatchesTemplate($version, 4, 2, 1, 1);

        $result = $this->makeCatalog()->buildCatalog($version, [], [10, 20, 30, 40]);

        $this->assertSame(4, $result['playerPoolSize']);
        $this->assertCount(2, $result['rows']);

        $usable = $this->rowByPartners($result['rows'], 1);
        $this->assertSame('yes', $usable['eligible']);
        $this->assertSame('yes', $usable['usable']);
        $this->assertNull($usable['demoReason']);
        $this->assertNotNull($usable['openQuery']);
        $this->assertSame($version, $usable['openQuery']['template-version']);
        $this->assertSame(1, $usable['openQuery']['include-scores']);
        $this->assertCount(4, $usable['openQuery']['player-ids']);
        $this->assertEqualsCanonicalizing([10, 20, 30, 40], $usable['openQuery']['player-ids']);
        $this->assertSame($usable['openQuery']['player-ids'], $usable['openQuery']['players-collecting-points']);

        $unusable = $this->rowByPartners($result['rows'], 2);
        $this->assertSame('no', $unusable['eligible']);
        $this->assertSame('no', $unusable['usable']);
        $this->assertNotNull($unusable['openQuery']);
    }

    public function test_unreadable_schema_shows_error_without_open_query(): void
    {
        $version = $this->allocVersion();
        $this->mkdirVersion($version);
        $this->putUsableTemplate($version, 4, 1, 1, 1);
        $this->putRawTemplate($version, 4, 3, 1, 1, '{"sentinel": true}');

        $result = $this->makeCatalog()->buildCatalog($version, [], [10, 20, 30, 40]);

        $bad = $this->rowByPartners($result['rows'], 3);
        $this->assertSame('error', $bad['eligible']);
        $this->assertSame('error', $bad['usable']);
        $this->assertSame('unreadable schema', $bad['demoReason']);
        $this->assertNull($bad['openQuery']);

        $good = $this->rowByPartners($result['rows'], 1);
        $this->assertNotNull($good['openQuery']);
    }

    public function test_load_status_missing_file_is_unreadable_file(): void
    {
        $status = $this->makeCatalog()->loadStatus(
            $this->tempBaseDir . DIRECTORY_SEPARATOR . 'does-not-exist.json'
        );

        $this->assertSame('error', $status['eligible']);
        $this->assertSame('error', $status['usable']);
        $this->assertTrue($status['unreadable']);
        $this->assertSame('unreadable file', $status['unreadableReason']);
    }

    public function test_load_status_invalid_json_is_unreadable_schema(): void
    {
        $path = $this->tempBaseDir . DIRECTORY_SEPARATOR . 'broken.json';
        file_put_contents($path, '{not-json');

        $status = $this->makeCatalog()->loadStatus($path);

        $this->assertSame('error', $status['eligible']);
        $this->assertSame('error', $status['usable']);
        $this->assertTrue($status['unreadable']);
        $this->assertSame('unreadable schema', $status['unreadableReason']);
    }

    public function test_insufficient_players_puts_demo_reason(): void
    {
        $version = $this->allocVersion();
        $this->mkdirVersion($version);
        $this->putUsableTemplate($version, 4, 1, 1, 1);

        $result = $this->makeCatalog()->buildCatalog($version, [], [10, 20]);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(2, $result['playerPoolSize']);
        $this->assertSame('need 4 players with static photos, have 2', $result['rows'][0]['demoReason']);
        $this->assertNull($result['rows'][0]['openQuery']);
    }

    public function test_players_filter_limits_rows(): void
    {
        $version = $this->allocVersion();
        $this->mkdirVersion($version);
        $this->putUsableTemplate($version, 4, 1, 1, 1);
        $this->putUsableTemplate($version, 8, 4, 1, 1);

        $result = $this->makeCatalog()->buildCatalog($version, ['players' => 4], range(1, 20));

        $this->assertCount(1, $result['rows']);
        $this->assertSame(4, $result['rows'][0]['players']);
        $this->assertSame(1, $result['rows'][0]['partners']);
    }

    public function test_partners_repeat_courts_and_fixed_teams_filters(): void
    {
        $version = $this->allocVersion();
        $this->mkdirVersion($version);
        $this->putUsableTemplate($version, 4, 1, 1, 1, false);
        $this->putUsableTemplate($version, 4, 1, 2, 1, false);
        $this->putUsableTemplate($version, 4, 1, 1, 2, false);
        $this->putUsableTemplate($version, 4, 1, 1, 1, true);

        $result = $this->makeCatalog()->buildCatalog($version, [
            'partners' => 1,
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => true,
        ], range(1, 20));

        $this->assertCount(1, $result['rows']);
        $this->assertTrue($result['rows'][0]['fixedTeams']);
        $this->assertSame(1, $result['rows'][0]['courts']);
        $this->assertSame(1, $result['rows'][0]['repeat']);
    }

    public function test_filters_forbidden_on_suffix_directory(): void
    {
        $version = $this->allocVersion();
        $suffix = 'v' . $version . '-no-compatibility';
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . $suffix, 0775, true);
        $this->putUsableTemplateInDir($suffix, 4, 1, 1, 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Filters are not allowed');

        $this->makeCatalog()->buildCatalog($version, ['players' => 4], [1, 2, 3, 4]);
    }

    public function test_suffix_directory_without_filters_lists_rows(): void
    {
        $version = $this->allocVersion();
        $suffix = 'v' . $version . '-no-compatibility';
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . $suffix, 0775, true);
        $this->putUsableTemplateInDir($suffix, 4, 1, 1, 1);

        $result = $this->makeCatalog()->buildCatalog($version, [], [10, 20, 30, 40]);

        $this->assertSame($suffix, $result['sourceDirectory']);
        $this->assertFalse($result['isClean']);
        $this->assertCount(1, $result['rows']);
        $this->assertNotNull($result['rows'][0]['openQuery']);
        $this->assertSame($version, $result['rows'][0]['openQuery']['template-version']);
    }

    public function test_build_matches_generated_query_shape(): void
    {
        $query = $this->makeCatalog()->buildMatchesGeneratedQuery(4, [
            'players' => 4,
            'partners' => 1,
            'repeat' => 1,
            'courts' => 2,
            'fixedTeams' => true,
        ], [11, 22, 33, 44]);

        $this->assertSame('demo', $query['edition']);
        $this->assertSame(4, $query['template-version']);
        $this->assertSame(1, $query['opponents-per-player']);
        $this->assertSame(1, $query['include-scores']);
        $this->assertSame(1, $query['fixed-teams']);
        $this->assertSame([11, 22, 33, 44], $query['player-ids']);
        $this->assertSame([11, 22, 33, 44], $query['players-collecting-points']);
        $this->assertSame(['Court 1', 'Court 2'], $query['court-names']);
        $this->assertSame(0, $query['include-final']);
        $this->assertSame(0, $query['allow-replacements']);
    }

    public function test_build_matches_generated_query_omits_fixed_teams_when_false(): void
    {
        $query = $this->makeCatalog()->buildMatchesGeneratedQuery(4, [
            'players' => 4,
            'partners' => 1,
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
        ], [11, 22, 33, 44]);

        $this->assertArrayNotHasKey('fixed-teams', $query);
        $this->assertSame(1, $query['include-scores']);
        $this->assertSame(['Court 1'], $query['court-names']);
    }

    public function test_sample_player_ids_returns_null_when_insufficient(): void
    {
        $catalog = $this->makeCatalog();

        $this->assertNull($catalog->samplePlayerIds([1, 2], 4));
        $ids = $catalog->samplePlayerIds([1, 2, 3, 4, 5], 4);
        $this->assertNotNull($ids);
        $this->assertCount(4, $ids);
        $this->assertSame([], array_diff($ids, [1, 2, 3, 4, 5]));
    }

    private function makeCatalog(): TemplateDemoCatalog
    {
        return new TemplateDemoCatalog(new TemplateMatchesRepository($this->tempBaseDir));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowByPartners(array $rows, int $partners): array
    {
        foreach ($rows as $row) {
            if ((int) $row['partners'] === $partners) {
                return $row;
            }
        }

        $this->fail('Row with partners=' . $partners . ' not found');
    }

    private function mkdirVersion(int $version): void
    {
        mkdir($this->tempBaseDir . DIRECTORY_SEPARATOR . 'v' . $version, 0775, true);
    }

    private function putUsableTemplate(
        int $version,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams = false
    ): void {
        $this->putUsableTemplateInDir('v' . $version, $players, $partners, $repeat, $courts, $fixedTeams);
    }

    private function putUsableTemplateInDir(
        string $directoryName,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams = false
    ): void {
        $data = $this->usableTemplateArray($players, $partners, $repeat, $courts, $fixedTeams);
        $this->putRawTemplateInDir(
            $directoryName,
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams,
            json_encode($data, JSON_THROW_ON_ERROR)
        );
    }

    private function putNullMatchesTemplate(
        int $version,
        int $players,
        int $partners,
        int $repeat,
        int $courts
    ): void {
        $data = [
            'players' => $players,
            'partners' => $partners,
            'repeat' => $repeat,
            'courts' => $courts,
            'fixedTeams' => false,
            'matches' => null,
            'metrics' => [
                'pairing' => [
                    'quality' => ['minPartnersFairness' => null, 'avgPartnersFairness' => null],
                    'stats' => [],
                ],
                'matchMaking' => ['quality' => [], 'stats' => []],
                'ordering' => ['quality' => [], 'stats' => []],
            ],
        ];
        $this->putRawTemplate($version, $players, $partners, $repeat, $courts, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function putRawTemplate(
        int $version,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        string $contents,
        bool $fixedTeams = false
    ): void {
        $this->putRawTemplateInDir('v' . $version, $players, $partners, $repeat, $courts, $fixedTeams, $contents);
    }

    private function putRawTemplateInDir(
        string $directoryName,
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams,
        string $contents
    ): void {
        $dir = $this->tempBaseDir . DIRECTORY_SEPARATOR . $directoryName;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filename = sprintf(
            'players-%d-partners-%d-repeat-%d-courts-%d%s.json',
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams ? '-fixedteams' : ''
        );
        file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, $contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function usableTemplateArray(
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams
    ): array {
        $template = new TemplateMatches(
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams,
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
            [
                0 => [1 => 1, 2 => 1, 3 => 1],
                1 => [0 => 1, 2 => 1, 3 => 1],
                2 => [0 => 1, 1 => 1, 3 => 1],
                3 => [0 => 1, 1 => 1, 2 => 1],
            ],
            1,
            1.0 / 3.0,
            2.0 / 3.0,
            2.0 / 3.0,
            2,
            1,
            2,
            1,
            null,
            'FACTORIAL_COMPLETE',
            0.04,
            1,
            null,
            0.95,
            0.97,
            0,
            0,
            null,
            null,
            0,
            null,
            1,
            'FACTORIAL_COMPLETE',
            5,
            3,
            50,
            1,
            1,
            0.08
        );

        return $template->toArray();
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
