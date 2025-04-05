<?php

use Arshwell\Monolith\Web;

if (Web::inGroup('site')) {
    foreach (glob("gates/site/*.php") as $file) {
        require_once $file;
	}
}
