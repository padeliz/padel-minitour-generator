<?php

namespace Arshavinel\PadelMiniTour\Table;

use Arshwell\Monolith\Table;

use Arshavinel\PadelMiniTour\Language\LangSite;

final class Player extends Table
{
    const TABLE = 'players';
    const PRIMARY_KEY = 'id_player';

    const TRANSLATOR = LangSite::class;

    const FILES = [
        'avatar' => [
            'quality' => 100,
            'sizes' => [
                'small' => [
                    'width' => 300,
                    'height' => 300
                ],
                'medium' => [
                    'width' => 500,
                    'height' => 500
                ]
            ]
        ]
    ];

    public static function findOrFailById(int $id): Player
    {
        return Player::first(
            [
                'columns' => "name, inserted_at, updated_at",
                'where' => "id_player = ?",
                'files' => true,
            ],
            [$id]
        );
    }
}
