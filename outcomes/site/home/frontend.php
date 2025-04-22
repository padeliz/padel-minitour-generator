<div class="container-fluid padding-2nd-2nd">
    <div class="row justify-content-center">

        <div class="col-md-7 offset-lg-3 col-lg-6 col-xl-5">
            <h2 class="text-dark">Division generator</h2>
            <hr>

            <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.1st-step-generate') ?>" target="_blank">

                <div class="row">

                    <div class="col-md-3 mb-3">
                        <!-- edition -->
                        <label for="edition" class="form-label">Edition</label>
                        <input type="number" class="form-control" min="1" max="100" name="edition" id="edition" required>
                    </div>

                    <div class="col-md-3 mb-3">
                        <!-- partner -->
                        <label for="partner-id" class="form-label">Powered by</label>
                        <select class="form-control" name="partner-id" id="partner-id" required>
                            <option></option>
                            <option value="1">PadelMania</option>
                            <option value="2">Padel One</option>
                            <option value="3">Padel World</option>
                            <option value="4">Magic Padel</option>
                        </select>
                    </div>

                    <div class="col-md-3 mb-3">
                        <!-- title -->
                        <label for="title" class="form-label">Division name</label>
                        <input type="text" class="form-control" name="title" id="title" required>
                    </div>

                    <div class="col-md-3 mb-3">
                        <!-- color -->
                        <label for="color" class="form-label">Color</label>
                        <select class="form-control" name="color" id="color" required>
                            <option></option>
                            <option value="#ffff00">Students Rookies</option>
                            <option value="#ffa500">Students Beginners</option>
                            <option value="#cb2b2b">Students Novice</option>
                            <option value="#89bdce">Adults Explorers</option>
                            <option value="#e476a7">Adults Learners</option>
                            <option value="#e476a7">Adults Mixt</option>
                        </select>
                    </div>

                    <div class="col-md">

                        <!-- time start -->
                        <div class="padding-0-1st">
                            <label for="time-start" class="form-label">Time start</label>
                            <input type="time" class="form-control" name="time-start" id="time-start" value="12:30" aria-describedby="time-start--help" required>
                            <div id="time-start--help" class="form-text">Starting matches...</div>
                        </div>

                        <!-- time end -->
                        <div class="padding-0-1st">
                            <label for="time-end" class="form-label">Time end</label>
                            <input type="time" class="form-control" name="time-end" id="time-end" value="16:30" aria-describedby="time-end--help" required>
                            <div id="time-end--help" class="form-text">...including also the finals.</div>
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
                    </div>

                    <div class="col-md">
                        <!-- player names -->
                        <div class="mb-3">
                            <label for="players" class="form-label">Players</label>
                            <textarea class="form-control" name="players" id="players" rows="14" required></textarea>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-info mt-1">
                    Generate matches
                    <i class="fas fa-external-link-alt fa-sm"></i>
                </button>
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
