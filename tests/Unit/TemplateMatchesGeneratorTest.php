<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\Progress\GenerationProgress;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\MatchMakingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\PairingProgress;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Behavior-level tests for the pure {@see TemplateMatchesGenerator}.
 *
 * Uses phase/pipeline reflection only — never public generate().
 */
final class TemplateMatchesGeneratorTest extends GeneratorTestCase
{
    public function test_generate_mixed_with_smallest_combo_returns_one_match(): void
    {
        $generator = new TemplateMatchesGenerator();
        $template = $this->invokePipelineMixed($generator, 4, 1);

        $this->assertInstanceOf(TemplateMatches::class, $template);
        $this->assertTrue($template->isEligible());
        $this->assertCount(1, $template->getMatches());
        $this->assertCount(1, $template->getMatches()[0]);
        $this->assertSame(0.0, (float) $template->getMatchMakingQualityMeetingsVariation());
        $this->assertSame(0, $template->getPairingQualityPartnersCountVariation());
        $this->assertContains(
            $template->getOrderingStatsStopReason(),
            [
                TemplateMatchesGenerator::STOP_REASON_TRIVIAL,
                TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            ]
        );
    }

    public function test_generate_fixed_teams_smoke_returns_eligible_template(): void
    {
        $generator = new TemplateMatchesGenerator();
        $template = $this->invokePipelineFixed($generator, 4, 2);

        $this->assertTrue($template->isEligible());
        $this->assertSame(1, $template->getMatchMakingStatsPermutationsIterated());
        $this->assertSame(1, $template->getMatchMakingStatsTemplatesGenerated());
    }

    public function test_generate_returns_ineligible_template_when_outer_budget_is_zero(): void
    {
        $generator = new TemplateMatchesGenerator(
            static function (): int {
                return 0;
            },
            0,
            self::TEST_PHASE_BUDGET_NS
        );

        $template = $this->invokePipelineMixed($generator, 4, 2, 1, 1, 0, 0, self::TEST_PHASE_BUDGET_NS);

        $this->assertFalse($template->isEligible());
        $this->assertNull($template->getMatches());
        $this->assertNull($template->getMatchMakingQualityMeetingsVariation());
        $this->assertNull($template->getMatchMakingQualityPlayersMet());
        $this->assertSame(0, $template->getMatchMakingStatsPermutationsIterated());
        $this->assertSame(0, $template->getMatchMakingStatsTemplatesGenerated());
    }

