<?php

use Arshwell\Monolith\Web;
use Mpdf\Mpdf;

if (empty($_GET['matches']) || !is_array($_GET['matches']) || empty($_GET['title'])) {
    die('$_GET vars [title] and [matches] are mandatory.');
}

ini_set('max_execution_time', ini_get('max_execution_time') + (2 * count($_GET['matches'])));


$mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A2-P']);

$mpdf->WriteHTML(
    file_get_contents(Web::url('site.matches.2nd-step-beautify', NULL, NULL, 0, $_GET))
);

$mpdf->Output();
