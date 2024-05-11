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
                    <!-- time start -->
                    <div class="col-md">
                        <div class="mb-3">
                            <label for="time-start" class="form-label">Time start</label>
                            <input type="time" class="form-control" name="time-start" id="time-start" value="14:00" aria-describedby="time-start--help">
                            <div id="time-start--help" class="form-text">Starting matches...</div>
                        </div>
                    </div>

                    <!-- time end -->
                    <div class="col-md">
                        <div class="mb-3">
                            <label for="time-end" class="form-label">Time end</label>
                            <input type="time" class="form-control" name="time-end" id="time-end" value="18:00" aria-describedby="time-end--help">
                            <div id="time-end--help" class="form-text">...including also the finals.</div>
                        </div>
                    </div>

                    <!-- matches per player -->
                    <div class="col-md">
                        <div class="mb-3">
                            <label for="matches-per-player" class="form-label">Matches per player</label>
                            <input type="number" class="form-control" name="limit-partners" id="matches-per-player" value="4" aria-describedby="matches-per-player--help">
                            <div id="matches-per-player--help" class="form-text">All playing the same number of matches.</div>
                        </div>
                    </div>
                </div>

                <!-- player names -->
                <div class="mb-3">
                    <label for="players" class="form-label">Players</label>
                    <textarea class="form-control" name="players" id="players" rows="14"></textarea>
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
