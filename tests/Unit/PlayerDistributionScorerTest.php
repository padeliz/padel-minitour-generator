<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Unit tests for the canonical player-distribution scorer.
 *
 * The scorer is the single source of truth for the matches-page UI (per-player percentages on
 * load and after every drag) and the sort-phase DFS leaf compute. These tests pin the
 * piecewise asymmetric penalty contract with hand-computed expected values so the on-screen
 * percentages stay locked even if the wider generator gets refactored further.
 */
final class PlayerDistributionScorerTest extends TestCase
{
    public function test_score_returns_one_when_player_appears_in_a_single_match(): void
    {
        $scorer = new PlayerDistributionScorer();

        $matches = [
            [[0, 1], [2, 3]],
            [[4, 5], [6, 7]],
            [[8, 9], [10, 11]],
        ];

        $this->assertSame(1.0, $scorer->score(0, $matches));
    }

    public function test_score_returns_one_when_player_never_appears(): void
    {
        $scorer = new PlayerDistributionScorer();

        $matches = [
            [[1, 2], [3, 4]],
            [[5, 6], [7, 8]],
        ];

        $this->assertSame(1.0, $scorer->score(0, $matches));
    }

    public function test_score_returns_one_for_empty_matches(): void
    {
        $scorer = new PlayerDistributionScorer();

        $this->assertSame(1.0, $scorer->score(0, []));
    }

    public function test_score_returns_one_for_perfect_neutral_band_spacing(): void
    {
        $scorer = new PlayerDistributionScorer();

        // 4 matches, player at indices 0 and 2.
        // idealCeil = ceil(4/2) = 2, neutralLow = 1, neutralHigh = 2.
        // inner sit-outs = 2 - 0 - 1 = 1 (inter, ==neutralLow) -> 0.
        // no lead (firstMatch=0), trail = 1 (edge, ==neutralLow) -> 0.
        // avg(0, 0) = 0 -> score = 1.0.
        $matches = [
            [[0, 1], [2, 3]],
            [[1, 2], [3, 4]],
            [[0, 4], [5, 6]],
            [[1, 3], [5, 6]],
        ];

        $this->assertSame(1.0, $scorer->score(0, $matches));
    }

    public function test_score_applies_long_wait_penalty_for_inner_gap_above_neutral_band(): void
    {
        $scorer = new PlayerDistributionScorer();

        // 6 matches, player at indices 0 and 5 (anchored at both endpoints to avoid lead/trail
        // contributions).
        // idealCeil = ceil(6/2) = 3, neutralHigh = 3.
        // inner sit-outs = 5 - 0 - 1 = 4 (inter, > neutralHigh by 1) -> 0.4 * 1 = 0.4.
        // no lead (firstMatch=0), no trail (lastMatch=5=totalMatches-1).
        // avg = 0.4 -> score = 0.6.
        $matches = [
            [[0, 1], [2, 3]],
            [[1, 2], [3, 4]],
            [[1, 2], [3, 4]],
            [[1, 2], [3, 4]],
            [[1, 2], [3, 4]],
            [[0, 5], [6, 7]],
        ];

        $this->assertEqualsWithDelta(0.6, $scorer->score(0, $matches), 1e-9);
    }

    public function test_score_applies_gentle_penalty_for_inter_gap_below_neutral_band(): void
    {
        $scorer = new PlayerDistributionScorer();

        // 12 matches, player at 0, 1, 6, 11.
        // matchCount = 4, idealCeil = 3, neutralLow = 2, neutralHigh = 3.
        // inner sit-outs 0 (inter, < neutralLow by 2) -> 0.1 * (2 - 0) = 0.2.
        // inner sit-outs 4 (inter, > neutralHigh by 1) -> 0.4 * 1 = 0.4.
        // inner sit-outs 4 (inter, > neutralHigh by 1) -> 0.4 * 1 = 0.4.
        // no lead (firstMatch=0), no trail (lastMatch=11).
        // avg = (0.2 + 0.4 + 0.4) / 3 = 1.0 / 3.
        // score = 1 - 1/3 = 2/3.
        $matches = $this->makeMatches(12, 0, [0, 1, 6, 11]);

        $this->assertEqualsWithDelta(1.0 - (1.0 / 3.0), $scorer->score(0, $matches), 1e-9);
    }

    public function test_score_charges_small_penalty_for_edge_gap_at_idealCeil(): void
    {
        $scorer = new PlayerDistributionScorer();

        // 4 matches, player at indices 2 and 3 (only at the tail).
        // idealCeil = 2, neutralLow = 1, neutralHigh = 2.
        // lead = 2 (edge, ==neutralHigh) -> 0.1.
        // inner sit-outs 0 (inter, < neutralLow by 1) -> 0.1 * (1 - 0) = 0.1.
        // no trail (lastMatch=3=totalMatches-1).
        // avg = (0.1 + 0.1) / 2 = 0.1 -> score = 0.9.
        $matches = $this->makeMatches(4, 0, [2, 3]);

        $this->assertEqualsWithDelta(0.9, $scorer->score(0, $matches), 1e-9);
    }

    public function test_score_treats_short_edge_gaps_as_free(): void
    {
        $scorer = new PlayerDistributionScorer();

        // 12 matches, player at indices 1, 5, 9.
        // idealCeil = 4, neutralLow = 3, neutralHigh = 4.
        // lead = 1 (edge, < neutralLow=3) -> 0 (edge-short rule).
        // inner sit-outs 5-1-1 = 3 (inter, ==neutralLow) -> 0.
        // inner sit-outs 9-5-1 = 3 (inter, ==neutralLow) -> 0.
        // trail = 12-1-9 = 2 (edge, < neutralLow=3) -> 0 (edge-short rule).
        // avg = 0 -> score = 1.0. The contrast with the previous test fixes the asymmetric
        // edge vs inter behaviour for sub-neutralLow gaps.
        $matches = $this->makeMatches(12, 0, [1, 5, 9]);

        $this->assertSame(1.0, $scorer->score(0, $matches));
    }

