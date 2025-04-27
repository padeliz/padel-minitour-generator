<?php

use Arshavinel\PadelMiniTour\DTO\PdfPlayer;
use Arshavinel\PadelMiniTour\Table\Division;
use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\Lottery;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryLucky;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryRule;
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

$next_edition = Edition::first([
    'columns' => "name, date",
    'where' => "date > ?",
    'order' => "date ASC"
], [date('Y-m-d')]);

Meta::set('title', "Lottery | ARSH Padel MiniTour");
Meta::set('description', "Draw lucky ones for edition's lottery.");
Meta::set('keywords', "padel, lottery, minitour");


$luckyOne = LotteryLucky::first([
    'columns' => "edition_lottery_luckies.lottery_rule_id, edition_lottery_rules.lottery_id, players.name AS player_name, available_at",
    'joins' => [
        [
            'type' => 'INNER',
            'table' => LotteryRule::TABLE,
            'on' => LotteryLucky::TABLE . '.lottery_rule_id = ' . LotteryRule::TABLE . '.' . LotteryRule::PRIMARY_KEY
        ],
        [
            'type' => 'INNER',
            'table' => Lottery::TABLE,
            'on' => LotteryRule::TABLE . '.lottery_id = ' . Lottery::TABLE . '.id_lottery AND ' . Lottery::TABLE . '.edition_id = ?'
        ],
        [
            'type' => 'INNER',
            'table' => Participation::TABLE,
            'on' => LotteryLucky::TABLE . '.drawn_participation_id = ' . Participation::TABLE . '.id_participation'
        ],
        [
            'type' => 'INNER',
            'table' => Player::TABLE,
            'on' => Participation::TABLE . '.player_id = ' . Player::TABLE . '.id_player'
        ],
    ],
    'where' => "accepted_at IS NULL AND rejected_at IS NULL",
    'order' => "id_lucky DESC"
], [Web::param('id')]);

if (!$luckyOne) {
    $luckiesCount = LotteryLucky::count(
        [
            'joins' => [
                [
                    'type' => 'inner',
                    'table' => LotteryRule::TABLE,
                    'on' => LotteryLucky::TABLE . '.lottery_rule_id = ' . LotteryRule::TABLE . '.' . LotteryRule::PRIMARY_KEY
                ],
                [
                    'type' => 'inner',
                    'table' => EditionDivision::TABLE,
                    'on' => LotteryRule::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY
                ],
            ],
            'where' => EditionDivision::TABLE . '.edition_id = ?'
        ],
        [Web::param('id')]
    );

    if (!$luckiesCount) {
        // zero draws for this edition so far;
        // create the first one.

        $luckyOne = new LotteryLucky();

        $first_lottery_rule = LotteryRule::first(
            [
                'columns' => "lottery_id, edition_division_id, playing_start_time",
                'joins' => [
                    [
                        'type' => 'LEFT',
                        'table' => EditionDivision::TABLE,
                        'on' => LotteryRule::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY
                    ],
                    [
                        'type' => 'INNER',
                        'table' => Lottery::TABLE,
                        'on' => LotteryRule::TABLE . '.lottery_id = ' . Lottery::TABLE . '.id_lottery'
                    ],
                ],
                'where' => Lottery::TABLE . ".edition_id = ?",
                'order' => "(" . EditionDivision::TABLE . ".playing_start_time IS NULL), "
                    . EditionDivision::TABLE . ".playing_start_time ASC, "
                    . LotteryRule::TABLE . ".orderliness ASC, "
                    . LotteryRule::TABLE . ".id_lottery_rule ASC",
            ],
            [Web::param('id')]
        );

        $luckyOne->lottery_id = $first_lottery_rule->lottery_id;
        $luckyOne->lottery_rule_id = $first_lottery_rule->id();

        $drawn_participation = Participation::first([
            'columns' => "players.name AS player_name",
            'join' => [
                'INNER',
                Player::TABLE,
                Participation::TABLE . '.player_id = ' . Player::TABLE . '.id_player'
            ],
            'where' => "
                absence_reason IS NULL AND edition_id = ? AND id_participation NOT IN (
                    SELECT drawn_participation_id FROM edition_lottery_luckies
                )
            ",
            'order' => "(edition_division_id = ?) DESC, RAND()",
        ], [Web::param('id'), $first_lottery_rule->edition_division_id]);

        $luckyOne->drawn_participation_id = $drawn_participation->id();
        $luckyOne->player_name = $drawn_participation->player_name;
        $luckyOne->drawn_at = time();

        // 20 minutes after all divisions started playing
        $luckyOne->available_at = max(
            strtotime("now +10 seconds"), // in case edition already started
            strtotime($edition->date . ' ' . $first_lottery_rule->playing_start_time . " +20 minutes")
        );

        $luckyOne->add();
    }
}

