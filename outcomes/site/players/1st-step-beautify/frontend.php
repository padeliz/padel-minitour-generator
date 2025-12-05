<link href="https://fonts.googleapis.com/css?family=Open%20Sans" rel="stylesheet">

<style type="text/css">
    table {
        width: 100%;
    }

    table .column {
        vertical-align: top;
        font-size: 40px;
    }

    tr {
        width: 100%;
    }

    td {
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: middle;
    }
</style>

<table cellspacing="0" style="table-layout: fixed; width: 100%;" autosize="1">
    <tr>
        <td colspan="2" style="text-align: left; vertical-align: bottom;">
            <div>
                <img style="max-width: 100%; width: 280px; max-height: 80px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour LongDown F.png' ?>" />
                <span style="font-size: 40px;">&nbsp;</span>
                <b style="font-family: sans; font-size: 40px; color: #a9d78b;">
                    <?= is_numeric($_GET['edition']) ? '#' : '' ?><span style="color: #7ab857;"><?= $_GET['edition'] ?></span>
                </b>
            </div>
        </td>
        <td colspan="2" style="text-align: left; padding-left: 50px;">
            <h1 style="font-size: 40px; text-align: left; background-color: <?= $_GET['color'] ?>; color: white;">
                <?= $_GET['title'] ?>
            </h1>
            <?php
            if (!empty($_GET['include-scores'])) { ?>
                <b style="font-size: 18px;">
                    <?= $_GET['court'] ?>
                    •
                    <?= $pointsPerMatch + (int)($_GET['adjust-points-per-match'] ?? 0) ?> points per match
                </b>
            <?php } ?>
        </td>
        <td colspan="2" style="text-align: right; vertical-align: bottom;">
            <img style="max-width: 100%; max-height: 55px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-partners/partner-' . $_GET['partner-id'] . '.png' ?>" />
        </td>
    </tr>
</table>

<table cellspacing="0" style="width: 100%; margin-top: <?= $marginTop ?>px;" autosize="1">
    <?php
    $matchesRows = Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::splitMatchRankingRows($countPlayers, $_GET['player-matches-count']);
    $nrOfRows = count($matchesRows);

    // there is not enough space on the page where there are 12 players
    if (count($_GET['player-ids']) < 12) { ?>
        <thead style="border-spacing: 0; padding: 0;">
            <tr>
                <td></td>
                <td></td>
                <td></td>
                <?php
                for ($i = 1; $i < max($matchesRows); $i++) { ?>
                    <td></td>
                    <td></td>
                <?php } ?>
                <td></td>
                <td style="text-align: center; vertical-align: bottom;">points won</td>
                <td></td>

                <?php
                for ($i = 0; $i < $_GET['player-matches-count']; $i++) { ?>
                    <td></td>
                <?php } ?>
                <td></td>
                <td style="text-align: center;">matches won</td>
            </tr>
        </thead>
    <?php } ?>
    <tbody style="border-collapse: separate; border-spacing: 0 <?= $marginTop ?>px;">
        <?php
        /** @var \Arshavinel\PadelMiniTour\DTO\PdfPlayer[] $pdfPlayers */
        foreach ($pdfPlayers as $pdfPlayer) {
            foreach ($matchesRows as $mr => $matchRow) { ?>
                <tr>
                    <?php
                    if ($mr == 0) { ?>
                        <td rowspan="<?= $nrOfRows ?>" style="width: 170px; text-align: right; padding-right: 17px; align-items: center;">
                            <div style="white-space: nowrap; font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getPlayerFontSize($pdfPlayer->getShortName()) ?>px;">
                                <?= $pdfPlayer->getHtmlShortName() ?>
                            </div>
                        </td>
                        <td rowspan="<?= $nrOfRows ?>" style="width: 155px; align-items: center;">
                            <img
                                width="<?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['img-width'] ?>"
                                src="<?= $pdfPlayer->getAvatarUrl() ?>"
                                alt="<?= $pdfPlayer->getShortName() ?>" />
                        </td>
                    <?php } ?>

                    <td style="padding: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['points-slot-padding'] ?>; width: 4.5%; vertical-align: bottom;">
                        <hr style="color: #b9b9b9; border-color: #b9b9b9;">
                    </td>
                    <?php
                    for ($i = 1; $i < $matchRow; $i++) { ?>
                        <td style="padding: 0px; font-size: 45px; color: #b9b9b9; text-align: center; height: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['img-width'] ?>;">
                            +
                        </td>
                        <td style="padding: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['points-slot-padding'] ?>; width: 4.5%; vertical-align: bottom;">
                            <hr style="color: #b9b9b9; border-color: #b9b9b9;">
                        </td>
                    <?php } ?>
                    <td style="font-size: 50px; color: #d9d9d9;">
                        <?php
                        if (($mr + 1) == $nrOfRows) { ?>
                            =
                        <?php } ?>
                    </td>
                    <td style="padding: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['total-points-padding'] ?>; font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['total-points-font-size'] ?>; font-weight: 200; color: #d9d9d9;">
                        <?php
                        if (($mr + 1) == $nrOfRows) { ?>
                            ▢
                        <?php } ?>
                    </td>
                    <td style="width: 3.5%;"></td>

                    <?php
                    for ($i = 0; $i < $_GET['player-matches-count']; $i++) { ?>
                        <?php
                        if (($mr + 1) == $nrOfRows) { ?>
                            <td style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['match-slot-font-size'] ?>; color: #b9b9b9; padding: 6px 10px 0 10px;">
                                │
                                <div style="font-size: 20px;">&nbsp;</div>
                                │
                            </td>
                        <?php } ?>
                    <?php } ?>
                    <td style="font-size: 50px; color: #d9d9d9;">
                        <?php
                        if (($mr + 1) == $nrOfRows) { ?>
                            =
                        <?php } ?>
                    </td>
                    <td style="padding: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['total-matches-padding'] ?>; font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::PLAYERS_ROWS_SIZING[$nrOfRows]['total-matches-font-size'] ?>; color: #d9d9d9;">
                        <?php
                        if (($mr + 1) == $nrOfRows) { ?>
                            □
                        <?php } ?>
                    </td>
                </tr>
            <?php }

            if ($countPlayers <= 5) { ?>
                <tr>
                    <td style="height: <?= $marginTop ?>px;"></td>
                </tr>
        <?php }
        } ?>
    </tbody>
</table>
