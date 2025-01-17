<div class="container-fluid padding-2nd-2nd">
    <div class="row">

        <!-- stats -->
        <div class="col-md col-lg-auto">
            <table id="stats-event" class="table table-striped">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>edition</td>
                        <td>
                            #<?= $eventDivision->getEdition() ?>
                        </td>
                    </tr>
                    <tr>
                        <td>partner</td>
                        <td>
                            <?= (new \NumberFormatter("en", \NumberFormatter::ORDINAL))->format($eventDivision->getPartnerId()) ?>
                        </td>
                    </tr>
                    <tr>
                        <td>interval</td>
                        <td>
                            <?= $eventDivision->getTimeStart() ?> - <?= $eventDivision->getTimeEnd() ?>
                        </td>
                    </tr>
                    <tr>
                        <td>division</td>
                        <td>
                            <?= $eventDivision->getTitle(); ?>
                            <?php
                            if ($eventDivision->hasDemonstrativeMatch()) { ?>
                                <br>
                                <small><i>has demonstrative match</i></small>
                            <?php } ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <table id="stats-matches" class="table table-striped margin-1st-2nd">
                <thead>
                    <tr>
                        <th>General</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>there are</td>
                        <td>
                            <?= $eventDivision->getPlayersCount() ?> players
                        </td>
                    </tr>
                    <tr>
                        <td>having total of</td>
                        <td>
                            <?= $eventDivision->getMatchesCount() ?> matches
                        </td>
                    </tr>
                    <tr>
                        <td>playing per match</td>
                        <td>
                            <?= $eventDivision->getPointsPerMatch() ?> points
                        </td>
                    </tr>
                </tbody>
            </table>

            <table id="stats-players" class="table table-striped margin-0-2nd">
                <thead>
                    <tr>
                        <th>For a player</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>playing</td>
                        <td>
                            <?= $eventDivision->getOpponentsPerPlayer() * $eventDivision->getRepeatPartners() ?> matches
                        </td>
                    </tr>
                    <tr>
                        <td>with</td>
                        <td>
                            <?= $eventDivision->getOpponentsPerPlayer() ?> partners
                        </td>
                    </tr>
                    <tr>
                        <td>as</td>
                        <td>
                            <?= $eventDivision->getPointsPerPlayer() ?> points
                        </td>
                    </tr>
                </tbody>
            </table>

            <b class="text-primary">Matches board:</b>
            <div>
                <!-- see beautified matches -->
                <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.2nd-step-beautify') ?>" class="d-inline" target="_blank">
                    <input type="hidden" name="edition" value="<?= $eventDivision->getEdition() ?>" />
                    <input type="hidden" name="partner-id" value="<?= $eventDivision->getPartnerId() ?>" />
                    <input type="hidden" name="title" value="<?= $eventDivision->getTitle() ?>" />
                    <input type="hidden" name="color" value="<?= $_GET['color'] ?>" />
                    <input type="hidden" name="time-start" value="<?= $eventDivision->getTimeStart() ?>" />
                    <input type="hidden" name="time-end" value="<?= $eventDivision->getTimeEnd() ?>" />
                    <input type="hidden" name="points-per-match" value="<?= $eventDivision->getPointsPerMatch() ?>" />
                    <input type="hidden" name="include-scores" value="<?= $_GET['include-scores'] ?? 0 ?>" />
                    <input type="hidden" name="demonstrative-match" value="<?= $eventDivision->hasDemonstrativeMatch() ?>" />
                    <input type="hidden" name="fixed-teams" value="<?= $_GET['fixed-teams'] ?? 0 ?>" />
                    <?php
                    array_map(function (int $key, array $match) { ?>
                        <input type="hidden" name="matches[<?= $key ?>][0][0]" value="<?= $match[0][0] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][0][1]" value="<?= $match[0][1] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][1][0]" value="<?= $match[1][0] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][1][1]" value="<?= $match[1][1] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][2]" value="<?= $match[2] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][3]" value="<?= $match[3] ?>" />
                    <?php }, array_keys($eventDivision->getMatches()), $eventDivision->getMatches());
                    ?>
                    <button type="submit" class="btn btn-outline-primary btn-sm mr-1 mb-1">
                        Preview
                        <i class="fas fa-external-link-alt fa-sm"></i>
                    </button>
                </form>

                <!-- print PDF -->
                <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.3rd-step-pdfy') ?>" class="d-inline" target="_blank">
                    <input type="hidden" name="edition" value="<?= $eventDivision->getEdition() ?>" />
                    <input type="hidden" name="partner-id" value="<?= $eventDivision->getPartnerId() ?>" />
                    <input type="hidden" name="title" value="<?= $eventDivision->getTitle() ?>" />
                    <input type="hidden" name="color" value="<?= $_GET['color'] ?>" />
                    <input type="hidden" name="time-start" value="<?= $eventDivision->getTimeStart() ?>" />
                    <input type="hidden" name="time-end" value="<?= $eventDivision->getTimeEnd() ?>" />
                    <input type="hidden" name="points-per-match" value="<?= $eventDivision->getPointsPerMatch() ?>" />
                    <input type="hidden" name="include-scores" value="<?= $_GET['include-scores'] ?? 0 ?>" />
                    <input type="hidden" name="demonstrative-match" value="<?= $eventDivision->hasDemonstrativeMatch() ?>" />
                    <input type="hidden" name="fixed-teams" value="<?= $_GET['fixed-teams'] ?? 0 ?>" />
                    <?php
                    array_map(function (int $key, array $match) { ?>
                        <input type="hidden" name="matches[<?= $key ?>][0][0]" value="<?= $match[0][0] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][0][1]" value="<?= $match[0][1] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][1][0]" value="<?= $match[1][0] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][1][1]" value="<?= $match[1][1] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][2]" value="<?= $match[2] ?>" />
                        <input type="hidden" name="matches[<?= $key ?>][3]" value="<?= $match[3] ?>" />
                    <?php }, array_keys($eventDivision->getMatches()), $eventDivision->getMatches());
                    ?>
                    <button type="submit" class="btn btn-primary btn-sm mr-1 mb-1">
                        PDF matches
                        <i class="fas fa-external-link-alt fa-sm"></i>
                    </button>
                </form>
            </div>

            <b class="text-success d-block margin-2nd-0">Players board:</b>
            <div>
                <!-- see beautified matches -->
                <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.players.1st-step-beautify') ?>" class="d-inline" target="_blank">
                    <input type="hidden" name="edition" value="<?= $eventDivision->getEdition() ?>" />
                    <input type="hidden" name="partner-id" value="<?= $eventDivision->getPartnerId() ?>" />
                    <input type="hidden" name="title" value="<?= $eventDivision->getTitle() ?>" />
                    <input type="hidden" name="color" value="<?= $_GET['color'] ?>" />
                    <input type="hidden" name="matches-count" value="<?= $eventDivision->getOpponentsPerPlayer() * $eventDivision->getRepeatPartners() ?>" />
                    <input type="hidden" name="include-scores" value="<?= $_GET['include-scores'] ?? 0 ?>" />
                    <input type="hidden" name="fixed-teams" value="<?= $_GET['fixed-teams'] ?? 0 ?>" />
                    <?php
                    array_map(function (int $key, string $player) { ?>
                        <input type="hidden" name="players[<?= $key ?>]" value="<?= $player ?>" />
                    <?php }, array_keys($eventDivision->getPlayers()), $eventDivision->getPlayers());
                    ?>
                    <button type="submit" class="btn btn-outline-success btn-sm mr-1 mb-1">
                        Preview
                        <i class="fas fa-external-link-alt fa-sm"></i>
                    </button>
                </form>

                <!-- print PDF -->
                <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.players.2nd-step-pdfy') ?>" class="d-inline" target="_blank">
                    <input type="hidden" name="edition" value="<?= $eventDivision->getEdition() ?>" />
                    <input type="hidden" name="partner-id" value="<?= $eventDivision->getPartnerId() ?>" />
                    <input type="hidden" name="title" value="<?= $eventDivision->getTitle() ?>" />
                    <input type="hidden" name="color" value="<?= $_GET['color'] ?>" />
                    <input type="hidden" name="matches-count" value="<?= $eventDivision->getOpponentsPerPlayer() * $eventDivision->getRepeatPartners() ?>" />
                    <input type="hidden" name="include-scores" value="<?= $_GET['include-scores'] ?? 0 ?>" />
                    <input type="hidden" name="fixed-teams" value="<?= $_GET['fixed-teams'] ?? 0 ?>" />
                    <?php
                    array_map(function (int $key, string $player) { ?>
                        <input type="hidden" name="players[<?= $key ?>]" value="<?= $player ?>" />
                    <?php }, array_keys($eventDivision->getPlayers()), $eventDivision->getPlayers());
                    ?>
                    <button type="submit" class="btn btn-success btn-sm mr-1 mb-1">
                        PDF players
                        <i class="fas fa-external-link-alt fa-sm"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- players -->
        <div class="col-md col-lg-auto">
            <table id="players" class="table table-striped">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Player name</th>
                        <?php
                        if ($eventDivision->hasDifferentPartnersNumber()) { ?>
                            <th scope="col">Partners</th>
                        <?php } ?>
                        <th scope="col">Meeting players</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($eventDivision->getPlayers() as $i => $player) { ?>
                        <tr>
                            <th scope="row"><?= ($i + 1) ?></th>
                            <td data-player-name="<?= $player ?>"><?= $player ?></td>
                            <?php
                            if ($eventDivision->hasDifferentPartnersNumber()) { ?>
                                <td><?= $eventDivision->countPartners($player) ?></td>
                            <?php } ?>
                            <td><?= $eventDivision->countPlayersMet($player) ?> players</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php
            if ($eventDivision->hasDifferentPartnersNumber()) { ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <div>
                        Different partners number
                    </div>
                    <i class="fas fa-exclamation-triangle ms-2"></i>
                </div>
            <?php } ?>
        </div>

        <!-- matches -->
        <div class="col-lg">
            <table id="matches" class="table table-striped">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Matches</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($eventDivision->getMatches() as $m => $match) { ?>
                        <tr>
                            <th scope="row"><?= ($m + 1) ?></th>
                            <td>
                                <span data-player-name="<?= $match[0][0] ?>">
                                    <?= $match[0][0] ?>
                                </span>
                                /
                                <span data-player-name="<?= $match[0][1] ?>">
                                    <?= $match[0][1] ?>
                                </span>
                            </td>
                            <td><?= $match[2] ?></td>
                            <td>
                                <span data-player-name="<?= $match[1][0] ?>">
                                    <?= $match[1][0] ?>
                                </span>
                                /
                                <span data-player-name="<?= $match[1][1] ?>">
                                    <?= $match[1][1] ?>
                                </span>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
