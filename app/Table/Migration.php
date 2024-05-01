<?php

namespace Arshavinel\PadelMiniTour\Table;

use Arshwell\Monolith\Table\TableMigration;

final class Migration extends TableMigration {
    const TABLE       = 'migrations';
    const PRIMARY_KEY = 'id_migration';

    static function migrations (): array {
        return array(
            '1.0.0' => function () {
                return "Migration 1.0.0 test successfully run";
            }
        );
    }
}
