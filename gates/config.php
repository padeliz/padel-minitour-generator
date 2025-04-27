<?php

use Arshwell\Monolith\Folder;
use Arshwell\Monolith\Session;
use Arshavinel\PadelMiniTour\Table\Migration;

date_default_timezone_set('Europe/Bucharest');

/* TODO: Replace this condition with a better solution */
if (Session::isNew() || Folder::mTime('app/Migration') > Folder::mTime('caches')) {
    // check if new migrations have been added

    Migration::syncDatabaseWithModules();
    Migration::migrate();
}
