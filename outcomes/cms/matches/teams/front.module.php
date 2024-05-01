<?php

return array(
    'breadcrumbs' => array(
        'Matches',
        'Teams'
    ),

    'actions' => array(
        'select' => array(
            'HTML' => array(
                'icon'      => 'arrow-alt-circle-left',
                'text'      => "Înapoi la toate",
                'class'     => "btn btn-sm btn-info",
                'hidden'    => (empty($_GET['ftr']) && (empty($_GET['ctn']) || $_GET['ctn'] == 'select')) // hide if SELECT page is active
            )
        ),
        'insert' => array(
            'HTML'  => array(
                'icon'      => 'plus-circle',
                'text'      => "Adaugă",
                'class'     => "btn btn-sm btn-success",
                'disabled'  => (!empty($_GET['ctn']) && $_GET['ctn'] == 'insert') // disable if INSERT page is active
            )
        )
    ),

    'features' => array(
        'update' => array(
            'HTML' => array(
                'icon'      => 'edit',
                'class'     => "btn badge btn-outline-info p-2"
            ),
            'JS' => array(
                'tooltip' => array(
                    'title'     => 'Editează',
                    'placement' => 'top'
                )
            )
        ),

        'delete' => array(
            'HTML' => array(
                'type'      => 'submit',
                'icon'      => 'trash-alt',
                'class'     => "btn badge btn-outline-danger p-2"
            ),
            'JS' => array(
                'confirmation' => array(
                    'title' => 'Vrei să ștergi?'
                )
            )
        )
    ),

    'fields' => array(
        'avatars' => array(
            'HTML' => array(
                'label'         => "Avatars",
                'icon'          => 'image',
                'type'          => 'image'
            )
        ),

        'player_1' => array(
            'HTML' => array(
                'label'         => "Player 1",
                'icon'          => 'info-circle',
                'type'          => 'text'
            )
        ),

        'player_2' => array(
            'HTML' => array(
                'label'         => "Player 2",
                'icon'          => 'info-circle',
                'type'          => 'text'
            )
        ),

        'inserted_at' => array(
            'HTML' => array(
                'label'         => "Adăugat",
                'type'          => 'date',
                'hidden'        => true
            )
        ),

        'updated_at' => array(
            'HTML' => array(
                'label'         => "Ultima editare",
                'type'          => 'date',
                'hidden'        => true
            )
        )
    )
);
