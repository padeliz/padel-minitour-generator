<?php

/**
 * PHP files from gates/ run at every request.
 *
 * You can keep global vars here. Like favicon, which is used by the layouts/site/layout.php
 */

use Arshwell\Monolith\Web;

$_GLOBAL = array(
    'favicon' => Web::site() . "statics/media/favicon/site/favicon.ico"
);
