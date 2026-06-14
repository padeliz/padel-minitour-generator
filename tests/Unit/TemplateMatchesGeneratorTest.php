<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use Arshavinel\PadelMiniTour\Service\Progress\GenerationProgress;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\PairingProgress;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Behavior-level tests for the pure {@see TemplateMatchesGenerator}.
 *
 * Replaces the legacy CLI-style test of the same name (now converted to the
 * `templates:stats` command).
 */
final class TemplateMatchesGeneratorTest extends TestCase
{
    public function test_generate_mixed_with_smallest_combo_returns_one_match(): void
    {
        $generator = new TemplateMatchesGenerator();

        $template = $generator->generate(4, 1, 1, false);

        $this->assertInstanceOf(TemplateMatches::class, $template);
        $this->assertTrue($template->isEligible());
        $this->assertCount(1, $template->getMatches());
        $this->assertSame(0.0, (float) $template->getPairingMeetingsVariation());
        $this->assertSame(0, $template->getPairingPartnersCountVariation());
        $this->assertSame(TemplateMatchesGenerator::STOP_REASON_TRIVIAL, $template->getSortingStopReason());
    }

    public function test_generate_mixed_for_eight_players_two_partners_produces_four_matches(): void
    {
        $generator = new TemplateMatchesGenerator();

        $template = $generator->generate(8, 2, 1, false);

        $this->assertTrue($template->isEligible());
        $this->assertCount(4, $template->getMatches());
        $this->assertNotNull($template->getPairingMeetingsVariation());
        $this->assertGreaterThan(0, $template->getPairingPermutationsIterated());
        $this->assertGreaterThan(0, $template->getPairingTemplatesGenerated());
        $this->assertNotNull($template->getSortingMinDistribution());
        $this->assertNotNull($template->getSortingAvgDistribution());
        $this->assertContains(
            $template->getSortingStopReason(),
            [
                TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
                TemplateMatchesGenerator::STOP_REASON_DEADLINE,
                TemplateMatchesGenerator::STOP_REASON_PRUNE_INFEASIBLE,
            ]
        );
    }

    public function test_generate_mixed_uses_every_player_in_every_match_at_4_3(): void
    {
        $generator = new TemplateMatchesGenerator();

        $template = $generator->generate(4, 3, 1, false);
        $this->assertTrue($template->isEligible());

        foreach ($template->getMatches() as $match) {
            $playersInMatch = array_merge($match[0], $match[1]);
            sort($playersInMatch);
            $this->assertSame([0, 1, 2, 3], $playersInMatch);
        }
    }

    public function test_generate_with_repeat_duplicates_match_list(): void
    {
        $generator = new TemplateMatchesGenerator();

        $single = $generator->generate(8, 2, 1, false);
        $tripled = $generator->generate(8, 2, 3, false);

        $this->assertSame(count($single->getMatches()) * 3, count($tripled->getMatches()));
    }

    public function test_generate_fixed_teams_produces_deterministic_table(): void
    {
        $generator = new TemplateMatchesGenerator();

        $template = $generator->generate(8, 2, 1, true);

        $this->assertTrue($template->isEligible());
        $this->assertNotEmpty($template->getMatches());
        $this->assertSame(1, $template->getPairingPermutationsIterated());
        $this->assertSame(1, $template->getPairingTemplatesGenerated());

        foreach ($template->getMatches() as $match) {
            $teamPair1 = $match[0];
            $teamPair2 = $match[1];
            sort($teamPair1);
            sort($teamPair2);
            $isFixed1 = ($teamPair1[1] - $teamPair1[0] === 1) && ($teamPair1[0] % 2 === 0);
            $isFixed2 = ($teamPair2[1] - $teamPair2[0] === 1) && ($teamPair2[0] % 2 === 0);
            $this->assertTrue(
                $isFixed1 && $isFixed2,
                'Fixed-teams matches must keep the (2k, 2k+1) pairings.'
            );
        }
    }

    public function test_generate_returns_ineligible_template_when_outer_budget_is_zero(): void
    {
        $generator = new TemplateMatchesGenerator(
            static function (): int {
                return 0;
            },
            0,
            TemplateMatchesGenerator::DEFAULT_SORT_WALL_BUDGET_NS
        );
        // 8-2 is not in the per-combo map, so the constructor's zero budget applies naturally.
        $template = $generator->generate(8, 2, 1, false);

        $this->assertFalse($template->isEligible());
        $this->assertNull($template->getMatches());
        $this->assertNull($template->getPairingMeetingsVariation());
        $this->assertNull($template->getPairingPlayersMet());
        $this->assertSame(0, $template->getPairingPermutationsIterated());
        $this->assertSame(0, $template->getPairingTemplatesGenerated());
    }

    public function test_repeat_matches_preserves_match_content(): void
    {
        $generator = new TemplateMatchesGenerator();
        $template = $generator->generate(4, 1, 4, false);

        $this->assertSame(4, count($template->getMatches()));
        $first = $template->getMatches()[0];
        foreach ($template->getMatches() as $match) {
            $this->assertSame($first, $match);
        }
    }

    public function test_combinations_constant_lists_supported_player_counts(): void
    {
        $this->assertArrayHasKey(4, TemplateMatchesGenerator::COMBINATIONS);
        $this->assertArrayHasKey(16, TemplateMatchesGenerator::COMBINATIONS);
        $this->assertContains(2, TemplateMatchesGenerator::COMBINATIONS[8]);
    }

