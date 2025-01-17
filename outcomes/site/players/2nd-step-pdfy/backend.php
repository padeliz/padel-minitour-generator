<?php

use Arshwell\Monolith\Web;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

if (
    empty($_GET['edition']) || is_array($_GET['edition']) ||
    empty($_GET['partner-id']) || !is_numeric($_GET['partner-id']) ||
    empty($_GET['title']) || !is_string($_GET['title']) ||
    empty($_GET['players']) || !is_array($_GET['players']) ||
    !isset($_GET['include-scores']) || !is_numeric($_GET['include-scores']) ||
    !isset($_GET['fixed-teams']) || !is_numeric($_GET['fixed-teams'])
) {
    die('$_GET vars [edition], [partner-id], [title], [players], [include-scores], [fixed-teams] are mandatory.');
}

ini_set('max_execution_time', ini_get('max_execution_time') + (2 * count($_GET['players'])));


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
$mpdf->SetFooter([
    'odd' => [
        'C' => [
            'content' => call_user_func(function () {
                if (!empty($_GET['include-scores'])) {
                    return 'Final is played by the 4 players with the most matches won.';
                }
            }),
        ],
    ]
]);

$mpdf->WriteHTML(
    file_get_contents(Web::url('site.players.1st-step-beautify', null, null, 0, $_GET))
);

$mpdf->Output('A2 Hârtie Color Laminat - ' . $_GET['title'] . ' Ranking.pdf', Destination::INLINE);
