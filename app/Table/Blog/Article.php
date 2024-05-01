<?php

namespace Arshavinel\PadelMiniTour\Table\Blog;

use Arshwell\Monolith\Table;

use Arshavinel\PadelMiniTour\Language\LangSite;

final class Article extends Table
{
    const TABLE       = 'articles';
    const PRIMARY_KEY = 'id_article';

    const TRANSLATOR = LangSite::class;

    const FILES = array(
        'banner' => array(
            'quality'   => 100,
            'sizes'     => array(
                'small' => array(
                    'width' => 300,
                    'height' => 300
                ),
                'big'   => array(
                    'width' => 1000,
                    'height' => 800
                )
            )
        )
    );
}
