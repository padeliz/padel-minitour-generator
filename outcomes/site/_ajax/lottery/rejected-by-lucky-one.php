<?php

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\Lottery;
use Arshavinel\PadelMiniTour\Table\Edition\LuckyOne;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshavinel\PadelMiniTour\Validation\SiteValidation;
use Arshwell\Monolith\StaticHandler;

if (StaticHandler::supervisor()) {
    $form = SiteValidation::run($_POST, array(
        "id_edition" => array(
            "required|int|inDB:".Edition::class
        ),
        "id_lucky_one" => array(
            "required|int|inDB:".LuckyOne::class
        ),
    ));

    if ($form->valid()) {
        $luckyOne = LuckyOne::get($form->value('id_lucky_one'), "lottery_id, drawn_participation_id");

        LuckyOne::update([
            'set' => "rejected_at = UNIX_TIMESTAMP()",
            'where' => "id_lucky_one = ?",
        ], [$luckyOne->id()]);

        $edition_division = EditionDivision::first([
            'columns' => "playing_start_time",
            'where' => "id_edition_division = (SELECT edition_division_id FROM edition_lotteries WHERE id_lottery = ?)",
        ], [$luckyOne->lottery_id]);

        $drawn_participation = Participation::first(
            [
                'columns' => Participation::PRIMARY_KEY,
                'join' => [
                    'inner',
                    EditionDivision::TABLE,
                    Participation::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.id_edition_division AND ' . EditionDivision::TABLE . '.playing_start_time = :playing_start_time'
                ],
                'where' =>
                    Participation::TABLE . ".edition_id = :edition_id AND id_participation NOT IN (
                        SELECT drawn_participation_id FROM edition_lucky_ones WHERE lottery_id IN (
                            SELECT id_lottery FROM edition_lotteries WHERE edition_id = :edition_id
                        )
                    )
                ",
                'order' => "(edition_division_id = :edition_division_id) DESC, RAND()",
            ],
            [
                ':playing_start_time' => $edition_division->playing_start_time,
                ':edition_id' => $form->value('id_edition'),
                ':edition_division_id' => $edition_division->id(),
            ]
        );

        if ($drawn_participation) {
            LuckyOne::insert(
                "lottery_id, drawn_participation_id, drawn_at, available_at",
                "?, ?, ?, ?",
                [$luckyOne->lottery_id, $drawn_participation->id(), time(), strtotime("+10 seconds")]
            );
        }
    }
}

echo $form->json();
