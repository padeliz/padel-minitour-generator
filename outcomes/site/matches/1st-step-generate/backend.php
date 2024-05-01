<?php

use Arshwell\Monolith\Func;
use Arshwell\Monolith\Meta;


if (empty($_GET['players']) || empty($_GET['limit-partners']) || !is_numeric($_GET['limit-partners']) ||
    empty($_GET['title']) || empty($_GET['time-in-hours']) || !is_numeric($_GET['time-in-hours'])
) {
    die('$_GET vars [time-in-hours], [limit-partners] and [players] are mandatory.');
}

$players = explode(',', trim(preg_replace('/([\s,]+)?\n([\s,]+)?/m', ',', $_GET['players']), ', '));

/**
 * Combinations which will work:
 *
 *  - 4 players, having 2 partners everyone
 *  - 4 players, having 3 partners everyone
 *
 *  - 5 players, having 4 partners everyone
 *
 *  - 6 players, having 4 partners everyone
 *
 *  - 7 players, having 4 partners everyone
 *
 *  - 8 players, having 2 partners everyone
 *  - 8 players, having 4 partners everyone
 *  - 8 players, having 6 partners everyone
 *  - 8 players, having 7 partners everyone
 *
 *  - 9 players, having 8 partners everyone
 *
 *  - 10 players, having 8 partners everyone
 *
 *  - 12 players, having 2 partners everyone
 *  - 12 players, having 3 partners everyone
 *  - 12 players, having 6 partners everyone
 *  - 12 players, having 7 partners everyone
 *  - 12 players, having 8 partners everyone
 *  - 12 players, having 9 partners everyone
 *
 *  - 13 players, having 4 partners everyone
 *
 *  - 14 players, having 4 partners everyone
 *  - 14 players, having 8 partners everyone
 *
 *  - 15 players, having 4 partners everyone
 *  - 15 players, having 8 partners everyone
 *
 *  - 16 players, having 2 partners everyone
 *  - 16 players, having 3 partners everyone
 *  - 16 players, having 4 partners everyone
 *  - 16 players, having 5 partners everyone
 *  - 16 players, having 8 partners everyone
 */

$limitTotalPointsPlayed = ceil(110 * (int)$_GET['time-in-hours'] - 1 + (20 / 60));
$limitPartners = (int)$_GET['limit-partners'];

$countTeams = [];
$countPartners = [];


$pairs = [];

foreach ($players as $player) {
    $countPartners[$player] = 0;
}


