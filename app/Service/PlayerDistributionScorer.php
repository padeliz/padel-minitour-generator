<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Stand-alone, single-source-of-truth scorer for the per-player match-distribution metric.
 *
 * Used both by the sort-phase DFS in {@see TemplateMatchesGenerator} (to rank candidate
 * orderings) and by the matches page's AJAX endpoint (to render the per-player percentages
 * on every reorder). Holding the algorithm in one place guarantees that the CLI table
 * (`Min Dist.` / `Avg Dist.` columns) and the web UI (per-player distribution-index cells)
 * always agree on what "distribution" means.
 *
 * The score is in `[0, 1]`: 1.0 is perfect spacing (every gap inside the neutral band),
 * 0.0 means the accumulated penalty saturates the clamp.
 */
class PlayerDistributionScorer
{
    /**
     * Per-player display thresholds, used by both the matches-page UI (4 bands including
     * `excellent`) and the CLI table (3 bands -- the CLI ignores `DISPLAY_EXCELLENT` and
     * paints anything `>= DISPLAY_GOOD` green). Presentation-only constants -- they do NOT
     * participate in search, pruning, or any algorithmic decision.
     *
     * Calibrated against the asymmetric penalty formula's observed output range. `>= EXCELLENT`
     * means essentially every gap landed inside the neutral band; `>= GOOD` means a few small
     * deviations; `>= FAIR` means several deviations or one harsh long-wait gap; below `FAIR`
     * means the player's spacing is significantly imbalanced.
     */
    public const DISPLAY_EXCELLENT = 0.90;
    public const DISPLAY_GOOD      = 0.80;
    public const DISPLAY_FAIR      = 0.70;

