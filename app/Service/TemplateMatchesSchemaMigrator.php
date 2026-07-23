<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Migrates on-disk template JSON (legacy or current) into the current {@see TemplateMatches} shape.
 */
final class TemplateMatchesSchemaMigrator
{
    /**
     * @param array<string, mixed>|list<mixed> $decoded
     * @param array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool} $identity
     */
    public function migrate(array $decoded, array $identity): TemplateMatches
    {
        $payload = $this->unwrapPayload($decoded);
        $matches = $this->normalizeMatches($payload['matches'] ?? null, $identity['courts']);

        if ($this->isCurrentSchema($payload)) {
            $payload = $this->upgradeCurrentSchemaMetrics($payload);
            $template = TemplateMatches::fromArray(array_merge($payload, [
                'players' => $identity['players'],
                'partners' => $identity['partners'],
                'repeat' => $identity['repeat'],
                'courts' => $identity['courts'],
                'fixedTeams' => $identity['fixedTeams'],
                'matches' => $matches,
            ]));

            return $template;
        }

        return $this->migrateLegacyPayload($payload, $identity, $matches);
    }

    /**
     * Pads older current-schema catalogs (e.g. v3) so they satisfy today's TemplateMatches contract.
     * Missing quality/stats keys become null; missing relaxAttempts fields become null.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function upgradeCurrentSchemaMetrics(array $payload): array
    {
        if (!isset($payload['metrics']) || !is_array($payload['metrics'])) {
            return $payload;
        }

        $metrics = $payload['metrics'];
        foreach (['pairing', 'matchMaking', 'ordering'] as $phase) {
            if (!isset($metrics[$phase]) || !is_array($metrics[$phase])) {
                $metrics[$phase] = ['quality' => [], 'stats' => []];
            }
            if (!isset($metrics[$phase]['quality']) || !is_array($metrics[$phase]['quality'])) {
                $metrics[$phase]['quality'] = [];
            }
            if (!isset($metrics[$phase]['stats']) || !is_array($metrics[$phase]['stats'])) {
                $metrics[$phase]['stats'] = [];
            }
        }

        foreach ([
            'minPartnersFairness',
            'avgPartnersFairness',
            'partnersCount',
            'partnersCountVariation',
            'pairCount',
        ] as $key) {
            if (!array_key_exists($key, $metrics['pairing']['quality'])) {
                $metrics['pairing']['quality'][$key] = null;
            }
        }

        foreach ([
            'meetingsVariation',
            'minOpponentsMet',
            'maxOpponentsMet',
            'playersMet',
            'matchesCount',
            'minPlayingFairness',
            'avgPlayingFairness',
            'maxPlayingFairnessPenalty',
        ] as $key) {
            if (!array_key_exists($key, $metrics['matchMaking']['quality'])) {
                $metrics['matchMaking']['quality'][$key] = null;
            }
        }

        foreach ([
            'minDistribution',
            'avgDistribution',
            'minBreak',
            'maxBreak',
            'consecutiveMinBreaks',
            'consecutiveMaxBreaks',
            'courtSwitches',
            'courtBalance',
            'roundsCount',
        ] as $key) {
            if (!array_key_exists($key, $metrics['ordering']['quality'])) {
                $metrics['ordering']['quality'][$key] = null;
            }
        }

        foreach ([
            'permutationsIterated',
            'permutationIndex',
            'templatesGenerated',
            'templateIndex',
            'nodesExplored',
            'stopReason',
            'time',
            'meetingsVariationLimit',
            'candidatesCollected',
            'candidatesDeduped',
            'candidateIndex',
            'relaxAttempts',
        ] as $key) {
            if (!array_key_exists($key, $metrics['matchMaking']['stats'])) {
                $metrics['matchMaking']['stats'][$key] = null;
            }
        }

        foreach ([
            'stopReason',
            'permutationsIterated',
            'permutationIndex',
            'nodesExplored',
            'seedIndex',
            'seedsTotal',
            'time',
            'relaxAttempts',
        ] as $key) {
            if (!array_key_exists($key, $metrics['ordering']['stats'])) {
                $metrics['ordering']['stats'][$key] = null;
            }
        }

        foreach ([
            'stopReason',
            'time',
            'nodesExplored',
            'seedIndex',
            'seedsTotal',
        ] as $key) {
            if (!array_key_exists($key, $metrics['pairing']['stats'])) {
                $metrics['pairing']['stats'][$key] = null;
            }
        }

        $mmRelax = $metrics['matchMaking']['stats']['relaxAttempts'] ?? null;
        if (is_array($mmRelax)) {
            $metrics['matchMaking']['stats']['relaxAttempts'] = $this->padMatchMakingRelaxAttempts($mmRelax);
        }

        $ordRelax = $metrics['ordering']['stats']['relaxAttempts'] ?? null;
        if (is_array($ordRelax)) {
            $metrics['ordering']['stats']['relaxAttempts'] = $this->padOrderingRelaxAttempts($ordRelax);
        }

        $payload['metrics'] = $metrics;

        return $payload;
    }

    /**
     * @param array<int, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function padMatchMakingRelaxAttempts(array $raw): array
    {
        $required = [
            'meetingsVariationLimit',
            'permutationsIterated',
            'templatesGenerated',
            'nodesExplored',
            'time',
            'candidatesCollected',
            'candidatesDeduped',
            'stopReason',
        ];
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($required as $key) {
                if (!array_key_exists($key, $entry)) {
                    $entry[$key] = null;
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function padOrderingRelaxAttempts(array $raw): array
    {
        $required = [
            'meetingsVariationLimit',
            'candidatesTried',
            'eligible',
            'time',
            'stopReason',
        ];
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($required as $key) {
                if (!array_key_exists($key, $entry)) {
                    $entry[$key] = null;
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|list<mixed> $decoded
     * @return array<string, mixed>
     */
    private function unwrapPayload(array $decoded): array
    {
        if ($this->isLegacyWrapper($decoded)) {
            $payload = $decoded[1];
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Legacy template wrapper payload must be an object.');
            }

            return $payload;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|list<mixed> $decoded
     */
    private function isLegacyWrapper(array $decoded): bool
    {
        if ($decoded === []) {
            return false;
        }
        $keys = array_keys($decoded);
        $isList = $keys === range(0, count($decoded) - 1);

        return $isList
            && count($decoded) === 2
            && is_array($decoded[1] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isCurrentSchema(array $payload): bool
    {
        return isset($payload['metrics']) && is_array($payload['metrics']);
    }

    /**
     * @param mixed $matches
     * @return array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>>|null
     */
    private function normalizeMatches($matches, int $courts): ?array
    {
        if ($matches === null) {
            return null;
        }
        if (!is_array($matches)) {
            throw new \InvalidArgumentException('Template matches must be an array or null.');
        }
        if ($matches === []) {
            return $courts > 0 ? array_fill(0, $courts, []) : [];
        }

        // Already per-court: first element is a list of matches (each match is [[p,p],[p,p]]).
        if ($this->looksLikePerCourt($matches)) {
            return $matches;
        }

        // Flat legacy round list → single court.
        return [0 => $matches];
    }

    /**
     * @param array<int, mixed> $matches
     */
    private function looksLikePerCourt(array $matches): bool
    {
        $first = reset($matches);
        if (!is_array($first) || $first === []) {
            return false;
        }
        $firstRound = reset($first);
        // Per-court: matches[court][round] = [[a,b],[c,d]]
        // Flat: matches[round] = [[a,b],[c,d]]
        return is_array($firstRound)
            && isset($firstRound[0], $firstRound[1])
            && is_array($firstRound[0])
            && array_key_exists(0, $firstRound[0]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{players:int,partners:int,repeat:int,courts:int,fixedTeams:bool} $identity
     * @param array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>>|null $matches
     */
    private function migrateLegacyPayload(array $payload, array $identity, ?array $matches): TemplateMatches
    {
        $partnersCount = isset($payload['partnersCount']) && is_array($payload['partnersCount'])
            ? $this->intList($payload['partnersCount'])
            : null;
        $playersMet = isset($payload['playersMet']) && is_array($payload['playersMet'])
            ? $this->normalizePlayersMet($payload['playersMet'])
            : null;

        $data = [
            'players' => $identity['players'],
            'partners' => $identity['partners'],
            'repeat' => $identity['repeat'],
            'courts' => $identity['courts'],
            'fixedTeams' => $identity['fixedTeams'],
            'matches' => $matches,
            'metrics' => [
                'pairing' => [
                    'quality' => [
                        'minPartnersFairness' => null,
                        'avgPartnersFairness' => null,
                        'partnersCount' => $partnersCount,
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
                        'meetingsVariation' => array_key_exists('meetingsVariation', $payload)
                            ? ($payload['meetingsVariation'] !== null ? (float) $payload['meetingsVariation'] : null)
                            : null,
                        'minOpponentsMet' => null,
                        'maxOpponentsMet' => null,
                        'playersMet' => $playersMet,
                        'matchesCount' => null,
                        'minPlayingFairness' => null,
                        'avgPlayingFairness' => null,
                        'maxPlayingFairnessPenalty' => null,
                    ],
                    'stats' => [
                        'permutationsIterated' => isset($payload['permutationsIterated'])
                            ? (int) $payload['permutationsIterated']
                            : null,
                        'permutationIndex' => array_key_exists('permutationIndex', $payload) && $payload['permutationIndex'] !== null
                            ? (int) $payload['permutationIndex']
                            : null,
                        'templatesGenerated' => isset($payload['templatesGenerated'])
                            ? (int) $payload['templatesGenerated']
                            : null,
                        'templateIndex' => array_key_exists('templateIndex', $payload) && $payload['templateIndex'] !== null
                            ? (int) $payload['templateIndex']
                            : null,
                        'nodesExplored' => null,
                        'stopReason' => null,
                        'time' => array_key_exists('generationTime', $payload) && $payload['generationTime'] !== null
                            ? (float) $payload['generationTime']
                            : null,
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

        return TemplateMatches::fromArray($data);
    }

    /**
     * @param array<int|string, mixed> $values
     * @return array<int, int>
     */
    private function intList(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out[(int) $k] = (int) $v;
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $playersMet
     * @return array<int, array<int, int>>
     */
    private function normalizePlayersMet(array $playersMet): array
    {
        $out = [];
        foreach ($playersMet as $p => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[(int) $p] = [];
            foreach ($row as $q => $count) {
                $out[(int) $p][(int) $q] = (int) $count;
            }
        }

        return $out;
    }
}