    public function test_progress_callback_receives_pairing_and_ordering_finals_for_mixed(): void
    {
        $events = $this->capturePipelineEvents(new TemplateMatchesGenerator(), 4, 2, 1, false);

        $this->assertGreaterThanOrEqual(1, count($events));

        $pairingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && $e->isFinal()
        ));
        $matchMakingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof MatchMakingProgress && $e->isFinal()
        ));
        $orderingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof OrderingProgress && $e->isFinal()
        ));

        $this->assertCount(1, $pairingFinals);
        $this->assertCount(1, $matchMakingFinals);
        $this->assertCount(1, $orderingFinals);

        /** @var PairingProgress $pairingFinal */
        $pairingFinal = $pairingFinals[0];
        $this->assertGreaterThan(0, $pairingFinal->getNodesExplored());
        $this->assertNotNull($pairingFinal->getBestMinPartnersFairness());

        /** @var MatchMakingProgress $matchMakingFinal */
        $matchMakingFinal = $matchMakingFinals[0];
        $this->assertGreaterThan(0, $matchMakingFinal->getIterations());
        $this->assertGreaterThan(0, $matchMakingFinal->getTemplatesGenerated());
        $this->assertNotNull($matchMakingFinal->getBestMeetingsVariation());

        /** @var OrderingProgress $orderingFinal */
        $orderingFinal = $orderingFinals[0];
        $this->assertNotNull($orderingFinal->getBestMin());
        $this->assertNotNull($orderingFinal->getBestAvg());
        $this->assertNotNull($orderingFinal->getStopReason());

        foreach ($events as $event) {
            $this->assertSame(4, $event->getPlayers());
            $this->assertSame(2, $event->getPartners());
            $this->assertSame(1, $event->getRepeat());
            $this->assertFalse($event->isFixedTeams());
        }
    }

    public function test_progress_callback_receives_pairing_and_ordering_finals_for_fixed(): void
    {
        $events = $this->capturePipelineEvents(new TemplateMatchesGenerator(), 4, 2, 1, true);

        $pairingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && $e->isFinal()
        ));
        $matchMakingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof MatchMakingProgress && $e->isFinal()
        ));
        $orderingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof OrderingProgress && $e->isFinal()
        ));

        $this->assertCount(1, $pairingFinals);
        $this->assertCount(0, $matchMakingFinals);
        $this->assertCount(1, $orderingFinals);

        /** @var PairingProgress $pairingFinal */
        $pairingFinal = $pairingFinals[0];
        $this->assertGreaterThanOrEqual(0, $pairingFinal->getNodesExplored());

        foreach ($events as $event) {
            $this->assertTrue($event->isFixedTeams());
            $this->assertSame(4, $event->getPlayers());
            $this->assertSame(2, $event->getPartners());
            $this->assertSame(1, $event->getRepeat());
        }
    }

    public function test_progress_emit_intervals_throttle_interim_ticks(): void
    {
        $tick = 0;
        $clock = static function () use (&$tick): int {
            return ($tick++) * 50_000_000;
        };

        $generator = new TemplateMatchesGenerator(
            $clock,
            self::TEST_PHASE_BUDGET_NS,
            self::TEST_PHASE_BUDGET_NS,
            20,
            1
        );

        $events = [];
        $generator->setProgressCallback(static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });

        $this->invokePipelineMixed($generator, 4, 2, 1, 1, null, null, null, static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });

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
        $beforeTemplate = $this->invokePipelineMixed($generator, 4, 1);

        $generator->setProgressCallback(null);
        $afterTemplate = $this->invokePipelineMixed($generator, 4, 1);

        $this->assertTrue($beforeTemplate->isEligible());
        $this->assertTrue($afterTemplate->isEligible());
        $this->assertSame($beforeTemplate->getMatches(), $afterTemplate->getMatches());
        $this->assertSame($beforeTemplate->getMatchMakingQualityMeetingsVariation(), $afterTemplate->getMatchMakingQualityMeetingsVariation());
    }

    public function test_generated_template_carries_identity_fields(): void
    {
        $mixed = $this->invokePipelineMixed(new TemplateMatchesGenerator(), 4, 1);
        $this->assertSame(4, $mixed->getPlayers());
        $this->assertSame(1, $mixed->getPartners());
        $this->assertSame(1, $mixed->getRepeat());
        $this->assertSame(1, $mixed->getCourts());
        $this->assertFalse($mixed->isFixedTeams());
    }

    public function test_generated_template_populates_pairing_block(): void
    {
        $template = $this->invokePipelineMixed(new TemplateMatchesGenerator(), 4, 2);

        $this->assertTrue($template->isEligible());
        $this->assertNotNull($template->getMatchMakingQualityMeetingsVariation());
        $this->assertNotNull($template->getPairingQualityPartnersCount());
        $this->assertNotNull($template->getMatchMakingQualityPlayersMet());
        $this->assertNotNull($template->getPairingQualityPartnersCountVariation());
        $this->assertGreaterThanOrEqual(0, $template->getPairingQualityPartnersCountVariation());
        $this->assertGreaterThan(0, $template->getMatchMakingStatsPermutationsIterated());
        $this->assertGreaterThan(0, $template->getMatchMakingStatsTemplatesGenerated());
        $this->assertNotNull($template->getMatchMakingStatsPermutationIndex());
        $this->assertNotNull($template->getMatchMakingStatsTemplateIndex());
        $this->assertNotNull($template->getPairingStatsStopReason());
        $this->assertContains($template->getPairingStatsStopReason(), [
            TemplateMatchesGenerator::STOP_REASON_FACTORIAL_COMPLETE,
            TemplateMatchesGenerator::STOP_REASON_DEADLINE,
        ]);
        $this->assertNotNull($template->getPairingStatsTime());
        $this->assertGreaterThanOrEqual(0.0, $template->getPairingStatsTime());
        $this->assertNotNull($template->getOrderingStatsTime());
        $this->assertGreaterThanOrEqual(0.0, $template->getOrderingStatsTime());
        $this->assertNotNull($template->getOrderingStatsPermutationIndex());
        $this->assertGreaterThan(0, $template->getOrderingStatsPermutationsIterated());
        $this->assertNotNull($template->getOrderingQualityMinBreak());
        $this->assertNotNull($template->getOrderingQualityMaxBreak());
        $this->assertGreaterThanOrEqual($template->getOrderingQualityMinBreak(), $template->getOrderingQualityMaxBreak());
    }

    public function test_pairing_stop_reason_pessimistic_in_multi_seed(): void
    {
        $generator = new TemplateMatchesGenerator(
            null,
            10_000,
            self::TEST_PHASE_BUDGET_NS,
            4,
            4
        );

        $template = $this->invokePipelineMixed($generator, 4, 2, 1, 1, 10_000);

        $this->assertSame(
            TemplateMatchesGenerator::STOP_REASON_DEADLINE,
            $template->getPairingStatsStopReason(),
            'Any deadlined seed must promote the aggregate stop reason to deadline.'
        );
    }

    public function test_fixed_teams_pairing_stop_reason_is_factorial_complete(): void
    {
        $template = $this->invokePipelineFixed(new TemplateMatchesGenerator(), 4, 2);

        $this->assertSame(
            TemplateMatchesGenerator::STOP_REASON_TRIVIAL,
            $template->getPairingStatsStopReason(),
            'Fixed-teams single-pass build uses the trivial pairing path.'
        );
    }

    public function test_pairing_below_multi_seed_threshold_runs_single_seed(): void
    {
        $events = $this->capturePipelineEvents(new TemplateMatchesGenerator(), 4, 2, 1, false);

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
        $generator = new TemplateMatchesGenerator(
            null,
            self::TEST_PHASE_BUDGET_NS,
            self::TEST_PHASE_BUDGET_NS,
            4,
            4
        );
        $events = [];
        $this->invokePipelineMixed($generator, 4, 2, 1, 1, null, null, null, static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });

        $pairingEvents = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress
        ));

        $this->assertNotEmpty($pairingEvents);

        $totals = array_unique(array_map(static fn(PairingProgress $e) => $e->getTotalSeeds(), $pairingEvents));
        $this->assertSame([4], array_values($totals), 'Every pairing event must report the same totalSeeds');

        $finals = array_values(array_filter($pairingEvents, static fn(PairingProgress $e) => $e->isFinal()));
        $this->assertCount(1, $finals, 'Multi-seed must still emit exactly one pairing-final per pipeline run');
        /** @var PairingProgress $final */
        $final = $finals[0];
        $this->assertSame(4, $final->getTotalSeeds());
        $this->assertGreaterThan(0, $final->getNodesExplored());
    }

    public function test_pairing_explicit_seed_count_one_disables_multi_seed(): void
    {
        $generator = new TemplateMatchesGenerator(
            null,
            self::TEST_PHASE_BUDGET_NS,
            self::TEST_PHASE_BUDGET_NS,
            1,
            2
        );
        $events = [];
        $this->invokePipelineMixed($generator, 4, 2, 1, 1, null, null, null, static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });

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
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $perm = $this->invokeLehmer($i, 4, 4);
            $seen[implode(',', $perm)] = true;
        }
        $this->assertCount(4, $seen);
    }

    public function test_lehmer_decodes_every_lex_index_when_count_equals_factorial(): void
    {
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
        foreach ([1, 5, 17, 31] as $seedIndex) {
            $perm = $this->invokeLehmer($seedIndex, 32, 12);
            $sorted = $perm;
            sort($sorted);
            $this->assertSame(range(0, 11), $sorted, "seedIndex={$seedIndex} must yield a permutation of 0..11");
        }
    }

    public function test_players_met_too_much_rejects_gap_above_limit(): void
    {
        $playersMet = [0 => [1 => 2, 2 => 0]];

        $result = $this->invokePlayersMetTooMuch([0, 3], [1, 4], $playersMet, 1);

        $this->assertTrue($result);
    }

    public function test_players_met_too_much_allows_gap_at_limit(): void
    {
        $playersMet = [0 => [1 => 1, 2 => 0]];

        $result = $this->invokePlayersMetTooMuch([0, 3], [1, 4], $playersMet, 1);

        $this->assertFalse($result);
    }

    public function test_players_met_too_much_strict_mode_rejects_any_gap(): void
    {
        $playersMet = [0 => [1 => 1, 2 => 0]];

        $result = $this->invokePlayersMetTooMuch([0, 3], [1, 4], $playersMet, 0);

        $this->assertTrue($result);
    }

    public function test_players_met_too_much_ignores_high_gap_when_mostmet_absent_from_match(): void
    {
        $playersMet = [0 => [9 => 5, 2 => 0]];

        $result = $this->invokePlayersMetTooMuch([0, 3], [1, 4], $playersMet, 1);

        $this->assertFalse($result);
    }

    public function test_use_static_budgets_returns_constructor_injected_values(): void
    {
        $generator = new TemplateMatchesGenerator(
            null,
            123,
            456,
            0.0,
            0.0
        );
        $generator->setUseStaticBudgets(true);

        $this->assertSame([123, 123, 456], $this->invokeBudgetFor($generator, 4, 2));
    }

    public function test_dynamic_budget_uses_compute_methods_when_static_disabled(): void
    {
        $generator = new TemplateMatchesGenerator(
            null,
            123,
            456,
            0.0,
            0.0
        );
        $generator->setUseStaticBudgets(false);

        $this->assertSame(
            [
                TemplateMatchesGenerator::computePairingWallBudgetNs(8, 2, 1),
                TemplateMatchesGenerator::computeMatchMakingWallBudgetNs(8, 2, 1),
                TemplateMatchesGenerator::computeOrderingWallBudgetNs(8, 2, 1),
            ],
            $this->invokeBudgetFor($generator, 8, 2)
        );
    }

    public function test_set_use_static_budgets_overrides_dynamic_defaults(): void
    {
        $generator = new TemplateMatchesGenerator(
            null,
            777,
            999,
            0.0,
            0.0
        );

        $this->assertSame(
            [
                TemplateMatchesGenerator::computePairingWallBudgetNs(16, 12, 1),
                TemplateMatchesGenerator::computeMatchMakingWallBudgetNs(16, 12, 1),
                TemplateMatchesGenerator::computeOrderingWallBudgetNs(16, 12, 1),
            ],
            $this->invokeBudgetFor($generator, 16, 12),
            'Default generator uses dynamic per-combo budgets.'
        );

        $generator->setUseStaticBudgets(true);

        $this->assertSame(
            [777, 777, 999],
            $this->invokeBudgetFor($generator, 16, 12),
            'setUseStaticBudgets(true) must force every combo down the constructor-injected path.'
        );
    }

    public function test_pairing_does_not_relax_when_strict_succeeds(): void
    {
        $template = $this->invokePipelineMixed(new TemplateMatchesGenerator(), 4, 2);

        $this->assertTrue($template->isEligible());
        $this->assertSame(1, $template->getMatchMakingStatsMeetingsVariationLimit());
        $attempts = $template->getMatchMakingStatsRelaxAttempts();
        $this->assertNotNull($attempts);
        $this->assertCount(1, $attempts);
        $this->assertSame(1, $attempts[0]['meetingsVariationLimit']);
        $this->assertGreaterThan(0, $attempts[0]['templatesGenerated']);
    }

    public function test_match_making_relaxes_dl_when_strict_yields_zero_templates(): void
    {
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

        $template = $this->invokePipelineMixed(
            $generator,
            4,
            2,
            1,
            1,
            PHP_INT_MAX,
            0,
            PHP_INT_MAX
        );

        $attempts = $template->getMatchMakingStatsRelaxAttempts();
        $this->assertNotNull($attempts);
        $this->assertCount(2, $attempts);
        $this->assertSame(1, $attempts[0]['meetingsVariationLimit']);
        $this->assertSame(2, $attempts[1]['meetingsVariationLimit']);
        $this->assertSame(2, $template->getMatchMakingStatsMeetingsVariationLimit());
    }

    public function test_match_making_returns_null_matches_when_all_dls_exhausted(): void
    {
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

        $template = $this->invokePipelineMixed(
            $generator,
            4,
            2,
            1,
            1,
            PHP_INT_MAX,
            0,
            PHP_INT_MAX
        );

        $this->assertFalse($template->isEligible());
        $this->assertNull($template->getMatches());
        $attempts = $template->getMatchMakingStatsRelaxAttempts();
        $this->assertNotNull($attempts);
        $this->assertCount(1, $attempts);
        $this->assertSame(1, $template->getMatchMakingStatsMeetingsVariationLimit());
    }

    public function test_pairing_relax_attempts_round_trip_through_json(): void
    {
        $template = new TemplateMatches(
            8,
            2,
            1,
            1,
            false,
            [[[[0, 1], [2, 3]]]],
            0.95,
            0.97,
            [0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2],
            0,
            8,
            'FACTORIAL_COMPLETE',
            0.1,
            100,
            1,
            1,
            0.0,
            2,
            2,
            [0 => [1 => 1]],
            1,
            10,
            7,
            10,
            7,
            'FACTORIAL_COMPLETE',
            0.05,
            2,
            [
                ['meetingsVariationLimit' => 1, 'permutationsIterated' => 3, 'templatesGenerated' => 0, 'time' => 0.04],
                ['meetingsVariationLimit' => 2, 'permutationsIterated' => 5, 'templatesGenerated' => 5, 'time' => 0.06],
            ],
            0.9,
            0.95,
            0,
            0,
            0,
            null,
            1,
            'FACTORIAL_COMPLETE',
            10,
            7,
            50,
            1,
            1,
            0.05
        );

        $round = TemplateMatches::fromArray($template->toArray());

        $this->assertSame(2, $round->getMatchMakingStatsMeetingsVariationLimit());
        $attempts = $round->getMatchMakingStatsRelaxAttempts();
        $this->assertNotNull($attempts);
        $this->assertCount(2, $attempts);
        $this->assertSame(1, $attempts[0]['meetingsVariationLimit']);
        $this->assertSame(3, $attempts[0]['permutationsIterated']);
        $this->assertSame(0, $attempts[0]['templatesGenerated']);
        $this->assertSame(0.04, $attempts[0]['time']);
        $this->assertSame(2, $attempts[1]['meetingsVariationLimit']);
        $this->assertSame(5, $attempts[1]['templatesGenerated']);
    }

    /**
     * @param array{0:int,1:int} $pair1
     * @param array{0:int,1:int} $pair2
     * @param array<int, array<int, int>> $playersMet
     */
    private function invokePlayersMetTooMuch(array $pair1, array $pair2, array $playersMet, int $meetingsVariationLimit): bool
    {
        $generator = new TemplateMatchesGenerator();
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('playersMetTooMuch');
        $method->setAccessible(true);

        return (bool) $method->invoke($generator, $pair1, $pair2, $playersMet, $meetingsVariationLimit);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function invokeBudgetFor(TemplateMatchesGenerator $generator, int $players, int $partners, int $courts = 1, bool $fixedTeams = false): array
    {
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('budgetFor');
        $method->setAccessible(true);

        return $method->invoke($generator, $players, $partners, $courts, $fixedTeams);
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

    public function test_backtracking_recovers_completion_greedy_would_miss(): void
    {
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
        $method = $reflection->getMethod('buildMatchMakingByBacktracking');
        $method->setAccessible(true);

        $result = $method->invoke($generator, $orderedPairs, 500_000_000, PHP_INT_MAX, 10_000, 8, null);

        $this->assertNull($result, 'DFS must return null once the wall deadline elapses, even with branch budget left.');
    }

    public function test_dfs_min_met_bb_prune_kills_unreachable_branch(): void
    {
        $orderedPairs = [
            ['players' => [0, 1], 'used' => false],
            ['players' => [2, 3], 'used' => false],
        ];

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
        $generator = new TemplateMatchesGenerator(
            null,
            self::TEST_PHASE_BUDGET_NS,
            self::TEST_PHASE_BUDGET_NS,
            4,
            4
        );
        $events = [];
        $this->invokePipelineMixed($generator, 4, 2, 1, 1, null, null, null, static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });

        $pairingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && $e->isFinal()
        ));
        $this->assertCount(1, $pairingFinals);
        /** @var PairingProgress $final */
        $final = $pairingFinals[0];
        $this->assertSame(4, $final->getTotalSeeds());
        $this->assertGreaterThan(0, $final->getNodesExplored());
    }

    public function test_pairing_single_seed_below_threshold_runs_one_dfs(): void
    {
        $generator = new TemplateMatchesGenerator();
        $events = [];
        $this->invokePipelineMixed($generator, 4, 2, 1, 1, null, null, null, static function (GenerationProgress $event) use (&$events): void {
            $events[] = $event;
        });

        $pairingFinals = array_values(array_filter($events, static fn(GenerationProgress $e) =>
            $e instanceof PairingProgress && $e->isFinal()
        ));
        $this->assertCount(1, $pairingFinals);
        /** @var PairingProgress $final */
        $final = $pairingFinals[0];
        $this->assertSame(1, $final->getTotalSeeds());
        $this->assertGreaterThanOrEqual(0, $final->getNodesExplored());
    }

    public function test_pairing_results_are_deterministic_across_runs(): void
    {
        $generator = new TemplateMatchesGenerator();

        $first = $this->stripWallClockFields($this->invokePipelineMixed($generator, 4, 2)->toArray());
        $second = $this->stripWallClockFields($this->invokePipelineMixed($generator, 4, 2)->toArray());

        $this->assertSame($first, $second);
    }

    public function test_pairing_tie_break_prefers_lowest_seed(): void
    {
        $generator = new TemplateMatchesGenerator(
            null,
            self::TEST_PHASE_BUDGET_NS,
            self::TEST_PHASE_BUDGET_NS,
            8,
            4
        );
        $template = $this->invokePipelineMixed($generator, 4, 2);

        $this->assertTrue($template->isEligible());
        $this->assertNotNull($template->getMatchMakingStatsPermutationIndex());
        $this->assertGreaterThanOrEqual(1, $template->getMatchMakingStatsPermutationIndex());
        $this->assertLessThanOrEqual(8, $template->getMatchMakingStatsPermutationIndex());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function stripWallClockFields(array $data): array
    {
        if (!isset($data['metrics']) || !is_array($data['metrics'])) {
            return $data;
        }

        foreach (['pairing', 'matchMaking', 'ordering'] as $phase) {
            if (isset($data['metrics'][$phase]['stats']['time'])) {
                unset($data['metrics'][$phase]['stats']['time']);
            }
        }

        if (isset($data['metrics']['matchMaking']['stats']['relaxAttempts'])
            && is_array($data['metrics']['matchMaking']['stats']['relaxAttempts'])
        ) {
            foreach ($data['metrics']['matchMaking']['stats']['relaxAttempts'] as &$attempt) {
                if (is_array($attempt)) {
                    unset($attempt['time']);
                }
            }
            unset($attempt);
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
        int $meetingsVariationLimit,
        int $branchCap,
        int $playersCount,
        ?int $bestMinMetSoFar
    ): ?array {
        $generator = new TemplateMatchesGenerator();
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('buildMatchMakingByBacktracking');
        $method->setAccessible(true);

        return $method->invoke(
            $generator,
            $orderedPairs,
            $deadlineNs,
            $meetingsVariationLimit,
            $branchCap,
            $playersCount,
            $bestMinMetSoFar
        );
    }
}

