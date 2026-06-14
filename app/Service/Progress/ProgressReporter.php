<?php

namespace Arshavinel\PadelMiniTour\Service\Progress;

/**
 * Combo-bound facade that the generator drives to emit {@see GenerationProgress} events.
 *
 * Owns the throttle state so the generator's hot loops stay free of bookkeeping. Final events
 * (`isFinal = true`) bypass the throttle; interim ticks are dropped if fewer than
 * {@code intervalNs} nanoseconds have passed since the previous emit.
 *
 * When constructed with a null callback the reporter short-circuits in {@see dispatch()}, so the
 * cost of an unused progress channel is one null-check per loop iteration. Use
 * {@see ProgressReporter::noop()} in tests that don't care about events.
 *
 * The reporter tracks a phase-relative start via {@see setPhaseStart()} so the elapsed time
 * reported on each event reflects only the current phase (pairing or sorting), not the entire
 * generate() call. The generator must call {@see setPhaseStart()} at the start of each phase.
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
    private int $startNs;
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
        $this->startNs = $startNs;
        $this->phaseStartNs = $startNs;
    }

    /**
     * Null Object factory. The returned reporter ignores every emit; use it when the generator
     * needs a reporter argument but the caller has nothing to listen.
     */
    public static function noop(int $players, int $partners, int $repeat, bool $fixedTeams): self
    {
        return new self(null, 0, $players, $partners, $repeat, $fixedTeams, 0);
    }

    /**
     * Resets the phase-relative origin used to compute {@code elapsedNs} on subsequent events.
     * The generator calls this at the start of each phase so the pairing and sorting events each
     * carry an elapsed time scoped to their own phase.
     */
    public function setPhaseStart(int $now): void
    {
        $this->phaseStartNs = $now;
    }

    /**
     * @param array<int, int>|null              $partnersCount
     * @param array<int, array<int, int>>|null  $playersMet
     */
    public function pairing(
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
        ?int $bestMatchesCount = null,
        ?array $partnersCount = null,
        ?array $playersMet = null,
        ?int $partnersCountVariation = null,
        ?string $aggregateStopReason = null,
        int $currentDifferenceLimit = 1
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
            $iterations,
            $templatesGenerated,
            $bestMeetingsVariation,
            $currentSeed,
            $totalSeeds,
            $bestPermutationIndex,
            $bestTemplateIndex,
            $bestMatchesCount,
            $partnersCount,
            $playersMet,
            $partnersCountVariation,
            $aggregateStopReason,
            $currentDifferenceLimit
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
        ?int $bestMaxBreak = null
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
            $bestMaxBreak
        ), $now);
    }

    private function dispatch(GenerationProgress $event, int $now): void
    {
        ($this->callback)($event);
        $this->lastEmitNs = $now;
    }
}
