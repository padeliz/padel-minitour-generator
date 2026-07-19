<?php

/**
 * Match-making DFS helpers for TemplateMatchesGenerator.
 * Included from TemplateMatchesGenerator.php — not a standalone class.
 */

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\Service\Progress\ProgressReporter;

trait TemplateMatchesMatchMakingDfs
{
    /**
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, int> $partnersCount
     * @return array{
     *     bestTemplate: array{
     *         meetingsVariation: float|null,
     *         matches: array<int, array<int, array<int, int>>>|null,
     *         playersMet: array<int, array<int, int>>,
     *         minOpponentsMet: int|null,
     *         minPlayingFairness: float|null,
     *         maxPlayingFairnessPenalty: float|null,
     *         avgPlayingFairness: float|null,
     *         permutationIndex: int|null,
     *         templateIndex: int|null,
     *         seedIndex: int|null
     *     },
     *     candidates: array<int, array<string, mixed>>,
     *     candidatesCollected: int,
     *     candidatesDeduped: int,
     *     processes: array{permutationsIterated: int, templatesGenerated: int},
     *     matchMakingStopReason: string,
     *     matchMakingTime: float,
     *     totalSeeds: int,
     *     nodesExplored: int
     * }
     */
    private function runMatchMakingPhase(
        array $pairs,
        int $n,
        array $partnersCount,
        int $partnersCountVariation,
        int $meetingsVariationLimit,
        ProgressReporter $reporter
    ): array {
        $useMultiSeed = ($n >= $this->multiSeedThresholdPairs && $this->multiSeedCountPairing > 1);
        $totalSeeds = $useMultiSeed ? $this->multiSeedCountPairing : 1;
        $perSeedBudgetNs = intdiv($this->effectiveMatchMakingBudgetNs, $totalSeeds);

        $processes = [
            'permutationsIterated' => 0,
            'templatesGenerated' => 0,
        ];

        $bestTemplate = $this->emptyMatchMakingBestTemplate();
        $bestTemplateSeedIdx = null;
        $totalNodesExplored = 0;
        $candidatePool = [];
        $candidatesCollected = 0;

        $matchMakingStartNs = $this->monotonicNow();
        $reporter->setPhaseStart($matchMakingStartNs);

        $playersCount = $this->inferPlayersCountFromPairs($pairs);
        $branchCap = self::computeMatchMakingDfsBranchCap($n);

        $seedStopReasons = [];
        for ($seedIdx = 0; $seedIdx < $totalSeeds; $seedIdx++) {
            $startPerm = $useMultiSeed
                ? $this->lehmerSeedPermutation($seedIdx, $totalSeeds, $n)
                : range(0, $n - 1);

            $seedStopReasons[] = $this->runMatchMakingSeedDfs(
                $pairs,
                $startPerm,
                $perSeedBudgetNs,
                $seedIdx,
                $seedIdx + 1,
                $totalSeeds,
                $reporter,
                $processes,
                $bestTemplate,
                $bestTemplateSeedIdx,
                $partnersCount,
                $partnersCountVariation,
                $meetingsVariationLimit,
                $playersCount,
                $branchCap,
                $totalNodesExplored,
                $candidatePool,
                $candidatesCollected
            );
        }

        $candidates = array_values($candidatePool);
        usort($candidates, static function (array $left, array $right): int {
            return -MatchMakingLex::compare($left, $right);
        });
        $candidatesDeduped = count($candidates);

        if ($candidates !== []) {
            $bestTemplate = $this->matchMakingCandidateToBestTemplate($candidates[0]);
        }

        $matchMakingStopReason = $this->aggregatePairingStopReason($seedStopReasons);
        $matchMakingEndNs = $this->monotonicNow();
        $matchMakingTime = $this->nsToSeconds($matchMakingEndNs - $matchMakingStartNs);

        $matchesCount = $bestTemplate['matches'] !== null ? count($bestTemplate['matches']) : null;
        $reporter->matchMaking(
            $processes['permutationsIterated'],
            $processes['templatesGenerated'],
            $bestTemplate['meetingsVariation'],
            $this->effectiveMatchMakingBudgetNs,
            $matchMakingEndNs,
            true,
            $totalSeeds,
            $totalSeeds,
            $bestTemplate['permutationIndex'],
            $bestTemplate['templateIndex'],
            $matchesCount,
            $partnersCount,
            $bestTemplate['playersMet'],
            $partnersCountVariation,
            $matchMakingStopReason,
            $meetingsVariationLimit,
            $totalNodesExplored
        );

        return [
            'bestTemplate' => $bestTemplate,
            'candidates' => $candidates,
            'candidatesCollected' => $candidatesCollected,
            'candidatesDeduped' => $candidatesDeduped,
            'processes' => $processes,
            'matchMakingStopReason' => $matchMakingStopReason,
            'matchMakingTime' => $matchMakingTime,
            'totalSeeds' => $totalSeeds,
            'nodesExplored' => $totalNodesExplored,
        ];
    }

