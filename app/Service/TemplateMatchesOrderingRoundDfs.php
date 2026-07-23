<?php

/**
 * Multi-court round-slice ordering DFS helpers for TemplateMatchesGenerator.
 * Included from TemplateMatchesGenerator.php — not a standalone class.
 */

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\Service\Progress\ProgressReporter;

trait TemplateMatchesOrderingRoundDfs
{
    /**
     * @param array<int, array<int, array<int, int>>> $flatMatches
     * @return array{
     *     ordered: array<int, array<int, array<int, array<int, int>>>>,
     *     stopReason: string,
     *     min: float|null,
     *     avg: float|null,
     *     permutationsIterated: int,
     *     permutationIndex: int|null,
     *     minBreak: int|null,
     *     maxBreak: int|null,
     *     courtSwitches: int|null,
     *     courtBalance: float|null,
     *     nodesExplored: int,
     *     seedIndex: int|null,
     *     seedsTotal: int
     * }
     */
    private function runOrderingPhase(array $flatMatches, array $mockPlayers, ProgressReporter $reporter): array
    {
        $flatMatches = array_values($flatMatches);
        $m = count($flatMatches);
        $courts = $this->activeCourts;

        if ($courts < 1) {
            return $this->orderingFailed(
                self::STOP_REASON_PRUNE_INFEASIBLE,
                0,
                0,
                1,
                $reporter
            );
        }

        if ($m === 0) {
            $emptyByCourt = [];
            for ($c = 0; $c < $courts; $c++) {
                $emptyByCourt[$c] = [];
            }

            return [
                'ordered' => $emptyByCourt,
                'stopReason' => self::STOP_REASON_TRIVIAL,
                'min' => null,
                'avg' => null,
                'permutationsIterated' => 0,
                'permutationIndex' => null,
                'minBreak' => null,
                'maxBreak' => null,
                'courtSwitches' => null,
                'courtBalance' => null,
                'nodesExplored' => 0,
                'seedIndex' => 1,
                'seedsTotal' => 1,
            ];
        }

        if ($m === 1) {
            $singleByCourt = [];
            for ($c = 0; $c < $courts; $c++) {
                $singleByCourt[$c] = [];
            }
            $singleByCourt[0][] = $flatMatches[0];

            return [
                'ordered' => $singleByCourt,
                'stopReason' => self::STOP_REASON_TRIVIAL,
                'min' => null,
                'avg' => null,
                'permutationsIterated' => 1,
                'permutationIndex' => 1,
                'minBreak' => null,
                'maxBreak' => null,
                'courtSwitches' => null,
                'courtBalance' => null,
                'nodesExplored' => 0,
                'seedIndex' => 1,
                'seedsTotal' => 1,
            ];
        }

        $useMultiSeed = ($m >= $this->multiSeedThresholdPairs || $courts >= 2)
            && $this->multiSeedCountSort > 1;
        $totalSeeds = $useMultiSeed ? $this->multiSeedCountSort : 1;
        $perSeedBudgetNs = intdiv($this->effectiveOrderingBudgetNs, $totalSeeds);

        $globalBest = null;
        $globalBestSeedIdx = null;
        $totalNodesExplored = 0;
        $anyDeadline = false;

        for ($seedIdx = 0; $seedIdx < $totalSeeds; $seedIdx++) {
            $perm = $useMultiSeed
                ? $this->lehmerSeedPermutation($seedIdx, $totalSeeds, $m)
                : range(0, $m - 1);
            $seedMatches = [];
            foreach ($perm as $idx) {
                $seedMatches[] = $flatMatches[$idx];
            }

            $this->orderingCurrentSeed = $seedIdx + 1;
            $this->orderingTotalSeeds = $totalSeeds;
            $this->orderingPerSeedBudgetNs = $perSeedBudgetNs;

            $deadlineNs = $this->monotonicNow() + $perSeedBudgetNs;
            $seedResult = $this->orderingSingleSeed($seedMatches, $mockPlayers, $deadlineNs, $reporter);
            $totalNodesExplored += $seedResult['nodesExplored'];

            if ($seedResult['stopReason'] === self::STOP_REASON_DEADLINE) {
                $anyDeadline = true;
            }

            if ($this->isOrderingSeedResultLexBetter($seedResult, $globalBest, $seedIdx, $globalBestSeedIdx)) {
                $globalBest = $seedResult;
                $globalBestSeedIdx = $seedIdx;
            }
        }

        if ($globalBest === null || $globalBest['ordered'] === null) {
            $stopReason = $anyDeadline
                ? self::STOP_REASON_DEADLINE
                : self::STOP_REASON_PRUNE_INFEASIBLE;

            return $this->orderingFailed(
                $stopReason,
                0,
                $totalNodesExplored,
                $totalSeeds,
                $reporter
            );
        }

        $reporter->ordering(
            $globalBest['permutationsIterated'],
            $globalBest['min'],
            $globalBest['avg'],
            $this->effectiveOrderingBudgetNs,
            $this->monotonicNow(),
            true,
            $globalBest['stopReason'],
            $globalBest['permutationIndex'],
            $globalBest['minBreak'],
            $globalBest['maxBreak'],
            $globalBest['courtSwitches'],
            $globalBestSeedIdx + 1,
            $totalSeeds
        );

        return [
            'ordered' => $globalBest['ordered'],
            'stopReason' => $globalBest['stopReason'],
            'min' => $globalBest['min'],
            'avg' => $globalBest['avg'],
            'permutationsIterated' => $globalBest['permutationsIterated'],
            'permutationIndex' => $globalBest['permutationIndex'],
            'minBreak' => $globalBest['minBreak'],
            'maxBreak' => $globalBest['maxBreak'],
            'consecutiveMinBreaks' => $globalBest['consecutiveMinBreaks'] ?? null,
            'consecutiveMaxBreaks' => $globalBest['consecutiveMaxBreaks'] ?? null,
            'normCourtSwitches' => $globalBest['normCourtSwitches'] ?? null,
            'courtSwitches' => $globalBest['courtSwitches'],
            'courtBalance' => $globalBest['courtBalance'],
            'nodesExplored' => $totalNodesExplored,
            'seedIndex' => $globalBestSeedIdx + 1,
            'seedsTotal' => $totalSeeds,
        ];
    }

