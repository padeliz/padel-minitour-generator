<?php

use Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper;
use Arshwell\Monolith\Meta;

if (
    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['color']) || !is_string($_GET['color']) ||
    empty($_GET['court']) || !is_string($_GET['court']) ||
    empty($_GET['player-matches-count']) || !is_numeric($_GET['player-matches-count']) ||
    empty($_GET['player-ids']) || !is_array($_GET['player-ids']) ||
    empty($_GET['players-collecting-points']) || !is_array($_GET['players-collecting-points']) ||
    !isset($_GET['include-scores']) || !is_numeric($_GET['include-scores']) ||
    !isset($_GET['fixed-teams']) || !is_numeric($_GET['fixed-teams']) ||
    empty($_GET['points-per-match']) || !is_numeric($_GET['points-per-match']) ||
    (!empty($_GET['adjust-points-per-match']) && !is_numeric($_GET['adjust-points-per-match'])) // optional integer
) {
    _vd($_GET, '$_GET');
    die('$_GET vars [edition], [partner-id], [title], [color], [player-matches-count], [player-ids], [players-collecting-points], [include-scores], [fixed-teams] are mandatory.');
}

$pointsPerMatch = ($_GET['points-per-match'] % 2 == 0 ? $_GET['points-per-match'] : ($_GET['points-per-match'] + 1));

// players collecting points
$playerIds = array_intersect($_GET['player-ids'], $_GET['players-collecting-points']);
$countPlayers = count($playerIds);

$pdfPlayers = [];
foreach ($playerIds as $playerId) {
    $pdfPlayers[] = new Arshavinel\PadelMiniTour\DTO\PdfPlayer($playerId);
}

usort($pdfPlayers, function ($a, $b) {
    return $a->getName() <=> $b->getName();
});

$marginTop = PdfHtmlHelper::getPlayersMarginTop($countPlayers);

Meta::set('title', "Players beautified | ARSH Padel MiniTour");
