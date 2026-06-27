<?php

use Arshavinel\PadelMiniTour\Helper\CourtNamesHelper;
use Arshavinel\PadelMiniTour\Service\EventDivision;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use Arshwell\Monolith\Meta;

try {
    $courtNames = CourtNamesHelper::normalizeFromRequest($_GET['court-names'] ?? null);
} catch (InvalidArgumentException $e) {
    die($e->getMessage());
}

if (
    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['organizer-id']) || !is_numeric($_GET['organizer-id']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['color']) || !is_string($_GET['color']) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end']) ||
    empty($_GET['opponents-per-player']) || !is_numeric($_GET['opponents-per-player']) ||
    empty($_GET['repeat-partners']) || !is_numeric($_GET['repeat-partners']) ||
    empty($_GET['player-ids']) || !is_array($_GET['player-ids']) ||
    !isset($_GET['include-final']) || !is_numeric($_GET['include-final']) ||
    !isset($_GET['allow-replacements']) || !is_numeric($_GET['allow-replacements']) ||
    (!empty($_GET['adjust-points-per-match']) && !is_numeric($_GET['adjust-points-per-match']))
) {
    _vd($_GET, '$_GET');
    die('$_GET vars: [edition], [organizer-id], [partner-id], [title], [color], [time-start], [time-end], [opponents-per-player], [repeat-partners], [player-ids], [include-final], [allow-replacements], [court-names][] are mandatory.
    [adjust-points-per-match] is an optional integer.');
}

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
            count($courtNames),
            (bool) ($_GET['fixed-teams'] ?? false)
        )) {
            $templateVersion = $candidate;
        }
    }
}

try {
    $eventDivision = new EventDivision(
        $_GET['edition'],
        $_GET['organizer-id'],
        $_GET['partner-id'],
        $_GET['title'],
        $courtNames,
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
} catch (\RuntimeException $e) {
    $playersCount = count($_GET['player-ids']);
    $courtsCount = count($courtNames);
    $versionLabel = $templateVersion !== null
        ? 'v' . $templateVersion
        : 'v' . (new TemplateMatchesRepository())->latestVersion();
    die(sprintf(
        "This division cannot be scheduled: no usable template for %d players, %d opponents, repeat %d, %d courts (%s).\n\n"
        . "The template file may be missing or marked not feasible (pairing/sort did not produce a valid schedule).\n"
        . "Choose another template version in the dropdown, or regenerate this combo with:\n"
        . "  php bin/console templates:regenerate --templates-version=N --players=%d --partners=%d --repeat=%d --fixed-teams=%d --courts=%d\n\n"
        . "Technical detail: %s",
        $playersCount,
        (int) $_GET['opponents-per-player'],
        (int) $_GET['repeat-partners'],
        $courtsCount,
        $versionLabel,
        $playersCount,
        (int) $_GET['opponents-per-player'],
        (int) $_GET['repeat-partners'],
        (int) ($_GET['fixed-teams'] ?? 0),
        $courtsCount,
        $e->getMessage()
    ));
}

Meta::set('title', "Matches | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
