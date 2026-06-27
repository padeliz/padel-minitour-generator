<?php

namespace Arshavinel\PadelMiniTour\Service\Progress;

/**
 * Progress events during pairing (building the 2-player partner pool).
 */
final class PairingProgress extends GenerationProgress
{
    private int $nodesExplored;
    private ?float $bestMinPartnersFairness;
    private ?float $bestAvgPartnersFairness;
    private int $currentSeed;
    private int $totalSeeds;
    /** @var array<int, int>|null */
    private ?array $partnersCount;
    private ?int $partnersCountVariation;
    private ?int $pairCount;
    private ?string $aggregateStopReason;

    /**
     * @param array<int, int>|null $partnersCount
     */
    public function __construct(
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        int $elapsedNs,
        int $budgetNs,
        bool $isFinal,
        int $nodesExplored,
        ?float $bestMinPartnersFairness,
        ?float $bestAvgPartnersFairness,
        int $currentSeed = 1,
        int $totalSeeds = 1,
        ?array $partnersCount = null,
        ?int $partnersCountVariation = null,
        ?int $pairCount = null,
        ?string $aggregateStopReason = null
    ) {
        parent::__construct(
            self::PHASE_PAIRING,
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            $elapsedNs,
            $budgetNs,
            $isFinal
        );
        $this->nodesExplored = $nodesExplored;
        $this->bestMinPartnersFairness = $bestMinPartnersFairness;
        $this->bestAvgPartnersFairness = $bestAvgPartnersFairness;
        $this->currentSeed = $currentSeed;
        $this->totalSeeds = $totalSeeds;
        $this->partnersCount = $partnersCount;
        $this->partnersCountVariation = $partnersCountVariation;
        $this->pairCount = $pairCount;
        $this->aggregateStopReason = $aggregateStopReason;
    }

    public function getNodesExplored(): int
    {
        return $this->nodesExplored;
    }

    public function getBestMinPartnersFairness(): ?float
    {
        return $this->bestMinPartnersFairness;
    }

    public function getBestAvgPartnersFairness(): ?float
    {
        return $this->bestAvgPartnersFairness;
    }

    public function getCurrentSeed(): int
    {
        return $this->currentSeed;
    }

    public function getTotalSeeds(): int
    {
        return $this->totalSeeds;
    }

    public function getPartnersCount(): ?array
    {
        return $this->partnersCount;
    }

    public function getPartnersCountVariation(): ?int
    {
        return $this->partnersCountVariation;
    }

    public function getPairCount(): ?int
    {
        return $this->pairCount;
    }

    public function getAggregateStopReason(): ?string
    {
        return $this->aggregateStopReason;
    }
}
