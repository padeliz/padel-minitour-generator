<?php

/**
 * Partner-pool DFS helpers for TemplateMatchesGenerator.
 * Included from TemplateMatchesGenerator.php — not a standalone class.
 */

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\Service\Progress\ProgressReporter;

trait TemplateMatchesPairingPoolDfs
{
    /**
     * Builds a complete partner pool via multi-seed DFS with partners-fairness optimization.
     * When {@code players × partners} is odd, tries near-regular pools (variation ≤ 1).
     *
     * @return array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     partnersCountVariation: int,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     pairCount: int,
     *     stopReason: string,
     *     time: float,
     *     nodesExplored: int,
     *     seedIndex: int|null,
     *     seedsTotal: int
     * }
     */
    private function runPairingPhase(
        int $playersCount,
        int $partnersPerPlayer,
        ProgressReporter $reporter
    ): array {
        if ($playersCount < 2 || $partnersPerPlayer < 1) {
            return $this->emitEmptyPairingPhase($reporter);
        }

        $totalSlots = $playersCount * $partnersPerPlayer;
        if ($totalSlots % 2 !== 0) {
            return $this->runPairingPhaseIrregular($playersCount, $partnersPerPlayer, $reporter);
        }

        $initialTargets = array_fill(0, $playersCount, $partnersPerPlayer);

        return $this->runPairingPhaseWithInitialTargets(
            $playersCount,
            $partnersPerPlayer,
            $initialTargets,
            $reporter
        );
    }

    /**
     * Near-regular pairing when {@code N×O} is odd: try deficit k=1, then k=3 if no complete pool.
     *
     * @return array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     partnersCountVariation: int,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     pairCount: int,
     *     stopReason: string,
     *     time: float,
     *     nodesExplored: int,
     *     seedIndex: int|null,
     *     seedsTotal: int
     * }
     */
    private function runPairingPhaseIrregular(
        int $playersCount,
        int $partnersPerPlayer,
        ProgressReporter $reporter
    ): array {
        $pairingStartNs = $this->monotonicNow();
        $deadlineNs = $pairingStartNs + $this->effectivePairingBudgetNs;
        $reporter->setPhaseStart($pairingStartNs);

        $globalBest = $this->emptyPairingBestState();
        $nodesExplored = 0;
        $seedStopReasons = [];
        $seedsTotal = 1;
        $foundComplete = false;
        $savedPairingBudgetNs = $this->effectivePairingBudgetNs;

        foreach ([1, 3] as $deficitCount) {
            if ($this->monotonicNow() >= $deadlineNs) {
                break;
            }

            $expectedPairCount = intdiv($playersCount * $partnersPerPlayer - $deficitCount, 2);
            $deficitSets = $this->deficitPlayerSets($playersCount, $deficitCount);
            $setCount = count($deficitSets);

            foreach ($deficitSets as $deficitPlayers) {
                if ($this->monotonicNow() >= $deadlineNs) {
                    break 2;
                }

                $remainingNs = $deadlineNs - $this->monotonicNow();
                $this->effectivePairingBudgetNs = max(1, intdiv($remainingNs, $setCount));

                $initialTargets = array_fill(0, $playersCount, $partnersPerPlayer);
                foreach ($deficitPlayers as $playerIndex) {
                    $initialTargets[$playerIndex] = $partnersPerPlayer - 1;
                }

                $this->executePairingMultiSeed(
                    $playersCount,
                    $partnersPerPlayer,
                    $initialTargets,
                    $expectedPairCount,
                    $reporter,
                    $nodesExplored,
                    $globalBest,
                    $seedStopReasons,
                    $seedsTotal
                );

                if (count($globalBest['pairs']) === $expectedPairCount && $globalBest['pairs'] !== []) {
                    $foundComplete = true;
                    break 2;
                }
            }

            if ($foundComplete) {
                break;
            }
        }

        $this->effectivePairingBudgetNs = $savedPairingBudgetNs;

        $pairingStopReason = $this->aggregatePairingStopReason($seedStopReasons);
        $pairingTime = $this->nsToSeconds($this->monotonicNow() - $pairingStartNs);

        $partnersCount = $globalBest['partnersCount'];
        $partnersCountVariation = empty($partnersCount)
            ? 0
            : max($partnersCount) - min($partnersCount);
        $pairCount = count($globalBest['pairs']);

        $reporter->pairing(
            $nodesExplored,
            $globalBest['minPartnersFairness'],
            $globalBest['avgPartnersFairness'],
            $this->effectivePairingBudgetNs,
            $this->monotonicNow(),
            true,
            $globalBest['seedIndex'] ?? $seedsTotal,
            $seedsTotal,
            $partnersCount !== [] ? $partnersCount : null,
            $pairCount > 0 ? $partnersCountVariation : null,
            $pairCount > 0 ? $pairCount : null,
            $pairingStopReason
        );

        return [
            'pairs' => $globalBest['pairs'],
            'partnersCount' => $partnersCount,
            'partnersCountVariation' => $partnersCountVariation,
            'minPartnersFairness' => $globalBest['minPartnersFairness'],
            'avgPartnersFairness' => $globalBest['avgPartnersFairness'],
            'pairCount' => $pairCount,
            'stopReason' => $pairingStopReason,
            'time' => $pairingTime,
            'nodesExplored' => $nodesExplored,
            'seedIndex' => $globalBest['seedIndex'],
            'seedsTotal' => $seedsTotal,
        ];
    }

