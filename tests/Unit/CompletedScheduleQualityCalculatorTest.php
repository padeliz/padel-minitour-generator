<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\CompletedScheduleQualityCalculator;
use Arshavinel\PadelMiniTour\Service\CourtScheduleMetrics;
use Arshavinel\PadelMiniTour\Service\TemplateMatchDerivation;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class CompletedScheduleQualityCalculatorTest extends TestCase
{
    public function test_rounds_count_is_time_slots_not_total_matches(): void
    {
        // 2 courts × 2 rounds = 4 matches, 2 time slots
        $matches = [
            0 => [
                [[0, 1], [2, 3]],
                [[0, 2], [1, 3]],
            ],
            1 => [
                [[4, 5], [6, 7]],
                [[4, 6], [5, 7]],
            ],
        ];

        $this->assertSame(4, TemplateMatchDerivation::matchesCount($matches));
        $this->assertSame(2, TemplateMatchDerivation::roundsCount($matches));

        $quality = (new CompletedScheduleQualityCalculator())->compute(8, 1, 1, 2, false, $matches);
        $this->assertSame(4, $quality['matchMaking']['matchesCount']);
        $this->assertSame(2, $quality['ordering']['roundsCount']);
    }

    public function test_single_court_court_metrics_are_zero(): void
    {
        $matches = [
            0 => [
                [[0, 1], [2, 3]],
                [[0, 2], [1, 3]],
            ],
        ];

        $metrics = CourtScheduleMetrics::score($matches, range(0, 3), 1);
        $this->assertSame(0, $metrics['courtSwitches']);
        $this->assertSame(0.0, $metrics['courtBalance']);
    }

    public function test_pairs_from_matches_dedupes_repeated_partner_pairs(): void
    {
        $matches = [
            0 => [
                [[0, 1], [2, 3]],
                [[0, 1], [2, 3]],
            ],
        ];

        $pairs = TemplateMatchDerivation::pairsFromMatches($matches);
        $this->assertCount(2, $pairs);
        $this->assertSame([0, 1], $pairs[0]['players']);
        $this->assertSame([2, 3], $pairs[1]['players']);
    }
}
