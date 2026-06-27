<?php

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\Service\Progress\MatchMakingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\PairingProgress;

/**
 * Immutable result of one template generation.
 *
 * Self-describing: carries its own identity (players, partners, repeat, fixedTeams) alongside the
 * generated match list and the diagnostic stats from each generation phase.
 *
 * Field layout mirrors the v4 JSON shape persisted by {@see TemplateMatchesRepository}:
 *
 * - Identity (required, non-nullable): players, partners, repeat, courts, fixedTeams.
 * - Top-level result: matches (null when no eligible permutation was found).
 * - metrics.pairing: partner-pool construction diagnostics.
 * - metrics.matchMaking: grouping pairs into 4-player matches diagnostics.
 * - metrics.ordering: round-slice schedule ordering diagnostics.
 *
 * Every diagnostic getter is phase- and section-prefixed so its JSON path is obvious at call sites.
 */
final class TemplateMatches
{
    private int $players;
    private int $partners;
    private int $repeat;
    private int $courts;
    private bool $fixedTeams;

    /**
     * Per-court ordered match lists. `matches[courtIndex][roundIndex]` is one match
     * `[[p1,p2],[p3,p4]]`. Round `r` across all courts is one simultaneous time slot.
     *
     * @var array<int, array<int, array<int, array<int, int>>>>|null
     */
    private ?array $matches;

    private ?float $pairingQualityMinPartnersFairness;
    private ?float $pairingQualityAvgPartnersFairness;
    /** @var array<int, int>|null */
    private ?array $pairingQualityPartnersCount;
    private ?int $pairingQualityPartnersCountVariation;
    private ?int $pairingQualityPairCount;

    private ?string $pairingStatsStopReason;
    private ?float $pairingStatsTime;
    private ?int $pairingStatsNodesExplored;
    private ?int $pairingStatsSeedIndex;
    private ?int $pairingStatsSeedsTotal;

    private ?float $matchMakingQualityMeetingsVariation;
    private ?int $matchMakingQualityMinOpponentsMet;
    private ?int $matchMakingQualityMaxOpponentsMet;
    /** @var array<int, array<int, int>>|null */
    private ?array $matchMakingQualityPlayersMet;
    private ?int $matchMakingQualityMatchesCount;

    private ?int $matchMakingStatsPermutationsIterated;
    private ?int $matchMakingStatsPermutationIndex;
    private ?int $matchMakingStatsTemplatesGenerated;
    private ?int $matchMakingStatsTemplateIndex;
    private ?string $matchMakingStatsStopReason;
    private ?float $matchMakingStatsTime;
    private ?int $matchMakingStatsMeetingsVariationLimit;
    /** @var array<int, array{meetingsVariationLimit:int,permutationsIterated:int,templatesGenerated:int,time:float}>|null */
    private ?array $matchMakingStatsRelaxAttempts;

    private ?float $orderingQualityMinDistribution;
    private ?float $orderingQualityAvgDistribution;
    private ?int $orderingQualityMinBreak;
    private ?int $orderingQualityMaxBreak;
    private ?int $orderingQualityCourtSwitches;
    private ?float $orderingQualityCourtBalance;
    private ?int $orderingQualityRoundsCount;

    private ?string $orderingStatsStopReason;
    private ?int $orderingStatsPermutationsIterated;
    private ?int $orderingStatsPermutationIndex;
    private ?int $orderingStatsNodesExplored;
    private ?int $orderingStatsSeedIndex;
    private ?int $orderingStatsSeedsTotal;
    private ?float $orderingStatsTime;

