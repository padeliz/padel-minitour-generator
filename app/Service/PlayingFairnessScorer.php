<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Per-player ladder-aware playing fairness from underdog team vs opponent strength.
 *
 * Player index 0 is the strongest on the ladder. Penalties apply only when a player's
 * team is weaker than the opponents (even or stronger teams are skipped).
 */
final class PlayingFairnessScorer
{
    public const DISPLAY_GOOD = 0.90;
    public const DISPLAY_FAIR = 0.80;
    public const DISPLAY_MAX_PENALTY_GOOD = 0.08;
    public const DISPLAY_MAX_PENALTY_FAIR = 0.12;

    /**
     * @param array{0: int, 1: int} $team
     */
    public static function teamStrength(array $team): float
    {
        return ((int) $team[0] + (int) $team[1]) / 2.0;
    }

    public static function matchGap(float $myStrength, float $oppStrength): float
    {
        return max(0.0, $myStrength - $oppStrength);
    }

    public static function matchPenalty(float $gap, int $playersCount, int $matchesForPlayer): float
    {
        if ($gap <= 0.0 || $playersCount <= 1 || $matchesForPlayer <= 0) {
            return 0.0;
        }

        return ($gap / (float) ($playersCount - 1)) / (float) $matchesForPlayer;
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @return array{min: float, avg: float, maxPenalty: float}
     */
    public function scoreTemplate(array $matchesByCourt, int $playersCount): array
    {
        if ($playersCount <= 0) {
            return ['min' => 0.0, 'avg' => 0.0, 'maxPenalty' => 0.0];
        }

        $matchCounts = array_fill(0, $playersCount, 0);
        foreach ($matchesByCourt as $courtRounds) {
            foreach ($courtRounds as $match) {
                foreach ([0, 1] as $teamIndex) {
                    foreach ($match[$teamIndex] as $player) {
                        $matchCounts[(int) $player]++;
                    }
                }
            }
        }

        $penaltySums = array_fill(0, $playersCount, 0.0);
        $maxPenalty = 0.0;

        foreach ($matchesByCourt as $courtRounds) {
            foreach ($courtRounds as $match) {
                $strength0 = self::teamStrength($match[0]);
                $strength1 = self::teamStrength($match[1]);

                if ($strength0 > $strength1) {
                    $this->applyUnderdogPenalties(
                        $match[0],
                        $strength0 - $strength1,
                        $playersCount,
                        $matchCounts,
                        $penaltySums,
                        $maxPenalty
                    );
                } elseif ($strength1 > $strength0) {
                    $this->applyUnderdogPenalties(
                        $match[1],
                        $strength1 - $strength0,
                        $playersCount,
                        $matchCounts,
                        $penaltySums,
                        $maxPenalty
                    );
                }
            }
        }

        $scores = [];
        for ($player = 0; $player < $playersCount; $player++) {
            $scores[] = max(0.0, min(1.0, 1.0 - $penaltySums[$player]));
        }

        return [
            'min' => min($scores),
            'avg' => array_sum($scores) / count($scores),
            'maxPenalty' => $maxPenalty,
        ];
    }

    /**
     * @param array{0: int, 1: int} $underdogTeam
     * @param array<int, int> $matchCounts
     * @param array<int, float> $penaltySums
     */
    private function applyUnderdogPenalties(
        array $underdogTeam,
        float $gap,
        int $playersCount,
        array $matchCounts,
        array &$penaltySums,
        float &$maxPenalty
    ): void {
        foreach ($underdogTeam as $player) {
            $playerIndex = (int) $player;
            $penalty = self::matchPenalty($gap, $playersCount, $matchCounts[$playerIndex]);
            $penaltySums[$playerIndex] += $penalty;
            if ($penalty > $maxPenalty) {
                $maxPenalty = $penalty;
            }
        }
    }
}
