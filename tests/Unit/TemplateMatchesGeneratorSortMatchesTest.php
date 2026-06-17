<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\ProgressReporter;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Unit tests for the (now backtracking DFS) sortMatches() implementation.
 *
 * The sortMatches method is private; tests reach it via reflection. All search-bound knobs
 * (clock, wall budgets) are constructor-injected on the new pure generator, so no static
 * reflection is needed.
 */
final class TemplateMatchesGeneratorSortMatchesTest extends TestCase
{
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

        $result = $this->invokeSortMatches($generator, $matches, [0, 1, 2, 3]);

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

        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);
        $expected = $this->bruteForceBestOrder($matches, $mockPlayers, $generator);

        $this->assertSame($expected, $result['ordered']);
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

        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);
        $expected = $this->bruteForceBestOrder($matches, $mockPlayers, $generator);

        $this->assertSame($expected, $result['ordered']);
    }

    public function test_sort_matches_returns_factorial_complete_when_thresholds_unreachable(): void
    {
        $generator = $this->makeGenerator(60_000_000_000);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];

        $result = $this->invokeSortMatches($generator, $matches, [0, 1, 2, 3]);

        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
    }

    public function test_lex_scan_does_not_worsen_minimum_distribution_vs_identity(): void
    {
        $generator = $this->makeGenerator(60_000_000_000);

        $matches = $this->makeSyntheticMatchesSix();
        $mockPlayers = range(0, 5);

        $identityScores = $this->invokeScore($this->wrapSingleCourt($matches), $mockPlayers);
        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);
        $optimizedScores = $this->invokeScore($result['ordered'], $mockPlayers);

        $this->assertGreaterThanOrEqual($identityScores['min'], $optimizedScores['min']);
    }

    public function test_sort_matches_returns_input_unchanged_when_match_count_is_one_or_zero(): void
    {
        $generator = $this->makeGenerator(1_000_000_000);

        $resultEmpty = $this->invokeSortMatches($generator, [], []);
        $this->assertSame([[]], $resultEmpty['ordered']);
        $this->assertNull($resultEmpty['minBreak']);
        $this->assertNull($resultEmpty['maxBreak']);

        $single = [[[0, 1], [2, 3]]];
        $resultSingle = $this->invokeSortMatches($generator, $single, [0, 1, 2, 3]);
        $this->assertSame($this->wrapSingleCourt($single), $resultSingle['ordered']);
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_TRIVIAL, $resultSingle['stopReason']);
    }

    public function test_sort_dfs_finds_best_min_dist_when_prune_inactive(): void
    {
        // Mirror of the four-player brute-force scan but framed as the S7 "prune-inactive"
        // contract: when `$maxBreakThreshold` is effectively infinite, the DFS visits every
        // ordering and the surfaced `(min, avg)` matches the exhaustive lex walk.
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 2], [1, 3]],
            [[0, 3], [1, 2]],
        ];
        $mockPlayers = [0, 1, 2, 3];

        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);
        $expected = $this->bruteForceBestOrder($matches, $mockPlayers, $generator);

        $this->assertSame($expected, $result['ordered']);
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

        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);

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

        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);

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

        $first  = $this->invokeSortMatches($this->makeGenerator(60_000_000_000), $matches, $mockPlayers);
        $second = $this->invokeSortMatches($this->makeGenerator(60_000_000_000), $matches, $mockPlayers);

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

        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);

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

        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);

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

    public function test_sort_dfs_tie_break_prefers_break_avg_closer_to_target(): void
    {
        // 8 players, 4 matches. Players 0 and 1 occupy the first slot of EVERY match, so they
        // play in all 4 positions and always score 1.0; under sit-out semantics each of their
        // 3 subsequent appearances closes a length-`0` back-to-back inner run, pinning
        // `perPlayerMin[0] = perPlayerMin[1] = 0` for every ordering (and thereby forcing
        // aggregate `minBreak = 0` on every leaf). Players 2 and 3 occupy the second slot of
        // M0 and M3 (identical matches); players 4-5 only appear in M1; players 6-7 only
        // appear in M2. The tied-at-top set carries 12 distinct permutations (every
        // (pos(M0), pos(M3)) pair from `{0,3}, {0,2}, {1,3}` keeps P2,P3 inside the neutral
        // band, and the remaining 4 single-match players always score 1.0 by the count<=1
        // short-circuit). Target break-balance = `m / playerMatches` = `4 / ((4 * 4) / 8)` =
        // `2.0`.
        //
        // The 12 tied leaves split by `(M0, M3)` position pair:
        //   - {0, 3} -- inner sit-outs for P2/P3 = 2, no edges -> max break = 2 -> break-avg
        //     1.0 -> distance 1.0.
        //   - {0, 2} or {1, 3} -- one of M1/M2 lands at an endpoint (pos 0 or 3), forcing a
        //     `lead = 3` or `trail = 3` run for the 1-match players in that match -> max break
        //     = 3 -> break-avg 1.5 -> distance 0.5  <- winner.
        //
        // The DFS visits leaves in lex order; the first distance-0.5 leaf encountered wins
        // because subsequent distance-0.5 leaves don't beat it on any tier (strict-better rule
        // preserves determinism). The lex-first tied leaf has distance 1.0 (perm `[0, 1, 2, 3]`,
        // pair `{0, 3}`); the next tied leaf the DFS finds is `[0, 1, 3, 2]` (pair `{0, 2}`)
        // with distance 0.5, which becomes and stays the winner. The Max Break prune is
        // disabled so the distance-0.5 group (which carries `maxBreak = 3 > ceil(8 / 4) = 2`)
        // is reachable.
        $generator = $this->makeGenerator(60_000_000_000, PHP_INT_MAX);

        $matches = [
            [[0, 1], [2, 3]],
            [[0, 1], [4, 5]],
            [[0, 1], [6, 7]],
            [[0, 1], [2, 3]],
        ];
        $mockPlayers = [0, 1, 2, 3, 4, 5, 6, 7];

        $brute = $this->bruteForceBestOrderThreeTier($matches, $mockPlayers, $generator);

        $result = $this->invokeSortMatches($generator, $matches, $mockPlayers);

        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE, $result['stopReason']);
        $this->assertSame($brute['ordered'], $result['ordered']);
        $this->assertSame($brute['min'], $result['min']);
        $this->assertSame($brute['avg'], $result['avg']);
        $this->assertSame($brute['minBreak'], $result['minBreak']);
        $this->assertSame($brute['maxBreak'], $result['maxBreak']);

        // Direct break-balance assertion: the winner must sit in the dist-0.5 group, i.e. its
        // average of (minBreak, maxBreak) is 1.5 -- the closest reachable approximation of the
        // per-player target of 2.0 once `minBreak = 0` is forced by the four 1-appearance
        // players. The losing group (avg = 1.0) is the trap the 3rd-tier compare must avoid.
        $winnerBreakAvg = ($result['minBreak'] + $result['maxBreak']) / 2.0;
        $this->assertSame(1.5, $winnerBreakAvg, 'Winner must be in the break-avg closest to target.');

        // Confirm the 3rd tier had real work to do: ≥ 2 leaves tie on (min, avg) and at least
        // one of those tied leaves has a strictly larger break-balance distance than the winner.
        $tiesAtTopTwo = array_values(array_filter(
            $brute['leaves'],
            static fn(array $leaf): bool => $leaf['min'] === $brute['min'] && $leaf['avg'] === $brute['avg']
        ));
        $this->assertGreaterThanOrEqual(
            2,
            count($tiesAtTopTwo),
            'Tie-break test input must produce at least two leaves tied on (min, avg).'
        );
        $worseBreakLeaves = array_filter(
            $tiesAtTopTwo,
            static fn(array $leaf): bool => $leaf['breakDistance'] > $brute['breakDistance']
        );
        $this->assertNotEmpty(
            $worseBreakLeaves,
            'At least one (min, avg)-tied leaf must have a worse break-balance distance, otherwise the 3rd-tier compare is vacuous.'
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

        $result = $this->invokeSortMatches($generator, $matches, range(0, 7));

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

        $result = $this->invokeSortMatches(
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

        $result = $this->invokeSortMatches($generator, $matches, [0, 1, 2, 3], $reporter);

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

        $result = $this->invokeSortMatches(
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

        $result = $this->invokeSortMatches(
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

        $result = $this->invokeSortMatches(
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

        $result = $this->invokeSortMatches(
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

        $result = $this->invokeSortMatches($generator, $matches, range(0, 7));

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

        $result = $this->invokeSortMatches(
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

        $result = $this->invokeSortMatches($generator, $matches, range(0, 7));

        $this->assertNotNull($result['ordered']);
        $this->assertSame(0, $result['minBreak']);
        $metrics = $this->computeBreakMetricsFromRoundSchedule($result['ordered'], range(0, 7));
        $this->assertSame(0, $metrics['minBreak']);
        $this->assertSame($result['minBreak'], $metrics['minBreak']);
        $this->assertSame($result['maxBreak'], $metrics['maxBreak']);
    }

    public function test_twelve_eight_courts_two_committed_schedule_has_min_break_zero(): void
    {
        $repo = new TemplateMatchesRepository();
        $path = $repo->path(5, 12, 8, 1, 2, false);
        if (!is_file($path)) {
            $this->markTestSkipped('v5 12/8/courts=2 template not present on disk.');
        }

        $template = $repo->findAt(5, 12, 8, 1, 2, false);
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
    private function invokeSortMatches(
        TemplateMatchesGenerator $generator,
        array $matches,
        array $mockPlayers,
        ?ProgressReporter $reporter = null
    ): array {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('sortMatches');
        $method->setAccessible(true);

        $activeCourts = $reflection->getProperty('activeCourts');
        $activeCourts->setAccessible(true);
        if ($activeCourts->getValue($generator) < 1) {
            $activeCourts->setValue($generator, 1);
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
     * 3-tier brute-force reference: enumerates every permutation and picks the lex-best with
     * `(Min Dist desc, Avg Dist desc, breakDistance asc, leaf-index asc)`. Returns the winning
     * ordering together with its metrics plus the full per-leaf trace, so the caller can assert
     * that at least one tied-at-top-two leaf had a strictly worse break-balance distance.
     *
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int>                         $mockPlayers
     * @return array{
     *     ordered: array<int, array<int, array<int, int>>>,
     *     min: float,
     *     avg: float,
     *     minBreak: int,
     *     maxBreak: int,
     *     breakDistance: float,
     *     leaves: array<int, array{min: float, avg: float, breakDistance: float}>
     * }
     */
    private function bruteForceBestOrderThreeTier(array $matches, array $mockPlayers, TemplateMatchesGenerator $generator): array
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $next = $reflection->getMethod('pcNextPermutation');
        $next->setAccessible(true);

        $scorer = new PlayerDistributionScorer();

        $m = count($matches);
        $playersCount = count($mockPlayers);
        $playerMatches = $playersCount > 0 ? ($m * 4) / $playersCount : 0.0;
        $targetBreakAvg = $playerMatches > 0 ? ($m / $playerMatches) : 0.0;

        $perm = range(0, $m - 1);
        $permCopy = $perm;
        $size = $m - 1;

        $bestOrdered = $matches;
        $bestMin = null;
        $bestAvg = null;
        $bestMinBreak = 0;
        $bestMaxBreak = 0;
        $bestBreakDistance = INF;
        $leaves = [];

        do {
            $ordered = [];
            foreach ($perm as $i) {
                $ordered[] = $matches[$i];
            }

            $aggregate = $scorer->scoreAll($mockPlayers, [$ordered]);
            $min = $aggregate['min'];
            $avg = $aggregate['avg'];

            // Asymmetric break metrics per the new contract:
            //   perPlayerMin[p] = shortest INNER break run (0 if no inner runs)
            //   perPlayerMax[p] = longest break run anywhere (lead + inner + trail)
            //   minBreak = min over players of perPlayerMin[p]
            //   maxBreak = max over players of perPlayerMax[p]
            $perPlayerMin = [];
            $perPlayerMax = [];
            foreach ($mockPlayers as $p) {
                $currentRun = 0;
                $longest = 0;
                $hasPlayed = false;
                $innerRuns = [];
                foreach ($ordered as $match) {
                    $seats = [
                        $match[0][0] ?? null,
                        $match[0][1] ?? null,
                        $match[1][0] ?? null,
                        $match[1][1] ?? null,
                    ];
                    $playsThisMatch = in_array((int) $p, array_map(static fn($x) => (int) $x, array_filter($seats, static fn($x) => $x !== null)), true);
                    if ($playsThisMatch) {
                        if ($hasPlayed) {
                            // Subsequent appearance: record the inner run (length 0 for
                            // back-to-back appearances, matching the production tracker's
                            // sit-out semantics).
                            $innerRuns[] = $currentRun;
                        }
                        if ($currentRun > $longest) {
                            $longest = $currentRun;
                        }
                        $hasPlayed = true;
                        $currentRun = 0;
                    } else {
                        $currentRun++;
                    }
                }
                if ($currentRun > $longest) {
                    $longest = $currentRun;
                }
                $perPlayerMin[] = empty($innerRuns) ? 0 : min($innerRuns);
                $perPlayerMax[] = $longest;
            }
            $minBreak = min($perPlayerMin);
            $maxBreak = max($perPlayerMax);
            $breakDistance = abs((($minBreak + $maxBreak) / 2.0) - $targetBreakAvg);

            $leaves[] = ['min' => $min, 'avg' => $avg, 'breakDistance' => $breakDistance];

            if (
                $bestMin === null
                || $min > $bestMin
                || ($min === $bestMin && $avg > $bestAvg)
                || ($min === $bestMin && $avg === $bestAvg && $breakDistance < $bestBreakDistance)
            ) {
                $bestMin = $min;
                $bestAvg = $avg;
                $bestOrdered = $ordered;
                $bestMinBreak = $minBreak;
                $bestMaxBreak = $maxBreak;
                $bestBreakDistance = $breakDistance;
            }
        } while (($perm = $next->invoke($generator, $perm, $size)) !== false && $perm !== $permCopy);

        return [
            'ordered' => [$bestOrdered],
            'min' => $bestMin,
            'avg' => $bestAvg,
            'minBreak' => $bestMinBreak,
            'maxBreak' => $bestMaxBreak,
            'breakDistance' => $bestBreakDistance,
            'leaves' => $leaves,
        ];
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
        $roundsTotal = 0;
        foreach ($matchesByCourt as $courtRounds) {
            $roundsTotal = max($roundsTotal, count($courtRounds));
        }

        $perPlayerMin = [];
        $perPlayerMax = [];
        foreach ($mockPlayers as $p) {
            $currentRun = 0;
            $longest = 0;
            $hasPlayed = false;
            $innerRuns = [];
            for ($r = 0; $r < $roundsTotal; $r++) {
                $playsThisRound = false;
                foreach ($matchesByCourt as $courtRounds) {
                    if (!isset($courtRounds[$r])) {
                        continue;
                    }
                    $match = $courtRounds[$r];
                    $seats = [
                        (int) $match[0][0],
                        (int) $match[0][1],
                        (int) $match[1][0],
                        (int) $match[1][1],
                    ];
                    if (in_array((int) $p, $seats, true)) {
                        $playsThisRound = true;
                        break;
                    }
                }
                if ($playsThisRound) {
                    if ($hasPlayed) {
                        $innerRuns[] = $currentRun;
                    }
                    if ($currentRun > $longest) {
                        $longest = $currentRun;
                    }
                    $hasPlayed = true;
                    $currentRun = 0;
                } else {
                    $currentRun++;
                }
            }
            if ($currentRun > $longest) {
                $longest = $currentRun;
            }
            $perPlayerMin[] = $innerRuns === [] ? 0 : min($innerRuns);
            $perPlayerMax[] = $longest;
        }

        return [
            'minBreak' => min($perPlayerMin),
            'maxBreak' => max($perPlayerMax),
        ];
    }
}
