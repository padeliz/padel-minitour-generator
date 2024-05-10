<?php

use Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper;
use Arshwell\Monolith\Meta;

if (
    empty($_GET['title']) || empty($_GET['matches']) || !is_array($_GET['matches']) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end'])
) {
    die('$_GET vars [title], [time-start], [time-end] and [matches] are mandatory.');
}

$countMatches = count($_GET['matches']);

$marginTop = PdfHtmlHelper::getMatchesMarginTop($countMatches);

Meta::set('title', "Matches beautified | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
