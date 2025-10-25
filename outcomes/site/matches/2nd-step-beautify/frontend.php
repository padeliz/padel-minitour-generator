<link href="https://fonts.googleapis.com/css?family=Open%20Sans" rel="stylesheet">

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
                        <td style="width: 30%; text-align: left;">
                            <img style="max-width: 100%; width: 400px; max-height: 100px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour LongDown F.png' ?>" />
                            <span style="font-size: 60px;">&nbsp;</span>
                            <b style="font-family: sans; font-size: 60px; color: #a9d78b;">
                                <?= is_numeric($_GET['edition']) ? '#' : '' ?><span style="color: #7ab857;"><?= $_GET['edition'] ?></span>
                            </b>
                        </td>
                        <td style="width: 36%; text-align: left; padding-left: 180px;">
                            <h1 style="font-size: 60px; background-color: <?= $_GET['color'] ?>; color: white;">
                                <?= $_GET['title'] ?>
                            </h1>
                            <?php
                            if (!empty($_GET['include-scores'])) { ?>
                                <b style="font-size: 24px;">
                                    <?= $pointsPerMatch + (int)($_GET['adjust-points-per-match'] ?? 0) ?> points per match
                                </b>
                            <?php } ?>
                        </td>
                        <td style="width: 34%; text-align: right;">
                            <img style="max-width: 100%; max-height: 80px;" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-partners/partner-' . $_GET['partner-id'] . '.png' ?>" />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="column" style="width: 50%; border-right: 1px solid gray;">
                <img height="180px" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-prints/MiniTour-step-stretching.jpg' ?>" />
                <br>
                <span style="font-size: 18px;">
                    <?= date('H:i', strtotime($_GET['matches'][0][2] . ' -15 minutes')) ?>
                    - we start with stretching
                </span>

                <?php
                foreach ($_GET['matches'] as $m => $match) {
                    $pdfPlayer1 = new Arshavinel\PadelMiniTour\DTO\PdfPlayer($match[0][0], in_array($match[0][0], $_GET['players-collecting-points']));
                    $pdfPlayer2 = new Arshavinel\PadelMiniTour\DTO\PdfPlayer($match[0][1], in_array($match[0][1], $_GET['players-collecting-points']));
                    $pdfPlayer3 = new Arshavinel\PadelMiniTour\DTO\PdfPlayer($match[1][0], in_array($match[1][0], $_GET['players-collecting-points']));
                    $pdfPlayer4 = new Arshavinel\PadelMiniTour\DTO\PdfPlayer($match[1][1], in_array($match[1][1], $_GET['players-collecting-points']));
                ?>
                    <table <?= ($m != (ceil($countMatches / 2)) ? 'style="margin-top: ' . $marginTop . 'px;"' : '') ?>>
                        <tr>
                            <td style="width: 21%; text-align: right; padding-right: 17px;">
                                <div style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getFontSize($pdfPlayer1) ?>px;">
                                    <?= $pdfPlayer1->getHtmlShortName() ?>
                                </div>
                                <div style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getFontSize($pdfPlayer2) ?>px;">
                                    <?= $pdfPlayer2->getHtmlShortName() ?>
                                </div>
                            </td>
                            <td style="width: 18%;">
                                <div style="width: 100%; max-width: 100%; text-align: left;">
                                    <img width="195px" src="<?= Arshavinel\PadelMiniTour\Helper\MatchImage\ImageTeamHelper::getImageUrl($pdfPlayer1, $pdfPlayer2) ?>" ?>
                                </div>
                            </td>

                            <?php
                            if (!empty($_GET['include-scores'])) { ?>
                                <td style="width: 9%; vertical-align: bottom;">
                                    <hr>
                                </td>
                                <td style="width: 4%;">
                                    <!-- timestamp -->
                                    <span style="font-size: 15px;"><?= $match[2] ?></span>
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
                                    <img width="195px" src="<?= Arshavinel\PadelMiniTour\Helper\MatchImage\ImageTeamHelper::getImageUrl($pdfPlayer3, $pdfPlayer4) ?>" ?>
                                </div>
                            </td>
                            <td style="width: 21%; text-align: left; padding-left: 17px;">
                                <div style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getFontSize($pdfPlayer3) ?>px;">
                                    <?= $pdfPlayer3->getHtmlShortName() ?>
                                </div>
                                <div style="font-size: <?= Arshavinel\PadelMiniTour\Helper\PdfHtmlHelper::getFontSize($pdfPlayer4) ?>px;">
                                    <?= $pdfPlayer4->getHtmlShortName() ?>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <?php
                    if ($m == ceil($countMatches / 2) - 1) { ?>
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
                            ___________
                        </div>
                        <br><br><br>
                        <div style="font-size: 40px; overflow: hidden;">
                            ___________
                        </div>
                    </td>
                    <td style="width: 18%;">
                        <div style="width: 100%; max-width: 100%; text-align: left;">
                            <img width="195px" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-prints/MiniTour-finalist-team.jpg' ?>" />
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
                            <img width="195px" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-prints/MiniTour-finalist-team.jpg' ?>" />
                        </div>
                    </td>
                    <td style="width: 21%; text-align: left; padding-left: 17px;">
                        <div style="font-size: 40px; overflow: hidden;">
                            ___________
                        </div>
                        <br><br><br>
                        <div style="font-size: 40px; overflow: hidden;">
                            ___________
                        </div>
                    </td>
                </tr>
            </table>
            <span style="font-size: 18px;">final: total of 24 points played</span>
        <?php } ?>

        <?php
        if ($hasDemonstrativeMatch) { ?>
            <!-- demonstrative match -->
            <table style="margin-top: <?= $marginTop ?>px;">
                <tr>
                    <td style="width: 21%; text-align: center; padding-right: 17px;">
                        <div style="font-size: 30px;">
                            experienced
                            <div style="font-size: 10px;">&nbsp;</div>
                            padel players
                        </div>
                    </td>
                    <td style="width: 18%;">
                        <div style="width: 100%; max-width: 100%; text-align: left;">
                            <img width="195px" src="<?= Arshwell\Monolith\Web::site() . Arshwell\Monolith\File::first('statics/media/MiniTour-prints/special-guests') ?>" />
                        </div>
                    </td>

                    <td style="width: 6%;"></td>
                    <td style="width: 10%;">
                        <!-- timestamp -->
                        <span style="font-size: 22px;"><?= DateTime::createFromFormat('H:i', $_GET['time-end'])->modify('-15 minutes')->format('H:i') ?></span>
                        <hr>
                        <span style="font-size: 16px; color: grey;">also 24 points to play</span>
                    </td>
                    <td style="width: 6%;"></td>

                    <td style="width: 18%;">
                        <div style="width: 100%; max-width: 100%; text-align: right;">
                            <img width="195px" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour-prints/MiniTour-best-teams.png' ?>" />
                        </div>
                    </td>
                    <td style="width: 21%; text-align: center; padding-left: 17px;">
                        <div style="font-size: 40px; overflow: hidden;">
                            1st place only
                        </div>
                    </td>
                </tr>
            </table>
        <?php } ?>

            </td>
        </tr>
    </table>
</div>
