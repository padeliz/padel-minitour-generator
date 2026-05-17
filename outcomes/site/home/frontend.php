<div class="container-fluid padding-2nd-2nd">
    <div class="row justify-content-center">

        <div class="col-md-7 offset-lg-3 col-lg-6 col-xl-5">
            <h2 class="text-dark">Division generator</h2>
            <hr>

            <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.1st-step-generate') ?>" target="_blank">

                <div class="row">

                    <div class="col-md-3 mb-3">
                        <!-- organizer -->
                        <label for="organizer-id" class="form-label">Organizer</label>
                        <select class="form-select" name="organizer-id" id="organizer-id" required>
                            <option></option>
                            <?php
                            foreach (\Arshavinel\PadelMiniTour\Service\EventDivision::ORGANIZERS as $organizerId => $organizerName) { ?>
                                <option value="<?= $organizerId ?>"><?= $organizerName ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <!-- edition -->
                        <label for="edition" class="form-label">Edition</label>
                        <input type="text" class="form-control" name="edition" id="edition" required>
                    </div>

                    <div class="col-md-3 mb-3">
                        <!-- partner -->
                        <label for="partner-id" class="form-label">Powered by</label>
                        <select class="form-select" name="partner-id" id="partner-id" required>
                            <option></option>
                            <?php
                            foreach (\Arshavinel\PadelMiniTour\Service\EventDivision::PARTNERS as $partnerId => $partnerName) { ?>
                                <option value="<?= $partnerId ?>"><?= $partnerName ?></option>
                            <?php } ?>
                        </select>
                    </div>

                </div>

                <div class="row">

                    <div class="col-md-3 mb-3">
                        <!-- division -->
                        <label for="title" class="form-label">Division name</label>
                        <input type="text" class="form-control" name="title" id="title" required>
                    </div>

                    <div class="col-md-3 mb-3">
                        <!-- color -->
                        <label for="color" class="form-label">Color</label>
                        <select class="form-select" name="color" id="color" required>
                            <option></option>
                            <?php
                            foreach (\Arshavinel\PadelMiniTour\Helper\DivisionHelper::DIVISION_COLORS as $division => $color) { ?>
                                <option value="<?= $color ?>"><?= $division ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <!-- court -->
                        <div class="padding-0-1st">
                            <label for="court" class="form-label">Court</label>
                            <input type="text" class="form-control" name="court" id="court" placeholder="e.g: Court 1, etc." required>
                        </div>
                    </div>

                </div>

                <div class="row">

                    <div class="col-md">

                        <div class="row g-0 padding-0-1st">
                            <div class="col">
                                <!-- time start -->
                                <div class="">
                                    <label for="time-start" class="form-label">Time start</label>
                                    <input type="time" class="form-control" name="time-start" id="time-start" value="12:30" aria-describedby="time-start--help" required>
                                </div>
                            </div>
                            <div class="col">
                                <!-- time end -->
                                <div class="">
                                    <label for="time-end" class="form-label">Time end</label>
                                    <input type="time" class="form-control" name="time-end" id="time-end" value="16:30" aria-describedby="time-end--help" required>
                                </div>
                            </div>
                            <div class="form-text">
                                <small id="time-start--help" class="float-start">Starting matches...</small>
                                <small id="time-end--help" class="float-end">...including also the finals.</small>
                            </div>
                        </div>

                        <!-- opponents per player -->
                        <div class="padding-0-1st">
                            <label for="opponents-per-player" class="form-label">Opponents per player</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="opponents-per-player" id="opponents-per-player" value="4" aria-describedby="opponents-per-player--help" aria-label="Partners per player" required>
                                <span class="input-group-text">×</span>
                                <input type="number" class="form-control text-end" name="repeat-partners" placeholder="Repeat partners" aria-label="Repeat partners" value="1" required>
                            </div>
                            <div id="opponents-per-player--help" class="form-text">
                                [opponents] × [repeat] = [matches per player]
                            </div>
                        </div>

                        <!-- include scores -->
                        <div class="padding-0-1st">
                            <input type="checkbox" name="include-scores" checked value="1" id="include-scores">
                            <label class="form-check-label" for="include-scores">
                                Include scores
                            </label>
                        </div>

                        <!-- allow replacements -->
                        <div class="padding-0-1st">
                            <input type="hidden" name="allow-replacements" value="0">
                            <input type="checkbox" name="allow-replacements" checked value="1" id="allow-replacements">
                            <label class="form-check-label" for="allow-replacements">
                                Allow replacements
                            </label>
                        </div>

                        <!-- include final -->
                        <div class="padding-0-1st">
                            <input type="hidden" name="include-final" value="0">
                            <input type="checkbox" name="include-final" checked value="1" id="include-final">
                            <label class="form-check-label" for="include-final">
                                Include final
                            </label>
                        </div>

                        <!-- demonstrative matches -->
                        <div class="padding-0-1st">
                            <input type="checkbox" name="demonstrative-match" value="1" id="demonstrative-match">
                            <label class="form-check-label" for="demonstrative-match">
                                Has demonstrative matches
                            </label>
                        </div>

                        <!-- fixed teams -->
                        <div class="padding-0-1st">
                            <input type="checkbox" name="fixed-teams" value="1" id="fixed-teams">
                            <label class="form-check-label" for="fixed-teams">
                                Fixed teams
                            </label>
                        </div>

                        <!-- adjust points per match -->
                        <div class="row padding-0-1st align-items-center">
                            <div class="col-6">
                                <label for="adjust-points-per-match" class="form-label">
                                    <small>Adjust points<br>per match...</small>
                                </label>
                            </div>
                            <div class="col-6">
                                <select class="form-select" name="adjust-points-per-match" id="adjust-points-per-match">
                                    <option value="-2">-2</option>
                                    <option selected value="0"></option>
                                    <option value="+2">+2</option>
                                    <option value="+4">+4</option>
                                    <option value="+6">+6</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-info mt-1">
                            Generate matches
                            <i class="fas fa-external-link-alt fa-sm"></i>
                        </button>
                    </div>

                    <div class="col-md">
                        <!-- player search -->
                        <div class="mb-3">
                            <label for="player-search" class="form-label" id="player-search-label">Players</label>
                            <input type="text"
                                class="form-control"
                                id="player-search"
                                placeholder="Search players by name..."
                                autocomplete="off">

                            <!-- Dropdown results -->
                            <div id="player-search-results"
                                class="list-group position-absolute"
                                style="display: none; max-height: 300px; overflow-y: auto; z-index: 1000;">
                            </div>
                        </div>

                        <!-- Selected players -->
                        <div class="mb-3">
                            <div id="selected-players">
                                <small class="text-muted">No players selected</small>
                                <!-- Hidden inputs for form submission will be created dynamically -->
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-3">
            <div class="text-end text-secondary py-2">
                <button class="btn" type="button" data-bs-toggle="collapse" data-bs-target="#valid-combinations" aria-expanded="true" aria-controls="valid-combinations">
                    Combinations which will work:
                </button>

                <div class="collapse show" id="valid-combinations">
                    <small>
                        <?php
                        array_map(
                            function (array $partners, int $players) {
                                echo "<br>{$players} players,";
                                echo "<br>partners: ";
                                echo implode(', ', $partners);
                                echo "<br>";
                            },
                            Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator::COMBINATIONS, // partners
                            array_keys(Arshavinel\PadelMiniTour\Service\TemplateMatchesGenerator::COMBINATIONS) // players
                        );
                        ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.PLAYERS_DATA = <?= json_encode($playersData) ?>;
</script>
