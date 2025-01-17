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
            <h1 style="font-size: 40px; text-align: left; background-color: <?= $_GET['color'] ?>; color: black;">
                <?= $_GET['title'] ?>
            </h1>
        </td>
        <td colspan="2" style="text-align: right; vertical-align: bottom;">
            <img style="max-width: 100%; max-height: 55px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-partners/partner-' . $_GET['partner-id'] . '.png' ?>" />
        </td>
    </tr>
</table>

<table cellspacing="0" style="width: 100%; margin-top: <?= $marginTop ?>px;" autosize="1">
    <thead style="border-spacing: 0; padding: 0;">
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <?php
            for ($i = 1; $i < $_GET['matches-count']; $i++) { ?>
                <td></td>
                <td></td>
            <?php } ?>
            <td></td>
            <td style="text-align: center; vertical-align: bottom;">points won</td>
            <td></td>

            <?php
            for ($i = 0; $i < $_GET['matches-count']; $i++) { ?>
                <td></td>
            <?php } ?>
            <td></td>
            <td style="text-align: center;">matches won</td>
        </tr>
    </thead>
    <tbody style="border-collapse: separate; border-spacing: 0 <?= $marginTop ?>px;">
        <?php
        foreach ($_GET['players'] as $playerName) {
            $player = new Arshavinel\PadelMiniTour\DTO\PdfPlayer($playerName);
        ?>
            <tr>
                <td style="width: 170px; text-align: right; padding-right: 17px;">
                    <div style="white-space: nowrap; font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getPlayerFontSize($player->getShortName()) ?>px;">
                        <?= $player->getHtmlShortName() ?>
                    </div>
                </td>
                <td style="width: 155px;">
                    <img width="155px" src="<?= $player->getAvatarUrl() ?>" alt="<?= $player->getShortName() ?>" />
                </td>

                <td style="padding: 30px 0; width: 4.5%; color: #d9d9d9; vertical-align: bottom;">
                    <hr style="color: #d9d9d9; border-color: #d9d9d9;">
                </td>
                <?php
                for ($i = 1; $i < $_GET['matches-count']; $i++) { ?>
                    <td style="padding: 0px; font-size: 45px; color: #d9d9d9;">
                        +
                    </td>
                    <td style="padding: 30px 0; width: 4.5%; vertical-align: bottom;">
                        <hr style="color: #d9d9d9; border-color: #d9d9d9;">
                    </td>
                <?php } ?>
                <td style=" font-size: 50px; color: #d9d9d9;">
                    =
                </td>
                <td style="padding: 0 0 20px 10px; font-size: 170px; font-weight: 200; color: #d9d9d9;">
                    ▢
                </td>
                <td style="width: 3.5%;"></td>

                <?php
                for ($i = 0; $i < $_GET['matches-count']; $i++) { ?>
                    <td style="font-size: 40px; color: #d9d9d9; padding: 6px 10px 0 10px;">
                        │
                        <div style="font-size: 20px;">&nbsp;</div>
                        │
                    </td>
                <?php } ?>
                <td style="font-size: 50px; color: #d9d9d9;">
                    =
                </td>
                <td style="padding: 0 0 20px 5px; font-size: 170px; color: #d9d9d9;">
                    □
                </td>
            </tr>
        <?php } ?>
    </tbody>
</table>
