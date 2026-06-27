<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\Progress\MatchMakingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\PairingProgress;
use Arshavinel\PadelMiniTour\Service\TemplateMatches;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class TemplateMatchesTest extends TestCase
{
    public function test_getters_return_constructor_values(): void
    {
        $template = $this->makeTemplate();

        $this->assertSame(4, $template->getPlayers());
        $this->assertSame(1, $template->getPartners());
        $this->assertSame(1, $template->getRepeat());
        $this->assertSame(1, $template->getCourts());
        $this->assertFalse($template->isFixedTeams());

        $this->assertSame([[[[0, 1], [2, 3]]]], $template->getMatches());

        $this->assertSame(0.95, $template->getPairingQualityMinPartnersFairness());
        $this->assertSame(0.97, $template->getPairingQualityAvgPartnersFairness());
        $this->assertSame([0 => 1, 1 => 1, 2 => 1, 3 => 1], $template->getPairingQualityPartnersCount());
        $this->assertSame(1, $template->getPairingQualityPartnersCountBy(0));
        $this->assertNull($template->getPairingQualityPartnersCountBy(99));
        $this->assertSame(0, $template->getPairingQualityPartnersCountVariation());
        $this->assertSame(2, $template->getPairingQualityPairCount());
        $this->assertSame('FACTORIAL_COMPLETE', $template->getPairingStatsStopReason());
        $this->assertSame(0.04, $template->getPairingStatsTime());
        $this->assertSame(100, $template->getPairingStatsNodesExplored());
        $this->assertSame(1, $template->getPairingStatsSeedIndex());
        $this->assertSame(1, $template->getPairingStatsSeedsTotal());

        $this->assertSame(0.0, $template->getMatchMakingQualityMeetingsVariation());
        $this->assertSame(1, $template->getMatchMakingQualityMinOpponentsMet());
        $this->assertSame(3, $template->getMatchMakingQualityMaxOpponentsMet());
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], $template->getMatchMakingQualityPlayersMetBy(0));
        $this->assertNull($template->getMatchMakingQualityPlayersMetBy(99));
        $this->assertSame(1, $template->getMatchMakingQualityMatchesCount());
        $this->assertSame(2, $template->getMatchMakingStatsPermutationsIterated());
        $this->assertSame(1, $template->getMatchMakingStatsPermutationIndex());
        $this->assertSame(2, $template->getMatchMakingStatsTemplatesGenerated());
        $this->assertSame(1, $template->getMatchMakingStatsTemplateIndex());
        $this->assertSame('FACTORIAL_COMPLETE', $template->getMatchMakingStatsStopReason());
        $this->assertSame(0.04, $template->getMatchMakingStatsTime());
        $this->assertSame(1, $template->getMatchMakingStatsMeetingsVariationLimit());

        $this->assertSame('FACTORIAL_COMPLETE', $template->getOrderingStatsStopReason());
        $this->assertSame(0.95, $template->getOrderingQualityMinDistribution());
        $this->assertSame(0.97, $template->getOrderingQualityAvgDistribution());
        $this->assertSame(0, $template->getOrderingQualityMinBreak());
        $this->assertSame(0, $template->getOrderingQualityMaxBreak());
        $this->assertSame(5, $template->getOrderingStatsPermutationsIterated());
        $this->assertSame(3, $template->getOrderingStatsPermutationIndex());
        $this->assertSame(0.08, $template->getOrderingStatsTime());
    }

    public function test_is_eligible_is_true_with_matches_and_false_without(): void
    {
        $eligible = $this->makeTemplate();
        $this->assertTrue($eligible->isEligible());

        $ineligible = $this->makeBlankTemplate();
        $this->assertFalse($ineligible->isEligible());
    }

    public function test_is_usable_requires_valid_schedule(): void
    {
        $this->assertTrue($this->makeTemplate()->isUsable());
        $this->assertFalse($this->makeBlankTemplate()->isUsable());
    }

    public function test_round_trip_through_array_preserves_data(): void
    {
        $original = $this->makeTemplate();
        $roundTripped = TemplateMatches::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $roundTripped->toArray());
    }

    public function test_to_array_nests_metrics_under_dedicated_phase_keys(): void
    {
        $array = $this->makeTemplate()->toArray();

        $this->assertSame(
            ['players', 'partners', 'repeat', 'courts', 'fixedTeams', 'matches', 'metrics'],
            array_keys($array)
        );

        $this->assertIsArray($array['metrics']);
        $this->assertSame(['pairing', 'matchMaking', 'ordering'], array_keys($array['metrics']));

        foreach (['pairing', 'matchMaking', 'ordering'] as $phase) {
            $this->assertIsArray($array['metrics'][$phase]);
            $this->assertSame(['quality', 'stats'], array_keys($array['metrics'][$phase]));
        }

        $expectedPairingQualityKeys = [
            'minPartnersFairness', 'avgPartnersFairness', 'partnersCount',
            'partnersCountVariation', 'pairCount',
        ];
        $this->assertSame($expectedPairingQualityKeys, array_keys($array['metrics']['pairing']['quality']));

        $expectedPairingStatsKeys = [
            'stopReason', 'time', 'nodesExplored', 'seedIndex', 'seedsTotal',
        ];
        $this->assertSame($expectedPairingStatsKeys, array_keys($array['metrics']['pairing']['stats']));

        $expectedOrderingQualityKeys = [
            'minDistribution', 'avgDistribution', 'minBreak', 'maxBreak',
            'courtSwitches', 'courtBalance', 'roundsCount',
        ];
        $this->assertSame($expectedOrderingQualityKeys, array_keys($array['metrics']['ordering']['quality']));

        $this->assertArrayNotHasKey('pairing', $array);
        $this->assertArrayNotHasKey('sorting', $array);
        $this->assertArrayNotHasKey('estimatedGenerationTime', $array);
        $this->assertArrayNotHasKey('generationTime', $array);
    }

    public function test_from_array_handles_json_decoded_string_keys_in_players_met(): void
    {
        $jsonStyle = $this->makeJsonArray();
        $jsonStyle['metrics']['matchMaking']['quality']['playersMet'] = [
            '0' => ['1' => 2, '2' => 3],
            '1' => ['0' => 2, '2' => 1],
        ];

        $template = TemplateMatches::fromArray($jsonStyle);

        $this->assertSame([1 => 2, 2 => 3], $template->getMatchMakingQualityPlayersMetBy(0));
        $this->assertSame(2, $template->getMatchMakingQualityPlayersMetBy(0)[1]);
        $this->assertSame(3, $template->getMatchMakingQualityPlayersMetBy(0)[2]);
    }

    public function test_from_array_handles_missing_optional_diagnostics(): void
    {
        $minimal = TemplateMatches::fromArray([
            'players' => 4,
            'partners' => 1,
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
            'matches' => null,
            'metrics' => [
                'pairing' => [
                    'quality' => ['minPartnersFairness' => null, 'avgPartnersFairness' => null],
                    'stats' => [],
                ],
                'matchMaking' => ['quality' => [], 'stats' => []],
                'ordering' => ['quality' => [], 'stats' => []],
            ],
        ]);

        $this->assertFalse($minimal->isEligible());
        $this->assertNull($minimal->getMatches());
        $this->assertNull($minimal->getPairingQualityMinPartnersFairness());
        $this->assertNull($minimal->getPairingStatsStopReason());
        $this->assertNull($minimal->getPairingStatsTime());
        $this->assertNull($minimal->getMatchMakingQualityMeetingsVariation());
        $this->assertNull($minimal->getOrderingStatsStopReason());
        $this->assertNull($minimal->getOrderingQualityMinDistribution());
        $this->assertNull($minimal->getOrderingQualityAvgDistribution());
        $this->assertNull($minimal->getOrderingQualityMinBreak());
        $this->assertNull($minimal->getOrderingQualityMaxBreak());
        $this->assertNull($minimal->getOrderingStatsTime());
    }

    public function test_from_array_throws_when_identity_keys_are_missing(): void
    {
        foreach (['players', 'partners', 'repeat', 'courts', 'fixedTeams'] as $omittedKey) {
            $data = $this->makeJsonArray();
            unset($data[$omittedKey]);

            $thrown = null;
            try {
                TemplateMatches::fromArray($data);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, "Expected exception when omitting identity key: {$omittedKey}");
            $this->assertStringContainsString($omittedKey, $thrown->getMessage());
        }
    }

    public function test_from_array_throws_when_metrics_object_is_missing(): void
    {
        $data = $this->makeJsonArray();
        unset($data['metrics']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('metrics');
        TemplateMatches::fromArray($data);
    }

    public function test_from_array_throws_when_metrics_phase_sections_are_missing(): void
    {
        foreach (['pairing', 'matchMaking', 'ordering'] as $phase) {
            $data = $this->makeJsonArray();
            unset($data['metrics'][$phase]);

            $thrown = null;
            try {
                TemplateMatches::fromArray($data);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, "Expected exception when omitting metrics phase: {$phase}");
            $this->assertStringContainsString($phase, $thrown->getMessage());
        }
    }

    public function test_from_array_rejects_v3_top_level_pairing_and_sorting_keys(): void
    {
        foreach (['pairing', 'sorting'] as $legacyKey) {
            $data = $this->makeJsonArray();
            $data[$legacyKey] = ['quality' => [], 'stats' => []];

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/legacy top-level key/');
            TemplateMatches::fromArray($data);
        }
    }

    public function test_from_array_rejects_legacy_top_level_timing_keys(): void
    {
        foreach (['estimatedGenerationTime', 'generationTime'] as $forbidden) {
            $data = $this->makeJsonArray();
            $data[$forbidden] = 1;

            $thrown = null;
            try {
                TemplateMatches::fromArray($data);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, "Expected exception when including legacy key: {$forbidden}");
            $this->assertStringContainsString($forbidden, $thrown->getMessage());
        }
    }

    public function test_from_array_requires_partners_fairness_keys(): void
    {
        foreach (['minPartnersFairness', 'avgPartnersFairness'] as $omitted) {
            $data = $this->makeJsonArray();
            unset($data['metrics']['pairing']['quality'][$omitted]);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage($omitted);
            TemplateMatches::fromArray($data);
        }
    }

    public function test_from_array_rejects_legacy_has_different_partners_number(): void
    {
        $data = $this->makeJsonArray();
        $data['metrics']['pairing']['quality']['hasDifferentPartnersNumber'] = false;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/hasDifferentPartnersNumber/');
        TemplateMatches::fromArray($data);
    }

    public function test_from_progress_builds_partial_template_from_events(): void
    {
        $pairing = new PairingProgress(
            8,
            2,
            1,
            false,
            500_000_000,
            1_000_000_000,
            false,
            500,
            0.88,
            0.93,
            1,
            1,
            [0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2],
            0,
            44,
            null
        );
        $matchMaking = new MatchMakingProgress(
            8,
            2,
            1,
            false,
            600_000_000,
            1_000_000_000,
            false,
            42,
            7,
            3.14,
            1,
            1,
            17,
            5,
            12,
            [0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2],
            [0 => [1 => 1, 2 => 1]],
            0,
            null,
            2
        );
        $ordering = new OrderingProgress(
            8,
            2,
            1,
            false,
            200_000_000,
            1_000_000_000,
            false,
            5,
            0.88,
            0.93,
            null,
            3,
            1,
            2,
            4
        );

        $snapshot = TemplateMatches::fromProgress(8, 2, 1, 1, false, $pairing, $matchMaking, $ordering);

        $this->assertSame(8, $snapshot->getPlayers());
        $this->assertSame(2, $snapshot->getPartners());
        $this->assertSame(1, $snapshot->getRepeat());
        $this->assertFalse($snapshot->isFixedTeams());

        $this->assertNull($snapshot->getMatches());
        $this->assertFalse($snapshot->isEligible());

        $this->assertSame(0.88, $snapshot->getPairingQualityMinPartnersFairness());
        $this->assertSame(500, $snapshot->getPairingStatsNodesExplored());
        $this->assertSame(44, $snapshot->getPairingQualityPairCount());
        $this->assertSame([0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2], $snapshot->getPairingQualityPartnersCount());
        $this->assertSame(0, $snapshot->getPairingQualityPartnersCountVariation());
        $this->assertNull($snapshot->getPairingStatsStopReason(), 'Aggregate stop reason is null on interim ticks.');
        $this->assertEquals(0.5, $snapshot->getPairingStatsTime(), '', 1e-9);

        $this->assertSame(3.14, $snapshot->getMatchMakingQualityMeetingsVariation());
        $this->assertSame(42, $snapshot->getMatchMakingStatsPermutationsIterated());
        $this->assertSame(7, $snapshot->getMatchMakingStatsTemplatesGenerated());
        $this->assertSame(17, $snapshot->getMatchMakingStatsPermutationIndex());
        $this->assertSame(5, $snapshot->getMatchMakingStatsTemplateIndex());
        $this->assertSame(12, $snapshot->getMatchMakingQualityMatchesCount());
        $this->assertSame([0 => [1 => 1, 2 => 1]], $snapshot->getMatchMakingQualityPlayersMet());

        $this->assertSame(0.88, $snapshot->getOrderingQualityMinDistribution());
        $this->assertSame(0.93, $snapshot->getOrderingQualityAvgDistribution());
        $this->assertSame(5, $snapshot->getOrderingStatsPermutationsIterated());
        $this->assertSame(3, $snapshot->getOrderingStatsPermutationIndex());
        $this->assertSame(1, $snapshot->getOrderingQualityMinBreak());
        $this->assertSame(2, $snapshot->getOrderingQualityMaxBreak());
        $this->assertSame(4, $snapshot->getOrderingQualityCourtSwitches());
        $this->assertNull($snapshot->getOrderingStatsStopReason());
        $this->assertEquals(0.2, $snapshot->getOrderingStatsTime(), '', 1e-9);
    }

    public function test_from_progress_accepts_null_events_at_run_start(): void
    {
        $snapshot = TemplateMatches::fromProgress(4, 1, 1, 1, false, null, null, null);

        $this->assertSame(4, $snapshot->getPlayers());
        $this->assertFalse($snapshot->isEligible());
        $this->assertNull($snapshot->getPairingQualityMinPartnersFairness());
        $this->assertNull($snapshot->getPairingStatsNodesExplored());
        $this->assertNull($snapshot->getPairingStatsTime());
        $this->assertNull($snapshot->getMatchMakingQualityMeetingsVariation());
        $this->assertNull($snapshot->getOrderingQualityMinDistribution());
        $this->assertNull($snapshot->getOrderingStatsStopReason());
        $this->assertNull($snapshot->getOrderingStatsTime());
    }

    public function test_from_progress_carries_aggregate_pairing_stop_reason_on_final_event(): void
    {
        $finalPairing = new PairingProgress(
            8,
            2,
            1,
            false,
            900_000_000,
            1_000_000_000,
            true,
            500_000,
            0.9,
            0.95,
            1,
            1,
            [0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2],
            0,
            44,
            'DEADLINE'
        );

        $snapshot = TemplateMatches::fromProgress(8, 2, 1, 1, false, $finalPairing, null, null);
        $this->assertSame('DEADLINE', $snapshot->getPairingStatsStopReason());
    }

    public function test_constructor_exposes_identity_fields(): void
    {
        $template = $this->makeBlankTemplate(8, 4, 2, true);

        $this->assertSame(8, $template->getPlayers());
        $this->assertSame(4, $template->getPartners());
        $this->assertSame(2, $template->getRepeat());
        $this->assertTrue($template->isFixedTeams());
    }

    private function makeTemplate(): TemplateMatches
    {
        return new TemplateMatches(
            4,
            1,
            1,
            1,
            false,
            [[[[0, 1], [2, 3]]]],
            0.95,
            0.97,
            [0 => 1, 1 => 1, 2 => 1, 3 => 1],
            0,
            2,
            'FACTORIAL_COMPLETE',
            0.04,
            100,
            1,
            1,
            0.0,
            1,
            3,
            [
                0 => [1 => 1, 2 => 1, 3 => 1],
                1 => [0 => 1, 2 => 1, 3 => 1],
                2 => [0 => 1, 1 => 1, 3 => 1],
                3 => [0 => 1, 1 => 1, 2 => 1],
            ],
            1,
            2,
            1,
            2,
            1,
            'FACTORIAL_COMPLETE',
            0.04,
            1,
            null,
            0.95,
            0.97,
            0,
            0,
            0,
            null,
            1,
            'FACTORIAL_COMPLETE',
            5,
            3,
            50,
            1,
            1,
            0.08
        );
    }

    private function makeBlankTemplate(int $players = 4, int $partners = 1, int $repeat = 1, bool $fixedTeams = false): TemplateMatches
    {
        return new TemplateMatches(
            $players,
            $partners,
            $repeat,
            1,
            $fixedTeams,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeJsonArray(): array
    {
        return $this->makeTemplate()->toArray();
    }
}
