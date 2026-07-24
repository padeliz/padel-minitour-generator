<?php

namespace Arshavinel\PadelMiniTour\Migration;

use Arshavinel\PadelMiniTour\Helper\CourtLabelHelper;
use Arshavinel\PadelMiniTour\Table\Court;
use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\DivisionReplacement;
use Arshavinel\PadelMiniTour\Table\Edition\DivisionStanding;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivisionCourt;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshavinel\PadelMiniTour\Table\Location;
use Arshavinel\PadelMiniTour\Table\Match\EditionMatch;
use Arshavinel\PadelMiniTour\Table\Match\Side;
use Arshavinel\PadelMiniTour\Table\Match\SidePlayer;
use Arshavinel\PadelMiniTour\Table\Player;
use Arshwell\Monolith\DB;

class Migration05From24Jul2026
{
    final public function goUp(): array
    {
        $logs = [];

        DB::createTable(Court::TABLE, [
            '`' . Court::PRIMARY_KEY . '` INT(11) AUTO_INCREMENT NOT NULL',
            '`location_id` INT(11) NOT NULL',
            '`name` VARCHAR(30) NOT NULL',
            '`is_indoor` TINYINT(1) NOT NULL',
            '`display_order` SMALLINT NOT NULL DEFAULT 0',
            '`inserted_at` INT(11) NOT NULL',
            '`updated_at` INT(11) NULL',
            'PRIMARY KEY (`' . Court::PRIMARY_KEY . '`)',
            'UNIQUE KEY `uq_court_location_name` (`location_id`, `name`)',
            'CONSTRAINT `fk_court_location` FOREIGN KEY (`location_id`) REFERENCES ' . Location::TABLE . ' (`' . Location::PRIMARY_KEY . '`)',
        ]);
        $logs[] = 'CREATE TABLE `' . Court::TABLE . '`';

        DB::createTable(EditionDivisionCourt::TABLE, [
            '`' . EditionDivisionCourt::PRIMARY_KEY . '` INT(11) AUTO_INCREMENT NOT NULL',
            '`edition_division_id` INT(11) NOT NULL',
            '`court_id` INT(11) NOT NULL',
            '`display_order` SMALLINT NOT NULL DEFAULT 0',
            '`inserted_at` INT(11) NOT NULL',
            '`updated_at` INT(11) NULL',
            'PRIMARY KEY (`' . EditionDivisionCourt::PRIMARY_KEY . '`)',
            'UNIQUE KEY `uq_ed_court` (`edition_division_id`, `court_id`)',
            'CONSTRAINT `fk_edc_edition_division` FOREIGN KEY (`edition_division_id`) REFERENCES ' . EditionDivision::TABLE . ' (`' . EditionDivision::PRIMARY_KEY . '`) ON DELETE CASCADE',
            'CONSTRAINT `fk_edc_court` FOREIGN KEY (`court_id`) REFERENCES ' . Court::TABLE . ' (`' . Court::PRIMARY_KEY . '`)',
        ]);
        $logs[] = 'CREATE TABLE `' . EditionDivisionCourt::TABLE . '`';

        $backfilled = $this->backfillCourtsFromEditionDivisions();
        $logs[] = "Backfill courts/pivot from edition_divisions.court ({$backfilled} rows)";

        DB::alterTable(EditionDivision::TABLE, 'DROP COLUMN', 'court');
        $logs[] = 'DROP COLUMN edition_divisions.`court`';

        DB::alterTable(EditionDivision::TABLE, 'ADD', 'points_per_match', 'TINYINT NULL');
        DB::alterTable(EditionDivision::TABLE, 'ADD', 'final_points_target', 'TINYINT NULL');
        DB::alterTable(EditionDivision::TABLE, 'ADD', 'include_final', 'TINYINT(1) NOT NULL DEFAULT 0');
        DB::alterTable(EditionDivision::TABLE, 'ADD', 'finalized_at', 'INT(11) NULL');
        $logs[] = 'ADD edition_divisions columns: points_per_match, final_points_target, include_final, finalized_at';

        DB::createTable(EditionMatch::TABLE, [
            '`' . EditionMatch::PRIMARY_KEY . '` INT(11) AUTO_INCREMENT NOT NULL',
            '`edition_division_id` INT(11) NOT NULL',
            '`edition_division_court_id` INT(11) NOT NULL',
            "`match_kind` ENUM('REGULAR','FINAL','DEMONSTRATIVE') NOT NULL",
            '`sequence_no` SMALLINT NOT NULL',
            '`scheduled_start` TIME NULL',
            '`scheduled_end` TIME NULL',
            '`points_target` TINYINT NULL',
            '`inserted_at` INT(11) NOT NULL',
            '`updated_at` INT(11) NULL',
            'PRIMARY KEY (`' . EditionMatch::PRIMARY_KEY . '`)',
            'UNIQUE KEY `uq_match_sequence` (`edition_division_id`, `match_kind`, `edition_division_court_id`, `sequence_no`)',
            'CONSTRAINT `fk_match_edition_division` FOREIGN KEY (`edition_division_id`) REFERENCES ' . EditionDivision::TABLE . ' (`' . EditionDivision::PRIMARY_KEY . '`)',
            'CONSTRAINT `fk_match_edition_division_court` FOREIGN KEY (`edition_division_court_id`) REFERENCES ' . EditionDivisionCourt::TABLE . ' (`' . EditionDivisionCourt::PRIMARY_KEY . '`)',
        ]);
        $logs[] = 'CREATE TABLE `' . EditionMatch::TABLE . '`';

        DB::createTable(Side::TABLE, [
            '`' . Side::PRIMARY_KEY . '` INT(11) AUTO_INCREMENT NOT NULL',
            '`match_id` INT(11) NOT NULL',
            "`side` ENUM('LEFT','RIGHT') NOT NULL",
            '`score` TINYINT NULL',
            'PRIMARY KEY (`' . Side::PRIMARY_KEY . '`)',
            'UNIQUE KEY `uq_match_side` (`match_id`, `side`)',
            'CONSTRAINT `fk_match_side_match` FOREIGN KEY (`match_id`) REFERENCES ' . EditionMatch::TABLE . ' (`' . EditionMatch::PRIMARY_KEY . '`) ON DELETE CASCADE',
        ]);
        $logs[] = 'CREATE TABLE `' . Side::TABLE . '`';

        DB::createTable(SidePlayer::TABLE, [
            '`' . SidePlayer::PRIMARY_KEY . '` INT(11) AUTO_INCREMENT NOT NULL',
            '`match_side_id` INT(11) NOT NULL',
            '`slot` TINYINT NOT NULL',
            '`participation_id` INT(11) NULL',
            '`player_id` INT(11) NULL',
            '`inserted_at` INT(11) NOT NULL',
            '`updated_at` INT(11) NULL',
            'PRIMARY KEY (`' . SidePlayer::PRIMARY_KEY . '`)',
            'UNIQUE KEY `uq_match_side_slot` (`match_side_id`, `slot`)',
            'CONSTRAINT `fk_match_player_side` FOREIGN KEY (`match_side_id`) REFERENCES ' . Side::TABLE . ' (`' . Side::PRIMARY_KEY . '`) ON DELETE CASCADE',
            'CONSTRAINT `fk_match_player_participation` FOREIGN KEY (`participation_id`) REFERENCES ' . Participation::TABLE . ' (`' . Participation::PRIMARY_KEY . '`)',
            'CONSTRAINT `fk_match_player_player` FOREIGN KEY (`player_id`) REFERENCES ' . Player::TABLE . ' (`' . Player::PRIMARY_KEY . '`)',
            'CONSTRAINT `chk_match_player_identity` CHECK (`participation_id` IS NULL OR `player_id` IS NULL)',
        ]);
        $logs[] = 'CREATE TABLE `' . SidePlayer::TABLE . '`';

        DB::createTable(DivisionReplacement::TABLE, [
            '`' . DivisionReplacement::PRIMARY_KEY . '` INT(11) AUTO_INCREMENT NOT NULL',
            '`edition_division_id` INT(11) NOT NULL',
            '`original_participation_id` INT(11) NOT NULL',
            '`substitute_participation_id` INT(11) NULL',
            '`substitute_player_id` INT(11) NULL',
            '`match_id` INT(11) NULL',
            '`match_scope_key` INT AS (IFNULL(`match_id`, 0)) PERSISTENT',
            '`inserted_at` INT(11) NOT NULL',
            '`updated_at` INT(11) NULL',
            'PRIMARY KEY (`' . DivisionReplacement::PRIMARY_KEY . '`)',
            'UNIQUE KEY `uq_replacement_scope` (`edition_division_id`, `original_participation_id`, `match_scope_key`)',
            'CONSTRAINT `fk_replacement_edition_division` FOREIGN KEY (`edition_division_id`) REFERENCES ' . EditionDivision::TABLE . ' (`' . EditionDivision::PRIMARY_KEY . '`)',
            'CONSTRAINT `fk_replacement_original` FOREIGN KEY (`original_participation_id`) REFERENCES ' . Participation::TABLE . ' (`' . Participation::PRIMARY_KEY . '`)',
            'CONSTRAINT `fk_replacement_sub_participation` FOREIGN KEY (`substitute_participation_id`) REFERENCES ' . Participation::TABLE . ' (`' . Participation::PRIMARY_KEY . '`)',
            'CONSTRAINT `fk_replacement_sub_player` FOREIGN KEY (`substitute_player_id`) REFERENCES ' . Player::TABLE . ' (`' . Player::PRIMARY_KEY . '`)',
            'CONSTRAINT `fk_replacement_match` FOREIGN KEY (`match_id`) REFERENCES ' . EditionMatch::TABLE . ' (`' . EditionMatch::PRIMARY_KEY . '`) ON DELETE CASCADE',
            'CONSTRAINT `chk_replacement_substitute` CHECK ('
                . '(`substitute_participation_id` IS NOT NULL AND `substitute_player_id` IS NULL) OR '
                . '(`substitute_participation_id` IS NULL AND `substitute_player_id` IS NOT NULL)'
                . ')',
        ]);
        $logs[] = 'CREATE TABLE `' . DivisionReplacement::TABLE . '`';

        DB::createTable(DivisionStanding::TABLE, [
            '`' . DivisionStanding::PRIMARY_KEY . '` INT(11) AUTO_INCREMENT NOT NULL',
            '`edition_division_id` INT(11) NOT NULL',
            '`participation_id` INT(11) NOT NULL',
            '`is_collecting_points` TINYINT(1) NOT NULL DEFAULT 1',
            '`points_total` INT(11) NULL',
            '`wins` DECIMAL(4,1) NULL',
            '`rank` SMALLINT NULL',
            '`inserted_at` INT(11) NOT NULL',
            '`updated_at` INT(11) NULL',
            'PRIMARY KEY (`' . DivisionStanding::PRIMARY_KEY . '`)',
            'UNIQUE KEY `uq_standing_participation` (`edition_division_id`, `participation_id`)',
            'CONSTRAINT `fk_standing_edition_division` FOREIGN KEY (`edition_division_id`) REFERENCES ' . EditionDivision::TABLE . ' (`' . EditionDivision::PRIMARY_KEY . '`)',
            'CONSTRAINT `fk_standing_participation` FOREIGN KEY (`participation_id`) REFERENCES ' . Participation::TABLE . ' (`' . Participation::PRIMARY_KEY . '`)',
        ]);
        $logs[] = 'CREATE TABLE `' . DivisionStanding::TABLE . '`';

        return $logs;
    }

