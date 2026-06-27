<?php

namespace Arshavinel\PadelMiniTour\Service\Progress;

/**
 * Common, immutable shape of a single template-generation progress event.
 *
 * Two concrete subclasses cover the two phases the generator runs:
 *
 * - {@see PairingProgress}     - building the partner pool (2-player partnerships).
 * - {@see MatchMakingProgress} - grouping pairs into 4-player matches.
 * - {@see OrderingProgress}    - scheduling matches across courts and rounds.
 *
 * Both modes emit the same event shape so downstream renderers don't have to branch on the
 * generation strategy. Each phase always emits exactly one final event ({@see isFinal()} = true)
 * and zero or more interim ticks before it.
 */
abstract class GenerationProgress
{
    public const PHASE_PAIRING = 'pairing';
    public const PHASE_MATCH_MAKING = 'matchMaking';
    public const PHASE_ORDERING = 'ordering';

    private string $phase;
    private int $players;
    private int $partners;
    private int $repeat;
    private bool $fixedTeams;
    private int $elapsedNs;
    private int $budgetNs;
    private bool $isFinal;

    protected function __construct(
        string $phase,
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        int $elapsedNs,
        int $budgetNs,
        bool $isFinal
    ) {
        $this->phase = $phase;
        $this->players = $players;
        $this->partners = $partners;
        $this->repeat = $repeat;
        $this->fixedTeams = $fixedTeams;
        $this->elapsedNs = $elapsedNs;
        $this->budgetNs = $budgetNs;
        $this->isFinal = $isFinal;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getPlayers(): int
    {
        return $this->players;
    }

    public function getPartners(): int
    {
        return $this->partners;
    }

    public function getRepeat(): int
    {
        return $this->repeat;
    }

    public function isFixedTeams(): bool
    {
        return $this->fixedTeams;
    }

    public function getElapsedNs(): int
    {
        return $this->elapsedNs;
    }

    public function getBudgetNs(): int
    {
        return $this->budgetNs;
    }

    public function isFinal(): bool
    {
        return $this->isFinal;
    }
}
