<?php

namespace Tests\Unit;

use Arshavinel\PadelMiniTour\Service\TemplateMatchesSchemaMigrator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

final class TemplateMatchesSchemaMigratorTest extends TestCase
{
    public function test_legacy_wrapper_maps_stats_and_wraps_matches(): void
    {
        $decoded = [
            0,
            [
                'matches' => [
                    [[0, 1], [2, 3]],
                    [[0, 2], [1, 3]],
                ],
                'meetingsVariation' => 1.5,
                'partnersCount' => [1, 1, 1, 1],
                'playersMet' => [
                    0 => [1 => 1, 2 => 1, 3 => 1],
                    1 => [0 => 1, 2 => 1, 3 => 1],
                    2 => [0 => 1, 1 => 1, 3 => 1],
                    3 => [0 => 1, 1 => 1, 2 => 1],
                ],
                'hasDifferentPartnersNumber' => false,
                'permutationsIterated' => 10,
                'permutationIndex' => 3,
                'templatesGenerated' => 4,
                'templateIndex' => 2,
                'estimatedGenerationTime' => 99,
                'generationTime' => '12.5',
            ],
        ];

        $template = (new TemplateMatchesSchemaMigrator())->migrate($decoded, [
            'players' => 4,
            'partners' => 1,
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
        ]);

        $this->assertSame(4, $template->getPlayers());
        $this->assertSame(1, $template->getCourts());
        $this->assertNotNull($template->getMatches());
        $this->assertCount(1, $template->getMatches());
        $this->assertCount(2, $template->getMatches()[0]);
        $this->assertSame(10, $template->getMatchMakingStatsPermutationsIterated());
        $this->assertSame(3, $template->getMatchMakingStatsPermutationIndex());
        $this->assertSame(4, $template->getMatchMakingStatsTemplatesGenerated());
        $this->assertSame(2, $template->getMatchMakingStatsTemplateIndex());
        $this->assertSame(12.5, $template->getMatchMakingStatsTime());
        $this->assertNull($template->getPairingStatsTime());
        $this->assertSame(1.5, $template->getMatchMakingQualityMeetingsVariation());
        $this->assertSame([1, 1, 1, 1], $template->getPairingQualityPartnersCount());
    }

    public function test_legacy_null_matches_preserves_null_quality_flats(): void
    {
        $decoded = [
            0,
            [
                'matches' => null,
                'meetingsVariation' => null,
                'partnersCount' => null,
                'playersMet' => null,
                'hasDifferentPartnersNumber' => null,
                'permutationsIterated' => 350000,
                'permutationIndex' => null,
                'templatesGenerated' => 0,
                'templateIndex' => null,
                'estimatedGenerationTime' => 220,
                'generationTime' => '75.13',
            ],
        ];

        $template = (new TemplateMatchesSchemaMigrator())->migrate($decoded, [
            'players' => 11,
            'partners' => 8,
            'repeat' => 1,
            'courts' => 1,
            'fixedTeams' => false,
        ]);

        $this->assertNull($template->getMatches());
        $this->assertNull($template->getMatchMakingQualityMeetingsVariation());
        $this->assertNull($template->getPairingQualityPartnersCount());
        $this->assertSame(350000, $template->getMatchMakingStatsPermutationsIterated());
        $this->assertSame(75.13, $template->getMatchMakingStatsTime());
        $this->assertNull($template->getPairingQualityMinPartnersFairness());
    }
}
