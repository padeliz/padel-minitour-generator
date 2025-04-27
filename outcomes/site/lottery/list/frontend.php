<div class="container">
    <div class="row justify-content-center align-items-center">
        <div class="col-md-8 col-lg-6 col-xl-5 padding-0-2nd">
            <img alt="ARSH Padel MiniTour" src="<?= Arshwell\Monolith\Web::site() . 'statics/media/MiniTour Long E.png' ?>" />
        </div>
    </div>
    <div class="row justify-content-center align-items-center">
        <?php
        array_map(function (Arshavinel\PadelMiniTour\Table\Edition $edition) { ?>
            <div class="col-md-8 col-lg-6 col-xl-5 padding-0-2nd">
                <div class="lottery">
                    <a class="d-block" href="<?= \Arshwell\Monolith\Web::url('site.lottery.item', ['id' => $edition->id()]) ?>">
                        <div class="padding-2nd-2nd">
                            <?= (new \DateTime($edition->date))->format("d M Y") ?>
                            <h1 class="edition-name m-0"><?= $edition->name ?></h1>
                            <?= $edition->location_name ?>
                        </div>
                    </a>
                </div>
            </div>
        <?php }, array_slice($editions, 0, 1)); ?>
    </div>
    <div class="row justify-content-center align-items-center">
        <?php
        array_map(function (Arshavinel\PadelMiniTour\Table\Edition $edition) { ?>
            <div class="col-md-6 col-lg-4 col-xl-3 padding-0-2nd">
                <div class="lottery">
                    <a class="d-block" href="<?= \Arshwell\Monolith\Web::url('site.lottery.item', ['id' => $edition->id()]) ?>">
                        <div class="padding-2nd-2nd">
                            <?= (new \DateTime($edition->date))->format("d M Y") ?>
                            <h2 class="edition-name m-0"><?= $edition->name ?></h2>
                            <?= $edition->location_name ?>
                        </div>
                    </a>
                </div>
            </div>
        <?php }, array_slice($editions, 1)); ?>
    </div>
</div>
