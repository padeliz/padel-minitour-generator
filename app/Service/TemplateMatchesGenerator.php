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
        8 => [2, 4, 6, 7],
        9 => [8],
        10 => [8],
        11 => [8],
        12 => [2, 3, 6, 7, 8, 9],
        13 => [4, 8],
        14 => [4, 8],
        15 => [4, 9],
        16 => [2, 3, 4, 5, 8, 11, 12],
    ];

    /** 8 minutes, in nanoseconds. */
    public const DEFAULT_OUTER_WALL_BUDGET_NS = 480_000_000_000;

    /** 8 minutes, in nanoseconds. */
    public const DEFAULT_SORT_WALL_BUDGET_NS = 480_000_000_000;

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
     * Per-combo wall-clock budgets, in nanoseconds, as `[outerWallBudgetNs, sortWallBudgetNs]`.
     * Keys are stringified `players-partners` because PHP array keys cannot be tuples. Combos
     * absent from this map fall back to the constructor-injected globals via {@see budgetFor()}.
     *
     * The current map gives the worst-10 combos from the bad-templates report 30 minutes per
     * phase (3.75x the historic 8-minute budget) so R1's balanced-meeting constraint has room to
     * find quality templates; the four-player combos get 30 seconds per phase because they
     * exhaust in milliseconds and any larger budget just wastes wall time in CI.
     */
    public const PER_COMBO_BUDGETS_NS = [
        '16-12' => [1_800_000_000_000, 1_800_000_000_000],
        '16-11' => [1_800_000_000_000, 1_800_000_000_000],
        '16-8'  => [1_800_000_000_000, 1_800_000_000_000],
        '12-9'  => [1_800_000_000_000, 1_800_000_000_000],
        '12-8'  => [1_800_000_000_000, 1_800_000_000_000],
        '15-9'  => [1_800_000_000_000, 1_800_000_000_000],
        '14-8'  => [1_800_000_000_000, 1_800_000_000_000],
        '13-8'  => [1_800_000_000_000, 1_800_000_000_000],
        '11-8'  => [1_800_000_000_000, 1_800_000_000_000],
        '16-5'  => [1_800_000_000_000, 1_800_000_000_000],
        '12-6'  => [1_800_000_000_000, 1_800_000_000_000],
        '10-8'  => [1_800_000_000_000, 1_800_000_000_000],
        '4-1'   => [30_000_000_000, 30_000_000_000],
        '4-2'   => [30_000_000_000, 30_000_000_000],
        '4-3'   => [30_000_000_000, 30_000_000_000],
    ];

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
     * satisfies it. The sort returns the input order verbatim (best-effort, never crash).
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

    /**
     * Active per-combo budget map. Defaults to {@see PER_COMBO_BUDGETS_NS} so production usage
     * picks up the hardcoded overrides; tests call {@see setPerComboBudgetsNs()} with an empty
     * array to fall back to the constructor-injected globals on every combo.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $perComboBudgetsNs;

    /**
     * Wall-clock budgets resolved per `generate()` call by {@see budgetFor()}: either the
     * per-combo override from {@see $perComboBudgetsNs} or the constructor-injected fallback.
     * Every internal method reads through these so the same generator instance can produce
     * different combos sequentially with each using the right budget.
     */
    private int $effectiveOuterBudgetNs;
    private int $effectiveSortBudgetNs;

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
     * @param int $dfsBranchCap Max recursion entries per pairing-phase DFS run (per seed); the
     *                          DFS aborts the seed and returns null once the cap is hit, leaving
     *                          the other seeds free to explore their own subtrees.
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
        int $dfsBranchCap = self::DEFAULT_DFS_BRANCH_CAP
    ) {
        $this->clock = $clock;
        $this->outerWallBudgetNs = $outerWallBudgetNs;
        $this->sortWallBudgetNs = $sortWallBudgetNs;
        $this->multiSeedCountPairing = max(1, $multiSeedCountPairing);
        $this->multiSeedThresholdPairs = max(1, $multiSeedThresholdPairs);
        $this->meetingsVariationLimit = max(0, $meetingsVariationLimit);
        $this->maxBreakThreshold = $maxBreakThreshold;
        // The relaxation ceiling cannot drop below the starting dl, otherwise the loop has no
        // headroom and behaves as if S6 were disabled.
        $this->meetingsVariationLimitMax = max($this->meetingsVariationLimit, $meetingsVariationLimitMax);
        $this->dfsBranchCap = max(1, $dfsBranchCap);
        $this->perComboBudgetsNs = self::PER_COMBO_BUDGETS_NS;
        $this->effectiveOuterBudgetNs = $outerWallBudgetNs;
        $this->effectiveSortBudgetNs = $sortWallBudgetNs;
        $this->distributionScorer = new PlayerDistributionScorer();
    }

    /**
     * Overrides the active per-combo budget map. Pass an empty array to disable the per-combo
     * overrides entirely (every combo falls back to the constructor-injected globals). Pass a
     * non-empty array to swap in a custom map - useful for tests that want to exercise the
     * override path without the production 30-minute values.
     *
     * The map is keyed by `players-partners` strings and yields `[outerWallBudgetNs, sortWallBudgetNs]`
     * tuples, identical to the format of {@see PER_COMBO_BUDGETS_NS}.
     *
     * @param array<string, array{0: int, 1: int}> $budgets
     */
    public function setPerComboBudgetsNs(array $budgets): void
    {
        $this->perComboBudgetsNs = $budgets;
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

    public function generate(int $players, int $partners, int $repeat, bool $fixedTeams = false): TemplateMatches
    {
        [$this->effectiveOuterBudgetNs, $this->effectiveSortBudgetNs] = $this->budgetFor($players, $partners);

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
     * Returns `[outerWallBudgetNs, sortWallBudgetNs]` for the given combo, looking up the active
     * per-combo budget map first and falling back to the constructor-injected globals when no
     * override is registered. Tests can wipe the active map via {@see setPerComboBudgetsNs([])}
     * to force every combo down the fallback path.
     *
     * @return array{0: int, 1: int}
     */
    private function budgetFor(int $players, int $partners): array
    {
        $key = $players . '-' . $partners;
        if (isset($this->perComboBudgetsNs[$key])) {
            return $this->perComboBudgetsNs[$key];
        }

        return [$this->outerWallBudgetNs, $this->sortWallBudgetNs];
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
            $matches = $this->adjustServingOrder($sortResult['ordered'], $playersCount);
            $matches = $this->repeatMatches($matches, $repeatOpponents);

            $playersMet = $bestTemplate['playersMet'];
            $partnersCountFinal = $partnersCount;
        }

        $sortingTime = $this->nsToSeconds($this->monotonicNow() - $sortingStartNs);

        return new TemplateMatches(
            $playersCount,
            $opponentsPerPlayer,
            $repeatOpponents,
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
            $relaxAttempts
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
        $matchesList = $this->adjustServingOrder($sortResult['ordered'], $playersCount);
        $matchesList = $this->repeatMatches($matchesList, $repeatOpponents);

        $sortingTime = $this->nsToSeconds($this->monotonicNow() - $sortingStartNs);

        return new TemplateMatches(
            $playersCount,
            $opponentsPerPlayer,
            $repeatOpponents,
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
            count($matchesList),
            self::STOP_REASON_FACTORIAL_COMPLETE,
            $pairingTime,
            $sortResult['stopReason'],
            $sortResult['min'],
            $sortResult['avg'],
            $sortResult['permutationsIterated'],
            $sortResult['permutationIndex'],
            $sortResult['minBreak'],
            $sortResult['maxBreak'],
            $sortingTime
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

    /**
     * Permutes match order to spread each player's matches across the schedule.
     *
     * S7 replaces the lex walk with a deterministic backtracking DFS over match orderings. At
     * depth `k` the DFS picks which unused match goes into position `k`, iterating candidates in
     * ascending match index (deterministic tie-break). A per-player consecutive-break-run
     * counter is maintained incrementally as the schedule grows; the DFS hard-prunes any branch
     * that would push some player's counter strictly above `ceil(playersCount / 4)` (or the
     * explicit `$maxBreakThreshold` injected via constructor).
     *
     * At a leaf (depth == m) the DFS scores the complete ordering with the existing piecewise
     * asymmetric distribution scorer and updates the global best on a 3-tier lexicographic
     * compare: `Min Dist` first, `Avg Dist` second, break-balance distance (the absolute gap
     * between `(minBreak + maxBreak) / 2` and the per-player ideal `m / playerMatches`) as the
     * final tie-breaker. Tie at all three tiers: the earlier leaf wins (deterministic).
     *
     * Stops when one of:
     * - the wall deadline elapses (returns the best-so-far),
     * - the DFS exhausts the (pruned) search tree (`FACTORIAL_COMPLETE`),
     * - no leaf is reachable under the prune threshold (`PRUNE_INFEASIBLE`; the input order is
     *   returned verbatim as a best-effort fallback so the caller never gets a crash).
     *
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int>                         $mockPlayers
     * @return array{
     *     ordered: array<int, array<int, array<int, int>>>,
     *     stopReason: string,
     *     min: float|null,
     *     avg: float|null,
     *     permutationsIterated: int,
     *     permutationIndex: int|null,
     *     minBreak: int|null,
     *     maxBreak: int|null
     * }
     */
    private function sortMatches(array $matches, array $mockPlayers, ProgressReporter $reporter): array
    {
        $matches = array_values($matches);
        $m = count($matches);

        if ($m <= 1) {
            // Trivial input: nothing to permute. Emit one ordering-final event so the renderer
            // contract holds (every generate() produces exactly one ordering-final event).
            $reporter->ordering(
                0,
                null,
                null,
                $this->effectiveSortBudgetNs,
                $this->monotonicNow(),
                true,
                self::STOP_REASON_TRIVIAL
            );

            return [
                'ordered' => $matches,
                'stopReason' => self::STOP_REASON_TRIVIAL,
                'min' => null,
                'avg' => null,
                'permutationsIterated' => 0,
                'permutationIndex' => null,
                'minBreak' => null,
                'maxBreak' => null,
            ];
        }

        $playersCount = count($mockPlayers);
        $maxBreakThreshold = $this->maxBreakThreshold >= 0
            ? $this->maxBreakThreshold
            : (int) ceil($playersCount / 4);

        $deadlineNs = $this->monotonicNow() + $this->effectiveSortBudgetNs;

        $schedule = [];
        $used = array_fill(0, $m, false);
        $currentRuns = [];
        $longestRuns = [];
        // Per-player state for the asymmetric break metrics:
        // - `$playedAtLeastOnce[p]` distinguishes a lead run (don't record as inner) from a
        //   closing inner run (record).
        // - `$shortestInner[p]` accumulates the shortest closed inner break run -- including
        //   length-`0` runs from back-to-back appearances (sit-out semantics: two consecutive
        //   matches give a run of `0`, pinning $shortestInner to `0` for that player). Stays
        //   `null` until the player has at least one inner run closed by a subsequent
        //   appearance; the leaf maps `null` to `0` per the asymmetric contract.
        $playedAtLeastOnce = [];
        $shortestInner = [];
        foreach ($mockPlayers as $playerIndex) {
            $currentRuns[$playerIndex] = 0;
            $longestRuns[$playerIndex] = 0;
            $playedAtLeastOnce[$playerIndex] = false;
            $shortestInner[$playerIndex] = null;
        }

        $bestState = [
            'ordered' => null,
            'min' => null,
            'avg' => null,
            'permutationIndex' => null,
            'minBreak' => null,
            'maxBreak' => null,
            // Distance between the candidate's break-balance `(minBreak + maxBreak) / 2` and the
            // per-player ideal `m / playerMatches`. Initialised to +INF so the first complete leaf
            // wins unconditionally (matches the pairing-phase "first leaf seeds the best" pattern).
            'breakDistance' => INF,
        ];

        $iterations = 0;
        $exit = ['stopReason' => self::STOP_REASON_FACTORIAL_COMPLETE];

        $this->sortDfsExpand(
            $matches,
            $mockPlayers,
            $playersCount,
            $schedule,
            $currentRuns,
            $longestRuns,
            $playedAtLeastOnce,
            $shortestInner,
            $used,
            $maxBreakThreshold,
            $deadlineNs,
            $bestState,
            $iterations,
            $reporter,
            $exit
        );

        if ($bestState['ordered'] === null) {
            // The DFS exited without finding any complete ordering. Two paths into this branch:
            // (a) `FACTORIAL_COMPLETE` exit + no leaf visited → the prune killed every branch
            //     before reaching depth m. The schedule is genuinely infeasible under the chosen
            //     `$maxBreakThreshold`.
            // (b) `DEADLINE` exit + no leaf visited → the deadline truncated the search before
            //     any leaf could be reached. We surface the deadline reason so the operator can
            //     distinguish "give me more time" from "loosen the threshold".
            $stopReason = $exit['stopReason'] === self::STOP_REASON_DEADLINE
                ? self::STOP_REASON_DEADLINE
                : self::STOP_REASON_PRUNE_INFEASIBLE;

            $reporter->ordering(
                $iterations,
                null,
                null,
                $this->effectiveSortBudgetNs,
                $this->monotonicNow(),
                true,
                $stopReason
            );

            return [
                'ordered' => $matches,
                'stopReason' => $stopReason,
                'min' => null,
                'avg' => null,
                'permutationsIterated' => $iterations,
                'permutationIndex' => null,
                'minBreak' => null,
                'maxBreak' => null,
            ];
        }

        $reporter->ordering(
            $iterations,
            $bestState['min'],
            $bestState['avg'],
            $this->effectiveSortBudgetNs,
            $this->monotonicNow(),
            true,
            $exit['stopReason'],
            $bestState['permutationIndex'],
            $bestState['minBreak'],
            $bestState['maxBreak']
        );

        return [
            'ordered' => $bestState['ordered'],
            'stopReason' => $exit['stopReason'],
            'min' => $bestState['min'],
            'avg' => $bestState['avg'],
            'permutationsIterated' => $iterations,
            'permutationIndex' => $bestState['permutationIndex'],
            'minBreak' => $bestState['minBreak'],
            'maxBreak' => $bestState['maxBreak'],
        ];
    }

    /**
     * Backtracking DFS over match orderings. At every depth picks the next match (lowest unused
     * index, deterministic) and recurses; prunes a candidate when placing it would push some
     * player's running consecutive-break counter strictly above `$maxBreakThreshold` (i.e. some
     * player would exceed the `Max Break` ceiling). At a leaf (depth == m) scores the ordering
     * and updates `$bestState` on a 3-tier lex compare: `Min Dist` > `Avg Dist` > break-balance
     * distance (lower is better).
     *
     * Maintains four parallel per-player maps incrementally to make the leaf compute O(playersCount):
     *   - `$currentRuns[p]` -- live consecutive-non-appearance counter (used by the prune).
     *   - `$longestRuns[p]` -- longest break run for p across all positions (lead + inner + trail)
     *     seen so far; the leaf's `maxBreak` is `max($longestRuns)`.
     *   - `$playedAtLeastOnce[p]` -- flips to true on p's first appearance, so the lead run is
     *     NOT recorded as an inner run.
     *   - `$shortestInner[p]` -- shortest closed inner break run for p so far. A subsequent
     *     appearance always closes an inner run, even when the previous appearance was the
     *     immediately preceding match (length `0`, sit-out semantics: two consecutive matches
     *     contribute a `0` and pin $shortestInner to `0`). Stays null when p has not yet had a
     *     second appearance (single-appearance or zero-appearance player); the leaf maps null
     *     to `0` per the asymmetric contract.
     *
     * Mutates `$schedule`, all four per-player maps, `$used`, `$bestState`, `$iterations`,
     * and `$exit` by reference. Returns when the search exhausts the (pruned) tree or the
     * wall deadline fires.
     *
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int>                         $mockPlayers
     * @param array<int, int>                         $schedule
     * @param array<int, int>                         $currentRuns
     * @param array<int, int>                         $longestRuns
     * @param array<int, bool>                        $playedAtLeastOnce
     * @param array<int, int|null>                    $shortestInner
     * @param array<int, bool>                        $used
     * @param array{
     *     ordered: array<int, array<int, array<int, int>>>|null,
     *     min: float|null,
     *     avg: float|null,
     *     permutationIndex: int|null,
     *     minBreak: int|null,
     *     maxBreak: int|null,
     *     breakDistance: float
     * } $bestState
     * @param array{stopReason: string} $exit
     */
    private function sortDfsExpand(
        array $matches,
        array $mockPlayers,
        int $playersCount,
        array &$schedule,
        array &$currentRuns,
        array &$longestRuns,
        array &$playedAtLeastOnce,
        array &$shortestInner,
        array &$used,
        int $maxBreakThreshold,
        int $deadlineNs,
        array &$bestState,
        int &$iterations,
        ProgressReporter $reporter,
        array &$exit
    ): bool {
        $now = $this->monotonicNow();
        if ($now >= $deadlineNs) {
            $exit['stopReason'] = self::STOP_REASON_DEADLINE;
            return true;
        }

        $m = count($matches);

        if (count($schedule) === $m) {
            $iterations++;

            $reporter->ordering(
                $iterations,
                $bestState['min'],
                $bestState['avg'],
                $this->effectiveSortBudgetNs,
                $now,
                false,
                null,
                $bestState['permutationIndex'],
                $bestState['minBreak'],
                $bestState['maxBreak']
            );

            $ordered = [];
            foreach ($schedule as $matchIndex) {
                $ordered[] = $matches[$matchIndex];
            }
            $scores = $this->scoreMatchOrderDistribution($ordered, $mockPlayers);
            $minScore = $scores['min'];
            $avgScore = $scores['avg'];

            // 3-tier lex tie-break:
            //   1. larger `Min Dist` wins,
            //   2. else larger `Avg Dist` wins,
            //   3. else the candidate whose break-balance is closer to the per-player ideal wins.
            //
            // Asymmetric break metrics:
            //   - `minBreak` = cross-player MIN of each player's shortest INNER break run (a
            //     break run bracketed by appearances on both sides). Inner runs include
            //     length-`0` back-to-back appearances (sit-out semantics: two consecutive
            //     matches give a run of `0`), so any player with at least one back-to-back
            //     contributes `0`. A player with no closed inner run at all (plays only once,
            //     or never plays) also contributes `0`. Either way, whenever any player's
            //     contribution is `0` the aggregate is `0` too.
            //   - `maxBreak` = cross-player MAX of each player's longest break run anywhere in
            //     the schedule (lead + inner + trail). Already encoded in `$longestRuns` because
            //     the DFS increments the counter at every absence regardless of position.
            //
            // The break-balance `(minBreak + maxBreak) / 2` compares to the per-player ideal
            // `m / playerMatches` (a float -- no ceil, since the break average itself can carry
            // a `.5`). `playerMatches` is the mean across players, derived from
            // `(m * 4) / playersCount` since every match seats 4 distinct players. When all
            // three tiers tie, the earlier-discovered leaf wins, because we only adopt on
            // strict improvement at every tier (determinism preserved).
            $perPlayerMin = [];
            foreach ($mockPlayers as $playerIndex) {
                $perPlayerMin[] = $shortestInner[$playerIndex] ?? 0;
            }
            $currentMinBreak = min($perPlayerMin);
            $currentMaxBreak = max($longestRuns);
            $breakAvg = ($currentMinBreak + $currentMaxBreak) / 2.0;
            $playerMatches = ($m * 4) / $playersCount;
            $targetBreakAvg = $playerMatches > 0 ? ($m / $playerMatches) : 0.0;
            $candidateBreakDistance = abs($breakAvg - $targetBreakAvg);

            if (
                $bestState['min'] === null
                || $minScore > $bestState['min']
                || ($minScore === $bestState['min'] && $avgScore > $bestState['avg'])
                || (
                    $minScore === $bestState['min']
                    && $avgScore === $bestState['avg']
                    && $candidateBreakDistance < $bestState['breakDistance']
                )
            ) {
                $bestState['min'] = $minScore;
                $bestState['avg'] = $avgScore;
                $bestState['ordered'] = $ordered;
                $bestState['permutationIndex'] = $iterations;
                $bestState['minBreak'] = $currentMinBreak;
                $bestState['maxBreak'] = $currentMaxBreak;
                $bestState['breakDistance'] = $candidateBreakDistance;
            }

            return false;
        }

        for ($j = 0; $j < $m; $j++) {
            if ($used[$j]) {
                continue;
            }

            $match = $matches[$j];
            $matchPlayers = [
                $match[0][0] ?? null,
                $match[0][1] ?? null,
                $match[1][0] ?? null,
                $match[1][1] ?? null,
            ];
            $matchPlayersLookup = array_flip(array_filter($matchPlayers, static fn($p) => $p !== null));

            // Compute the post-placement state in one pass:
            //   - appearing players reset $currentRuns to 0. If this is their first appearance,
            //     flag $deltaPlayed so the apply step can flip $playedAtLeastOnce. Otherwise (a
            //     subsequent appearance) the just-completed $currentRuns run is a CLOSED inner
            //     break, so update $shortestInner if it improves, stashing the previous value
            //     in $deltaShortest for backtrack undo.
            //   - absent players increment $currentRuns by 1 (gated by the prune) and possibly
            //     bump $longestRuns.
            $deltaRun = [];
            $deltaLongest = [];
            $deltaPlayed = [];      // [p => true] for players whose `played` flag flipped this step
            $deltaShortest = [];    // [p => previousShortestInner] for players whose `shortestInner` changed
            $pruned = false;
            foreach ($mockPlayers as $playerIndex) {
                if (isset($matchPlayersLookup[$playerIndex])) {
                    $deltaRun[$playerIndex] = -$currentRuns[$playerIndex];
                    $deltaLongest[$playerIndex] = 0;
                    if (!$playedAtLeastOnce[$playerIndex]) {
                        // First appearance: the accumulated $currentRuns was the lead -- do NOT
                        // record it as an inner run.
                        $deltaPlayed[$playerIndex] = true;
                    } else {
                        // Subsequent appearance closing an inner break run of length
                        // $currentRuns (a value of 0 means back-to-back appearances, matching
                        // the scorer's sit-out semantics -- a 0 is a legitimate inner run that
                        // pins $shortestInner to 0). Stash the previous value (for undo) only
                        // when we actually improve, then we will apply the new value below
                        // alongside the other deltas.
                        $prev = $shortestInner[$playerIndex];
                        $candidate = $currentRuns[$playerIndex];
                        if ($prev === null || $candidate < $prev) {
                            $deltaShortest[$playerIndex] = $prev;
                        }
                    }
                } else {
                    $newRun = $currentRuns[$playerIndex] + 1;
                    if ($newRun > $maxBreakThreshold) {
                        $pruned = true;
                        break;
                    }
                    $deltaRun[$playerIndex] = 1;
                    $deltaLongest[$playerIndex] = $newRun > $longestRuns[$playerIndex]
                        ? $newRun - $longestRuns[$playerIndex]
                        : 0;
                }
            }

            if ($pruned) {
                continue;
            }

            foreach ($deltaRun as $playerIndex => $delta) {
                $currentRuns[$playerIndex] += $delta;
                $longestRuns[$playerIndex] += $deltaLongest[$playerIndex];
            }
            foreach ($deltaPlayed as $playerIndex => $_unused) {
                $playedAtLeastOnce[$playerIndex] = true;
            }
            foreach ($deltaShortest as $playerIndex => $prev) {
                // The candidate inner-run length is the player's $currentRuns BEFORE we zeroed
                // it via $deltaRun. We stored $deltaRun = -previousCurrentRuns above, so the
                // candidate is its negation. We only stash entries in $deltaShortest when we
                // strictly improve, so this writes the new minimum unconditionally.
                $shortestInner[$playerIndex] = -$deltaRun[$playerIndex];
            }
            $used[$j] = true;
            $schedule[] = $j;

            $stop = $this->sortDfsExpand(
                $matches,
                $mockPlayers,
                $playersCount,
                $schedule,
                $currentRuns,
                $longestRuns,
                $playedAtLeastOnce,
                $shortestInner,
                $used,
                $maxBreakThreshold,
                $deadlineNs,
                $bestState,
                $iterations,
                $reporter,
                $exit
            );

            array_pop($schedule);
            $used[$j] = false;
            foreach ($deltaRun as $playerIndex => $delta) {
                $currentRuns[$playerIndex] -= $delta;
                $longestRuns[$playerIndex] -= $deltaLongest[$playerIndex];
            }
            foreach ($deltaPlayed as $playerIndex => $_unused) {
                $playedAtLeastOnce[$playerIndex] = false;
            }
            foreach ($deltaShortest as $playerIndex => $prev) {
                $shortestInner[$playerIndex] = $prev;
            }

            if ($stop) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cross-player aggregate of the per-player distribution score. Delegates to
     * {@see PlayerDistributionScorer::scoreAll()} and discards the per-player breakdown -- the
     * sort DFS only needs `min` (worst-player score) and `avg` (mean across players) for its
     * 3-tier lex compare.
     *
     * @return array{min: float, avg: float}
     */
    private function scoreMatchOrderDistribution(array $orderedMatches, array $mockPlayers): array
    {
        $aggregate = $this->distributionScorer->scoreAll($mockPlayers, $orderedMatches);

        return [
            'min' => $aggregate['min'],
            'avg' => $aggregate['avg'],
        ];
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

    private function repeatMatches(array $matches, int $repeatOpponents): array
    {
        $repeated = [];
        for ($i = 1; $i <= $repeatOpponents; $i++) {
            foreach ($matches as $match) {
                $repeated[] = $match;
            }
        }

        return $repeated;
    }

    private function monotonicNow(): int
    {
        if (is_callable($this->clock)) {
            return (int) ($this->clock)();
        }

        return (int) hrtime(true);
    }
}
