<?php

use Arshwell\Monolith\Meta;

Meta::set('title',			'404');
Meta::set('description',	'404');
Meta::set('keywords',		'404');

http_response_code(404);
