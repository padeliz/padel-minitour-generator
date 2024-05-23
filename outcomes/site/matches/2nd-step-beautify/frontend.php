<style type="text/css">
    table {
        width: 100%;
    }

    table .column {
        width: 50%;
        vertical-align: top;
        font-size: 40px;
    }

    tr {
        width: 100%;
    }

    td {
        color: black;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>

<div style="text-align: center; width: 100%;">
    <table cellspacing="0" style="text-align: center; width: 100%;" autosize="1">
        <tr>
            <td colspan="2" style="padding-bottom: <?= $marginTop ?>px;">
                <table cellspacing="0" style="width: 100%;" autosize="1">
                    <tr>
                        <td style="width: 33.33%; text-align: left;">
                            <img style="max-width: 100%; width: 400px; max-height: 100px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour Long F.png' ?>" />
                        </td>
                        <td style="width: 33.33%; text-align: left; padding-left: 170px;">
                            <h1 style="font-size: 60px;">
                                <?= $_GET['title'] ?>
                            </h1>
                            <span style="font-size: 20px;">
                                <?= $pointsPerMatch ?> points per match
                            </span>
                        </td>
                        <td style="width: 33.33%; text-align: right;">
                            <img style="max-width: 100%; max-height: 80px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/PadelMania colored.png' ?>" />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="column" style="width: 50%; border-right: 1px solid gray;">
                <?php
                foreach ($_GET['matches'] as $m => $match) {
                    $player1 = new Arshavinel\PadelMiniTour\DTO\Player($match[0][0], "https://i.pravatar.cc/200?u=1{$m}");
                    $player2 = new Arshavinel\PadelMiniTour\DTO\Player($match[0][1], "https://i.pravatar.cc/200?u=2{$m}");
                    $player3 = new Arshavinel\PadelMiniTour\DTO\Player($match[1][0], "https://i.pravatar.cc/200?u=3{$m}");
                    $player4 = new Arshavinel\PadelMiniTour\DTO\Player($match[1][1], "https://i.pravatar.cc/200?u=4{$m}");

                ?>
                    <table <?= ($m > 0 && $m != (($countMatches / 2) + 1) ? 'style="margin-top: ' . $marginTop . 'px;"' : '') ?>>
                        <tr>
                            <td style="width: 21%; text-align: right; padding-right: 17px;">
                                <div style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getFontSize($player1->getShortName()) ?>px;">
                                    <?= $player1->getHtmlShortName() ?>
                                </div>
                                <div style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getFontSize($player2->getShortName()) ?>px;">
                                    <?= $player2->getHtmlShortName() ?>
                                </div>
                            </td>
                            <td style="width: 18%;">
                                <div style="width: 100%; max-width: 100%; text-align: left;">
                                    <img width="195px" src="<?= Arshavinel\PadelMiniTour\Helper\MatchImage\ImageTeamHelper::getImageUrl($player1, $player2) ?>" ?>
                                </div>
                            </td>

                            <?php
                            if (!empty($_GET['include-scores'])) { ?>
                                <td style="width: 9%; vertical-align: bottom;">
                                    <hr>
                                </td>
                                <td style="width: 4%;">
                                    <?php
                                    if ($m % 3 == 0) { ?>
                                        <!-- timestamp -->
                                        <span style="font-size: 15px;"><?= $match[2] ?></span>
                                    <?php } ?>
                                    <hr>
                                </td>
                                <td style="width: 9%; vertical-align: bottom;">
                                    <hr>
                                </td>
                            <?php } else { ?>
                                <td style="width: 22%;">
                                    <!-- timestamp -->
                                    <span style="font-size: 30px;"><?= $match[2] ?></span>
                                </td>
                            <?php } ?>


                            <td style="width: 18%;">
                                <div style="width: 100%; max-width: 100%; text-align: right;">
                                    <img width="195px" src="<?= Arshavinel\PadelMiniTour\Helper\MatchImage\ImageTeamHelper::getImageUrl($player3, $player4) ?>" ?>
                                </div>
                            </td>
                            <td style="width: 21%; text-align: left; padding-left: 17px;">
                                <div style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getFontSize($player3->getShortName()) ?>px;">
                                    <?= $player3->getHtmlShortName() ?>
                                </div>
                                <div style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getFontSize($player4->getShortName()) ?>px;">
                                    <?= $player4->getHtmlShortName() ?>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <?php
                    if ($m == ($countMatches / 2)) { ?>
            </td>
            <td class="column" style="width: 50%; border-left: 1px solid gray;">
            <?php } ?>
        <?php } ?>

        <?php
        if (!empty($_GET['include-scores'])) { ?>
            <!-- final match -->
            <table style="margin-top: <?= $marginTop ?>px;">
                <tr>
                    <td style="width: 21%; text-align: right; padding-right: 17px;">
                        <div style="font-size: 40px; overflow: hidden;">
                            ____________
                        </div>
                        <br><br><br>
                        <div style="font-size: 40px; overflow: hidden;">
                            ____________
                        </div>
                    </td>
                    <td style="width: 18%;">
                        <div style="width: 100%; max-width: 100%; text-align: left;">
                            <img width="195px" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-final-match.jpg' ?>" />
                        </div>
                    </td>

                    <td style="width: 9%; vertical-align: bottom;">
                        <hr>
                    </td>
                    <td style="width: 4%;">
                        <!-- timestamp -->
                        <span style="font-size: 15px;"><?= $match[3] ?></span>
                        <hr>
                    </td>
                    <td style="width: 9%; vertical-align: bottom;">
                        <hr>
                    </td>

                    <td style="width: 18%;">
                        <div style="width: 100%; max-width: 100%; text-align: right;">
                            <img width="195px" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-final-match.jpg' ?>" />
                        </div>
                    </td>
                    <td style="width: 21%; text-align: left; padding-left: 17px;">
                        <div style="font-size: 40px; overflow: hidden;">
                            ____________
                        </div>
                        <br><br><br>
                        <div style="font-size: 40px; overflow: hidden;">
                            ____________
                        </div>
                    </td>
                </tr>
            </table>
        <?php } ?>

            </td>
        </tr>
    </table>

</div>