    /**
     * Returns a score in [0, 1] measuring how well the player's matches are spread across the
     * schedule. 1.0 means the spacing falls entirely within the neutral band; 0.0 means the
     * accumulated penalty saturates the [0, 1] clamp.
     *
     * The score is a piecewise asymmetric penalty over the player's gap list:
     *
     * Definitions
     * - Each gap is the **number of matches the player sits out** at that position -- so two
     *   consecutive player matches yield a gap of `0`, the same convention the `sortDfsExpand`
     *   break tracker uses. This keeps inter-match, lead, and trail gaps consistent with each
     *   other (all of them count sit-outs, never positions or steps).
     * - `idealCeil = ceil(totalMatches / playerMatchCount)` — the upper bound of the ideal gap.
     * - Neutral band = `{idealCeil - 1, idealCeil}`. Gaps in this band incur zero penalty (with
     *   one edge-gap exception below).
     * - Gap list = inter-match gaps (sit-outs between consecutive player appearances; `0` when
     *   the appearances are back-to-back) plus a lead gap (sit-outs before the first appearance)
     *   if non-zero and a trail gap (sit-outs after the last appearance) if non-zero. Lead and
     *   trail gaps are marked as "edge" gaps; they get softer treatment at the short end and a
     *   small `0.1` penalty when sitting exactly at `idealCeil`.
     *
     * Penalty function (per gap of size `g`)
     * - `g == idealCeil - 1`            → `0.0` (always neutral)
     * - `g == idealCeil` (inter-match)  → `0.0` (neutral)
     * - `g == idealCeil` (edge)         → `0.1` (small penalty: an edge gap exactly at the
     *                                    ceiling reads as the schedule almost starting / ending
     *                                    without the player, which is mildly suboptimal)
     * - `g < idealCeil - 1` (inter)     → `0.1 * (idealCeil - 1 - g)` (gentle ascending: playing
     *                                    twice in close succession is mildly suboptimal)
     * - `g < idealCeil - 1` (edge)      → `0.0` (short lead/trail gaps are free: starting or
     *                                    ending the schedule for the player is the natural state)
     * - `g > idealCeil` (any)           → `0.4 * (g - idealCeil)` (harsh ascending: a long wait
     *                                    is dramatically worse than equivalent close-succession)
     *
     * The `0.4 / 0.1 = 4:1` ratio embeds the asymmetry between "long wait" and "close
     * succession" that matches user-perceived schedule quality. The final score is
     * `1 - clamp(avg(penalties), 0, 1)`.
     *
     * MatchCount weighting falls out implicitly: a player with fewer matches has fewer gaps, so
     * each gap's contribution to the average is proportionally larger — fewer-match players
     * therefore feel each anomaly more, which matches the intuition that "a long wait hurts more
     * when you only have 2 matches to play".
     *
     * @param int                                     $playerIndex Player ID to score.
     * @param array<int, array<int, array<int, int>>> $matches     Ordered match list; each entry
     *                                                             is `[[p1, p2], [p3, p4], ...]`.
     */
    public function score(int $playerIndex, array $matches): float
    {
        $playerMatches = [];
        foreach ($matches as $i => $match) {
            if (
                ($match[0][0] ?? null) === $playerIndex || ($match[0][1] ?? null) === $playerIndex ||
                ($match[1][0] ?? null) === $playerIndex || ($match[1][1] ?? null) === $playerIndex
            ) {
                $playerMatches[] = (int) $i;
            }
        }

        if (count($playerMatches) <= 1) {
            return 1.0;
        }

        $totalMatches = count($matches);
        if ($totalMatches <= 0) {
            return 1.0;
        }

        $matchCount = count($playerMatches);
        $idealCeil = (int) ceil($totalMatches / $matchCount);
        $neutralLow = $idealCeil - 1;
        $neutralHigh = $idealCeil;

        $gaps = [];
        for ($i = 1; $i < $matchCount; $i++) {
            // Sit-out count between two adjacent appearances: index difference minus 1, so
            // back-to-back appearances yield 0 (consistent with the lead/trail formulae below
            // and with the `sortDfsExpand` break tracker).
            $gaps[] = ['size' => $playerMatches[$i] - $playerMatches[$i - 1] - 1, 'isEdge' => false];
        }

        $firstMatch = $playerMatches[0];
        $lastMatch = $playerMatches[$matchCount - 1];

        if ($firstMatch > 0) {
            array_unshift($gaps, ['size' => $firstMatch, 'isEdge' => true]);
        }
        if ($lastMatch < $totalMatches - 1) {
            $gaps[] = ['size' => $totalMatches - 1 - $lastMatch, 'isEdge' => true];
        }

        if (empty($gaps)) {
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
            } else { // $size > $neutralHigh
                $penalty = 0.4 * ($size - $neutralHigh);
            }

            $totalPenalty += $penalty;
        }

        $avgPenalty = $totalPenalty / count($gaps);

        return max(0.0, 1.0 - min(1.0, $avgPenalty));
    }

    /**
     * Scores every player in `$playerIds` against the same ordered `$matches` list and returns
     * the per-player breakdown plus the cross-player aggregate (`min` and `avg`).
     *
     * `min` is the worst-player score (the CLI's `Min Dist.` column); `avg` is the mean across
     * players (the CLI's `Avg Dist.` column). On an empty player list both aggregates fall back
     * to `0.0`, mirroring {@see TemplateMatchesGenerator::scoreMatchOrderDistribution()}'s
     * historical behavior.
     *
     * @param array<int, int>                         $playerIds
     * @param array<int, array<int, array<int, int>>> $matches
     * @return array{
     *     perPlayer: array<int, float>,
     *     min: float,
     *     avg: float
     * }
     */
    public function scoreAll(array $playerIds, array $matches): array
    {
        $perPlayer = [];
        $sum = 0.0;
        $min = INF;

        foreach ($playerIds as $playerId) {
            $score = $this->score((int) $playerId, $matches);
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
     * Maps a raw score in `[0, 1]` to its display payload: rounded percentage and one of the
     * four CSS class strings `excellent | good | fair | poor`. The thresholds are the canonical
     * `DISPLAY_EXCELLENT / DISPLAY_GOOD / DISPLAY_FAIR` constants on this class.
     *
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
}
