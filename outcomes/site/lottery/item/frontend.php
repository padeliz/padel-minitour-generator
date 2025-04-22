<div class="container padding-0-8th">
    <div class="row align-items-end justify-content-center">
        <div class="col-6">
            <a href="<?= \Arshwell\Monolith\Web::url('site.lottery.list') ?>">
                <img alt="ARSH Padel MiniTour" style="max-width: 100%;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour Long E.png' ?>" />
            </a>
            <div class="text-end pe-4">
                <div id="edition">
                    <?= $edition->name ?> Lottery
                </div>
            </div>
        </div>
    </div>
</div>
<div class="container-fluid">
    <?php
    if (!$luckyOne) { ?>
        <div class="row align-items-start justify-content-center">
            <?php
            foreach ($allLuckyOnes as $allLuckyOne) { ?>
                <div class="col-auto text-center pb-3">
                    <img style="max-width: 100%; max-height: 100%;" src="<?= $allLuckyOne->pdfPlayer->getAvatarUrl() ?>" alt="<?= $allLuckyOne->pdfPlayer->getShortName() ?>" />
                    <div class="fz-16 nowrap"><?= $allLuckyOne->pdfPlayer->getShortName() ?></div>
                </div>
            <?php } ?>
        </div>
        <div id="finished" class="text-center margin-8th-0">
            Felicită și tu norocoșii de azi!
            <br>
            Ne revedem la next edition pe 27 aprilie 💚
        </div>
    <?php } else { ?>
        <input type="hidden" name="id_edition" value="<?= $edition->id() ?>" />
        <input type="hidden" name="id_lucky_one" value="<?= $luckyOne->id() ?>" />

        <div class="row align-items-center justify-content-center">
            <?php
            if ($timeLeft['hours'] <= 0) { ?>
                <div class="col-md-5 text-center">
                    <div id="prize">
                        <?php
                        if ($luckyOne->lottery->total_draws_nr > 1) { ?>
                            <div class="pb-3">
                                <?= $luckyOne->lottery->prize_index ?> / <?= $luckyOne->lottery->total_draws_nr ?>
                            </div>
                        <?php } ?>
                        <div class="row g-0 justify-content-center align-items-center">
                            <div class="col-auto">
                                <div class="d-inline-block p-3 rounded" style="font-size: <?= Arshavinel\PadelMiniTour\Helper\LotteryHtmlHelper::getFirstTextSize($luckyOne->lottery->box_1_text, !empty($luckyOne->lottery->image)) ?>px; background-color: <?= $luckyOne->lottery->box_1_bg_color ?>; color: <?= $luckyOne->lottery->box_1_text_color ?>;">
                                    <?= $luckyOne->lottery->box_1_text ?>
                                </div>
                            </div>
                            <?php
                            if ($luckyOne->lottery->image) { ?>
                                <div class="col ps-3">
                                    <img src="<?= \Arshwell\Monolith\Web::site() ?>statics/media/MiniTour-lottery/prizes/<?= $luckyOne->lottery->image ?>" ?>
                                </div>
                            <?php } ?>
                        </div>
                        <?php
                        if ($luckyOne->lottery->box_2_text) { ?>
                            <div class="d-inline-flex p-3 rounded mt-4" style="font-size: <?= Arshavinel\PadelMiniTour\Helper\LotteryHtmlHelper::getSecondTextSize($luckyOne->lottery->box_1_text, $luckyOne->lottery->box_2_text) ?>px; background-color: <?= $luckyOne->lottery->box_2_bg_color ?>; color: <?= $luckyOne->lottery->box_2_text_color ?>;">
                                <small><?= $luckyOne->lottery->box_2_text ?></small>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
            <div class="col-md-7 text-center">
                <?php
                if ($luckyOne) { ?>
                    <?php
                    if ($timeLeftInTotalSeconds > 1) { ?>
                        <div id="timer">
                            <?php
                            if ($timeLeft['days']) {
                            ?><span id="days"><?= $timeLeft['days'] ?></span>:<?php
                                                                            }
                                                                            if ($timeLeft['hours']) {
                                                                                ?><span id="hours"><?= $timeLeft['hours'] ?></span>:<?php
                                                                                                                                    } ?><span id="minutes"><?= $timeLeft['minutes'] ?></span>:<span id="seconds"><?= $timeLeft['seconds'] ?></span>
                        </div>
                    <?php } else { ?>
                        <img style="width: 50%; max-height: 100%;" src="<?= $pdfPlayer->getAvatarUrl() ?>" alt="<?= $pdfPlayer->getShortName() ?>" />
                        <div><?= $pdfPlayer->getShortName() ?></div>
                        <?php
                        if (Arshwell\Monolith\StaticHandler::supervisor()) { ?>
                            <div>
                                <button id="button--rejected-by-lucky-one" class="btn btn-danger">
                                    <small>draw someone else 😞</small>
                                </button>
                                <button id="button--accepted-by-lucky-one" class="btn btn-primary">
                                    <small>great, I'll keep it 💚</small>
                                </button>
                            </div>
                        <?php } ?>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>
    <?php } ?>
</div>
