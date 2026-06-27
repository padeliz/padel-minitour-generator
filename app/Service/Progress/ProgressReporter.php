<?php

namespace Arshavinel\PadelMiniTour\Service\Progress;

/**
 * Combo-bound facade that the generator drives to emit {@see GenerationProgress} events.
 */
final class ProgressReporter
{
    /** @var callable|null */
    private $callback;

    private int $intervalNs;
    private int $lastEmitNs = 0;

    private int $players;
    private int $partners;
    private int $repeat;
    private bool $fixedTeams;
    private int $phaseStartNs;

    /**
     * @param callable|null $callback function(GenerationProgress $event): void, or null for noop
     */
    public function __construct(
        ?callable $callback,
        int $intervalNs,
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        int $startNs
    ) {
        $this->callback = $callback;
        $this->intervalNs = $intervalNs;
        $this->players = $players;
        $this->partners = $partners;
        $this->repeat = $repeat;
        $this->fixedTeams = $fixedTeams;
        $this->phaseStartNs = $startNs;
    }

    public static function noop(int $players, int $partners, int $repeat, bool $fixedTeams): self
    {
        return new self(null, 0, $players, $partners, $repeat, $fixedTeams, 0);
    }

    public function setPhaseStart(int $now): void
    {
        $this->phaseStartNs = $now;
    }

    /**
     * @param array<int, int>|null $partnersCount
     */
    public function pairing(
        int $nodesExplored,
        ?float $bestMinPartnersFairness,
        ?float $bestAvgPartnersFairness,
        int $budgetNs,
        int $now,
        bool $isFinal,
        int $currentSeed = 1,
        int $totalSeeds = 1,
        ?array $partnersCount = null,
        ?int $partnersCountVariation = null,
        ?int $pairCount = null,
        ?string $aggregateStopReason = null
    ): void {
        if ($this->callback === null) {
            return;
        }

        if (!$isFinal && ($now - $this->lastEmitNs) < $this->intervalNs) {
            return;
        }

        $this->dispatch(new PairingProgress(
            $this->players,
            $this->partners,
            $this->repeat,
            $this->fixedTeams,
            max(0, $now - $this->phaseStartNs),
            $budgetNs,
            $isFinal,
            $nodesExplored,
            $bestMinPartnersFairness,
            $bestAvgPartnersFairness,
            $currentSeed,
            $totalSeeds,
            $partnersCount,
            $partnersCountVariation,
            $pairCount,
            $aggregateStopReason
        ), $now);
    }

    /**
     * @param array<int, int>|null              $partnersCount
     * @param array<int, array<int, int>>|null  $playersMet
     */
    public function matchMaking(
        int $iterations,
        int $templatesGenerated,
        ?float $bestMeetingsVariation,
        int $budgetNs,
        int $now,
        bool $isFinal,
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
    ): void {
        if ($this->callback === null) {
            return;
        }

        if (!$isFinal && ($now - $this->lastEmitNs) < $this->intervalNs) {
            return;
        }

        $this->dispatch(new MatchMakingProgress(
            $this->players,
            $this->partners,
            $this->repeat,
            $this->fixedTeams,
            max(0, $now - $this->phaseStartNs),
            $budgetNs,
            $isFinal,
            $iterations,
            $templatesGenerated,
            $bestMeetingsVariation,
            $currentSeed,
            $totalSeeds,
            $bestPermutationIndex,
            $bestTemplateIndex,
            $matchesCount,
            $partnersCount,
            $playersMet,
            $partnersCountVariation,
            $aggregateStopReason,
            $currentMeetingsVariationLimit
        ), $now);
    }

    public function ordering(
        int $iterations,
        ?float $bestMin,
        ?float $bestAvg,
        int $budgetNs,
        int $now,
        bool $isFinal,
        ?string $stopReason = null,
        ?int $bestPermutationIndex = null,
        ?int $bestMinBreak = null,
        ?int $bestMaxBreak = null,
        ?int $bestCourtSwitches = null,
        int $currentSeed = 1,
        int $totalSeeds = 1
    ): void {
        if ($this->callback === null) {
            return;
        }

        if (!$isFinal && ($now - $this->lastEmitNs) < $this->intervalNs) {
            return;
        }

        $this->dispatch(new OrderingProgress(
            $this->players,
            $this->partners,
            $this->repeat,
            $this->fixedTeams,
            max(0, $now - $this->phaseStartNs),
            $budgetNs,
            $isFinal,
            $iterations,
            $bestMin,
            $bestAvg,
            $stopReason,
            $bestPermutationIndex,
            $bestMinBreak,
            $bestMaxBreak,
            $bestCourtSwitches,
            $currentSeed,
            $totalSeeds
        ), $now);
    }

    private function dispatch(GenerationProgress $event, int $now): void
    {
        ($this->callback)($event);
        $this->lastEmitNs = $now;
    }
}