    /**
     * @param array<int, array<int, array<int, int>>> $flatMatches
     * @return array{
     *     ordered: array<int, array<int, array<int, array<int, int>>>>|null,
     *     stopReason: string,
     *     min: float|null,
     *     avg: float|null,
     *     normCourtSwitches: float|null,
     *     permutationsIterated: int,
     *     permutationIndex: int|null,
     *     minBreak: int|null,
     *     maxBreak: int|null,
     *     consecutiveMinBreaks: int,
     *     consecutiveMaxBreaks: int,
     *     courtSwitches: int|null,
     *     courtBalance: float|null,
     *     nodesExplored: int
     * }
     */
    private function orderingSingleSeed(
        array $flatMatches,
        array $mockPlayers,
        int $deadlineNs,
        ProgressReporter $reporter
    ): array {
        $m = count($flatMatches);
        $courts = $this->activeCourts;
        $playersCount = count($mockPlayers);
        $roundsTotal = (int) ceil($m / $courts);
        $maxBreakThreshold = $this->maxBreakThreshold >= 0
            ? $this->maxBreakThreshold
            : (int) ceil($playersCount / 4);

        $matchesByCourt = [];
        for ($c = 0; $c < $courts; $c++) {
            $matchesByCourt[$c] = [];
        }

        $used = array_fill(0, $m, false);
        $currentRuns = [];
        $longestRuns = [];
        $playedAtLeastOnce = [];
        $shortestInner = [];
        $lastCourt = [];
        $courtSwitches = [];
        foreach ($mockPlayers as $playerIndex) {
            $currentRuns[$playerIndex] = 0;
            $longestRuns[$playerIndex] = 0;
            $playedAtLeastOnce[$playerIndex] = false;
            $shortestInner[$playerIndex] = null;
            $lastCourt[$playerIndex] = null;
            $courtSwitches[$playerIndex] = 0;
        }

        $bestState = [
            'ordered' => null,
            'min' => null,
            'avg' => null,
            'permutationIndex' => null,
            'minBreak' => null,
            'maxBreak' => null,
            'consecutiveMinBreaks' => PHP_INT_MAX,
            'consecutiveMaxBreaks' => PHP_INT_MAX,
            'normCourtSwitches' => null,
            'courtSwitches' => null,
            'courtBalance' => null,
        ];

        $iterations = 0;
        $nodesExplored = 0;
        $branchesRemaining = $this->dfsBranchCap;
        $exit = ['stopReason' => self::STOP_REASON_FACTORIAL_COMPLETE];

        $this->sortRoundDfsExpand(
            $flatMatches,
            $mockPlayers,
            $playersCount,
            $courts,
            $roundsTotal,
            $matchesByCourt,
            $used,
            $currentRuns,
            $longestRuns,
            $playedAtLeastOnce,
            $shortestInner,
            $lastCourt,
            $courtSwitches,
            $maxBreakThreshold,
            $deadlineNs,
            $bestState,
            $iterations,
            $nodesExplored,
            $branchesRemaining,
            $reporter,
            $exit
        );

        return [
            'ordered' => $bestState['ordered'],
            'stopReason' => $exit['stopReason'],
            'min' => $bestState['min'],
            'avg' => $bestState['avg'],
            'permutationsIterated' => $iterations,
            'permutationIndex' => $bestState['permutationIndex'],
            'minBreak' => $bestState['minBreak'],
            'maxBreak' => $bestState['maxBreak'],
            'consecutiveMinBreaks' => $bestState['consecutiveMinBreaks'],
            'consecutiveMaxBreaks' => $bestState['consecutiveMaxBreaks'],
            'normCourtSwitches' => $bestState['normCourtSwitches'],
            'courtSwitches' => $bestState['courtSwitches'],
            'courtBalance' => $bestState['courtBalance'],
            'nodesExplored' => $nodesExplored,
        ];
    }

