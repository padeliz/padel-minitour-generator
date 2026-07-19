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
     * Upper bound on final minBreak from locked (non-null) shortestInner values only.
     *
     * @param array<int, int|null> $shortestInner
     */
    public static function minBreakUpperBound(array $shortestInner): ?int
    {
        $locked = [];
        foreach ($shortestInner as $value) {
            if ($value !== null) {
                $locked[] = (int) $value;
            }
        }

        return $locked === [] ? null : min($locked);
    }

    /**
     * Density-aware absolute cMin threshold (T0 / T1), or null when fallback is disabled.
     */
    public static function densityAbsoluteCMinThreshold(
        int $players,
        int $partners,
        int $courts,
        int $partialMinBreak
    ): ?int {
        if ($partialMinBreak > 1) {
            return null;
        }

        $idlePerRound = $players - (4 * $courts);
        if ($idlePerRound <= 0) {
            return null;
        }

        $rounds = (int) ceil(($players * $partners) / (4 * $courts));
        $sitsPerPlayer = $rounds - $partners;
        $t0 = max(2, $partners - max(0, $sitsPerPlayer - 1));

        if ($partialMinBreak === 0) {
            return $t0;
        }

        return max(3, $t0 + 1);
    }

    /**
     * Lex-implied mid-search prune on minBreak / maxBreak vs incumbent (no deferral).
     *
     * @param array<int, int|null> $shortestInner
     * @param array<int, int>      $longestRuns
     * @param array{
     *     ordered: mixed,
     *     minBreak: int|null,
     *     maxBreak: int|null
     * } $bestState
     */
    public static function shouldPrunePartialBreakBounds(
        array $shortestInner,
        array $longestRuns,
        array $bestState
    ): bool {
        if ($bestState['ordered'] === null) {
            return false;
        }

        $bestMin = $bestState['minBreak'];
        $bestMax = $bestState['maxBreak'];
        if ($bestMin === null || $bestMax === null) {
            return false;
        }

        $minBreakUb = self::minBreakUpperBound($shortestInner);
        if ($minBreakUb === null) {
            return false;
        }

        if ($minBreakUb < $bestMin) {
            return true;
        }

        if ($minBreakUb === $bestMin) {
            $partialMaxBreak = max($longestRuns);

            return $partialMaxBreak > $bestMax;
        }

        return false;
    }

    /**
     * Mid-search streak prunes: incumbent cMin/cMax when targets locked, then density absolute cMin.
     *
     * Caller must apply deferral (unseen players / &lt;75% placed) before invoking.
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @param array<int, int>                                     $playerIndices
     * @param array<int, bool>                                    $playedAtLeastOnce
     * @param array<int, int|null>                                $shortestInner
     * @param array<int, int>                                     $longestRuns
     * @param array{
     *     ordered: mixed,
     *     minBreak: int|null,
     *     maxBreak: int|null,
     *     consecutiveMinBreaks: int,
     *     consecutiveMaxBreaks: int
     * }|null $bestState
     */
    public static function shouldPrunePartialConsecutiveMinBreaks(
        array $matchesByCourt,
        array $playerIndices,
        array $playedAtLeastOnce,
        array $shortestInner,
        int $players,
        int $partners,
        int $courts,
        array $longestRuns = [],
        ?array $bestState = null
    ): bool {
        $playedPlayers = [];
        $innerBreaks = [];
        foreach ($playerIndices as $player) {
            if (!($playedAtLeastOnce[$player] ?? false)) {
                continue;
            }
            $playedPlayers[] = (int) $player;
            if ($shortestInner[$player] !== null) {
                $innerBreaks[] = $shortestInner[$player];
            }
        }

        if ($playedPlayers === [] || $innerBreaks === []) {
            return false;
        }

        $partialMinBreak = min($innerBreaks);
        $minBreakUb = $partialMinBreak;
        $partialMaxBreak = $longestRuns === [] ? 0 : max($longestRuns);

        $maxInnerGapCount = 0;
        foreach ($playedPlayers as $player) {
            $maxInnerGapCount = max(
                $maxInnerGapCount,
                count(self::innerGapsForPlayer($matchesByCourt, (int) $player))
            );
        }

        if (
            $bestState !== null
            && $bestState['ordered'] !== null
            && $bestState['minBreak'] !== null
            && $bestState['maxBreak'] !== null
            && $minBreakUb === $bestState['minBreak']
        ) {
            if ($partialMaxBreak >= $bestState['maxBreak'] && $maxInnerGapCount >= 2) {
                $partialCMin = self::longestPartialConsecutiveMinBreakStreak(
                    $matchesByCourt,
                    $playedPlayers,
                    $partialMinBreak
                );
                if ($partialCMin > $bestState['consecutiveMinBreaks']) {
                    return true;
                }
            }

            if ($partialMaxBreak === $bestState['maxBreak']) {
                $partialCMax = self::longestPartialConsecutiveMaxBreakStreak(
                    $matchesByCourt,
                    $playedPlayers,
                    $bestState['maxBreak']
                );
                if ($partialCMax > $bestState['consecutiveMaxBreaks']) {
                    return true;
                }
            }
        }

        if ($partialMinBreak > 1) {
            return false;
        }

        if ($maxInnerGapCount < 2) {
            return false;
        }

        $threshold = self::densityAbsoluteCMinThreshold(
            $players,
            $partners,
            $courts,
            $partialMinBreak
        );
        if ($threshold === null) {
            return false;
        }

        $consecutiveMinBreaks = self::longestPartialConsecutiveMinBreakStreak(
            $matchesByCourt,
            $playedPlayers,
            $partialMinBreak
        );

        return $consecutiveMinBreaks > $threshold;
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
     * Completed gaps only (excludes open trail) — safe lower bound for partial cMax.
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array<int, int>
     */
    private static function completedGapsForPlayer(array $matchesByCourt, int $player): array
    {
        $gaps = self::allGapsForPlayer($matchesByCourt, $player);
        if ($gaps === []) {
            return [];
        }

        array_pop($gaps);

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

    /**
     * Longest consecutive minBreak-matching inner-gap run on a partial schedule.
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @param array<int, int>                                     $playerIndices
     */
    private static function longestPartialConsecutiveMinBreakStreak(
        array $matchesByCourt,
        array $playerIndices,
        int $targetMinBreak
    ): int {
        $best = 0;
        foreach ($playerIndices as $player) {
            $best = max($best, self::longestConsecutiveRunMatching(
                self::innerGapsForPlayer($matchesByCourt, (int) $player),
                $targetMinBreak
            ));
        }

        return $best;
    }

    /**
     * Longest consecutive maxBreak-matching completed-gap run on a partial schedule.
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @param array<int, int>                                     $playerIndices
     */
    private static function longestPartialConsecutiveMaxBreakStreak(
        array $matchesByCourt,
        array $playerIndices,
        int $targetMaxBreak
    ): int {
        $best = 0;
        foreach ($playerIndices as $player) {
            $best = max($best, self::longestConsecutiveRunMatching(
                self::completedGapsForPlayer($matchesByCourt, (int) $player),
                $targetMaxBreak
            ));
        }

        return $best;
    }

    /**
     * Inner sit-out gaps between consecutive play appearances (excludes lead and trail).
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array<int, int>
     */
    private static function innerGapsForPlayer(array $matchesByCourt, int $player): array
    {
        $roundsTotal = self::roundsTotal($matchesByCourt);
        $innerGaps = [];
        $currentRun = 0;
        $hasPlayed = false;

        for ($r = 0; $r < $roundsTotal; $r++) {
            if (self::playerPlaysRound($matchesByCourt, $player, $r)) {
                if ($hasPlayed) {
                    $innerGaps[] = $currentRun;
                }
                $hasPlayed = true;
                $currentRun = 0;
            } else {
                $currentRun++;
            }
        }

        return $innerGaps;
    }
}
