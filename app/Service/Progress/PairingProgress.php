<?php

namespace Arshavinel\PadelMiniTour\Service\Progress;

/**
 * Progress event emitted during the pairing phase (assembling the match list from pair
 * permutations).
 *
 * - In mixed mode the generator emits ticks throughout the factorial outer loop, then a final
 *   event when the loop exits (`DEADLINE` or `FACTORIAL_COMPLETE`).
 * - In fixed-teams mode the generator emits a single final event after the deterministic
 *   single-pass build, with {@code iterations = 1} and {@code templatesGenerated = 1}.
 *
 * For combos whose pair count makes a linear factorial walk hopeless within budget, the generator
 * runs multiple seeded walks across evenly-spaced points of the lex space and fans interim ticks
 * out per seed. {@see getCurrentSeed()} / {@see getTotalSeeds()} expose that fan-out so renderers
 * can show "seed 3/16"; for single-seed runs both default to 1.
 *
 * Best-template-so-far diagnostics ({@code bestPermutationIndex}, {@code bestTemplateIndex},
 * {@code bestMatchesCount}, {@code playersMet}, {@code partnersCount}, {@code partnersCountVariation})
 * mirror the {@see \Arshavinel\PadelMiniTour\Service\TemplateMatches} DTO so the live-progress
 * renderer can populate every pairing-related cell as soon as the first eligible template is found,
 * without waiting for the phase to complete.
 *
 * {@code aggregateStopReason} is populated only on the final event with one of
 * {@code STOP_REASON_*} constants on {@see \Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator}.
 * In multi-seed runs it is the pessimistic aggregate across seeds: `DEADLINE` if any seed hit its
 * per-seed wall budget, else `FACTORIAL_COMPLETE`.
 */
final class PairingProgress extends GenerationProgress
{
    private int $iterations;
    private int $templatesGenerated;
    private ?float $bestMeetingsVariation;
    private int $currentSeed;
    private int $totalSeeds;
    private ?int $bestPermutationIndex;
    private ?int $bestTemplateIndex;
    private ?int $bestMatchesCount;
    /** @var array<int, int>|null */
    private ?array $partnersCount;
    /** @var array<int, array<int, int>>|null */
    private ?array $playersMet;
    private ?int $partnersCountVariation;
    private ?string $aggregateStopReason;
    private int $currentMeetingsVariationLimit;

    /**
     * @param array<int, int>|null              $partnersCount
     * @param array<int, array<int, int>>|null  $playersMet
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
        ?int $bestMatchesCount = null,
        ?array $partnersCount = null,
        ?array $playersMet = null,
        ?int $partnersCountVariation = null,
        ?string $aggregateStopReason = null,
        int $currentMeetingsVariationLimit = 1
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
        $this->iterations = $iterations;
        $this->templatesGenerated = $templatesGenerated;
        $this->bestMeetingsVariation = $bestMeetingsVariation;
        $this->currentSeed = $currentSeed;
        $this->totalSeeds = $totalSeeds;
        $this->bestPermutationIndex = $bestPermutationIndex;
        $this->bestTemplateIndex = $bestTemplateIndex;
        $this->bestMatchesCount = $bestMatchesCount;
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

    /**
     * 1-based index of the seed currently being walked, in `[1, totalSeeds]`. Always 1 for
     * single-seed runs (i.e., combos whose pair count is below the multi-seed threshold).
     */
    public function getCurrentSeed(): int
    {
        return $this->currentSeed;
    }

    /**
     * Number of seeded walks the pairing phase is splitting its wall-clock budget across. Equals 1
     * for single-seed runs.
     */
    public function getTotalSeeds(): int
    {
        return $this->totalSeeds;
    }

    /**
     * Lex position (1-based iteration count) at which the best template so far was last improved.
     * `null` before any eligible template has been found.
     */
    public function getBestPermutationIndex(): ?int
    {
        return $this->bestPermutationIndex;
    }

    /**
     * Count of valid templates produced up to (and including) the best so far. `null` before any
     * eligible template has been found.
     */
    public function getBestTemplateIndex(): ?int
    {
        return $this->bestTemplateIndex;
    }

    /**
     * Number of matches in the best-so-far template. Lets the live renderer fill the TEAMS.Matches
     * cell mid-flight, before the final ordered schedule exists.
     */
    public function getBestMatchesCount(): ?int
    {
        return $this->bestMatchesCount;
    }

    /**
     * @return array<int, int>|null Per-player partner count (constant across the phase, computed
     *                              from the pair-assembly step).
     */
    public function getPartnersCount(): ?array
    {
        return $this->partnersCount;
    }

    /**
     * @return array<int, array<int, int>>|null Who-met-whom matrix of the best template so far.
     */
    public function getPlayersMet(): ?array
    {
        return $this->playersMet;
    }

    /**
     * `max(partnersCount) - min(partnersCount)`. 0 means every player has the same number of
     * partners; >0 means the pair-assembly produced an imbalance. Constant per generate() call.
     */
    public function getPartnersCountVariation(): ?int
    {
        return $this->partnersCountVariation;
    }

    /**
     * Final pairing stop reason. `null` on interim ticks; on the final event it is the pessimistic
     * aggregate across seeds (`DEADLINE` if any seed deadlined, else `FACTORIAL_COMPLETE`).
     */
    public function getAggregateStopReason(): ?string
    {
        return $this->aggregateStopReason;
    }

    /**
     * The currently-active `meetingsVariationLimit` for the pairing phase. Surfaces the S6
     * adaptive auto-relax loop's state so the renderer can show e.g. "mvl=2" mid-flight.
     * Default `1` matches the historic strict-build behaviour for back-compat with snapshots
     * that never reached the relax loop.
     */
    public function getCurrentMeetingsVariationLimit(): int
    {
        return $this->currentMeetingsVariationLimit;
    }
}