    /**
     * @param array<int, int> $initialTargets Per-player partner slot target (may differ for near-regular).
     * @return array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     partnersCountVariation: int,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     pairCount: int,
     *     stopReason: string,
     *     time: float,
     *     nodesExplored: int,
     *     seedIndex: int|null,
     *     seedsTotal: int
     * }
     */
    private function runPairingPhaseWithInitialTargets(
        int $playersCount,
        int $partnersPerPlayer,
        array $initialTargets,
        ProgressReporter $reporter
    ): array {
        $expectedPairCount = intdiv(array_sum($initialTargets), 2);
        $best = $this->emptyPairingBestState();
        $nodesExplored = 0;
        $seedStopReasons = [];
        $seedsTotal = 1;

        $pairingStartNs = $this->monotonicNow();
        $reporter->setPhaseStart($pairingStartNs);

        $this->executePairingMultiSeed(
            $playersCount,
            $partnersPerPlayer,
            $initialTargets,
            $expectedPairCount,
            $reporter,
            $nodesExplored,
            $best,
            $seedStopReasons,
            $seedsTotal
        );

        $pairingStopReason = $this->aggregatePairingStopReason($seedStopReasons);
        $pairingTime = $this->nsToSeconds($this->monotonicNow() - $pairingStartNs);

        $partnersCount = $best['partnersCount'];
        $partnersCountVariation = empty($partnersCount)
            ? 0
            : max($partnersCount) - min($partnersCount);
        $pairCount = count($best['pairs']);

        $reporter->pairing(
            $nodesExplored,
            $best['minPartnersFairness'],
            $best['avgPartnersFairness'],
            $this->effectivePairingBudgetNs,
            $this->monotonicNow(),
            true,
            $best['seedIndex'] ?? $seedsTotal,
            $seedsTotal,
            $partnersCount !== [] ? $partnersCount : null,
            $pairCount > 0 ? $partnersCountVariation : null,
            $pairCount > 0 ? $pairCount : null,
            $pairingStopReason
        );

        return [
            'pairs' => $best['pairs'],
            'partnersCount' => $partnersCount,
            'partnersCountVariation' => $partnersCountVariation,
            'minPartnersFairness' => $best['minPartnersFairness'],
            'avgPartnersFairness' => $best['avgPartnersFairness'],
            'pairCount' => $pairCount,
            'stopReason' => $pairingStopReason,
            'time' => $pairingTime,
            'nodesExplored' => $nodesExplored,
            'seedIndex' => $best['seedIndex'],
            'seedsTotal' => $seedsTotal,
        ];
    }