    /**
     * @param array<int, array<int, array<int, array<int, int>>>>|null $matches
     * @param array<int, int>|null                                      $pairingQualityPartnersCount
     * @param array<int, array<int, int>>|null                            $matchMakingQualityPlayersMet
     * @param array<int, array{meetingsVariationLimit:int,permutationsIterated:int,templatesGenerated:int,time:float}>|null $matchMakingStatsRelaxAttempts
     */
    public function __construct(
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams,
        ?array $matches,
        ?float $pairingQualityMinPartnersFairness,
        ?float $pairingQualityAvgPartnersFairness,
        ?array $pairingQualityPartnersCount,
        ?int $pairingQualityPartnersCountVariation,
        ?int $pairingQualityPairCount,
        ?string $pairingStatsStopReason,
        ?float $pairingStatsTime,
        ?int $pairingStatsNodesExplored,
        ?int $pairingStatsSeedIndex,
        ?int $pairingStatsSeedsTotal,
        ?float $matchMakingQualityMeetingsVariation,
        ?int $matchMakingQualityMinOpponentsMet,
        ?int $matchMakingQualityMaxOpponentsMet,
        ?array $matchMakingQualityPlayersMet,
        ?int $matchMakingQualityMatchesCount,
        ?int $matchMakingStatsPermutationsIterated,
        ?int $matchMakingStatsPermutationIndex,
        ?int $matchMakingStatsTemplatesGenerated,
        ?int $matchMakingStatsTemplateIndex,
        ?string $matchMakingStatsStopReason,
        ?float $matchMakingStatsTime,
        ?int $matchMakingStatsMeetingsVariationLimit,
        ?array $matchMakingStatsRelaxAttempts,
        ?float $orderingQualityMinDistribution,
        ?float $orderingQualityAvgDistribution,
        ?int $orderingQualityMinBreak,
        ?int $orderingQualityMaxBreak,
        ?int $orderingQualityCourtSwitches,
        ?float $orderingQualityCourtBalance,
        ?int $orderingQualityRoundsCount,
        ?string $orderingStatsStopReason,
        ?int $orderingStatsPermutationsIterated,
        ?int $orderingStatsPermutationIndex,
        ?int $orderingStatsNodesExplored,
        ?int $orderingStatsSeedIndex,
        ?int $orderingStatsSeedsTotal,
        ?float $orderingStatsTime
    ) {
        $this->players = $players;
        $this->partners = $partners;
        $this->repeat = $repeat;
        $this->courts = $courts;
        $this->fixedTeams = $fixedTeams;
        $this->matches = $matches;
        $this->pairingQualityMinPartnersFairness = $pairingQualityMinPartnersFairness;
        $this->pairingQualityAvgPartnersFairness = $pairingQualityAvgPartnersFairness;
        $this->pairingQualityPartnersCount = $pairingQualityPartnersCount;
        $this->pairingQualityPartnersCountVariation = $pairingQualityPartnersCountVariation;
        $this->pairingQualityPairCount = $pairingQualityPairCount;
        $this->pairingStatsStopReason = $pairingStatsStopReason;
        $this->pairingStatsTime = $pairingStatsTime;
        $this->pairingStatsNodesExplored = $pairingStatsNodesExplored;
        $this->pairingStatsSeedIndex = $pairingStatsSeedIndex;
        $this->pairingStatsSeedsTotal = $pairingStatsSeedsTotal;
        $this->matchMakingQualityMeetingsVariation = $matchMakingQualityMeetingsVariation;
        $this->matchMakingQualityMinOpponentsMet = $matchMakingQualityMinOpponentsMet;
        $this->matchMakingQualityMaxOpponentsMet = $matchMakingQualityMaxOpponentsMet;
        $this->matchMakingQualityPlayersMet = $matchMakingQualityPlayersMet;
        $this->matchMakingQualityMatchesCount = $matchMakingQualityMatchesCount;
        $this->matchMakingStatsPermutationsIterated = $matchMakingStatsPermutationsIterated;
        $this->matchMakingStatsPermutationIndex = $matchMakingStatsPermutationIndex;
        $this->matchMakingStatsTemplatesGenerated = $matchMakingStatsTemplatesGenerated;
        $this->matchMakingStatsTemplateIndex = $matchMakingStatsTemplateIndex;
        $this->matchMakingStatsStopReason = $matchMakingStatsStopReason;
        $this->matchMakingStatsTime = $matchMakingStatsTime;
        $this->matchMakingStatsMeetingsVariationLimit = $matchMakingStatsMeetingsVariationLimit;
        $this->matchMakingStatsRelaxAttempts = $matchMakingStatsRelaxAttempts;
        $this->orderingQualityMinDistribution = $orderingQualityMinDistribution;
        $this->orderingQualityAvgDistribution = $orderingQualityAvgDistribution;
        $this->orderingQualityMinBreak = $orderingQualityMinBreak;
        $this->orderingQualityMaxBreak = $orderingQualityMaxBreak;
        $this->orderingQualityCourtSwitches = $orderingQualityCourtSwitches;
        $this->orderingQualityCourtBalance = $orderingQualityCourtBalance;
        $this->orderingQualityRoundsCount = $orderingQualityRoundsCount;
        $this->orderingStatsStopReason = $orderingStatsStopReason;
        $this->orderingStatsPermutationsIterated = $orderingStatsPermutationsIterated;
        $this->orderingStatsPermutationIndex = $orderingStatsPermutationIndex;
        $this->orderingStatsNodesExplored = $orderingStatsNodesExplored;
        $this->orderingStatsSeedIndex = $orderingStatsSeedIndex;
        $this->orderingStatsSeedsTotal = $orderingStatsSeedsTotal;
        $this->orderingStatsTime = $orderingStatsTime;
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

    public function getCourts(): int
    {
        return $this->courts;
    }

    public function isFixedTeams(): bool
    {
        return $this->fixedTeams;
    }

    public function getMatches(): ?array
    {
        return $this->matches;
    }

    public function getPairingQualityMinPartnersFairness(): ?float
    {
        return $this->pairingQualityMinPartnersFairness;
    }

    public function getPairingQualityAvgPartnersFairness(): ?float
    {
        return $this->pairingQualityAvgPartnersFairness;
    }

    public function getPairingQualityPartnersCount(): ?array
    {
        return $this->pairingQualityPartnersCount;
    }

    /**
     * @param int|string $player
     */
    public function getPairingQualityPartnersCountBy($player): ?int
    {
        return $this->pairingQualityPartnersCount[$player] ?? null;
    }

    public function getPairingQualityPartnersCountVariation(): ?int
    {
        return $this->pairingQualityPartnersCountVariation;
    }

    public function getPairingQualityPairCount(): ?int
    {
        return $this->pairingQualityPairCount;
    }

    public function getPairingStatsStopReason(): ?string
    {
        return $this->pairingStatsStopReason;
    }

    public function getPairingStatsTime(): ?float
    {
        return $this->pairingStatsTime;
    }

    public function getPairingStatsNodesExplored(): ?int
    {
        return $this->pairingStatsNodesExplored;
    }

    public function getPairingStatsSeedIndex(): ?int
    {
        return $this->pairingStatsSeedIndex;
    }

    public function getPairingStatsSeedsTotal(): ?int
    {
        return $this->pairingStatsSeedsTotal;
    }

    public function getMatchMakingQualityMeetingsVariation(): ?float
    {
        return $this->matchMakingQualityMeetingsVariation;
    }

    public function getMatchMakingQualityMinOpponentsMet(): ?int
    {
        return $this->matchMakingQualityMinOpponentsMet;
    }

    public function getMatchMakingQualityMaxOpponentsMet(): ?int
    {
        return $this->matchMakingQualityMaxOpponentsMet;
    }

    public function getMatchMakingQualityPlayersMet(): ?array
    {
        return $this->matchMakingQualityPlayersMet;
    }

    /**
     * @param int|string $player
     * @return array<int, int>|null
     */
    public function getMatchMakingQualityPlayersMetBy($player): ?array
    {
        return $this->matchMakingQualityPlayersMet[$player] ?? null;
    }

    public function getMatchMakingQualityMatchesCount(): ?int
    {
        return $this->matchMakingQualityMatchesCount;
    }

    public function getMatchMakingStatsPermutationsIterated(): ?int
    {
        return $this->matchMakingStatsPermutationsIterated;
    }

    public function getMatchMakingStatsPermutationIndex(): ?int
    {
        return $this->matchMakingStatsPermutationIndex;
    }

    public function getMatchMakingStatsTemplatesGenerated(): ?int
    {
        return $this->matchMakingStatsTemplatesGenerated;
    }

    public function getMatchMakingStatsTemplateIndex(): ?int
    {
        return $this->matchMakingStatsTemplateIndex;
    }

    public function getMatchMakingStatsStopReason(): ?string
    {
        return $this->matchMakingStatsStopReason;
    }

    public function getMatchMakingStatsTime(): ?float
    {
        return $this->matchMakingStatsTime;
    }

    public function getMatchMakingStatsMeetingsVariationLimit(): ?int
    {
        return $this->matchMakingStatsMeetingsVariationLimit;
    }

    /**
     * Per-attempt forensic trail of the S6 match-making relax loop.
     *
     * Each entry is `{meetingsVariationLimit, permutationsIterated, templatesGenerated, time}`.
     *
     * @return array<int, array{meetingsVariationLimit:int,permutationsIterated:int,templatesGenerated:int,time:float}>|null
     */
    public function getMatchMakingStatsRelaxAttempts(): ?array
    {
        return $this->matchMakingStatsRelaxAttempts;
    }

    public function getOrderingQualityMinDistribution(): ?float
    {
        return $this->orderingQualityMinDistribution;
    }

    public function getOrderingQualityAvgDistribution(): ?float
    {
        return $this->orderingQualityAvgDistribution;
    }

    /**
     * Cross-player minimum of each player's shortest INNER consecutive break run.
     *
     * An inner break run is the stretch of consecutive schedule steps in which the player does
     * not appear between two of their own appearances. For single-court templates each step is
     * one match; for multi-court templates each step is one round (all courts advance together).
     * Lead runs (before the player's first appearance) and trail runs (after the player's last
     * appearance) are EXCLUDED. Sit-out semantics apply: when a player plays in two consecutive
     * steps the inner run length is `0`, and that `0` counts -- the player's contribution to
     * `Min Break` becomes `0`. A player with no closed inner run at all (plays only once, or never
     * plays) also contributes `0`. Either way, whenever any player's contribution is `0` the
     * aggregate becomes `0` too.
     *
     * Ordering-sensitive: this metric meaningfully participates in the sort phase as the third
     * tie-break tier `(minBreak + maxBreak) / 2` versus `m / playerMatches`. `null` when the
     * ordering phase produced no leaf (infeasible prune or deadline before any complete ordering).
     */
    public function getOrderingQualityMinBreak(): ?int
    {
        return $this->orderingQualityMinBreak;
    }

    /**
     * Cross-player maximum of each player's longest consecutive break run.
     *
     * Unlike {@see getOrderingQualityMinBreak()}, this metric includes lead, inner, AND trail runs --
     * any maximal absence stretch in the schedule contributes. The ordering DFS also enforces this
     * as a hard prune ceiling at `ceil(playersCount / 4)`, so the leaf value is always bounded by
     * that threshold when present.
     */
    public function getOrderingQualityMaxBreak(): ?int
    {
        return $this->orderingQualityMaxBreak;
    }

    public function getOrderingQualityCourtSwitches(): ?int
    {
        return $this->orderingQualityCourtSwitches;
    }

    public function getOrderingQualityCourtBalance(): ?float
    {
        return $this->orderingQualityCourtBalance;
    }

    public function getOrderingQualityRoundsCount(): ?int
    {
        return $this->orderingQualityRoundsCount;
    }

    public function getOrderingStatsStopReason(): ?string
    {
        return $this->orderingStatsStopReason;
    }

    public function getOrderingStatsPermutationsIterated(): ?int
    {
        return $this->orderingStatsPermutationsIterated;
    }

    public function getOrderingStatsPermutationIndex(): ?int
    {
        return $this->orderingStatsPermutationIndex;
    }

    public function getOrderingStatsNodesExplored(): ?int
    {
        return $this->orderingStatsNodesExplored;
    }

    public function getOrderingStatsSeedIndex(): ?int
    {
        return $this->orderingStatsSeedIndex;
    }

    public function getOrderingStatsSeedsTotal(): ?int
    {
        return $this->orderingStatsSeedsTotal;
    }

    public function getOrderingStatsTime(): ?float
    {
        return $this->orderingStatsTime;
    }

    /**
     * Whether the generator successfully produced a complete template.
     */
    public function isEligible(): bool
    {
        return $this->matches !== null;
    }

    /**
     * Whether the template has a loadable multi-court schedule for the UI/runtime.
     */
    public function isUsable(): bool
    {
        return self::hasValidRoundSchedule($this->matches);
    }

    /**
     * Verifies that no player appears on more than one court in the same round index.
     *
     * @param array<int, array<int, array<int, array<int, int>>>>|null $matchesByCourt
     */
    public static function hasValidRoundSchedule(?array $matchesByCourt): bool
    {
        if ($matchesByCourt === null || $matchesByCourt === []) {
            return false;
        }

        $roundsTotal = 0;
        foreach ($matchesByCourt as $courtRounds) {
            $roundsTotal = max($roundsTotal, count($courtRounds));
        }

        for ($r = 0; $r < $roundsTotal; $r++) {
            $seen = [];
            foreach ($matchesByCourt as $courtRounds) {
                if (!isset($courtRounds[$r])) {
                    continue;
                }
                $match = $courtRounds[$r];
                foreach ([$match[0][0], $match[0][1], $match[1][0], $match[1][1]] as $playerIndex) {
                    if (isset($seen[$playerIndex])) {
                        return false;
                    }
                    $seen[$playerIndex] = true;
                }
            }
        }

        return true;
    }

    /**
     * Builds a partial DTO from in-flight generation progress.
     *
     * Designed for the live-progress renderer: every diagnostic the events expose is mirrored into
     * the snapshot, so the shared {@see MetricsFormatterTrait::buildUnifiedRow()} fills cells as soon
     * as the underlying value becomes available mid-flight. Diagnostics that the events do not yet
     * carry stay null and render as `-`.
     *
     * `matches` is always null in a snapshot - the schedule does not exist until the generator
     * returns. `isEligible()` therefore returns false for snapshots, which is correct: the run has
     * not produced a usable template yet.
     */
    public static function fromProgress(
        int $players,
        int $partners,
        int $repeat,
        int $courts,
        bool $fixedTeams,
        ?PairingProgress $pairing,
        ?MatchMakingProgress $matchMaking,
        ?OrderingProgress $ordering
    ): self {
        $pairingQualityMinPartnersFairness = null;
        $pairingQualityAvgPartnersFairness = null;
        $pairingQualityPartnersCount = null;
        $pairingQualityPartnersCountVariation = null;
        $pairingQualityPairCount = null;
        $pairingStatsStopReason = null;
        $pairingStatsTime = null;
        $pairingStatsNodesExplored = null;
        $pairingStatsSeedIndex = null;
        $pairingStatsSeedsTotal = null;
        if ($pairing !== null) {
            $pairingQualityMinPartnersFairness = $pairing->getBestMinPartnersFairness();
            $pairingQualityAvgPartnersFairness = $pairing->getBestAvgPartnersFairness();
            $pairingQualityPartnersCount = $pairing->getPartnersCount();
            $pairingQualityPartnersCountVariation = $pairing->getPartnersCountVariation();
            $pairingQualityPairCount = $pairing->getPairCount();
            $pairingStatsStopReason = $pairing->getAggregateStopReason();
            $pairingStatsTime = self::nsToSeconds($pairing->getElapsedNs());
            $pairingStatsNodesExplored = $pairing->getNodesExplored();
            $pairingStatsSeedIndex = $pairing->getCurrentSeed();
            $pairingStatsSeedsTotal = $pairing->getTotalSeeds();
        }

        $matchMakingQualityMeetingsVariation = null;
        $matchMakingQualityMinOpponentsMet = null;
        $matchMakingQualityMaxOpponentsMet = null;
        $matchMakingQualityPlayersMet = null;
        $matchMakingQualityMatchesCount = null;
        $matchMakingStatsPermutationsIterated = null;
        $matchMakingStatsPermutationIndex = null;
        $matchMakingStatsTemplatesGenerated = null;
        $matchMakingStatsTemplateIndex = null;
        $matchMakingStatsStopReason = null;
        $matchMakingStatsTime = null;
        $matchMakingStatsMeetingsVariationLimit = null;
        if ($matchMaking !== null) {
            $matchMakingQualityMeetingsVariation = $matchMaking->getBestMeetingsVariation();
            $matchMakingQualityPlayersMet = $matchMaking->getPlayersMet();
            $opponentsBounds = self::opponentsMetBounds($matchMakingQualityPlayersMet, $players);
            $matchMakingQualityMinOpponentsMet = $opponentsBounds['min'];
            $matchMakingQualityMaxOpponentsMet = $opponentsBounds['max'];
            $matchMakingQualityMatchesCount = $matchMaking->getMatchesCount();
            $matchMakingStatsPermutationsIterated = $matchMaking->getIterations();
            $matchMakingStatsPermutationIndex = $matchMaking->getBestPermutationIndex();
            $matchMakingStatsTemplatesGenerated = $matchMaking->getTemplatesGenerated();
            $matchMakingStatsTemplateIndex = $matchMaking->getBestTemplateIndex();
            $matchMakingStatsStopReason = $matchMaking->getAggregateStopReason();
            $matchMakingStatsTime = self::nsToSeconds($matchMaking->getElapsedNs());
            $matchMakingStatsMeetingsVariationLimit = $matchMaking->getCurrentMeetingsVariationLimit();
        }

        $orderingQualityMinDistribution = null;
        $orderingQualityAvgDistribution = null;
        $orderingQualityMinBreak = null;
        $orderingQualityMaxBreak = null;
        $orderingQualityCourtSwitches = null;
        $orderingQualityCourtBalance = null;
        $orderingQualityRoundsCount = null;
        $orderingStatsStopReason = null;
        $orderingStatsPermutationsIterated = null;
        $orderingStatsPermutationIndex = null;
        $orderingStatsNodesExplored = null;
        $orderingStatsSeedIndex = null;
        $orderingStatsSeedsTotal = null;
        $orderingStatsTime = null;
        if ($ordering !== null) {
            $orderingQualityMinDistribution = $ordering->getBestMin();
            $orderingQualityAvgDistribution = $ordering->getBestAvg();
            $orderingQualityMinBreak = $ordering->getBestMinBreak();
            $orderingQualityMaxBreak = $ordering->getBestMaxBreak();
            $orderingQualityCourtSwitches = $ordering->getBestCourtSwitches();
            $orderingStatsStopReason = $ordering->getStopReason();
            $orderingStatsPermutationsIterated = $ordering->getIterations();
            $orderingStatsPermutationIndex = $ordering->getBestPermutationIndex();
            $orderingStatsSeedIndex = $ordering->getCurrentSeed();
            $orderingStatsSeedsTotal = $ordering->getTotalSeeds();
            $orderingStatsTime = self::nsToSeconds($ordering->getElapsedNs());
        }

        return new self(
            $players,
            $partners,
            $repeat,
            $courts,
            $fixedTeams,
            null,
            $pairingQualityMinPartnersFairness,
            $pairingQualityAvgPartnersFairness,
            $pairingQualityPartnersCount,
            $pairingQualityPartnersCountVariation,
            $pairingQualityPairCount,
            $pairingStatsStopReason,
            $pairingStatsTime,
            $pairingStatsNodesExplored,
            $pairingStatsSeedIndex,
            $pairingStatsSeedsTotal,
            $matchMakingQualityMeetingsVariation,
            $matchMakingQualityMinOpponentsMet,
            $matchMakingQualityMaxOpponentsMet,
            $matchMakingQualityPlayersMet,
            $matchMakingQualityMatchesCount,
            $matchMakingStatsPermutationsIterated,
            $matchMakingStatsPermutationIndex,
            $matchMakingStatsTemplatesGenerated,
            $matchMakingStatsTemplateIndex,
            $matchMakingStatsStopReason,
            $matchMakingStatsTime,
            $matchMakingStatsMeetingsVariationLimit,
            null,
            $orderingQualityMinDistribution,
            $orderingQualityAvgDistribution,
            $orderingQualityMinBreak,
            $orderingQualityMaxBreak,
            $orderingQualityCourtSwitches,
            $orderingQualityCourtBalance,
            $orderingQualityRoundsCount,
            $orderingStatsStopReason,
            $orderingStatsPermutationsIterated,
            $orderingStatsPermutationIndex,
            $orderingStatsNodesExplored,
            $orderingStatsSeedIndex,
            $orderingStatsSeedsTotal,
            $orderingStatsTime
        );
    }

    /**
     * Decodes a JSON-shaped associative array into a {@see TemplateMatches} instance.
     *
     * Strict v4: requires `metrics` with `pairing`, `matchMaking`, and `ordering`, each containing
     * `quality` and `stats` objects. Legacy top-level `pairing` / `sorting` keys and older timing
     * fields are rejected loudly so a stale file surfaces as an explicit error.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException when a required key is missing or a forbidden legacy key is present.
     */
    public static function fromArray(array $data): self
    {
        foreach (['players', 'partners', 'repeat', 'courts', 'fixedTeams'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException("TemplateMatches JSON missing required identity key: {$key}");
            }
        }

        foreach (['pairing', 'sorting'] as $legacyKey) {
            if (array_key_exists($legacyKey, $data)) {
                throw new \InvalidArgumentException(
                    "TemplateMatches JSON contains legacy top-level key '{$legacyKey}'; regenerate the templates with the v4 schema."
                );
            }
        }

        foreach (['estimatedGenerationTime', 'generationTime'] as $forbidden) {
            if (array_key_exists($forbidden, $data)) {
                throw new \InvalidArgumentException(
                    "TemplateMatches JSON contains legacy top-level key '{$forbidden}'; regenerate the templates with the current schema."
                );
            }
        }

        if (!array_key_exists('metrics', $data) || !is_array($data['metrics'])) {
            throw new \InvalidArgumentException('TemplateMatches JSON missing required object: metrics');
        }

        $metrics = $data['metrics'];
        foreach (['pairing', 'matchMaking', 'ordering'] as $phase) {
            if (!array_key_exists($phase, $metrics) || !is_array($metrics[$phase])) {
                throw new \InvalidArgumentException("TemplateMatches JSON missing required metrics phase: {$phase}");
            }
            foreach (['quality', 'stats'] as $section) {
                if (!array_key_exists($section, $metrics[$phase]) || !is_array($metrics[$phase][$section])) {
                    throw new \InvalidArgumentException("TemplateMatches JSON missing required metrics.{$phase}.{$section}");
                }
            }
        }

        $pairingQuality = $metrics['pairing']['quality'];
        $pairingStats = $metrics['pairing']['stats'];
        $matchMakingQuality = $metrics['matchMaking']['quality'];
        $matchMakingStats = $metrics['matchMaking']['stats'];
        $orderingQuality = $metrics['ordering']['quality'];
        $orderingStats = $metrics['ordering']['stats'];

        if (array_key_exists('hasDifferentPartnersNumber', $pairingQuality)
            || array_key_exists('hasDifferentPartnersNumber', $matchMakingQuality)
        ) {
            throw new \InvalidArgumentException(
                "TemplateMatches JSON contains legacy key 'hasDifferentPartnersNumber'; use 'partnersCountVariation' instead and regenerate the templates."
            );
        }

        $matches = null;
        if (isset($data['matches']) && is_array($data['matches'])) {
            $matches = self::normalizeMatchesByCourt($data['matches'], (int) $data['courts']);
        }

        foreach (['minPartnersFairness', 'avgPartnersFairness'] as $required) {
            if (!array_key_exists($required, $pairingQuality)) {
                throw new \InvalidArgumentException(
                    "TemplateMatches JSON missing required metrics.pairing.quality key: {$required}"
                );
            }
        }

        return new self(
            (int) $data['players'],
            (int) $data['partners'],
            (int) $data['repeat'],
            (int) $data['courts'],
            (bool) $data['fixedTeams'],
            $matches,
            $pairingQuality['minPartnersFairness'] !== null ? (float) $pairingQuality['minPartnersFairness'] : null,
            $pairingQuality['avgPartnersFairness'] !== null ? (float) $pairingQuality['avgPartnersFairness'] : null,
            isset($pairingQuality['partnersCount']) && is_array($pairingQuality['partnersCount'])
                ? $pairingQuality['partnersCount']
                : null,
            isset($pairingQuality['partnersCountVariation']) ? (int) $pairingQuality['partnersCountVariation'] : null,
            isset($pairingQuality['pairCount']) ? (int) $pairingQuality['pairCount'] : null,
            isset($pairingStats['stopReason']) ? (string) $pairingStats['stopReason'] : null,
            isset($pairingStats['time']) ? (float) $pairingStats['time'] : null,
            isset($pairingStats['nodesExplored']) ? (int) $pairingStats['nodesExplored'] : null,
            isset($pairingStats['seedIndex']) ? (int) $pairingStats['seedIndex'] : null,
            isset($pairingStats['seedsTotal']) ? (int) $pairingStats['seedsTotal'] : null,
            isset($matchMakingQuality['meetingsVariation']) ? (float) $matchMakingQuality['meetingsVariation'] : null,
            isset($matchMakingQuality['minOpponentsMet']) ? (int) $matchMakingQuality['minOpponentsMet'] : null,
            isset($matchMakingQuality['maxOpponentsMet']) ? (int) $matchMakingQuality['maxOpponentsMet'] : null,
            isset($matchMakingQuality['playersMet']) && is_array($matchMakingQuality['playersMet'])
                ? self::normalizePlayersMet($matchMakingQuality['playersMet'])
                : null,
            isset($matchMakingQuality['matchesCount']) ? (int) $matchMakingQuality['matchesCount'] : null,
            isset($matchMakingStats['permutationsIterated']) ? (int) $matchMakingStats['permutationsIterated'] : null,
            isset($matchMakingStats['permutationIndex']) ? (int) $matchMakingStats['permutationIndex'] : null,
            isset($matchMakingStats['templatesGenerated']) ? (int) $matchMakingStats['templatesGenerated'] : null,
            isset($matchMakingStats['templateIndex']) ? (int) $matchMakingStats['templateIndex'] : null,
            isset($matchMakingStats['stopReason']) ? (string) $matchMakingStats['stopReason'] : null,
            isset($matchMakingStats['time']) ? (float) $matchMakingStats['time'] : null,
            isset($matchMakingStats['meetingsVariationLimit']) ? (int) $matchMakingStats['meetingsVariationLimit'] : null,
            isset($matchMakingStats['relaxAttempts']) && is_array($matchMakingStats['relaxAttempts'])
                ? self::normalizeRelaxAttempts($matchMakingStats['relaxAttempts'])
                : null,
            isset($orderingQuality['minDistribution']) ? (float) $orderingQuality['minDistribution'] : null,
            isset($orderingQuality['avgDistribution']) ? (float) $orderingQuality['avgDistribution'] : null,
            isset($orderingQuality['minBreak']) ? (int) $orderingQuality['minBreak'] : null,
            isset($orderingQuality['maxBreak']) ? (int) $orderingQuality['maxBreak'] : null,
            isset($orderingQuality['courtSwitches']) ? (int) $orderingQuality['courtSwitches'] : null,
            isset($orderingQuality['courtBalance']) ? (float) $orderingQuality['courtBalance'] : null,
            isset($orderingQuality['roundsCount']) ? (int) $orderingQuality['roundsCount'] : null,
            isset($orderingStats['stopReason']) ? (string) $orderingStats['stopReason'] : null,
            isset($orderingStats['permutationsIterated']) ? (int) $orderingStats['permutationsIterated'] : null,
            isset($orderingStats['permutationIndex']) ? (int) $orderingStats['permutationIndex'] : null,
            isset($orderingStats['nodesExplored']) ? (int) $orderingStats['nodesExplored'] : null,
            isset($orderingStats['seedIndex']) ? (int) $orderingStats['seedIndex'] : null,
            isset($orderingStats['seedsTotal']) ? (int) $orderingStats['seedsTotal'] : null,
            isset($orderingStats['time']) ? (float) $orderingStats['time'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'players' => $this->players,
            'partners' => $this->partners,
            'repeat' => $this->repeat,
            'courts' => $this->courts,
            'fixedTeams' => $this->fixedTeams,
            'matches' => $this->matches,
            'metrics' => [
                'pairing' => [
                    'quality' => [
                        'minPartnersFairness' => $this->pairingQualityMinPartnersFairness,
                        'avgPartnersFairness' => $this->pairingQualityAvgPartnersFairness,
                        'partnersCount' => $this->pairingQualityPartnersCount,
                        'partnersCountVariation' => $this->pairingQualityPartnersCountVariation,
                        'pairCount' => $this->pairingQualityPairCount,
                    ],
                    'stats' => [
                        'stopReason' => $this->pairingStatsStopReason,
                        'time' => $this->pairingStatsTime,
                        'nodesExplored' => $this->pairingStatsNodesExplored,
                        'seedIndex' => $this->pairingStatsSeedIndex,
                        'seedsTotal' => $this->pairingStatsSeedsTotal,
                    ],
                ],
                'matchMaking' => [
                    'quality' => [
                        'meetingsVariation' => $this->matchMakingQualityMeetingsVariation,
                        'minOpponentsMet' => $this->matchMakingQualityMinOpponentsMet,
                        'maxOpponentsMet' => $this->matchMakingQualityMaxOpponentsMet,
                        'playersMet' => $this->matchMakingQualityPlayersMet,
                        'matchesCount' => $this->matchMakingQualityMatchesCount,
                    ],
                    'stats' => [
                        'permutationsIterated' => $this->matchMakingStatsPermutationsIterated,
                        'permutationIndex' => $this->matchMakingStatsPermutationIndex,
                        'templatesGenerated' => $this->matchMakingStatsTemplatesGenerated,
                        'templateIndex' => $this->matchMakingStatsTemplateIndex,
                        'stopReason' => $this->matchMakingStatsStopReason,
                        'time' => $this->matchMakingStatsTime,
                        'meetingsVariationLimit' => $this->matchMakingStatsMeetingsVariationLimit,
                        'relaxAttempts' => $this->matchMakingStatsRelaxAttempts,
                    ],
                ],
                'ordering' => [
                    'quality' => [
                        'minDistribution' => $this->orderingQualityMinDistribution,
                        'avgDistribution' => $this->orderingQualityAvgDistribution,
                        'minBreak' => $this->orderingQualityMinBreak,
                        'maxBreak' => $this->orderingQualityMaxBreak,
                        'courtSwitches' => $this->orderingQualityCourtSwitches,
                        'courtBalance' => $this->orderingQualityCourtBalance,
                        'roundsCount' => $this->orderingQualityRoundsCount,
                    ],
                    'stats' => [
                        'stopReason' => $this->orderingStatsStopReason,
                        'permutationsIterated' => $this->orderingStatsPermutationsIterated,
                        'permutationIndex' => $this->orderingStatsPermutationIndex,
                        'nodesExplored' => $this->orderingStatsNodesExplored,
                        'seedIndex' => $this->orderingStatsSeedIndex,
                        'seedsTotal' => $this->orderingStatsSeedsTotal,
                        'time' => $this->orderingStatsTime,
                    ],
                ],
            ],
        ];
    }

    /**
     * Validates and normalises the per-court matches shape. Rejects legacy flat lists.
     *
     * @param array<int, mixed> $raw
     * @return array<int, array<int, array<int, array<int, int>>>>
     */
    private static function normalizeMatchesByCourt(array $raw, int $courts): array
    {
        if ($courts < 1) {
            throw new \InvalidArgumentException('TemplateMatches JSON courts must be >= 1.');
        }

        if (count($raw) !== $courts) {
            throw new \InvalidArgumentException(sprintf(
                'TemplateMatches JSON matches must have exactly %d court list(s); got %d.',
                $courts,
                count($raw)
            ));
        }

        $normalized = [];
        foreach ($raw as $courtIndex => $courtMatches) {
            if (!is_array($courtMatches)) {
                throw new \InvalidArgumentException('TemplateMatches JSON matches court entry must be an array.');
            }

            $normalizedCourt = [];
            foreach ($courtMatches as $roundIndex => $match) {
                if (!is_array($match) || !isset($match[0], $match[1])) {
                    throw new \InvalidArgumentException('TemplateMatches JSON match must be [[p,p],[p,p]].');
                }
                if (!is_array($match[0]) || !is_array($match[1])) {
                    throw new \InvalidArgumentException(
                        'TemplateMatches JSON uses legacy flat match list; regenerate with per-court matches shape.'
                    );
                }
                $normalizedCourt[(int) $roundIndex] = $match;
            }
            $normalized[(int) $courtIndex] = $normalizedCourt;
        }

        return $normalized;
    }

    /**
     * JSON round-trips integer keys as strings; restore them so getMatchMakingQualityPlayersMetBy(0) works
     * again.
     *
     * @param array<int|string, array<int|string, int>> $playersMet
     * @return array<int, array<int, int>>
     */
    private static function normalizePlayersMet(array $playersMet): array
    {
        $normalized = [];
        foreach ($playersMet as $player => $opponents) {
            $key = is_numeric($player) ? (int) $player : $player;
            $normalized[$key] = [];
            foreach ($opponents as $opponent => $count) {
                $oKey = is_numeric($opponent) ? (int) $opponent : $opponent;
                $normalized[$key][$oKey] = (int) $count;
            }
        }

        return $normalized;
    }

    /**
     * Coerces the deserialised matchMaking.stats.relaxAttempts array into the canonical shape used
     * everywhere else (int / float fields, fixed key order).
     *
     * @param array<int, array<string, mixed>> $raw
     * @return array<int, array{meetingsVariationLimit:int,permutationsIterated:int,templatesGenerated:int,time:float}>
     */
    private static function normalizeRelaxAttempts(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized[] = [
                'meetingsVariationLimit' => isset($entry['meetingsVariationLimit']) ? (int) $entry['meetingsVariationLimit'] : 0,
                'permutationsIterated' => isset($entry['permutationsIterated']) ? (int) $entry['permutationsIterated'] : 0,
                'templatesGenerated' => isset($entry['templatesGenerated']) ? (int) $entry['templatesGenerated'] : 0,
                'time' => isset($entry['time']) ? (float) $entry['time'] : 0.0,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, array<int, int>>|null $playersMet
     * @return array{min: int|null, max: int|null}
     */
    private static function opponentsMetBounds(?array $playersMet, int $playersCount): array
    {
        return OpponentsMetSummary::fromPlayersMet($playersMet, $playersCount);
    }

    private static function nsToSeconds(int $ns): float
    {
        return $ns / 1_000_000_000;
    }
}
