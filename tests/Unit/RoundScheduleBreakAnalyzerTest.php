<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\RoundScheduleBreakAnalyzer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class RoundScheduleBreakAnalyzerTest extends TestCase
{
    public function test_consecutive_min_breaks_scans_all_gaps_against_threshold(): void
    {
        // Player 0: gaps [1, 2, 1] (lead 1, inner 2 after first play, trail 1)
        // Player 1: gaps [0, 3] (plays round 0, sits 3)
        $matches = [[
            [[0, 1], [2, 3]],
            [[2, 3], [4, 5]],
            [[0, 2], [1, 3]],
            [[4, 5], [2, 3]],
            [[0, 1], [2, 3]],
        ]];

        $result = RoundScheduleBreakAnalyzer::analyze($matches, range(0, 5), 1, null);

        $this->assertSame(3, $result['consecutiveMinBreaks']);
        $this->assertNull($result['consecutiveMaxBreaks']);
    }

    public function test_consecutive_max_breaks_counts_repeated_max_length_absences(): void
    {
        // Player 0 sits rounds 0-1 (lead 2), plays round 2, sits rounds 3-4 (inner 2), plays round 5
        // Gaps for player 0: [2, 0, 2, 0] with maxBreak threshold 2 -> streak of 2 at start is NOT consecutive
        // because inner 0 breaks the run. Trail after last play at round 5 in 6-round schedule: gap 0.
        // Craft schedule where two consecutive gaps equal maxBreak:
        // Player 1: never plays first 2 rounds... let's use simpler 1-court schedule.
        //
        // 6 rounds, player 0 plays only at rounds 2 and 5 -> gaps [2, 2, 0] with threshold 2 -> streak 2
        $matches = [[
            [[1, 2], [3, 4]],
            [[1, 2], [3, 4]],
            [[0, 1], [2, 3]],
            [[1, 2], [3, 4]],
            [[1, 2], [3, 4]],
            [[0, 1], [2, 3]],
        ]];

        $result = RoundScheduleBreakAnalyzer::analyze($matches, range(0, 4), null, 2);

        $this->assertNull($result['consecutiveMinBreaks']);
        $this->assertSame(2, $result['consecutiveMaxBreaks']);
    }

    public function test_no_matching_gaps_returns_zero(): void
    {
        $matches = [[
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
        ]];

        $result = RoundScheduleBreakAnalyzer::analyze($matches, range(0, 3), 5, 5);

        $this->assertSame(0, $result['consecutiveMinBreaks']);
        $this->assertSame(0, $result['consecutiveMaxBreaks']);
    }

    public function test_null_thresholds_yield_null_streak_fields(): void
    {
        $matches = [[[[0, 1], [2, 3]]]];

        $result = RoundScheduleBreakAnalyzer::analyze($matches, range(0, 3), null, null);

        $this->assertNull($result['consecutiveMinBreaks']);
        $this->assertNull($result['consecutiveMaxBreaks']);
    }

    public function test_compute_break_metrics_matches_round_schedule_reference(): void
    {
        $matches = [
            [[1, 2], [3, 4]],
            [[5, 6], [7, 0]],
            [[1, 3], [2, 4]],
            [[5, 7], [6, 0]],
            [[1, 4], [2, 5]],
            [[3, 6], [7, 0]],
            [[1, 5], [2, 6]],
            [[3, 7], [4, 0]],
        ];

        $metrics = RoundScheduleBreakAnalyzer::computeBreakMetrics([$matches], range(0, 7));

        $this->assertSame(0, $metrics['minBreak']);
        $this->assertGreaterThanOrEqual(1, $metrics['maxBreak']);
    }

    public function test_multi_court_round_aware_play_detection(): void
    {
        $matches = [
            [
                [[0, 1], [2, 3]],
                [[4, 5], [6, 7]],
            ],
            [
                [[0, 2], [1, 3]],
                [[4, 6], [5, 7]],
            ],
        ];

        $result = RoundScheduleBreakAnalyzer::analyze($matches, range(0, 7), 0, null);

        $this->assertGreaterThanOrEqual(1, $result['consecutiveMinBreaks']);
    }
}
