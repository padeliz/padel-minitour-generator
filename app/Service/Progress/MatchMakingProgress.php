<?php

namespace Arshavinel\PadelMiniTour\Service\Progress;

/**
 * Progress events during match-making (grouping 2-player pairs into 4-player matches).
 */
final class MatchMakingProgress extends GenerationProgress
{
    private int $iterations;
    private int $templatesGenerated;
    private ?float $bestMeetingsVariation;
    private int $currentSeed;
    private int $totalSeeds;
    private ?int $bestPermutationIndex;
    private ?int $bestTemplateIndex;
    private ?int $matchesCount;
    /** @var array<int, int>|null */
    private ?array $partnersCount;
    /** @var array<int, array<int, int>>|null */
    private ?array $playersMet;
    private ?int $partnersCountVariation;
    private ?string $aggregateStopReason;
    private int $currentMeetingsVariationLimit;

    /**
     * @param array<int, int>|null             $partnersCount
     * @param array<int, array<int, int>>|null $playersMet
     */
    public function __construct(
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        int $elapsedNs,
        int $budgetNs,
        bool $isFinal,
        int $iterations,
        int $templatesGenerated,
        ?float $bestMeetingsVariation,
        int $currentSeed = 1,
        int $totalSeeds = 1,
        ?int $bestPermutationIndex = null,
        ?int $bestTemplateIndex = null,
        ?int $matchesCount = null,
        ?array $partnersCount = null,
        ?array $playersMet = null,
        ?int $partnersCountVariation = null,
        ?string $aggregateStopReason = null,
        int $currentMeetingsVariationLimit = 1
    ) {
        parent::__construct(
            self::PHASE_MATCH_MAKING,
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            $elapsedNs,
            $budgetNs,
            $isFinal
        );
        $this->iterations = $iterations;
        $this->templatesGenerated = $templatesGenerated;
        $this->bestMeetingsVariation = $bestMeetingsVariation;
        $this->currentSeed = $currentSeed;
        $this->totalSeeds = $totalSeeds;
        $this->bestPermutationIndex = $bestPermutationIndex;
        $this->bestTemplateIndex = $bestTemplateIndex;
        $this->matchesCount = $matchesCount;
        $this->partnersCount = $partnersCount;
        $this->playersMet = $playersMet;
        $this->partnersCountVariation = $partnersCountVariation;
        $this->aggregateStopReason = $aggregateStopReason;
        $this->currentMeetingsVariationLimit = $currentMeetingsVariationLimit;
    }

    public function getIterations(): int
    {
        return $this->iterations;
    }

    public function getTemplatesGenerated(): int
    {
        return $this->templatesGenerated;
    }

    public function getBestMeetingsVariation(): ?float
    {
        return $this->bestMeetingsVariation;
    }

    public function getCurrentSeed(): int
    {
        return $this->currentSeed;
    }

    public function getTotalSeeds(): int
    {
        return $this->totalSeeds;
    }

    public function getBestPermutationIndex(): ?int
    {
        return $this->bestPermutationIndex;
    }

    public function getBestTemplateIndex(): ?int
    {
        return $this->bestTemplateIndex;
    }

    public function getMatchesCount(): ?int
    {
        return $this->matchesCount;
    }

    public function getPartnersCount(): ?array
    {
        return $this->partnersCount;
    }

    public function getPlayersMet(): ?array
    {
        return $this->playersMet;
    }

    public function getPartnersCountVariation(): ?int
    {
        return $this->partnersCountVariation;
    }

    public function getAggregateStopReason(): ?string
    {
        return $this->aggregateStopReason;
    }

    public function getCurrentMeetingsVariationLimit(): int
    {
        return $this->currentMeetingsVariationLimit;
    }
}
