<?php

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryLucky;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryRule;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshavinel\PadelMiniTour\Validation\SiteValidation;
use Arshwell\Monolith\StaticHandler;

if (StaticHandler::supervisor()) {
    $form = SiteValidation::run($_POST, array(
        "id_edition" => array(
            "required|int|inDB:".Edition::class
        ),
        "id_lucky" => array(
            "required|int|inDB:" . LotteryLucky::class
        ),
    ));

    if ($form->valid()) {
        $luckyOne = LotteryLucky::first(
            [
                'columns' => "lottery_rule_id, edition_division_id",
                'join' => [
                    'INNER',
                    LotteryRule::TABLE,
                    LotteryLucky::TABLE . '.lottery_rule_id = ' . LotteryRule::TABLE . '.' . LotteryRule::PRIMARY_KEY
                ],
                "where" => LotteryLucky::TABLE . '.' . LotteryLucky::PRIMARY_KEY . ' = ?'
            ],
            [$form->value('id_lucky')]
        );

        LotteryLucky::update([
            'set' => "rejected_at = UNIX_TIMESTAMP()",
            'where' => "id_lucky = ?",
        ], [$luckyOne->id()]);

        $edition_division = null;
        if ($luckyOne->edition_division_id) {
            $edition_division = EditionDivision::get($luckyOne->edition_division_id, "playing_start_time");
        }

        $drawn_participation = Participation::first(
            [
                'columns' => Participation::PRIMARY_KEY,
                'joins' => [
                    [
                        'type' => 'LEFT',
                        'table' => EditionDivision::TABLE,
                        'on' => Participation::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY
                    ],
                    [
                        'type' => 'INNER',
                        'table' => Edition::TABLE,
                        'on' => EditionDivision::TABLE . '.edition_id = ' . Edition::TABLE . '.' . Edition::PRIMARY_KEY
                    ],
                ],

                // participant didn't receive already a prize to this edition
                'where' => 'absence_reason IS NULL AND (' . Edition::TABLE . '.date <= CURRENT_DATE() OR ' . EditionDivision::TABLE . '.playing_start_time <= :playing_start_time) AND ' .
                    Participation::TABLE . ".edition_id = :edition_id AND id_participation NOT IN (
                        SELECT drawn_participation_id FROM edition_lottery_luckies WHERE lottery_rule_id IN (
                            SELECT id_lottery_rule FROM edition_lottery_rules WHERE lottery_id IN (
                                SELECT id_lottery FROM edition_lotteries WHERE edition_id = :edition_id
                            )
                        )
                    )
                ",

                // priority of picking from the certain division (if not NULL)
                'order' => "(edition_division_id = :edition_division_id) DESC, RAND()",
            ],
            [
                ':playing_start_time' => $edition_division ? $edition_division->playing_start_time : date('H:i:s'),
                ':edition_id' => $form->value('id_edition'),
                ':edition_division_id' => $edition_division ? $edition_division->id() : null, // it can be null
            ]
        );

        if ($drawn_participation) {
            LotteryLucky::insert(
                "lottery_rule_id, drawn_participation_id, drawn_at, available_at",
                "?, ?, ?, ?",
                [$luckyOne->lottery_rule_id, $drawn_participation->id(), time(), strtotime("+10 seconds")]
            );
        }
    }
}

echo $form->json();
