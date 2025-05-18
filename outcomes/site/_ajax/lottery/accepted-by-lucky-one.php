<?php

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\Lottery;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryLucky;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryRule;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshavinel\PadelMiniTour\Validation\SiteValidation;
use Arshwell\Monolith\DB;
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
        $edition = Edition::get($form->value('id_edition'), "date");
        $luckyOne = LotteryLucky::get($form->value('id_lucky'));

        $luckyOne->accepted_at = time();
        $luckyOne->edit();

        /**
         * Get all lottery rules for this edition.
         */
        $lotteryRules = LotteryRule::select(
            [
                'columns' => "lottery_id, edition_division_id, rule_prize_quantity",
                'joins' => [
                    [
                        'type' => 'LEFT',
                        'table' => EditionDivision::TABLE,
                        'on' => LotteryRule::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY
                    ],
                    [
                        'type' => 'INNER',
                        'table' => Lottery::TABLE,
                        'on' => LotteryRule::TABLE . '.lottery_id = ' . Lottery::TABLE . '.' . Lottery::PRIMARY_KEY
                    ],
                ],
                'where' => Lottery::TABLE . ".edition_id = ?",
                'order' => "(" . EditionDivision::TABLE . ".playing_start_time IS NULL), "
                    . EditionDivision::TABLE . ".playing_start_time ASC, "
                    . LotteryRule::TABLE . ".orderliness ASC, "
                    . LotteryRule::TABLE . ".id_lottery_rule ASC",
            ],
            [$form->value('id_edition')]
        );

        foreach ($lotteryRules as $lotteryRule) {
            if ($lotteryRule->rule_prize_quantity > LotteryLucky::countWhere('lottery_rule_id = ? AND accepted_at IS NOT NULL', [$lotteryRule->id()])) {
                // not all draws have been picked-up yet

                $luckyOne = LotteryLucky::first([
                    'columns' => LotteryLucky::PRIMARY_KEY,
                    'where' => "lottery_rule_id = ? AND accepted_at IS NULL AND rejected_at IS NULL"
                ], [$lotteryRule->id()]);

                if (!$luckyOne) {

                    $luckyOne = new LotteryLucky();

                    $current_time = date('H:i:s');

                    $drawn_participation = Participation::first(
                        [
                            'columns' => Participation::PRIMARY_KEY . ', ' . EditionDivision::TABLE . '.playing_start_time',
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
                            ':playing_start_time' => $current_time,
                            ':edition_id' => $form->value('id_edition'),
                            ':edition_division_id' => $lotteryRule->edition_division_id // it can be null
                        ]
                    );


                    if (!$drawn_participation) {
                        continue; // skip this lottery prize
                    }


                    // total draws for all divisions that already started
                    $sum_of_prize_quantity = LotteryRule::field(
                        "SUM(rule_prize_quantity)",
                        "lottery_id IN (SELECT id_lottery FROM edition_lotteries WHERE edition_id = ?) AND (edition_division_id IS NULL OR edition_division_id IN (SELECT id_edition_division FROM edition_divisions WHERE playing_start_time <= ? OR playing_start_time = ?))",
                        [$form->value('id_edition'), $current_time, $drawn_participation->playing_start_time]
                    );

                    // remaining draws for all divisions that already started
                    $remaining_draws_nr = $sum_of_prize_quantity - LotteryLucky::count(
                        [
                            'joins' => [
                                [
                                    'type' => 'INNER',
                                    'table' => LotteryRule::TABLE,
                                    'on' => LotteryLucky::TABLE . '.lottery_rule_id = ' . LotteryRule::TABLE . '.' . LotteryRule::PRIMARY_KEY
                                ],
                                [
                                    'type' => 'INNER',
                                    'table' => Lottery::TABLE,
                                    'on' => LotteryRule::TABLE . '.lottery_id = ' . Lottery::TABLE . '.' . Lottery::PRIMARY_KEY
                                ],
                                [
                                    'type' => 'INNER',
                                    'table' => EditionDivision::TABLE,
                                    'on' => LotteryRule::TABLE . '.edition_division_id = ' . EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY
                                ],
                            ],
                            'where' => LotteryLucky::TABLE . ".rejected_at IS NULL
                            AND " . Lottery::TABLE . ".edition_id = ?
                            AND " . EditionDivision::TABLE . ".edition_id = ?
                            AND (" . EditionDivision::TABLE . ".playing_start_time <= ? OR " . EditionDivision::TABLE . ".playing_start_time = ?)"
                        ],
                        [$form->value('id_edition'), $form->value('id_edition'), $current_time, $drawn_participation->playing_start_time]
                    );

                    $adjustedNow = max( // in case you run this script before the edition started
                        strtotime($edition->date . " " . $drawn_participation->playing_start_time),
                        time()
                    );

                    $editionDivisionsEndingTimes = DB::column(
                        EditionDivision::class,
                        "playing_end_time",
                        "edition_id = ? AND (playing_start_time <= ? OR playing_start_time = ?)",
                        [$form->value('id_edition'), $current_time, $drawn_participation->playing_start_time]
                    );

                    if ($editionDivisionsEndingTimes) {
                        $soonestDivisionFinish = min(
                            array_filter($editionDivisionsEndingTimes, fn($v) => $v >= $drawn_participation->playing_start_time)
                        );
                        $adjustedEnd = max(
                            $current_time, // in case edition almost finished
                            date("H:i:s", strtotime($soonestDivisionFinish . " -30 minutes")) // some buffer at the end
                        );
                        $lotteryRuleSecondsLeft = (strtotime($adjustedEnd) - $adjustedNow);

                        $secondsToCount = round($lotteryRuleSecondsLeft / $remaining_draws_nr);
                    } else {
                        // all divisions already finished

                        $secondsToCount = 10;
                    }

                    $luckyOne->lottery_rule_id = $lotteryRule->id();
                    $luckyOne->drawn_participation_id = $drawn_participation->id();
                    $luckyOne->drawn_at = time();
                    $luckyOne->available_at = max(
                        strtotime("now +10 seconds"), // in case edition already finished
                        $adjustedNow + $secondsToCount
                    );

                    $luckyOne->add();
                }

                break;
            }
        }
    }
}

echo $form->json();
