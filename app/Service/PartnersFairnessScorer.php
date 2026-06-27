<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Per-player partner-pool fairness from complement deviation and choice-aware top-O ranking.
 */
final class PartnersFairnessScorer
{
    public const DISPLAY_GOOD = 0.90;
    public const DISPLAY_FAIR = 0.80;

    public static function complementDeviation(int $p, int $q, int $playersCount): int
    {
        return abs(($p + $q) - ($playersCount - 1));
    }

    public static function maxDeviation(int $p, int $playersCount): int
    {
        $max = 0;
        for ($q = 0; $q < $playersCount; $q++) {
            if ($q === $p) {
                continue;
            }
            $deviation = self::complementDeviation($p, $q, $playersCount);
            if ($deviation > $max) {
                $max = $deviation;
            }
        }

        return $max;
    }

    /**
     * Deviation of the O_p-th best complement candidate for player p (1-indexed rank).
     */
    public static function devCutoff(int $p, int $playersCount, int $O_p): int
    {
        if ($O_p <= 0) {
            return PHP_INT_MAX;
        }

        $deviations = [];
        for ($q = 0; $q < $playersCount; $q++) {
            if ($q === $p) {
                continue;
            }
            $deviations[] = ['dev' => self::complementDeviation($p, $q, $playersCount), 'q' => $q];
        }

        usort($deviations, static function (array $a, array $b): int {
            if ($a['dev'] !== $b['dev']) {
                return $a['dev'] <=> $b['dev'];
            }

            return $a['q'] <=> $b['q'];
        });

        $candidateCount = count($deviations);
        if ($O_p >= $candidateCount) {
            return $deviations[$candidateCount - 1]['dev'];
        }

        return $deviations[$O_p - 1]['dev'];
    }

    public static function edgePenalty(int $p, int $q, int $playersCount, int $O_p): float
    {
        if ($O_p <= 0) {
            return 0.0;
        }

        $deviation = self::complementDeviation($p, $q, $playersCount);
        $devCutoff = self::devCutoff($p, $playersCount, $O_p);
        if ($deviation <= $devCutoff) {
            return 0.0;
        }

        $devWorst = self::maxDeviation($p, $playersCount);
        if ($devWorst <= $devCutoff) {
            return 0.0;
        }

        return (($deviation - $devCutoff) / ($devWorst - $devCutoff)) / $O_p;
    }

    /**
     * @param list<int> $partnerIndices
     */
    public function scorePlayer(int $p, array $partnerIndices, int $playersCount, int $O_p): float
    {
        $penaltySum = 0.0;
        foreach ($partnerIndices as $q) {
            $penaltySum += self::edgePenalty($p, (int) $q, $playersCount, $O_p);
        }

        return max(0.0, min(1.0, 1.0 - $penaltySum));
    }

    /**
     * Optimistic upper bound on final min partners fairness for a partial pool.
     *
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, int>|null $targetPartnersCountByPlayer
     */
    public function minUpperPartial(
        array $pairs,
        int $playersCount,
        int $nominalPartnersPerPlayer,
        ?array $targetPartnersCountByPlayer = null
    ): float {
        if ($playersCount <= 0) {
            return 0.0;
        }

        $adjacency = $this->buildAdjacency($pairs, $playersCount);
        $minUpper = 1.0;
        for ($p = 0; $p < $playersCount; $p++) {
            $O_p = $this->resolvePartnersPerPlayer(
                $p,
                $adjacency[$p],
                $nominalPartnersPerPlayer,
                $targetPartnersCountByPlayer
            );
            $penaltySum = 0.0;
            foreach ($adjacency[$p] as $q) {
                $penaltySum += self::edgePenalty($p, $q, $playersCount, $O_p);
            }
            $partialUpper = max(0.0, min(1.0, 1.0 - $penaltySum));
            if ($partialUpper < $minUpper) {
                $minUpper = $partialUpper;
            }
        }

        return $minUpper;
    }

    /**
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, int>|null $targetPartnersCountByPlayer
     * @return array{min: float, avg: float}
     */
    public function scorePool(
        array $pairs,
        int $playersCount,
        int $nominalPartnersPerPlayer,
        ?array $targetPartnersCountByPlayer = null
    ): array {
        if ($pairs === [] || $playersCount <= 0) {
            return ['min' => 0.0, 'avg' => 0.0];
        }

        $adjacency = $this->buildAdjacency($pairs, $playersCount);
        $scores = [];
        for ($p = 0; $p < $playersCount; $p++) {
            $O_p = $this->resolvePartnersPerPlayer(
                $p,
                $adjacency[$p],
                $nominalPartnersPerPlayer,
                $targetPartnersCountByPlayer
            );
            $scores[] = $this->scorePlayer($p, $adjacency[$p], $playersCount, $O_p);
        }

        return [
            'min' => min($scores),
            'avg' => array_sum($scores) / count($scores),
        ];
    }

    /**
     * @param list<int> $partnerIndices
     * @param array<int, int>|null $targetPartnersCountByPlayer
     */
    private function resolvePartnersPerPlayer(
        int $p,
        array $partnerIndices,
        int $nominalPartnersPerPlayer,
        ?array $targetPartnersCountByPlayer
    ): int {
        if ($targetPartnersCountByPlayer !== null) {
            return $targetPartnersCountByPlayer[$p];
        }

        return count($partnerIndices);
    }

    /**
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @return array<int, list<int>>
     */
    private function buildAdjacency(array $pairs, int $playersCount): array
    {
        $adjacency = array_fill(0, $playersCount, []);
        foreach ($pairs as $pair) {
            [$a, $b] = $pair['players'];
            $adjacency[$a][] = $b;
            $adjacency[$b][] = $a;
        }

        return $adjacency;
    }
}
