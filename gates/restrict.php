<?php

use Arshwell\Monolith\Web;

/**
 * PHP files from gates/ run at every request.
 *
 * You can restrict access or redirect the user as you like.
 */

if (Web::is('site.lottery.item-admin', 'GET')) {
    Web::force('site.lottery.item');
}
