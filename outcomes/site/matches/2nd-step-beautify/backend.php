<?php

use Arshwell\Monolith\Meta;

if (empty($_GET['matches']) || !is_array($_GET['matches']) || empty($_GET['title'])) {
    die('$_GET vars [title] and [matches] are mandatory.');
}

$countMatches = count($_GET['matches']);

if ($countMatches >= 28) {
    $marginTop = 14;
} elseif ($countMatches >= 24) {
    $marginTop = 40;
} else {
    $marginTop = 50;
}


function getFontSize(string $name) {
    $max = 43;

    if (strlen($name) > 10) {
        return $max - (3 * (strlen($name) - 10));
    }

    return $max;
}

Meta::set('title', "Matches beautified | ARSH Padel MiniTour");
Meta::set('description', "Fun short padel matches.");
Meta::set('keywords', "padel, players, minitour");
