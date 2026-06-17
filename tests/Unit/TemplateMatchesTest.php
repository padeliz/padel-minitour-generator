<?php

namespace Tests\Unit;

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

        $this->assertSame(0.0, $template->getPairingMeetingsVariation());
        $this->assertSame([0 => 1, 1 => 1, 2 => 1, 3 => 1], $template->getPairingPartnersCount());
        $this->assertSame(1, $template->getPairingPartnersCountBy(0));
        $this->assertNull($template->getPairingPartnersCountBy(99));
        $this->assertSame([0 => 1, 1 => 1, 2 => 1], $template->getPairingPlayersMetBy(3));
        $this->assertNull($template->getPairingPlayersMetBy(99));
        $this->assertSame(0, $template->getPairingPartnersCountVariation());
        $this->assertSame(1, $template->getPairingBestMatchesCount());
        $this->assertSame('FACTORIAL_COMPLETE', $template->getPairingStopReason());
        $this->assertSame(0.04, $template->getPairingTime());
        $this->assertSame(2, $template->getPairingPermutationsIterated());
        $this->assertSame(1, $template->getPairingPermutationIndex());
        $this->assertSame(2, $template->getPairingTemplatesGenerated());
        $this->assertSame(1, $template->getPairingTemplateIndex());

        $this->assertSame('FACTORIAL_COMPLETE', $template->getSortingStopReason());
        $this->assertSame(0.95, $template->getSortingMinDistribution());
        $this->assertSame(0.97, $template->getSortingAvgDistribution());
        $this->assertSame(0, $template->getSortingMinBreak());
        $this->assertSame(0, $template->getSortingMaxBreak());
        $this->assertSame(5, $template->getSortingPermutationsIterated());
        $this->assertSame(3, $template->getSortingPermutationIndex());
        $this->assertSame(0.08, $template->getSortingTime());
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

    public function test_to_array_nests_pairing_and_sorting_under_dedicated_keys(): void
    {
        $array = $this->makeTemplate()->toArray();

        // Identity + matches live at the root; legacy estimatedGenerationTime/generationTime are gone.
        $this->assertSame(['players', 'partners', 'repeat', 'courts', 'fixedTeams', 'matches', 'pairing', 'sorting'], array_keys($array));

        $this->assertIsArray($array['pairing']);
        $this->assertIsArray($array['sorting']);

        $expectedPairingKeys = [
            'meetingsVariation', 'permutationsIterated', 'permutationIndex',
            'templatesGenerated', 'templateIndex', 'partnersCount', 'playersMet',
            'partnersCountVariation', 'bestMatchesCount', 'stopReason', 'time',
            'meetingsVariationLimit', 'relaxAttempts',
        ];
        $this->assertSame($expectedPairingKeys, array_keys($array['pairing']));

        $expectedSortingKeys = [
            'stopReason', 'minDistribution', 'avgDistribution',
            'permutationsIterated', 'permutationIndex',
            'minBreak', 'maxBreak', 'courtSwitches', 'courtBalance',
            'nodesExplored', 'seedIndex', 'seedsTotal', 'time',
        ];
        $this->assertSame($expectedSortingKeys, array_keys($array['sorting']));

        $this->assertArrayNotHasKey('hasDifferentPartnersNumber', $array['pairing'], 'Legacy bool must not leak back into the schema.');
        $this->assertArrayNotHasKey('estimatedGenerationTime', $array);
        $this->assertArrayNotHasKey('generationTime', $array);
    }

    public function test_from_array_handles_json_decoded_string_keys_in_players_met(): void
    {
        $jsonStyle = $this->makeJsonArray();
        $jsonStyle['pairing']['playersMet'] = [
            '0' => ['1' => 2, '2' => 3],
            '1' => ['0' => 2, '2' => 1],
        ];

        $template = TemplateMatches::fromArray($jsonStyle);

        $this->assertSame([1 => 2, 2 => 3], $template->getPairingPlayersMetBy(0));
        $this->assertSame(2, $template->getPairingPlayersMetBy(0)[1]);
        $this->assertSame(3, $template->getPairingPlayersMetBy(0)[2]);
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
            'pairing' => [],
            'sorting' => [],
        ]);

        $this->assertFalse($minimal->isEligible());
        $this->assertNull($minimal->getMatches());
        $this->assertNull($minimal->getPairingMeetingsVariation());
        $this->assertNull($minimal->getPairingStopReason());
        $this->assertNull($minimal->getPairingTime());
        $this->assertNull($minimal->getSortingStopReason());
        $this->assertNull($minimal->getSortingMinDistribution());
        $this->assertNull($minimal->getSortingAvgDistribution());
        $this->assertNull($minimal->getSortingMinBreak());
        $this->assertNull($minimal->getSortingMaxBreak());
        $this->assertNull($minimal->getSortingTime());
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

    public function test_from_array_throws_when_pairing_or_sorting_objects_are_missing(): void
    {
        foreach (['pairing', 'sorting'] as $omittedKey) {
            $data = $this->makeJsonArray();
            unset($data[$omittedKey]);

            $thrown = null;
            try {
                TemplateMatches::fromArray($data);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, "Expected exception when omitting nested key: {$omittedKey}");
            $this->assertStringContainsString($omittedKey, $thrown->getMessage());
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

    public function test_from_array_rejects_legacy_pairing_has_different_partners_number(): void
    {
        $data = $this->makeJsonArray();
        $data['pairing']['hasDifferentPartnersNumber'] = false;

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
            null
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

        $snapshot = TemplateMatches::fromProgress(8, 2, 1, 1, false, $pairing, $ordering);

        $this->assertSame(8, $snapshot->getPlayers());
        $this->assertSame(2, $snapshot->getPartners());
        $this->assertSame(1, $snapshot->getRepeat());
        $this->assertFalse($snapshot->isFixedTeams());

        $this->assertNull($snapshot->getMatches());
        $this->assertFalse($snapshot->isEligible());

        $this->assertSame(3.14, $snapshot->getPairingMeetingsVariation());
        $this->assertSame(42, $snapshot->getPairingPermutationsIterated());
        $this->assertSame(7, $snapshot->getPairingTemplatesGenerated());
        $this->assertSame(17, $snapshot->getPairingPermutationIndex());
        $this->assertSame(5, $snapshot->getPairingTemplateIndex());
        $this->assertSame(12, $snapshot->getPairingBestMatchesCount());
        $this->assertSame([0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2], $snapshot->getPairingPartnersCount());
        $this->assertSame([0 => [1 => 1, 2 => 1]], $snapshot->getPairingPlayersMet());
        $this->assertSame(0, $snapshot->getPairingPartnersCountVariation());
        $this->assertNull($snapshot->getPairingStopReason(), 'Aggregate stop reason is null on interim ticks.');
        $this->assertEquals(0.5, $snapshot->getPairingTime(), '', 1e-9);

        $this->assertSame(0.88, $snapshot->getSortingMinDistribution());
        $this->assertSame(0.93, $snapshot->getSortingAvgDistribution());
        $this->assertSame(5, $snapshot->getSortingPermutationsIterated());
        $this->assertSame(3, $snapshot->getSortingPermutationIndex());
        $this->assertSame(1, $snapshot->getSortingMinBreak());
        $this->assertSame(2, $snapshot->getSortingMaxBreak());
        $this->assertSame(4, $snapshot->getSortingCourtSwitches());
        $this->assertNull($snapshot->getSortingStopReason());
        $this->assertEquals(0.2, $snapshot->getSortingTime(), '', 1e-9);
    }

    public function test_from_progress_accepts_null_events_at_run_start(): void
    {
        $snapshot = TemplateMatches::fromProgress(4, 1, 1, 1, false, null, null);

        $this->assertSame(4, $snapshot->getPlayers());
        $this->assertFalse($snapshot->isEligible());
        $this->assertNull($snapshot->getPairingMeetingsVariation());
        $this->assertNull($snapshot->getPairingPermutationsIterated());
        $this->assertNull($snapshot->getPairingTime());
        $this->assertNull($snapshot->getSortingMinDistribution());
        $this->assertNull($snapshot->getSortingStopReason());
        $this->assertNull($snapshot->getSortingTime());
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
            10_000,
            2.5,
            2,
            2,
            17,
            5,
            12,
            [0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2],
            [0 => [1 => 1]],
            0,
            'DEADLINE'
        );

        $snapshot = TemplateMatches::fromProgress(8, 2, 1, 1, false, $finalPairing, null);
        $this->assertSame('DEADLINE', $snapshot->getPairingStopReason());
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
            0.0,
            2,
            1,
            2,
            1,
            [0 => 1, 1 => 1, 2 => 1, 3 => 1],
            [
                0 => [1 => 1, 2 => 1, 3 => 1],
                1 => [0 => 1, 2 => 1, 3 => 1],
                2 => [0 => 1, 1 => 1, 3 => 1],
                3 => [0 => 1, 1 => 1, 2 => 1],
            ],
            0,
            1,
            'FACTORIAL_COMPLETE',
            0.04,
            'FACTORIAL_COMPLETE',
            0.95,
            0.97,
            5,
            3,
            0,
            0,
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
