<?php

declare(strict_types=1);

namespace Arshavinel\PadelMiniTour\Tests\Unit;

use Arshavinel\PadelMiniTour\Service\PartnersFairnessScorer;
use PHPUnit\Framework\TestCase;

final class PartnersFairnessScorerTest extends TestCase
{
    public function test_perfect_complement_has_zero_penalty(): void
    {
        $this->assertSame(0.0, PartnersFairnessScorer::edgePenalty(0, 10, 11, 8));
        $this->assertSame(0.0, PartnersFairnessScorer::edgePenalty(1, 9, 11, 8));
    }

    public function test_o_equals_one_optimal_partner_scores_one(): void
    {
        $scorer = new PartnersFairnessScorer();
        $this->assertSame(0.0, PartnersFairnessScorer::edgePenalty(0, 11, 12, 1));
        $this->assertEqualsWithDelta(1.0, $scorer->scorePlayer(0, [11], 12, 1), 1e-9);
        $this->assertGreaterThan(0.0, PartnersFairnessScorer::edgePenalty(0, 1, 12, 1));
    }

    public function test_twelve_eleven_complete_graph_scores_perfect(): void
    {
        $scorer = new PartnersFairnessScorer();
        $pairs = [];
        for ($a = 0; $a < 12; $a++) {
            for ($b = $a + 1; $b < 12; $b++) {
                $pairs[] = ['players' => [$a, $b], 'used' => false];
            }
        }

        $result = $scorer->scorePool($pairs, 12, 11);
        $this->assertEqualsWithDelta(1.0, $result['min'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $result['avg'], 1e-9);
    }

    public function test_twelve_eight_edge_penalties_distinguish_bad_and_good_pairs(): void
    {
        $this->assertGreaterThan(0.0, PartnersFairnessScorer::edgePenalty(0, 1, 12, 8));
        $this->assertSame(0.0, PartnersFairnessScorer::edgePenalty(5, 9, 12, 8));
        $this->assertSame(0.0, PartnersFairnessScorer::edgePenalty(9, 5, 12, 8));
    }

    public function test_twelve_ten_edge_penalties_match_choice_aware_expectations(): void
    {
        $this->assertGreaterThan(0.0, PartnersFairnessScorer::edgePenalty(0, 1, 12, 10));
        $this->assertSame(0.0, PartnersFairnessScorer::edgePenalty(0, 2, 12, 10));
        $this->assertGreaterThan(0.0, PartnersFairnessScorer::edgePenalty(9, 11, 12, 10));
        $this->assertGreaterThan(0.0, PartnersFairnessScorer::edgePenalty(10, 11, 12, 10));
        $this->assertGreaterThan(0.0, PartnersFairnessScorer::edgePenalty(11, 10, 12, 10));
    }

    public function test_player_zero_n12_o8_mixed_portfolio_scores_expected(): void
    {
        $scorer = new PartnersFairnessScorer();
        $partners = [1, 2, 3, 4, 8, 9, 10, 11];
        $score = $scorer->scorePlayer(0, $partners, 12, 8);
        $this->assertEqualsWithDelta(0.75, $score, 1e-9);
    }

    public function test_pool_min_avg_over_players_not_edges(): void
    {
        $scorer = new PartnersFairnessScorer();
        $pairs = [];
        foreach ([1, 2, 3, 4, 8, 9, 10, 11] as $q) {
            $pairs[] = ['players' => [0, $q], 'used' => false];
        }
        $targets = array_fill(0, 12, 8);
        $result = $scorer->scorePool($pairs, 12, 8, $targets);
        $this->assertEqualsWithDelta(0.75, $result['min'], 1e-9);
        $this->assertGreaterThan($result['min'], $result['avg']);
    }

    public function test_min_upper_partial_uses_target_partner_count(): void
    {
        $scorer = new PartnersFairnessScorer();
        $pairs = [
            ['players' => [0, 1], 'used' => false],
            ['players' => [0, 2], 'used' => false],
        ];
        $targets = array_fill(0, 12, 8);
        $minUpper = $scorer->minUpperPartial($pairs, 12, 8, $targets);
        $player0 = $scorer->scorePlayer(0, [1, 2], 12, 8);
        $this->assertEqualsWithDelta($player0, $minUpper, 1e-9);
    }

    public function test_worst_avoidable_edge_penalty_capped_at_one_over_o(): void
    {
        $penalty = PartnersFairnessScorer::edgePenalty(0, 1, 12, 8);
        $this->assertEqualsWithDelta(1.0 / 8.0, $penalty, 1e-9);
    }

    public function test_fifteen_nine_per_player_o_from_adjacency_count(): void
    {
        $scorer = new PartnersFairnessScorer();
        $eightPartners = range(1, 8);
        $scoreExplicit = $scorer->scorePlayer(0, $eightPartners, 15, 8);

        $pairs = [];
        foreach ($eightPartners as $q) {
            $pairs[] = ['players' => [0, $q], 'used' => false];
        }

        $targets = array_fill(0, 15, 9);
        $targets[0] = 8;
        $result = $scorer->scorePool($pairs, 15, 9, $targets);
        $this->assertEqualsWithDelta($scoreExplicit, $result['min'], 1e-9);
        $this->assertGreaterThan($result['min'], $result['avg']);
    }
}
