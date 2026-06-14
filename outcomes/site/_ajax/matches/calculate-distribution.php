<?php

use Arshavinel\PadelMiniTour\Service\PlayerDistributionScorer;
use Arshavinel\PadelMiniTour\Validation\SiteValidation;

/**
 * Computes per-player distribution scores for a given match ordering.
 *
 * The matches page calls this on initial render and after every drag-drop reorder. The single
 * source of truth for the algorithm is {@see PlayerDistributionScorer}, so the CLI table
 * (`Min Dist.` / `Avg Dist.` columns) and the web UI (per-player distribution-index cells)
 * always agree.
 *
 * Request:
 *   - `form_token` (CSRF, enforced by `SiteValidation::run`)
 *   - `ajax_token` (CSRF, enforced by the framework's AJAX route gate)
 *   - `matches`    (numerically-indexed array; each entry is `[[p1,p2],[p3,p4]]` or the wider
 *                   `[[p1,p2],[p3,p4],startTime,endTime]` shape used by the page's hidden inputs;
 *                   only the first two slots are read here)
 *   - `player_ids` (numerically-indexed array of int player IDs)
 *
 * Response (under `values`):
 *   - `perPlayer[playerId]` = `{ score, percentage, cssClass }` for each requested player
 *   - `aggregate` = `{ min, avg }` cross-player scores
 */
$form = SiteValidation::run($_POST, []);

if (
    $form->valid()
    && isset($_POST['matches']) && is_array($_POST['matches'])
    && isset($_POST['player_ids']) && is_array($_POST['player_ids'])
) {
    // Coerce matches to the shape the scorer expects: array<int, array{0: array{0:int,1:int}, 1: array{0:int,1:int}}>.
    // We tolerate trailing entries (start/end times) that the page's hidden inputs include but the
    // scorer ignores; missing or non-numeric IDs become 0 (a sentinel that no real player ID will
    // collide with -- player IDs are positive in this app).
    $matches = [];
    foreach ($_POST['matches'] as $match) {
        if (!is_array($match) || !isset($match[0]) || !isset($match[1]) || !is_array($match[0]) || !is_array($match[1])) {
            continue;
        }
        $matches[] = [
            [
                isset($match[0][0]) ? (int) $match[0][0] : 0,
                isset($match[0][1]) ? (int) $match[0][1] : 0,
            ],
            [
                isset($match[1][0]) ? (int) $match[1][0] : 0,
                isset($match[1][1]) ? (int) $match[1][1] : 0,
            ],
        ];
    }

    $playerIds = array_values(array_map('intval', $_POST['player_ids']));

    $scorer = new PlayerDistributionScorer();
    $aggregate = $scorer->scoreAll($playerIds, $matches);

    $perPlayer = [];
    foreach ($aggregate['perPlayer'] as $pid => $score) {
        $perPlayer[$pid] = array_merge(
            ['score' => $score],
            $scorer->classify($score)
        );
    }

    $form->perPlayer = $perPlayer;
    $form->aggregate = ['min' => $aggregate['min'], 'avg' => $aggregate['avg']];
}

echo $form->json();
