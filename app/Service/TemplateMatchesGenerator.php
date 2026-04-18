<?php

namespace Arshavinel\PadelMiniTour\Service;

use Arshwell\Monolith\Cache;
use Arshwell\Monolith\Func;

class TemplateMatchesGenerator
{
    /**
     * Monotonic nanoseconds clock for `sortMatches()` timing.
     *
     * Tests may assign a callable `fn(): int` returning nanoseconds to simulate time advancing
     * between permutation evaluations without sleeping.
     */
    private static $sortMatchesClock = null;

    /**
     * Wall-time budget (nanoseconds) for `sortMatches()` to spend evaluating permutations.
     *
     * Default is 25 seconds. Tests should override this to keep unit tests fast/deterministic.
     */
    private static int $sortMatchesWallBudgetNs = 25_000_000_000;

    /**
     * When scanning match-order permutations lexicographically, evaluate every Nth permutation.
     *
     * A value of 1 means evaluate every permutation (no skipping). Higher values allow the search to
     * reach farther into the lexicographic sequence within the same wall-clock budget, at the cost
     * of evaluating fewer candidates.
     */
    private static int $sortMatchesLexStride = 1;

    /**
     * Use multi-start local search when match count is at least this value (12–26+ supported).
     */
    private const SORT_MATCHES_MULTISTART_THRESHOLD = 12;

    // player => partners
    const COMBINATIONS = [
        4 => [1, 2, 3],
        5 => [4],
        6 => [4],
        7 => [4],
        8 => [2, 4, 6, 7],
        9 => [8],
        10 => [8],
        // 11 => [],
        12 => [2, 3, 6, 7, 8, 9],
        13 => [4],
        14 => [4, 8],
        15 => [4, 9],
        16 => [2, 3, 4, 5, 8, 11, 12],
    ];

    // data
    private ?array $matches;
    private ?float $meetingsVariation;
    private ?array $partnersCount;
    private ?array $playersMet;
    private ?bool $hasDifferentPartnersNumber;

    // processes
    private ?int $permutationsIterated;
    private ?int $permutationIndex;
    private ?int $templatesGenerated;
    private ?int $templateIndex;

    // time
    private int $estimatedGenerationTime;
    private int $generationTime;

    public function __construct(int $playersCount, int $opponentsPerPlayer, int $repeatOpponents, bool $fixedTeams = false)
    {
        $key = "template-matches/v2.0/players-{$playersCount}-partners-{$opponentsPerPlayer}-repeat-{$repeatOpponents}";

        if ($fixedTeams) {
            $key .= '-fixedteams';
        }

        $divisionData = Cache::fetch($key);

        if (empty($divisionData)) {
            $divisionData = $this->generateTemplateMatches($playersCount, $opponentsPerPlayer, $repeatOpponents, $fixedTeams);

            Cache::store($key, $divisionData);
        }

        // data
        $this->matches = $divisionData['matches'];
        $this->meetingsVariation = $divisionData['meetingsVariation'];
        $this->hasDifferentPartnersNumber = $divisionData['hasDifferentPartnersNumber'];
        $this->partnersCount = $divisionData['partnersCount'];
        $this->playersMet = $divisionData['playersMet'];

        // processes
        $this->permutationsIterated = $divisionData['permutationsIterated'];
        $this->permutationIndex = $divisionData['permutationIndex'];
        $this->templatesGenerated = $divisionData['templatesGenerated'];
        $this->templateIndex = $divisionData['templateIndex'];

        // time
        $this->estimatedGenerationTime = $divisionData['estimatedGenerationTime'];
        $this->generationTime = $divisionData['generationTime'];
    }


    public function generateTemplateMatches(int $playersCount, int $opponentsPerPlayer, int $repeatOpponents, bool $fixedTeams): array
    {
        if ($fixedTeams) {
            return $this->generateTemplateFixedMatches($playersCount, $opponentsPerPlayer, $repeatOpponents);
        } else {
            return $this->generateTemplateMixedMatches($playersCount, $opponentsPerPlayer, $repeatOpponents);
        }
    }

