<?php

namespace Arshavinel\PadelMiniTour\Migration;

use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\Lottery;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryLucky;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryRule;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshwell\Monolith\DB;

class Migration01From23Apr2025
{
    final public function goUp(): array
    {
        $logs = [];


        /**
         * CREATE TABLE Lottery Rule
         */
        DB::createTable(LotteryRule::TABLE, [
            "`".LotteryRule::PRIMARY_KEY."` INT(11) AUTO_INCREMENT NOT NULL",
            "`lottery_id` INT(11) NOT NULL",
            "`rule_prize_quantity` TINYINT(2) NOT NULL",
            "`orderliness` TINYINT(2) NOT NULL DEFAULT 0",
            "`edition_division_id` INT(11) NULL",
            "`gender` ENUM('MALE', 'FEMALE') NULL",
            "`has_social_status` ENUM('STUDENT', 'ADULT') NULL",
            "`legal_age` ENUM('MINOR', 'MAJOR') NULL",
            "`is_younger_than` INT(2) NULL",
            "`is_older_than` INT(2) NULL",
            "`has_tshirt_size` ENUM('XS', 'S', 'M', 'L', 'XL', 'XXL') NULL",
            "`is_guest` TINYINT(1) NULL",
            "`is_padel_first_timer` TINYINT(1) NULL",
            "`is_event_first_timer` TINYINT(1) NULL",
            "`inserted_at` INT(11) NOT NULL",
            "`updated_at` INT(11) NULL",
            "PRIMARY KEY (`".LotteryRule::PRIMARY_KEY."`)",
            "CONSTRAINT `fk_lottery` FOREIGN KEY (`lottery_id`) REFERENCES ".Lottery::TABLE." (`".Lottery::PRIMARY_KEY."`)",
            "CONSTRAINT `fk_edition_division` FOREIGN KEY (`edition_division_id`) REFERENCES ".EditionDivision::TABLE." (`".EditionDivision::PRIMARY_KEY."`)",
        ]);

        $lotteries = Lottery::all("id_lottery, edition_division_id, total_draws_nr");

        foreach ($lotteries as $lottery) {
            LotteryRule::insert(
                "lottery_id, edition_division_id, rule_prize_quantity, inserted_at",
                "?, ?, ?, UNIX_TIMESTAMP()",
                [$lottery->id(), $lottery->edition_division_id, $lottery->total_draws_nr]
            );
        }

        LotteryRule::update([ // copy orderliness data
            'join' => [
                'inner',
                Lottery::TABLE,
                LotteryRule::TABLE . '.orderliness = ' . Lottery::TABLE . '.orderliness'
            ],
            'set' => LotteryRule::TABLE . '.orderliness = ' . Lottery::TABLE . '.orderliness'
        ]);

        DB::alterTable(Lottery::TABLE, 'DROP CONSTRAINT', 'pm_edition_lotteries_ibfk_6');
        DB::alterTable(Lottery::TABLE, 'DROP COLUMN', 'edition_division_id');
        DB::alterTable(Lottery::TABLE, 'DROP COLUMN', 'orderliness');

        $logs[] = "CREATE TABLE `". LotteryRule::TABLE ."` and fill in the edition_division_ids";
        $logs[] = "DROP COLUMN " . Lottery::PRIMARY_KEY . ".`edition_division_id`";
        $logs[] = "DROP COLUMN " . Lottery::PRIMARY_KEY . ".`orderliness`";


        /**
         * RENAME COLUMN total_draws_nr TO prize_quantity
         * MODIFY COLUMN prize_id
         */
        DB::alterTable(Lottery::TABLE, 'RENAME COLUMN', 'total_draws_nr', 'prize_quantity');
        DB::alterTable(Lottery::TABLE, 'MODIFY COLUMN', 'prize_id', 'INT (11)');

        $logs[] = "Lottery RENAME COLUMN `total_draws_nr` TO `prize_quantity`";
        $logs[] = "Make lottery `prize_id` column mandatory";


        /**
         * RENAME TABLE edition_lucky_ones TO edition_lottery_luckies
         * RENAME COLUMN id_lucky_one TO id_lucky
         */
        DB::alterTable('edition_lucky_ones', 'RENAME TO', 'edition_lottery_luckies');
        DB::alterTable(LotteryLucky::TABLE, 'RENAME COLUMN', 'id_lucky_one', 'id_lucky');

        $logs[] = "Lottery Luckies RENAME TABLE `edition_lucky_ones` TO `edition_lottery_luckies`";
        $logs[] = "Lottery Luckies RENAME COLUMN `id_lucky_one` TO `id_lucky`";


        /**
         * CHANGE COLUMN lottery_id WITH lottery_rule_id
         */
        DB::alterTable(LotteryLucky::TABLE, 'ADD', 'lottery_rule_id', 'INT(11) AFTER lottery_id');
        DB::alterTable(
            LotteryLucky::TABLE,
            'ADD CONSTRAINT fk_lottery_rule_id FOREIGN KEY',
            'lottery_rule_id',
            "REFERENCES ". LotteryRule::TABLE ."(". LotteryRule::PRIMARY_KEY .")"
        );

        LotteryLucky::update([ // fill the new lottery_rule_id column
            'join' => [
                'inner',
                LotteryRule::TABLE,
                LotteryLucky::TABLE . '.lottery_id = ' . LotteryRule::TABLE . '.lottery_id',
                'join' => [
                    'inner',
                    Participation::TABLE,
                    LotteryLucky::TABLE . '.drawn_participation_id = ' . Participation::TABLE . '.' . Participation::PRIMARY_KEY,
                    'join' => [
                        'inner',
                        EditionDivision::TABLE,
                        Participation::TABLE . '.edition_division_id = ' . LotteryRule::TABLE . '.edition_division_id'
                    ],
                ],
            ],
            'set' => LotteryLucky::TABLE . ".lottery_rule_id = " . LotteryRule::TABLE .'.'. LotteryRule::PRIMARY_KEY,
        ]);

        DB::alterTable(LotteryLucky::TABLE, 'DROP CONSTRAINT', 'pm_edition_lottery_luckies_ibfk_1');
        DB::alterTable(LotteryLucky::TABLE, 'DROP COLUMN', 'lottery_id');

        $logs[] = "LotteryLucky CHANGE COLUMN `lottery_id` WITH `lottery_rule_id`";


        return $logs;
    }
}