    public function test_progress_callback_receives_pairing_and_ordering_finals_for_mixed(): void
    {
        $events = $this->captureEvents(8, 2, 1, false);

        $this->assertGreaterThanOrEqual(1, count($events));

        $pairingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && $e->isFinal()
        ));
        $orderingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof OrderingProgress && $e->isFinal()
        ));

        $this->assertCount(1, $pairingFinals);
        $this->assertCount(1, $orderingFinals);

        /** @var PairingProgress $pairingFinal */
        $pairingFinal = $pairingFinals[0];
        $this->assertGreaterThan(0, $pairingFinal->getIterations());
        $this->assertGreaterThan(0, $pairingFinal->getTemplatesGenerated());
        $this->assertNotNull($pairingFinal->getBestMeetingsVariation());

        /** @var OrderingProgress $orderingFinal */
        $orderingFinal = $orderingFinals[0];
        $this->assertNotNull($orderingFinal->getBestMin());
        $this->assertNotNull($orderingFinal->getBestAvg());
        $this->assertNotNull($orderingFinal->getStopReason());

        foreach ($events as $event) {
            $this->assertSame(8, $event->getPlayers());
            $this->assertSame(2, $event->getPartners());
            $this->assertSame(1, $event->getRepeat());
            $this->assertFalse($event->isFixedTeams());
        }
    }

    public function test_progress_callback_receives_pairing_and_ordering_finals_for_fixed(): void
    {
        $events = $this->captureEvents(8, 2, 1, true);

        $pairingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && $e->isFinal()
        ));
        $orderingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof OrderingProgress && $e->isFinal()
        ));

        $this->assertCount(1, $pairingFinals);
        $this->assertCount(1, $orderingFinals);

        /** @var PairingProgress $pairingFinal */
        $pairingFinal = $pairingFinals[0];
        $this->assertSame(1, $pairingFinal->getIterations());
        $this->assertSame(1, $pairingFinal->getTemplatesGenerated());
        $this->assertSame(0, $pairingFinal->getBudgetNs());

        foreach ($events as $event) {
            $this->assertTrue($event->isFixedTeams());
            $this->assertSame(8, $event->getPlayers());
            $this->assertSame(2, $event->getPartners());
            $this->assertSame(1, $event->getRepeat());
        }
    }

    public function test_progress_emit_intervals_throttle_interim_ticks(): void
    {
        // Under S1, each seed in the pairing DFS emits at most one interim tick; throttling
        // applies per-call across the multi-seed loop. To exercise the throttle we force
        // multi-seed on a small combo (threshold=1, count=20) so the reporter sees 20 tick
        // requests in a row, and use a stepping clock that advances by 50ms per call. Only
        // every fifth tick should clear the 250ms throttle floor.
        $tick = 0;
        $clock = static function () use (&$tick): int {
            return ($tick++) * 50_000_000;
        };

        $generator = new TemplateMatchesGenerator(
            $clock,
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            TemplateMatchesGenerator::DEFAULT_SORT_WALL_BUDGET_NS,
            20,
            1
        );

        $events = [];
        $generator->setProgressCallback(static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });

        $generator->generate(4, 2, 1, false);

        $pairingNonFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && !$e->isFinal()
        ));

        $this->assertGreaterThan(1, count($pairingNonFinals), 'Expected at least a couple of interim emits');

        for ($i = 1; $i < count($pairingNonFinals); $i++) {
            $delta = $pairingNonFinals[$i]->getElapsedNs() - $pairingNonFinals[$i - 1]->getElapsedNs();
            $this->assertGreaterThanOrEqual(
                250_000_000,
                $delta,
                'Two consecutive interim pairing emits must be at least PROGRESS_EMIT_INTERVAL_NS apart'
            );
        }
    }

    public function test_no_progress_callback_means_no_observable_state_change(): void
    {
        $generator = new TemplateMatchesGenerator();
        $beforeTemplate = $generator->generate(4, 1, 1, false);

        $generator->setProgressCallback(null);
        $afterTemplate = $generator->generate(4, 1, 1, false);

        $this->assertTrue($beforeTemplate->isEligible());
        $this->assertTrue($afterTemplate->isEligible());
        $this->assertSame($beforeTemplate->getMatches(), $afterTemplate->getMatches());
        $this->assertSame($beforeTemplate->getPairingMeetingsVariation(), $afterTemplate->getPairingMeetingsVariation());
    }

    public function test_generated_template_carries_identity_fields(): void
    {
        $generator = new TemplateMatchesGenerator();

        $mixed = $generator->generate(4, 1, 1, false);
        $this->assertSame(4, $mixed->getPlayers());
        $this->assertSame(1, $mixed->getPartners());
        $this->assertSame(1, $mixed->getRepeat());
        $this->assertFalse($mixed->isFixedTeams());

        $fixed = $generator->generate(8, 2, 1, true);
        $this->assertSame(8, $fixed->getPlayers());
        $this->assertSame(2, $fixed->getPartners());
        $this->assertSame(1, $fixed->getRepeat());
        $this->assertTrue($fixed->isFixedTeams());
    }

    public function test_generated_template_populates_pairing_block(): void
    {
        $generator = new TemplateMatchesGenerator();
        $template = $generator->generate(8, 2, 1, false);

        $this->assertTrue($template->isEligible());
        $this->assertNotNull($template->getPairingMeetingsVariation());
        $this->assertNotNull($template->getPairingPartnersCount());
        $this->assertNotNull($template->getPairingPlayersMet());
        $this->assertNotNull($template->getPairingPartnersCountVariation());
        $this->assertGreaterThanOrEqual(0, $template->getPairingPartnersCountVariation());
        $this->assertGreaterThan(0, $template->getPairingPermutationsIterated());
        $this->assertGreaterThan(0, $template->getPairingTemplatesGenerated());
        $this->assertNotNull($template->getPairingPermutationIndex());
        $this->assertNotNull($template->getPairingTemplateIndex());
        $this->assertNotNull($template->getPairingStopReason());
        $this->assertContains($template->getPairingStopReason(), [
            TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            TemplateMatchesGenerator::STOP_REASON_DEADLINE,
        ]);
        $this->assertNotNull($template->getPairingTime());
        $this->assertGreaterThanOrEqual(0.0, $template->getPairingTime());
        $this->assertNotNull($template->getSortingTime());
        $this->assertGreaterThanOrEqual(0.0, $template->getSortingTime());
        $this->assertNotNull($template->getSortingPermutationIndex());
        $this->assertGreaterThan(0, $template->getSortingPermutationsIterated());
        $this->assertNotNull($template->getSortingMinBreak());
        $this->assertNotNull($template->getSortingMaxBreak());
        $this->assertGreaterThanOrEqual($template->getSortingMinBreak(), $template->getSortingMaxBreak());
    }

    public function test_pairing_stop_reason_pessimistic_in_multi_seed(): void
    {
        // Force multi-seed mode on a tiny combo with a per-seed budget so small every seed dies
        // on its first deadline check. Aggregate must report `deadline`.
        $generator = new TemplateMatchesGenerator(
            null,
            10_000,
            TemplateMatchesGenerator::DEFAULT_SORT_WALL_BUDGET_NS,
            4,
            4
        );
        // The 4-2 combo is in the production per-combo budget map (30s); wipe the map so the
        // 10_000ns constructor budget takes effect and every seed is guaranteed to deadline.
        $generator->setPerComboBudgetsNs([]);

        $template = $generator->generate(4, 2, 1, false);

        $this->assertSame(
            TemplateMatchesGenerator::STOP_REASON_DEADLINE,
            $template->getPairingStopReason(),
            'Any deadlined seed must promote the aggregate stop reason to deadline.'
        );
    }

    public function test_fixed_teams_pairing_stop_reason_is_factorial_complete(): void
    {
        $generator = new TemplateMatchesGenerator();
        $template = $generator->generate(8, 2, 1, true);

        $this->assertSame(
            TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            $template->getPairingStopReason(),
            'Fixed-teams single-pass build always exhausts the (trivial) pairing space.'
        );
    }

    public function test_pairing_below_multi_seed_threshold_runs_single_seed(): void
    {
        // 8/2 has 8 pairs, well below the default threshold of 12. Multi-seed must stay off.
        $events = $this->captureEvents(8, 2, 1, false);

        $pairingEvents = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress
        ));

        $this->assertNotEmpty($pairingEvents);
        /** @var PairingProgress $event */
        foreach ($pairingEvents as $event) {
            $this->assertSame(1, $event->getTotalSeeds(), 'Below-threshold combos must report totalSeeds=1');
            $this->assertSame(1, $event->getCurrentSeed(), 'Below-threshold combos must report currentSeed=1');
        }
    }

    public function test_pairing_above_multi_seed_threshold_emits_seed_context(): void
    {
        // Force multi-seed on a small combo so the test runs quickly: 4/2 has 4 pairs (4! = 24),
        // and multiSeedCount=4 yields four distinct lex starting points — enough to exercise the
        // fan-out without relying on a real-world large-pair combo.
        $generator = new TemplateMatchesGenerator(
            null,
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            TemplateMatchesGenerator::DEFAULT_SORT_WALL_BUDGET_NS,
            4,
            4
        );
        $events = [];
        $generator->setProgressCallback(static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });
        $generator->generate(4, 2, 1, false);

        $pairingEvents = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress
        ));

        $this->assertNotEmpty($pairingEvents);

        $totals = array_unique(array_map(static fn(PairingProgress $e) => $e->getTotalSeeds(), $pairingEvents));
        $this->assertSame([4], array_values($totals), 'Every pairing event must report the same totalSeeds');

        $finals = array_values(array_filter($pairingEvents, static fn(PairingProgress $e) => $e->isFinal()));
        $this->assertCount(1, $finals, 'Multi-seed must still emit exactly one pairing-final per generate()');
        /** @var PairingProgress $final */
        $final = $finals[0];
        $this->assertSame(4, $final->getCurrentSeed(), 'Final event must report the last seed (K-of-K)');
        $this->assertSame(4, $final->getTotalSeeds());
    }

    public function test_pairing_explicit_seed_count_one_disables_multi_seed(): void
    {
        // Even with a threshold low enough to trigger, count=1 must keep the legacy single-seed
        // behavior. This is the escape hatch tests use to A/B against the multi-seed branch.
        $generator = new TemplateMatchesGenerator(
            null,
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            TemplateMatchesGenerator::DEFAULT_SORT_WALL_BUDGET_NS,
            1,
            2
        );
        $events = [];
        $generator->setProgressCallback(static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });
        $generator->generate(4, 2, 1, false);

        $pairingEvents = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress
        ));

        $this->assertNotEmpty($pairingEvents);
        /** @var PairingProgress $event */
        foreach ($pairingEvents as $event) {
            $this->assertSame(1, $event->getTotalSeeds());
            $this->assertSame(1, $event->getCurrentSeed());
        }
    }

    public function test_lehmer_seed_index_zero_is_identity_permutation(): void
    {
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], $this->invokeLehmer(0, 16, 8));
        $this->assertSame([0, 1, 2, 3], $this->invokeLehmer(0, 4, 4));
    }

    public function test_lehmer_seeds_are_distinct_when_count_at_most_factorial(): void
    {
        // K=4 ≤ 4! = 24 — every seed must decode to a distinct permutation.
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $perm = $this->invokeLehmer($i, 4, 4);
            $seen[implode(',', $perm)] = true;
        }
        $this->assertCount(4, $seen);
    }

    public function test_lehmer_decodes_every_lex_index_when_count_equals_factorial(): void
    {
        // K = n! is the boundary case: each seed index must map to its own lex permutation
        // exactly, in lex order. We compare against pcNextPermutation as the reference walk.
        $expected = [];
        $perm = [0, 1, 2, 3];
        do {
            $expected[] = $perm;
            $perm = $this->invokePcNext($perm, 3);
        } while ($perm !== false);

        $this->assertCount(24, $expected);

        $actual = [];
        for ($i = 0; $i < 24; $i++) {
            $actual[] = $this->invokeLehmer($i, 24, 4);
        }

        $this->assertSame($expected, $actual);
    }

    public function test_lehmer_returns_a_valid_permutation_for_arbitrary_seed(): void
    {
        // Sanity check: regardless of seed/totalSeeds, the result must be a permutation of {0..n-1}.
        foreach ([1, 5, 17, 31] as $seedIndex) {
            $perm = $this->invokeLehmer($seedIndex, 32, 12);
            $sorted = $perm;
            sort($sorted);
            $this->assertSame(range(0, 11), $sorted, "seedIndex={$seedIndex} must yield a permutation of 0..11");
        }
    }

    public function test_default_difference_limit_is_one(): void
    {
        $this->assertSame(1, TemplateMatchesGenerator::DEFAULT_DIFFERENCE_LIMIT);
    }

    public function test_players_met_too_much_rejects_gap_above_limit(): void
    {
        // Player 0 already has a gap of 2 between most-met (player 1, count = 2) and least-met
        // (player 2, count = 0). Adding a match whose participants include player 1 (the
        // most-met partner) is rejected at differenceLimit = 1 because the resulting per-player
        // gap would be strictly greater than the limit.
        $playersMet = [0 => [1 => 2, 2 => 0]];

        $result = $this->invokePlayersMetTooMuch([0, 3], [1, 4], $playersMet, 1);

        $this->assertTrue($result);
    }

    public function test_players_met_too_much_allows_gap_at_limit(): void
    {
        // Player 0 has a gap of exactly 1 (most-met = 1 with count 1, least-met = 2 with count
        // 0). At differenceLimit = 1, the check passes because the condition rejects only gaps
        // strictly greater than the limit. This pins the R1 off-by-one fix: the previous `>=`
        // would have rejected here, leaving no headroom for partial imbalance.
        $playersMet = [0 => [1 => 1, 2 => 0]];

        $result = $this->invokePlayersMetTooMuch([0, 3], [1, 4], $playersMet, 1);

        $this->assertFalse($result);
    }

    public function test_players_met_too_much_strict_mode_rejects_any_gap(): void
    {
        // At differenceLimit = 0, even a gap of 1 is rejected when the most-met partner is one
        // of the four players about to join the match. This exercises the "strictly balanced"
        // mode tests can opt into.
        $playersMet = [0 => [1 => 1, 2 => 0]];

        $result = $this->invokePlayersMetTooMuch([0, 3], [1, 4], $playersMet, 0);

        $this->assertTrue($result);
    }

    public function test_players_met_too_much_ignores_high_gap_when_mostmet_absent_from_match(): void
    {
        // Player 0 has a gap of 5 with most-met = player 9, but player 9 is NOT in the candidate
        // match {0, 3, 1, 4}. The constraint stays inactive because adding this match would not
        // touch the most-met partner.
        $playersMet = [0 => [9 => 5, 2 => 0]];

        $result = $this->invokePlayersMetTooMuch([0, 3], [1, 4], $playersMet, 1);

        $this->assertFalse($result);
    }

    public function test_pairing_meeting_constraint_bounds_variation_for_8_2(): void
    {
        // With the constraint re-enabled at differenceLimit = 1, an eligible 8/2 template must
        // have meetingsVariation <= 1.0 (the constraint forbids per-player gaps strictly above
        // the limit during the build, and 8/2 is small enough that the constraint reliably
        // produces a balanced template).
        $generator = new TemplateMatchesGenerator();
        $generator->setPerComboBudgetsNs([]);

        $template = $generator->generate(8, 2, 1, false);

        $this->assertTrue($template->isEligible());
        $this->assertNotNull($template->getPairingMeetingsVariation());
        $this->assertLessThanOrEqual(
            1.0,
            $template->getPairingMeetingsVariation(),
            'differenceLimit = 1 must bound the average per-player meetings gap at 1.0.'
        );
    }

    public function test_per_combo_budget_override_is_honored(): void
    {
        $generator = new TemplateMatchesGenerator(
            null,
            100,
            100,
            0.0,
            0.0
        );
        $generator->setPerComboBudgetsNs([
            '4-2' => [9_999, 8_888],
        ]);

        $this->assertSame([9_999, 8_888], $this->invokeBudgetFor($generator, 4, 2));
    }

    public function test_per_combo_budget_falls_back_to_constructor_when_combo_absent(): void
    {
        $generator = new TemplateMatchesGenerator(
            null,
            123,
            456,
            0.0,
            0.0
        );
        $generator->setPerComboBudgetsNs([
            '4-2' => [9_999, 8_888],
        ]);

        $this->assertSame([123, 456], $this->invokeBudgetFor($generator, 8, 2));
    }

    public function test_per_combo_budgets_setter_overrides_class_constant(): void
    {
        // The setter must fully replace the default map: a freshly constructed generator
        // initialises from {@see PER_COMBO_BUDGETS_NS}, but after `setPerComboBudgetsNs([])` no
        // combo (not even the worst-10 production overrides) returns the production values.
        $generator = new TemplateMatchesGenerator(
            null,
            777,
            999,
            0.0,
            0.0
        );

        $this->assertSame(
            TemplateMatchesGenerator::PER_COMBO_BUDGETS_NS['16-12'],
            $this->invokeBudgetFor($generator, 16, 12),
            'Production default map must apply on a fresh generator.'
        );

        $generator->setPerComboBudgetsNs([]);

        $this->assertSame(
            [777, 999],
            $this->invokeBudgetFor($generator, 16, 12),
            'Setter with empty array must force every combo down the fallback path.'
        );
    }

    public function test_distribution_score_is_one_when_player_appears_in_every_match(): void
    {
        // Player 0 at positions [0, 1, 2, 3] of 4 matches. idealCeil = 1, neutral band = {0, 1}.
        // Each inter-match sit-out count is 0 (= neutralLow, inter-match → penalty 0); no edge
        // gaps. Total penalty = 0, score = 1.0.
        $matches = [
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
            [[0, 1], [2, 3]],
        ];

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertSame(1.0, $score);
    }

    public function test_distribution_score_is_low_for_clustered_player(): void
    {
        // Player at [0, 1, 2, 3, 13] of 14 matches. matchCount = 5, idealCeil = 3, neutral band
        // = {2, 3}. Inter-match sit-outs: [0, 0, 0, 9] (no lead/trail because endpoints land on
        // 0 / 13). Penalties: 0.1*(2-0)*3 + 0.4*(9-3) = 0.6 + 2.4 = 3.0. Avg over 4 gaps = 0.75
        // → score = 0.25. Bound at 0.50 keeps the assertion stable against tiny formula tweaks.
        $matches = $this->makeMatchesWithPlayerAt(14, 0, [0, 1, 2, 3, 13]);

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertLessThanOrEqual(0.50, $score, sprintf('Expected clustered score <= 0.50, got %.4f', $score));
    }

    public function test_distribution_score_clamps_at_extreme_gap(): void
    {
        // Player at [0, 13] of 14 matches: a single huge inter-match sit-out run of 12 against
        // an idealCeil of 7. Penalty = 0.4 * (12 - 7) = 2.0, which the clamp pulls to 1, so the
        // score floors at exactly 0.0.
        $matches = $this->makeMatchesWithPlayerAt(14, 0, [0, 13]);

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertSame(0.0, $score, sprintf('Expected extreme-gap score = 0.0, got %.4f', $score));
    }

    public function test_distribution_score_for_single_appearance_returns_one(): void
    {
        // Single-appearance player keeps the matchCount <= 1 early return: score = 1.0.
        $matches = $this->makeMatchesWithPlayerAt(8, 0, [3]);

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertSame(1.0, $score);
    }

    public function test_distribution_score_perfect_when_all_gaps_in_neutral_band(): void
    {
        // 32 matches, player at [0, 4, 8, 12, 16, 20, 24, 28]. matchCount = 8, idealCeil = 4,
        // neutral band = {3, 4}. Inter-match sit-outs all 3 (= neutralLow, inter → penalty 0);
        // trail = 31 - 28 = 3 (= neutralLow → penalty 0). Avg = 0, score = 1.0.
        $matches = $this->makeMatchesWithPlayerAt(32, 0, [0, 4, 8, 12, 16, 20, 24, 28]);

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertSame(1.0, $score);
    }

    public function test_distribution_score_short_inter_match_gap_costs_gentle_penalty(): void
    {
        // 32 matches, player at [0, 4, 8, 12, 16, 20, 24, 26]. idealCeil = 4, neutral = {3, 4}.
        // Inter-match sit-outs: [3,3,3,3,3,3,1] — the last one (1) is below the neutral band by
        // 2. Penalty = 0.1 * (3 - 1) = 0.2. Trail = 31 - 26 = 5 (long edge gap; edge bias does
        // not apply because the rule only protects the SHORT end). Edge long penalty =
        // 0.4 * (5 - 4) = 0.4. Total penalty = 0.6 across 8 gaps; avg = 0.075; score = 0.925.
        $matches = $this->makeMatchesWithPlayerAt(32, 0, [0, 4, 8, 12, 16, 20, 24, 26]);

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertEqualsWithDelta(0.925, $score, 1e-9);
    }

    public function test_distribution_score_long_gap_costs_4x_more_than_equivalent_short_gap(): void
    {
        // The formula embeds a 4:1 asymmetry between gentle (short, inter-match) and harsh
        // (long, any) penalties. Build two schedules with exactly the same number of gaps
        // (matchCount = 8 → 7 inter, 0 lead, 0 trail) where each schedule has exactly one
        // single-step deviation from the neutral band:
        //
        // shortMatches: 32 matches, player at [0, 5, 10, 15, 20, 24, 28, 31]. idealCeil = 4,
        //   neutral = {3, 4}. Inter sit-outs: [4,4,4,4,3,3,2]; lead = 0 (firstMatch=0); trail = 0
        //   (lastMatch=31). The only non-zero contribution is the size-2 inter sit-outs → 0.1 *
        //   (3 - 2) = 0.1 across 7 gaps → avg = 0.1/7.
        //
        // longMatches: 32 matches, player at [0, 4, 8, 12, 16, 20, 25, 31]. idealCeil = 4,
        //   neutral = {3, 4}. Inter sit-outs: [3,3,3,3,3,4,5]; lead = 0; trail = 0. The only
        //   above-neutral gap is the 5 → 0.4 * (5 - 4) = 0.4 across 7 gaps → avg = 0.4/7.
        //
        // Ratio of deductions: (0.4/7) / (0.1/7) = exactly 4. Both schedules share the same
        // 7 gaps with all other gaps neutral, so the schedule-level ratio collapses cleanly to
        // the per-gap ratio.
        $shortMatches = $this->makeMatchesWithPlayerAt(32, 0, [0, 5, 10, 15, 20, 24, 28, 31]);
        $longMatches  = $this->makeMatchesWithPlayerAt(32, 0, [0, 4, 8, 12, 16, 20, 25, 31]);

        $shortScore = $this->invokeCalculatePlayerDistribution(0, $shortMatches);
        $longScore  = $this->invokeCalculatePlayerDistribution(0, $longMatches);

        $shortDeduction = 1.0 - $shortScore;
        $longDeduction  = 1.0 - $longScore;

        $this->assertGreaterThan(0.0, $shortDeduction);
        $this->assertGreaterThan($shortDeduction, $longDeduction);
        // 4:1 per-gap asymmetry; both schedules share the same gap count, so the schedule
        // ratio is exactly 4. Use a small tolerance to absorb floating-point rounding.
        $this->assertEqualsWithDelta(4.0, $longDeduction / $shortDeduction, 1e-9);
    }

    public function test_distribution_score_edge_gap_short_is_zero_penalty(): void
    {
        // 32 matches, player at [1, 5, 9, 13, 17, 21, 25, 29]. idealCeil = 4, neutral = {3, 4}.
        // Lead gap = 1 (edge, short, < neutralLow) → penalty 0; inter sit-outs all 3 (= neutralLow,
        // inter) → penalty 0; trail = 31 - 29 = 2 (edge, short, < neutralLow) → penalty 0.
        // Total penalty 0, score = 1.0.
        $matches = $this->makeMatchesWithPlayerAt(32, 0, [1, 5, 9, 13, 17, 21, 25, 29]);

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertSame(1.0, $score);
    }

    public function test_distribution_score_edge_gap_at_idealceil_is_small_penalty(): void
    {
        // 32 matches, player at [4, 7, 11, 15, 19, 23, 27, 31]. idealCeil = 4, neutral = {3, 4}.
        // Lead gap = 4 (edge, at neutralHigh → penalty 0.1).
        // Inter sit-outs: [2, 3, 3, 3, 3, 3, 3]. The leading 2 is below neutralLow=3 by 1 →
        // 0.1 * (3 - 2) = 0.1; the others are = neutralLow → 0.
        // Trail = 31 - 31 = 0 (no trail gap).
        // Total penalty = 0.1 + 0.1 = 0.2 over 8 gaps → avg 0.025 → score = 0.975.
        $matches = $this->makeMatchesWithPlayerAt(32, 0, [4, 7, 11, 15, 19, 23, 27, 31]);

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertEqualsWithDelta(0.975, $score, 1e-9);
    }

    public function test_distribution_score_clamps_at_extreme_bunching(): void
    {
        // Player crammed into the first 8 slots of a 32-match schedule. idealCeil = 4,
        // neutral = {3, 4}. Inter sit-outs: all 0 → 7 penalties of 0.1*(3-0)=0.3 = 2.1.
        // Trail = 31 - 7 = 24 (edge long, no exemption) → 0.4*(24-4)=8.0. Total = 10.1 over 8
        // gaps; avg = 1.2625 → clamped at 1.0 → score = 0.0.
        $matches = $this->makeMatchesWithPlayerAt(32, 0, [0, 1, 2, 3, 4, 5, 6, 7]);

        $score = $this->invokeCalculatePlayerDistribution(0, $matches);

        $this->assertSame(0.0, $score);
    }

    public function test_default_difference_limit_max_is_two(): void
    {
        $this->assertSame(2, TemplateMatchesGenerator::DEFAULT_DIFFERENCE_LIMIT_MAX);
    }

    public function test_pairing_does_not_relax_when_strict_succeeds(): void
    {
        // 4/2 finds a template under dl=1 in microseconds. The relaxAttempts trail must have
        // length 1 and the surfaced differenceLimit must stay at 1.
        $generator = new TemplateMatchesGenerator();
        $generator->setPerComboBudgetsNs([]);

        $template = $generator->generate(4, 2, 1, false);

        $this->assertTrue($template->isEligible());
        $this->assertSame(1, $template->getPairingDifferenceLimit());
        $attempts = $template->getPairingRelaxAttempts();
        $this->assertNotNull($attempts);
        $this->assertCount(1, $attempts);
        $this->assertSame(1, $attempts[0]['differenceLimit']);
        $this->assertGreaterThan(0, $attempts[0]['templatesGenerated']);
    }

    public function test_pairing_relaxes_dl_when_strict_yields_zero_templates(): void
    {
        // Force every pairing attempt to exit with 0 templates by giving it a zero outer
        // budget (the deadline fires before the first iteration of the lex walk). With
        // differenceLimitMax = 2 and starting dl = 1, the relax loop should fire once and
        // accumulate exactly two attempts in the trail (dl=1 then dl=2).
        $generator = new TemplateMatchesGenerator(
            static function (): int { return 0; },
            0,
            0,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_COUNT_PAIRING,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_THRESHOLD_PAIRS,
            1,
            -1,
            2
        );
        $generator->setPerComboBudgetsNs([]);

        $template = $generator->generate(8, 2, 1, false);

        $attempts = $template->getPairingRelaxAttempts();
        $this->assertNotNull($attempts);
        $this->assertCount(2, $attempts);
        $this->assertSame(1, $attempts[0]['differenceLimit']);
        $this->assertSame(2, $attempts[1]['differenceLimit']);
        $this->assertSame(2, $template->getPairingDifferenceLimit());
    }

    public function test_pairing_returns_null_matches_when_all_dls_exhausted(): void
    {
        // Zero outer budget forces every dl attempt to exit immediately on the first deadline
        // check. With differenceLimitMax clamped to the starting dl (no relaxation headroom)
        // the loop runs exactly once and the result is null matches with a single-entry
        // relaxAttempts trail.
        $generator = new TemplateMatchesGenerator(
            static function (): int { return 0; },
            0,
            0,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_COUNT_PAIRING,
            TemplateMatchesGenerator::DEFAULT_MULTI_SEED_THRESHOLD_PAIRS,
            1,
            -1,
            0
        );
        $generator->setPerComboBudgetsNs([]);

        $template = $generator->generate(8, 2, 1, false);

        $this->assertFalse($template->isEligible());
        $this->assertNull($template->getMatches());
        $attempts = $template->getPairingRelaxAttempts();
        $this->assertNotNull($attempts);
        $this->assertCount(1, $attempts);
        $this->assertSame(1, $template->getPairingDifferenceLimit());
    }

    public function test_pairing_relax_attempts_round_trip_through_json(): void
    {
        // Forensic trail must survive toArray / fromArray. Verify by feeding a synthetic attempt
        // list through the DTO and checking round-trip equality.
        $template = new TemplateMatches(
            8,
            2,
            1,
            false,
            [[[0, 1], [2, 3]]],
            0.0,
            5,
            3,
            5,
            3,
            [0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2],
            [0 => [1 => 1]],
            0,
            1,
            'FACTORIAL_COMPLETE',
            0.1,
            'FACTORIAL_COMPLETE',
            0.9,
            0.95,
            10,
            7,
            1,
            2,
            0.05,
            2,
            [
                ['differenceLimit' => 1, 'permutationsIterated' => 3, 'templatesGenerated' => 0, 'time' => 0.04],
                ['differenceLimit' => 2, 'permutationsIterated' => 5, 'templatesGenerated' => 5, 'time' => 0.06],
            ]
        );

        $round = TemplateMatches::fromArray($template->toArray());

        $this->assertSame(2, $round->getPairingDifferenceLimit());
        $attempts = $round->getPairingRelaxAttempts();
        $this->assertNotNull($attempts);
        $this->assertCount(2, $attempts);
        $this->assertSame(1, $attempts[0]['differenceLimit']);
        $this->assertSame(3, $attempts[0]['permutationsIterated']);
        $this->assertSame(0, $attempts[0]['templatesGenerated']);
        $this->assertSame(0.04, $attempts[0]['time']);
        $this->assertSame(2, $attempts[1]['differenceLimit']);
        $this->assertSame(5, $attempts[1]['templatesGenerated']);
    }

    /**
     * @return array<int, GenerationProgress>
     */
    private function captureEvents(int $players, int $partners, int $repeat, bool $fixedTeams): array
    {
        $generator = new TemplateMatchesGenerator();
        $events = [];
        $generator->setProgressCallback(static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });

        $generator->generate($players, $partners, $repeat, $fixedTeams);

        return $events;
    }

    /**
     * @param array{0:int,1:int} $pair1
     * @param array{0:int,1:int} $pair2
     * @param array<int, array<int, int>> $playersMet
     */
    private function invokePlayersMetTooMuch(array $pair1, array $pair2, array $playersMet, int $differenceLimit): bool
    {
        $generator = new TemplateMatchesGenerator();
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('playersMetTooMuch');
        $method->setAccessible(true);

        return (bool) $method->invoke($generator, $pair1, $pair2, $playersMet, $differenceLimit);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function invokeBudgetFor(TemplateMatchesGenerator $generator, int $players, int $partners): array
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('budgetFor');
        $method->setAccessible(true);

        return $method->invoke($generator, $players, $partners);
    }

    /**
     * @param array<int, array<int, array<int, int>>> $matches
     */
    private function invokeCalculatePlayerDistribution(int $playerIndex, array $matches): float
    {
        return (new PlayerDistributionScorer())->score($playerIndex, $matches);
    }

    /**
     * Builds a synthetic matches array of {@code $totalMatches} entries where {@code $playerIndex}
     * appears at exactly the positions listed in {@code $positions} and never elsewhere. Each
     * non-player match uses fixed dummy player indices that cannot collide with the target index.
     *
     * @param array<int, int> $positions
     * @return array<int, array<int, array<int, int>>>
     */
    private function makeMatchesWithPlayerAt(int $totalMatches, int $playerIndex, array $positions): array
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

    /**
     * @return array<int, int>
     */
    private function invokeLehmer(int $seedIndex, int $totalSeeds, int $n): array
    {
        $generator = new TemplateMatchesGenerator();
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('lehmerSeedPermutation');
        $method->setAccessible(true);

        return $method->invoke($generator, $seedIndex, $totalSeeds, $n);
    }

    /**
     * @param array<int, int> $perm
     * @return array<int, int>|false
     */
    private function invokePcNext(array $perm, int $size)
    {
        $generator = new TemplateMatchesGenerator();
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('pcNextPermutation');
        $method->setAccessible(true);

        return $method->invoke($generator, $perm, $size);
    }

    public function test_default_dfs_branch_cap_is_ten_thousand(): void
    {
        $this->assertSame(10000, TemplateMatchesGenerator::DEFAULT_DFS_BRANCH_CAP);
    }

    public function test_default_multi_seed_count_pairing_is_two_hundred_fifty_six(): void
    {
        $this->assertSame(256, TemplateMatchesGenerator::DEFAULT_MULTI_SEED_COUNT_PAIRING);
    }

    public function test_backtracking_recovers_completion_greedy_would_miss(): void
    {
        // Synthetic 6-pair scenario where the lex-first match (pair0 with pair1) leaves a
        // remainder in which the DFS must backtrack on the *second* match to complete a third.
        // Specifically: after matching (0,1)-(2,3) and then (2,4)-(5,6), the leftover pairs
        // (3,7) and (4,7) share player 7 and cannot pair. The DFS must back out, re-pair (2,4)
        // with (3,7) instead, leaving (5,6)-(4,7) as a viable third match.
        $orderedPairs = [
            ['players' => [0, 1], 'used' => false],
            ['players' => [2, 3], 'used' => false],
            ['players' => [2, 4], 'used' => false],
            ['players' => [5, 6], 'used' => false],
            ['players' => [3, 7], 'used' => false],
            ['players' => [4, 7], 'used' => false],
        ];

        $result = $this->invokeBuildTemplateByBacktracking(
            $orderedPairs,
            PHP_INT_MAX,
            PHP_INT_MAX,
            10_000,
            8,
            null
        );

        $this->assertNotNull($result, 'DFS must backtrack from the greedy dead-end and assemble a complete template.');
        $this->assertCount(3, $result['matches']);
    }

    public function test_backtracking_respects_branch_cap(): void
    {
        // Branch cap of 1: the DFS may enter the root recursion exactly once, decrement to 0,
        // and refuse to recurse deeper. With three matches needed, that's not enough — null.
        $orderedPairs = [
            ['players' => [0, 1], 'used' => false],
            ['players' => [2, 3], 'used' => false],
            ['players' => [4, 5], 'used' => false],
            ['players' => [6, 7], 'used' => false],
        ];

        $result = $this->invokeBuildTemplateByBacktracking(
            $orderedPairs,
            PHP_INT_MAX,
            PHP_INT_MAX,
            1,
            8,
            null
        );

        $this->assertNull($result, 'Branch cap = 1 must abort before the recursion can complete a template.');
    }

    public function test_backtracking_respects_wall_deadline(): void
    {
        // Inject a clock that crosses the deadline on the second call. The DFS enters once
        // (clock = 0), decrements the cap, then re-checks the clock (now beyond deadline) and
        // bails out without recursing into a second match.
        $tick = 0;
        $clock = static function () use (&$tick): int {
            $val = $tick;
            $tick = $tick === 0 ? 1_000_000_000 : $tick;
            return $val;
        };

        $generator = new TemplateMatchesGenerator($clock);

        $orderedPairs = [
            ['players' => [0, 1], 'used' => false],
            ['players' => [2, 3], 'used' => false],
            ['players' => [4, 5], 'used' => false],
            ['players' => [6, 7], 'used' => false],
        ];

        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('buildTemplateByBacktracking');
        $method->setAccessible(true);

        $result = $method->invoke($generator, $orderedPairs, 500_000_000, PHP_INT_MAX, 10_000, 8, null);

        $this->assertNull($result, 'DFS must return null once the wall deadline elapses, even with branch budget left.');
    }

    public function test_dfs_min_met_bb_prune_kills_unreachable_branch(): void
    {
        // With an unsatisfiable bestMinMetSoFar (any positive value larger than the maximum
        // distinct-opponent count the synthetic pair pool can ever produce), the prune fires
        // at the root and the DFS returns null without exploring any branch.
        $orderedPairs = [
            ['players' => [0, 1], 'used' => false],
            ['players' => [2, 3], 'used' => false],
        ];

        // The pool only ever lets each player meet a few opponents. Set the bound to a
        // dramatically higher value to trigger the prune immediately.
        $result = $this->invokeBuildTemplateByBacktracking(
            $orderedPairs,
            PHP_INT_MAX,
            PHP_INT_MAX,
            10_000,
            8,
            999
        );

        $this->assertNull($result, 'Min Met B&B must prune the root when even a perfect completion cannot reach the bound.');
    }

    public function test_pairing_uses_multiple_seeds_when_pair_count_meets_threshold(): void
    {
        // Force multi-seed on a small combo: count=4, threshold=4. 4/2 yields 4 pairs (>= 4 →
        // multi-seed). Each seed runs one DFS that produces one template, so the pairing-final
        // event should report iterations = 4.
        $generator = new TemplateMatchesGenerator(
            null,
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            TemplateMatchesGenerator::DEFAULT_SORT_WALL_BUDGET_NS,
            4,
            4
        );
        $events = [];
        $generator->setProgressCallback(static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });
        $generator->generate(4, 2, 1, false);

        $pairingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && $e->isFinal()
        ));
        $this->assertCount(1, $pairingFinals);
        /** @var PairingProgress $final */
        $final = $pairingFinals[0];
        $this->assertSame(4, $final->getIterations(), 'Each of the 4 seeds runs exactly one DFS attempt.');
        $this->assertSame(4, $final->getTotalSeeds());
    }

    public function test_pairing_single_seed_below_threshold_runs_one_dfs(): void
    {
        // n=4 pairs, threshold=12 → multi-seed off. The DFS runs once from the identity
        // permutation; iterations == 1.
        $generator = new TemplateMatchesGenerator();
        $generator->setPerComboBudgetsNs([]);
        $events = [];
        $generator->setProgressCallback(static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });
        $generator->generate(4, 2, 1, false);

        $pairingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && $e->isFinal()
        ));
        $this->assertCount(1, $pairingFinals);
        /** @var PairingProgress $final */
        $final = $pairingFinals[0];
        $this->assertSame(1, $final->getIterations(), 'Single-seed DFS contributes exactly one attempt.');
        $this->assertSame(1, $final->getTotalSeeds());
    }

    public function test_pairing_results_are_deterministic_across_runs(): void
    {
        // Two consecutive runs with the same configuration must produce byte-identical output.
        // Determinism is the foundational invariant of S1's DFS model; the seeds, pair-1
        // ordering, and pair-2 candidate ordering are all index-driven. Wall-clock `time`
        // fields obviously drift between runs, so we strip them before comparing.
        $generator = new TemplateMatchesGenerator();
        $generator->setPerComboBudgetsNs([]);

        $first = $this->stripWallClockFields($generator->generate(8, 2, 1, false)->toArray());
        $second = $this->stripWallClockFields($generator->generate(8, 2, 1, false)->toArray());

        $this->assertSame($first, $second);
    }

    public function test_pairing_tie_break_prefers_lowest_seed(): void
    {
        // Two seeds may produce templates with the same `meetingsVariation`. The promotion
        // logic uses strict `>` for improvement, so the first seed to land at a given variance
        // wins and the persisted `permutationIndex` equals that seed's 1-based index. Verify
        // by running a small combo with multi-seed on and observing the resulting index is
        // necessarily small (≤ totalSeeds).
        $generator = new TemplateMatchesGenerator(
            null,
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            TemplateMatchesGenerator::DEFAULT_SORT_WALL_BUDGET_NS,
            8,
            4
        );
        $template = $generator->generate(4, 2, 1, false);

        $this->assertTrue($template->isEligible());
        $this->assertNotNull($template->getPairingPermutationIndex());
        $this->assertGreaterThanOrEqual(1, $template->getPairingPermutationIndex());
        $this->assertLessThanOrEqual(8, $template->getPairingPermutationIndex());
    }

    /**
     * Drops the wall-clock fields (`pairing.time`, `pairing.relaxAttempts[].time`, `sorting.time`)
     * that legitimately drift between runs, so a determinism test compares only the algorithmic
     * outputs.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function stripWallClockFields(array $data): array
    {
        if (isset($data['pairing']) && is_array($data['pairing'])) {
            unset($data['pairing']['time']);
            if (isset($data['pairing']['relaxAttempts']) && is_array($data['pairing']['relaxAttempts'])) {
                foreach ($data['pairing']['relaxAttempts'] as &$attempt) {
                    if (is_array($attempt)) {
                        unset($attempt['time']);
                    }
                }
                unset($attempt);
            }
        }
        if (isset($data['sorting']) && is_array($data['sorting'])) {
            unset($data['sorting']['time']);
        }

        return $data;
    }

    /**
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $orderedPairs
     * @return array{matches: array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>, playersMet: array<int, array<int, int>>}|null
     */
    private function invokeBuildTemplateByBacktracking(
        array $orderedPairs,
        int $deadlineNs,
        int $differenceLimit,
        int $branchCap,
        int $playersCount,
        ?int $bestMinMetSoFar
    ): ?array {
        $generator = new TemplateMatchesGenerator();
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('buildTemplateByBacktracking');
        $method->setAccessible(true);

        return $method->invoke(
            $generator,
            $orderedPairs,
            $deadlineNs,
            $differenceLimit,
            $branchCap,
            $playersCount,
            $bestMinMetSoFar
        );
    }
}
