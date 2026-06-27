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
        $budgetNs = 20_000_000_000;
        $first = $this->invokePairingPhase(new TemplateMatchesGenerator(null, 1, 12), 12, 8, $budgetNs);
        $second = $this->invokePairingPhase(new TemplateMatchesGenerator(null, 1, 12), 12, 8, $budgetNs);

        $this->assertSame($first['pairCount'], $second['pairCount']);
        $this->assertSame($first['minPartnersFairness'], $second['minPartnersFairness']);
        $this->assertSame($first['avgPartnersFairness'], $second['avgPartnersFairness']);
        $this->assertSame(
            array_map(static fn(array $pair): array => $pair['players'], $first['pairs']),
            array_map(static fn(array $pair): array => $pair['players'], $second['pairs'])
        );
    }
}
