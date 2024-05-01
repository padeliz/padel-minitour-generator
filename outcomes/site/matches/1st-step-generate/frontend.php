<div class="container-fluid padding-2nd-2nd">
    <div class="row">

        <!-- stats -->
        <div class="col-md col-lg-auto">
            <h2><span class="badge bg-dark"><?= $_GET['title'] ?></span></h2>

            <table id="stats-minitour" class="table table-striped margin-1st-2nd">
                <thead>
                    <tr>
                        <th scope="col">General</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td scope="col">there are</td>
                        <td scope="col">
                            <?= count($players) ?> players
                        </td>
                    </tr>
                    <tr>
                        <td scope="col">having total of</td>
                        <td scope="col">
                            <?= count($sortedMatches) ?> matches
                        </td>
                    </tr>
                    <tr>
                        <td scope="col">playing per match</td>
                        <td scope="col">
                            <?= floor($limitTotalPointsPlayed / count($sortedMatches)) ?> points
                        </td>
                    </tr>
                </tbody>
            </table>

            <table id="stats-players" class="table table-striped margin-0-2nd">
                <thead>
                    <tr>
                        <th scope="col">For a player</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td scope="col">playing</td>
                        <td scope="col">
                            <?= $limitPartners ?> matches
                        </td>
                    </tr>
                    <tr>
                        <td scope="col">with</td>
                        <td scope="col">
                            <?= array_sum($countPartners) / count($countPartners) ?> partners
                        </td>
                    </tr>
                    <tr>
                        <td scope="col">as</td>
                        <td scope="col">
                            <?= floor($limitTotalPointsPlayed / count($sortedMatches)) * $limitPartners ?> points
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- see beautified matches -->
            <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.2nd-step-beautify') ?>" target="_blank">
                <input type="hidden" name="title" value="<?= $_GET['title'] ?>" />
                <?php
                array_map(function (int $key, array $match) { ?>
                    <input type="hidden" name="matches[<?= $key ?>][0][0]" value="<?= $match[0][0] ?>" />
                    <input type="hidden" name="matches[<?= $key ?>][0][1]" value="<?= $match[0][1] ?>" />
                    <input type="hidden" name="matches[<?= $key ?>][1][0]" value="<?= $match[1][0] ?>" />
                    <input type="hidden" name="matches[<?= $key ?>][1][1]" value="<?= $match[1][1] ?>" />
                <?php }, array_keys($sortedMatches), $sortedMatches);
                ?>
                <button type="submit" class="btn btn-primary mr-1 mb-1">
                    Preview beautified matches
                </button>
            </form>

            <!-- print PDF -->
            <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.3rd-step-pdfy') ?>" target="_blank">
                <input type="hidden" name="title" value="<?= $_GET['title'] ?>" />
                <?php
                array_map(function (int $key, array $match) { ?>
                    <input type="hidden" name="matches[<?= $key ?>][0][0]" value="<?= $match[0][0] ?>" />
                    <input type="hidden" name="matches[<?= $key ?>][0][1]" value="<?= $match[0][1] ?>" />
                    <input type="hidden" name="matches[<?= $key ?>][1][0]" value="<?= $match[1][0] ?>" />
                    <input type="hidden" name="matches[<?= $key ?>][1][1]" value="<?= $match[1][1] ?>" />
                <?php }, array_keys($sortedMatches), $sortedMatches);
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
                        if ($differentPartnersNumber) { ?>
                            <th scope="col">Partners</th>
                        <?php } ?>
                        <th scope="col">Meeting players</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($players as $i => $player) { ?>
                        <tr>
                            <th scope="row"><?= ($i+1) ?></th>
                            <td data-player-name="<?= $player ?>"><?= $player ?></td>
                            <?php
                            if ($differentPartnersNumber) { ?>
                                <td><?= $countPartners[$player] ?></td>
                            <?php } ?>
                            <td><?= (count($countPlayersMet[$player])) ?> players</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php
            if (count(array_count_values($countPartners)) > 1) { ?>
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
                    foreach ($sortedMatches as $m => $match) { ?>
                        <tr>
                            <th scope="row"><?= ($m+1) ?></th>
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
