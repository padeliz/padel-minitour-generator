<?php

namespace Arshavinel\PadelMiniTour\Table;

use Arshwell\Monolith\Table\TableTranslation;

use Arshavinel\PadelMiniTour\Language\LangSite;

final class Translation extends TableTranslation
{
    static function langsPerWebGroup (): array
    {
        return [
            'site'  => LangSite::class,
            '404'   => [
                'site' => LangSite::class,
            ]
        ];
    }
}
