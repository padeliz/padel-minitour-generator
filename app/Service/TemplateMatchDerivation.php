<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Derive pairing / match-making inputs from a completed per-court schedule.
 */
final class TemplateMatchDerivation
{
    /**
     * @param array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>> $matchesByCourt
     * @return array<int, array<int, int>>
     */
    public static function playersMetFromMatches(array $matchesByCourt): array
    {
        $playersMet = [];
        foreach ($matchesByCourt as $rounds) {
            foreach ($rounds as $match) {
                $playersMet = self::addPlayersMet($playersMet, $match);
            }
        }

        return $playersMet;
    }

    /**
     * Unique undirected partner pairs from the schedule (deduped across repeats).
     *
     * @param array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>> $matchesByCourt
     * @return array<int, array{players: array{0:int,1:int}, used: bool}>
     */
    public static function pairsFromMatches(array $matchesByCourt): array
    {
        $seen = [];
        $pairs = [];
        foreach ($matchesByCourt as $rounds) {
            foreach ($rounds as $match) {
                foreach ([$match[0], $match[1]] as $team) {
                    $a = (int) $team[0];
                    $b = (int) $team[1];
                    if ($a > $b) {
                        $tmp = $a;
                        $a = $b;
                        $b = $tmp;
                    }
                    $key = $a . '-' . $b;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $pairs[] = [
                        'players' => [$a, $b],
                        'used' => true,
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>> $matchesByCourt
     * @return array<int, int>
     */
    public static function partnersCountFromMatches(array $matchesByCourt, int $playersCount): array
    {
        $partners = array_fill(0, $playersCount, []);
        foreach (self::pairsFromMatches($matchesByCourt) as $pair) {
            [$a, $b] = $pair['players'];
            $partners[$a][$b] = true;
            $partners[$b][$a] = true;
        }

        $counts = [];
        for ($p = 0; $p < $playersCount; $p++) {
            $counts[$p] = count($partners[$p]);
        }

        return $counts;
    }

    /**
     * @param array<int, int> $partnersCount
     */
    public static function partnersCountVariation(array $partnersCount): int
    {
        if ($partnersCount === []) {
            return 0;
        }

        return max($partnersCount) - min($partnersCount);
    }

    /**
     * Total matches across all courts.
     *
     * @param array<int, array<int, mixed>>|null $matchesByCourt
     */
    public static function matchesCount(?array $matchesByCourt): ?int
    {
        if ($matchesByCourt === null) {
            return null;
        }

        $total = 0;
        foreach ($matchesByCourt as $courtRounds) {
            $total += count($courtRounds);
        }

        return $total;
    }

    /**
     * Simultaneous time slots (rounds on court 0). All courts share the same length.
     *
     * @param array<int, array<int, mixed>>|null $matchesByCourt
     */
    public static function roundsCount(?array $matchesByCourt): ?int
    {
        if ($matchesByCourt === null || $matchesByCourt === []) {
            return null;
        }

        $first = reset($matchesByCourt);
        if (!is_array($first)) {
            return null;
        }

        return count($first);
    }

    /**
     * @param array<int, array<int, int>> $playersMet
     * @param array{0: array{0:int,1:int}, 1: array{0:int,1:int}} $match
     * @return array<int, array<int, int>>
     */
    public static function addPlayersMet(array $playersMet, array $match): array
    {
        $matchPlayers = [
            $match[0][0],
            $match[0][1],
            $match[1][0],
            $match[1][1],
        ];

        foreach ($matchPlayers as $p1) {
            foreach ($matchPlayers as $p2) {
                if ($p1 !== $p2) {
                    if (!isset($playersMet[$p1])) {
                        $playersMet[$p1] = [];
                    }
                    if (!isset($playersMet[$p1][$p2])) {
                        $playersMet[$p1][$p2] = 0;
                    }
                    $playersMet[$p1][$p2]++;
                }
            }
        }

        return $playersMet;
    }
}
