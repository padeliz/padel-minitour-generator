<?php

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\Service\Progress\OrderingProgress;
use Arshavinel\PadelMiniTour\Service\Progress\PairingProgress;

/**
 * Immutable result of one template generation.
 *
 * Self-describing: carries its own identity (players, partners, repeat, fixedTeams) alongside the
 * generated match list and the diagnostic stats from each generation phase.
 *
 * Field layout mirrors the JSON shape persisted by {@see TemplateMatchesRepository}:
 *
 * - Identity (required, non-nullable): players, partners, repeat, fixedTeams.
 * - Top-level result: matches (null when no eligible permutation was found).
 * - Pairing-phase diagnostics (prefixed `pairing*`): the outer pair-ordering loop's outputs.
 * - Sorting-phase diagnostics (prefixed `sorting*`): the sortMatches() outputs.
 *
 * Every diagnostic getter is phase-prefixed so its provenance is obvious at call sites.
 */
final class TemplateMatches
{
    private int $players;
    private int $partners;
    private int $repeat;
    private bool $fixedTeams;

    /** @var array<int, array<int, array<int, int>>>|null */
    private ?array $matches;

    private ?float $pairingMeetingsVariation;
    private ?int $pairingPermutationsIterated;
    private ?int $pairingPermutationIndex;
    private ?int $pairingTemplatesGenerated;
    private ?int $pairingTemplateIndex;
    /** @var array<int, int>|null */
    private ?array $pairingPartnersCount;
    /** @var array<int, array<int, int>>|null */
    private ?array $pairingPlayersMet;
    private ?int $pairingPartnersCountVariation;
    private ?int $pairingBestMatchesCount;
    private ?string $pairingStopReason;
    private ?float $pairingTime;
    private ?int $pairingDifferenceLimit;
    /** @var array<int, array{differenceLimit:int,permutationsIterated:int,templatesGenerated:int,time:float}>|null */
    private ?array $pairingRelaxAttempts;

    private ?string $sortingStopReason;
    private ?float $sortingMinDistribution;
    private ?float $sortingAvgDistribution;
    private ?int $sortingPermutationsIterated;
    private ?int $sortingPermutationIndex;
    private ?int $sortingMinBreak;
    private ?int $sortingMaxBreak;
    private ?float $sortingTime;

