<?php

namespace Arshavinel\PadelMiniTour\Service;

use Arshwell\Monolith\Cache;
use Arshwell\Monolith\Func;

class EventDivision
{
    private string $title;
    private int $partnersLimit;
    private array $players;
    private int $playersCount;
    private array $matches;
    private int $matchesCount;
    private array $countPartners;
    private array $countPlayersMet;
    private bool $hasDifferentPartnersNumber;
    private int $pointsPerMatch;
    private int $pointsPerPlayer;

    public function __construct(string $title, int $eventHours, array $players, int $partnersLimit)
    {
        $this->partnersLimit = $partnersLimit;

        $playersCount = count($players);

        $divisionData = Cache::fetch("division-data/players-{$playersCount}-partners-{$partnersLimit}");

        if (empty($divisionData)) {
            $divisionData = $this->generateDivisionData($playersCount, $partnersLimit);

            Cache::store(
                "division-data/players-{$playersCount}-partners-{$partnersLimit}",
                $divisionData
            );
        }

        $matches = $divisionData['matches'];
        $hasDifferentPartnersNumber = $divisionData['hasDifferentPartnersNumber'];

        array_walk_recursive($matches, function (&$value) use ($players) {
            $value = $players[$value];
        });

        $countPartners = [];
        array_walk_recursive($divisionData['countPartners'], function ($value, $key) use (&$countPartners, $players) {
            $countPartners[$players[$key]] = $value;
        });

        $countPlayersMet = [];
        array_walk_recursive($divisionData['countPlayersMet'], function ($value, $key) use (&$countPlayersMet, $players) {
            $countPlayersMet[$players[$key]] = $value;
        });


        $this->title = $title;
        $this->players = $players;
        $this->playersCount = $playersCount;
        $this->matches = $matches;
        $this->matchesCount = count($matches);
        $this->countPartners = $countPartners;
        $this->countPlayersMet = $countPlayersMet;
        $this->hasDifferentPartnersNumber = $hasDifferentPartnersNumber;

        $this->pointsPerMatch = floor(ceil(110 * $eventHours - 1 + (20 / 60)) / $this->matchesCount);
        $this->pointsPerPlayer = $this->pointsPerMatch * $partnersLimit;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPartnersLimit(): int
    {
        return $this->partnersLimit;
    }

    public function getPlayers(): array
    {
        return $this->players;
    }

    public function getPlayersCount(): int
    {
        return $this->playersCount;
    }

    public function getMatches(): array
    {
        return $this->matches;
    }

    public function getMatchesCount(): int
    {
        return $this->playersCount;
    }

    public function countPartners(string $player): int
    {
        return $this->countPartners[$player];
    }

    public function countPlayersMet(string $player): int
    {
        return $this->countPlayersMet[$player];
    }

    public function hasDifferentPartnersNumber(): bool
    {
        return $this->hasDifferentPartnersNumber;
    }

    public function getPointsPerMatch(): int
    {
        return $this->pointsPerMatch;
    }

    public function getPointsPerPlayer(): int
    {
        return $this->pointsPerPlayer;
    }


    private function generateDivisionData(int $playersCount, int $partnersLimit): array
    {
        $mockPlayers = range(0, $playersCount - 1);

        $countTeams = [];
        $countPartners = [];


        $pairs = [];

        foreach ($mockPlayers as $player) {
            $countPartners[$player] = 0;
        }


        foreach ($mockPlayers as $p1) {

            foreach (array_reverse($mockPlayers) as $p2) {

                if ($countPartners[$p1] < $partnersLimit && $countPartners[$p2] < $partnersLimit) {

                    if ($p1 != $p2 && !isset($countTeams["$p1 + $p2"]) && !isset($countTeams["$p2 + $p1"])) {
                        $countTeams["$p1 + $p2"] = count($pairs);

                        $countPartners[$p1] = $countPartners[$p1] + 1;
                        $countPartners[$p2] = $countPartners[$p2] + 1;

                        $pairs[] = [
                            'players' => [$p1, $p2],
                            'used' => false,
                        ];
                    }
                }
            }
        }




        $size = count($pairs) - 1;
        $perm = range(0, $size);
        $permCopy = range(0, $size);

        do {
            $permutedPairs = [];

            foreach ($perm as $i) {
                $permutedPairs[] = $pairs[$i];
            }

            $matches = [];
            $countPlayersMet = [];

            do {
                $matchAdded = false;

                foreach ($permutedPairs as $i => &$pair1) {
                    if ($pair1['used'] == false) {
                        foreach ($permutedPairs as $j => &$pair2) {
                            if (
                                $i != $j && $pair1['used'] == false && $pair2['used'] == false &&
                                !array_intersect($pair1['players'], $pair2['players']) &&
                                !$this->playersMetTooMuch($pair1['players'], $pair2['players'], $countPlayersMet)
                            ) {
                                $matches[] = [
                                    $pair1['players'],
                                    $pair2['players'],
                                ];

                                $pair1['used'] = true;
                                $pair2['used'] = true;

                                $countPlayersMet = $this->addPlayersMet($countPlayersMet, [$pair1['players'], $pair2['players']]);

                                $matchAdded = true;
                                break;
                            }

                            unset($pair2);
                        }
                    }

                    unset($pair1);
                }

                $permutedPairs = array_filter($permutedPairs, function ($pair) {
                    return $pair['used'] == false;
                });
            } while ($matchAdded && $this->hasUsefullPairs($permutedPairs));
        } while (!empty($permutedPairs) && ($perm = $this->pcNextPermutation($perm, $size)) && $perm !== $permCopy);

        return [
            'matches' => $this->sortMatches($matches, $mockPlayers),
            'countPartners' => $countPartners,
            'countPlayersMet' => $countPlayersMet,
            'hasDifferentPartnersNumber' => (count(array_count_values($countPartners)) > 1),
        ];
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

    private function hasUsefullPairs(array $array)
    {
        $playerCounts = array();

        foreach ($array as $item) {
            foreach ($item['players'] as $player) {
                if (!isset($playerCounts[$player])) {
                    $playerCounts[$player] = 1;
                } else {
                    $playerCounts[$player]++;
                }
            }
        }

        $repeatingPlayers = array_filter($playerCounts, function ($count) use ($array) {
            return $count == count($array);
        });

        return empty($repeatingPlayers);
    }

    private function addPlayersMet(array $countPlayersMet, array $match): array
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
                    if (!isset($countPlayersMet[$p1])) {
                        $countPlayersMet[$p1] = [];
                    }
                    if (!isset($countPlayersMet[$p1][$p2])) {
                        $countPlayersMet[$p1][$p2] = 0;
                    }

                    $countPlayersMet[$p1][$p2]++;
                }
            }
        }

        return $countPlayersMet;
    }

    private function playersMetTooMuch(array $pair1, array $pair2, array $countPlayersMet): bool
    {
        $mockPlayers = [
            $pair1[0],
            $pair1[1],
            $pair2[0],
            $pair2[1],
        ];

        foreach ($mockPlayers as $p1) {
            if (isset($countPlayersMet[$p1])) {
                $leastMetPlayer = Func::keyFromSmallest($countPlayersMet[$p1]);
                $mostMetPlayer = Func::keyFromBiggest($countPlayersMet[$p1]);

                if ($countPlayersMet[$leastMetPlayer] != $countPlayersMet[$mostMetPlayer] && in_array($mostMetPlayer, $mockPlayers)) {
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
}
