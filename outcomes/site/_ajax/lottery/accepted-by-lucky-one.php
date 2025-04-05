<?php

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\Lottery;
use Arshavinel\PadelMiniTour\Table\Edition\LuckyOne;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshavinel\PadelMiniTour\Validation\SiteValidation;
use Arshwell\Monolith\DB;
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
        $edition = Edition::get($form->value('id_edition'), "date");
        $luckyOne = LuckyOne::get($form->value('id_lucky_one'));

        $luckyOne->accepted_at = time();
        $luckyOne->edit();

        $lotteries = Lottery::select([
            'columns' => "playing_start_time, edition_division_id, total_draws_nr",
            'join' => [
                'inner',
                EditionDivision::TABLE,
                Lottery::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.id_edition_division'
            ],
            'where' => Lottery::TABLE . ".edition_id = ?",
            'order' => "playing_start_time ASC, `orderliness` ASC, id_lottery ASC"
        ], [$form->value('id_edition')]);

        foreach ($lotteries as $lottery) {
            if ($lottery->total_draws_nr > LuckyOne::count('lottery_id = ? AND accepted_at IS NOT NULL', [$lottery->id()])) {
                // not all draws have been picked-up yet

                $luckyOne = LuckyOne::first([
                    'columns' => LuckyOne::PRIMARY_KEY,
                    'where' => "lottery_id = ? AND accepted_at IS NULL AND rejected_at IS NULL"
                ], [$lottery->id()]);

                if (!$luckyOne) {
                    $luckyOne = new LuckyOne();

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
                            ':playing_start_time' => $lottery->playing_start_time,
                            ':edition_id' => $form->value('id_edition'),
                            ':edition_division_id' => $lottery->edition_division_id
                        ]
                    );

                    if (!$drawn_participation) {
                        continue; // skip this lottery prize
                    }

                    $editionDivisionsEndingTimes = DB::column(
                        EditionDivision::class,
                        "playing_end_time",
                        "edition_id = ? AND playing_start_time = ?",
                        [$form->value('id_edition'), $lottery->playing_start_time]
                    );

                    // 30 minutes before any division ends playing
                    $adjustedEnd = date("H:i:s", strtotime(min($editionDivisionsEndingTimes) . " -30 minutes"));

                    // in case you run this script before the edition started
                    $adjustedNow = max(strtotime($edition->date . " " . $lottery->playing_start_time), time());

                    $lotteryTimeMinutesLeft = (strtotime($adjustedEnd) - $adjustedNow) / 60;

                    // total draws for all divisions happening in the same time
                    $sum_of_total_draws_nr = Lottery::field(
                        "SUM(total_draws_nr)",
                        "edition_id = ? AND edition_division_id IN (SELECT id_edition_division FROM edition_divisions WHERE playing_start_time = ?)",
                        [$form->value('id_edition'), $lottery->playing_start_time]
                    );
                    // remaining draws for all divisions happening in the same time
                    $remaining_draws_nr = $sum_of_total_draws_nr - LuckyOne::count(
                        "rejected_at IS NULL AND lottery_id IN (
                            SELECT id_lottery FROM edition_lotteries
                                WHERE edition_id = ? AND edition_division_id IN (
                                    SELECT id_edition_division FROM edition_divisions WHERE playing_start_time = ?
                                )
                        )",
                        [$form->value('id_edition'), $lottery->playing_start_time]
                    );

                    $luckyOne->lottery_id = $lottery->id();
                    $luckyOne->drawn_participation_id = $drawn_participation->id();
                    $luckyOne->drawn_at = time();
                    $luckyOne->available_at = strtotime($edition->date . " " . $lottery->playing_start_time . " + " . round($lotteryTimeMinutesLeft / $remaining_draws_nr) . " minutes");

                    $luckyOne->add();
                }

                break;
            }
        }
    }
}

echo $form->json();
