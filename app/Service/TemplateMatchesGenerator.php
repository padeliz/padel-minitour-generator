<?php

namespace Arshavinel\PadelMiniTour\Service;

use Arshavinel\PadelMiniTour\Service\Progress\GenerationProgress;
use Arshavinel\PadelMiniTour\Service\Progress\ProgressReporter;
use Arshwell\Monolith\Func;

/**
 * Pure, stateless template generator.
 *
 * Holds no filesystem state and no static mutable state. Persistence is delegated entirely to
 * {@see TemplateMatchesRepository}.
 *
 * Search policy:
 *
 * - **Outer pair-ordering loop** (mixed teams): under S1, the lex walk is replaced with a
 *   {@code K seeds × one DFS per seed} backtracking search. {@see DEFAULT_MULTI_SEED_COUNT_PAIRING}
 *   distant Lehmer-coded permutations seed `K` independent DFS attempts, each receiving an equal
 *   slice of the per-combo wall budget. Each DFS run produces at most one template — the
 *   `(meetingsVariation, seedIndex)` lex best across seeds wins. Two prune signals fire inside
 *   each DFS: the existing {@see playersMetTooMuch()} hard constraint, and a Min-Met B&B prune
 *   that kills branches that cannot beat the current global-best template's Min Met.
 *
 *   Below {@see DEFAULT_MULTI_SEED_THRESHOLD_PAIRS} pairs the search collapses to a single seed
 *   (identity permutation), keeping small-combo outputs bit-for-bit equivalent to the legacy
 *   greedy path whenever the first DFS choice matches greedy's choice (which it does — both
 *   visit candidates in ascending index order).
 *
 *   S6 wraps the entire pairing phase in an adaptive relaxation loop: if a strict
 *   {@code meetingsVariationLimit = 1} build yields zero templates, the loop bumps the limit up
 *   to {@see DEFAULT_MEETINGS_VARIATION_LIMIT_MAX} and retries with the full per-combo budget.
 *
 * - **{@see sortMatches()}**: deterministic backtracking DFS over match orderings with a hard
 *   prune on `Max Break > ceil(playersCount / 4)`. Tracks the lexicographic
 *   {@code (Min Dist, Avg Dist)} optimum; stops early when both early-stop thresholds are met or
 *   on deadline / pruned-infeasible fallback.
 *
 * The budgets, the early-stop thresholds, the multi-seed / branch-cap knobs and the clock are
 * constructor-injected so the class stays trivially testable.
 */
class TemplateMatchesGenerator
{
    use TemplateMatchesSortRoundDfs;

    /**
     * Supported `players => [partners, ...]` combinations targeted by the bulk regenerate run.
     *
     * Also used by the home form ({@see outcomes/site/home/frontend.php}) and the
     * regenerate/stats CLI commands.
     *
     * A combo `(N, partners)` can in principle achieve `Partners Var. = 0` (every player ends
     * up with the same number of distinct partners) only when both:
     *
     *   1. `N * partners` is divisible by 4 (total matches = `N * partners / 4` must be an
     *      integer in the repeat=1 pipeline).
     *   2. `partners <= N - 1` (a player cannot have more distinct partners than there are
     *      other players).
     *
     * Divisibility is necessary but not always sufficient: the meetings/opponents constraints
     * can still defeat the generator (see e.g. `16-8`, where the committed JSON has
     * `partnersCountVariation: null` because no eligible template was found within budget).
     *
     * Combos that violate condition 1 are intentionally absent: e.g. `6 => 5` (30 / 4 = 7.5),
     * `10 => 9` (90 / 4 = 22.5), and any `7 => partners` with partners not equal to 4 (7 is
     * odd, so partners must itself be divisible by 4; `partners <= 6` leaves only
     * `partners = 4`).
     */
    public const COMBINATIONS = [
        4 => [1, 2, 3],
        5 => [4],
        6 => [4],
        7 => [4],
        8 => [4, 6, 7],
        9 => [8],
        10 => [8],
        11 => [8],
        12 => [7, 8, 9],
        13 => [8],
        14 => [8],
        15 => [9, 12],
        16 => [8, 11, 12],
    ];

    /** 8 minutes, in nanoseconds — fallback when dynamic formula is not used in tests. */
    public const DEFAULT_OUTER_WALL_BUDGET_NS = 480_000_000_000;

    /** 8 minutes, in nanoseconds — fallback when dynamic formula is not used in tests. */
    public const DEFAULT_SORT_WALL_BUDGET_NS = 480_000_000_000;

    /** Base seconds for pairing wall-time formula. */
    public const PAIRING_BUDGET_BASE_S = 100.0;
    public const PAIRING_BUDGET_PER_PLAYER_S = 40.0;
    public const PAIRING_BUDGET_PER_MATCH_S = 30.0;
    public const PAIRING_BUDGET_MAX_S = 1800.0;

    /** Base seconds for sorting wall-time formula. */
    public const SORT_BUDGET_BASE_S = 100.0;
    public const SORT_BUDGET_PER_ROUND_S = 40.0;
    public const SORT_BUDGET_PER_COURT_S = 24.0;
    public const SORT_BUDGET_PER_MATCH_S = 20;
    public const SORT_BUDGET_MAX_S = 1800.0;

    /**
     * Default per-player meeting-gap tolerance used by {@see playersMetTooMuch()} during the
     * greedy pair-matching loop. The constraint rejects a candidate match whenever any of the
     * four involved players would end up with a gap strictly greater than this between their
     * most-met and least-met partner. A value of 1 means "gap of 1 is allowed; gap of 2 or more
     * is rejected." Tests can pass 0 for strictly-balanced builds or higher values to relax the
     * constraint for combos that cannot complete under the strict default.
     */
    public const DEFAULT_MEETINGS_VARIATION_LIMIT = 1;

    /**
     * Maximum value of {@code $meetingsVariationLimit} that the S6 adaptive auto-relax loop is
     * allowed to attempt. When the strict-build pairing phase returns no templates, the loop
     * bumps `$effectiveMeetingsVariationLimit` to `mvl + 1` and re-runs until a template is
     * found or the limit is reached. Each retry receives the full per-combo budget, so the
     * worst-case wall time is
     * `(meetingsVariationLimitMax - meetingsVariationLimit + 1) * outerBudget`.
     *
     * 3 vs the prior 2 gives one extra relaxation level for combos whose strict and mid builds
     * both fail to find a template within budget; under the historic 2-cap they returned the
     * "no eligible permutation" record (e.g. v3/16-8). At 3 the constraint becomes loose enough
     * that the per-player most-met minus least-met gap can be up to 3, which is generally
     * achievable even on graphs where the pairing structure resists tighter balance.
     */
    public const DEFAULT_MEETINGS_VARIATION_LIMIT_MAX = 3;