    /**
     * @param array<int, int> $initialTargets
     * @param array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     seedIndex: int|null
     * } $best Mutated by reference.
     * @param list<string> $seedStopReasons
     * @return array{pairCount: int}
     */
    private function executePairingMultiSeed(
        int $playersCount,
        int $partnersPerPlayer,
        array $initialTargets,
        int $expectedPairCount,
        ProgressReporter $reporter,
        int &$nodesExplored,
        array &$best,
        array &$seedStopReasons,
        int &$seedsTotal
    ): array {
        $useMultiSeed = ($expectedPairCount >= $this->multiSeedThresholdPairs && $this->multiSeedCountPairing > 1);
        $totalSeeds = $useMultiSeed ? $this->multiSeedCountPairing : 1;
        $seedsTotal = max($seedsTotal, $totalSeeds);
        $perSeedBudgetNs = intdiv($this->effectivePairingBudgetNs, $totalSeeds);
        $branchCap = self::computePairingDfsBranchCap($expectedPairCount);

        for ($seedIdx = 0; $seedIdx < $totalSeeds; $seedIdx++) {
            $candidateOrder = $useMultiSeed
                ? $this->lehmerSeedPermutation($seedIdx, $totalSeeds, $playersCount - 1)
                : range(0, $playersCount - 2);

            $seedStopReasons[] = $this->runPairingSeedDfs(
                $playersCount,
                $partnersPerPlayer,
                $initialTargets,
                $candidateOrder,
                $branchCap,
                $perSeedBudgetNs,
                $seedIdx + 1,
                $totalSeeds,
                $reporter,
                $nodesExplored,
                $best
            );
        }

        return ['pairCount' => count($best['pairs'])];
    }

    /**
     * @param array<int, int> $initialTargets
     * @param array<int, int> $candidateOrder Permutation of partner-candidate offsets for the active player.
     * @param array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     seedIndex: int|null
     * } $best Mutated by reference.
     */
    private function runPairingSeedDfs(
        int $playersCount,
        int $partnersPerPlayer,
        array $initialTargets,
        array $candidateOrder,
        int $branchCap,
        int $perSeedBudgetNs,
        int $currentSeed,
        int $totalSeeds,
        ProgressReporter $reporter,
        int &$nodesExplored,
        array &$best
    ): string {
        $deadlineNs = $this->monotonicNow() + $perSeedBudgetNs;
        if ($this->monotonicNow() >= $deadlineNs) {
            return self::STOP_REASON_DEADLINE;
        }

        $remaining = $initialTargets;
        $edges = [];
        $pairs = [];
        $branchesRemaining = $branchCap;

        $this->buildPairingByBacktracking(
            $playersCount,
            $partnersPerPlayer,
            $initialTargets,
            $remaining,
            $edges,
            $pairs,
            $candidateOrder,
            $deadlineNs,
            $branchesRemaining,
            $nodesExplored,
            $best,
            $currentSeed
        );

        $reporter->pairing(
            $nodesExplored,
            $best['minPartnersFairness'],
            $best['avgPartnersFairness'],
            $this->effectivePairingBudgetNs,
            $this->monotonicNow(),
            false,
            $currentSeed,
            $totalSeeds,
            $best['partnersCount'] !== [] ? $best['partnersCount'] : null,
            null,
            count($best['pairs']) > 0 ? count($best['pairs']) : null,
            null
        );

        if ($this->monotonicNow() >= $deadlineNs) {
            return self::STOP_REASON_DEADLINE;
        }

        return self::STOP_REASON_FACTORIAL_COMPLETE;
    }

    /**
     * @param array<int, int> $initialTargets
     * @param array<int, int> $remaining
     * @param array<string, true> $edges
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, int> $candidateOrder
     * @param array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     seedIndex: int|null
     * } $best
     */
    private function buildPairingByBacktracking(
        int $playersCount,
        int $partnersPerPlayer,
        array $initialTargets,
        array $remaining,
        array $edges,
        array $pairs,
        array $candidateOrder,
        int $deadlineNs,
        int $branchesRemaining,
        int &$nodesExplored,
        array &$best,
        int $currentSeed
    ): void {
        $this->pairingDfsExpand(
            $playersCount,
            $partnersPerPlayer,
            $initialTargets,
            $remaining,
            $edges,
            $pairs,
            $candidateOrder,
            $deadlineNs,
            $branchesRemaining,
            $nodesExplored,
            $best,
            $currentSeed
        );
    }

