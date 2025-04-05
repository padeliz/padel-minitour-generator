<?php

use Arshavinel\PadelMiniTour\DTO\PdfPlayer;
use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\Lottery;
use Arshavinel\PadelMiniTour\Table\Edition\LuckyOne;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshavinel\PadelMiniTour\Table\Location;
use Arshavinel\PadelMiniTour\Table\Player;
use Arshavinel\PadelMiniTour\Table\Prize;
use Arshavinel\PadelMiniTour\Table\PrizeFromClub;
use Arshwell\Monolith\Meta;
use Arshwell\Monolith\Web;


$edition = Edition::first(
    [
        'columns' => Edition::TABLE . ".name, date, club_id",
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
    http_response_code(301); // permanent redirect
    exit;
}

Meta::set('title', "Lottery | ARSH Padel MiniTour");
Meta::set('description', "Draw lucky ones for edition's lottery.");
Meta::set('keywords', "padel, lottery, minitour");


$luckyOne = LuckyOne::first([
    'columns' => "edition_lucky_ones.lottery_id, players.name AS player_name, available_at",
    'join' => [
        'INNER',
        Lottery::TABLE,
        LuckyOne::TABLE . '.lottery_id = ' . Lottery::TABLE . '.id_lottery AND ' . Lottery::TABLE . '.edition_id = ?',
        'join' => [
            'INNER',
            Participation::TABLE,
            LuckyOne::TABLE . '.drawn_participation_id = ' . Participation::TABLE . '.id_participation',
            'join' => [
                'INNER',
                Player::TABLE,
                Participation::TABLE . '.player_id = ' . Player::TABLE . '.id_player'
            ],
        ],
    ],
    'where' => "accepted_at IS NULL AND rejected_at IS NULL",
    'order' => "id_lucky_one DESC"
], [Web::param('id')]);

if (!$luckyOne && !LuckyOne::count("lottery_id IN (SELECT id_lottery FROM edition_lotteries WHERE edition_id = ?)", [Web::param('id')])) {
    // zero draws for this edition so far;
    // create the first one.

    $luckyOne = new LuckyOne();

    $lottery = Lottery::first([
        'columns' => "edition_division_id, playing_start_time",
        'join' => [
            'inner',
            EditionDivision::TABLE,
            Lottery::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.id_edition_division'
        ],
        'where' => Lottery::TABLE . ".edition_id = ?",
        'order' => "playing_start_time ASC, `orderliness` ASC, id_lottery ASC"
    ], [Web::param('id')]);

    $luckyOne->lottery_id = $lottery->id();

    $drawn_participation = Participation::first([
        'columns' => "players.name AS player_name",
        'join' => [
            'INNER',
            Player::TABLE,
            Participation::TABLE . '.player_id = ' . Player::TABLE . '.id_player'
        ],
        'where' => "
            edition_id = ? AND id_participation NOT IN (
                SELECT drawn_participation_id FROM edition_lucky_ones
            )
        ",
        'order' => "(edition_division_id = ?) DESC, RAND()",
    ], [Web::param('id'), $lottery->edition_division_id]);

    $luckyOne->drawn_participation_id = $drawn_participation->id();
    $luckyOne->player_name = $drawn_participation->player_name;
    $luckyOne->drawn_at = time();

    // 20 minutes after all divisions started playing
    $luckyOne->available_at = strtotime($edition->date .' '. $lottery->playing_start_time . " +20 minutes");

    $luckyOne->add();
}

if ($luckyOne) {
    $luckyOne->lottery = Lottery::first([
        'columns' => "
            edition_lotteries.edition_id,
            edition_lotteries.total_draws_nr,
            COALESCE(prizes_from_clubs.image, prizes.image) AS image,
            COALESCE(prizes_from_clubs.box_1_text, prizes.box_1_text) AS box_1_text,
            COALESCE(prizes_from_clubs.box_1_bg_color, prizes.box_1_bg_color) AS box_1_bg_color,
            COALESCE(prizes_from_clubs.box_1_text_color, prizes.box_1_text_color) AS box_1_text_color,
            COALESCE(prizes_from_clubs.box_2_text, prizes.box_2_text) AS box_2_text,
            COALESCE(prizes_from_clubs.box_2_bg_color, prizes.box_2_bg_color) AS box_2_bg_color,
            COALESCE(prizes_from_clubs.box_2_text_color, prizes.box_2_text_color) AS box_2_text_color
        ",
        'join' => [
            'INNER',
            Prize::TABLE,
            Lottery::TABLE . '.prize_id = ' . Prize::TABLE . '.id_prize',
            'join' => [
                'LEFT',
                PrizeFromClub::TABLE,
                Prize::TABLE . '.id_prize = ' . PrizeFromClub::TABLE . '.prize_id AND (club_id IS NULL OR club_id = ?)'
            ]
        ],
        'where' => 'id_lottery = ?'
    ], [$edition->club_id, $luckyOne->lottery_id]);

    // each of the same type is
    $luckyOne->lottery->prize_index = 1 + LuckyOne::count("lottery_id = ? AND accepted_at IS NOT NULL", [$luckyOne->lottery->id()]);

    $pdfPlayer = new PdfPlayer($luckyOne->player_name);

    function secondsToTime($seconds) {
        $days = floor($seconds / 86400); // 1 day = 86400 seconds
        $seconds %= 86400;

        $hours = floor($seconds / 3600); // 1 hour = 3600 seconds
        $seconds %= 3600;

        $minutes = floor($seconds / 60); // 1 minute = 60 seconds
        $seconds %= 60;

        return [
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds
        ];
    }

    $timeLeftInTotalSeconds = $luckyOne->available_at - time();
    $timeLeft = secondsToTime($timeLeftInTotalSeconds);
}

if (!$luckyOne) {
    $allLuckyOnes = LuckyOne::select([
        'columns' => "edition_lucky_ones.lottery_id, players.name AS player_name, available_at",
        'join' => [
            'INNER',
            Lottery::TABLE,
            LuckyOne::TABLE . '.lottery_id = ' . Lottery::TABLE . '.id_lottery AND ' . Lottery::TABLE . '.edition_id = ?',
            'join' => [
                'INNER',
                Participation::TABLE,
                LuckyOne::TABLE . '.drawn_participation_id = ' . Participation::TABLE . '.id_participation',
                'join' => [
                    'INNER',
                    Player::TABLE,
                    Participation::TABLE . '.player_id = ' . Player::TABLE . '.id_player'
                ],
            ],
        ],
        'where' => "accepted_at IS NOT NULL AND rejected_at IS NULL",
        'order' => "id_lucky_one DESC"
    ], [Web::param('id')]);

    foreach ($allLuckyOnes as $allLuckyOne) {
        $allLuckyOne->pdfPlayer = new PdfPlayer($allLuckyOne->player_name);
    }
}
