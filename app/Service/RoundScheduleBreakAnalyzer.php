<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Round-aware break metrics from a per-court match schedule.
 *
 * One schedule step is one round index across all courts; a player plays in a step if they appear
 * on any court that round.
 */
final class RoundScheduleBreakAnalyzer
{
    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @param array<int, int>                                     $playerIndices
     * @return array{consecutiveMinBreaks: int|null, consecutiveMaxBreaks: int|null}
     */
    public static function analyze(
        array $matchesByCourt,
        array $playerIndices,
        ?int $minBreak,
        ?int $maxBreak
    ): array {
        $consecutiveMinBreaks = null;
        $consecutiveMaxBreaks = null;

        if ($minBreak !== null) {
            $consecutiveMinBreaks = 0;
            foreach ($playerIndices as $player) {
                $gaps = self::allGapsForPlayer($matchesByCourt, (int) $player);
                $consecutiveMinBreaks = max(
                    $consecutiveMinBreaks,
                    self::longestConsecutiveRunMatching($gaps, $minBreak)
                );
            }
        }

        if ($maxBreak !== null) {
            $consecutiveMaxBreaks = 0;
            foreach ($playerIndices as $player) {
                $gaps = self::allGapsForPlayer($matchesByCourt, (int) $player);
                $consecutiveMaxBreaks = max(
                    $consecutiveMaxBreaks,
                    self::longestConsecutiveRunMatching($gaps, $maxBreak)
                );
            }
        }

        return [
            'consecutiveMinBreaks' => $consecutiveMinBreaks,
            'consecutiveMaxBreaks' => $consecutiveMaxBreaks,
        ];
    }

    /**
     * Reference minBreak / maxBreak from a committed schedule (matches production DFS semantics).
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @param array<int, int>                                     $playerIndices
     * @return array{minBreak: int, maxBreak: int}
     */
    public static function computeBreakMetrics(array $matchesByCourt, array $playerIndices): array
    {
        $perPlayerMin = [];
        $perPlayerMax = [];
        foreach ($playerIndices as $player) {
            $metrics = self::innerAndLongestBreakMetrics($matchesByCourt, (int) $player);
            $perPlayerMin[] = $metrics['shortestInner'];
            $perPlayerMax[] = $metrics['longestAll'];
        }

        return [
            'minBreak' => min($perPlayerMin),
            'maxBreak' => max($perPlayerMax),
        ];
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array{shortestInner: int, longestAll: int}
     */
    private static function innerAndLongestBreakMetrics(array $matchesByCourt, int $player): array
    {
        $roundsTotal = self::roundsTotal($matchesByCourt);
        $currentRun = 0;
        $longest = 0;
        $hasPlayed = false;
        $innerRuns = [];

        for ($r = 0; $r < $roundsTotal; $r++) {
            if (self::playerPlaysRound($matchesByCourt, $player, $r)) {
                if ($hasPlayed) {
                    $innerRuns[] = $currentRun;
                }
                if ($currentRun > $longest) {
                    $longest = $currentRun;
                }
                $hasPlayed = true;
                $currentRun = 0;
            } else {
                $currentRun++;
            }
        }

        if ($currentRun > $longest) {
            $longest = $currentRun;
        }

        return [
            'shortestInner' => $innerRuns === [] ? 0 : min($innerRuns),
            'longestAll' => $longest,
        ];
    }

    /**
     * Lead, inner, and trail gaps: each length is rounds sat out between appearances.
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array<int, int>
     */
    private static function allGapsForPlayer(array $matchesByCourt, int $player): array
    {
        $roundsTotal = self::roundsTotal($matchesByCourt);
        $gaps = [];
        $currentRun = 0;
        $hasPlayed = false;

        for ($r = 0; $r < $roundsTotal; $r++) {
            if (self::playerPlaysRound($matchesByCourt, $player, $r)) {
                $gaps[] = $currentRun;
                $hasPlayed = true;
                $currentRun = 0;
            } else {
                $currentRun++;
            }
        }

        if ($hasPlayed || $roundsTotal > 0) {
            $gaps[] = $currentRun;
        }

        return $gaps;
    }

    /**
     * @param array<int, int> $gaps
     */
    private static function longestConsecutiveRunMatching(array $gaps, int $target): int
    {
        $best = 0;
        $current = 0;
        foreach ($gaps as $gap) {
            if ($gap === $target) {
                $current++;
                if ($current > $best) {
                    $best = $current;
                }
            } else {
                $current = 0;
            }
        }

        return $best;
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     */
    private static function roundsTotal(array $matchesByCourt): int
    {
        $roundsTotal = 0;
        foreach ($matchesByCourt as $courtRounds) {
            $roundsTotal = max($roundsTotal, count($courtRounds));
        }

        return $roundsTotal;
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     */
    private static function playerPlaysRound(array $matchesByCourt, int $player, int $round): bool
    {
        foreach ($matchesByCourt as $courtRounds) {
            if (!isset($courtRounds[$round])) {
                continue;
            }
            $match = $courtRounds[$round];
            $seats = [
                (int) $match[0][0],
                (int) $match[0][1],
                (int) $match[1][0],
                (int) $match[1][1],
            ];
            if (in_array($player, $seats, true)) {
                return true;
            }
        }

        return false;
    }
}
