<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Lexicographic scoring and comparison for match-making DFS complete leaves.
 *
 * Tiers 1–5 apply within and across seeds; tier 6 (lower seed index) applies only cross-seed.
 */
final class MatchMakingLex
{
    /**
     * @param array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}> $matches
     * @param array<int, array<int, int>> $playersMet
     * @return array{
     *     matches: array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>,
     *     playersMet: array<int, array<int, int>>,
     *     minOpponentsMet: int,
     *     meetingsVariation: float,
     *     minPlayingFairness: float,
     *     maxPlayingFairnessPenalty: float,
     *     avgPlayingFairness: float
     * }
     */
    public static function scoreLeaf(
        array $matches,
        array $playersMet,
        int $playersCount,
        PlayingFairnessScorer $playingFairnessScorer
    ): array {
        $opponentsBounds = OpponentsMetSummary::fromPlayersMet($playersMet, $playersCount);
        $playingFairness = $playingFairnessScorer->scoreTemplate([0 => $matches], $playersCount);

        return [
            'matches' => self::cloneMatches($matches),
            'playersMet' => self::clonePlayersMet($playersMet),
            'minOpponentsMet' => (int) ($opponentsBounds['min'] ?? 0),
            'meetingsVariation' => self::meetingsVariation($playersMet),
            'minPlayingFairness' => $playingFairness['min'],
            'maxPlayingFairnessPenalty' => $playingFairness['maxPenalty'],
            'avgPlayingFairness' => $playingFairness['avg'],
        ];
    }

    /**
     * @param array{
     *     minOpponentsMet?: int,
     *     meetingsVariation?: float,
     *     minPlayingFairness?: float,
     *     maxPlayingFairnessPenalty?: float,
     *     avgPlayingFairness?: float
     * } $candidate
     * @param array{
     *     minOpponentsMet?: int,
     *     meetingsVariation?: float,
     *     minPlayingFairness?: float,
     *     maxPlayingFairnessPenalty?: float,
     *     avgPlayingFairness?: float,
     *     matches?: array|null
     * }|null $incumbent
     * @return int positive when candidate wins, negative when incumbent wins, zero on full tie
     */
    public static function compare(array $candidate, ?array $incumbent): int
    {
        if ($incumbent === null || ($incumbent['matches'] ?? null) === null) {
            return 1;
        }

        $cMin = $candidate['minOpponentsMet'] ?? 0;
        $iMin = $incumbent['minOpponentsMet'] ?? 0;
        if ($cMin > $iMin) {
            return 1;
        }
        if ($cMin < $iMin) {
            return -1;
        }

        $cVar = $candidate['meetingsVariation'] ?? 0.0;
        $iVar = $incumbent['meetingsVariation'] ?? 0.0;
        if (LexFloat::isBetterMin($cVar, $iVar)) {
            return 1;
        }
        if (LexFloat::isBetterMin($iVar, $cVar)) {
            return -1;
        }

        $cMinPf = $candidate['minPlayingFairness'] ?? 0.0;
        $iMinPf = $incumbent['minPlayingFairness'] ?? 0.0;
        if (LexFloat::isBetterMax($cMinPf, $iMinPf)) {
            return 1;
        }
        if (LexFloat::isBetterMax($iMinPf, $cMinPf)) {
            return -1;
        }

        $cMaxPen = $candidate['maxPlayingFairnessPenalty'] ?? 0.0;
        $iMaxPen = $incumbent['maxPlayingFairnessPenalty'] ?? 0.0;
        if (LexFloat::isBetterMin($cMaxPen, $iMaxPen)) {
            return 1;
        }
        if (LexFloat::isBetterMin($iMaxPen, $cMaxPen)) {
            return -1;
        }

        $cAvgPf = $candidate['avgPlayingFairness'] ?? 0.0;
        $iAvgPf = $incumbent['avgPlayingFairness'] ?? 0.0;
        if (LexFloat::isBetterMax($cAvgPf, $iAvgPf)) {
            return 1;
        }
        if (LexFloat::isBetterMax($iAvgPf, $cAvgPf)) {
            return -1;
        }

        return 0;
    }

    /**
     * @param array{matches?: array|null, ...} $candidate
     * @param array{matches?: array|null, ...}|null $incumbent
     */
    public static function isSeedResultBetter(
        array $candidate,
        ?array $incumbent,
        int $candidateSeedIdx,
        ?int $incumbentSeedIdx
    ): bool {
        if (($candidate['matches'] ?? null) === null) {
            return false;
        }
        if ($incumbent === null || ($incumbent['matches'] ?? null) === null) {
            return true;
        }

        $compare = self::compare($candidate, $incumbent);
        if ($compare > 0) {
            return true;
        }
        if ($compare < 0) {
            return false;
        }

        return $incumbentSeedIdx === null || $candidateSeedIdx < $incumbentSeedIdx;
    }

    /**
     * @param array<int, array<int, int>> $playersMet
     */
    public static function meetingsVariation(array $playersMet): float
    {
        if ($playersMet === []) {
            return 0.0;
        }

        $variations = array_map(static function (array $met) {
            return max($met) - min($met);
        }, $playersMet);

        return (float) array_sum($variations) / count($variations);
    }

    /**
     * @param array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}> $matches
     * @return array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>
     */
    public static function cloneMatches(array $matches): array
    {
        $clone = [];
        foreach ($matches as $match) {
            $clone[] = [
                [$match[0][0], $match[0][1]],
                [$match[1][0], $match[1][1]],
            ];
        }

        return $clone;
    }

    /**
     * @param array<int, array<int, int>> $playersMet
     * @return array<int, array<int, int>>
     */
    public static function clonePlayersMet(array $playersMet): array
    {
        $clone = [];
        foreach ($playersMet as $player => $met) {
            $clone[(int) $player] = $met;
        }

        return $clone;
    }

    /**
     * Canonical multiset key for deduplicating MM complete leaves (unordered matches, sorted pairs).
     *
     * @param array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}> $matches
     */
    public static function canonicalMatchMultisetKey(array $matches): string
    {
        $matchKeys = [];
        foreach ($matches as $match) {
            $pairA = [$match[0][0], $match[0][1]];
            $pairB = [$match[1][0], $match[1][1]];
            sort($pairA);
            sort($pairB);
            $pairs = [$pairA, $pairB];
            usort($pairs, static function (array $left, array $right): int {
                return $left[0] <=> $right[0] ?: $left[1] <=> $right[1];
            });
            $matchKeys[] = json_encode($pairs, JSON_THROW_ON_ERROR);
        }
        sort($matchKeys);

        return implode('|', $matchKeys);
    }

    /**
     * Dedupe by canonical multiset (keep better MM-lex representative), sort MM-lex descending.
     *
     * @param array<int, array<string, mixed>> $leaves
     * @return array<int, array<string, mixed>>
     */
    public static function dedupeAndSortCandidates(array $leaves): array
    {
        $byKey = [];
        foreach ($leaves as $leaf) {
            if (!isset($leaf['matches']) || !is_array($leaf['matches'])) {
                continue;
            }
            $key = self::canonicalMatchMultisetKey($leaf['matches']);
            if (!isset($byKey[$key]) || self::compare($leaf, $byKey[$key]) > 0) {
                $byKey[$key] = $leaf;
            }
        }

        $candidates = array_values($byKey);
        usort($candidates, static function (array $left, array $right): int {
            return -self::compare($left, $right);
        });

        return $candidates;
    }
}