foreach ($players as $p1) {

    foreach (array_reverse($players) as $p2) {

        if ($countPartners[$p1] < $limitPartners && $countPartners[$p2] < $limitPartners) {

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


function pc_next_permutation($p, $size) {
    // slide down the array looking for where we're smaller than the next guy
    for ($i = $size - 1; $i >= 0 && $p[$i] >= $p[$i+1]; --$i) { }

    // if this doesn't occur, we've finished our permutations
    // the array is reversed: (1, 2, 3, 4) => (4, 3, 2, 1)
    if ($i == -1) { return false; }

    // slide down the array looking for a bigger number than what we found before
    for ($j = $size; $p[$j] <= $p[$i]; --$j) { }

    // swap them
    $tmp = $p[$i]; $p[$i] = $p[$j]; $p[$j] = $tmp;

    // now reverse the elements in between by swapping the ends
    for (++$i, $j = $size; $i < $j; ++$i, --$j) {
         $tmp = $p[$i]; $p[$i] = $p[$j]; $p[$j] = $tmp;
    }

    return $p;
}

function hasUsefullPairs(array $array) {
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

    $repeatingPlayers = array_filter($playerCounts, function($count) use ($array) {
        return $count == count($array);
    });

    return empty($repeatingPlayers);
}

function addPlayersMet(array $countPlayersMet, array $match): array {
    $players = [
        $match[0][0],
        $match[0][1],
        $match[1][0],
        $match[1][1],
    ];

    foreach ($players as $p1) {
        foreach ($players as $p2) {
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

function playersMetTooMuch(array $pair1, array $pair2, array $countPlayersMet): bool
{
    $players = [
        $pair1[0],
        $pair1[1],
        $pair2[0],
        $pair2[1],
    ];

    // _vd($players, '$players');
    // _vd($countPlayersMet, '$countPlayersMet');
    // _br(3);

    foreach ($players as $p1) {
        if (isset($countPlayersMet[$p1])) {
            $leastMetPlayer = Func::keyFromSmallest($countPlayersMet[$p1]);
            $mostMetPlayer = Func::keyFromBiggest($countPlayersMet[$p1]);

            // _vd($mostMetPlayer, '$mostMetPlayer');

            if ($countPlayersMet[$leastMetPlayer] != $countPlayersMet[$mostMetPlayer] && in_array($mostMetPlayer, $players)) {
                // _br(3);
                // die('omgggg');
                // _vd($countPlayersMet[$p1], '$countPlayersMet[$p1]');
                // _vd($pair1, '$pair1');
                // _vd($pair2, '$pair2');
                // _vd($p1, '$p1');
                // _br(3);
                // exit;
                return true;
            }
        }
    }

    return false;
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
                    if ($i != $j && $pair1['used'] == false && $pair2['used'] == false &&
                    !array_intersect($pair1['players'], $pair2['players']) &&
                    !playersMetTooMuch($pair1['players'], $pair2['players'], $countPlayersMet)) {
                        $matches[] = [
                            $pair1['players'],
                            $pair2['players'],
                        ];

                        $pair1['used'] = true;
                        $pair2['used'] = true;

                        $countPlayersMet = addPlayersMet($countPlayersMet, [$pair1['players'], $pair2['players']]);

                        // _vd($countPlayersMet, '$countPlayersMet');

                        $matchAdded = true;
                        break;
                    }

                    unset($pair2);
                }
            }

            unset($pair1);
        }

        $permutedPairs = array_filter($permutedPairs, function($pair) {
            return $pair['used'] == false;
        });
    } while ($matchAdded && hasUsefullPairs($permutedPairs));

    // _vd($matches, '$matches');
    // exit;

} while (!empty($permutedPairs) && ($perm = pc_next_permutation($perm, $size)) && $perm !== $permCopy);


// _vd($matches, '$matches');
// exit;


function boredPlayers(array $matches, array $players): array {
    $playersEnergy = array_fill_keys($players, -1);

    foreach ($matches as $m => $match) {
        foreach ($match as $team) {
            foreach ($team as $player) {
                $playersEnergy[$player] = $m;
            }
        }
    }

    asort($playersEnergy);

    return array_keys($playersEnergy);
}

function next_combination(&$combination, $n, $k) {
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

function getNextMatchIndex(array $matches, array $players, array $playersToOmit = []): ?int {
    $players = array_values($players);
    $k = 4;  // Size of combinations
    $n = count($players);
    $combination = range(0, $k - 1);  // Initial combination: [0, 1, 2, 3]

    $backupMatches = [];

    do {
        $combPlayers = [];
        foreach ($combination as $index) {
            $combPlayers[] = $players[$index];
        }

        $foundMatches = array_filter($matches, function (array $match) use ($combPlayers) {
            return !array_diff($combPlayers, Func::arrayFlatten($match));
        });
        $perfectMatches = array_filter($foundMatches, function (array $match) use ($playersToOmit) {
            return empty(array_intersect($playersToOmit, Func::arrayFlatten($match)));
        });

        if (empty($perfectMatches)) {
            $backupMatches = array_replace($backupMatches, $foundMatches);
        }
    } while (($combination = next_combination($combination, $n, $k)) && empty($perfectMatches));

    if ($perfectMatches) {
        return key($perfectMatches);
    }
    if ($backupMatches) {
        // _vd($backupMatches, '$backupMatches');

        return key($backupMatches);
    }

    return null;
}

function sortMatches(array $matches, array $players): array {
    $sortedMatches = array();

    $matches = array_reverse($matches);

    while ($matches) {
        // players sorted by how long ago they had the last match
        $boredPlayers = boredPlayers($sortedMatches, $players);

        // players which still have matches to play
        $playersToPlay = array_intersect($boredPlayers, Func::arrayFlatten($matches));

        if ($sortedMatches) {
            $nextMatchIndex = getNextMatchIndex(
                $matches,
                $playersToPlay,
                Func::arrayFlatten($sortedMatches[array_key_last($sortedMatches)])
            );
        } else {
            $nextMatchIndex = getNextMatchIndex(
                $matches,
                $playersToPlay
            );
        }

        $sortedMatches[] = $matches[$nextMatchIndex];

        unset($matches[$nextMatchIndex]);
    }

    return $sortedMatches;
}

$sortedMatches = sortMatches($matches, $players);

// _vd($countPartners, '$countPartners');
// _vd($countPlayersMet, '$countPlayersMet');
// _vd(array_count_values($countPlayersMet), 'array_count_values($countPlayersMet)');
// exit;

$differentPartnersNumber = (count(array_count_values($countPartners)) > 1);


Meta::set('title', "Matches | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
