<style type="text/css">
    table {
        width: 100%;
    }
    table .column {
        vertical-align: top;
        font-size: 40px;
    }
    td {
        color: black;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>

<div style="text-align: center; width: 100%;">

    <h1><?= $_GET['title'] ?></h1>

    <table cellspacing="0" style="text-align: center; width: 100%;" autosize="1">
        <tr>
            <td class="column" style="border-right: 1px solid gray;">
                <?php
                $countMatches = count($_GET['matches']);

                foreach ($_GET['matches'] as $m => $match) {
                    $player1 = new Arshavinel\PadelMiniTour\DTO\Player($match[0][0], "https://i.pravatar.cc/200?u=1{$m}");
                    $player2 = new Arshavinel\PadelMiniTour\DTO\Player($match[0][1], "https://i.pravatar.cc/200?u=2{$m}");
                    $player3 = new Arshavinel\PadelMiniTour\DTO\Player($match[1][0], "https://i.pravatar.cc/200?u=3{$m}");
                    $player4 = new Arshavinel\PadelMiniTour\DTO\Player($match[1][1], "https://i.pravatar.cc/200?u=4{$m}");

                    ?>
                    <table style="margin-top: <?= $marginTop ?>px;">
                        <tr>
                            <td style="width: 21%; text-align: right; padding-right: 17px;">
                                <div style="font-size: <?= getFontSize($player1->getShortName()) ?>px;">
                                    <?= $player1->getShortName() ?>
                                </div>
                                <div style="font-size: <?= getFontSize($player2->getShortName()) ?>px;">
                                    <?= $player2->getShortName() ?>
                                </div>
                            </td>
                            <td style="width: 18%;">
                                <div style="width: 100%; max-width: 100%; text-align: left;">
                                    <img src="<?= Arshavinel\PadelMiniTour\Service\MatchImage\ImageTeamService::getImageUrl($player1, $player2) ?>" ?>
                                </div>
                            </td>

                            <td style="width: 9%; vertical-align: bottom;">
                                <hr>
                            </td>
                            <td style="width: 4%;">
                                <hr>
                            </td>
                            <td style="width: 9%; vertical-align: bottom;">
                                <hr>
                            </td>

                            <td style="width: 18%;">
                                <div style="width: 100%; max-width: 100%; text-align: right;">
                                    <img src="<?= Arshavinel\PadelMiniTour\Service\MatchImage\ImageTeamService::getImageUrl($player3, $player4) ?>" ?>
                                </div>
                            </td>
                            <td style="width: 21%; text-align: left; padding-left: 17px;">
                                <div style="font-size: <?= getFontSize($player3->getShortName()) ?>px;">
                                    <?= $player3->getShortName() ?>
                                </div>
                                <div style="font-size: <?= getFontSize($player4->getShortName()) ?>px;">
                                    <?= $player4->getShortName() ?>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <?php
                    if ($m == ($countMatches / 2)-1) { ?>
                        </td><td class="column" style="border-left: 1px solid gray;">
                    <?php } ?>
                <?php } ?>

                <!-- final match -->
                <table style="margin-top: <?= $marginTop ?>px;">
                    <tr>
                        <td style="width: 21%; text-align: right; padding-right: 17px;">
                            <div style="font-size: 40px; overflow: hidden;">
                                ____________
                            </div>
                            <div style="font-size: 40px; overflow: hidden; margin-top: 50px;">
                                ____________
                            </div>
                        </td>
                        <td style="width: 18%;">
                            <div style="width: 100%; max-width: 100%; text-align: left;">
                                <img style="max-width: 200px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-final-match.jpg' ?>" />
                            </div>
                        </td>

                        <td style="width: 9%; vertical-align: bottom;">
                            <hr>
                        </td>
                        <td style="width: 4%;">
                            <hr>
                        </td>
                        <td style="width: 9%; vertical-align: bottom;">
                            <hr>
                        </td>

                        <td style="width: 18%;">
                            <div style="width: 100%; max-width: 100%; text-align: right;">
                                <img style="max-width: 200px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-final-match.jpg' ?>" />
                            </div>
                        </td>
                        <td style="width: 21%; text-align: left; padding-left: 17px;">
                            <div style="font-size: 40px; overflow: hidden; height: 100px;">
                                ____________
                            </div>
                            <div style="font-size: 40px; overflow: hidden; height: 100px;">
                                ____________
                            </div>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>

</div>
