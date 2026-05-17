<div class="container padding-2nd-1st">
    <div class="row align-items-end justify-content-center">
        <div class="col-6">
            <a href="<?= \Arshwell\Monolith\Web::url('site.lottery.list') ?>">
                <img alt="ARSH Padel MiniTour" style="max-width: 100%;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour Long E.png' ?>" />
            </a>
            <div class="text-end pe-4">
                <div id="edition">
                    <?php
                    /** @var \Arshavinel\PadelMiniTour\Table\Edition $edition */
                    echo $edition->name;
                    ?>
                    Lottery
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid padding-2nd-2nd">
    <?php
    /** @var array<array{label: string, prizes: array}> $intervals */
    foreach ($intervals as $interval) { ?>
        <h2 class="interval-heading text-center"><?= htmlspecialchars($interval['label']) ?></h2>

        <?php
        foreach ($interval['prizes'] as $prizeEntry) {
            $lottery = $prizeEntry['lottery'];
            $luckies = $prizeEntry['luckies'];
            $hasImage = !empty($lottery->image);
            ?>
            <div class="prize-row text-center">
                <?php
                if ($hasImage || $lottery->box_1_text) {
                    $prizeHeadHasMediaBesideText = $hasImage && $lottery->box_1_text;
                    ?>
                    <div class="prize-head mb-3">
                        <div class="prize-head__body<?= $prizeHeadHasMediaBesideText ? ' prize-head__body--has-media' : '' ?>">
                            <?php
                            if ($hasImage) { ?>
                                <div class="prize-media">
                                    <?= Arshavinel\PadelMiniTour\Helper\LotteryHtmlHelper::renderPrizeImageOrVideo($lottery->image, '', 'prize-media__asset') ?>
                                </div>
                            <?php } ?>
                            <?php
                            if ($lottery->box_1_text) { ?>
                                <div class="d-inline-block p-3 rounded box-text" style="font-size: 40px; background-color: <?= htmlspecialchars($lottery->box_1_bg_color) ?>; color: <?= htmlspecialchars($lottery->box_1_text_color) ?>;">
                                    <?= $lottery->box_1_text ?>
                                </div>
                            <?php } ?>
                            <div class="d-inline-block prize-quantity p-3 box-text">
                                Quantity: <?= (int) $lottery->interval_quantity ?>
                            </div>
                        </div>
                    </div>
                <?php } ?>



                <?php
                if ($luckies === []) { ?>
                    <p class="prize-luckies-empty text-center mb-0">Draws pending</p>
                <?php } else { ?>
                    <div class="row align-items-start justify-content-center">
                        <?php
                        foreach ($luckies as $lucky) {
                            $pdfPlayer = new Arshavinel\PadelMiniTour\DTO\PdfPlayer((int) $lucky->lucky_player_id);
                            $statusClass = 'lucky--' . $lucky->status;
                            ?>
                            <div class="col-auto text-center pb-3">
                                <div class="lucky <?= $statusClass ?>">
                                    <small class="thumb d-inline-block px-3 fz-16 nowrap" style="background-color: <?= htmlspecialchars($lucky->division_color) ?>;">
                                        <?= htmlspecialchars($lucky->division_name) ?>
                                    </small>
                                    <br>
                                    <div class="thumb-wrapper" style="background-color: <?= htmlspecialchars($lucky->division_color) ?>; border-color: <?= htmlspecialchars($lucky->division_color) ?>;">
                                        <img style="max-width: 100%; max-height: 100%;" src="<?= $pdfPlayer->getAvatarUrl() ?>" alt="<?= htmlspecialchars($pdfPlayer->getShortName()) ?>" />
                                    </div>
                                </div>
                                <div class="fz-16 nowrap"><?= htmlspecialchars($pdfPlayer->getShortName()) ?></div>
                                <?php
                                if ($lucky->status !== 'accepted') { ?>
                                    <div class="lucky-status"><?= htmlspecialchars($lucky->status) ?></div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    <?php } ?>

    <?php
    if ($intervals === []) { ?>
        <p class="text-center" style="font-family: Nunito, serif;">No lottery prizes scheduled for this edition.</p>
    <?php } ?>
</div>
