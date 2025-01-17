<?php

use Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper;
use Arshwell\Monolith\Meta;

if (
    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['color']) || !is_string($_GET['color']) ||
    empty($_GET['matches-count']) || !is_numeric($_GET['matches-count']) ||
    empty($_GET['players']) || !is_array($_GET['players']) ||
    !isset($_GET['include-scores']) || !is_numeric($_GET['include-scores']) ||
    !isset($_GET['fixed-teams']) || !is_numeric($_GET['fixed-teams'])
) {
    die('$_GET vars [edition], [partner-id], [title], [color], [matches-count], [players], [include-scores], [fixed-teams] are mandatory.');
}

foreach ($_GET['players'] as $p => $playerName) {
    if ($playerName == '-') {
        unset($_GET['players'][$p]);
    }
}

sort($_GET['players']);

$countPlayers = count($_GET['players']);

$marginTop = PdfHtmlHelper::getPlayersMarginTop($countPlayers);

Meta::set('title', "Players beautified | ARSH Padel MiniTour");
Meta::set('description', "Division players.");
Meta::set('keywords', "padel, players, minitour");