    final public function goDown(): array
    {
        $logs = [];

        DB::dropTable(DivisionStanding::TABLE);
        $logs[] = 'DROP TABLE `' . DivisionStanding::TABLE . '`';

        DB::dropTable(DivisionReplacement::TABLE);
        $logs[] = 'DROP TABLE `' . DivisionReplacement::TABLE . '`';

        DB::dropTable(SidePlayer::TABLE);
        $logs[] = 'DROP TABLE `' . SidePlayer::TABLE . '`';

        DB::dropTable(Side::TABLE);
        $logs[] = 'DROP TABLE `' . Side::TABLE . '`';

        DB::dropTable(EditionMatch::TABLE);
        $logs[] = 'DROP TABLE `' . EditionMatch::TABLE . '`';

        DB::alterTable(EditionDivision::TABLE, 'DROP COLUMN', 'finalized_at');
        DB::alterTable(EditionDivision::TABLE, 'DROP COLUMN', 'include_final');
        DB::alterTable(EditionDivision::TABLE, 'DROP COLUMN', 'final_points_target');
        DB::alterTable(EditionDivision::TABLE, 'DROP COLUMN', 'points_per_match');
        $logs[] = 'DROP edition_divisions columns: points_per_match, final_points_target, include_final, finalized_at';

        DB::alterTable(EditionDivision::TABLE, 'ADD', 'court', "VARCHAR(30) NOT NULL DEFAULT ''");
        $restored = $this->restoreCourtColumnFromPivot();
        $logs[] = "ADD edition_divisions.`court` and refill from pivot ({$restored} rows)";

        DB::dropTable(EditionDivisionCourt::TABLE);
        $logs[] = 'DROP TABLE `' . EditionDivisionCourt::TABLE . '`';

        DB::dropTable(Court::TABLE);
        $logs[] = 'DROP TABLE `' . Court::TABLE . '`';

        return $logs;
    }

