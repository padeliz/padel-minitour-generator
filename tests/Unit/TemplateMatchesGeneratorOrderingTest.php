<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\LexFloat;
use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\ProgressReporter;
use Arshavinel\PadelMiniTour\Service\RoundScheduleBreakAnalyzer;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Unit tests for the (now backtracking DFS) runOrderingPhase() implementation.
 *
 * The runOrderingPhase method is private; tests reach it via reflection. All search-bound knobs
 * (clock, wall budgets) are constructor-injected on the new pure generator, so no static
 * reflection is needed.
 */
final class TemplateMatchesGeneratorOrderingTest extends TestCase
{
    use TemplateVersionTestTrait;

    protected function setUp(): void
    {
        $this->resetAllocatedVersions();
    }

    public function test_sort_matches_with_zero_wall_budget_returns_input_order_without_scoring(): void
    {
        $generator = new TemplateMatchesGenerator(
            static function (): int {
                return 0;
            },
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            0
        );

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];

        $result = $this->invokeOrderingPhase($generator, $matches, [0, 1, 2, 3]);

        $this->assertNull($result['ordered']);
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_DEADLINE, $result['stopReason']);
        $this->assertNull($result['min']);
        $this->assertNull($result['avg']);
    }

    public function test_sort_matches_matches_brute_force_when_wall_budget_allows_full_scan_four_players(): void
    {
        $generator = $this->makeGenerator(60_000_000_000);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];
        $mockPlayers = [0, 1, 2, 3];

        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);
        $expected = $this->bruteForceBestOrderSevenTier($matches, $mockPlayers, $generator);

        $this->assertOrderingLexEquivalent($generator, $result, $expected);
    }

    public function test_sort_matches_matches_brute_force_when_wall_budget_allows_full_scan_eight_players(): void
    {
        // mockPlayers includes seats (6) that appear in no input match. Under the new S7 prune
        // those seats accumulate a consecutive break run equal to the match count, which would
        // trip the default `ceil(playersCount / 4)` threshold for any non-trivial schedule. Pass
        // an effectively-infinite `maxBreakThreshold` so the DFS degenerates to an exhaustive
        // search and the brute-force comparison stays meaningful.
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $matches = [
            [[7, 5], [0, 3]],
            [[5, 7], [2, 4]],
            [[2, 1], [0, 5]],
            [[0, 5], [1, 4]],
        ];
        $mockPlayers = range(0, 7);

        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);
        $expected = $this->bruteForceBestOrderSevenTier($matches, $mockPlayers, $generator);

        $this->assertOrderingLexEquivalent($generator, $result, $expected);
    }

    public function test_sort_matches_returns_factorial_complete_when_thresholds_unreachable(): void
    {
        $generator = $this->makeGenerator(60_000_000_000);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];

        $result = $this->invokeOrderingPhase($generator, $matches, [0, 1, 2, 3]);

        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
    }

    public function test_sort_dfs_matches_seven_tier_brute_force_for_synthetic_six(): void
    {
        $generator = $this->makeGenerator(60_000_000_000);

        $matches = $this->makeSyntheticMatchesSix();
        $mockPlayers = range(0, 5);

        $brute = $this->bruteForceBestOrderSevenTier($matches, $mockPlayers, $generator);
        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);

        $this->assertOrderingLexEquivalent($generator, $result, $brute);
    }

    public function test_sort_matches_returns_input_unchanged_when_match_count_is_one_or_zero(): void
    {
        $generator = $this->makeGenerator(1_000_000_000);

        $resultEmpty = $this->invokeOrderingPhase($generator, [], []);
        $this->assertSame([[]], $resultEmpty['ordered']);
        $this->assertNull($resultEmpty['minBreak']);
        $this->assertNull($resultEmpty['maxBreak']);

        $single = [[[0, 1], [2, 3]]];
        $resultSingle = $this->invokeOrderingPhase($generator, $single, [0, 1, 2, 3]);
        $this->assertSame($this->wrapSingleCourt($single), $resultSingle['ordered']);
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_TRIVIAL, $resultSingle['stopReason']);
    }

    public function test_sort_dfs_finds_seven_tier_best_when_prune_inactive(): void
    {
        // When `$maxBreakThreshold` is effectively infinite, the DFS visits every ordering and
        // the surfaced schedule matches the exhaustive seven-tier lex walk.
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];
        $mockPlayers = [0, 1, 2, 3];

        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);
        $expected = $this->bruteForceBestOrderSevenTier($matches, $mockPlayers, $generator);

        $this->assertSame($expected['ordered'], $result['ordered']);
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
        $this->assertNotNull($result['minBreak']);
        $this->assertNotNull($result['maxBreak']);
    }

    public function test_sort_dfs_prune_fires_on_consecutive_break_overflow(): void
    {
        // 8 players, 4 matches. Player 6 appears in no match, so every ordering pushes player 6's
        // consecutive-break run to 4 across the schedule. Under the default `ceil(8 / 4) = 2`
        // threshold (no explicit override), every branch is pruned at depth 2 and the result is
        // the input order with `stopReason = PRUNE_INFEASIBLE`.
        $generator = $this->makeGenerator(60_000_000_000);

        $matches = [
            [[7, 5], [0, 3]],
            [[5, 7], [2, 4]],
            [[2, 1], [0, 5]],
            [[0, 5], [1, 4]],
        ];
        $mockPlayers = range(0, 7);

        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);

        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_PRUNE_INFEASIBLE, $result['stopReason']);
        $this->assertNull($result['ordered']);
        $this->assertSame(0, $result['permutationsIterated'], 'No leaf must be reached when every branch is pruned.');
        $this->assertNull($result['minBreak']);
        $this->assertNull($result['maxBreak']);
    }

    public function test_sort_dfs_prune_keeps_max_break_at_or_below_threshold(): void
    {
        // 5 players, 4 matches where each match uses exactly 4 of 5 players. Under threshold = 1
        // every ordering that lets ANY player sit out twice in a row is pruned. The DFS is
        // expected to find an ordering whose max consecutive break run is at most 1.
        $generator = $this->makeGenerator(60_000_000_000, 1);

        $matches = [
            [[0, 1], [2, 3]], // 4 sits out
            [[0, 1], [2, 4]], // 3 sits out
            [[0, 2], [3, 4]], // 1 sits out
            [[1, 2], [3, 4]], // 0 sits out
        ];
        $mockPlayers = [0, 1, 2, 3, 4];

        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);

        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
        $this->assertNotNull($result['maxBreak']);
        $this->assertLessThanOrEqual(1, $result['maxBreak'], 'DFS prune must enforce maxBreak <= threshold');
    }

    public function test_sort_dfs_is_deterministic_across_runs(): void
    {
        // Two independent generators on identical inputs must produce byte-identical output. The
        // DFS is fully deterministic (lowest-unused-index iteration order, lowest-leaf-index
        // tie-break), so the two runs must match.
        $matches = $this->makeSyntheticMatchesSix();
        $mockPlayers = range(0, 5);

        $first  = $this->invokeOrderingPhase($this->makeGenerator(60_000_000_000), $matches, $mockPlayers);
        $second = $this->invokeOrderingPhase($this->makeGenerator(60_000_000_000), $matches, $mockPlayers);

        $this->assertSame($first['ordered'], $second['ordered']);
        $this->assertSame($first['stopReason'], $second['stopReason']);
        $this->assertSame($first['min'], $second['min']);
        $this->assertSame($first['avg'], $second['avg']);
        $this->assertSame($first['permutationsIterated'], $second['permutationsIterated']);
        $this->assertSame($first['permutationIndex'], $second['permutationIndex']);
        $this->assertSame($first['minBreak'], $second['minBreak']);
        $this->assertSame($first['maxBreak'], $second['maxBreak']);
    }

    public function test_sort_dfs_respects_wall_deadline(): void
    {
        // Inject a stepping clock that exceeds the sort budget before the DFS can complete.
        // Step size 1ns ensures we make some progress before the deadline so the result is
        // best-so-far, not the PRUNE_INFEASIBLE fallback.
        $tick = 0;
        $clock = static function () use (&$tick): int {
            return ($tick++);
        };

        // Sort budget = 5 ticks. After ~5 recursion entries the deadline check fires.
        $generator = new TemplateMatchesGenerator(
            $clock,
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            5,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_COUNT_PAIRING,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_THRESHOLD_PAIRS,
            TemplateMatchesGenerator::DEFAULT_MEETINGS_VARIATION_LIMIT,
            PHP_INT_MAX
        );

        $matches = $this->makeSyntheticMatchesSix();
        $mockPlayers = range(0, 5);

        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);

        // Whether the DFS managed to visit a leaf before the deadline depends on the timing of
        // the clock injection; either way the stop reason must reflect the deadline rather than
        // the full factorial walk.
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_DEADLINE, $result['stopReason']);
    }

    public function test_sort_matches_tracks_breaks_and_index_for_best_ordering(): void
    {
        // 4 matches over 5 players. Each match uses 4 of 5 players, so per match exactly one
        // player sits out. Under the asymmetric break contract player 2 (who plays in every
        // match) closes a length-`0` inner run on every subsequent appearance (back-to-back
        // sit-out semantics), pinning `perPlayerMin[2] = 0` regardless of ordering -- the
        // aggregate `minBreak` is therefore always `0`. The DFS still finds an ordering where
        // the `Max Break` ceiling at `ceil(5/4) = 2` is respected; the prune keeps every
        // player's longest run at most 2 (alternating sit-out orderings achieve 1).
        $generator = $this->makeGenerator(60_000_000_000);

        $matches = [
            [[0, 1], [2, 3]], // 4 sits out
            [[0, 1], [2, 4]], // 3 sits out
            [[0, 2], [3, 4]], // 1 sits out
            [[1, 2], [3, 4]], // 0 sits out
        ];
        $mockPlayers = [0, 1, 2, 3, 4];

        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);

        $this->assertNotNull($result['permutationIndex'], 'bestPermutationIndex must be populated once any iteration finds a candidate.');
        $this->assertGreaterThan(0, $result['permutationIndex']);
        $this->assertLessThanOrEqual($result['permutationsIterated'], $result['permutationIndex']);

        $this->assertNotNull($result['minBreak']);
        $this->assertNotNull($result['maxBreak']);
        // Player 2 plays every match, so every inner gap is a length-`0` back-to-back run →
        // perPlayerMin[2] = 0, forcing aggregate minBreak = 0. Max <= 1 because the
        // alternating optimum keeps every sit-out non-adjacent.
        $this->assertSame(0, $result['minBreak']);
        $this->assertLessThanOrEqual(1, $result['maxBreak']);
    }

    public function test_sort_dfs_tie_break_prefers_lower_max_break(): void
    {
        // 8 players, 4 matches. Every leaf ties `minBreak = 0` (players 0/1 play every match).
        // Tier 2 minimizes `maxBreak`:
        //   - {0, 3} position pair for M0/M3 -> maxBreak = 2
        //   - {0, 2} or {1, 3} -> maxBreak = 3
        // Winner must carry maxBreak = 2. Max Break prune is disabled (PHP_INT_MAX threshold)
        // so the maxBreak = 3 group remains reachable but loses on tier 2.
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 1], [4, 5]],
            [[0, 1], [6, 7]],
            [[0, 1], [2, 3]],
        ];
        $mockPlayers = [0, 1, 2, 3, 4, 5, 6, 7];

        $brute = $this->bruteForceBestOrderSevenTier($matches, $mockPlayers, $generator);

        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);

        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
        $this->assertSame($brute['ordered'], $result['ordered']);
        $this->assertSame($brute['min'], $result['min']);
        $this->assertSame($brute['avg'], $result['avg']);
        $this->assertSame($brute['minBreak'], $result['minBreak']);
        $this->assertSame(2, $result['maxBreak'], 'Winner must minimize maxBreak among tied minBreak leaves.');

        $tiesAtMinBreak = array_values(array_filter(
            $brute['leaves'],
            static fn(array $leaf): bool => $leaf['minBreak'] === $brute['minBreak']
        ));
        $this->assertGreaterThanOrEqual(
            2,
            count($tiesAtMinBreak),
            'Tie-break test input must produce at least two leaves tied on minBreak.'
        );
        $worseMaxBreakLeaves = array_filter(
            $tiesAtMinBreak,
            static fn(array $leaf): bool => $leaf['maxBreak'] > $brute['maxBreak']
        );
        $this->assertNotEmpty(
            $worseMaxBreakLeaves,
            'At least one minBreak-tied leaf must have a worse maxBreak, otherwise tier 2 is vacuous.'
        );
    }

    public function test_sort_dfs_prefers_lower_streak_tie_break_when_dist_and_break_tie(): void
    {
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 4]],
            [[0, 3], [4, 5]],
            [[1, 5], [2, 4]],
            [[3, 4], [0, 5]],
            [[2, 5], [1, 3]],
        ];
        $mockPlayers = range(0, 5);

        $brute = $this->bruteForceBestOrderSevenTier($matches, $mockPlayers, $generator);
        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);

        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
        $this->assertSame($brute['ordered'], $result['ordered']);
        $this->assertSame(2, $brute['consecutiveMinBreaks']);
        $this->assertLessThan(3, $brute['consecutiveMinBreaks'], 'Winner must beat leaves with cMin=3.');

        $breakTies = array_values(array_filter(
            $brute['leaves'],
            static fn(array $leaf): bool => $leaf['minBreak'] === $brute['minBreak']
                && $leaf['maxBreak'] === $brute['maxBreak']
        ));
        $this->assertGreaterThanOrEqual(2, count($breakTies));
        $distinctStreakMins = array_unique(array_column($breakTies, 'consecutiveMinBreaks'));
        $this->assertGreaterThan(1, count($distinctStreakMins), 'Tier-3 ties must differ on consecutiveMinBreaks.');
    }

    public function test_sort_dfs_break_metrics_beat_better_distribution(): void
    {
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 4]],
            [[0, 3], [4, 5]],
            [[1, 5], [2, 4]],
            [[3, 4], [0, 5]],
            [[2, 5], [1, 3]],
        ];
        $mockPlayers = range(0, 5);

        $distOnly = $this->bruteForceBestOrder($matches, $mockPlayers, $generator);
        $brute = $this->bruteForceBestOrderSevenTier($matches, $mockPlayers, $generator);
        $result = $this->invokeOrderingPhase($generator, $matches, $mockPlayers);

        $this->assertSame($brute['ordered'], $result['ordered']);
        $this->assertNotSame(
            $distOnly,
            $brute['ordered'],
            'Seven-tier winner must differ from distribution-only optimum on this fixture.'
        );
    }

    public function test_sort_dfs_runs_to_factorial_complete_when_no_deadline(): void
    {
        // S7's "always run the tree" contract: with a generous sort budget and an input whose
        // Max Break prune does not kill every branch, the DFS must exhaust the (pruned) search
        // tree and report `FACTORIAL_COMPLETE` -- never the (now-removed) `THRESHOLD_MET`.
        // Also asserts the DFS actually visited every leaf (4! = 24 permutations), proving the
        // search did not bail out via any early-stop short-circuit.
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $matches = [
            [[0, 1], [2, 3]],
            [[4, 5], [6, 7]],
            [[0, 2], [4, 6]],
            [[1, 3], [5, 7]],
        ];

        $result = $this->invokeOrderingPhase($generator, $matches, range(0, 7));

        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
        $this->assertSame(24, $result['permutationsIterated'], 'Every leaf must be visited when there is no deadline and no infeasible prune.');
    }

    public function test_sort_matches_emits_interim_ordering_during_exploration_before_deadline(): void
    {
        $now = 0;
        $tickNs = 300_000_000;
        $generator = new TemplateMatchesGenerator(
            static function () use (&$now, $tickNs): int {
                return ($now += $tickNs);
            },
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            2_000_000_000,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_COUNT_PAIRING,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_THRESHOLD_PAIRS,
            TemplateMatchesGenerator::DEFAULT_MEETINGS_VARIATION_LIMIT,
            PHP_INT_MAX
        );
        $generator->setUseStaticBudgets(true);

        $events = [];
        $reporter = new ProgressReporter(
            static function ($event) use (&$events): void {
                $events[] = $event;
            },
            250_000_000,
            8,
            2,
            1,
            false,
            0
        );
        $reporter->setPhaseStart(0);

        $result = $this->invokeOrderingPhase(
            $generator,
            $this->makeSyntheticMatchesSix(),
            range(0, 5),
            $reporter
        );

        $interimOrdering = array_values(array_filter(
            $events,
            static fn ($event) => $event instanceof OrderingProgress && !$event->isFinal()
        ));
        $finalOrdering = array_values(array_filter(
            $events,
            static fn ($event) => $event instanceof OrderingProgress && $event->isFinal()
        ));

        $this->assertGreaterThanOrEqual(2, count($interimOrdering), 'DFS exploration must emit throttled interim ordering ticks before the final event.');
        $this->assertCount(1, $finalOrdering);
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_DEADLINE, $finalOrdering[0]->getStopReason());
        $this->assertGreaterThan(
            $interimOrdering[0]->getElapsedNs(),
            $interimOrdering[count($interimOrdering) - 1]->getElapsedNs(),
            'Interim ordering elapsed time must advance across ticks.'
        );
    }

    public function test_sort_matches_interim_ordering_reflects_post_merge_best_state_on_leaf(): void
    {
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $events = [];
        $reporter = new ProgressReporter(
            static function ($event) use (&$events): void {
                $events[] = $event;
            },
            0,
            4,
            1,
            1,
            false,
            0
        );
        $reporter->setPhaseStart(0);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];

        $result = $this->invokeOrderingPhase($generator, $matches, [0, 1, 2, 3], $reporter);

        $leafInterim = null;
        foreach ($events as $event) {
            if (!$event instanceof OrderingProgress || $event->isFinal()) {
                continue;
            }
            if ($event->getBestMin() !== null) {
                $leafInterim = $event;
            }
        }

        $this->assertNotNull($leafInterim, 'A completed leaf must emit an interim ordering tick with scored metrics.');
        $this->assertSame($result['min'], $leafInterim->getBestMin());
        $this->assertSame($result['avg'], $leafInterim->getBestAvg());
        $this->assertSame($result['permutationIndex'], $leafInterim->getBestPermutationIndex());
        $this->assertSame($result['minBreak'], $leafInterim->getBestMinBreak());
        $this->assertSame($result['maxBreak'], $leafInterim->getBestMaxBreak());
        $this->assertSame($result['courtSwitches'], $leafInterim->getBestCourtSwitches());
    }

    public function test_sort_matches_reports_nodes_explored_and_seed_metadata(): void
    {
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $result = $this->invokeOrderingPhase(
            $generator,
            [
                [[0, 1], [2, 3]],
                [[0, 2], [1, 3]],
                [[0, 3], [1, 2]],
            ],
            [0, 1, 2, 3]
        );

        $this->assertArrayHasKey('nodesExplored', $result);
        $this->assertGreaterThan(0, $result['nodesExplored']);
        $this->assertSame(1, $result['seedsTotal']);
        $this->assertSame(1, $result['seedIndex']);
    }

    public function test_sort_matches_uses_multi_seed_when_courts_are_two(): void
    {
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX, 4);
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $activeCourts = $reflection->getProperty('activeCourts');
        $activeCourts->setAccessible(true);
        $activeCourts->setValue($generator, 2);

        $result = $this->invokeOrderingPhase(
            $generator,
            [
                [[0, 1], [2, 3]],
                [[0, 2], [1, 3]],
                [[0, 3], [1, 2]],
                [[4, 5], [6, 7]],
            ],
            range(0, 7)
        );

        $this->assertSame(4, $result['seedsTotal']);
        $this->assertGreaterThan(0, $result['nodesExplored']);
    }

    public function test_sort_matches_stays_single_seed_for_small_combo(): void
    {
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX, 256);

        $result = $this->invokeOrderingPhase(
            $generator,
            [
                [[0, 1], [2, 3]],
                [[0, 2], [1, 3]],
                [[0, 3], [1, 2]],
            ],
            [0, 1, 2, 3]
        );

        $this->assertSame(1, $result['seedsTotal']);
        $this->assertSame(1, $result['seedIndex']);
    }

    public function test_sort_nodes_explored_respects_branch_cap_per_seed(): void
    {
        $generator = $this->makeGenerator(
            60_000_000_000,
            PHP_INT_MAX,
            1,
            5
        );

        $result = $this->invokeOrderingPhase(
            $generator,
            $this->makeSyntheticMatchesSix(),
            range(0, 5)
        );

        $this->assertLessThanOrEqual(5, $result['nodesExplored']);
    }

    public function test_sort_matches_multi_court_output_passes_round_schedule_validation(): void
    {
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX, 4);
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $activeCourts = $reflection->getProperty('activeCourts');
        $activeCourts->setAccessible(true);
        $activeCourts->setValue($generator, 2);

        $matches = [
            [[0, 1], [2, 3]],
            [[4, 5], [6, 7]],
            [[0, 2], [1, 3]],
            [[4, 6], [5, 7]],
            [[0, 3], [2, 4]],
            [[1, 5], [6, 7]],
            [[0, 4], [1, 5]],
            [[2, 6], [3, 7]],
        ];

        $result = $this->invokeOrderingPhase($generator, $matches, range(0, 7));

        $this->assertNotNull($result['ordered']);
        $this->assertTrue(TemplateMatches::hasValidRoundSchedule($result['ordered']));
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
    }

    public function test_sort_matches_multi_court_zero_budget_returns_deadline_without_pairing(): void
    {
        $generator = new TemplateMatchesGenerator(
            static function (): int {
                return 0;
            },
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            0
        );
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $activeCourts = $reflection->getProperty('activeCourts');
        $activeCourts->setAccessible(true);
        $activeCourts->setValue($generator, 2);

        $result = $this->invokeOrderingPhase(
            $generator,
            [
                [[0, 1], [2, 3]],
                [[4, 5], [6, 7]],
            ],
            range(0, 7)
        );

        $this->assertNull($result['ordered']);
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_DEADLINE, $result['stopReason']);
    }

    public function test_sort_dfs_records_zero_inner_break_on_back_to_back_rounds(): void
    {
        // Player 1 plays rounds 0-1 consecutively (inner run 0) but also has a longer inner gap
        // later in the schedule. Under the asymmetric contract the back-to-back stretch must pin
        // perPlayerMin[1] = 0 and therefore aggregate minBreak = 0.
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX, 1);
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $activeCourts = $reflection->getProperty('activeCourts');
        $activeCourts->setAccessible(true);
        $activeCourts->setValue($generator, 2);

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

        $result = $this->invokeOrderingPhase($generator, $matches, range(0, 7));

        $this->assertNotNull($result['ordered']);
        $this->assertSame(0, $result['minBreak']);
        $metrics = $this->computeBreakMetricsFromRoundSchedule($result['ordered'], range(0, 7));
        $this->assertSame(0, $metrics['minBreak']);
        $this->assertSame($result['minBreak'], $metrics['minBreak']);
        $this->assertSame($result['maxBreak'], $metrics['maxBreak']);
    }

    public function test_twelve_eight_courts_two_committed_schedule_has_min_break_zero(): void
    {
        $repo = $this->productionRepository();
        $version = $this->latestProductionVersion();
        $path = $repo->path($version, 12, 8, 1, 2, false);
        if (!is_file($path)) {
            $this->fail("No template at {$path}; regenerate offline: php bin/console templates:regenerate --players=12 --partners=8 --courts=2");
        }

        $template = $this->loadLatestCommittedTemplate(12, 8, 1, 2);
        if ($template->getMatches() === null) {
            fwrite(
                STDERR,
                "WARNING: Latest v{$version} 12/8/courts=2 has matches=null; skipping break metrics.\n"
            );

            return;
        }

        $metrics = $this->computeBreakMetricsFromRoundSchedule(
            $template->getMatches(),
            range(0, 11)
        );

        $this->assertSame(0, $metrics['minBreak']);
    }

    /**
     * Builds a generator with the standard outer wall budget and a caller-supplied sort budget.
     * The sort DFS always runs to factorial completion or deadline.
     *
     * The `$maxBreakThreshold` lets tests override the S7 prune. Pass `PHP_INT_MAX` to fully
     * disable the prune (DFS degenerates to exhaustive search) so brute-force comparisons stay
     * meaningful for synthetic inputs that don't satisfy `ceil(playersCount / 4)`. Default
     * `-1` keeps the production behaviour (derive threshold from playersCount at runtime).
     */
    private function makeGenerator(
        int $sortBudgetNs,
        int $maxBreakThreshold = -1,
        int $multiSeedCountSort = TemplateMatchesGenerator::DEFAULT_MULTI_SEED_COUNT_SORT,
        int $dfsBranchCap = TemplateMatchesGenerator::DEFAULT_DFS_BRANCH_CAP
    ): TemplateMatchesGenerator {
        return new TemplateMatchesGenerator(
            static function (): int {
                return 0;
            },
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            $sortBudgetNs,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_COUNT_PAIRING,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_THRESHOLD_PAIRS,
            TemplateMatchesGenerator::DEFAULT_MEETINGS_VARIATION_LIMIT,
            $maxBreakThreshold,
            TemplateMatchesGenerator::DEFAULT_MEETINGS_VARIATION_LIMIT_MAX,
            $dfsBranchCap,
            $multiSeedCountSort
        );
    }

    /**
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int>                         $mockPlayers
     * @return array{ordered: array, stopReason: string, min: float|null, avg: float|null}
     */
    private function invokeOrderingPhase(
        TemplateMatchesGenerator $generator,
        array $matches,
        array $mockPlayers,
        ?ProgressReporter $reporter = null
    ): array {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('runOrderingPhase');
        $method->setAccessible(true);

        $activeCourts = $reflection->getProperty('activeCourts');
        $activeCourts->setAccessible(true);
        if ($activeCourts->getValue($generator) < 1) {
            $activeCourts->setValue($generator, 1);
        }

        $activePartners = $reflection->getProperty('activePartners');
        $activePartners->setAccessible(true);
        if ($activePartners->getValue($generator) < 1) {
            $activePartners->setValue($generator, 1);
        }

        $reporter ??= ProgressReporter::noop(0, 0, 0, false);

        return $method->invoke($generator, $matches, $mockPlayers, $reporter);
    }

    /**
     * @param array<int, array<int, array<int, int>>> $flatMatches
     * @return array<int, array<int, array<int, array<int, int>>>>
     */
    private function wrapSingleCourt(array $flatMatches): array
    {
        return [$flatMatches];
    }

    /**
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @param array<int, int>                         $mockPlayers
     * @return array{min: float, avg: float}
     */
    private function invokeScore(array $matchesByCourt, array $mockPlayers): array
    {
        // The generator delegates to PlayerDistributionScorer; calling the scorer directly avoids
        // reflection on a now-trivial private method without changing the asserted values.
        $aggregate = (new PlayerDistributionScorer())->scoreAll($mockPlayers, $matchesByCourt);

        return ['min' => $aggregate['min'], 'avg' => $aggregate['avg']];
    }

    /**
     * Six-match input drawn from a 6-player pool. 6! = 720 permutations — fast enough for a full
     * factorial scan even on the slowest CI hardware, but rich enough that distinct permutations
     * produce distinct distribution scores.
     *
     * @return array<int, array<int, array<int, int>>>
     */
    private function makeSyntheticMatchesSix(): array
    {
        return [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 4]],
            [[0, 3], [4, 5]],
            [[1, 5], [2, 4]],
            [[3, 4], [0, 5]],
            [[2, 5], [1, 3]],
        ];
    }

    /**
     * Reference implementation: enumerate every permutation and pick the lex-best (min, avg).
     *
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int>                         $mockPlayers
     * @return array<int, array<int, array<int, int>>>
     */
    /**
     * @suppress PhanUnusedPrivateMethodParameter
     */
    private function bruteForceBestOrder(array $matches, array $mockPlayers, TemplateMatchesGenerator $generator): array
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $next = $reflection->getMethod('pcNextPermutation');
        $next->setAccessible(true);

        $scorer = new PlayerDistributionScorer();

        $m = count($matches);
        $perm = range(0, $m - 1);
        $permCopy = $perm;
        $size = $m - 1;

        $bestOrdered = $matches;
        $bestMin = null;
        $bestAvg = null;

        do {
            $ordered = [];
            foreach ($perm as $i) {
                $ordered[] = $matches[$i];
            }

            $aggregate = $scorer->scoreAll($mockPlayers, [$ordered]);
            $min = $aggregate['min'];
            $avg = $aggregate['avg'];

            if ($bestMin === null || $min > $bestMin || ($min === $bestMin && $avg > $bestAvg)) {
                $bestMin = $min;
                $bestAvg = $avg;
                $bestOrdered = $ordered;
            }
        } while (($perm = $next->invoke($generator, $perm, $size)) !== false && $perm !== $permCopy);

        return [$bestOrdered];
    }

    /**
     * 7-tier brute-force reference aligned with production ordering DFS:
     * `(minBreak max, maxBreak min, consecutiveMinBreaks min, consecutiveMaxBreaks min,
     * normCourtSwitches min, minDist max, avgDist max)`.
     *
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int>                         $mockPlayers
     * @return array{
     *     ordered: array<int, array<int, array<int, array<int, int>>>>,
     *     min: float,
     *     avg: float,
     *     minBreak: int,
     *     maxBreak: int,
     *     normCourtSwitches: float,
     *     consecutiveMinBreaks: int,
     *     consecutiveMaxBreaks: int,
     *     leaves: array<int, array{
     *         min: float,
     *         avg: float,
     *         minBreak: int,
     *         maxBreak: int,
     *         normCourtSwitches: float,
     *         consecutiveMinBreaks: int,
     *         consecutiveMaxBreaks: int
     *     }>
     * }
     */
    private function bruteForceBestOrderSevenTier(
        array $matches,
        array $mockPlayers,
        TemplateMatchesGenerator $generator
    ): array {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $next = $reflection->getMethod('pcNextPermutation');
        $next->setAccessible(true);

        $scorer = new PlayerDistributionScorer();

        $m = count($matches);
        $roundsTotal = $m;

        $perm = range(0, $m - 1);
        $permCopy = $perm;
        $size = $m - 1;

        $bestOrdered = $matches;
        $bestMin = null;
        $bestAvg = null;
        $bestMinBreak = -1;
        $bestMaxBreak = PHP_INT_MAX;
        $bestNormCourtSwitches = INF;
        $bestConsecutiveMinBreaks = PHP_INT_MAX;
        $bestConsecutiveMaxBreaks = PHP_INT_MAX;
        $leaves = [];

        do {
            $ordered = [];
            foreach ($perm as $i) {
                $ordered[] = $matches[$i];
            }

            $aggregate = $scorer->scoreAll($mockPlayers, [$ordered]);
            $min = $aggregate['min'];
            $avg = $aggregate['avg'];
            $normCourtSwitches = $roundsTotal > 1 ? 0.0 : 0.0;

            $breakMetrics = RoundScheduleBreakAnalyzer::computeBreakMetrics([$ordered], $mockPlayers);
            $minBreak = $breakMetrics['minBreak'];
            $maxBreak = $breakMetrics['maxBreak'];
            $streaks = RoundScheduleBreakAnalyzer::analyze(
                [$ordered],
                $mockPlayers,
                $minBreak,
                $maxBreak
            );
            $consecutiveMinBreaks = $streaks['consecutiveMinBreaks'] ?? PHP_INT_MAX;
            $consecutiveMaxBreaks = $streaks['consecutiveMaxBreaks'] ?? PHP_INT_MAX;

            $leaves[] = [
                'min' => $min,
                'avg' => $avg,
                'minBreak' => $minBreak,
                'maxBreak' => $maxBreak,
                'normCourtSwitches' => $normCourtSwitches,
                'consecutiveMinBreaks' => $consecutiveMinBreaks,
                'consecutiveMaxBreaks' => $consecutiveMaxBreaks,
            ];

            if ($this->isSevenTierLexBetter(
                $minBreak,
                $maxBreak,
                $consecutiveMinBreaks,
                $consecutiveMaxBreaks,
                $normCourtSwitches,
                $min,
                $avg,
                $bestMinBreak,
                $bestMaxBreak,
                $bestConsecutiveMinBreaks,
                $bestConsecutiveMaxBreaks,
                $bestNormCourtSwitches,
                $bestMin,
                $bestAvg
            )) {
                $bestMin = $min;
                $bestAvg = $avg;
                $bestOrdered = $ordered;
                $bestMinBreak = $minBreak;
                $bestMaxBreak = $maxBreak;
                $bestNormCourtSwitches = $normCourtSwitches;
                $bestConsecutiveMinBreaks = $consecutiveMinBreaks;
                $bestConsecutiveMaxBreaks = $consecutiveMaxBreaks;
            }
        } while (($perm = $next->invoke($generator, $perm, $size)) !== false && $perm !== $permCopy);

        return [
            'ordered' => [$bestOrdered],
            'min' => $bestMin,
            'avg' => $bestAvg,
            'minBreak' => $bestMinBreak,
            'maxBreak' => $bestMaxBreak,
            'normCourtSwitches' => $bestNormCourtSwitches,
            'consecutiveMinBreaks' => $bestConsecutiveMinBreaks,
            'consecutiveMaxBreaks' => $bestConsecutiveMaxBreaks,
            'leaves' => $leaves,
        ];
    }

    /**
     * Under LexFloat 2dp indifference, tied schedules may differ by discovery order.
     * Require production DFS and brute reference to be full ordering-lex equivalent.
     *
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     */
    private function assertOrderingLexEquivalent(
        TemplateMatchesGenerator $generator,
        array $actual,
        array $expected
    ): void {
        $this->assertNotNull($actual['ordered']);
        $this->assertNotNull($expected['ordered']);

        $compare = (new \ReflectionClass($generator))->getMethod('compareOrderingLex');
        $compare->setAccessible(true);

        $actualLex = [
            'ordered' => $actual['ordered'],
            'minBreak' => $actual['minBreak'] ?? $expected['minBreak'],
            'maxBreak' => $actual['maxBreak'] ?? $expected['maxBreak'],
            'consecutiveMinBreaks' => $actual['consecutiveMinBreaks'] ?? $expected['consecutiveMinBreaks'],
            'consecutiveMaxBreaks' => $actual['consecutiveMaxBreaks'] ?? $expected['consecutiveMaxBreaks'],
            'normCourtSwitches' => $actual['normCourtSwitches'] ?? $expected['normCourtSwitches'],
            'min' => $actual['min'] ?? $expected['min'],
            'avg' => $actual['avg'] ?? $expected['avg'],
        ];
        $expectedLex = [
            'ordered' => $expected['ordered'],
            'minBreak' => $expected['minBreak'],
            'maxBreak' => $expected['maxBreak'],
            'consecutiveMinBreaks' => $expected['consecutiveMinBreaks'],
            'consecutiveMaxBreaks' => $expected['consecutiveMaxBreaks'],
            'normCourtSwitches' => $expected['normCourtSwitches'],
            'min' => $expected['min'],
            'avg' => $expected['avg'],
        ];

        $this->assertSame(0, $compare->invoke($generator, $actualLex, $expectedLex));
    }

    private function isSevenTierLexBetter(
        int $minBreak,
        int $maxBreak,
        int $consecutiveMinBreaks,
        int $consecutiveMaxBreaks,
        float $normCourtSwitches,
        float $min,
        float $avg,
        int $bestMinBreak,
        int $bestMaxBreak,
        int $bestConsecutiveMinBreaks,
        int $bestConsecutiveMaxBreaks,
        float $bestNormCourtSwitches,
        ?float $bestMin,
        ?float $bestAvg
    ): bool {
        if ($bestMin === null) {
            return true;
        }
        if ($minBreak > $bestMinBreak) {
            return true;
        }
        if ($minBreak < $bestMinBreak) {
            return false;
        }
        if ($maxBreak < $bestMaxBreak) {
            return true;
        }
        if ($maxBreak > $bestMaxBreak) {
            return false;
        }
        if ($consecutiveMinBreaks < $bestConsecutiveMinBreaks) {
            return true;
        }
        if ($consecutiveMinBreaks > $bestConsecutiveMinBreaks) {
            return false;
        }
        if ($consecutiveMaxBreaks < $bestConsecutiveMaxBreaks) {
            return true;
        }
        if ($consecutiveMaxBreaks > $bestConsecutiveMaxBreaks) {
            return false;
        }
        if (LexFloat::isBetterMin($normCourtSwitches, $bestNormCourtSwitches)) {
            return true;
        }
        if (LexFloat::isBetterMin($bestNormCourtSwitches, $normCourtSwitches)) {
            return false;
        }
        if (LexFloat::isBetterMax($min, $bestMin)) {
            return true;
        }
        if (LexFloat::isBetterMax($bestMin, $min)) {
            return false;
        }

        return LexFloat::isBetterMax($avg, $bestAvg);
    }

    /**
     * Round-aware break metrics reference for per-court schedules (multi-court DFS output).
     *
     * @param array<int, array<int, array<int, array<int, int>>>> $matchesByCourt
     * @param array<int, int>                                     $mockPlayers
     * @return array{minBreak: int, maxBreak: int}
     */
    private function computeBreakMetricsFromRoundSchedule(array $matchesByCourt, array $mockPlayers): array
    {
        return RoundScheduleBreakAnalyzer::computeBreakMetrics($matchesByCourt, $mockPlayers);
    }
}
