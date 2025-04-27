<?php

use Arshavinel\PadelMiniTour\Table\Edition;
use Arshavinel\PadelMiniTour\Table\Edition\LotteryLucky;
use Arshavinel\PadelMiniTour\Validation\SiteValidation;
use Arshwell\Monolith\StaticHandler;

if (StaticHandler::supervisor()) {
    $form = SiteValidation::run($_POST, array(
        "id_edition" => array(
            "required|int|inDB:".Edition::class
        ),
        "id_lucky" => array(
            "required|int|inDB:" . LotteryLucky::class
        ),
    ));

    if ($form->valid()) {
        $luckyOne = LotteryLucky::get($form->value('id_lucky'), "announced_at");

        if (!$luckyOne->announced_at) {
            LotteryLucky::update([
                'set' => "announced_at = UNIX_TIMESTAMP()",
                'where' => "id_lucky = ?",
            ], [$luckyOne->id()]);
        }
    }
}

echo $form->json();
