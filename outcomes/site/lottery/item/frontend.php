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
            foreach ($allLotteryLuckies as $allLotteryLucky) { ?>
                <div class="col-auto text-center pb-3">
                    <div class="lucky">
                        <small class="thumb d-inline-block px-3 fz-16 nowrap" style="background-color: <?= $allLotteryLucky->division_color ?>;">
                            <?= $allLotteryLucky->division_name ?>
                        </small>
                        <br>
                        <div class="thumb-wrapper" style="background-color: <?= $allLotteryLucky->division_color ?>; border-color: <?= $allLotteryLucky->division_color ?>;">
                            <img style="max-width: 100%; max-height: 100%;" src="<?= $allLotteryLucky->pdfPlayer->getAvatarUrl() ?>" alt="<?= $allLotteryLucky->pdfPlayer->getShortName() ?>" />
                        </div>
                    </div>
                    <div class="fz-16 nowrap"><?= $allLotteryLucky->pdfPlayer->getShortName() ?></div>
                </div>
            <?php } ?>
        </div>
        <div id="finished" class="text-center margin-8th-0">
            <?php
            if ($edition->date == date('Y-m-d')) { ?>
                Congratulations to today's lucky winners!
            <?php } else { ?>
                Congratulations to the lucky ones!
            <?php } ?>
            <br>
            <?php
            if ($next_edition) { ?>
                See you at the next edition: <?= $next_edition->name ?> on <?= date('d M Y', strtotime($next_edition->date)) ?> 💚
            <?php } else { ?>
                See you at the next edition 💚
            <?php } ?>
        </div>
    <?php } else { ?>
        <input type="hidden" name="id_edition" value="<?= $edition->id() ?>" />
        <input type="hidden" name="id_lucky" value="<?= $luckyOne->id() ?>" />

        <div class="row align-items-center justify-content-center">
            <?php
            if ($timeLeft['hours'] <= 0) { ?>
                <div class="col-md-6 text-center">
                    <div id="prize">
                        <?php
                        if ($luckyOne->lottery->prize_quantity > 1) { ?>
                            <div class="pb-3">
                                <?= $luckyOne->lottery->prize_index ?> / <?= $luckyOne->lottery->prize_quantity ?>
                            </div>
                        <?php } ?>

                        <div class="row g-0 justify-content-center align-items-center mb-4">
                            <?php
                            // IMAGE LEFT
                            if ($luckyOne->lottery->image && $luckyOne->lottery->template == 'IMAGE_LEFT') { ?>
                                <div class="col ps-3">
                                    <img src="<?= \Arshwell\Monolith\Web::site() ?>statics/media/MiniTour-lottery/prizes/<?= $luckyOne->lottery->image ?>" ?>
                                </div>
                            <?php } ?>
                            <div class="col-auto">
                                <?php
                                // IMAGE TOP
                                if ($luckyOne->lottery->image && $luckyOne->lottery->template == 'IMAGE_TOP') { ?>
                                    <div class="col ps-3">
                                        <img src="<?= \Arshwell\Monolith\Web::site() ?>statics/media/MiniTour-lottery/prizes/<?= $luckyOne->lottery->image ?>" ?>
                                    </div>
                                <?php } ?>

                                <!-- box_1_text -->
                                <div class="d-inline-block p-3 rounded" style="font-size: <?= Arshavinel\PadelMiniTour\Helper\LotteryHtmlHelper::getFirstTextSize($luckyOne->lottery->box_1_text, !empty($luckyOne->lottery->image)) ?>px; background-color: <?= $luckyOne->lottery->box_1_bg_color ?>; color: <?= $luckyOne->lottery->box_1_text_color ?>;">
                                    <?= $luckyOne->lottery->box_1_text ?>
                                </div>
                            </div>
                            <?php
                            // IMAGE RIGHT
                            if ($luckyOne->lottery->image && $luckyOne->lottery->template == 'IMAGE_RIGHT') { ?>
                                <div class="col ps-3">
                                    <img src="<?= \Arshwell\Monolith\Web::site() ?>statics/media/MiniTour-lottery/prizes/<?= $luckyOne->lottery->image ?>" ?>
                                </div>
                            <?php } ?>
                        </div>

                        <?php
                        // IMAGE MIDDLE
                        if ($luckyOne->lottery->image && $luckyOne->lottery->template == 'IMAGE_MIDDLE') { ?>
                            <div class="col ps-3 mb-4">
                                <img style="max-height: 35vh;" src="<?= \Arshwell\Monolith\Web::site() ?>statics/media/MiniTour-lottery/prizes/<?= $luckyOne->lottery->image ?>" ?>
                            </div>
                        <?php } ?>

                        <?php
                        // box_2_text
                        if ($luckyOne->lottery->box_2_text) { ?>
                            <div class="d-inline-flex p-3 rounded" style="font-size: <?= Arshavinel\PadelMiniTour\Helper\LotteryHtmlHelper::getSecondTextSize($luckyOne->lottery->box_1_text, $luckyOne->lottery->box_2_text) ?>px; background-color: <?= $luckyOne->lottery->box_2_bg_color ?>; color: <?= $luckyOne->lottery->box_2_text_color ?>;">
                                <small><?= $luckyOne->lottery->box_2_text ?></small>
                            </div>
                        <?php } ?>

                        <?php
                        // IMAGE BOTTOM
                        if ($luckyOne->lottery->image && $luckyOne->lottery->template == 'IMAGE_BOTTOM') { ?>
                            <div class="col ps-3">
                                <img src="<?= \Arshwell\Monolith\Web::site() ?>statics/media/MiniTour-lottery/prizes/<?= $luckyOne->lottery->image ?>" ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
            <div class="col-md-6 text-center">
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
                        <div class="lucky">
                            <small class="thumb d-inline-block px-3" style="background-color: <?= $luckyOne->division->color ?>;">
                                <?= $luckyOne->division->name ?>
                            </small>
                            <br>
                            <div class="thumb-wrapper" style="background-color: <?= $luckyOne->division->color ?>; border-color: <?= $luckyOne->division->color ?>;">
                                <img src="<?= $pdfPlayer->getAvatarUrl() ?>" alt="<?= $pdfPlayer->getShortName() ?>" />
                            </div>
                        </div>

                        <div><?= $pdfPlayer->getShortName() ?></div>
                        <?php
                        if (substr(Arshwell\Monolith\Web::path(), -6) == '/admin' && Arshwell\Monolith\StaticHandler::supervisor()) { ?>
                            <div>
                                <button data-confirmation="true" id="button--rejected-by-lucky-one" class="btn btn-outline-light">
                                    <small>
                                        <b>
                                            <i class="far fa-frown"></i>
                                            draw someone else
                                            <b>
                                    </small>
                                </button>
                                <button id="button--accepted-by-lucky-one" class="btn btn-outline-light">
                                    <small><b>
                                            great, I'll keep it
                                            <i class="far fa-heart"></i>
                                            <b>

                                    </small>
                                </button>
                            </div>
                        <?php } ?>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>
    <?php } ?>
</div>
