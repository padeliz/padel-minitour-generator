<?php

use Arshavinel\PadelMiniTour\Service\EventDivision;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use Arshwell\Monolith\Meta;

if (
    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['organizer-id']) || !is_numeric($_GET['organizer-id']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['color']) || !is_string($_GET['color']) ||
    empty($_GET['court']) || !is_string($_GET['court']) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end']) ||
    empty($_GET['opponents-per-player']) || !is_numeric($_GET['opponents-per-player']) ||
    empty($_GET['repeat-partners']) || !is_numeric($_GET['repeat-partners']) ||
    empty($_GET['player-ids']) || !is_array($_GET['player-ids']) ||
    !isset($_GET['include-final']) || !is_numeric($_GET['include-final']) ||
    !isset($_GET['allow-replacements']) || !is_numeric($_GET['allow-replacements']) ||
    (!empty($_GET['adjust-points-per-match']) && !is_numeric($_GET['adjust-points-per-match'])) // optional integer
) {
    _vd($_GET, '$_GET');
    die('$_GET vars: [edition], [organizer-id], [partner-id], [title], [color], [time-start], [time-end], [opponents-per-player], [repeat-partners], [players], [include-final], [allow-replacements] are mandatory.
    [adjust-points-per-match] is an optional integer.');
}

// Optional `?template-version=N` overrides the default template version. Silently fall back to the
// default when the value is missing, non-numeric, or doesn't have the requested combo on disk -- the
// disabled-in-dropdown UX is the primary guard, this guard just protects against stale URLs and
// hand-crafted bookmarks 500ing the page.
$templateVersion = null;
if (isset($_GET['template-version']) && is_scalar($_GET['template-version']) && is_numeric($_GET['template-version'])) {
    $candidate = (int) $_GET['template-version'];
    if ($candidate > 0) {
        $repository = new TemplateMatchesRepository();
        if ($repository->hasAt(
            $candidate,
            count($_GET['player-ids']),
            (int) $_GET['opponents-per-player'],
            (int) $_GET['repeat-partners'],
            (bool) ($_GET['fixed-teams'] ?? false)
        )) {
            $templateVersion = $candidate;
        }
    }
}

$eventDivision = new EventDivision(
    $_GET['edition'],
    $_GET['organizer-id'],
    $_GET['partner-id'],
    $_GET['title'],
    $_GET['court'],
    $_GET['player-ids'],
    $_GET['opponents-per-player'],
    $_GET['repeat-partners'],
    $_GET['time-start'],
    $_GET['time-end'],
    (bool) ($_GET['include-final'] ?? false),
    (bool) ($_GET['demonstrative-match'] ?? false),
    (bool) ($_GET['fixed-teams'] ?? false),
    $templateVersion
);


Meta::set('title', "Matches | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
