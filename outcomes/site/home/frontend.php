<div class="container-fluid padding-2nd-2nd">
    <div class="row justify-content-center">

        <div class="col-md-7 offset-lg-3 col-lg-6 col-xl-5">
            <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.1st-step-generate') ?>" target="_blank">

                <!-- title -->
                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" name="title" id="title">
                </div>

                <div class="row">

                    <div class="col-md">

                        <!-- time start -->
                        <div class="padding-0-1st">
                            <label for="time-start" class="form-label">Time start</label>
                            <input type="time" class="form-control" name="time-start" id="time-start" value="12:30" aria-describedby="time-start--help">
                            <div id="time-start--help" class="form-text">Starting matches...</div>
                        </div>

                        <!-- time end -->
                        <div class="padding-0-1st">
                            <label for="time-end" class="form-label">Time end</label>
                            <input type="time" class="form-control" name="time-end" id="time-end" value="16:30" aria-describedby="time-end--help">
                            <div id="time-end--help" class="form-text">...including also the finals.</div>
                        </div>

                        <!-- matches per player -->
                        <div class="padding-0-1st">
                            <label for="partners-per-player" class="form-label">Partners per player</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="partners-per-player" id="partners-per-player" value="4" aria-describedby="partners-per-player--help" aria-label="Partners per player">
                                <span class="input-group-text">×</span>
                                <input type="number" class="form-control text-end" name="repeat-partners" placeholder="Repeat partners" aria-label="Repeat partners" value="1">
                            </div>
                            <div id="partners-per-player--help" class="form-text">All having the same number of partners.</div>
                        </div>

                        <!-- include score -->
                        <div class="padding-0-1st">
                            <input type="checkbox" name="include-scores" checked value="1" id="include-scores">
                            <label class="form-check-label" for="include-scores">
                                Include scores
                            </label>
                        </div>
                    </div>

                    <div class="col-md">
                        <!-- player names -->
                        <div class="mb-3">
                            <label for="players" class="form-label">Players</label>
                            <textarea class="form-control" name="players" id="players" rows="14"></textarea>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Generate matches</button>
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
                        array_map(function ($combination) {
                            echo '<br>' . $combination;
                        }, [
                            "4 players,",
                            "partners: 2, 3",
                            "",
                            "5 players,",
                            "partners: 4",
                            "",
                            "6 players,",
                            "partners: 4",
                            "",
                            "7 players,",
                            "partners: 4",
                            "",
                            "8 players,",
                            "partners: 2, 4, 6, 7",
                            "",
                            "9 players,",
                            "partners: 8",
                            "",
                            "10 players,",
                            "partners: 8",
                            "",
                            "12 players,",
                            "partners: 2, 3, 6, 7, 8, 9",
                            "",
                            "13 players,",
                            "partners: 4",
                            "",
                            "14 players,",
                            "partners: 4, 8",
                            "",
                            "15 players,",
                            "partners: 4, 8",
                            "",
                            "16 players,",
                            "partners: 2, 3, 4, 5, 8",
                        ])
                        ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