    private function generateTemplateMixedMatches(int $playersCount, int $opponentsPerPlayer, int $repeatOpponents): array
    {
        $startTime = microtime(true);

        $mockPlayers = range(0, $playersCount - 1);

        list($pairs, $partnersCount) = $this->generateMixedPairs($mockPlayers, $opponentsPerPlayer);


        $size = count($pairs) - 1;
        $perm = range(0, $size);
        $permCopy = range(0, $size); // representing the first permutation

        $estimatedGenerationTime = $this->estimateGenerationTime(count($pairs));
        ini_set('max_execution_time', ini_get('max_execution_time') + $estimatedGenerationTime); // for 350k processes

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
        ];

        do {
            $processes['permutationsIterated']++;

            $permutation = [
                'pairs' => [],
                'matches' => [],
                'playersMet' => [],
            ];

            foreach ($perm as $i) {
                $permutation['pairs'][] = $pairs[$i];
            }

            // over the time, we are less restrictive
            $differenceLimit = max(1, floor($processes['permutationsIterated'] / 90000));

            do {
                $matchAdded = false;

                foreach ($permutation['pairs'] as $i => &$pair1) {
                    if ($pair1['used'] === false) {
                        foreach ($permutation['pairs'] as $j => &$pair2) {
                            if (
                                $i != $j && $pair2['used'] === false &&
                                !array_intersect($pair1['players'], $pair2['players'])
                                &&
                                !$this->playersMetTooMuch($pair1['players'], $pair2['players'], $permutation['playersMet'], $differenceLimit)
                            ) {
                                $permutation['matches'][] = [
                                    $pair1['players'],
                                    $pair2['players'],
                                ];

                                $pair1['used'] = true;
                                $pair2['used'] = true;

                                $permutation['playersMet'] = $this->addPlayersMet($permutation['playersMet'], [$pair1['players'], $pair2['players']]);

                                $matchAdded = true;

                                break; // go to next $pair1
                            }

                            unset($pair2);
                        }
                    }

                    unset($pair1);
                }

                $unusedPairs = array_filter($permutation['pairs'], function ($pair) {
                    return $pair['used'] === false;
                });
            } while ($matchAdded);

            if (empty($unusedPairs)) {
                $processes['templatesGenerated']++;

                $playersMetMeetingsVariation = $this->calculatePlayersMetMeetingsVariation($permutation['playersMet']);

                if (null == $bestTemplate['meetingsVariation'] || $bestTemplate['meetingsVariation'] > $playersMetMeetingsVariation) {
                    $bestTemplate['meetingsVariation'] = $playersMetMeetingsVariation;
                    $bestTemplate['matches'] = $permutation['matches'];
                    $bestTemplate['playersMet'] = $permutation['playersMet'];

                    $bestTemplate['permutationIndex'] = $processes['permutationsIterated'];
                    $bestTemplate['templateIndex'] = $processes['templatesGenerated'];
                }
            }
        } while (
            ($processes['permutationsIterated'] + $processes['templatesGenerated']) < 350000 &&
            ($perm = $this->pcNextPermutation($perm, $size)) && $perm !== $permCopy
        );

        if (empty($bestTemplate['matches'])) {
            $bestTemplate['matches'] = null;
            $partnersCount = null;
            $bestTemplate['playersMet'] = null;
            $hasDifferentPartnersNumber = null;
        } else {
            $bestTemplate['matches'] = $this->sortMatches($bestTemplate['matches'], $mockPlayers);
            $bestTemplate['matches'] = $this->adjustServingOrder($bestTemplate['matches'], $playersCount);
            $bestTemplate['matches'] = $this->repeatMatches($bestTemplate['matches'], $repeatOpponents);

            $hasDifferentPartnersNumber = (count(array_count_values($partnersCount)) > 1);
        }

        $endTime = microtime(true);

