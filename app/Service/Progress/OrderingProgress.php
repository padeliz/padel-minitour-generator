<?php

namespace Arshavinel\PadelMiniTour\Service\Progress;

/**
 * Progress event emitted during the ordering phase (lex factorial walk over match orderings).
 *
 * Always emitted at least once per {@see \Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator::generate()}
 * call, regardless of mode or input size. The trivial branch (matches count <= 1) emits a single
 * final event with {@code iterations = 0, bestMin = bestAvg = null, stopReason = 'trivial'}.
 *
 * The {@code stopReason} is null on interim ticks and populated on the final event with one of the
 * STOP_REASON_* constants on {@see \Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator}.
 *
 * The best-ordering-so-far diagnostics ({@code bestPermutationIndex}, {@code bestMinBreak},
 * {@code bestMaxBreak}) update as the sortMatches loop discovers improvements; they let the live
 * renderer light up the SORTING / SORTING STATS columns mid-flight.
 */
final class OrderingProgress extends GenerationProgress
{
    private int $iterations;
    private ?float $bestMin;
    private ?float $bestAvg;
    private ?string $stopReason;
    private ?int $bestPermutationIndex;
    private ?int $bestMinBreak;
    private ?int $bestMaxBreak;

    public function __construct(
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        int $elapsedNs,
        int $budgetNs,
        bool $isFinal,
        int $iterations,
        ?float $bestMin,
        ?float $bestAvg,
        ?string $stopReason = null,
        ?int $bestPermutationIndex = null,
        ?int $bestMinBreak = null,
        ?int $bestMaxBreak = null
    ) {
        parent::__construct(
            self::PHASE_ORDERING,
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            $elapsedNs,
            $budgetNs,
            $isFinal
        );
        $this->iterations = $iterations;
        $this->bestMin = $bestMin;
        $this->bestAvg = $bestAvg;
        $this->stopReason = $stopReason;
        $this->bestPermutationIndex = $bestPermutationIndex;
        $this->bestMinBreak = $bestMinBreak;
        $this->bestMaxBreak = $bestMaxBreak;
    }

    public function getIterations(): int
    {
        return $this->iterations;
    }

    public function getBestMin(): ?float
    {
        return $this->bestMin;
    }

    public function getBestAvg(): ?float
    {
        return $this->bestAvg;
    }

    public function getStopReason(): ?string
    {
        return $this->stopReason;
    }

    /**
     * 1-based iteration count at which the best ordering so far was last improved.
     * `null` before any iteration has run (e.g. the trivial branch).
     */
    public function getBestPermutationIndex(): ?int
    {
        return $this->bestPermutationIndex;
    }

    /**
     * Cross-player minimum of each player's shortest INNER consecutive break run in the
     * best-so-far ordering.
     *
     * An inner break run is a maximal stretch of consecutive matches in which the player does
     * not appear, bracketed by appearances on BOTH sides. Lead runs (before the player's first
     * appearance) and trail runs (after the player's last appearance) are EXCLUDED. When a
     * player has no inner break runs (plays every match, plays only once, or never plays at
     * all), their per-player value is `0`, which means the aggregate becomes `0` whenever any
     * player has no inner runs. `null` before any leaf has been visited.
     */
    public function getBestMinBreak(): ?int
    {
        return $this->bestMinBreak;
    }

    /**
     * Cross-player maximum of each player's longest consecutive break run in the best-so-far
     * ordering. Unlike {@see getBestMinBreak()}, this metric includes lead, inner, AND trail
     * runs. `null` before any leaf has been visited.
     */
    public function getBestMaxBreak(): ?int
    {
        return $this->bestMaxBreak;
    }
}
