<?php

use Arshwell\Monolith\Web;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

if (

    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['matches']) || !is_array($_GET['matches']) ||
    empty($_GET['time-start']) || !is_string($_GET['time-start']) ||
    empty($_GET['time-end']) || !is_string($_GET['time-end'])
) {
    die('$_GET vars [edition], [partner-id], [title], [time-start], [time-end] and [matches] are mandatory.');
}

ini_set('max_execution_time', ini_get('max_execution_time') + (2 * count($_GET['matches'])));

$countMatches = count($_GET['matches']);
$hasDemonstrativeMatch = (bool) ($_GET['demonstrative-match'] ?? false);

if ($hasDemonstrativeMatch) {
    $countMatches++;
}


$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A2-P',
    'autoPageBreak' => false,
    'nonPrintMargin' => 0,
    'simpleTable' => true,
    'tableMinSizePriority' => true,
    // 'shrink_tables_to_fit' => 1,
    // 'defaultfooterfontsize' => 14,
    // 'footer_line_spacing' => 5,
    'keepColumns' => true,
    'margin_left' => 5,
    'margin_right' => 5,
    'margin_top' => 0,
    'margin_bottom' => 0,
]);

$mpdf->SetMargins(0, 0, 0);

if ($countMatches < 25) {
    $mpdf->SetFooter([
        'odd' => [
            'L' => [
                'content' => call_user_func(function () {
                    if (!empty($_GET['include-scores'])) {
                        return '<b>If someone is missing</b> from the next match, you can take their place <u>without receiving points</u> for that match.';
                    } else {
                        return "The score doesn't matter, but if you prefer, you can keep it during the match.";
                    }
                }),
            ],
            'R' => [
                'content' => 'Two serves per player/team. The team on the left serves first.',
            ],
        ]
    ]);
}

$mpdf->WriteHTML(
    file_get_contents(Web::url('site.matches.2nd-step-beautify', null, null, 0, $_GET))
);

$mpdf->Output('A2 Hârtie Color Laminat - ' . $_GET['title'] . ' Matches.pdf', Destination::INLINE);
