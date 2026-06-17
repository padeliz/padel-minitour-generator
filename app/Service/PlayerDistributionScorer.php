<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Stand-alone scorer for per-player match-distribution metrics on per-court schedules.
 */
class PlayerDistributionScorer
{
    public const DISPLAY_EXCELLENT = 0.90;
    public const DISPLAY_GOOD      = 0.80;
    public const DISPLAY_FAIR      = 0.70;

    /**
     * @param int $playerIndex
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     */
    public function score(int $playerIndex, array $matchesByCourt): float
    {
        $playerRounds = $this->playerRoundIndices($playerIndex, $matchesByCourt);

        if (count($playerRounds) <= 1) {
            return 1.0;
        }

        $roundsTotal = $this->roundsTotal($matchesByCourt);
        if ($roundsTotal <= 0) {
            return 1.0;
        }

        $matchCount = count($playerRounds);
        $idealCeil = (int) ceil($roundsTotal / $matchCount);
        $neutralLow = $idealCeil - 1;
        $neutralHigh = $idealCeil;

        $gaps = [];
        for ($i = 1; $i < $matchCount; $i++) {
            $gaps[] = ['size' => $playerRounds[$i] - $playerRounds[$i - 1] - 1, 'isEdge' => false];
        }

        $firstRound = $playerRounds[0];
        $lastRound = $playerRounds[$matchCount - 1];

        if ($firstRound > 0) {
            array_unshift($gaps, ['size' => $firstRound, 'isEdge' => true]);
        }
        if ($lastRound < $roundsTotal - 1) {
            $gaps[] = ['size' => $roundsTotal - 1 - $lastRound, 'isEdge' => true];
        }

        if ($gaps === []) {
            return 1.0;
        }

        $totalPenalty = 0.0;
        foreach ($gaps as $g) {
            $size = $g['size'];
            $isEdge = $g['isEdge'];

            if ($size === $neutralLow) {
                $penalty = 0.0;
            } elseif ($size === $neutralHigh) {
                $penalty = $isEdge ? 0.1 : 0.0;
            } elseif ($size < $neutralLow) {
                $penalty = $isEdge ? 0.0 : 0.1 * ($neutralLow - $size);
            } else {
                $penalty = 0.4 * ($size - $neutralHigh);
            }

            $totalPenalty += $penalty;
        }

        $avgPenalty = $totalPenalty / count($gaps);

        return max(0.0, 1.0 - min(1.0, $avgPenalty));
    }

    /**
     * @param array<int, int> $playerIds
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array{perPlayer: array<int, float>, min: float, avg: float}
     */
    public function scoreAll(array $playerIds, array $matchesByCourt): array
    {
        $perPlayer = [];
        $sum = 0.0;
        $min = INF;

        foreach ($playerIds as $playerId) {
            $score = $this->score((int) $playerId, $matchesByCourt);
            $perPlayer[(int) $playerId] = $score;
            $sum += $score;
            if ($score < $min) {
                $min = $score;
            }
        }

        $count = count($playerIds);

        return [
            'perPlayer' => $perPlayer,
            'min' => $min === INF ? 0.0 : (float) $min,
            'avg' => $count > 0 ? $sum / $count : 0.0,
        ];
    }

    /**
     * @return array{percentage: int, cssClass: string}
     */
    public function classify(float $score): array
    {
        $percentage = (int) round($score * 100);

        if ($score >= self::DISPLAY_EXCELLENT) {
            $cssClass = 'excellent';
        } elseif ($score >= self::DISPLAY_GOOD) {
            $cssClass = 'good';
        } elseif ($score >= self::DISPLAY_FAIR) {
            $cssClass = 'fair';
        } else {
            $cssClass = 'poor';
        }

        return ['percentage' => $percentage, 'cssClass' => $cssClass];
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array<int, int>
     */
    private function playerRoundIndices(int $playerIndex, array $matchesByCourt): array
    {
        $rounds = [];
        $roundsTotal = $this->roundsTotal($matchesByCourt);
        for ($r = 0; $r < $roundsTotal; $r++) {
            foreach ($matchesByCourt as $courtMatches) {
                if (!isset($courtMatches[$r])) {
                    continue;
                }
                $match = $courtMatches[$r];
                if (
                    ($match[0][0] ?? null) === $playerIndex || ($match[0][1] ?? null) === $playerIndex ||
                    ($match[1][0] ?? null) === $playerIndex || ($match[1][1] ?? null) === $playerIndex
                ) {
                    $rounds[] = $r;
                    break;
                }
            }
        }

        return $rounds;
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     */
    private function roundsTotal(array $matchesByCourt): int
    {
        if ($matchesByCourt === []) {
            return 0;
        }

        return count($matchesByCourt[0] ?? []);
    }
}
