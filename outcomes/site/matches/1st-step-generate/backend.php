<?php

use Arshavinel\PadelMiniTour\Service\EventDivision;
use Arshwell\Monolith\Meta;

if (
    empty($_GET['title']) || empty($_GET['players']) ||
    empty($_GET['limit-partners']) || !is_numeric($_GET['limit-partners']) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end'])
) {
    die('$_GET vars [title], [time-start], [time-end], [limit-partners] and [players] are mandatory.');
}

$eventDivision = new EventDivision(
    $_GET['title'],
    $_GET['time-start'],
    $_GET['time-end'],
    explode(',', trim(preg_replace('/([\s,]+)?\n([\s,]+)?/m', ',', $_GET['players']), ', ')), // players
    $_GET['limit-partners']
);


Meta::set('title', "Matches | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