    /**
     * @param array{
     *     minBreak?: int|null,
     *     maxBreak?: int|null,
     *     consecutiveMinBreaks?: int|null,
     *     consecutiveMaxBreaks?: int|null,
     *     normCourtSwitches?: float|null,
     *     min?: float|null,
     *     avg?: float|null
     * } $candidate
     * @param array{
     *     minBreak?: int|null,
     *     maxBreak?: int|null,
     *     consecutiveMinBreaks?: int|null,
     *     consecutiveMaxBreaks?: int|null,
     *     normCourtSwitches?: float|null,
     *     min?: float|null,
     *     avg?: float|null,
     *     ordered?: array<int, array<int, array<int, array<int, int>>>>|null
     * }|null $currentBest
     */
    private function isOrderingLexBetter(array $candidate, ?array $currentBest): bool
    {
        return $this->compareOrderingLex($candidate, $currentBest) > 0;
    }

    /**
     * Lex ordering for DFS leaves and cross-seed winners.
     *
     * Tiers: minBreak (max), maxBreak (min), consecutiveMinBreaks (min), consecutiveMaxBreaks (min),
     * normCourtSwitches (min), minDistribution (max), avgDistribution (max).
     *
     * @param array{
     *     minBreak?: int|null,
     *     maxBreak?: int|null,
     *     consecutiveMinBreaks?: int|null,
     *     consecutiveMaxBreaks?: int|null,
     *     normCourtSwitches?: float|null,
     *     min?: float|null,
     *     avg?: float|null
     * } $candidate
     * @param array{
     *     minBreak?: int|null,
     *     maxBreak?: int|null,
     *     consecutiveMinBreaks?: int|null,
     *     consecutiveMaxBreaks?: int|null,
     *     normCourtSwitches?: float|null,
     *     min?: float|null,
     *     avg?: float|null,
     *     ordered?: array<int, array<int, array<int, array<int, int>>>>|null
     * }|null $currentBest
     * @return int positive when candidate wins, negative when incumbent wins, zero on full tie
     */
    private function compareOrderingLex(array $candidate, ?array $currentBest): int
    {
        if ($currentBest === null || ($currentBest['ordered'] ?? null) === null) {
            return 1;
        }

        $cMinBreak = $candidate['minBreak'] ?? -1;
        $bMinBreak = $currentBest['minBreak'] ?? -1;
        if ($cMinBreak > $bMinBreak) {
            return 1;
        }
        if ($cMinBreak < $bMinBreak) {
            return -1;
        }

        $cMaxBreak = $candidate['maxBreak'] ?? PHP_INT_MAX;
        $bMaxBreak = $currentBest['maxBreak'] ?? PHP_INT_MAX;
        if ($cMaxBreak < $bMaxBreak) {
            return 1;
        }
        if ($cMaxBreak > $bMaxBreak) {
            return -1;
        }

        $cStreakMin = $this->orderingStreakValue($candidate['consecutiveMinBreaks'] ?? null);
        $bStreakMin = $this->orderingStreakValue($currentBest['consecutiveMinBreaks'] ?? null);
        if ($cStreakMin < $bStreakMin) {
            return 1;
        }
        if ($cStreakMin > $bStreakMin) {
            return -1;
        }

        $cStreakMax = $this->orderingStreakValue($candidate['consecutiveMaxBreaks'] ?? null);
        $bStreakMax = $this->orderingStreakValue($currentBest['consecutiveMaxBreaks'] ?? null);
        if ($cStreakMax < $bStreakMax) {
            return 1;
        }
        if ($cStreakMax > $bStreakMax) {
            return -1;
        }

        $cNormCourt = $candidate['normCourtSwitches'] ?? INF;
        $bNormCourt = $currentBest['normCourtSwitches'] ?? INF;
        if (LexFloat::isBetterMin($cNormCourt, $bNormCourt)) {
            return 1;
        }
        if (LexFloat::isBetterMin($bNormCourt, $cNormCourt)) {
            return -1;
        }

        $cMin = $candidate['min'] ?? -INF;
        $bMin = $currentBest['min'] ?? -INF;
        if (LexFloat::isBetterMax($cMin, $bMin)) {
            return 1;
        }
        if (LexFloat::isBetterMax($bMin, $cMin)) {
            return -1;
        }

        $cAvg = $candidate['avg'] ?? -INF;
        $bAvg = $currentBest['avg'] ?? -INF;
        if (LexFloat::isBetterMax($cAvg, $bAvg)) {
            return 1;
        }
        if (LexFloat::isBetterMax($bAvg, $cAvg)) {
            return -1;
        }

        return 0;
    }

