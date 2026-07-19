<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\LexFloat;
use Arshavinel\PadelMiniTour\Service\MatchMakingLex;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class LexFloatTest extends TestCase
{
    public function test_quantize_rounds_to_two_decimals(): void
    {
        $this->assertSame(0.91, LexFloat::quantize(0.912));
        $this->assertSame(0.92, LexFloat::quantize(0.918));
        $this->assertSame(0.91, LexFloat::quantize(0.9149));
        $this->assertSame(0.92, LexFloat::quantize(0.915));
    }

    public function test_compare_treats_third_decimal_as_tie(): void
    {
        $this->assertSame(0, LexFloat::compare(0.911, 0.914));
        $this->assertSame(0, LexFloat::compare(0.9149, 0.9101));
        $this->assertSame(1, LexFloat::compare(0.92, 0.91));
        $this->assertSame(-1, LexFloat::compare(0.90, 0.91));
    }

    public function test_is_better_max_and_min(): void
    {
        $this->assertFalse(LexFloat::isBetterMax(0.911, 0.914));
        $this->assertTrue(LexFloat::isBetterMax(0.93, 0.914));
        $this->assertFalse(LexFloat::isBetterMin(0.911, 0.914));
        $this->assertTrue(LexFloat::isBetterMin(0.90, 0.914));
    }

    public function test_non_finite_values_pass_through(): void
    {
        $this->assertSame(INF, LexFloat::quantize(INF));
        $this->assertSame(-INF, LexFloat::quantize(-INF));
        $this->assertSame(0, LexFloat::compare(INF, INF));
        $this->assertTrue(LexFloat::isBetterMin(1.0, INF));
    }

    public function test_match_making_lex_ties_on_two_decimal_playing_fairness(): void
    {
        $left = $this->lexVector(5, 1.0, 0.911, 0.1, 0.95);
        $right = $this->lexVector(5, 1.0, 0.914, 0.1, 0.95);
        $this->assertSame(0, MatchMakingLex::compare($left, $right));

        $better = $this->lexVector(5, 1.0, 0.93, 0.1, 0.95);
        $this->assertSame(1, MatchMakingLex::compare($better, $right));
    }

    public function test_ordering_lex_ties_on_two_decimal_distribution(): void
    {
        $generator = new TemplateMatchesGenerator();
        $compare = (new \ReflectionClass($generator))->getMethod('compareOrderingLex');
        $compare->setAccessible(true);

        $left = [
            'ordered' => [[[[0, 1], [2, 3]]]],
            'minBreak' => 0,
            'maxBreak' => 2,
            'consecutiveMinBreaks' => 1,
            'consecutiveMaxBreaks' => 1,
            'normCourtSwitches' => 0.0,
            'min' => 0.911,
            'avg' => 0.95,
        ];
        $right = $left;
        $right['min'] = 0.914;

        $this->assertSame(0, $compare->invoke($generator, $left, $right));

        $better = $left;
        $better['min'] = 0.93;
        $this->assertSame(1, $compare->invoke($generator, $better, $right));
    }

    public function test_ordering_wall_budget_scales_cap_and_per_court_with_courts(): void
    {
        $this->assertSame(30.0, TemplateMatchesGenerator::ORDERING_BUDGET_PER_COURT_S);

        $oneCourt = TemplateMatchesGenerator::computeOrderingWallBudgetNs(16, 12, 1);
        $twoCourt = TemplateMatchesGenerator::computeOrderingWallBudgetNs(16, 12, 2);

        $matchCount = (16 * 12) / 4;
        $rounds1 = (int) ceil($matchCount / 1);
        $raw1 = TemplateMatchesGenerator::ORDERING_BUDGET_BASE_S
            + TemplateMatchesGenerator::ORDERING_BUDGET_PER_ROUND_S * $rounds1
            + TemplateMatchesGenerator::ORDERING_BUDGET_PER_COURT_S * 1
            + TemplateMatchesGenerator::ORDERING_BUDGET_PER_MATCH_S * $matchCount;
        $expected1 = min($raw1, TemplateMatchesGenerator::ORDERING_BUDGET_MAX_S * 1);
        $this->assertGreaterThan(TemplateMatchesGenerator::ORDERING_BUDGET_MAX_S, $raw1);
        $this->assertSame((int) round($expected1 * 1_000_000_000), $oneCourt);

        $rounds2 = (int) ceil($matchCount / 2);
        $expected2 = min(
            TemplateMatchesGenerator::ORDERING_BUDGET_BASE_S
                + TemplateMatchesGenerator::ORDERING_BUDGET_PER_ROUND_S * $rounds2
                + TemplateMatchesGenerator::ORDERING_BUDGET_PER_COURT_S * 2
                + TemplateMatchesGenerator::ORDERING_BUDGET_PER_MATCH_S * $matchCount,
            TemplateMatchesGenerator::ORDERING_BUDGET_MAX_S * 2
        );
        $this->assertSame((int) round($expected2 * 1_000_000_000), $twoCourt);
        $this->assertGreaterThan($oneCourt, $twoCourt);
    }

    public function test_ordering_lex_prefers_better_consecutive_streaks_across_candidates(): void
    {
        $generator = new TemplateMatchesGenerator();
        $compare = (new \ReflectionClass($generator))->getMethod('compareOrderingLex');
        $compare->setAccessible(true);

        $worseStreaks = [
            'ordered' => [[[[0, 1], [2, 3]]]],
            'minBreak' => 0,
            'maxBreak' => 2,
            'consecutiveMinBreaks' => 2,
            'consecutiveMaxBreaks' => 1,
            'normCourtSwitches' => 0.0,
            'min' => 0.95,
            'avg' => 0.97,
        ];
        $betterStreaks = $worseStreaks;
        $betterStreaks['consecutiveMinBreaks'] = 1;

        $this->assertSame(1, $compare->invoke($generator, $betterStreaks, $worseStreaks));
        $this->assertSame(-1, $compare->invoke($generator, $worseStreaks, $betterStreaks));
    }

    /**
     * @return array{
     *     matches: list<array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>,
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
            'matches' => [[[0, 1], [2, 3]]],
            'minOpponentsMet' => $minOpponentsMet,
            'meetingsVariation' => $meetingsVariation,
            'minPlayingFairness' => $minPlayingFairness,
            'maxPlayingFairnessPenalty' => $maxPlayingFairnessPenalty,
            'avgPlayingFairness' => $avgPlayingFairness,
        ];
    }
}
