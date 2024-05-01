<?php

use Arshavinel\PadelMiniTour\Service\EventDivision;
use Arshwell\Monolith\Meta;


if (empty($_GET['players']) || empty($_GET['limit-partners']) || !is_numeric($_GET['limit-partners']) ||
    empty($_GET['title']) || empty($_GET['time-in-hours']) || !is_numeric($_GET['time-in-hours'])
) {
    die('$_GET vars [time-in-hours], [limit-partners] and [players] are mandatory.');
}

$eventDivision = new EventDivision(
    $_GET['title'],
    $_GET['time-in-hours'],
    explode(',', trim(preg_replace('/([\s,]+)?\n([\s,]+)?/m', ',', $_GET['players']), ', ')), // players
    $_GET['limit-partners']
);


Meta::set('title', "Matches | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
