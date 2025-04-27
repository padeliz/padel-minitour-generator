<?php

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Location;
use Arshwell\Monolith\Meta;

Meta::set('title', "Lottery | ARSH Padel MiniTour");
Meta::set('description', "Draw lucky ones for edition's lottery.");
Meta::set('keywords', "padel, lottery, minitour");

$editions = Edition::select([
    'columns' => Edition::TABLE . ".name, date, " . Location::TABLE . '.name AS location_name',
    'join' => [
        "LEFT",
        Location::TABLE,
        Edition::TABLE . '.location_id = ' . Location::TABLE . '.id_location'
    ],
    'where' => "id_edition IN (SELECT edition_id FROM edition_lotteries GROUP BY edition_id)",
    'order' => "date DESC"
]);
