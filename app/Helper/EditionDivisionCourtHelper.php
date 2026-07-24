<?php

namespace Arshavinel\PadelMiniTour\Helper;

use Arshavinel\PadelMiniTour\Table\Court;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivisionCourt;

/**
 * Resolves court names for an edition division via the courts pivot.
 */
final class EditionDivisionCourtHelper
{
    /**
     * First court name by pivot display_order (then id), or empty string if none.
     */
    public static function firstCourtName(int $editionDivisionId): string
    {
        $row = EditionDivisionCourt::first([
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

        if ($row === null) {
            return '';
        }

        return (string) $row->name;
    }
}
