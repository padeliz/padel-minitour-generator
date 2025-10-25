<?php

namespace Arshavinel\PadelMiniTour\Migration;

use Arshavinel\PadelMiniTour\Table\Match\Team;
use Arshwell\Monolith\DB;

class Migration03From25Oct2025
{
    final public function goUp(): array
    {
        $logs = [];


        /**
         * Team ADD COLUMN player_1_is_collecting_points
         */
        DB::alterTable(Team::TABLE, 'ADD', 'player_1_is_collecting_points', "TINYINT(1) NOT NULL DEFAULT 1 AFTER player_2");

        $logs[] = "Team ADD COLUMN `player_1_is_collecting_points`";


        /**
         * Team ADD COLUMN player_2_is_collecting_points
         */
        DB::alterTable(Team::TABLE, 'ADD', 'player_2_is_collecting_points', "TINYINT(1) NOT NULL DEFAULT 1 AFTER player_1_is_collecting_points");

        $logs[] = "Team ADD COLUMN `player_2_is_collecting_points`";


        /**
         * Update existing teams to have both players collecting points by default
         */
        Team::update([
            'set' => "player_1_is_collecting_points = 1, player_2_is_collecting_points = 1"
        ]);

        $logs[] = "Update existing teams to have both players collecting points by default";


        return $logs;
    }

    final public function goDown(): array
    {
        $logs = [];

        /**
         * Team DROP COLUMN player_1_is_collecting_points
         */
        DB::alterTable(Team::TABLE, 'DROP', 'player_1_is_collecting_points');
        DB::alterTable(Team::TABLE, 'DROP', 'player_2_is_collecting_points');

        $logs[] = "Team DROP COLUMN `player_1_is_collecting_points`";
        $logs[] = "Team DROP COLUMN `player_2_is_collecting_points`";

        return $logs;
    }
}
