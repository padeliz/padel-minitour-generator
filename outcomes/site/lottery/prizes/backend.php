<?php

use Arshavinel\PadelMiniTour\Service\LotteryPrizesViewBuilder;
use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\Lottery;
use Arshavinel\PadelMiniTour\Table\Location;
use Arshwell\Monolith\Meta;
use Arshwell\Monolith\Web;

$edition = Edition::first(
    [
        'columns' => Edition::TABLE . ".id_edition, " . Edition::TABLE . ".name, date, club_id",
        'join' => [
            'inner',
            Location::TABLE,
            Edition::TABLE . '.location_id = ' . Location::TABLE . '.id_location'
        ],
        'where' => "id_edition = ?",
    ],
    [Web::param('id')]
);

if (!$edition) {
    Web::go('site.lottery.list');
    http_response_code(301);
    exit;
}

$lotteryCount = Lottery::count([
    'where' => 'edition_id = ?',
], [Web::param('id')]);

if (!$lotteryCount) {
    Web::go('site.lottery.list');
    http_response_code(301);
    exit;
}

Meta::set('title', "Lottery prizes | ARSH Padel MiniTour");
Meta::set('description', "Prizes and lucky winners for edition's lottery.");
Meta::set('keywords', "padel, lottery, minitour, prizes");

$editionDate = Edition::field('date', Edition::TABLE . '.id_edition = ?', [Web::param('id')]);

$next_edition = Edition::first([
    'columns' => 'name, date',
    'where' => Edition::TABLE . '.date > ?',
    'order' => Edition::TABLE . '.date ASC',
], [$editionDate ?: date('Y-m-d')]);

$intervals = (new LotteryPrizesViewBuilder())->build(
    (int) Web::param('id'),
    $edition->club_id !== null ? (int) $edition->club_id : null,
    $next_edition
);