        return [
            // data
            'matches' => $bestTemplate['matches'],
            'meetingsVariation' => $bestTemplate['meetingsVariation'],
            'partnersCount' => $partnersCount,
            'playersMet' => $bestTemplate['playersMet'],
            'hasDifferentPartnersNumber' => $hasDifferentPartnersNumber,

            // processes
            'permutationsIterated' => $processes['permutationsIterated'],
            'permutationIndex' => $bestTemplate['permutationIndex'],
            'templatesGenerated' => $processes['templatesGenerated'],
            'templateIndex' => $bestTemplate['templateIndex'],

            // time
            'estimatedGenerationTime' => $estimatedGenerationTime,
            'generationTime' => number_format($endTime - $startTime, 2),
        ];
    }

    private function generateTemplateFixedMatches(int $playersCount, int $opponentsPerPlayer, int $repeatOpponents): array
    {
        $startTime = microtime(true);

        $mockPlayers = range(
            0,
            $playersCount - 1
        );

        list($pairs, $partnersCount) = $this->generateFixedPairs($mockPlayers);

        $processes = [
            'permutationsIterated' => 1,
            'templatesGenerated' => 1,
        ];

        $bestTemplate = [
            'meetingsVariation' => null,
            'matches' => [],
            'playersMet' => [],
            'permutationIndex' => 1,
            'templateIndex' => 1,
        ];

        $combinations = [];
        $matchesPerPair = array_fill_keys(range(0, count($pairs) - 1), 0);

        foreach ($pairs as $i => $pair1) {
            foreach (array_reverse($pairs, true) as $j => $pair2) {
                if (
                    $i != $j && !in_array("$i.$j", $combinations)
                    && $matchesPerPair[$i] < $opponentsPerPlayer && $matchesPerPair[$j] < $opponentsPerPlayer
                ) {
                    $bestTemplate['matches'][] = [
                        $pair1['players'],
                        $pair2['players'],
                    ];

                    $matchesPerPair[$i]++;
                    $matchesPerPair[$j]++;

                    $combinations[] = "$i.$j";

                    $bestTemplate['playersMet'] = $this->addPlayersMet($bestTemplate['playersMet'], [$pair1['players'], $pair2['players']]);
                }
            }
        }


        $bestTemplate['meetingsVariation'] = $this->calculatePlayersMetMeetingsVariation($bestTemplate['playersMet']);

        $bestTemplate['matches'] = $this->sortMatches($bestTemplate['matches'], $mockPlayers);
        $bestTemplate['matches'] = $this->adjustServingOrder($bestTemplate['matches'], $playersCount);
        $bestTemplate['matches'] = $this->repeatMatches($bestTemplate['matches'], $repeatOpponents);

        $hasDifferentPartnersNumber = (count(array_count_values($partnersCount)) > 1);

        $endTime = microtime(true);

        return [
            // data
            'matches' => $bestTemplate['matches'],
            'meetingsVariation' => $bestTemplate['meetingsVariation'],
            'partnersCount' => $partnersCount,
            'playersMet' => $bestTemplate['playersMet'],
            'hasDifferentPartnersNumber' => $hasDifferentPartnersNumber,

            // processes
            'permutationsIterated' => $processes['permutationsIterated'],
            'permutationIndex' => $bestTemplate['permutationIndex'],
            'templatesGenerated' => $processes['templatesGenerated'],
            'templateIndex' => $bestTemplate['templateIndex'],

            // time
            'estimatedGenerationTime' => 1,
            'generationTime' => number_format($endTime - $startTime, 2),
        ];
    }

    public function getMatches(): ?array
    {
        return $this->matches;
    }
    public function getMeetingsVariation(): ?float
    {
        return $this->meetingsVariation;
    }
    public function getPartnersCount(): ?array
    {
        return $this->partnersCount;
    }
    public function getPartnersCountBy(string $player): ?int
    {
        return $this->partnersCount[$player] ?? null;
    }
    public function getPlayersMet(): ?array
    {
        return $this->playersMet;
    }
    public function getPlayersMetBy(string $player): ?int
    {
        return $this->playersMet[$player] ?? null;
    }
    public function hasDifferentPartnersNumber(): ?bool
    {
        return $this->hasDifferentPartnersNumber;
    }

    public function getPermutationsIterated(): ?int
    {
        return $this->permutationsIterated;
    }
    public function getPermutationIndex(): ?int
    {
        return $this->permutationIndex;
    }
    public function getTemplatesGenerated(): ?int
    {
        return $this->templatesGenerated;
    }
    public function getTemplateIndex(): ?int
    {
        return $this->templateIndex;
    }

    /**
     * Seconds estimated for generating the template.
     */
    public function getEstimatedGenerationTime(): int
    {
        return $this->estimatedGenerationTime;
    }
    /**
     * Seconds took for generating the template.
     */
    public function getGenerationTime(): int
    {
        return $this->generationTime;
    }



    /**
     * @return [$pairs, $partnersCount]
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
            if ($i % 2 == 1) {
                $pairs[] = [
                    'players' => [$mockPlayers[$i - 1], $mockPlayers[$i]],
                    'used' => false,
                ];
            }
        }

        return [$pairs, $partnersCount];
    }

    /**
     * @return [$pairs, $partnersCount]
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

                    if (
                        $p1 != $p2 && !isset($countTeams["$p1 + $p2"]) && !isset($countTeams["$p2 + $p1"])
                    ) {
                        $countTeams["$p1 + $p2"] = count($pairs);

                        $partnersCount[$p1] = $partnersCount[$p1] + 1;
                        $partnersCount[$p2] = $partnersCount[$p2] + 1;

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

    /**
     * Progressive function to guess approximately estimation time needed for 350k processes.
     *
     * Based on the number of pairs.
     */
    private function estimateGenerationTime(int $pairs): int
    {
        // coefficients obtained from polynomial regression
        $a = 0.1; // Updated hypothetical value
        $b = 0.7; // Hypothetical value
        $c = 5;   // Hypothetical value

        // calculate estimated process time
        return $a * pow(
            $pairs,
            2
        ) + $b * $pairs + $c;
    }

    private function calculatePlayersMetMeetingsVariation(array $playersMet): float
    {
        $meetingsVariationPerPlayer = array_map(function (array $met) {
            return max($met) - min($met);
        }, $playersMet);

        return (float) array_sum($meetingsVariationPerPlayer) / count($meetingsVariationPerPlayer);
    }

    /**
     * Returns a score in [0, 1] where 1 means the player's matches are well spread across the schedule.
     *
     * Input matches are template matches, using mock player indices (0..playersCount-1):
     * [
     *   [[pA, pB], [pC, pD]],
     *   ...
     * ]
     */
    private function calculatePlayerDistribution(int $playerIndex, array $matches): float
    {
        $playerMatches = [];
        foreach ($matches as $i => $match) {
            if (
                ($match[0][0] ?? null) === $playerIndex || ($match[0][1] ?? null) === $playerIndex ||
                ($match[1][0] ?? null) === $playerIndex || ($match[1][1] ?? null) === $playerIndex
            ) {
                $playerMatches[] = (int) $i;
            }
        }

        if (count($playerMatches) <= 1) {
            return 1.0;
        }

        $totalMatches = count($matches);
        if ($totalMatches <= 0) {
            return 1.0;
        }

        $matchCount = count($playerMatches);

        $idealGap = $totalMatches / $matchCount;
        if ($idealGap <= 0) {
            return 1.0;
        }

        $gaps = [];
        for ($i = 0; $i < $matchCount; $i++) {
            if ($i > 0) {
                $gaps[] = $playerMatches[$i] - $playerMatches[$i - 1];
            }
        }

        $firstMatch = $playerMatches[0];
        $lastMatch = $playerMatches[$matchCount - 1];

        if ($firstMatch > 0) {
            array_unshift($gaps, $firstMatch);
        }
        if ($lastMatch < $totalMatches - 1) {
            $gaps[] = ($totalMatches - 1 - $lastMatch);
        }

        if (empty($gaps)) {
            return 1.0;
        }

        $avgGap = array_sum($gaps) / count($gaps);
        $gapVariance = 0.0;
        foreach ($gaps as $gap) {
            $gapVariance += pow($gap - $avgGap, 2);
        }
        $gapVariance = $gapVariance / count($gaps);
        $normalizedGapVariance = $gapVariance / pow($totalMatches, 2);

        $clusteringPenalty = 0.0;
        $largeGapPenalty = 0.0;

        $matchDensity = $matchCount / $totalMatches;
        $basePenalty = max(0.1, 0.5 - ($matchDensity * 0.3));

        $acceptableRange = $idealGap * 0.3;
        $minAcceptableGap = $idealGap - $acceptableRange;
        $maxAcceptableGap = $idealGap + $acceptableRange;

        foreach ($gaps as $gap) {
            if ($gap < $minAcceptableGap) {
                $gapDeficit = $minAcceptableGap - $gap;
                $penaltyRatio = $gapDeficit / $idealGap;
                $clusteringPenalty += min($basePenalty, $penaltyRatio * $basePenalty);
            }

            if ($gap > $maxAcceptableGap && $maxAcceptableGap > 0) {
                $gapExcess = $gap - $maxAcceptableGap;
                $penaltyRatio = $gapExcess / $maxAcceptableGap;
                $largeGapPenalty += min(0.6, $penaltyRatio * 0.6);
            }
        }

        $totalSpan = $lastMatch - $firstMatch;
        $idealSpan = ($matchCount - 1) * $idealGap;
        $spanRatio = ($idealSpan > 0) ? min($totalSpan / $idealSpan, 1) : 1.0;

        $gapScore = 1 - $normalizedGapVariance;
        $clusteringScore = max(0.0, 1 - $clusteringPenalty);
        $largeGapScore = max(0.0, 1 - $largeGapPenalty);

        $finalScore = ($gapScore * 0.3) + ($clusteringScore * 0.3) + ($largeGapScore * 0.3) + ($spanRatio * 0.1);

        return max(0.0, min(1.0, $finalScore));
    }

    private function pcNextPermutation($p, $size)
    {
        // slide down the array looking for where we're smaller than the next guy
        for ($i = $size - 1; $i >= 0 && $p[$i] >= $p[$i + 1]; --$i) {
        }

        // if this doesn't occur, we've finished our permutations
        // the array is reversed: (1, 2, 3, 4) => (4, 3, 2, 1)
        if ($i == -1) {
            return false;
        }

        // slide down the array looking for a bigger number than what we found before
        for ($j = $size; $p[$j] <= $p[$i]; --$j) {
        }

        // swap them
        $tmp = $p[$i];
        $p[$i] = $p[$j];
        $p[$j] = $tmp;

        // now reverse the elements in between by swapping the ends
        for (++$i, $j = $size; $i < $j; ++$i, --$j) {
            $tmp = $p[$i];
            $p[$i] = $p[$j];
            $p[$j] = $tmp;
        }

        return $p;
    }

    private function addPlayersMet(array $playersMet, array $match): array
    {
        $mockPlayers = [
            $match[0][0],
            $match[0][1],
            $match[1][0],
            $match[1][1],
        ];

        foreach ($mockPlayers as $p1) {
            foreach ($mockPlayers as $p2) {
                if ($p1 != $p2) {
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
     * Check if, one of the 4 players, is playing with his most met player.
     */
    private function playersMetTooMuch(array $pair1, array $pair2, array $playersMet, int $differenceLimit = 1): bool
    {
        $matchPlayers = [
            $pair1[0],
            $pair1[1],
            $pair2[0],
            $pair2[1],
        ];

        foreach ($matchPlayers as $p) {
            if (isset($playersMet[$p])) {
                $leastMetPlayerMeetings = min($playersMet[$p]);
                $mostMetPlayer = Func::keyFromBiggest($playersMet[$p]);

                // if ($playersMet[$leastMetPlayer] != $playersMet[$mostMetPlayer] && in_array($mostMetPlayer, $matchPlayers)) {
                if ($playersMet[$p][$mostMetPlayer] - $leastMetPlayerMeetings >= $differenceLimit && in_array($mostMetPlayer, $matchPlayers)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function nextCombination(&$combination, $n, $k)
    {
        $i = $k - 1;
        while ($i >= 0 && $combination[$i] == $n - $k + $i) {
            $i--;
        }
        if ($i >= 0) {
            $combination[$i]++;
            for ($j = $i + 1; $j < $k; $j++) {
                $combination[$j] = $combination[$i] + $j - $i;
            }
            return $combination;
        }
        return null;
    }

    /**
     * Sort matches so every player has his matches distributed equally over the time.
     *
     * For match counts below {@see TemplateMatchesGenerator::SORT_MATCHES_MULTISTART_THRESHOLD}, walks
     * permutations in lexicographic order from the identity mapping. For larger counts, uses a deterministic
     * multi-start local search (windowed swaps + occasional full swap passes) within the wall budget.
     */
    private function sortMatches(array $matches, array $mockPlayers): array
    {
        $matches = array_values($matches);
        $m = count($matches);

        if ($m <= 1) {
            return $matches;
        }

        $estimatedTime = $this->estimateGenerationTime($m);
        $wallSeconds = (int) max(1, (int) ceil(self::$sortMatchesWallBudgetNs / 1_000_000_000));
        ini_set(
            'max_execution_time',
            (string) ((int) ini_get('max_execution_time') + $estimatedTime + $wallSeconds)
        );

        $deadlineNs = $this->sortMatchesMonotonicNow() + self::$sortMatchesWallBudgetNs;

        if ($m >= self::SORT_MATCHES_MULTISTART_THRESHOLD) {
            return $this->sortMatchesMultistartLocalSearch($matches, $mockPlayers, $deadlineNs);
        }

        $bestOrderedMatches = $matches;
        $bestMin = null;
        $bestAvg = null;

        // Lexicographic scan from identity (deterministic) for smaller m.
        $size = $m - 1;
        $perm = range(0, $size);
        $permCopy = range(0, $size);

        $lexStride = self::$sortMatchesLexStride;
        if ($lexStride <= 1 && $m >= 15) {
            $lexStride = 7;
        }

        $permutationsExplored = 0;
        $permutationsSkipped = 0;
        $bestImprovementIndex = 0;

        do {
            if ($this->sortMatchesMonotonicNow() >= $deadlineNs) {
                break;
            }

            $orderedMatches = $this->sortMatchesOrderByPerm($matches, $perm);
            $scores = $this->scoreMatchOrderDistribution($orderedMatches, $mockPlayers);
            $minScore = $scores['min'];
            $avgScore = $scores['avg'];

            ++$permutationsExplored;

            if ($bestMin === null || $minScore > $bestMin || ($minScore === $bestMin && $avgScore > $bestAvg)) {
                $bestMin = $minScore;
                $bestAvg = $avgScore;
                $bestOrderedMatches = $orderedMatches;
                ++$bestImprovementIndex;
            }

            if ($this->sortMatchesMonotonicNow() >= $deadlineNs) {
                break;
            }

            $next = $perm;
            for ($s = 0; $s < $lexStride; ++$s) {
                $next = $this->pcNextPermutation($next, $size);
                if ($next === false || $next === $permCopy) {
                    break 2;
                }
                if ($s < $lexStride - 1) {
                    ++$permutationsSkipped;
                }
            }
            $perm = $next;
        } while ($perm && $perm !== $permCopy);

        return $bestOrderedMatches;
    }

    /**
     * Deterministic multi-start local search for larger match counts (no bigint / no randomness).
     *
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int> $mockPlayers
     * @return array<int, array<int, array<int, int>>>
     */
    private function sortMatchesMultistartLocalSearch(
        array $matches,
        array $mockPlayers,
        int $deadlineNs
    ): array {
        $m = count($matches);
        $budgetTotalNs = self::$sortMatchesWallBudgetNs;
        $perStartSliceNs = intdiv($budgetTotalNs * 12, 100);
        if ($perStartSliceNs < 1) {
            $perStartSliceNs = 1;
        }

        $seedShuffle = (int) (crc32((string) json_encode($matches, JSON_UNESCAPED_UNICODE)) & 0x7fffffff);
        if ($seedShuffle === 0) {
            $seedShuffle = 1;
        }

        $starts = [
            'identity' => array_values($matches),
            'reverse' => array_values(array_reverse($matches)),
            'rotate_third' => $this->sortMatchesRotateMatchesList($matches, intdiv($m, 3)),
            'rotate_two_thirds' => $this->sortMatchesRotateMatchesList($matches, intdiv(2 * $m, 3)),
            'deterministic_shuffle' => $this->sortMatchesOrderByPerm(
                $matches,
                $this->sortMatchesDeterministicIndicesPermutation($m, $seedShuffle)
            ),
        ];

        $windowW = min(12, max(2, intdiv($m, 3)));
        if ($m > 1 && $windowW >= $m) {
            $windowW = $m - 1;
        }

        $baseScores = $this->scoreMatchOrderDistribution($matches, $mockPlayers);
        $globalBestOrder = array_values($matches);
        $globalBestMin = $baseScores['min'];
        $globalBestAvg = $baseScores['avg'];

        foreach ($starts as $startOrder) {
            if ($this->sortMatchesMonotonicNow() >= $deadlineNs) {
                break;
            }

            $sliceEnd = min($deadlineNs, $this->sortMatchesMonotonicNow() + $perStartSliceNs);
            $stats = [
                'improvements' => 0,
                'passes' => 0,
                'fullPasses' => 0,
            ];
            $refined = $this->sortMatchesLocalSearchUntilDeadline(
                array_values($startOrder),
                $mockPlayers,
                $sliceEnd,
                $windowW,
                $stats
            );

            $sc = $this->scoreMatchOrderDistribution($refined, $mockPlayers);
            if ($sc['min'] > $globalBestMin || ($sc['min'] === $globalBestMin && $sc['avg'] > $globalBestAvg)) {
                $globalBestMin = $sc['min'];
                $globalBestAvg = $sc['avg'];
                $globalBestOrder = $refined;
            }
        }

        $statsRefine = [
            'improvements' => 0,
            'passes' => 0,
            'fullPasses' => 0,
        ];
        return $this->sortMatchesLocalSearchUntilDeadline(
            $globalBestOrder,
            $mockPlayers,
            $deadlineNs,
            $windowW,
            $statsRefine
        );
    }

    /**
     * @param array<int, array<int, array<int, int>>> $matches
     * @return array<int, array<int, array<int, int>>>
     */
    private function sortMatchesRotateMatchesList(array $matches, int $k): array
    {
        $matches = array_values($matches);
        $m = count($matches);
        if ($m <= 1) {
            return $matches;
        }

        $k = $k % $m;

        return array_values(array_merge(array_slice($matches, $k), array_slice($matches, 0, $k)));
    }

    /**
     * @return array<int, int>
     */
    private function sortMatchesDeterministicIndicesPermutation(int $m, int $seed): array
    {
        $perm = range(0, $m - 1);
        if ($m < 2) {
            return $perm;
        }

        $s = $seed & 0x7fffffff;
        if ($s === 0) {
            $s = 1;
        }

        for ($i = $m - 1; $i > 0; --$i) {
            $s = (int) (($s * 1103515245 + 12345) & 0x7fffffff);
            $j = $s % ($i + 1);
            $tmp = $perm[$i];
            $perm[$i] = $perm[$j];
            $perm[$j] = $tmp;
        }

        return $perm;
    }

    /**
     * @param array<int, array<int, array<int, int>>> $ordered
     * @param array<int, int> $mockPlayers
     */
    private function sortMatchesLocalSearchUntilDeadline(
        array $ordered,
        array $mockPlayers,
        int $deadlineNs,
        int $windowW,
        array &$stats
    ): array {
        $m = count($ordered);
        if ($m < 2) {
            return $ordered;
        }

        while ($this->sortMatchesMonotonicNow() < $deadlineNs) {
            $next = $this->sortMatchesTryFirstImprovingSwapWindowed($ordered, $mockPlayers, $deadlineNs, $windowW);
            if ($next !== null) {
                $ordered = $next;
                ++$stats['improvements'];
                ++$stats['passes'];

                continue;
            }

            $nextFull = $this->sortMatchesTryFirstImprovingSwapFull($ordered, $mockPlayers, $deadlineNs);
            if ($nextFull !== null) {
                $ordered = $nextFull;
                ++$stats['improvements'];
                ++$stats['passes'];
                ++$stats['fullPasses'];

                continue;
            }

            break;
        }

        return $ordered;
    }

    /**
     * @param array<int, array<int, array<int, int>>> $ordered
     * @param array<int, int> $mockPlayers
     * @return array<int, array<int, array<int, int>>>|null
     */
    private function sortMatchesTryFirstImprovingSwapWindowed(
        array $ordered,
        array $mockPlayers,
        int $deadlineNs,
        int $windowW
    ): ?array {
        $m = count($ordered);
        $baseScores = $this->scoreMatchOrderDistribution($ordered, $mockPlayers);
        $baseMin = $baseScores['min'];
        $baseAvg = $baseScores['avg'];

        for ($i = 0; $i < $m - 1; ++$i) {
            for ($d = 1; $d <= $windowW; ++$d) {
                $j = $i + $d;
                if ($j >= $m) {
                    break;
                }

                if ($this->sortMatchesMonotonicNow() >= $deadlineNs) {
                    return null;
                }

                $trial = $ordered;
                $tmp = $trial[$i];
                $trial[$i] = $trial[$j];
                $trial[$j] = $tmp;

                $sc = $this->scoreMatchOrderDistribution($trial, $mockPlayers);
                if ($sc['min'] > $baseMin || ($sc['min'] === $baseMin && $sc['avg'] > $baseAvg)) {
                    return $trial;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<int, array<int, int>>> $ordered
     * @param array<int, int> $mockPlayers
     * @return array<int, array<int, array<int, int>>>|null
     */
    private function sortMatchesTryFirstImprovingSwapFull(array $ordered, array $mockPlayers, int $deadlineNs): ?array
    {
        $m = count($ordered);
        $baseScores = $this->scoreMatchOrderDistribution($ordered, $mockPlayers);
        $baseMin = $baseScores['min'];
        $baseAvg = $baseScores['avg'];

        for ($i = 0; $i < $m - 1; ++$i) {
            for ($j = $i + 1; $j < $m; ++$j) {
                if ($this->sortMatchesMonotonicNow() >= $deadlineNs) {
                    return null;
                }

                $trial = $ordered;
                $tmp = $trial[$i];
                $trial[$i] = $trial[$j];
                $trial[$j] = $tmp;

                $sc = $this->scoreMatchOrderDistribution($trial, $mockPlayers);
                if ($sc['min'] > $baseMin || ($sc['min'] === $baseMin && $sc['avg'] > $baseAvg)) {
                    return $trial;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<int, array<int, int>>> $matches
     * @param array<int, int> $perm
     * @return array<int, array<int, array<int, int>>>
     */
    private function sortMatchesOrderByPerm(array $matches, array $perm): array
    {
        $ordered = [];
        foreach ($perm as $i) {
            $ordered[] = $matches[$i];
        }

        return $ordered;
    }

    private function sortMatchesMonotonicNow(): int
    {
        if (is_callable(self::$sortMatchesClock)) {
            return (int) (self::$sortMatchesClock)();
        }

        return (int) hrtime(true);
    }

    /**
     * @return array{min: float, avg: float}
     */
    private function scoreMatchOrderDistribution(array $orderedMatches, array $mockPlayers): array
    {
        $sum = 0.0;
        $min = INF;

        foreach ($mockPlayers as $playerIndex) {
            $score = $this->calculatePlayerDistribution((int) $playerIndex, $orderedMatches);
            $sum += $score;
            if ($score < $min) {
                $min = $score;
            }
        }

        $count = count($mockPlayers);

        return [
            'min' => $min === INF ? 0.0 : (float) $min,
            'avg' => $count > 0 ? $sum / $count : 0.0,
        ];
    }

    /**
     * Make sure every player serves the first as equally often as possible.
     */
    private function adjustServingOrder(array $matches, int $playerNumber): array
    {
        // Initialize serving counts for each player
        $serve_counts = array_fill(0, $playerNumber, 0);

        foreach ($matches as &$match) {
            // Calculate total serves for each player in the match
            $team1 = $match[0];
            $team2 = $match[1];

            $team1_serve_count = $serve_counts[$team1[0]] + $serve_counts[$team1[1]];
            $team2_serve_count = $serve_counts[$team2[0]] + $serve_counts[$team2[1]];

            // Swap teams if team2 has served less than team1
            if ($team2_serve_count < $team1_serve_count) {
                $match = array($team2, $team1);
                $team1 = $match[0];
            }

            // Increment serve counts for the first team players
            $serve_counts[$team1[0]]++;
            $serve_counts[$team1[1]]++;
        }

        return $matches;
    }

    /**
     * Duplicate the matches equally with @param repeatOpponents.
     */
    private function repeatMatches(array $matches, int $repeatOpponents): array
    {
        $repeatedMatches = [];

        for ($i = 1; $i <= $repeatOpponents; $i++) {
            foreach ($matches as $match) {
                $repeatedMatches[] = $match;
            }
        }

        return $repeatedMatches;
    }
}
