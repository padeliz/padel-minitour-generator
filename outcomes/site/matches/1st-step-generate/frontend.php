<div class="container-fluid padding-2nd-2nd">
    <div class="row">

        <!-- stats -->
        <div class="col-md col-lg-auto">
            <div class="row align-items-center">
                <div class="col-auto">
                    <h2>
                        <span class="badge bg-dark">
                            <?= $eventDivision->getTitle() ?>
                        </span>
                    </h2>
                </div>
                <div class="col-auto">
                    <small class="badge bg-secondary">
                        <?= $eventDivision->getTimeStart() ?> - <?= $eventDivision->getTimeEnd() ?>
                    </small>
                </div>
            </div>


            <table id="stats-minitour" class="table table-striped margin-1st-2nd">
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
                            <?= $eventDivision->getPartnersLimit() ?> matches
                        </td>
                    </tr>
                    <tr>
                        <td>with</td>
                        <td>
                            <?= $eventDivision->getPartnersLimit() ?> partners
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

            <!-- see beautified matches -->
            <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.2nd-step-beautify') ?>" target="_blank">
                <input type="hidden" name="title" value="<?= $eventDivision->getTitle() ?>" />
                <input type="hidden" name="time-start" value="<?= $eventDivision->getTimeStart() ?>" />
                <input type="hidden" name="time-end" value="<?= $eventDivision->getTimeEnd() ?>" />
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
                <button type="submit" class="btn btn-primary mr-1 mb-1">
                    Preview beautified matches
                </button>
            </form>

            <!-- print PDF -->
            <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.3rd-step-pdfy') ?>" target="_blank">
                <input type="hidden" name="title" value="<?= $eventDivision->getTitle() ?>" />
                <input type="hidden" name="time-start" value="<?= $eventDivision->getTimeStart() ?>" />
                <input type="hidden" name="time-end" value="<?= $eventDivision->getTimeEnd() ?>" />
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
                <button type="submit" class="btn btn-success mr-1 mb-1">
                    Print PDF
                </button>
            </form>
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
                        <th scope="col">Home</th>
                        <th scope="col">Away</th>
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