    /**
     * @return array{
     *     meetingsVariation: float|null,
     *     matches: array<int, array<int, array<int, int>>>|null,
     *     playersMet: array<int, array<int, int>>,
     *     minOpponentsMet: int|null,
     *     minPlayingFairness: float|null,
     *     maxPlayingFairnessPenalty: float|null,
     *     avgPlayingFairness: float|null,
     *     permutationIndex: int|null,
     *     templateIndex: int|null,
     *     seedIndex: int|null
     * }
     */
    private function emptyMatchMakingBestTemplate(): array
    {
        return [
            'meetingsVariation' => null,
            'matches' => null,
            'playersMet' => [],
            'minOpponentsMet' => null,
            'minPlayingFairness' => null,
            'maxPlayingFairnessPenalty' => null,
            'avgPlayingFairness' => null,
            'permutationIndex' => null,
            'templateIndex' => null,
            'seedIndex' => null,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array{
     *     meetingsVariation: float|null,
     *     matches: array<int, array<int, array<int, int>>>|null,
     *     playersMet: array<int, array<int, int>>,
     *     minOpponentsMet: int|null,
     *     minPlayingFairness: float|null,
     *     maxPlayingFairnessPenalty: float|null,
     *     avgPlayingFairness: float|null,
     *     permutationIndex: int|null,
     *     templateIndex: int|null,
     *     seedIndex: int|null
     * }
     */
    private function matchMakingCandidateToBestTemplate(array $candidate): array
    {
        return [
            'meetingsVariation' => $candidate['meetingsVariation'] ?? null,
            'matches' => $candidate['matches'] ?? null,
            'playersMet' => $candidate['playersMet'] ?? [],
            'minOpponentsMet' => $candidate['minOpponentsMet'] ?? null,
            'minPlayingFairness' => $candidate['minPlayingFairness'] ?? null,
            'maxPlayingFairnessPenalty' => $candidate['maxPlayingFairnessPenalty'] ?? null,
            'avgPlayingFairness' => $candidate['avgPlayingFairness'] ?? null,
            'permutationIndex' => $candidate['permutationIndex'] ?? null,
            'templateIndex' => $candidate['templateIndex'] ?? null,
            'seedIndex' => $candidate['seedIndex'] ?? null,
        ];
    }

    /**
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, int> $startPerm
     * @param array{permutationsIterated:int,templatesGenerated:int} $processes
     * @param array<string, mixed> $bestTemplate
     * @param array<string, array<string, mixed>> $candidatePool
     */
    private function runMatchMakingSeedDfs(
        array $pairs,
        array $startPerm,
        int $perSeedBudgetNs,
        int $seedIdx,
        int $currentSeed,
        int $totalSeeds,
        ProgressReporter $reporter,
        array &$processes,
        array &$bestTemplate,
        ?int &$bestTemplateSeedIdx,
        array $partnersCount,
        int $partnersCountVariation,
        int $meetingsVariationLimit,
        int $playersCount,
        int $branchCap,
        int &$totalNodesExplored,
        array &$candidatePool,
        int &$candidatesCollected
    ): string {
        $deadlineNs = $this->monotonicNow() + $perSeedBudgetNs;

        if ($this->monotonicNow() >= $deadlineNs) {
            return self::STOP_REASON_DEADLINE;
        }

        $processes['permutationsIterated']++;

        $matchesCount = $bestTemplate['matches'] !== null ? count($bestTemplate['matches']) : null;
        $reporter->matchMaking(
            $processes['permutationsIterated'],
            $processes['templatesGenerated'],
            $bestTemplate['meetingsVariation'],
            $this->effectiveMatchMakingBudgetNs,
            $this->monotonicNow(),
            false,
            $currentSeed,
            $totalSeeds,
            $bestTemplate['permutationIndex'],
            $bestTemplate['templateIndex'],
            $matchesCount,
            $partnersCount,
            $bestTemplate['playersMet'],
            $partnersCountVariation,
            null,
            $meetingsVariationLimit,
            $totalNodesExplored
        );

        $orderedPairs = [];
        foreach ($startPerm as $i) {
            $orderedPairs[] = [
                'players' => $pairs[$i]['players'],
                'used' => false,
            ];
        }

        $result = $this->buildMatchMakingByBacktracking(
            $orderedPairs,
            $deadlineNs,
            $meetingsVariationLimit,
            $branchCap,
            $playersCount,
            $bestTemplate['minOpponentsMet'],
            $processes['permutationsIterated'],
            $seedIdx
        );

        if ($result !== null) {
            $totalNodesExplored += $result['nodesExplored'];
            $candidatesCollected += $result['leavesCollected'];
            foreach ($result['leaves'] as $leaf) {
                $processes['templatesGenerated']++;
                $leaf['templateIndex'] = $processes['templatesGenerated'];
                $leaf['permutationIndex'] = $processes['permutationsIterated'];
                $leaf['seedIndex'] = $seedIdx;

                $key = MatchMakingLex::canonicalMatchMultisetKey($leaf['matches']);
                if (
                    !isset($candidatePool[$key])
                    || MatchMakingLex::compare($leaf, $candidatePool[$key]) > 0
                ) {
                    $candidatePool[$key] = $leaf;
                }

                if (MatchMakingLex::isSeedResultBetter(
                    $leaf,
                    $bestTemplate['matches'] !== null ? $bestTemplate : null,
                    $seedIdx,
                    $bestTemplateSeedIdx
                )) {
                    $bestTemplate = $this->matchMakingCandidateToBestTemplate($leaf);
                    $bestTemplateSeedIdx = $seedIdx;
                }
            }
            $this->trimMatchMakingCandidatePool($candidatePool);
        }

        if ($this->monotonicNow() >= $deadlineNs) {
            return self::STOP_REASON_DEADLINE;
        }

        return self::STOP_REASON_FACTORIAL_COMPLETE;
    }

    /**
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $orderedPairs
     * @return array{leaves: array<int, array<string, mixed>>, leavesCollected: int, nodesExplored: int}|null
     */
    private function buildMatchMakingByBacktracking(
        array $orderedPairs,
        int $deadlineNs,
        int $meetingsVariationLimit,
        int $branchCap,
        int $playersCount,
        ?int $globalPruneMinOpponentsMet,
        int $permutationIndex,
        int $seedIndex
    ): ?array {
        $pairCount = count($orderedPairs);
        $used = array_fill(0, $pairCount, false);
        $playersMet = [];
        $matches = [];
        $branchesRemaining = $branchCap;
        $seedBest = null;
        $seedLeavesByKey = [];
        $leavesCollected = 0;
        $nodesExplored = 0;

        $this->matchMakingDfsExpand(
            $orderedPairs,
            $playersCount,
            $used,
            $playersMet,
            $matches,
            $deadlineNs,
            $meetingsVariationLimit,
            $branchesRemaining,
            $globalPruneMinOpponentsMet,
            $seedBest,
            $seedLeavesByKey,
            $leavesCollected,
            $nodesExplored
        );

        if ($seedLeavesByKey === []) {
            return null;
        }

        return [
            'leaves' => array_values($seedLeavesByKey),
            'leavesCollected' => $leavesCollected,
            'nodesExplored' => $nodesExplored,
        ];
    }

    /**
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, bool> $used
     * @param array<int, array<int, int>> $playersMet
     * @param array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}> $matches
     * @param array<string, mixed>|null $seedBest
     * @param array<string, array<string, mixed>> $seedLeavesByKey
     */
    private function matchMakingDfsExpand(
        array $pairs,
        int $playersCount,
        array &$used,
        array &$playersMet,
        array &$matches,
        int $deadlineNs,
        int $meetingsVariationLimit,
        int &$branchesRemaining,
        ?int $globalPruneMinOpponentsMet,
        ?array &$seedBest,
        array &$seedLeavesByKey,
        int &$leavesCollected,
        int &$nodesExplored
    ): void {
        if ($branchesRemaining <= 0) {
            return;
        }
        if ($this->monotonicNow() >= $deadlineNs) {
            return;
        }
        $branchesRemaining--;

        $pruneMinOpponentsMet = $this->resolveMatchMakingPruneMinOpponentsMet(
            $globalPruneMinOpponentsMet,
            $seedBest
        );
        if ($pruneMinOpponentsMet !== null && $pruneMinOpponentsMet > 0) {
            $remainingForP = array_fill(0, $playersCount, 0);
            $pairCount = count($pairs);
            for ($i = 0; $i < $pairCount; $i++) {
                if ($used[$i]) {
                    continue;
                }
                $remainingForP[$pairs[$i]['players'][0]]++;
                $remainingForP[$pairs[$i]['players'][1]]++;
            }
            $maxDistinct = $playersCount - 1;
            for ($p = 0; $p < $playersCount; $p++) {
                $current = isset($playersMet[$p]) ? count($playersMet[$p]) : 0;
                $upperBound = $current + 3 * $remainingForP[$p];
                if ($upperBound > $maxDistinct) {
                    $upperBound = $maxDistinct;
                }
                if ($upperBound < $pruneMinOpponentsMet) {
                    return;
                }
            }
        }

        $pair1Idx = -1;
        $pairCount = count($pairs);
        for ($i = 0; $i < $pairCount; $i++) {
            if (!$used[$i]) {
                $pair1Idx = $i;
                break;
            }
        }

        if ($pair1Idx === -1) {
            $nodesExplored++;
            $leafKey = MatchMakingLex::canonicalMatchMultisetKey($matches);
            $leavesCollected++;
            if (!isset($seedLeavesByKey[$leafKey])) {
                $candidate = MatchMakingLex::scoreLeaf(
                    $matches,
                    $playersMet,
                    $playersCount,
                    $this->playingFairnessScorer
                );
                $seedLeavesByKey[$leafKey] = $candidate;
                if (MatchMakingLex::compare($candidate, $seedBest) > 0) {
                    $seedBest = $candidate;
                }
            }

            return;
        }

        $pair1Players = $pairs[$pair1Idx]['players'];
        $used[$pair1Idx] = true;

        for ($j = $pair1Idx + 1; $j < $pairCount; $j++) {
            if ($used[$j]) {
                continue;
            }
            $pair2Players = $pairs[$j]['players'];
            if (array_intersect($pair1Players, $pair2Players)) {
                continue;
            }
            if ($this->playersMetTooMuch($pair1Players, $pair2Players, $playersMet, $meetingsVariationLimit)) {
                continue;
            }

            $playersMetSnapshot = $playersMet;
            $playersMet = $this->addPlayersMet($playersMet, [$pair1Players, $pair2Players]);
            $matches[] = [$pair1Players, $pair2Players];
            $used[$j] = true;

            $this->matchMakingDfsExpand(
                $pairs,
                $playersCount,
                $used,
                $playersMet,
                $matches,
                $deadlineNs,
                $meetingsVariationLimit,
                $branchesRemaining,
                $globalPruneMinOpponentsMet,
                $seedBest,
                $seedLeavesByKey,
                $leavesCollected,
                $nodesExplored
            );

            $used[$j] = false;
            array_pop($matches);
            $playersMet = $playersMetSnapshot;

            if ($branchesRemaining <= 0) {
                $used[$pair1Idx] = false;
                return;
            }
            if ($this->monotonicNow() >= $deadlineNs) {
                $used[$pair1Idx] = false;
                return;
            }
        }

        $used[$pair1Idx] = false;
    }

    /**
     * @param array{minOpponentsMet?: int}|null $seedBest
     */
    private function resolveMatchMakingPruneMinOpponentsMet(?int $globalMin, ?array $seedBest): ?int
    {
        $seedMin = $seedBest['minOpponentsMet'] ?? null;
        if ($globalMin === null && $seedMin === null) {
            return null;
        }
        if ($globalMin === null) {
            return $seedMin;
        }
        if ($seedMin === null) {
            return $globalMin;
        }

        return max($globalMin, $seedMin);
    }

    /**
     * @param array<string, array<string, mixed>> $candidatePool
     */
    private function trimMatchMakingCandidatePool(array &$candidatePool): void
    {
        if (count($candidatePool) <= self::MAX_MM_CANDIDATE_POOL) {
            return;
        }

        $candidates = array_values($candidatePool);
        usort($candidates, static function (array $left, array $right): int {
            return -MatchMakingLex::compare($left, $right);
        });

        $candidatePool = [];
        foreach (array_slice($candidates, 0, self::MAX_MM_CANDIDATE_POOL) as $candidate) {
            if (!isset($candidate['matches']) || !is_array($candidate['matches'])) {
                continue;
            }
            $key = MatchMakingLex::canonicalMatchMultisetKey($candidate['matches']);
            $candidatePool[$key] = $candidate;
        }
    }
}