    /**
     * Number of distant Lehmer-coded seeds the outer pair-ordering loop fans out to under the
     * S1 DFS model. Each seed runs **one** independent DFS over the permuted pair list, receives
     * `outerWallBudgetNs / K` of the per-combo wall budget, and produces at most one template;
     * the global-best across seeds wins on `(meetingsVariation, seedIndex)` lex.
     *
     * 256 vs. the legacy 16 reflects the shift from "many lex permutations per seed" to "one
     * DFS per seed". Adjacent lex permutations share long prefixes, so the legacy walker
     * re-descended the same subtrees over and over; under DFS that's wasted work. With 16x more
     * distant seeds we get 16x broader coverage of the position-0 pair, which is the choice
     * point where the DFS branches widest.
     */
    public const DEFAULT_MULTI_SEED_COUNT_PAIRING = 256;

    /** Distant Lehmer seeds for the sort-phase round-slice DFS (same K as pairing by default). */
    public const DEFAULT_MULTI_SEED_COUNT_SORT = self::DEFAULT_MULTI_SEED_COUNT_PAIRING;

    /**
     * Maximum number of DFS recursion entries the pairing-phase {@see dfsExpand()} method may
     * make per seed before giving up. The cap protects against pathological combos where the
     * search tree explodes; in practice the strict `playersMetTooMuch` constraint plus the
     * Min Met B&B prune keep the branch count well below this ceiling.
     *
     * 10k is comfortably above the empirically-observed branch budget for every combo in
     * {@see COMBINATIONS} (including 16/12), with safety margin for future bumps to
     * `meetingsVariationLimit`.
     */
    public const DEFAULT_DFS_BRANCH_CAP = 10_000;

    /**
     * Pair-count threshold at or above which the outer loop switches from a single-seed identity
     * walk to a multi-seed walk.
     *
     * `n!` for n=11 is ~40M and is realistically coverable in 8 minutes for any combo; n=12 already
     * has `n! ≈ 479M` and the rate-limiting ones (12/2, 6/4) only scratch a fraction of that in
     * budget. Above this threshold we always benefit from spreading the budget across distinct
     * regions of the search space.
     */
    public const DEFAULT_MULTI_SEED_THRESHOLD_PAIRS = 12;

    public const STOP_REASON_TRIVIAL = 'TRIVIAL';
    public const STOP_REASON_DEADLINE = 'DEADLINE';
    public const STOP_REASON_FACTORIAL_COMPLETE = 'FACTORIAL_COMPLETE';

    /**
     * S7 sort DFS pruned every complete ordering before it could reach a leaf, i.e. the Max
     * Break threshold `ceil(playersCount / 4)` is so tight that no ordering of the input matches
     * satisfies it. Sort returns `ordered: null` with this stop reason.
     */
    public const STOP_REASON_PRUNE_INFEASIBLE = 'PRUNE_INFEASIBLE';

    /**
     * Canonical stop-reason key => human-facing display label rendered in the CLI table and any
     * other user-facing log line. The keys are the values stored in `PairingProgress` /
     * `OrderingProgress` events, in `TemplateMatches::getXxxStopReason()`, and in the persisted
     * JSON; the labels are read-only display strings and can be reworded freely without touching
     * code logic or the JSON schema.
     */
    public const STOP_REASONS = [
        self::STOP_REASON_FACTORIAL_COMPLETE => 'exhausted',
        self::STOP_REASON_DEADLINE => 'deadline',
        self::STOP_REASON_TRIVIAL => 'trivial',
        self::STOP_REASON_PRUNE_INFEASIBLE => 'infeasible',
    ];

    /**
     * Looks up the display label for a stop-reason key. Returns `null` for `null` input (so
     * callers can chain it directly on optional fields) and falls through to the raw key for
     * unknown values (stale JSON from older schema versions degrades gracefully rather than
     * blowing up the table).
     */
    public static function stopReasonLabel(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return self::STOP_REASONS[$key] ?? $key;
    }

    /**
     * CLI display thresholds for the sorter's `Avg Dist.` column. Presentation-only constants --
     * they do NOT participate in search, pruning, or any algorithmic decision. They are
     * calibrated against the piecewise asymmetric formula's observed output range. Numbers
     * `>= GREEN` render green, `>= YELLOW` render yellow, and anything below `YELLOW` renders red.
     *
     * The companion per-player thresholds (used for the `Min Dist.` column AND for the matches
     * page UI) live on {@see PlayerDistributionScorer::DISPLAY_GOOD} / {@see PlayerDistributionScorer::DISPLAY_FAIR}
     * so the CLI and the web surface cannot drift.
     */
    public const DISPLAY_AVG_DIST_GREEN = 0.85;
    public const DISPLAY_AVG_DIST_YELLOW = 0.70;

    /** Max time between two consecutive non-final progress emits, in nanoseconds (~4 ticks/s). */
    private const PROGRESS_EMIT_INTERVAL_NS = 250_000_000;

    /** @var callable|null */
    private $clock;

    private int $outerWallBudgetNs;
    private int $sortWallBudgetNs;
    private int $multiSeedCountPairing;
    private int $multiSeedCountSort;
    private int $multiSeedThresholdPairs;
    private int $meetingsVariationLimit;
    private int $meetingsVariationLimitMax;
    private int $dfsBranchCap;

    /**
     * Sort-phase DFS hard prune threshold on `Max Break` (the cross-player maximum of each
     * player's longest consecutive break run, INCLUDING lead, inner, and trail gaps). The DFS
     * prunes any branch where placing the candidate match would push some player's running
     * non-appearance counter strictly above this ceiling. `-1` is a sentinel meaning "derive
     * from playersCount at generation time via `ceil($playersCount / 4)`"; an explicit positive
     * value lets tests override the threshold to exercise the prune (very tight) or disable it
     * (very loose / >= match count). Note: this gates `Max Break` only; the asymmetric
     * `Min Break` metric (cross-player min of each player's shortest INNER break run) is
     * computed at the leaf and never participates in the prune.
     */
    private int $maxBreakThreshold;

    /** Courts count for the active {@see generate()} call (set per invocation). */
    private int $activeCourts = 1;

    /** When true, {@see budgetFor()} uses constructor-injected globals instead of the formula. */
    private bool $useStaticBudgets = false;