if ($luckyOne) {
    $luckyOne->lottery = Lottery::first([
        'columns' => "
            edition_lotteries.edition_id,
            edition_lotteries.prize_quantity,
            COALESCE(prizes_from_clubs.image, prizes.image) AS image,
            COALESCE(prizes_from_clubs.template, prizes.template) AS template,
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

    $luckyOne->lottery->box_1_text = str_replace(
        ['{{edition.next.name}}', '{{edition.next.date.short}}'],
        [$next_edition->name, date('d M Y', strtotime($next_edition->date))],
        $luckyOne->lottery->box_1_text
    );

    $luckyOne->division = Division::first([
        'columns' => "name, color",
        'joins' => [
            [
                'type' => 'INNER',
                'table' => EditionDivision::TABLE,
                'on' => Division::TABLE . '.' . Division::PRIMARY_KEY . ' = ' . EditionDivision::TABLE . '.division_id'
            ],
            [
                'type' => 'INNER',
                'table' => Participation::TABLE,
                'on' => EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY . ' = ' . Participation::TABLE . '.edition_division_id'
            ],
            [
                'type' => 'INNER',
                'table' => LotteryLucky::TABLE,
                'on' => Participation::TABLE . '.' . Participation::PRIMARY_KEY . ' = ' . LotteryLucky::TABLE . '.drawn_participation_id'
            ],
        ],
        'where' => LotteryLucky::TABLE . '.' . LotteryLucky::PRIMARY_KEY . ' = ?'
    ], [$luckyOne->id()]);

    // each of the same type is
    $luckyOne->lottery->prize_index = 1 + LotteryLucky::count([
        'join' => [
            'inner',
            LotteryRule::TABLE,
            LotteryLucky::TABLE . '.lottery_rule_id = ' . LotteryRule::TABLE . '.' . LotteryRule::PRIMARY_KEY
        ],
        'where' => LotteryRule::TABLE . ".lottery_id = ? AND " . LotteryLucky::TABLE . ".accepted_at IS NOT NULL"
    ], [$luckyOne->lottery->id()]);

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
    $allLotteryLuckies = LotteryLucky::select([
        'columns' => LotteryRule::TABLE . ".lottery_id, players.name AS player_name, available_at, " . Division::TABLE . '.name AS division_name, ' . Division::TABLE . '.color AS division_color',
        'joins' => [
            [
                'type' => 'INNER',
                'table' => LotteryRule::TABLE,
                'on' => LotteryLucky::TABLE . '.lottery_rule_id = ' . LotteryRule::TABLE . '.' . LotteryRule::PRIMARY_KEY,
            ],
            [
                'type' => 'INNER',
                'table' => Lottery::TABLE,
                'on' => LotteryRule::TABLE . '.lottery_id = ' . Lottery::TABLE . '.id_lottery AND ' . Lottery::TABLE . '.edition_id = ?',
            ],
            [
                'type' => 'INNER',
                'table' => Participation::TABLE,
                'on' => LotteryLucky::TABLE . '.drawn_participation_id = ' . Participation::TABLE . '.id_participation',
            ],
            [
                'type' => 'INNER',
                'table' => Player::TABLE,
                'on' => Participation::TABLE . '.player_id = ' . Player::TABLE . '.id_player'
            ],
            [
                'type' => 'INNER',
                'table' => EditionDivision::TABLE,
                'on' => EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY . ' = ' . Participation::TABLE . '.edition_division_id'
            ],
            [
                'type' => 'INNER',
                'table' => Division::TABLE,
                'on' => Division::TABLE . '.' . Division::PRIMARY_KEY . ' = ' . EditionDivision::TABLE . '.division_id'
            ],
        ],
        'where' => "accepted_at IS NOT NULL AND rejected_at IS NULL",
        'order' => "id_lucky ASC"
    ], [Web::param('id')]);

    foreach ($allLotteryLuckies as $allLotteryLucky) {
        $allLotteryLucky->pdfPlayer = new PdfPlayer($allLotteryLucky->player_name);
    }
}