    /**
     * @param array<int, int> $initialTargets
     * @param array<int, int> $remaining
     * @param array<string, true> $edges
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, int> $candidateOrder
     * @param array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     seedIndex: int|null
     * } $best
     */
    private function pairingDfsExpand(
        int $playersCount,
        int $partnersPerPlayer,
        array $initialTargets,
        array &$remaining,
        array &$edges,
        array &$pairs,
        array $candidateOrder,
        int $deadlineNs,
        int &$branchesRemaining,
        int &$nodesExplored,
        array &$best,
        int $currentSeed
    ): void {
        if ($branchesRemaining <= 0 || $this->monotonicNow() >= $deadlineNs) {
            return;
        }
        $branchesRemaining--;
        $nodesExplored++;

        if ($this->pairingPartialMinUpperBelowBest(
            $pairs,
            $playersCount,
            $partnersPerPlayer,
            $initialTargets,
            $best['minPartnersFairness']
        )) {
            return;
        }

        $activePlayer = -1;
        for ($p = 0; $p < $playersCount; $p++) {
            if ($remaining[$p] > 0) {
                $activePlayer = $p;
                break;
            }
        }

        if ($activePlayer === -1) {
            $scores = $this->partnersFairnessScorer->scorePool($pairs, $playersCount, $partnersPerPlayer);
            if ($this->isPairingLexBetter(
                $scores['min'],
                $scores['avg'],
                $currentSeed,
                $best['minPartnersFairness'],
                $best['avgPartnersFairness'],
                $best['seedIndex']
            )) {
                $best['minPartnersFairness'] = $scores['min'];
                $best['avgPartnersFairness'] = $scores['avg'];
                $best['pairs'] = $pairs;
                $best['partnersCount'] = $this->partnersCountFromRemaining($playersCount, $initialTargets, $remaining);
                $best['seedIndex'] = $currentSeed;
            }

            return;
        }

        $candidates = [];
        for ($q = $activePlayer + 1; $q < $playersCount; $q++) {
            if ($remaining[$q] > 0 && !isset($edges[$this->pairingEdgeKey($activePlayer, $q)])) {
                $candidates[] = $q;
            }
        }

        if ($candidates === []) {
            return;
        }

        $orderedCandidates = $this->orderPairingCandidates(
            $candidates,
            $candidateOrder,
            $activePlayer,
            $playersCount,
            $initialTargets[$activePlayer]
        );

        foreach ($orderedCandidates as $q) {
            $edgeKey = $this->pairingEdgeKey($activePlayer, $q);
            $remaining[$activePlayer]--;
            $remaining[$q]--;
            $edges[$edgeKey] = true;
            $pairs[] = [
                'players' => [$activePlayer, $q],
                'used' => false,
            ];

            $this->pairingDfsExpand(
                $playersCount,
                $partnersPerPlayer,
                $initialTargets,
                $remaining,
                $edges,
                $pairs,
                $candidateOrder,
                $deadlineNs,
                $branchesRemaining,
                $nodesExplored,
                $best,
                $currentSeed
            );

            array_pop($pairs);
            unset($edges[$edgeKey]);
            $remaining[$q]++;
            $remaining[$activePlayer]++;

            if ($branchesRemaining <= 0 || $this->monotonicNow() >= $deadlineNs) {
                return;
            }
        }
    }

    /**
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     */
    /**
     * @param array<int, int> $initialTargets
     */
    private function pairingPartialMinUpperBelowBest(
        array $pairs,
        int $playersCount,
        int $partnersPerPlayer,
        array $initialTargets,
        ?float $bestMin
    ): bool {
        if ($bestMin === null || $pairs === []) {
            return false;
        }

        $minUpper = $this->partnersFairnessScorer->minUpperPartial(
            $pairs,
            $playersCount,
            $partnersPerPlayer,
            $initialTargets
        );

        return $minUpper < $bestMin;
    }

    private function isPairingLexBetter(
        float $min,
        float $avg,
        int $seedIndex,
        ?float $bestMin,
        ?float $bestAvg,
        ?int $bestSeedIndex
    ): bool {
        if ($bestMin === null) {
            return true;
        }
        if ($min > $bestMin) {
            return true;
        }
        if ($min < $bestMin) {
            return false;
        }
        if ($bestAvg === null || $avg > $bestAvg) {
            return true;
        }
        if ($avg < $bestAvg) {
            return false;
        }

        return $bestSeedIndex === null || $seedIndex < $bestSeedIndex;
    }

