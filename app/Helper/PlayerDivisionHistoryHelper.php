<?php

namespace Arshavinel\PadelMiniTour\Helper;

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;

/**
 * SQL fragments for a player's edition / division history.
 */
final class PlayerDivisionHistoryHelper
{
    /**
     * Correlated subquery: latest division_title by edition date for a player.
     *
     * Outer query must expose the player id column (default players.id_player).
     */
    public static function lastDivisionTitleSubquery(string $playerIdColumn = 'players.id_player'): string
    {
        return '(SELECT ' . EditionDivision::TABLE . '.division_title'
            . ' FROM ' . EditionDivision::TABLE
            . ' JOIN ' . Participation::TABLE
            . ' ON ' . EditionDivision::TABLE . '.' . EditionDivision::PRIMARY_KEY
            . ' = ' . Participation::TABLE . '.edition_division_id'
            . ' JOIN ' . Edition::TABLE
            . ' ON ' . EditionDivision::TABLE . '.edition_id = ' . Edition::TABLE . '.' . Edition::PRIMARY_KEY
            . ' WHERE ' . Participation::TABLE . '.player_id = ' . $playerIdColumn
            . ' ORDER BY ' . Edition::TABLE . '.date DESC'
            . ' LIMIT 1)';
    }
}
