<?php

use Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper;
use Arshwell\Monolith\Meta;

if (
    empty($_GET['edition']) || !is_numeric($_GET['edition']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['matches']) || !is_array($_GET['matches']) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end'])
) {
    die('$_GET vars [edition], [partner-id], [title], [time-start], [time-end] and [matches] are mandatory.');
}

$pointsPerMatch = ($_GET['points-per-match'] % 2 == 0 ? $_GET['points-per-match'] : ($_GET['points-per-match'] + 1));

$countMatches = count($_GET['matches']);

$marginTop = PdfHtmlHelper::getMatchesMarginTop($countMatches);

Meta::set('title', "Matches beautified | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