    /**
     * Wall-clock budgets resolved per `generate()` call by {@see budgetFor()}: either the
     * per-combo override from {@see $perComboBudgetsNs} or the constructor-injected fallback.
     * Every internal method reads through these so the same generator instance can produce
     * different combos sequentially with each using the right budget.
     */
    private int $effectiveOuterBudgetNs;
    private int $effectiveSortBudgetNs;

    /** Live-progress context for the active sort seed (set per seed inside {@see sortMatches()}). */
    private int $sortOrderingCurrentSeed = 1;
    private int $sortOrderingTotalSeeds = 1;
    private int $sortOrderingPerSeedBudgetNs = 0;

    /** @var callable|null function(GenerationProgress $event): void */
    private $progressCallback = null;

    /**
     * Stand-alone scorer used by both the sort-phase DFS leaf compute and the matches-page AJAX
     * endpoint. Holding it on the generator (rather than re-instantiating per call) keeps the
     * leaf compute call-site noise-free; the scorer is stateless so a single instance is safe
     * for the whole generator lifetime.
     */
    private PlayerDistributionScorer $distributionScorer;

    /**
     * @param callable|null $clock Optional `fn(): int` returning monotonic nanoseconds. Defaults
     *                             to `hrtime(true)`. Tests inject a deterministic clock to control
     *                             how budgets elapse without sleeping.
     * @param int $multiSeedCountPairing Number of distant Lehmer seeds the pairing-phase DFS
     *                                   fans out to. Pass `1` to disable multi-seed entirely,
     *                                   irrespective of pair count. Useful for tests that want
     *                                   the single-seed-identity DFS path.
     * @param int $multiSeedThresholdPairs Pair-count cutoff above which multi-seed kicks in. Lower
     *                                    it in tests to exercise the multi-seed code path on small
     *                                    combos.
     * @param int $meetingsVariationLimit Per-player meeting-gap tolerance for the pair-matching DFS.
     *                             See {@see DEFAULT_MEETINGS_VARIATION_LIMIT}.
     * @param int $multiSeedCountSort Number of distant Lehmer seeds the sort-phase DFS fans out
     *                               to. Pass `1` to force the identity match order.
     * @param int $dfsBranchCap Max recursion entries per pairing-phase DFS run (per seed); the
     *                          DFS aborts the seed and returns null once the cap is hit, leaving
     *                          the other seeds free to explore their own subtrees. Also applied
     *                          per seed in the sort-phase round-slice DFS.
     */
    public function __construct(
        ?callable $clock = null,
        int $outerWallBudgetNs = self::DEFAULT_OUTER_WALL_BUDGET_NS,
        int $sortWallBudgetNs = self::DEFAULT_SORT_WALL_BUDGET_NS,
        int $multiSeedCountPairing = self::DEFAULT_MULTI_SEED_COUNT_PAIRING,
        int $multiSeedThresholdPairs = self::DEFAULT_MULTI_SEED_THRESHOLD_PAIRS,
        int $meetingsVariationLimit = self::DEFAULT_MEETINGS_VARIATION_LIMIT,
        int $maxBreakThreshold = -1,
        int $meetingsVariationLimitMax = self::DEFAULT_MEETINGS_VARIATION_LIMIT_MAX,
        int $dfsBranchCap = self::DEFAULT_DFS_BRANCH_CAP,
        int $multiSeedCountSort = self::DEFAULT_MULTI_SEED_COUNT_SORT
    ) {
        $this->clock = $clock;
        $this->outerWallBudgetNs = $outerWallBudgetNs;
        $this->sortWallBudgetNs = $sortWallBudgetNs;
        $this->multiSeedCountPairing = max(1, $multiSeedCountPairing);
        $this->multiSeedCountSort = max(1, $multiSeedCountSort);
        $this->multiSeedThresholdPairs = max(1, $multiSeedThresholdPairs);
        $this->meetingsVariationLimit = max(0, $meetingsVariationLimit);
        $this->maxBreakThreshold = $maxBreakThreshold;
        // The relaxation ceiling cannot drop below the starting dl, otherwise the loop has no
        // headroom and behaves as if S6 were disabled.
        $this->meetingsVariationLimitMax = max($this->meetingsVariationLimit, $meetingsVariationLimitMax);
        $this->dfsBranchCap = max(1, $dfsBranchCap);
        $this->effectiveOuterBudgetNs = $outerWallBudgetNs;
        $this->effectiveSortBudgetNs = $sortWallBudgetNs;
        $this->distributionScorer = new PlayerDistributionScorer();
    }

    /**
     * Forces {@see budgetFor()} to return the constructor-injected globals (for fast tests).
     */
    public function setUseStaticBudgets(bool $useStaticBudgets): void
    {
        $this->useStaticBudgets = $useStaticBudgets;
    }

    public static function computePairingWallBudgetNs(int $players, int $partners, int $courts): int
    {
        $matchCount = ($players * $partners) / 4;
        $seconds = self::PAIRING_BUDGET_BASE_S
            + self::PAIRING_BUDGET_PER_PLAYER_S * $players
            + self::PAIRING_BUDGET_PER_MATCH_S * $matchCount;
        $seconds = min($seconds, self::PAIRING_BUDGET_MAX_S);

        return (int) round($seconds * 1_000_000_000);
    }

    public static function computeSortingWallBudgetNs(int $players, int $partners, int $courts): int
    {
        $matchCount = ($players * $partners) / 4;
        $roundsTotal = $courts > 0 ? (int) ceil($matchCount / $courts) : (int) $matchCount;
        $seconds = self::SORT_BUDGET_BASE_S
            + self::SORT_BUDGET_PER_ROUND_S * $roundsTotal
            + self::SORT_BUDGET_PER_COURT_S * $courts
            + self::SORT_BUDGET_PER_MATCH_S * $matchCount;
        $seconds = min($seconds, self::SORT_BUDGET_MAX_S);

        return (int) round($seconds * 1_000_000_000);
    }

    /**
     * @deprecated Tests only — no-op; per-combo map removed in favour of dynamic budgets.
     * @param array<string, array{0: int, 1: int}> $budgets
     */
    public function setPerComboBudgetsNs(array $budgets): void
    {
        $this->useStaticBudgets = empty($budgets);
    }

