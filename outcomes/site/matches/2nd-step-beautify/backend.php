<?php

use Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper;
use Arshwell\Monolith\Meta;

if (
    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['color']) || !is_string($_GET['color']) ||
    empty($_GET['court']) || !is_string($_GET['court']) ||
    empty($_GET['matches']) || !is_array($_GET['matches']) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end']) ||
    empty($_GET['points-per-match']) || !is_numeric($_GET['points-per-match']) ||
    (!empty($_GET['adjust-points-per-match']) && !is_numeric($_GET['adjust-points-per-match'])) // optional integer
) {
    _vd($_GET, '$_GET');
    die('$_GET vars [edition], [partner-id], [title], [color], [time-start], [time-end] and [matches] are mandatory.
    [adjust-points-per-match] is an optional integer.');
}

$pointsPerMatch = ($_GET['points-per-match'] % 2 == 0 ? $_GET['points-per-match'] : ($_GET['points-per-match'] + 1));

$countMatches = count($_GET['matches']);
$hasDemonstrativeMatch = (bool) ($_GET['demonstrative-match'] ?? false);

if ($hasDemonstrativeMatch) {
    $countMatches++;
}

$marginTop = PdfHtmlHelper::getMatchesMarginTop($countMatches);

Meta::set('title', "Matches beautified | ARSH Padel MiniTour");
Meta::set('description', "Fun and short padel matches.");
Meta::set('keywords', "padel, matches, minitour");