    private function backfillCourtsFromEditionDivisions(): int
    {
        $rows = EditionDivision::select([
            'columns' => EditionDivision::TABLE . '.court, '
                . Edition::TABLE . '.location_id, '
                . Location::TABLE . '.total_indoors_courts, '
                . Location::TABLE . '.total_outdoor_courts',
            'joins' => [
                [
                    'type' => 'INNER',
                    'table' => Edition::TABLE,
                    'on' => EditionDivision::TABLE . '.edition_id = ' . Edition::TABLE . '.' . Edition::PRIMARY_KEY,
                ],
                [
                    'type' => 'INNER',
                    'table' => Location::TABLE,
                    'on' => Edition::TABLE . '.location_id = ' . Location::TABLE . '.' . Location::PRIMARY_KEY,
                ],
            ],
        ]);

        $count = 0;
        $now = time();

        foreach ($rows as $row) {
            $rawCourt = trim((string) ($row->court ?? ''));
            if ($rawCourt === '') {
                continue;
            }

            $parsed = CourtLabelHelper::parse($rawCourt);
            if ($parsed['name'] === '') {
                continue;
            }

            $isIndoor = CourtLabelHelper::resolveIsIndoor(
                $parsed['is_indoor'],
                (int) $row->total_indoors_courts,
                (int) $row->total_outdoor_courts
            ) ? 1 : 0;

            $locationId = (int) $row->location_id;
            $name = mb_substr($parsed['name'], 0, 30);

            $court = Court::first([
                'columns' => Court::PRIMARY_KEY,
                'where' => 'location_id = ? AND name = ?',
            ], [$locationId, $name]);

            if ($court === null) {
                $courtId = Court::insert(
                    'location_id, name, is_indoor, display_order, inserted_at',
                    '?, ?, ?, 0, ?',
                    [$locationId, $name, $isIndoor, $now]
                );
            } else {
                $courtId = $court->id();
            }

            $editionDivisionId = (int) $row->id();

            $pivot = EditionDivisionCourt::first([
                'columns' => EditionDivisionCourt::PRIMARY_KEY,
                'where' => 'edition_division_id = ? AND court_id = ?',
            ], [$editionDivisionId, $courtId]);

            if ($pivot === null) {
                EditionDivisionCourt::insert(
                    'edition_division_id, court_id, display_order, inserted_at',
                    '?, ?, 0, ?',
                    [$editionDivisionId, $courtId, $now]
                );
                $count++;
            }
        }

        return $count;
    }

    private function restoreCourtColumnFromPivot(): int
    {
        $editionDivisionIds = EditionDivision::column(EditionDivision::PRIMARY_KEY);
        $count = 0;

        foreach ($editionDivisionIds as $editionDivisionId) {
            $editionDivisionId = (int) $editionDivisionId;

            $pivot = EditionDivisionCourt::first([
                'columns' => Court::TABLE . '.name',
                'joins' => [
                    [
                        'type' => 'INNER',
                        'table' => Court::TABLE,
                        'on' => EditionDivisionCourt::TABLE . '.court_id = ' . Court::TABLE . '.' . Court::PRIMARY_KEY,
                    ],
                ],
                'where' => EditionDivisionCourt::TABLE . '.edition_division_id = ?',
                'order' => EditionDivisionCourt::TABLE . '.display_order ASC, '
                    . EditionDivisionCourt::TABLE . '.' . EditionDivisionCourt::PRIMARY_KEY . ' ASC',
            ], [$editionDivisionId]);

            $name = $pivot !== null ? (string) $pivot->name : '';

            EditionDivision::updateId($editionDivisionId, 'court = ?', [$name]);
            $count++;
        }

        return $count;
    }
}
