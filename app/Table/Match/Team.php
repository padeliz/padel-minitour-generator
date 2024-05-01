<?php

namespace Arshavinel\PadelMiniTour\Table\Match;

use Arshwell\Monolith\Table;

use Arshavinel\PadelMiniTour\Language\LangSite;

final class Team extends Table
{
    const TABLE = 'matches_teams';
    const PRIMARY_KEY = 'id_team';

    const TRANSLATOR = LangSite::class;

    const FILES = array(
        'avatars' => array(
            'quality'   => 100,
            'sizes'     => array(
                'small' => array(
                    'width' => 300,
                    'height' => 300
                ),
                'medium'   => array(
                    'width' => 500,
                    'height' => 500
                )
            )
        )
    );
}
