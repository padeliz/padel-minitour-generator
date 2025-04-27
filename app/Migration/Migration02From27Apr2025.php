<?php

namespace Arshavinel\PadelMiniTour\Migration;

use Arshavinel\PadelMiniTour\Table\Prize;
use Arshavinel\PadelMiniTour\Table\PrizeFromClub;
use Arshwell\Monolith\DB;

class Migration02From27Apr2025
{
    final public function goUp(): array
    {
        $logs = [];


        /**
         * Prize ADD COLUMN template
         */
        DB::alterTable(Prize::TABLE, 'ADD', 'template', "ENUM('IMAGE_LEFT', 'IMAGE_TOP', 'IMAGE_RIGHT', 'IMAGE_MIDDLE', 'IMAGE_BOTTOM') AFTER image");

        Prize::update([
            'set' => "template = 'IMAGE_RIGHT'"
        ]);

        $logs[] = "Prize ADD COLUMN `template`";


        /**
         * PrizeFromClub ADD COLUMN template
         */
        DB::alterTable(PrizeFromClub::TABLE, 'ADD', 'template', "ENUM('IMAGE_LEFT', 'IMAGE_TOP', 'IMAGE_RIGHT', 'IMAGE_MIDDLE', 'IMAGE_BOTTOM') NULL AFTER image");

        $logs[] = "PrizeFromClub ADD COLUMN `template`";


        return $logs;
    }
}
