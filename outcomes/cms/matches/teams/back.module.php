<?php

use Arshavinel\PadelMiniTour\Table\Match\Team;
use Arshavinel\PadelMiniTour\Validation\SiteValidation;
use Arshwell\Monolith\Meta;

Meta::set('title', 'Matches Teams');

return array(
    'DB' => array(
        'conn'  => 'padel_minitour',
        'table' => Team::class
    ),

    'PHP' => array(
        'validation' => array(
            'class' => SiteValidation::class
        )
    ),

    'actions' => array(
        'select' => array(
            'columns' => array(
                'public' => array('avatars', 'player_1', 'player_2'),
            ),
            'limit' => 10
        ),
        'insert' => true
    ),

    'features' => array(
        'update' => true,

        'delete' => true
    ),

    'fields' => array(
        'avatars' => array(
            'DB' => NULL,
            'PHP' => array(
                'rules' => array(
                    'insert' => array(
                        "required|image:Arshavinel\PadelMiniTour\Table\Match\Team,avatars"
                    ),
                    'update' => array(
                        "optional|image:Arshavinel\PadelMiniTour\Table\Match\Team,avatars"
                    )
                )
            )
        ),

        'player_1' => array(
            'DB' => array(
                'column'    => 'player_1',
                'type'      => 'varchar',
                'length'    => 50,
            ),
            'PHP' => array(
                'rules' => array(
                    "required|minLength:4|minLength:25"
                )
            )
        ),

        'player_2' => array(
            'DB' => array(
                'column'    => 'player_2',
                'type'      => 'varchar',
                'length'    => 50,
            ),
            'PHP' => array(
                'rules' => array(
                    "required|minLength:4|minLength:25"
                )
            )
        ),

        'inserted_at' => array(
            'DB' => array(
                'column'    => 'inserted_at',
                'type'      => 'int'
            ),
            'PHP' => array(
                'rules' => array(
                    'insert' => array(
                        function ($value) {
                            return time();
                        }
                    ),
                    'update' => array(
                        function ($value) {
                            return strtotime($value);
                        }
                    )
                )
            )
        ),

        'updated_at' => array(
            'DB' => array(
                'column'    => 'updated_at',
                'type'      => 'int',
                'null'      => true
            ),
            'PHP' => array(
                'rules' => array(
                    'insert' => array(
                        function ($value) {
                            return NULL;
                        }
                    ),
                    'update' => array(
                        function ($value) {
                            return time();
                        }
                    )
                )
            )
        ),
    )
);
