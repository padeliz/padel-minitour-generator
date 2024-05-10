<?php

use Arshwell\Monolith\Web;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

if (empty($_GET['title']) || empty($_GET['matches']) || !is_array($_GET['matches'])) {
    die('$_GET vars [title] and [matches] are mandatory.');
}

ini_set('max_execution_time', ini_get('max_execution_time') + (2 * count($_GET['matches'])));


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
]);

$mpdf->SetMargins(0, 0, 0);
$mpdf->SetFooter([
    'odd' => [
        'L' => [
            'content' => 'Dacă cineva lipsește din meciul curent, îi poți lua locul, fără să primești puncte pentru acest meci.',
        ],
        'R' => [
            'content' => 'Echipa din stânga servește prima.',
        ],
    ]
]);

$mpdf->WriteHTML(
    file_get_contents(Web::url('site.matches.2nd-step-beautify', null, null, 0, $_GET))
);

$mpdf->Output('MiniTour ' . $_GET['title'] . '.pdf', Destination::INLINE);