    public function test_score_clamps_extreme_penalties_to_zero(): void
    {
        $scorer = new PlayerDistributionScorer();

        // 20 matches, player at indices 0 and 19. Gigantic single inner sit-out run of 18,
        // idealCeil = 10. Penalty = 0.4 * (18 - 10) = 3.2, but the avg/clamp pins
        // the score at 0.0. Single-gap so no averaging dilution.
        $matches = $this->makeMatches(20, 0, [0, 19]);

        $this->assertSame(0.0, $scorer->score(0, $matches));
    }

    public function test_scoreAll_returns_per_player_breakdown_and_aggregate(): void
    {
        $scorer = new PlayerDistributionScorer();

        // Player 0 spans the full schedule (perfect), player 1 plays only twice in close
        // succession at the start, so their scores diverge cleanly.
        $matches = [
            [[0, 1], [2, 3]],   // 0: P0, P1
            [[0, 2], [4, 5]],   // 1: P0
            [[1, 4], [3, 5]],   // 2: P1
            [[0, 3], [4, 5]],   // 3: P0
        ];

        $aggregate = $scorer->scoreAll([0, 1], $matches);

        $expectedP0 = $scorer->score(0, $matches);
        $expectedP1 = $scorer->score(1, $matches);

        $this->assertSame([0 => $expectedP0, 1 => $expectedP1], $aggregate['perPlayer']);
        $this->assertSame(min($expectedP0, $expectedP1), $aggregate['min']);
        $this->assertEqualsWithDelta(($expectedP0 + $expectedP1) / 2.0, $aggregate['avg'], 1e-12);
    }

    public function test_scoreAll_returns_zero_aggregates_for_empty_player_list(): void
    {
        $scorer = new PlayerDistributionScorer();

        $matches = [
            [[0, 1], [2, 3]],
        ];

        $aggregate = $scorer->scoreAll([], $matches);

        $this->assertSame([], $aggregate['perPlayer']);
        $this->assertSame(0.0, $aggregate['min']);
        $this->assertSame(0.0, $aggregate['avg']);
    }

    public function test_classify_paints_excellent_at_or_above_ninety_percent(): void
    {
        $scorer = new PlayerDistributionScorer();

        $this->assertSame(['percentage' => 100, 'cssClass' => 'excellent'], $scorer->classify(1.0));
        $this->assertSame(['percentage' => 90, 'cssClass' => 'excellent'], $scorer->classify(0.90));
        $this->assertSame(['percentage' => 95, 'cssClass' => 'excellent'], $scorer->classify(0.95));
    }

    public function test_classify_paints_good_in_eighty_to_ninety_band(): void
    {
        $scorer = new PlayerDistributionScorer();

        $this->assertSame(['percentage' => 80, 'cssClass' => 'good'], $scorer->classify(0.80));
        $this->assertSame(['percentage' => 89, 'cssClass' => 'good'], $scorer->classify(0.89));
        // 0.8999 rounds to 90% but stays in the 'good' band because the threshold compares the
        // raw float, not the rounded percentage. This locks the rule that classification follows
        // the float, not the cosmetic percentage.
        $this->assertSame(['percentage' => 90, 'cssClass' => 'good'], $scorer->classify(0.8999));
    }

    public function test_classify_paints_fair_in_seventy_to_eighty_band(): void
    {
        $scorer = new PlayerDistributionScorer();

        $this->assertSame(['percentage' => 70, 'cssClass' => 'fair'], $scorer->classify(0.70));
        $this->assertSame(['percentage' => 79, 'cssClass' => 'fair'], $scorer->classify(0.79));
        $this->assertSame(['percentage' => 80, 'cssClass' => 'fair'], $scorer->classify(0.7999));
    }

    public function test_classify_paints_poor_below_seventy_percent(): void
    {
        $scorer = new PlayerDistributionScorer();

        $this->assertSame(['percentage' => 70, 'cssClass' => 'poor'], $scorer->classify(0.6999));
        $this->assertSame(['percentage' => 50, 'cssClass' => 'poor'], $scorer->classify(0.50));
        $this->assertSame(['percentage' => 0, 'cssClass' => 'poor'], $scorer->classify(0.0));
    }

    /**
     * Builds a matches array of {@code $totalMatches} entries where {@code $playerIndex}
     * appears at exactly the positions listed in {@code $positions} and never elsewhere. The
     * filler players are derived from the target so they cannot collide with it.
     *
     * @param array<int, int> $positions
     * @return array<int, array<int, array<int, int>>>
     */
    private function makeMatches(int $totalMatches, int $playerIndex, array $positions): array
    {
        $dummyA = $playerIndex + 100;
        $dummyB = $playerIndex + 101;
        $dummyC = $playerIndex + 102;
        $dummyD = $playerIndex + 103;
        $positionsLookup = array_flip($positions);

        $matches = [];
        for ($i = 0; $i < $totalMatches; $i++) {
            if (isset($positionsLookup[$i])) {
                $matches[] = [[$playerIndex, $dummyA], [$dummyB, $dummyC]];
            } else {
                $matches[] = [[$dummyA, $dummyB], [$dummyC, $dummyD]];
            }
        }

        return $matches;
    }
}