    /**
     * Subscribes to live generation progress. Pass `null` (or never call this) to disable.
     *
     * The callback is invoked with a {@see GenerationProgress} subclass for the pairing and
     * ordering phases. Both phases always emit at least one event with `isFinal() === true`,
     * regardless of generation mode or input size, and zero or more interim ticks before it
     * (interim ticks are time-throttled internally so the callback is hit at most ~4 times/second).
     *
     * @param callable|null $callback function(GenerationProgress $event): void
     */
    public function setProgressCallback(?callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    public function generate(
        int $players,
        int $partners,
        int $repeat,
        int $courts = 1,
        bool $fixedTeams = false
    ): TemplateMatches {
        $this->activeCourts = max(1, $courts);
        [$this->effectiveOuterBudgetNs, $this->effectiveSortBudgetNs] = $this->budgetFor(
            $players,
            $partners,
            $this->activeCourts,
            $fixedTeams
        );

        $reporter = new ProgressReporter(
            $this->progressCallback,
            self::PROGRESS_EMIT_INTERVAL_NS,
            $players,
            $partners,
            $repeat,
            $fixedTeams,
            $this->monotonicNow()
        );

        return $fixedTeams
            ? $this->generateFixed($players, $partners, $repeat, $reporter)
            : $this->generateMixed($players, $partners, $repeat, $reporter);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function budgetFor(int $players, int $partners, int $courts, bool $fixedTeams): array
    {
        if ($this->useStaticBudgets) {
            return [$this->outerWallBudgetNs, $this->sortWallBudgetNs];
        }

        return [
            self::computePairingWallBudgetNs($players, $partners, $courts),
            self::computeSortingWallBudgetNs($players, $partners, $courts),
        ];
    }

    private function generateMixed(
        int $playersCount,
        int $opponentsPerPlayer,
        int $repeatOpponents,
        ProgressReporter $reporter
    ): TemplateMatches {
        $mockPlayers = range(0, $playersCount - 1);
        list($pairs, $partnersCount) = $this->generateMixedPairs($mockPlayers, $opponentsPerPlayer);
        $partnersCountVariation = (empty($partnersCount) ? 0 : max($partnersCount) - min($partnersCount));

        $n = count($pairs);

        $effectiveMeetingsVariationLimit = $this->meetingsVariationLimit;
        $relaxAttempts = [];
        $phase = null;

        // S6: adaptive auto-relax. Try the strict `dl` first; if no template is found, bump dl
        // by one and re-run. Stop on success or when the relaxation ceiling is reached. Each
        // retry receives the full per-combo budget (deliberate: a relaxed pairing run might need
        // to search a different subtree at higher cost).
        while (true) {
            $phase = $this->runPairingPhase(
                $pairs,
                $n,
                $partnersCount,
                $partnersCountVariation,
                $effectiveMeetingsVariationLimit,
                $reporter
            );
            $relaxAttempts[] = [
                'meetingsVariationLimit' => $effectiveMeetingsVariationLimit,
                'permutationsIterated' => $phase['processes']['permutationsIterated'],
                'templatesGenerated' => $phase['processes']['templatesGenerated'],
                'time' => $phase['pairingTime'],
            ];

            if ($phase['bestTemplate']['matches'] !== null || $effectiveMeetingsVariationLimit >= $this->meetingsVariationLimitMax) {
                break;
            }
            $effectiveMeetingsVariationLimit++;
        }

        $bestTemplate = $phase['bestTemplate'];
        $processes = $phase['processes'];
        $pairingStopReason = $phase['pairingStopReason'];
        $pairingTime = $phase['pairingTime'];

        $sortingStartNs = $this->monotonicNow();
        $reporter->setPhaseStart($sortingStartNs);

        $sortResult = ['ordered' => [], 'stopReason' => self::STOP_REASON_TRIVIAL, 'min' => null, 'avg' => null, 'permutationIndex' => null, 'permutationsIterated' => 0, 'minBreak' => null, 'maxBreak' => null];

        if ($bestTemplate['matches'] === null) {
            $matches = null;
            $playersMet = null;
            $partnersCountFinal = null;

            // Renderer contract: every generate() call must produce one ordering-final event.
            // No template was found, so report the trivial branch with the input we don't have.
            $reporter->ordering(
                0,
                null,
                null,
                $this->effectiveSortBudgetNs,
                $this->monotonicNow(),
                true,
                self::STOP_REASON_TRIVIAL
            );
        } else {
            $sortResult = $this->sortMatches($bestTemplate['matches'], $mockPlayers, $reporter);
            if ($sortResult['ordered'] === null) {
                $matches = null;
            } else {
                $matches = $this->adjustServingOrderByCourt($sortResult['ordered'], $playersCount);
                $matches = $this->repeatMatchesByCourt($matches, $repeatOpponents);
            }

            $playersMet = $bestTemplate['playersMet'];
            $partnersCountFinal = $partnersCount;
        }

        $sortingTime = $this->nsToSeconds($this->monotonicNow() - $sortingStartNs);

        return new TemplateMatches(
            $playersCount,
            $opponentsPerPlayer,
            $repeatOpponents,
            $this->activeCourts,
            false,
            $matches,
            $bestTemplate['meetingsVariation'],
            $processes['permutationsIterated'],
            $bestTemplate['permutationIndex'],
            $processes['templatesGenerated'],
            $bestTemplate['templateIndex'],
            $partnersCountFinal,
            $playersMet,
            $matches !== null ? $partnersCountVariation : null,
            $matches !== null ? count($bestTemplate['matches']) : null,
            $pairingStopReason,
            $pairingTime,
            $sortResult['stopReason'],
            $sortResult['min'],
            $sortResult['avg'],
            $sortResult['permutationsIterated'],
            $sortResult['permutationIndex'],
            $sortResult['minBreak'],
            $sortResult['maxBreak'],
            $sortingTime,
            $effectiveMeetingsVariationLimit,
            $relaxAttempts,
            $sortResult['courtSwitches'] ?? null,
            $sortResult['courtBalance'] ?? null,
            $sortResult['nodesExplored'] ?? null,
            $sortResult['seedIndex'] ?? null,
            $sortResult['seedsTotal'] ?? null
        );
    }

    /**
     * Executes one full multi-seed pairing pass at the given `$meetingsVariationLimit`. Extracted from
     * {@see generateMixed()} so the S6 auto-relax loop can re-invoke it with a bumped dl when
     * the strict build yields no templates.
     *
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, int> $partnersCount
     * @return array{
     *     bestTemplate: array{
     *         meetingsVariation: float|null,
     *         matches: array<int, array<int, array<int, int>>>|null,
     *         playersMet: array<int, array<int, int>>,
     *         permutationIndex: int|null,
     *         templateIndex: int|null
     *     },
     *     processes: array{permutationsIterated: int, templatesGenerated: int},
     *     pairingStopReason: string,
     *     pairingTime: float,
     *     totalSeeds: int
     * }
     */
    private function runPairingPhase(
        array $pairs,
        int $n,
        array $partnersCount,
        int $partnersCountVariation,
        int $meetingsVariationLimit,
        ProgressReporter $reporter
    ): array {
        $useMultiSeed = ($n >= $this->multiSeedThresholdPairs && $this->multiSeedCountPairing > 1);
        $totalSeeds = $useMultiSeed ? $this->multiSeedCountPairing : 1;
        // intdiv is safe: totalSeeds is always >= 1 by construction. A 0-budget input still does
        // the right thing (each seed exits on the first deadline check).
        $perSeedBudgetNs = intdiv($this->effectiveOuterBudgetNs, $totalSeeds);

        $processes = [
            'permutationsIterated' => 0,
            'templatesGenerated' => 0,
        ];

        $bestTemplate = [
            'meetingsVariation' => null,
            'matches' => null,
            'playersMet' => [],
            'permutationIndex' => null,
            'templateIndex' => null,
            'minMet' => null,
        ];

        $pairingStartNs = $this->monotonicNow();
        $reporter->setPhaseStart($pairingStartNs);

        $playersCount = $this->inferPlayersCountFromPairs($pairs);

        $seedStopReasons = [];
        for ($seedIdx = 0; $seedIdx < $totalSeeds; $seedIdx++) {
            $startPerm = $useMultiSeed
                ? $this->lehmerSeedPermutation($seedIdx, $totalSeeds, $n)
                : range(0, $n - 1);

            $seedStopReasons[] = $this->runSeedDfs(
                $pairs,
                $startPerm,
                $perSeedBudgetNs,
                $seedIdx + 1,
                $totalSeeds,
                $reporter,
                $processes,
                $bestTemplate,
                $partnersCount,
                $partnersCountVariation,
                $meetingsVariationLimit,
                $playersCount
            );
        }

        $pairingStopReason = $this->aggregatePairingStopReason($seedStopReasons);
        $pairingEndNs = $this->monotonicNow();
        $pairingTime = $this->nsToSeconds($pairingEndNs - $pairingStartNs);

        $bestMatchesCount = $bestTemplate['matches'] !== null ? count($bestTemplate['matches']) : null;
        $reporter->pairing(
            $processes['permutationsIterated'],
            $processes['templatesGenerated'],
            $bestTemplate['meetingsVariation'],
            $this->effectiveOuterBudgetNs,
            $pairingEndNs,
            true,
            $totalSeeds,
            $totalSeeds,
            $bestTemplate['permutationIndex'],
            $bestTemplate['templateIndex'],
            $bestMatchesCount,
            $partnersCount,
            $bestTemplate['playersMet'],
            $partnersCountVariation,
            $pairingStopReason,
            $meetingsVariationLimit
        );

        return [
            'bestTemplate' => $bestTemplate,
            'processes' => $processes,
            'pairingStopReason' => $pairingStopReason,
            'pairingTime' => $pairingTime,
            'totalSeeds' => $totalSeeds,
        ];
    }

    /**
     * Pessimistic aggregation across seeds: returns `DEADLINE` if any seed exited because its
     * per-seed wall budget elapsed, otherwise `FACTORIAL_COMPLETE` (every seed exhausted its lex
     * space without hitting the budget). Single-seed runs degenerate to that one seed's reason.
     *
     * @param array<int, string> $seedStopReasons
     */
    private function aggregatePairingStopReason(array $seedStopReasons): string
    {
        if (empty($seedStopReasons)) {
            return self::STOP_REASON_FACTORIAL_COMPLETE;
        }
        foreach ($seedStopReasons as $reason) {
            if ($reason === self::STOP_REASON_DEADLINE) {
                return self::STOP_REASON_DEADLINE;
            }
        }
        return self::STOP_REASON_FACTORIAL_COMPLETE;
    }

    /**
     * Runs one DFS attempt rooted at {@code $startPerm}: orders the pair list by the seed
     * permutation, then calls {@see buildTemplateByBacktracking()} once. If the DFS finds a
     * complete template, the seed contributes to {@code $bestTemplate} on
     * `(meetingsVariation, seedIndex)` lex; if not, the seed contributes nothing and the next
     * one runs.
     *
     * Returns the seed's exit reason: `DEADLINE` if the per-seed wall budget elapsed during
     * the DFS, `FACTORIAL_COMPLETE` otherwise (the DFS finished its pruned subtree exploration,
     * whether or not it produced a template).
     *
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs Indexed pair pool.
     * @param array<int, int> $startPerm Pair indices, the lex starting point for this seed.
     * @param array{permutationsIterated:int,templatesGenerated:int} $processes Mutated by reference.
     * @param array{meetingsVariation:?float,matches:?array,playersMet:array,permutationIndex:?int,templateIndex:?int,minMet:?int} $bestTemplate Mutated by reference.
     * @param array<int, int> $partnersCount Per-player partner count (constant across the phase).
     * @param int $meetingsVariationLimit Active gap tolerance for {@see playersMetTooMuch()}. Passed in
     *                             explicitly so the S6 auto-relax loop can re-invoke the seed
     *                             runner with a bumped value without mutating generator state.
     */
    private function runSeedDfs(
        array $pairs,
        array $startPerm,
        int $perSeedBudgetNs,
        int $currentSeed,
        int $totalSeeds,
        ProgressReporter $reporter,
        array &$processes,
        array &$bestTemplate,
        array $partnersCount,
        int $partnersCountVariation,
        int $meetingsVariationLimit,
        int $playersCount
    ): string {
        $deadlineNs = $this->monotonicNow() + $perSeedBudgetNs;

        if ($this->monotonicNow() >= $deadlineNs) {
            return self::STOP_REASON_DEADLINE;
        }

        $processes['permutationsIterated']++;

        $bestMatchesCount = $bestTemplate['matches'] !== null ? count($bestTemplate['matches']) : null;
        $reporter->pairing(
            $processes['permutationsIterated'],
            $processes['templatesGenerated'],
            $bestTemplate['meetingsVariation'],
            $this->effectiveOuterBudgetNs,
            $this->monotonicNow(),
            false,
            $currentSeed,
            $totalSeeds,
            $bestTemplate['permutationIndex'],
            $bestTemplate['templateIndex'],
            $bestMatchesCount,
            $partnersCount,
            $bestTemplate['playersMet'],
            $partnersCountVariation,
            null,
            $meetingsVariationLimit
        );

        $orderedPairs = [];
        foreach ($startPerm as $i) {
            // Snapshot each pair as `used = false` regardless of the source slot's state;
            // pair entries are mutated in-place during the DFS and we need a fresh template
            // for each seed.
            $orderedPairs[] = [
                'players' => $pairs[$i]['players'],
                'used' => false,
            ];
        }

        $result = $this->buildTemplateByBacktracking(
            $orderedPairs,
            $deadlineNs,
            $meetingsVariationLimit,
            $this->dfsBranchCap,
            $playersCount,
            $bestTemplate['minMet']
        );

        if ($result !== null) {
            $processes['templatesGenerated']++;

            $meetingsVariation = $this->calculatePlayersMetMeetingsVariation($result['playersMet']);
            $candidateMinMet = $this->calculateMinMet($result['playersMet'], $playersCount);

            // Promote on strict `meetingsVariation` improvement; ties resolved by seed order
            // (lower seed wins) — we only enter this branch on the first arrival for a given
            // variation, since the comparison is strict `>` and the seeds iterate in ascending
            // index.
            if ($bestTemplate['meetingsVariation'] === null || $bestTemplate['meetingsVariation'] > $meetingsVariation) {
                $bestTemplate['meetingsVariation'] = $meetingsVariation;
                $bestTemplate['matches'] = $result['matches'];
                $bestTemplate['playersMet'] = $result['playersMet'];
                $bestTemplate['permutationIndex'] = $processes['permutationsIterated'];
                $bestTemplate['templateIndex'] = $processes['templatesGenerated'];
                $bestTemplate['minMet'] = $candidateMinMet;
            }
        }

        if ($this->monotonicNow() >= $deadlineNs) {
            return self::STOP_REASON_DEADLINE;
        }
        return self::STOP_REASON_FACTORIAL_COMPLETE;
    }

    /**
     * Builds one complete template via depth-first search with chronological backtracking on
     * the given pair list. Pair-1 is always the lowest unused index in the ordered list
     * (deterministic); pair-2 candidates are tried in ascending index order (deterministic).
     *
     * The DFS enforces two prune signals:
     * - HARD CONSTRAINT: {@see playersMetTooMuch()} with the current `$meetingsVariationLimit`.
     * - B&B PRUNE on Min Met: if any player's current distinct-opponent count plus the count of
     *   unused pairs containing that player is `< $bestMinMetSoFar`, the branch cannot beat
     *   the current global-best template's Min Met — prune.
     *
     * Returns `null` when the recursion exhausts every choice without producing a complete
     * template, when the wall deadline elapses, or when the branch cap is hit.
     *
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $orderedPairs
     * @return array{matches: array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>, playersMet: array<int, array<int, int>>}|null
     */
    private function buildTemplateByBacktracking(
        array $orderedPairs,
        int $deadlineNs,
        int $meetingsVariationLimit,
        int $branchCap,
        int $playersCount,
        ?int $bestMinMetSoFar
    ): ?array {
        $pairCount = count($orderedPairs);
        $used = array_fill(0, $pairCount, false);
        $playersMet = [];
        $matches = [];
        $branchesRemaining = $branchCap;

        $success = $this->dfsExpand(
            $orderedPairs,
            $playersCount,
            $used,
            $playersMet,
            $matches,
            $deadlineNs,
            $meetingsVariationLimit,
            $branchesRemaining,
            $bestMinMetSoFar
        );

        if (!$success) {
            return null;
        }

        return [
            'matches' => $matches,
            'playersMet' => $playersMet,
        ];
    }

    /**
     * Recursive DFS body for {@see buildTemplateByBacktracking()}. At each entry: checks the
     * wall deadline, decrements the branch counter, applies the Min Met B&B prune, then picks
     * the lowest unused pair as pair-1 and iterates pair-2 candidates in ascending index
     * order. Returns `true` once a complete template is assembled (all pairs used); `false`
     * when the (pruned) subtree below the current node is exhausted, when the deadline fires,
     * or when the branch cap is hit.
     *
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     * @param array<int, bool> $used
     * @param array<int, array<int, int>> $playersMet
     * @param array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}> $matches
     */
    private function dfsExpand(
        array $pairs,
        int $playersCount,
        array &$used,
        array &$playersMet,
        array &$matches,
        int $deadlineNs,
        int $meetingsVariationLimit,
        int &$branchesRemaining,
        ?int $bestMinMetSoFar
    ): bool {
        if ($branchesRemaining <= 0) {
            return false;
        }
        if ($this->monotonicNow() >= $deadlineNs) {
            return false;
        }
        $branchesRemaining--;

        if ($bestMinMetSoFar !== null && $bestMinMetSoFar > 0) {
            // Upper bound on each player's final distinct-opponent count: every still-unused
            // pair containing player `p` will, once matched, give `p` up to 3 new distinct
            // opponents (1 partner + 2 cross-pair opponents). Clamping by `playersCount - 1`
            // matches the absolute ceiling (a player cannot meet more people than exist).
            //
            // If any player's upper bound is `< bestMinMetSoFar`, the branch cannot beat the
            // current global-best Min Met and we prune.
            $remainingForP = array_fill(0, $playersCount, 0);
            $pairCount = count($pairs);
            for ($i = 0; $i < $pairCount; $i++) {
                if ($used[$i]) {
                    continue;
                }
                $remainingForP[$pairs[$i]['players'][0]]++;
                $remainingForP[$pairs[$i]['players'][1]]++;
            }
            $maxDistinct = $playersCount - 1;
            for ($p = 0; $p < $playersCount; $p++) {
                $current = isset($playersMet[$p]) ? count($playersMet[$p]) : 0;
                $upperBound = $current + 3 * $remainingForP[$p];
                if ($upperBound > $maxDistinct) {
                    $upperBound = $maxDistinct;
                }
                if ($upperBound < $bestMinMetSoFar) {
                    return false;
                }
            }
        }

        $pair1Idx = -1;
        $pairCount = count($pairs);
        for ($i = 0; $i < $pairCount; $i++) {
            if (!$used[$i]) {
                $pair1Idx = $i;
                break;
            }
        }

        if ($pair1Idx === -1) {
            return true;
        }

        $pair1Players = $pairs[$pair1Idx]['players'];
        $used[$pair1Idx] = true;

        for ($j = $pair1Idx + 1; $j < $pairCount; $j++) {
            if ($used[$j]) {
                continue;
            }
            $pair2Players = $pairs[$j]['players'];
            if (array_intersect($pair1Players, $pair2Players)) {
                continue;
            }
            if ($this->playersMetTooMuch($pair1Players, $pair2Players, $playersMet, $meetingsVariationLimit)) {
                continue;
            }

            $playersMetSnapshot = $playersMet;
            $playersMet = $this->addPlayersMet($playersMet, [$pair1Players, $pair2Players]);
            $matches[] = [$pair1Players, $pair2Players];
            $used[$j] = true;

            $success = $this->dfsExpand(
                $pairs,
                $playersCount,
                $used,
                $playersMet,
                $matches,
                $deadlineNs,
                $meetingsVariationLimit,
                $branchesRemaining,
                $bestMinMetSoFar
            );

            if ($success) {
                return true;
            }

            $used[$j] = false;
            array_pop($matches);
            $playersMet = $playersMetSnapshot;

            if ($branchesRemaining <= 0) {
                $used[$pair1Idx] = false;
                return false;
            }
            if ($this->monotonicNow() >= $deadlineNs) {
                $used[$pair1Idx] = false;
                return false;
            }
        }

        $used[$pair1Idx] = false;
        return false;
    }

    /**
     * Smallest count of distinct opponents across `[0..playersCount-1]`. Players who never met
     * anyone (absent from `$playersMet`) contribute 0 — the schedule's Min Met cannot exceed
     * what an uninvited player has met.
     */
    private function calculateMinMet(array $playersMet, int $playersCount): int
    {
        $min = PHP_INT_MAX;
        for ($p = 0; $p < $playersCount; $p++) {
            $distinct = isset($playersMet[$p]) ? count($playersMet[$p]) : 0;
            if ($distinct < $min) {
                $min = $distinct;
            }
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /**
     * Largest player index referenced across the pair pool, plus one. Pairs always cover the
     * full {@code [0..playersCount-1]} range under our pair-generation strategy, so the result
     * matches the `playersCount` supplied by `generateMixed()`. Falls back to `0` for an empty
     * pair list — defensive but never hit on production inputs.
     *
     * @param array<int, array{players: array{0:int,1:int}, used: bool}> $pairs
     */
    private function inferPlayersCountFromPairs(array $pairs): int
    {
        $max = -1;
        foreach ($pairs as $pair) {
            if ($pair['players'][0] > $max) {
                $max = $pair['players'][0];
            }
            if ($pair['players'][1] > $max) {
                $max = $pair['players'][1];
            }
        }

        return $max + 1;
    }

    /**
     * Decodes the lex permutation at index `floor(seedIndex * n! / totalSeeds)` over `{0..n-1}`
     * via the factorial number system (Lehmer code). Returns the result as a 0-indexed integer
     * array, ready to seed {@see pcNextPermutation()}.
     *
     * Uses pure mixed-radix arithmetic: the running remainder is bounded by `totalSeeds - 1`, so
     * the algorithm never builds the giant lex index `seedIndex * n! / totalSeeds` and stays
     * overflow-free even for n in the dozens.
     *
     * Invariants:
     * - `seedIndex = 0` returns the identity permutation `[0, 1, ..., n-1]`.
     * - For `seedIndex` in `[0, totalSeeds)` the K returned permutations are pairwise distinct as
     *   long as `totalSeeds <= n!` (always true under our usage, since multi-seed only kicks in
     *   when `n!` already exceeds what we can plausibly walk).
     *
     * @return array<int, int>
     */
    private function lehmerSeedPermutation(int $seedIndex, int $totalSeeds, int $n): array
    {
        $remainder = $seedIndex;
        $pool = range(0, $n - 1);
        $perm = [];

        for ($p = 0; $p < $n; $p++) {
            $weight = $n - $p;
            $product = $remainder * $weight;
            $digit = intdiv($product, $totalSeeds);
            $remainder = $product - ($digit * $totalSeeds);

            $perm[] = $pool[$digit];
            array_splice($pool, $digit, 1);
        }

        return $perm;
    }

    private function generateFixed(
        int $playersCount,
        int $opponentsPerPlayer,
        int $repeatOpponents,
        ProgressReporter $reporter
    ): TemplateMatches {
        $mockPlayers = range(0, $playersCount - 1);
        list($pairs, $partnersCount) = $this->generateFixedPairs($mockPlayers);
        $partnersCountVariation = (empty($partnersCount) ? 0 : max($partnersCount) - min($partnersCount));

        $pairingStartNs = $this->monotonicNow();
        $reporter->setPhaseStart($pairingStartNs);

        $combinations = [];
        $matchesPerPair = array_fill_keys(range(0, count($pairs) - 1), 0);
        $matchesList = [];
        $playersMet = [];

        foreach ($pairs as $i => $pair1) {
            foreach (array_reverse($pairs, true) as $j => $pair2) {
                if (
                    $i != $j && !in_array("$i.$j", $combinations, true)
                    && $matchesPerPair[$i] < $opponentsPerPlayer
                    && $matchesPerPair[$j] < $opponentsPerPlayer
                ) {
                    $matchesList[] = [
                        $pair1['players'],
                        $pair2['players'],
                    ];

                    $matchesPerPair[$i]++;
                    $matchesPerPair[$j]++;

                    $combinations[] = "$i.$j";

                    $playersMet = $this->addPlayersMet($playersMet, [$pair1['players'], $pair2['players']]);
                }
            }
        }

        $meetingsVariation = $this->calculatePlayersMetMeetingsVariation($playersMet);
        $pairingEndNs = $this->monotonicNow();
        $pairingTime = $this->nsToSeconds($pairingEndNs - $pairingStartNs);

        // Single-pass build is microseconds; one isFinal event keeps the renderer contract
        // identical to the mixed path (one pairing-final + one ordering-final per generate() call).
        $reporter->pairing(
            1,
            1,
            $meetingsVariation,
            0,
            $pairingEndNs,
            true,
            1,
            1,
            1,
            1,
            count($matchesList),
            $partnersCount,
            $playersMet,
            $partnersCountVariation,
            self::STOP_REASON_FACTORIAL_COMPLETE
        );

        $sortingStartNs = $this->monotonicNow();
        $reporter->setPhaseStart($sortingStartNs);

        $sortResult = $this->sortMatches($matchesList, $mockPlayers, $reporter);
        if ($sortResult['ordered'] === null) {
            $matchesList = null;
        } else {
            $matchesList = $this->adjustServingOrderByCourt($sortResult['ordered'], $playersCount);
            $matchesList = $this->repeatMatchesByCourt($matchesList, $repeatOpponents);
        }

        $sortingTime = $this->nsToSeconds($this->monotonicNow() - $sortingStartNs);

        return new TemplateMatches(
            $playersCount,
            $opponentsPerPlayer,
            $repeatOpponents,
            $this->activeCourts,
            true,
            $matchesList,
            $meetingsVariation,
            1,
            1,
            1,
            1,
            $partnersCount,
            $playersMet,
            $partnersCountVariation,
            $matchesList !== null ? array_sum(array_map('count', $matchesList)) : null,
            self::STOP_REASON_FACTORIAL_COMPLETE,
            $pairingTime,
            $sortResult['stopReason'],
            $sortResult['min'],
            $sortResult['avg'],
            $sortResult['permutationsIterated'],
            $sortResult['permutationIndex'],
            $sortResult['minBreak'],
            $sortResult['maxBreak'],
            $sortingTime,
            null,
            null,
            $sortResult['courtSwitches'] ?? null,
            $sortResult['courtBalance'] ?? null,
            $sortResult['nodesExplored'] ?? null,
            $sortResult['seedIndex'] ?? null,
            $sortResult['seedsTotal'] ?? null
        );
    }

    /**
     * @return array{0: array<int, array{players: array{0: int, 1: int}, used: bool}>, 1: array<int, int>}
     */
    private function generateFixedPairs(array $mockPlayers): array
    {
        $playersCount = count($mockPlayers);
        $partnersCount = [];
        $pairs = [];

        foreach ($mockPlayers as $player) {
            $partnersCount[$player] = 1;
        }

        for ($i = 0; $i < $playersCount; $i++) {
            if ($i % 2 === 1) {
                $pairs[] = [
                    'players' => [$mockPlayers[$i - 1], $mockPlayers[$i]],
                    'used' => false,
                ];
            }
        }

        return [$pairs, $partnersCount];
    }

    /**
     * @return array{0: array<int, array{players: array{0: int, 1: int}, used: bool}>, 1: array<int, int>}
     */
    private function generateMixedPairs(array $mockPlayers, int $opponentsPerPlayer): array
    {
        $countTeams = [];
        $partnersCount = [];
        $pairs = [];

        foreach ($mockPlayers as $player) {
            $partnersCount[$player] = 0;
        }

        foreach ($mockPlayers as $p1) {
            foreach (array_reverse($mockPlayers) as $p2) {
                if ($partnersCount[$p1] < $opponentsPerPlayer && $partnersCount[$p2] < $opponentsPerPlayer) {
                    if ($p1 !== $p2 && !isset($countTeams["$p1 + $p2"]) && !isset($countTeams["$p2 + $p1"])) {
                        $countTeams["$p1 + $p2"] = count($pairs);

                        $partnersCount[$p1]++;
                        $partnersCount[$p2]++;

                        $pairs[] = [
                            'players' => [$p1, $p2],
                            'used' => false,
                        ];
                    }
                }
            }
        }

        return [$pairs, $partnersCount];
    }

    private function nsToSeconds(int $ns): float
    {
        return $ns / 1_000_000_000;
    }

    private function calculatePlayersMetMeetingsVariation(array $playersMet): float
    {
        if (empty($playersMet)) {
            return 0.0;
        }

        $variations = array_map(static function (array $met) {
            return max($met) - min($met);
        }, $playersMet);

        return (float) array_sum($variations) / count($variations);
    }

    /**
     * Knuth's lexicographic next-permutation. Returns the next array, or `false` after the last.
     *
     * @param array<int, int> $p
     * @return array<int, int>|false
     */
    private function pcNextPermutation(array $p, int $size)
    {
        for ($i = $size - 1; $i >= 0 && $p[$i] >= $p[$i + 1]; --$i) {
        }

        if ($i === -1) {
            return false;
        }

        for ($j = $size; $p[$j] <= $p[$i]; --$j) {
        }

        $tmp = $p[$i];
        $p[$i] = $p[$j];
        $p[$j] = $tmp;

        for (++$i, $j = $size; $i < $j; ++$i, --$j) {
            $tmp = $p[$i];
            $p[$i] = $p[$j];
            $p[$j] = $tmp;
        }

        return $p;
    }

    private function addPlayersMet(array $playersMet, array $match): array
    {
        $matchPlayers = [
            $match[0][0],
            $match[0][1],
            $match[1][0],
            $match[1][1],
        ];

        foreach ($matchPlayers as $p1) {
            foreach ($matchPlayers as $p2) {
                if ($p1 !== $p2) {
                    if (!isset($playersMet[$p1])) {
                        $playersMet[$p1] = [];
                    }
                    if (!isset($playersMet[$p1][$p2])) {
                        $playersMet[$p1][$p2] = 0;
                    }
                    $playersMet[$p1][$p2]++;
                }
            }
        }

        return $playersMet;
    }

    /**
     * True when adding this match would push any of the four players over the gap tolerance
     * between their most-met and least-met partner.
     *
     * Reads strictly above `$meetingsVariationLimit`: with the natural reading "gap of 1 is allowed",
     * `$meetingsVariationLimit = 1` rejects gaps of 2 or more, `$meetingsVariationLimit = 0` enforces a
     * strictly-balanced build (every per-player met-count tied to within zero), and any positive
     * value relaxes the constraint accordingly.
     *
     * {@see Func::keyFromBiggest()} is declared to return `?string`, so the most-met partner key
     * arrives as a string even though the underlying `$playersMet[$p]` is int-keyed. We cast it
     * back to int before the strict `in_array()` check, otherwise the constraint silently never
     * fires (string "1" !== int 1) and the whole gap-tolerance logic degenerates to a no-op.
     */
    private function playersMetTooMuch(array $pair1, array $pair2, array $playersMet, int $meetingsVariationLimit): bool
    {
        $matchPlayers = [
            $pair1[0],
            $pair1[1],
            $pair2[0],
            $pair2[1],
        ];

        foreach ($matchPlayers as $p) {
            if (isset($playersMet[$p])) {
                $leastMet = min($playersMet[$p]);
                $mostMetPlayer = (int) Func::keyFromBiggest($playersMet[$p]);

                if ($playersMet[$p][$mostMetPlayer] - $leastMet > $meetingsVariationLimit && in_array($mostMetPlayer, $matchPlayers, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function adjustServingOrder(array $matches, int $playerNumber): array
    {
        $serveCounts = array_fill(0, $playerNumber, 0);

        foreach ($matches as &$match) {
            $team1 = $match[0];
            $team2 = $match[1];

            $team1Serve = $serveCounts[$team1[0]] + $serveCounts[$team1[1]];
            $team2Serve = $serveCounts[$team2[0]] + $serveCounts[$team2[1]];

            if ($team2Serve < $team1Serve) {
                $match = [$team2, $team1];
                $team1 = $match[0];
            }

            $serveCounts[$team1[0]]++;
            $serveCounts[$team1[1]]++;
        }
        unset($match);

        return $matches;
    }

    private function monotonicNow(): int
    {
        if (is_callable($this->clock)) {
            return (int) ($this->clock)();
        }

        return (int) hrtime(true);
    }
}