    private function orderingStreakValue(?int $value): int
    {
        return $value ?? PHP_INT_MAX;
    }

    /**
     * @param array{
     *     ordered: array<int, array<int, array<int, array<int, int>>>>|null,
     *     minBreak?: int|null,
     *     maxBreak?: int|null,
     *     consecutiveMinBreaks?: int|null,
     *     consecutiveMaxBreaks?: int|null,
     *     normCourtSwitches?: float|null,
     *     min?: float|null,
     *     avg?: float|null
     * } $candidate
     * @param array{
     *     ordered: array<int, array<int, array<int, array<int, int>>>>|null,
     *     minBreak?: int|null,
     *     maxBreak?: int|null,
     *     consecutiveMinBreaks?: int|null,
     *     consecutiveMaxBreaks?: int|null,
     *     normCourtSwitches?: float|null,
     *     min?: float|null,
     *     avg?: float|null
     * }|null $currentBest
     */
    private function isOrderingSeedResultLexBetter(
        array $candidate,
        ?array $currentBest,
        int $candidateSeedIdx,
        ?int $currentBestSeedIdx
    ): bool {
        if ($candidate['ordered'] === null) {
            return false;
        }
        if ($currentBest === null || $currentBest['ordered'] === null) {
            return true;
        }

        $compare = $this->compareOrderingLex($candidate, $currentBest);
        if ($compare > 0) {
            return true;
        }
        if ($compare < 0) {
            return false;
        }

        return $currentBestSeedIdx === null || $candidateSeedIdx < $currentBestSeedIdx;
    }

    /**
     * @return array{
     *     ordered: null,
     *     stopReason: string,
     *     min: null,
     *     avg: null,
     *     permutationsIterated: int,
     *     permutationIndex: null,
     *     minBreak: null,
     *     maxBreak: null,
     *     courtSwitches: null,
     *     courtBalance: null,
     *     nodesExplored: int,
     *     seedIndex: null,
     *     seedsTotal: int
     * }
     */
    private function orderingFailed(
        string $stopReason,
        int $iterations,
        int $nodesExplored,
        int $seedsTotal,
        ProgressReporter $reporter
    ): array {
        $reporter->ordering(
            $iterations,
            null,
            null,
            $this->effectiveOrderingBudgetNs,
            $this->monotonicNow(),
            true,
            $stopReason,
            null,
            null,
            null,
            max(1, $seedsTotal),
            max(1, $seedsTotal)
        );

        return [
            'ordered' => null,
            'stopReason' => $stopReason,
            'min' => null,
            'avg' => null,
            'permutationsIterated' => $iterations,
            'permutationIndex' => null,
            'minBreak' => null,
            'maxBreak' => null,
            'courtSwitches' => null,
            'courtBalance' => null,
            'nodesExplored' => $nodesExplored,
            'seedIndex' => null,
            'seedsTotal' => $seedsTotal,
        ];
    }

