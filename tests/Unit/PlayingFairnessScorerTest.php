<?php

declare(strict_types=1);

namespace Arshavinel\PadelMiniTour\Tests\Unit;

use Arshavinel\PadelMiniTour\Service\PlayingFairnessScorer;
use PHPUnit\Framework\TestCase;

final class PlayingFairnessScorerTest extends TestCase
{
    public function test_even_and_favored_matches_apply_zero_penalty(): void
    {
        $scorer = new PlayingFairnessScorer();
        $matches = [[
            [[4, 5], [3, 6]],
        ]];

        $result = $scorer->scoreTemplate($matches, 10);

        $this->assertEqualsWithDelta(1.0, $result['min'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $result['avg'], 1e-9);
        $this->assertSame(0.0, $result['maxPenalty']);
    }

    public function test_single_max_unfair_match_costs_one_over_matches_for_player(): void
    {
        $scorer = new PlayingFairnessScorer();
        $matches = [[[[8, 9], [0, 1]]]];

        $result = $scorer->scoreTemplate($matches, 10);

        $expectedPenalty = (8.0 / 9.0) / 1.0;
        $this->assertEqualsWithDelta($expectedPenalty, $result['maxPenalty'], 1e-9);
        $this->assertEqualsWithDelta(1.0 - $expectedPenalty, $result['min'], 1e-9);
        $this->assertEqualsWithDelta(1.0 - ($expectedPenalty / 5.0), $result['avg'], 1e-9);
    }

    public function test_all_max_unfair_matches_drive_score_to_minimum_for_ladder(): void
    {
        $scorer = new PlayingFairnessScorer();
        $matches = [[
            [[8, 9], [0, 1]],
            [[8, 9], [0, 1]],
            [[8, 9], [0, 1]],
            [[8, 9], [0, 1]],
            [[8, 9], [0, 1]],
            [[8, 9], [0, 1]],
            [[8, 9], [0, 1]],
            [[8, 9], [0, 1]],
        ]];

        $result = $scorer->scoreTemplate($matches, 10);

        $this->assertEqualsWithDelta(1.0 / 9.0, $result['min'], 1e-9);
        $this->assertEqualsWithDelta((8.0 / 9.0) / 8.0, $result['maxPenalty'], 1e-9);
    }

    public function test_ladder_normalization_scales_gap_by_player_count(): void
    {
        $gap = PlayingFairnessScorer::matchGap(8.5, 0.5);
        $penalty10 = PlayingFairnessScorer::matchPenalty($gap, 10, 8);
        $penalty16 = PlayingFairnessScorer::matchPenalty($gap, 16, 8);

        $this->assertGreaterThan($penalty16, $penalty10);
        $this->assertEqualsWithDelta((8.0 / 9.0) / 8.0, $penalty10, 1e-9);
        $this->assertEqualsWithDelta((8.0 / 15.0) / 8.0, $penalty16, 1e-9);
    }

    public function test_four_player_template_scores_underdog_side(): void
    {
        $scorer = new PlayingFairnessScorer();
        $result = $scorer->scoreTemplate([[[[0, 1], [2, 3]]]], 4);

        $penalty = (2.0 / 3.0) / 1.0;
        $this->assertEqualsWithDelta(1.0 - $penalty, $result['min'], 1e-9);
        $this->assertEqualsWithDelta((2.0 * (1.0 - $penalty) + 2.0) / 4.0, $result['avg'], 1e-9);
        $this->assertEqualsWithDelta($penalty, $result['maxPenalty'], 1e-9);
    }
}
