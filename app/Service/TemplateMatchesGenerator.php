<?php

namespace Arshavinel\PadelMiniTour\Service;

use Arshwell\Monolith\Cache;
use Arshwell\Monolith\Func;

class TemplateMatchesGenerator
{
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
        $key = "template-matches/v1.5/players-{$playersCount}-partners-{$opponentsPerPlayer}-repeat-{$repeatOpponents}";

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
            'matches' => null,
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

    private function boredPlayers(array $matches, array $mockPlayers): array
    {
        $mockPlayersEnergy = array_fill_keys($mockPlayers, -1);

        foreach ($matches as $m => $match) {
            foreach ($match as $team) {
                foreach ($team as $player) {
                    $mockPlayersEnergy[$player] = $m;
                }
            }
        }

        asort($mockPlayersEnergy);

        return array_keys($mockPlayersEnergy);
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

    private function getNextMatchIndex(array $matches, array $mockPlayers, array $mockPlayersToOmit = []): ?int
    {
        $mockPlayers = array_values($mockPlayers);
        $k = 4;  // Size of combinations
        $n = count($mockPlayers);
        $combination = range(0, $k - 1);  // Initial combination: [0, 1, 2, 3]

        $backupMatches = [];

        do {
            $combPlayers = [];
            foreach ($combination as $index) {
                $combPlayers[] = $mockPlayers[$index];
            }

            $foundMatches = array_filter($matches, function (array $match) use ($combPlayers) {
                return !array_diff($combPlayers, Func::arrayFlatten($match));
            });
            $perfectMatches = array_filter($foundMatches, function (array $match) use ($mockPlayersToOmit) {
                return empty(array_intersect($mockPlayersToOmit, Func::arrayFlatten($match)));
            });

            if (empty($perfectMatches)) {
                $backupMatches = array_replace($backupMatches, $foundMatches);
            }
        } while (($combination = $this->nextCombination($combination, $n, $k)) && empty($perfectMatches));

        if ($perfectMatches) {
            return key($perfectMatches);
        }
        if ($backupMatches) {
            return key($backupMatches);
        }

        return null;
    }

    /**
     * Sort matches so every player has his matches distributed equally over the time.
     */
    private function sortMatches(array $matches, array $mockPlayers): array
    {
        $sortedMatches = array();

        $matches = array_reverse($matches);

        while ($matches) {
            // players sorted by how long ago they had the last match
            $boredPlayers = $this->boredPlayers($sortedMatches, $mockPlayers);

            // players which still have matches to play
            $mockPlayersToPlay = array_intersect($boredPlayers, Func::arrayFlatten($matches));

            if ($sortedMatches) {
                $nextMatchIndex = $this->getNextMatchIndex(
                    $matches,
                    $mockPlayersToPlay,
                    Func::arrayFlatten($sortedMatches[array_key_last($sortedMatches)])
                );
            } else {
                $nextMatchIndex = $this->getNextMatchIndex(
                    $matches,
                    $mockPlayersToPlay
                );
            }

            $sortedMatches[] = $matches[$nextMatchIndex];

            unset($matches[$nextMatchIndex]);
        }

        return $sortedMatches;
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
