<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\MatchMakingLex;
use Arshavinel\PadelMiniTour\Service\PlayingFairnessScorer;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class MatchMakingLexTest extends TestCase
{
    private const STUB_MATCHES = [[[0, 1], [2, 3]]];

    public function test_compare_respects_tier_order(): void
    {
        $incumbent = $this->lexVector(5, 1.0, 0.9, 0.1, 0.95);

        $this->assertSame(1, MatchMakingLex::compare($this->lexVector(6, 2.0, 0.0, 1.0, 0.0), $incumbent));
        $this->assertSame(-1, MatchMakingLex::compare($this->lexVector(4, 0.0, 1.0, 0.0, 1.0), $incumbent));

        $sameMin = $this->lexVector(5, 2.0, 0.0, 1.0, 0.0);
        $this->assertSame(1, MatchMakingLex::compare($this->lexVector(5, 1.0, 0.0, 1.0, 0.0), $sameMin));
        $this->assertSame(-1, MatchMakingLex::compare($this->lexVector(5, 3.0, 0.0, 1.0, 0.0), $sameMin));

        $sameVar = $this->lexVector(5, 1.0, 0.5, 0.5, 0.0);
        $this->assertSame(1, MatchMakingLex::compare($this->lexVector(5, 1.0, 0.9, 0.5, 0.0), $sameVar));
        $this->assertSame(-1, MatchMakingLex::compare($this->lexVector(5, 1.0, 0.1, 0.5, 0.0), $sameVar));

        $samePf = $this->lexVector(5, 1.0, 0.5, 0.5, 0.0);
        $this->assertSame(1, MatchMakingLex::compare($this->lexVector(5, 1.0, 0.5, 0.1, 0.0), $samePf));
        $this->assertSame(-1, MatchMakingLex::compare($this->lexVector(5, 1.0, 0.5, 0.9, 0.0), $samePf));

        $samePenalty = $this->lexVector(5, 1.0, 0.5, 0.5, 0.4);
        $this->assertSame(1, MatchMakingLex::compare($this->lexVector(5, 1.0, 0.5, 0.5, 0.9), $samePenalty));
        $this->assertSame(-1, MatchMakingLex::compare($this->lexVector(5, 1.0, 0.5, 0.5, 0.2), $samePenalty));

        $fullTie = $this->lexVector(5, 1.0, 0.5, 0.5, 0.4);
        $this->assertSame(0, MatchMakingLex::compare($fullTie, $fullTie));
    }

    public function test_score_leaf_produces_expected_metrics_on_fixture(): void
    {
        $matches = [
            [[0, 1], [5, 6]],
            [[2, 3], [4, 7]],
            [[2, 4], [3, 7]],
        ];

        $generator = new TemplateMatchesGenerator();
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $addPlayersMet = $reflection->getMethod('addPlayersMet');
        $addPlayersMet->setAccessible(true);

        $playersMet = [];
        foreach ($matches as $match) {
            $playersMet = $addPlayersMet->invoke($generator, $playersMet, $match);
        }

        $scored = MatchMakingLex::scoreLeaf($matches, $playersMet, 8, new PlayingFairnessScorer());

        $this->assertSame(3, $scored['minOpponentsMet']);
        $this->assertEqualsWithDelta(0.0, $scored['meetingsVariation'], 1e-9);
        $this->assertEqualsWithDelta(0.2857142857142857, $scored['minPlayingFairness'], 1e-9);
        $this->assertCount(3, $scored['matches']);
        $this->assertArrayHasKey(7, $scored['playersMet']);
    }

    public function test_is_seed_result_better_defers_to_lower_seed_on_full_metric_tie(): void
    {
        $vector = $this->lexVector(5, 1.0, 0.8, 0.1, 0.9);

        $this->assertTrue(MatchMakingLex::isSeedResultBetter($vector, $vector, 0, 1));
        $this->assertFalse(MatchMakingLex::isSeedResultBetter($vector, $vector, 2, 1));
        $this->assertTrue(MatchMakingLex::isSeedResultBetter($vector, null, 3, null));
    }

    public function test_playing_fairness_breaks_tie_when_min_opponents_met_and_variation_match(): void
    {
        $incumbent = $this->lexVector(5, 1.0, 0.5, 0.2, 0.4);
        $betterPf = $this->lexVector(5, 1.0, 0.9, 0.2, 0.4);

        $this->assertSame(1, MatchMakingLex::compare($betterPf, $incumbent));
        $this->assertTrue(MatchMakingLex::isSeedResultBetter($betterPf, $incumbent, 1, 0));
    }

    public function test_dedupe_and_sort_candidates_keeps_best_representative_per_multiset(): void
    {
        $sharedMatches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
        ];
        $worse = $this->lexVector(4, 2.0, 0.0, 1.0, 0.0);
        $worse['matches'] = $sharedMatches;
        $worse['seedIndex'] = 0;
        $better = $this->lexVector(5, 1.0, 0.9, 0.1, 0.95);
        $better['matches'] = array_reverse($sharedMatches);
        $better['seedIndex'] = 1;

        $deduped = MatchMakingLex::dedupeAndSortCandidates([$worse, $better]);

        $this->assertCount(1, $deduped);
        $this->assertSame(5, $deduped[0]['minOpponentsMet']);
        $this->assertSame(1, $deduped[0]['seedIndex']);
    }

    public function test_canonical_match_multiset_key_is_order_invariant(): void
    {
        $a = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
        ];
        $b = [
            [[1, 3], [0, 2]],
            [[2, 3], [0, 1]],
        ];

        $this->assertSame(
            MatchMakingLex::canonicalMatchMultisetKey($a),
            MatchMakingLex::canonicalMatchMultisetKey($b)
        );
    }

    /**
     * @return array{
     *     matches: array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>,
     *     minOpponentsMet: int,
     *     meetingsVariation: float,
     *     minPlayingFairness: float,
     *     maxPlayingFairnessPenalty: float,
     *     avgPlayingFairness: float
     * }
     */
    private function lexVector(
        int $minOpponentsMet,
        float $meetingsVariation,
        float $minPlayingFairness,
        float $maxPlayingFairnessPenalty,
        float $avgPlayingFairness
    ): array {
        return [
            'matches' => self::STUB_MATCHES,
            'minOpponentsMet' => $minOpponentsMet,
            'meetingsVariation' => $meetingsVariation,
            'minPlayingFairness' => $minPlayingFairness,
            'maxPlayingFairnessPenalty' => $maxPlayingFairnessPenalty,
            'avgPlayingFairness' => $avgPlayingFairness,
        ];
    }
}