    /**
     * @param array<int, array<int, array<int, int>>>|null $matches
     * @param array<int, int>|null                         $pairingPartnersCount
     * @param array<int, array<int, int>>|null             $pairingPlayersMet
     * @param array<int, array{differenceLimit:int,permutationsIterated:int,templatesGenerated:int,time:float}>|null $pairingRelaxAttempts
     */
    public function __construct(
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        ?array $matches,
        ?float $pairingMeetingsVariation,
        ?int $pairingPermutationsIterated,
        ?int $pairingPermutationIndex,
        ?int $pairingTemplatesGenerated,
        ?int $pairingTemplateIndex,
        ?array $pairingPartnersCount,
        ?array $pairingPlayersMet,
        ?int $pairingPartnersCountVariation,
        ?int $pairingBestMatchesCount,
        ?string $pairingStopReason,
        ?float $pairingTime,
        ?string $sortingStopReason,
        ?float $sortingMinDistribution,
        ?float $sortingAvgDistribution,
        ?int $sortingPermutationsIterated,
        ?int $sortingPermutationIndex,
        ?int $sortingMinBreak,
        ?int $sortingMaxBreak,
        ?float $sortingTime,
        ?int $pairingDifferenceLimit = null,
        ?array $pairingRelaxAttempts = null
    ) {
        $this->players = $players;
        $this->partners = $partners;
        $this->repeat = $repeat;
        $this->fixedTeams = $fixedTeams;
        $this->matches = $matches;
        $this->pairingMeetingsVariation = $pairingMeetingsVariation;
        $this->pairingPermutationsIterated = $pairingPermutationsIterated;
        $this->pairingPermutationIndex = $pairingPermutationIndex;
        $this->pairingTemplatesGenerated = $pairingTemplatesGenerated;
        $this->pairingTemplateIndex = $pairingTemplateIndex;
        $this->pairingPartnersCount = $pairingPartnersCount;
        $this->pairingPlayersMet = $pairingPlayersMet;
        $this->pairingPartnersCountVariation = $pairingPartnersCountVariation;
        $this->pairingBestMatchesCount = $pairingBestMatchesCount;
        $this->pairingStopReason = $pairingStopReason;
        $this->pairingTime = $pairingTime;
        $this->sortingStopReason = $sortingStopReason;
        $this->sortingMinDistribution = $sortingMinDistribution;
        $this->sortingAvgDistribution = $sortingAvgDistribution;
        $this->sortingPermutationsIterated = $sortingPermutationsIterated;
        $this->sortingPermutationIndex = $sortingPermutationIndex;
        $this->sortingMinBreak = $sortingMinBreak;
        $this->sortingMaxBreak = $sortingMaxBreak;
        $this->sortingTime = $sortingTime;
        $this->pairingDifferenceLimit = $pairingDifferenceLimit;
        $this->pairingRelaxAttempts = $pairingRelaxAttempts;
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

    public function getMatches(): ?array
    {
        return $this->matches;
    }

    public function getPairingMeetingsVariation(): ?float
    {
        return $this->pairingMeetingsVariation;
    }

    public function getPairingPermutationsIterated(): ?int
    {
        return $this->pairingPermutationsIterated;
    }

    public function getPairingPermutationIndex(): ?int
    {
        return $this->pairingPermutationIndex;
    }

    public function getPairingTemplatesGenerated(): ?int
    {
        return $this->pairingTemplatesGenerated;
    }

    public function getPairingTemplateIndex(): ?int
    {
        return $this->pairingTemplateIndex;
    }

    public function getPairingPartnersCount(): ?array
    {
        return $this->pairingPartnersCount;
    }

    /**
     * @param int|string $player
     */
    public function getPairingPartnersCountBy($player): ?int
    {
        return $this->pairingPartnersCount[$player] ?? null;
    }

    public function getPairingPlayersMet(): ?array
    {
        return $this->pairingPlayersMet;
    }

    /**
     * @param int|string $player
     * @return array<int, int>|null
     */
    public function getPairingPlayersMetBy($player): ?array
    {
        return $this->pairingPlayersMet[$player] ?? null;
    }

    public function getPairingPartnersCountVariation(): ?int
    {
        return $this->pairingPartnersCountVariation;
    }

    public function getPairingBestMatchesCount(): ?int
    {
        return $this->pairingBestMatchesCount;
    }

    public function getPairingStopReason(): ?string
    {
        return $this->pairingStopReason;
    }

    public function getPairingTime(): ?float
    {
        return $this->pairingTime;
    }

    /**
     * The `differenceLimit` at which the pairing phase finally produced a template (or the max
     * attempted value when none was found). Surfaces the S6 adaptive auto-relax outcome:
     * `1` means the strict build succeeded, `2` (or higher) means the loop had to relax.
     */
    public function getPairingDifferenceLimit(): ?int
    {
        return $this->pairingDifferenceLimit;
    }

    /**
     * Per-attempt forensic trail of the S6 pairing relax loop. Always populated for a generated
     * mixed-teams template (length 1 on the happy path); `null` for templates predating S6.
     *
     * Each entry is `{differenceLimit, permutationsIterated, templatesGenerated, time}`.
     *
     * @return array<int, array{differenceLimit:int,permutationsIterated:int,templatesGenerated:int,time:float}>|null
     */
    public function getPairingRelaxAttempts(): ?array
    {
        return $this->pairingRelaxAttempts;
    }

    public function getSortingStopReason(): ?string
    {
        return $this->sortingStopReason;
    }

    public function getSortingMinDistribution(): ?float
    {
        return $this->sortingMinDistribution;
    }

    public function getSortingAvgDistribution(): ?float
    {
        return $this->sortingAvgDistribution;
    }

    public function getSortingPermutationsIterated(): ?int
    {
        return $this->sortingPermutationsIterated;
    }

    public function getSortingPermutationIndex(): ?int
    {
        return $this->sortingPermutationIndex;
    }

    /**
     * Cross-player minimum of each player's shortest INNER consecutive break run.
     *
     * An inner break run is a maximal stretch of consecutive matches in which the player does
     * not appear, bracketed by appearances on BOTH sides. Lead runs (before the player's first
     * appearance) and trail runs (after the player's last appearance) are EXCLUDED. When a
     * player has no inner break runs (plays every match, plays only once, or never plays at
     * all), their per-player value is `0`, which means the aggregate becomes `0` whenever any
     * player has no inner runs.
     *
     * Ordering-sensitive: this metric meaningfully participates in the sort phase as the third
     * tie-break tier `(minBreak + maxBreak) / 2` versus `m / playerMatches`. `null` when the
     * sort phase produced no leaf (infeasible prune or deadline before any complete ordering).
     */
    public function getSortingMinBreak(): ?int
    {
        return $this->sortingMinBreak;
    }

    /**
     * Cross-player maximum of each player's longest consecutive break run.
     *
     * Unlike {@see getSortingMinBreak()}, this metric includes lead, inner, AND trail runs --
     * any maximal absence stretch in the schedule contributes. The sort DFS also enforces this
     * as a hard prune ceiling at `ceil(playersCount / 4)`, so the leaf value is always
     * bounded by that threshold when present.
     */
    public function getSortingMaxBreak(): ?int
    {
        return $this->sortingMaxBreak;
    }

    public function getSortingTime(): ?float
    {
        return $this->sortingTime;
    }

    /**
     * Whether the generator successfully produced a complete template.
     */
    public function isEligible(): bool
    {
        return $this->matches !== null;
    }

    /**
     * Builds a partial DTO from in-flight generation progress.
     *
     * Designed for the live-progress renderer: every diagnostic the events expose is mirrored into
     * the snapshot, so the shared {@see StatsFormatterTrait::buildUnifiedRow()} fills cells as soon
     * as the underlying value becomes available mid-flight. Diagnostics that the events do not yet
     * carry (e.g. sorting stop reason on a pre-final ordering event) stay null and render as `-`.
     *
     * `matches` is always null in a snapshot - the schedule does not exist until the generator
     * returns. `isEligible()` therefore returns false for snapshots, which is correct: the run has
     * not produced a usable template yet.
     */
    public static function fromProgress(
        int $players,
        int $partners,
        int $repeat,
        bool $fixedTeams,
        ?PairingProgress $pairing,
        ?OrderingProgress $ordering
    ): self {
        $pairingMeetingsVariation = null;
        $pairingPermutationsIterated = null;
        $pairingPermutationIndex = null;
        $pairingTemplatesGenerated = null;
        $pairingTemplateIndex = null;
        $pairingPartnersCount = null;
        $pairingPlayersMet = null;
        $pairingPartnersCountVariation = null;
        $pairingBestMatchesCount = null;
        $pairingStopReason = null;
        $pairingTime = null;
        if ($pairing !== null) {
            $pairingMeetingsVariation = $pairing->getBestMeetingsVariation();
            $pairingPermutationsIterated = $pairing->getIterations();
            $pairingPermutationIndex = $pairing->getBestPermutationIndex();
            $pairingTemplatesGenerated = $pairing->getTemplatesGenerated();
            $pairingTemplateIndex = $pairing->getBestTemplateIndex();
            $pairingPartnersCount = $pairing->getPartnersCount();
            $pairingPlayersMet = $pairing->getPlayersMet();
            $pairingPartnersCountVariation = $pairing->getPartnersCountVariation();
            $pairingBestMatchesCount = $pairing->getBestMatchesCount();
            $pairingStopReason = $pairing->getAggregateStopReason();
            $pairingTime = self::nsToSeconds($pairing->getElapsedNs());
        }

        $sortingStopReason = null;
        $sortingMinDistribution = null;
        $sortingAvgDistribution = null;
        $sortingPermutationsIterated = null;
        $sortingPermutationIndex = null;
        $sortingMinBreak = null;
        $sortingMaxBreak = null;
        $sortingTime = null;
        if ($ordering !== null) {
            $sortingStopReason = $ordering->getStopReason();
            $sortingMinDistribution = $ordering->getBestMin();
            $sortingAvgDistribution = $ordering->getBestAvg();
            $sortingPermutationsIterated = $ordering->getIterations();
            $sortingPermutationIndex = $ordering->getBestPermutationIndex();
            $sortingMinBreak = $ordering->getBestMinBreak();
            $sortingMaxBreak = $ordering->getBestMaxBreak();
            $sortingTime = self::nsToSeconds($ordering->getElapsedNs());
        }

        return new self(
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            null,
            $pairingMeetingsVariation,
            $pairingPermutationsIterated,
            $pairingPermutationIndex,
            $pairingTemplatesGenerated,
            $pairingTemplateIndex,
            $pairingPartnersCount,
            $pairingPlayersMet,
            $pairingPartnersCountVariation,
            $pairingBestMatchesCount,
            $pairingStopReason,
            $pairingTime,
            $sortingStopReason,
            $sortingMinDistribution,
            $sortingAvgDistribution,
            $sortingPermutationsIterated,
            $sortingPermutationIndex,
            $sortingMinBreak,
            $sortingMaxBreak,
            $sortingTime
        );
    }

    /**
     * Decodes a JSON-shaped associative array into a {@see TemplateMatches} instance.
     *
     * Strict on the identity keys (players, partners, repeat, fixedTeams) and on the presence of
     * the `pairing` and `sorting` objects. Legacy keys from older schema variants (the boolean
     * `hasDifferentPartnersNumber`, the top-level `estimatedGenerationTime` / `generationTime`)
     * are rejected loudly so a stale file surfaces as an explicit error rather than silently
     * round-tripping into the new shape.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException when a required key is missing or a forbidden legacy key is present.
     */
    public static function fromArray(array $data): self
    {
        foreach (['players', 'partners', 'repeat', 'fixedTeams'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException("TemplateMatches JSON missing required identity key: {$key}");
            }
        }
        foreach (['pairing', 'sorting'] as $key) {
            if (!array_key_exists($key, $data) || !is_array($data[$key])) {
                throw new \InvalidArgumentException("TemplateMatches JSON missing required object: {$key}");
            }
        }
        foreach (['estimatedGenerationTime', 'generationTime'] as $forbidden) {
            if (array_key_exists($forbidden, $data)) {
                throw new \InvalidArgumentException(
                    "TemplateMatches JSON contains legacy top-level key '{$forbidden}'; regenerate the templates with the current schema."
                );
            }
        }

        $pairing = $data['pairing'];
        $sorting = $data['sorting'];

        if (array_key_exists('hasDifferentPartnersNumber', $pairing)) {
            throw new \InvalidArgumentException(
                "TemplateMatches JSON pairing contains legacy key 'hasDifferentPartnersNumber'; use 'partnersCountVariation' instead and regenerate the templates."
            );
        }

        return new self(
            (int) $data['players'],
            (int) $data['partners'],
            (int) $data['repeat'],
            (bool) $data['fixedTeams'],
            isset($data['matches']) && is_array($data['matches']) ? $data['matches'] : null,
            isset($pairing['meetingsVariation']) ? (float) $pairing['meetingsVariation'] : null,
            isset($pairing['permutationsIterated']) ? (int) $pairing['permutationsIterated'] : null,
            isset($pairing['permutationIndex']) ? (int) $pairing['permutationIndex'] : null,
            isset($pairing['templatesGenerated']) ? (int) $pairing['templatesGenerated'] : null,
            isset($pairing['templateIndex']) ? (int) $pairing['templateIndex'] : null,
            isset($pairing['partnersCount']) && is_array($pairing['partnersCount']) ? $pairing['partnersCount'] : null,
            isset($pairing['playersMet']) && is_array($pairing['playersMet'])
                ? self::normalizePlayersMet($pairing['playersMet'])
                : null,
            isset($pairing['partnersCountVariation']) ? (int) $pairing['partnersCountVariation'] : null,
            isset($pairing['bestMatchesCount']) ? (int) $pairing['bestMatchesCount'] : null,
            isset($pairing['stopReason']) ? (string) $pairing['stopReason'] : null,
            isset($pairing['time']) ? (float) $pairing['time'] : null,
            isset($sorting['stopReason']) ? (string) $sorting['stopReason'] : null,
            isset($sorting['minDistribution']) ? (float) $sorting['minDistribution'] : null,
            isset($sorting['avgDistribution']) ? (float) $sorting['avgDistribution'] : null,
            isset($sorting['permutationsIterated']) ? (int) $sorting['permutationsIterated'] : null,
            isset($sorting['permutationIndex']) ? (int) $sorting['permutationIndex'] : null,
            isset($sorting['minBreak']) ? (int) $sorting['minBreak'] : null,
            isset($sorting['maxBreak']) ? (int) $sorting['maxBreak'] : null,
            isset($sorting['time']) ? (float) $sorting['time'] : null,
            isset($pairing['differenceLimit']) ? (int) $pairing['differenceLimit'] : null,
            isset($pairing['relaxAttempts']) && is_array($pairing['relaxAttempts'])
                ? self::normalizeRelaxAttempts($pairing['relaxAttempts'])
                : null
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
            'fixedTeams' => $this->fixedTeams,
            'matches' => $this->matches,
            'pairing' => [
                'meetingsVariation' => $this->pairingMeetingsVariation,
                'permutationsIterated' => $this->pairingPermutationsIterated,
                'permutationIndex' => $this->pairingPermutationIndex,
                'templatesGenerated' => $this->pairingTemplatesGenerated,
                'templateIndex' => $this->pairingTemplateIndex,
                'partnersCount' => $this->pairingPartnersCount,
                'playersMet' => $this->pairingPlayersMet,
                'partnersCountVariation' => $this->pairingPartnersCountVariation,
                'bestMatchesCount' => $this->pairingBestMatchesCount,
                'stopReason' => $this->pairingStopReason,
                'time' => $this->pairingTime,
                'differenceLimit' => $this->pairingDifferenceLimit,
                'relaxAttempts' => $this->pairingRelaxAttempts,
            ],
            'sorting' => [
                'stopReason' => $this->sortingStopReason,
                'minDistribution' => $this->sortingMinDistribution,
                'avgDistribution' => $this->sortingAvgDistribution,
                'permutationsIterated' => $this->sortingPermutationsIterated,
                'permutationIndex' => $this->sortingPermutationIndex,
                'minBreak' => $this->sortingMinBreak,
                'maxBreak' => $this->sortingMaxBreak,
                'time' => $this->sortingTime,
            ],
        ];
    }

    /**
     * JSON round-trips integer keys as strings; restore them so getPairingPlayersMetBy(0) works
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
     * Coerces the deserialised pairing.relaxAttempts array into the canonical shape used
     * everywhere else (int / float fields, fixed key order).
     *
     * @param array<int, array<string, mixed>> $raw
     * @return array<int, array{differenceLimit:int,permutationsIterated:int,templatesGenerated:int,time:float}>
     */
    private static function normalizeRelaxAttempts(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized[] = [
                'differenceLimit' => isset($entry['differenceLimit']) ? (int) $entry['differenceLimit'] : 0,
                'permutationsIterated' => isset($entry['permutationsIterated']) ? (int) $entry['permutationsIterated'] : 0,
                'templatesGenerated' => isset($entry['templatesGenerated']) ? (int) $entry['templatesGenerated'] : 0,
                'time' => isset($entry['time']) ? (float) $entry['time'] : 0.0,
            ];
        }

        return $normalized;
    }

    private static function nsToSeconds(int $ns): float
    {
        return $ns / 1_000_000_000;
    }
}
