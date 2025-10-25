<?php

use Arshavinel\PadelMiniTour\Table\Player;
use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\Participation;
use Arshavinel\PadelMiniTour\Table\Edition\EditionDivision;
use Arshwell\Monolith\Meta;

Meta::set('title', "ARSH Padel MiniTour");
Meta::set('description', "Generate matches for your next edition.");
Meta::set('keywords', "padel, players, minitour");

// Fetch all players with their participation history
$players = Player::select([
    'columns' => "
        players.id_player,
        players.name,
        (SELECT editions.name FROM editions
         JOIN edition_divisions ON editions.id_edition = edition_divisions.edition_id
         JOIN edition_participations ON edition_divisions.id_edition_division = edition_participations.edition_division_id
         WHERE edition_participations.player_id = players.id_player
         ORDER BY editions.date ASC
         LIMIT 1) AS first_edition_name,
        (SELECT editions.name FROM editions
         JOIN edition_divisions ON editions.id_edition = edition_divisions.edition_id
         JOIN edition_participations ON edition_divisions.id_edition_division = edition_participations.edition_division_id
         WHERE edition_participations.player_id = players.id_player
         ORDER BY editions.date DESC
         LIMIT 1) AS last_edition_name,
        (SELECT divisions.name FROM divisions
         JOIN edition_divisions ON divisions.id_division = edition_divisions.division_id
         JOIN edition_participations ON edition_divisions.id_edition_division = edition_participations.edition_division_id
         WHERE edition_participations.player_id = players.id_player
         ORDER BY editions.date DESC
         LIMIT 1) AS last_division_name
    ",
    'joins' => [
        [
            'type' => 'LEFT',
            'table' => Participation::TABLE,
            'on' => 'players.id_player = edition_participations.player_id'
        ],
        [
            'type' => 'LEFT',
            'table' => EditionDivision::TABLE,
            'on' => 'edition_participations.edition_division_id = edition_divisions.id_edition_division'
        ],
        [
            'type' => 'LEFT',
            'table' => Edition::TABLE,
            'on' => 'edition_divisions.edition_id = editions.id_edition'
        ]
    ],
    'group' => 'players.id_player, players.name',
    'order' => 'players.name ASC',
    'files' => true
]);

// Format players for JSON
$playersData = array_map(function ($player) {
    return [
        'id' => $player->id(),
        'name' => $player->name,
        'avatar_small' => $player->file('avatar')->url('small'),
        'first_edition' => $player->first_edition_name ?? null,
        'last_edition' => $player->last_edition_name ?? null,
        'last_division' => $player->last_division_name ?? null
    ];
}, $players);
