<div class="container-fluid padding-2nd-2nd">
    <div class="row align-items-center justify-content-center">
        <div class="col-md-7 col-lg-6 col-xl-5">
            <form method="GET" action="<?= Arshwell\Monolith\Web::url('site.matches.1st-step-generate') ?>" target="_blank">

                <!-- title -->
                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" name="title" id="title">
                </div>

                <div class="row">
                    <!-- hours -->
                    <div class="col-md">
                        <div class="mb-3">
                            <label for="time-in-hours" class="form-label">Hours for the even</label>
                            <input type="number" class="form-control" name="time-in-hours" id="time-in-hours" value="3" aria-describedby="time-in-hours--help">
                            <div id="time-in-hours--help" class="form-text">Including also the raffle and the finals.</div>
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
    </div>
</div>
