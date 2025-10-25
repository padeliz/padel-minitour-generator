<?php

use Arshavinel\PadelMiniTour\Service\EventDivision;
use Arshwell\Monolith\Meta;

if (
    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['color']) || !is_string($_GET['color']) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end']) ||
    empty($_GET['opponents-per-player']) || !is_numeric($_GET['opponents-per-player']) ||
    empty($_GET['repeat-partners']) || !is_numeric($_GET['repeat-partners']) ||
    empty($_GET['player-ids']) || !is_array($_GET['player-ids']) ||
    (!empty($_GET['adjust-points-per-match']) && !is_numeric($_GET['adjust-points-per-match'])) // optional integer
) {
    die('$_GET vars: [edition], [partner-id], [title], [color], [time-start], [time-end], [opponents-per-player], [repeat-partners], [players] are mandatory.
    [adjust-points-per-match] is an optional integer.');
}

$eventDivision = new EventDivision(
    $_GET['edition'],
    $_GET['partner-id'],
    $_GET['title'],
    $_GET['player-ids'],
    $_GET['opponents-per-player'],
    $_GET['repeat-partners'],
    $_GET['time-start'],
    $_GET['time-end'],
    (bool) ($_GET['include-scores'] ?? false),
    (bool) ($_GET['demonstrative-match'] ?? false),
    (bool) ($_GET['fixed-teams'] ?? false)
);


Meta::set('title', "Matches | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
