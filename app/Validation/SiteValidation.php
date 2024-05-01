<?php

namespace Arshavinel\PadelMiniTour\Validation;

use Arshwell\Monolith\Table\TableValidation;

use Arshavinel\PadelMiniTour\Language\LangSite;

class SiteValidation extends TableValidation {
    const TABLE         = 'validations_site';
    const PRIMARY_KEY   = NULL;

    const TRANSLATOR    = LangSite::class;
}
