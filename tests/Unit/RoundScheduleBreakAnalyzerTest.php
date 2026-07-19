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

    public function test_partial_streak_prune_ignores_trailing_gap_on_incomplete_schedule(): void
    {
        $matches = [[[[0, 2], [1, 3]]]];
        $playedAtLeastOnce = [true, true, true, true];
        $shortestInner = [null, null, null, null];

        $this->assertFalse(RoundScheduleBreakAnalyzer::shouldPrunePartialConsecutiveMinBreaks(
            $matches,
            range(0, 3),
            $playedAtLeastOnce,
            $shortestInner,
            4,
            1,
            1
        ));
    }

    public function test_partial_streak_prune_waits_until_inner_breaks_are_known(): void
    {
        $matches = [[
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
        ]];
        $playedAtLeastOnce = [true, true, true, true];
        $shortestInner = [null, null, null, null];

        $this->assertFalse(RoundScheduleBreakAnalyzer::shouldPrunePartialConsecutiveMinBreaks(
            $matches,
            range(0, 3),
            $playedAtLeastOnce,
            $shortestInner,
            4,
            1,
            1
        ));
    }

    public function test_partial_streak_prune_fires_when_density_threshold_exceeded(): void
    {
        // 8/6 → T0=2; five back-to-back rounds ⇒ inner streak of 4 > 2
        $matches = [[
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
        ]];
        $playedAtLeastOnce = array_fill(0, 8, true);
        $shortestInner = array_fill(0, 8, 0);

        $this->assertTrue(RoundScheduleBreakAnalyzer::shouldPrunePartialConsecutiveMinBreaks(
            $matches,
            range(0, 7),
            $playedAtLeastOnce,
            $shortestInner,
            8,
            6,
            1
        ));
    }

    public function test_density_threshold_allows_five_four_historical_cmin(): void
    {
        $this->assertSame(4, RoundScheduleBreakAnalyzer::densityAbsoluteCMinThreshold(5, 4, 1, 0));
        $this->assertSame(2, RoundScheduleBreakAnalyzer::densityAbsoluteCMinThreshold(8, 6, 1, 0));
        $this->assertSame(5, RoundScheduleBreakAnalyzer::densityAbsoluteCMinThreshold(12, 8, 2, 0));
        $this->assertNull(RoundScheduleBreakAnalyzer::densityAbsoluteCMinThreshold(4, 3, 1, 0));
        $this->assertSame(3, RoundScheduleBreakAnalyzer::densityAbsoluteCMinThreshold(6, 4, 1, 0));
        $this->assertSame(5, RoundScheduleBreakAnalyzer::densityAbsoluteCMinThreshold(5, 4, 1, 1));
    }

    public function test_density_fallback_does_not_kill_streak_equal_to_t0_for_five_four(): void
    {
        // Four consecutive zero-rest inner gaps ⇒ streak 4; T0=4 ⇒ do not prune on >
        $matches = [[
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
        ]];
        $playedAtLeastOnce = [true, true, true, true, true];
        $shortestInner = [0, 0, 0, 0, null];

        $this->assertFalse(RoundScheduleBreakAnalyzer::shouldPrunePartialConsecutiveMinBreaks(
            $matches,
            range(0, 4),
            $playedAtLeastOnce,
            $shortestInner,
            5,
            4,
            1
        ));
    }

    public function test_idle_zero_disables_density_absolute_fallback(): void
    {
        $matches = [[
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
        ]];
        $playedAtLeastOnce = [true, true, true, true];
        $shortestInner = [0, 0, 0, 0];

        $this->assertFalse(RoundScheduleBreakAnalyzer::shouldPrunePartialConsecutiveMinBreaks(
            $matches,
            range(0, 3),
            $playedAtLeastOnce,
            $shortestInner,
            4,
            3,
            1
        ));
    }

    public function test_min_break_upper_bound_ignores_nulls(): void
    {
        $this->assertNull(RoundScheduleBreakAnalyzer::minBreakUpperBound([null, null]));
        $this->assertSame(1, RoundScheduleBreakAnalyzer::minBreakUpperBound([null, 2, 1]));
    }

    public function test_break_bounds_prune_when_min_break_ub_worse_than_best(): void
    {
        $best = [
            'ordered' => [[[[0, 1], [2, 3]]]],
            'minBreak' => 1,
            'maxBreak' => 2,
        ];

        $this->assertTrue(RoundScheduleBreakAnalyzer::shouldPrunePartialBreakBounds(
            [0, 1, null],
            [1, 1, 1],
            $best
        ));
    }

    public function test_break_bounds_prune_max_only_when_min_tied(): void
    {
        $best = [
            'ordered' => [[[[0, 1], [2, 3]]]],
            'minBreak' => 1,
            'maxBreak' => 2,
        ];

        // minBreakUb still better than best → do not prune on max alone
        $this->assertFalse(RoundScheduleBreakAnalyzer::shouldPrunePartialBreakBounds(
            [2, 2],
            [5, 5],
            $best
        ));

        // min tied, max lower-bound already worse
        $this->assertTrue(RoundScheduleBreakAnalyzer::shouldPrunePartialBreakBounds(
            [1, 2],
            [3, 1],
            $best
        ));
    }

    public function test_break_bounds_skipped_until_first_leaf(): void
    {
        $best = [
            'ordered' => null,
            'minBreak' => null,
            'maxBreak' => null,
        ];

        $this->assertFalse(RoundScheduleBreakAnalyzer::shouldPrunePartialBreakBounds(
            [0],
            [9],
            $best
        ));
    }

    public function test_incumbent_cmin_prunes_only_with_strict_greater(): void
    {
        // Three rounds ⇒ two inner zero-gaps (streak 2). 8/6 density T0=2 ⇒ streak 2 does not trip absolute.
        $matches = [[
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
        ]];
        $playedAtLeastOnce = array_fill(0, 8, true);
        $shortestInner = array_fill(0, 8, 0);
        $longestRuns = array_fill(0, 8, 2);

        $bestEqual = [
            'ordered' => $matches,
            'minBreak' => 0,
            'maxBreak' => 2,
            'consecutiveMinBreaks' => 2,
            'consecutiveMaxBreaks' => 1,
        ];

        $this->assertFalse(RoundScheduleBreakAnalyzer::shouldPrunePartialConsecutiveMinBreaks(
            $matches,
            range(0, 7),
            $playedAtLeastOnce,
            $shortestInner,
            8,
            6,
            1,
            $longestRuns,
            $bestEqual
        ));

        $bestBetter = $bestEqual;
        $bestBetter['consecutiveMinBreaks'] = 1;
        $this->assertTrue(RoundScheduleBreakAnalyzer::shouldPrunePartialConsecutiveMinBreaks(
            $matches,
            range(0, 7),
            $playedAtLeastOnce,
            $shortestInner,
            8,
            6,
            1,
            $longestRuns,
            $bestBetter
        ));
    }
}