    /**
     * @param array{
     *     min: float|null,
     *     avg: float|null,
     *     permutationIndex: int|null,
     *     minBreak: int|null,
     *     maxBreak: int|null,
     *     courtSwitches: int|null
     * } $bestState
     */
    private function emitOrderingProgress(
        ProgressReporter $reporter,
        int $now,
        bool $isFinal,
        int $iterations,
        array $bestState,
        ?string $stopReason = null
    ): void {
        $reporter->ordering(
            $iterations,
            $bestState['min'],
            $bestState['avg'],
            $this->orderingPerSeedBudgetNs,
            $now,
            $isFinal,
            $stopReason,
            $bestState['permutationIndex'],
            $bestState['minBreak'],
            $bestState['maxBreak'],
            $bestState['courtSwitches'],
            $this->orderingCurrentSeed,
            $this->orderingTotalSeeds
        );
    }

    private function countUsedMatches(array $used): int
    {
        $count = 0;
        foreach ($used as $flag) {
            if ($flag) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Defer streak prune while an unused match can still seat a player who has not appeared.
     *
     * @param array<int, array<int, array<int, int>>> $flatMatches
     * @param array<int, int>                         $mockPlayers
     * @param array<int, bool>                        $used
     * @param array<int, bool>                        $playedAtLeastOnce
     */
    private function orderingPartialStreakPruneDeferred(
        array $flatMatches,
        array $mockPlayers,
        array $used,
        array $playedAtLeastOnce
    ): bool {
        $matchCount = count($flatMatches);
        $usedCount = $this->countUsedMatches($used);
        if ($usedCount >= $matchCount) {
            return false;
        }

        foreach ($mockPlayers as $playerIndex) {
            if ($playedAtLeastOnce[$playerIndex] ?? false) {
                continue;
            }

            foreach ($flatMatches as $matchIdx => $match) {
                if ($used[$matchIdx] ?? false) {
                    continue;
                }

                if (isset($this->matchPlayerLookup($match)[$playerIndex])) {
                    return true;
                }
            }
        }

        // Defer until at least 75% of matches are placed so early prefixes can still branch.
        return ($usedCount * 4) < ($matchCount * 3);
    }

    /**
     * @param array<int, array<int, array<int, int>>> $flatMatches
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @param array<int, bool> $used
     * @param array{
     *     ordered: array<int, array<int, array<int, array<int, int>>>>|null,
     *     min: float|null,
     *     avg: float|null,
     *     permutationIndex: int|null,
     *     minBreak: int|null,
     *     maxBreak: int|null,
     *     normCourtSwitches: float|null,
     *     courtSwitches: int|null,
     *     courtBalance: float|null,
     *     consecutiveMinBreaks: int,
     *     consecutiveMaxBreaks: int
     * } $bestState
     * @param array{stopReason: string} $exit
     */
    private function sortRoundDfsExpand(
        array $flatMatches,
        array $mockPlayers,
        int $playersCount,
        int $courts,
        int $roundsTotal,
        array &$matchesByCourt,
        array &$used,
        array &$currentRuns,
        array &$longestRuns,
        array &$playedAtLeastOnce,
        array &$shortestInner,
        array &$lastCourt,
        array &$courtSwitches,
        int $maxBreakThreshold,
        int $deadlineNs,
        array &$bestState,
        int &$iterations,
        int &$nodesExplored,
        int &$branchesRemaining,
        ProgressReporter $reporter,
        array &$exit
    ): bool {
        $now = $this->monotonicNow();
        if ($now >= $deadlineNs) {
            $exit['stopReason'] = self::STOP_REASON_DEADLINE;
            return true;
        }

        if ($branchesRemaining <= 0) {
            return false;
        }
        $branchesRemaining--;

        $nodesExplored++;

        $m = count($flatMatches);
        if ($this->countUsedMatches($used) === $m) {
            $iterations++;

            $scores = $this->scoreMatchOrderDistribution($matchesByCourt, $mockPlayers);
            $minScore = $scores['min'];
            $avgScore = $scores['avg'];

            $perPlayerMin = [];
            foreach ($mockPlayers as $playerIndex) {
                $perPlayerMin[] = $shortestInner[$playerIndex] ?? 0;
            }
            $currentMinBreak = min($perPlayerMin);
            $currentMaxBreak = max($longestRuns);

            $maxCourtSwitches = max($courtSwitches);
            $normCourtSwitches = $roundsTotal > 1
                ? $maxCourtSwitches / ($roundsTotal - 1)
                : 0.0;
            $courtBalance = CourtScheduleMetrics::courtBalanceFromSchedule($matchesByCourt, $mockPlayers, $courts);

            $candidateLex = [
                'minBreak' => $currentMinBreak,
                'maxBreak' => $currentMaxBreak,
                'normCourtSwitches' => $normCourtSwitches,
                'min' => $minScore,
                'avg' => $avgScore,
            ];

            $streaks = null;
            if ($bestState['ordered'] === null) {
                $streaks = RoundScheduleBreakAnalyzer::analyze(
                    $matchesByCourt,
                    $mockPlayers,
                    $currentMinBreak,
                    $currentMaxBreak
                );
                $candidateLex['consecutiveMinBreaks'] = $streaks['consecutiveMinBreaks'];
                $candidateLex['consecutiveMaxBreaks'] = $streaks['consecutiveMaxBreaks'];
            } elseif (
                $currentMinBreak === $bestState['minBreak']
                && $currentMaxBreak === $bestState['maxBreak']
            ) {
                $streaks = RoundScheduleBreakAnalyzer::analyze(
                    $matchesByCourt,
                    $mockPlayers,
                    $currentMinBreak,
                    $currentMaxBreak
                );
                $candidateLex['consecutiveMinBreaks'] = $streaks['consecutiveMinBreaks'];
                $candidateLex['consecutiveMaxBreaks'] = $streaks['consecutiveMaxBreaks'];
            }

            if ($this->isOrderingLexBetter($candidateLex, $bestState)) {
                if ($streaks === null) {
                    $streaks = RoundScheduleBreakAnalyzer::analyze(
                        $matchesByCourt,
                        $mockPlayers,
                        $currentMinBreak,
                        $currentMaxBreak
                    );
                }
                $bestState['min'] = $minScore;
                $bestState['avg'] = $avgScore;
                $bestState['ordered'] = $this->cloneMatchesByCourt($matchesByCourt);
                $bestState['permutationIndex'] = $iterations;
                $bestState['minBreak'] = $currentMinBreak;
                $bestState['maxBreak'] = $currentMaxBreak;
                $bestState['consecutiveMinBreaks'] = $streaks['consecutiveMinBreaks'];
                $bestState['consecutiveMaxBreaks'] = $streaks['consecutiveMaxBreaks'];
                $bestState['normCourtSwitches'] = $normCourtSwitches;
                $bestState['courtSwitches'] = $maxCourtSwitches;
                $bestState['courtBalance'] = $courtBalance;
            }

            $this->emitOrderingProgress($reporter, $now, false, $iterations, $bestState);

            return false;
        }

        $this->emitOrderingProgress($reporter, $now, false, $nodesExplored, $bestState);

        $unusedCount = $m - $this->countUsedMatches($used);
        $sliceSize = min($courts, $unusedCount);
        $roundCandidates = $this->collectRoundSliceCandidates($flatMatches, $used, $sliceSize);

        foreach ($roundCandidates as $slice) {
            $snapshot = $this->snapshotRoundDfsState(
                $matchesByCourt,
                $used,
                $currentRuns,
                $longestRuns,
                $playedAtLeastOnce,
                $shortestInner,
                $lastCourt,
                $courtSwitches,
                $courts
            );

            $pruned = $this->applyRoundSlice(
                $flatMatches,
                $slice,
                $mockPlayers,
                $courts,
                $matchesByCourt,
                $used,
                $currentRuns,
                $longestRuns,
                $playedAtLeastOnce,
                $shortestInner,
                $lastCourt,
                $courtSwitches,
                $maxBreakThreshold
            );

            if ($pruned) {
                continue;
            }

            if (
                $bestState['ordered'] !== null
                && RoundScheduleBreakAnalyzer::shouldPrunePartialBreakBounds(
                    $shortestInner,
                    $longestRuns,
                    $bestState
                )
            ) {
                $this->restoreRoundDfsState(
                    $snapshot,
                    $matchesByCourt,
                    $used,
                    $currentRuns,
                    $longestRuns,
                    $playedAtLeastOnce,
                    $shortestInner,
                    $lastCourt,
                    $courtSwitches,
                    $courts
                );
                continue;
            }

            if (
                $this->countUsedMatches($used) < $m
                && !$this->orderingPartialStreakPruneDeferred(
                    $flatMatches,
                    $mockPlayers,
                    $used,
                    $playedAtLeastOnce
                )
                && RoundScheduleBreakAnalyzer::shouldPrunePartialConsecutiveMinBreaks(
                    $matchesByCourt,
                    $mockPlayers,
                    $playedAtLeastOnce,
                    $shortestInner,
                    $playersCount,
                    $this->activePartners,
                    $courts,
                    $longestRuns,
                    $bestState
                )
            ) {
                $this->restoreRoundDfsState(
                    $snapshot,
                    $matchesByCourt,
                    $used,
                    $currentRuns,
                    $longestRuns,
                    $playedAtLeastOnce,
                    $shortestInner,
                    $lastCourt,
                    $courtSwitches,
                    $courts
                );
                continue;
            }

            $stop = $this->sortRoundDfsExpand(
                $flatMatches,
                $mockPlayers,
                $playersCount,
                $courts,
                $roundsTotal,
                $matchesByCourt,
                $used,
                $currentRuns,
                $longestRuns,
                $playedAtLeastOnce,
                $shortestInner,
                $lastCourt,
                $courtSwitches,
                $maxBreakThreshold,
                $deadlineNs,
                $bestState,
                $iterations,
                $nodesExplored,
                $branchesRemaining,
                $reporter,
                $exit
            );

            $this->restoreRoundDfsState(
                $snapshot,
                $matchesByCourt,
                $used,
                $currentRuns,
                $longestRuns,
                $playedAtLeastOnce,
                $shortestInner,
                $lastCourt,
                $courtSwitches,
                $courts
            );

            if ($stop) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<int, array<int, int>>> $flatMatches
     * @param array<int, int> $used
     * @return list<array<int, int>>
     */
    private function collectRoundSliceCandidates(array $flatMatches, array $used, int $sliceSize): array
    {
        if ($sliceSize < 1) {
            return [];
        }

        $unused = [];
        $m = count($flatMatches);
        for ($i = 0; $i < $m; $i++) {
            if (!$used[$i]) {
                $unused[] = $i;
            }
        }

        if (count($unused) < $sliceSize) {
            return [];
        }

        $results = [];
        $this->collectRoundSliceRecursive($flatMatches, $unused, $sliceSize, 0, [], $results);

        return $results;
    }

    /**
     * @param array<int, int> $unused
     * @param array<int, int> $current
     * @param list<array<int, int>> $results
     */
    private function collectRoundSliceRecursive(
        array $flatMatches,
        array $unused,
        int $sliceSize,
        int $startPos,
        array $current,
        array &$results
    ): void {
        if (count($current) === $sliceSize) {
            $results[] = $current;
            return;
        }

        $need = $sliceSize - count($current);
        $limit = count($unused) - $need;
        for ($pos = $startPos; $pos <= $limit; $pos++) {
            $idx = $unused[$pos];
            if (!$this->roundSliceCompatible($current, $idx, $flatMatches)) {
                continue;
            }
            $current[] = $idx;
            $this->collectRoundSliceRecursive($flatMatches, $unused, $sliceSize, $pos + 1, $current, $results);
            array_pop($current);
        }
    }

    /**
     * @param array<int, int> $currentIndices
     */
    private function roundSliceCompatible(array $currentIndices, int $candidateIdx, array $flatMatches): bool
    {
        $players = $this->matchPlayerLookup($flatMatches[$candidateIdx]);
        foreach ($currentIndices as $i) {
            if (array_intersect_key($players, $this->matchPlayerLookup($flatMatches[$i]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, true>
     */
    private function matchPlayerLookup(array $match): array
    {
        $lookup = [];
        foreach ([$match[0][0], $match[0][1], $match[1][0], $match[1][1]] as $p) {
            $lookup[(int) $p] = true;
        }

        return $lookup;
    }

    /**
     * @param array<int, int> $slice Match indices, one per court in order.
     * @return bool true when pruned (max break exceeded).
     */
    private function applyRoundSlice(
        array $flatMatches,
        array $slice,
        array $mockPlayers,
        int $courts,
        array &$matchesByCourt,
        array &$used,
        array &$currentRuns,
        array &$longestRuns,
        array &$playedAtLeastOnce,
        array &$shortestInner,
        array &$lastCourt,
        array &$courtSwitches,
        int $maxBreakThreshold
    ): bool {
        $playersInRound = [];
        foreach ($slice as $courtIdx => $matchIdx) {
            foreach ($this->matchPlayerLookup($flatMatches[$matchIdx]) as $p => $_) {
                $playersInRound[$p] = $courtIdx;
            }
        }

        foreach ($mockPlayers as $playerIndex) {
            if (!isset($playersInRound[$playerIndex])) {
                $newRun = $currentRuns[$playerIndex] + 1;
                if ($newRun > $maxBreakThreshold) {
                    return true;
                }
            }
        }

        foreach ($slice as $courtIdx => $matchIdx) {
            $matchesByCourt[$courtIdx][] = $flatMatches[$matchIdx];
            $used[$matchIdx] = true;
        }

        foreach ($mockPlayers as $playerIndex) {
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
                    $candidate = $currentRuns[$playerIndex];
                    $prev = $shortestInner[$playerIndex];
                    if ($prev === null || $candidate < $prev) {
                        $shortestInner[$playerIndex] = $candidate;
                    }
                } else {
                    $playedAtLeastOnce[$playerIndex] = true;
                }
                $lastCourt[$playerIndex] = $court;
                $currentRuns[$playerIndex] = 0;
            } else {
                $newRun = $currentRuns[$playerIndex] + 1;
                $currentRuns[$playerIndex] = $newRun;
                if ($newRun > $longestRuns[$playerIndex]) {
                    $longestRuns[$playerIndex] = $newRun;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array<int, array<int, array<int, array<int, int>>>>
     */
    private function cloneMatchesByCourt(array $matchesByCourt): array
    {
        $clone = [];
        foreach ($matchesByCourt as $c => $rounds) {
            $clone[$c] = [];
            foreach ($rounds as $r => $match) {
                $clone[$c][$r] = $match;
            }
        }

        return $clone;
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     */
    private function snapshotRoundDfsState(
        array $matchesByCourt,
        array $used,
        array $currentRuns,
        array $longestRuns,
        array $playedAtLeastOnce,
        array $shortestInner,
        array $lastCourt,
        array $courtSwitches,
        int $courts
    ): array {
        $courtSnapshot = [];
        for ($c = 0; $c < $courts; $c++) {
            $courtSnapshot[$c] = $matchesByCourt[$c];
        }

        return [
            'matchesByCourt' => $courtSnapshot,
            'used' => $used,
            'currentRuns' => $currentRuns,
            'longestRuns' => $longestRuns,
            'playedAtLeastOnce' => $playedAtLeastOnce,
            'shortestInner' => $shortestInner,
            'lastCourt' => $lastCourt,
            'courtSwitches' => $courtSwitches,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function restoreRoundDfsState(
        array $snapshot,
        array &$matchesByCourt,
        array &$used,
        array &$currentRuns,
        array &$longestRuns,
        array &$playedAtLeastOnce,
        array &$shortestInner,
        array &$lastCourt,
        array &$courtSwitches,
        int $courts
    ): void {
        for ($c = 0; $c < $courts; $c++) {
            $matchesByCourt[$c] = $snapshot['matchesByCourt'][$c];
        }
        $used = $snapshot['used'];
        $currentRuns = $snapshot['currentRuns'];
        $longestRuns = $snapshot['longestRuns'];
        $playedAtLeastOnce = $snapshot['playedAtLeastOnce'];
        $shortestInner = $snapshot['shortestInner'];
        $lastCourt = $snapshot['lastCourt'];
        $courtSwitches = $snapshot['courtSwitches'];
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array{min: float, avg: float}
     */
    private function scoreMatchOrderDistribution(array $matchesByCourt, array $mockPlayers): array
    {
        $aggregate = $this->distributionScorer->scoreAll($mockPlayers, $matchesByCourt);

        return [
            'min' => $aggregate['min'],
            'avg' => $aggregate['avg'],
        ];
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array<int, array<int, array<int, array<int, int>>>>
     */
    private function adjustServingOrderByCourt(array $matchesByCourt, int $playerNumber): array
    {
        foreach ($matchesByCourt as $courtIdx => $courtMatches) {
            $matchesByCourt[$courtIdx] = $this->adjustServingOrder($courtMatches, $playerNumber);
        }

        return $matchesByCourt;
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array<int, array<int, array<int, array<int, int>>>>
     */
    private function repeatMatchesByCourt(array $matchesByCourt, int $repeatOpponents): array
    {
        $repeated = [];
        $courtCount = count($matchesByCourt);
        for ($c = 0; $c < $courtCount; $c++) {
            $repeated[$c] = [];
            for ($rep = 1; $rep <= $repeatOpponents; $rep++) {
                foreach ($matchesByCourt[$c] as $match) {
                    $repeated[$c][] = $match;
                }
            }
        }

        return $repeated;
    }
}
