<?php

use Arshavinel\PadelMiniTour\Helper\CourtNamesHelper;
use Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper;
use Arshwell\Monolith\Meta;

try {
    $courtNames = CourtNamesHelper::normalizeFromRequest($_GET['court-names'] ?? null);
} catch (InvalidArgumentException $e) {
    die($e->getMessage());
}

$courtIndex = isset($_GET['court-index']) && is_numeric($_GET['court-index'])
    ? (int) $_GET['court-index']
    : 0;

if ($courtIndex < 0 || $courtIndex >= count($courtNames)) {
    die('Invalid court-index.');
}

if (
    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['organizer-id']) || !is_numeric($_GET['organizer-id']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    !isset($_GET['include-final']) || !is_numeric($_GET['include-final']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['color']) || !is_string($_GET['color']) ||
    empty($_GET['matches']) || !is_array($_GET['matches']) ||
    !isset($_GET['matches'][$courtIndex]) || !is_array($_GET['matches'][$courtIndex]) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end']) ||
    empty($_GET['points-per-match']) || !is_numeric($_GET['points-per-match']) ||
    (!empty($_GET['adjust-points-per-match']) && !is_numeric($_GET['adjust-points-per-match']))
) {
    _vd($_GET, '$_GET');
    die('$_GET vars [edition], [organizer-id], [partner-id], [include-final], [title], [color], [court-names][], [court-index], [time-start], [time-end] and [matches] are mandatory.
    [adjust-points-per-match] is an optional integer.');
}

$courtName = $courtNames[$courtIndex];
$matches = $_GET['matches'][$courtIndex];

$pointsPerMatch = ($_GET['points-per-match'] % 2 == 0 ? $_GET['points-per-match'] : ($_GET['points-per-match'] + 1));

$matchesCount = $activitiesCount = count($matches);
$hasDemonstrativeMatch = (bool) ($_GET['demonstrative-match'] ?? false);

if (!empty($_GET['include-final'])) {
    $activitiesCount += 2;
}
if ($hasDemonstrativeMatch) {
    $activitiesCount++;
}

$matchesWithoutMarginTop = [];
if (empty($_GET['include-final'])) {
    $matchesWithoutMarginTop[] = 0;
}
$matchesWithoutMarginTop[] = ceil($matchesCount / 2);

$matchBreakColumn = ceil($matchesCount / 2) - 1;

$marginTop = PdfHtmlHelper::getActivitiesMarginTop($activitiesCount);

Meta::set('title', "Matches beautified | ARSH Padel MiniTour");
Meta::set('description', "Fun and short padel matches.");
Meta::set('keywords', "padel, matches, minitour");
