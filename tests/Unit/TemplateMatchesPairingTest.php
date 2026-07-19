<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\PartnersFairnessScorer;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator;

require_once __DIR__ . '/../../vendor/autoload.php';

final class TemplateMatchesPairingTest extends GeneratorTestCase
{
    public function test_compute_pairing_dfs_branch_cap_clamps_to_formula(): void
    {
        $this->assertSame(10_000, TemplateMatchesGenerator::computePairingDfsBranchCap(1));
        $this->assertSame(10_000, TemplateMatchesGenerator::computePairingDfsBranchCap(20));
        $this->assertSame(21_200, TemplateMatchesGenerator::computePairingDfsBranchCap(48));
        $this->assertSame(50_000, TemplateMatchesGenerator::computePairingDfsBranchCap(200));
    }

    public function test_order_pairing_candidates_sorts_by_edge_penalty_then_lehmer(): void
    {
        $generator = new TemplateMatchesGenerator(null, 1, 12);
        $reflection = new \ReflectionClass(TemplateMatchesGenerator::class);
        $method = $reflection->getMethod('orderPairingCandidates');
        $method->setAccessible(true);

        $playersCount = 8;
        $partnersPerPlayer = 4;
        $activePlayer = 0;
        $candidates = range(1, $playersCount - 1);
        $candidateOrder = range(0, $playersCount - 2);

        /** @var array<int, int> $ordered */
        $ordered = $method->invoke(
            $generator,
            $candidates,
            $candidateOrder,
            $activePlayer,
            $playersCount,
            $partnersPerPlayer
        );

        $penalties = [];
        foreach ($ordered as $q) {
            $penalties[] = PartnersFairnessScorer::edgePenalty($activePlayer, $q, $playersCount, $partnersPerPlayer);
        }

        $sortedPenalties = $penalties;
        sort($sortedPenalties, SORT_NUMERIC);
        $this->assertSame($sortedPenalties, $penalties);
    }

    public function test_fifteen_nine_pairing_finds_complete_near_regular_pool(): void
    {
        $generator = new TemplateMatchesGenerator(null, 1, 12);
        $result = $this->invokePairingPhase($generator, 15, 9, 20_000_000_000);

        $this->assertGreaterThan(0, $result['pairCount']);
        $this->assertContains($result['pairCount'], [66, 67]);
        $this->assertLessThanOrEqual(1, $result['partnersCountVariation']);
        $this->assertNotNull($result['minPartnersFairness']);
        $this->assertNotNull($result['avgPartnersFairness']);
    }

    public function test_pairing_phase_is_deterministic_for_twelve_eight(): void
    {
        // Freeze the wall clock so the budget never elapses; otherwise two runs under load can
        // hit DEADLINE at different DFS depths and disagree on best-so-far. Single-seed keeps the
        // search on the identity path (see TemplateMatchesGenerator::$multiSeedCountPairing).
        $clock = static fn (): int => 0;
        $makeGenerator = static fn (): TemplateMatchesGenerator => new TemplateMatchesGenerator(
            $clock,
            TemplateMatchesGenerator::DEFAULT_OUTER_WALL_BUDGET_NS,
            TemplateMatchesGenerator::DEFAULT_SORT_WALL_BUDGET_NS,
            1
        );

        $first = $this->invokePairingPhase($makeGenerator(), 12, 8, self::TEST_PHASE_BUDGET_NS);
        $second = $this->invokePairingPhase($makeGenerator(), 12, 8, self::TEST_PHASE_BUDGET_NS);

        $this->assertSame($first['stopReason'], $second['stopReason']);
        $this->assertSame($first['pairCount'], $second['pairCount']);
        $this->assertSame($first['seedIndex'], $second['seedIndex']);
        $this->assertSame(
            array_map(static fn(array $pair): array => $pair['players'], $first['pairs']),
            array_map(static fn(array $pair): array => $pair['players'], $second['pairs'])
        );
        $this->assertEqualsWithDelta($first['minPartnersFairness'], $second['minPartnersFairness'], 1e-9);
        $this->assertEqualsWithDelta($first['avgPartnersFairness'], $second['avgPartnersFairness'], 1e-9);
    }
}