    /**
     * @param array<int, int> $candidates
     * @param array<int, int> $candidateOrder
     * @return array<int, int>
     */
    private function orderPairingCandidates(
        array $candidates,
        array $candidateOrder,
        int $activePlayer,
        int $playersCount,
        int $activePlayerTargetPartners
    ): array {
        if (count($candidates) <= 1) {
            return $candidates;
        }

        $penaltyCache = [];

        usort($candidates, function (int $a, int $b) use (
            $candidateOrder,
            $activePlayer,
            $playersCount,
            $activePlayerTargetPartners,
            &$penaltyCache
        ): int {
            $cacheKeyA = $activePlayer . ':' . $a;
            $cacheKeyB = $activePlayer . ':' . $b;
            $penaltyA = $penaltyCache[$cacheKeyA]
                ?? ($penaltyCache[$cacheKeyA] = PartnersFairnessScorer::edgePenalty(
                    $activePlayer,
                    $a,
                    $playersCount,
                    $activePlayerTargetPartners
                ));
            $penaltyB = $penaltyCache[$cacheKeyB]
                ?? ($penaltyCache[$cacheKeyB] = PartnersFairnessScorer::edgePenalty(
                    $activePlayer,
                    $b,
                    $playersCount,
                    $activePlayerTargetPartners
                ));

            if ($penaltyA !== $penaltyB) {
                return $penaltyA <=> $penaltyB;
            }

            $offsetA = $a - $activePlayer - 1;
            $offsetB = $b - $activePlayer - 1;
            $rankA = $this->pairingCandidateRank($offsetA, $candidateOrder);
            $rankB = $this->pairingCandidateRank($offsetB, $candidateOrder);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return $a <=> $b;
        });

        return $candidates;
    }

    private function pairingCandidateRank(int $offset, array $candidateOrder): int
    {
        if ($offset < 0) {
            return PHP_INT_MAX;
        }
        $pos = array_search($offset, $candidateOrder, true);

        return $pos === false ? PHP_INT_MAX : (int) $pos;
    }

    private function pairingEdgeKey(int $a, int $b): string
    {
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }

        return $a . ':' . $b;
    }

    /**
     * @param array<int, int> $initialTargets Target partner slots per player at search start.
     * @param array<int, int> $remaining Slots still to fill (zero when complete).
     * @return array<int, int>
     */
    private function partnersCountFromRemaining(int $playersCount, array $initialTargets, array $remaining): array
    {
        $partnersCount = [];
        for ($p = 0; $p < $playersCount; $p++) {
            $partnersCount[$p] = $initialTargets[$p] - $remaining[$p];
        }

        return $partnersCount;
    }

    /**
     * @return list<list<int>>
     */
    private function deficitPlayerSets(int $playersCount, int $deficitCount): array
    {
        if ($deficitCount === 0) {
            return [[]];
        }

        $result = [];
        $this->collectDeficitCombinations([], 0, $playersCount, $deficitCount, $result);

        return $result;
    }

    /**
     * @param list<int> $current
     * @param list<list<int>> $result
     */
    private function collectDeficitCombinations(array $current, int $start, int $playersCount, int $remaining, array &$result): void
    {
        if ($remaining === 0) {
            $result[] = $current;

            return;
        }

        for ($i = $start; $i <= $playersCount - $remaining; $i++) {
            $current[] = $i;
            $this->collectDeficitCombinations($current, $i + 1, $playersCount, $remaining - 1, $result);
            array_pop($current);
        }
    }

    /**
     * @return array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     partnersCountVariation: int,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     pairCount: int,
     *     stopReason: string,
     *     time: float,
     *     nodesExplored: int,
     *     seedIndex: int|null,
     *     seedsTotal: int
     * }
     */
    private function emitEmptyPairingPhase(ProgressReporter $reporter): array
    {
        $reporter->setPhaseStart($this->monotonicNow());
        $reporter->pairing(
            0,
            null,
            null,
            $this->effectivePairingBudgetNs,
            $this->monotonicNow(),
            true,
            1,
            1,
            null,
            null,
            0,
            self::STOP_REASON_FACTORIAL_COMPLETE
        );

        return [
            'pairs' => [],
            'partnersCount' => [],
            'partnersCountVariation' => 0,
            'minPartnersFairness' => null,
            'avgPartnersFairness' => null,
            'pairCount' => 0,
            'stopReason' => self::STOP_REASON_FACTORIAL_COMPLETE,
            'time' => 0.0,
            'nodesExplored' => 0,
            'seedIndex' => null,
            'seedsTotal' => 1,
        ];
    }

    /**
     * @return array{
     *     pairs: array<int, array{players: array{0:int,1:int}, used: bool}>,
     *     partnersCount: array<int, int>,
     *     minPartnersFairness: float|null,
     *     avgPartnersFairness: float|null,
     *     seedIndex: int|null
     * }
     */
    private function emptyPairingBestState(): array
    {
        return [
            'pairs' => [],
            'partnersCount' => [],
            'minPartnersFairness' => null,
            'avgPartnersFairness' => null,
            'seedIndex' => null,
        ];
    }
}
