<?php

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\LuckyOne;
use Arshavinel\PadelMiniTour\Validation\SiteValidation;
use Arshwell\Monolith\StaticHandler;

if (StaticHandler::supervisor()) {
    $form = SiteValidation::run($_POST, array(
        "id_edition" => array(
            "required|int|inDB:".Edition::class
        ),
        "id_lucky_one" => array(
            "required|int|inDB:".LuckyOne::class
        ),
    ));

    if ($form->valid()) {
        $luckyOne = LuckyOne::get($form->value('id_lucky_one'), "announced_at");

        if (!$luckyOne->announced_at) {
            LuckyOne::update([
                'set' => "announced_at = UNIX_TIMESTAMP()",
                'where' => "id_lucky_one = ?",
            ], [$luckyOne->id()]);
        }
    }
}

echo $form->json();
