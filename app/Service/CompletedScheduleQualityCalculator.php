<?php

namespace Arshavinel\PadelMiniTour\Service;

/**
 * Computes all metrics.*.quality fields from a completed schedule (identity + matches).
 */
final class CompletedScheduleQualityCalculator
{
    private PartnersFairnessScorer $partnersFairnessScorer;
    private PlayingFairnessScorer $playingFairnessScorer;
    private PlayerDistributionScorer $distributionScorer;

    public function __construct(
        ?PartnersFairnessScorer $partnersFairnessScorer = null,
        ?PlayingFairnessScorer $playingFairnessScorer = null,
        ?PlayerDistributionScorer $distributionScorer = null
    ) {
        $this->partnersFairnessScorer = $partnersFairnessScorer ?? new PartnersFairnessScorer();
        $this->playingFairnessScorer = $playingFairnessScorer ?? new PlayingFairnessScorer();
        $this->distributionScorer = $distributionScorer ?? new PlayerDistributionScorer();
    }

    /**
     * @param array<int, array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>> $matchesByCourt
     * @return array{
     *     pairing: array{
     *         minPartnersFairness: float,
     *         avgPartnersFairness: float,
     *         partnersCount: array<int, int>,
     *         partnersCountVariation: int,
     *         pairCount: int
     *     },
     *     matchMaking: array{
     *         meetingsVariation: float,
     *         minOpponentsMet: int|null,
     *         maxOpponentsMet: int|null,
     *         playersMet: array<int, array<int, int>>,
     *         matchesCount: int,
     *         minPlayingFairness: float,
     *         avgPlayingFairness: float,
     *         maxPlayingFairnessPenalty: float
     *     },
     *     ordering: array{
     *         minDistribution: float,
     *         avgDistribution: float,
     *         minBreak: int,
     *         maxBreak: int,
     *         consecutiveMinBreaks: int|null,
     *         consecutiveMaxBreaks: int|null,
     *         courtSwitches: int,
     *         courtBalance: float,
     *         roundsCount: int
     *     }
     * }
     */
    public function compute(
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams,
        array $matchesByCourt
    ): array {
        unset($repeat, $fixedTeams);

        $playerIndices = range(0, $players - 1);
        $pairs = TemplateMatchDerivation::pairsFromMatches($matchesByCourt);
        $partnersCount = TemplateMatchDerivation::partnersCountFromMatches($matchesByCourt, $players);
        $poolScores = $this->partnersFairnessScorer->scorePool($pairs, $players, $partners);

        $playersMet = TemplateMatchDerivation::playersMetFromMatches($matchesByCourt);
        $opponentsBounds = OpponentsMetSummary::fromPlayersMet($playersMet, $players);
        $playingFairness = $this->playingFairnessScorer->scoreTemplate($matchesByCourt, $players);

        $distribution = $this->distributionScorer->scoreAll($playerIndices, $matchesByCourt);
        $breaks = RoundScheduleBreakAnalyzer::computeBreakMetrics($matchesByCourt, $playerIndices);
        $streaks = RoundScheduleBreakAnalyzer::analyze(
            $matchesByCourt,
            $playerIndices,
            $breaks['minBreak'],
            $breaks['maxBreak']
        );
        $court = CourtScheduleMetrics::score($matchesByCourt, $playerIndices, $courts);

        return [
            'pairing' => [
                'minPartnersFairness' => $poolScores['min'],
                'avgPartnersFairness' => $poolScores['avg'],
                'partnersCount' => $partnersCount,
                'partnersCountVariation' => TemplateMatchDerivation::partnersCountVariation($partnersCount),
                'pairCount' => count($pairs),
            ],
            'matchMaking' => [
                'meetingsVariation' => MatchMakingLex::meetingsVariation($playersMet),
                'minOpponentsMet' => $opponentsBounds['min'],
                'maxOpponentsMet' => $opponentsBounds['max'],
                'playersMet' => $playersMet,
                'matchesCount' => (int) TemplateMatchDerivation::matchesCount($matchesByCourt),
                'minPlayingFairness' => $playingFairness['min'],
                'avgPlayingFairness' => $playingFairness['avg'],
                'maxPlayingFairnessPenalty' => $playingFairness['maxPenalty'],
            ],
            'ordering' => [
                'minDistribution' => $distribution['min'],
                'avgDistribution' => $distribution['avg'],
                'minBreak' => $breaks['minBreak'],
                'maxBreak' => $breaks['maxBreak'],
                'consecutiveMinBreaks' => $streaks['consecutiveMinBreaks'],
                'consecutiveMaxBreaks' => $streaks['consecutiveMaxBreaks'],
                'courtSwitches' => $court['courtSwitches'],
                'courtBalance' => $court['courtBalance'],
                'roundsCount' => (int) TemplateMatchDerivation::roundsCount($matchesByCourt),
            ],
        ];
    }
}
