<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Court-switch and court-balance metrics from a completed per-court schedule.
 *
 * Court-switch semantics match ordering DFS leaf rules: increment only when a player
 * played the previous time slot (no sit-out) and changes court.
 */
final class CourtScheduleMetrics
{
    /**
     * @param array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>> $matchesByCourt
     * @param array<int, int> $playerIndices
     * @return array{courtSwitches: int, courtBalance: float}
     */
    public static function score(array $matchesByCourt, array $playerIndices, int $courts): array
    {
        return [
            'courtSwitches' => self::courtSwitchesFromSchedule($matchesByCourt, $playerIndices, $courts),
            'courtBalance' => self::courtBalanceFromSchedule($matchesByCourt, $playerIndices, $courts),
        ];
    }

    /**
     * @param array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>> $matchesByCourt
     * @param array<int, int> $playerIndices
     */
    public static function courtSwitchesFromSchedule(
        array $matchesByCourt,
        array $playerIndices,
        int $courts
    ): int {
        if ($courts <= 1 || $matchesByCourt === []) {
            return 0;
        }

        $roundsTotal = TemplateMatchDerivation::roundsCount($matchesByCourt) ?? 0;
        if ($roundsTotal <= 0) {
            return 0;
        }

        $currentRuns = [];
        $playedAtLeastOnce = [];
        $lastCourt = [];
        $courtSwitches = [];
        foreach ($playerIndices as $playerIndex) {
            $currentRuns[$playerIndex] = 0;
            $playedAtLeastOnce[$playerIndex] = false;
            $lastCourt[$playerIndex] = null;
            $courtSwitches[$playerIndex] = 0;
        }

        for ($r = 0; $r < $roundsTotal; $r++) {
            $playersInRound = [];
            for ($c = 0; $c < $courts; $c++) {
                if (!isset($matchesByCourt[$c][$r])) {
                    continue;
                }
                foreach (self::matchPlayerLookup($matchesByCourt[$c][$r]) as $p => $_) {
                    $playersInRound[$p] = $c;
                }
            }

            foreach ($playerIndices as $playerIndex) {
                if (isset($playersInRound[$playerIndex])) {
                    $court = $playersInRound[$playerIndex];
                    if ($playedAtLeastOnce[$playerIndex]) {
                        if (
                            $currentRuns[$playerIndex] === 0
                            && $lastCourt[$playerIndex] !== null
                            && $lastCourt[$playerIndex] !== $court
                        ) {
                            $courtSwitches[$playerIndex]++;
                        }
                    } else {
                        $playedAtLeastOnce[$playerIndex] = true;
                    }
                    $lastCourt[$playerIndex] = $court;
                    $currentRuns[$playerIndex] = 0;
                } else {
                    $currentRuns[$playerIndex]++;
                }
            }
        }

        return $courtSwitches === [] ? 0 : max($courtSwitches);
    }

    /**
     * @param array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>> $matchesByCourt
     * @param array<int, int> $playerIndices
     */
    public static function courtBalanceFromSchedule(
        array $matchesByCourt,
        array $playerIndices,
        int $courts
    ): float {
        if ($courts <= 1) {
            return 0.0;
        }

        $perPlayerSpread = [];
        foreach ($playerIndices as $playerIndex) {
            $counts = array_fill(0, $courts, 0);
            foreach ($matchesByCourt as $courtIdx => $rounds) {
                foreach ($rounds as $match) {
                    $lookup = self::matchPlayerLookup($match);
                    if (isset($lookup[$playerIndex])) {
                        $counts[$courtIdx]++;
                    }
                }
            }
            $perPlayerSpread[] = max($counts) - min($counts);
        }

        return $perPlayerSpread === [] ? 0.0 : (float) max($perPlayerSpread);
    }

    /**
     * @param array{0: array{0:int,1:int}, 1: array{0:int,1:int}} $match
     * @return array<int, true>
     */
    private static function matchPlayerLookup(array $match): array
    {
        $lookup = [];
        foreach ([$match[0][0], $match[0][1], $match[1][0], $match[1][1]] as $p) {
            $lookup[(int) $p] = true;
        }

        return $lookup;
    }
}
