<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Builds the templates demo catalog rows (Eligible/Usable + matches-generated query payloads).
 */
final class TemplateDemoCatalog
{
    private TemplateMatchesRepository $repository;

    public function __construct(?TemplateMatchesRepository $repository = null)
    {
        $this->repository = $repository ?? new TemplateMatchesRepository();
    }

    /**
     * @param array<string, mixed> $query typically $_GET
     * @return array{
     *     players?: int,
     *     partners?: int,
     *     repeat?: int,
     *     courts?: int,
     *     fixedTeams?: bool
     * }
     */
    public function parseFiltersFromQuery(array $query): array
    {
        $filters = [];

        if (isset($query['players']) && $query['players'] !== '' && !is_array($query['players'])) {
            $filters['players'] = (int) $query['players'];
        }
        if (isset($query['partners']) && $query['partners'] !== '' && !is_array($query['partners'])) {
            $filters['partners'] = (int) $query['partners'];
        }
        if (isset($query['repeat']) && $query['repeat'] !== '' && !is_array($query['repeat'])) {
            $filters['repeat'] = (int) $query['repeat'];
        }
        if (isset($query['fixed-teams']) && $query['fixed-teams'] !== '' && !is_array($query['fixed-teams'])) {
            $filters['fixedTeams'] = $this->parseBool((string) $query['fixed-teams']);
        }
        if (isset($query['courts']) && $query['courts'] !== '' && !is_array($query['courts'])) {
            $filters['courts'] = max(1, (int) $query['courts']);
        }

        return $filters;
    }

    /**
     * @param array{
     *     players?: int,
     *     partners?: int,
     *     repeat?: int,
     *     courts?: int,
     *     fixedTeams?: bool
     * } $filters
     * @param list<int> $playerIdPool already limited to players usable for demo Open links
     * @return array{
     *     sourceDirectory: string,
     *     isClean: bool,
     *     playerPoolSize: int,
     *     rows: list<array{
     *         players: int,
     *         partners: int,
     *         repeat: int,
     *         courts: int,
     *         fixedTeams: bool,
     *         eligible: string,
     *         usable: string,
     *         demoReason: ?string,
     *         openQuery: ?array<string, mixed>
     *     }>
     * }
     */
    public function buildCatalog(int $version, array $filters, array $playerIdPool): array
    {
        $playerIdPool = array_values(array_map('intval', $playerIdPool));

        $source = $this->repository->resolveVersionSourceDirectory($version);

        if (!$source['isClean'] && $filters !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Filters are not allowed when the source directory has a suffix (%s). Run without filters.',
                $source['directoryName']
            ));
        }

        $files = $this->repository->listTemplateFilesInDirectory(
            $source['directoryName'],
            $source['isClean'] ? $filters : []
        );

        $rows = [];
        foreach ($files as $file) {
            $identity = [
                'players' => $file['players'],
                'partners' => $file['partners'],
                'repeat' => $file['repeat'],
                'courts' => $file['courts'],
                'fixedTeams' => $file['fixedTeams'],
            ];

            $status = $this->loadStatus($file['absolutePath']);
            $demo = $this->resolveDemo(
                $version,
                $identity,
                $status['unreadable'],
                $status['unreadableReason'],
                $playerIdPool
            );

            $rows[] = [
                'players' => $identity['players'],
                'partners' => $identity['partners'],
                'repeat' => $identity['repeat'],
                'courts' => $identity['courts'],
                'fixedTeams' => $identity['fixedTeams'],
                'eligible' => $status['eligible'],
                'usable' => $status['usable'],
                'demoReason' => $demo['reason'],
                'openQuery' => $demo['query'],
            ];
        }

        return [
            'sourceDirectory' => $source['directoryName'],
            'isClean' => $source['isClean'],
            'playerPoolSize' => count($playerIdPool),
            'rows' => $rows,
        ];
    }

    /**
     * @param array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool} $identity
     * @param list<int> $playerIds
     * @return array<string, mixed>
     */
    public function buildMatchesGeneratedQuery(int $templateVersion, array $identity, array $playerIds): array
    {
        $query = [
            'edition' => 'demo',
            'organizer-id' => 1,
            'partner-id' => 1,
            'title' => sprintf('Demo %dp/%do', $identity['players'], $identity['partners']),
            'color' => '#e74c3c',
            'time-start' => '12:30',
            'time-end' => '16:30',
            'opponents-per-player' => $identity['partners'],
            'repeat-partners' => $identity['repeat'],
            'include-scores' => 1,
            'include-final' => 0,
            'allow-replacements' => 0,
            'template-version' => $templateVersion,
            'player-ids' => $playerIds,
            'players-collecting-points' => $playerIds,
            'court-names' => $this->courtNames($identity['courts']),
        ];

        if ($identity['fixedTeams']) {
            $query['fixed-teams'] = 1;
        }

        return $query;
    }

    /**
     * @param list<int> $pool
     * @return list<int>|null
     */
    public function samplePlayerIds(array $pool, int $n): ?array
    {
        if (count($pool) < $n) {
            return null;
        }

        $copy = array_values($pool);
        shuffle($copy);

        return array_slice($copy, 0, $n);
    }

    /**
     * @return array{eligible: string, usable: string, unreadable: bool, unreadableReason: ?string}
     */
    public function loadStatus(string $absolutePath): array
    {
        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            return [
                'eligible' => 'error',
                'usable' => 'error',
                'unreadable' => true,
                'unreadableReason' => 'unreadable file',
            ];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                'eligible' => 'error',
                'usable' => 'error',
                'unreadable' => true,
                'unreadableReason' => 'unreadable schema',
            ];
        }

        if (!is_array($decoded)) {
            return [
                'eligible' => 'error',
                'usable' => 'error',
                'unreadable' => true,
                'unreadableReason' => 'unreadable schema',
            ];
        }

        try {
            $template = TemplateMatches::fromArray($decoded);
        } catch (\Throwable $e) {
            return [
                'eligible' => 'error',
                'usable' => 'error',
                'unreadable' => true,
                'unreadableReason' => 'unreadable schema',
            ];
        }

        return [
            'eligible' => $template->isEligible() ? 'yes' : 'no',
            'usable' => $template->isUsable() ? 'yes' : 'no',
            'unreadable' => false,
            'unreadableReason' => null,
        ];
    }

    /**
     * @param array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool} $identity
     * @param list<int> $playerIdPool
     * @return array{reason: ?string, query: ?array<string, mixed>}
     */
    private function resolveDemo(
        int $version,
        array $identity,
        bool $unreadable,
        ?string $unreadableReason,
        array $playerIdPool
    ): array {
        if ($unreadable) {
            return [
                'reason' => $unreadableReason ?? 'unreadable schema',
                'query' => null,
            ];
        }

        $playerIds = $this->samplePlayerIds($playerIdPool, $identity['players']);
        if ($playerIds === null) {
            return [
                'reason' => sprintf(
                    'need %d players with static photos, have %d',
                    $identity['players'],
                    count($playerIdPool)
                ),
                'query' => null,
            ];
        }

        return [
            'reason' => null,
            'query' => $this->buildMatchesGeneratedQuery($version, $identity, $playerIds),
        ];
    }

    /**
     * @return list<string>
     */
    private function courtNames(int $courts): array
    {
        $names = [];
        for ($i = 1; $i <= max(1, $courts); $i++) {
            $names[] = 'Court ' . $i;
        }

        return $names;
    }

    private function parseBool(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new \InvalidArgumentException(sprintf('Invalid boolean value: "%s". Use 0/1 or true/false.', $value));
    }
}
