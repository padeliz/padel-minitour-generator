<?php

namespace Arshavinel\PadelMiniTour\Table;

use Arshwell\Monolith\File;
use Arshwell\Monolith\Module\Backend;
use Arshwell\Monolith\Table\TableMigration;

final class Migration extends TableMigration
{
    const TABLE       = 'migrations';
    const PRIMARY_KEY = 'id_migration';


    static function migrations(): array
    {
        $files = File::folder('app/Migration', ['php'], true, false);

        sort($files, SORT_STRING);

        $migrations = [];


        foreach ($files as $file) {
            $class = str_replace(['/', '\\\\'], '\\', File::name(preg_replace("/^" . preg_quote('app/', '/') . "/", 'Arshavinel\\PadelMiniTour\\', $file), false));

            $migrations[$class] = function () use ($class) {
                $migration = new $class();

                $logs = $migration->goUp();

                return self::insert(
                    "migration, logs, inserted_at",
                    "?, ?, UNIX_TIMESTAMP()",
                    array($class, serialize($logs))
                );

                return implode(' | ', $logs);
            };
        }

        // migrations which are not logged in database
        return array_diff_key($migrations, array_flip(self::column('migration')));
    }

    static function syncDatabaseWithModules()
    {
        Backend::buildDB(
            array(
                'conn' => 'padel_minitour',
                'table' => Migration::class,
            ),
            array(
                // features doesn't matter
            ),
            array(
                'migration' => array(
                    'DB' => array(
                        'column'    => 'migration',
                        'type'      => 'varchar'
                    )
                ),

                'logs' => array(
                    'DB' => array(
                        'column'    => 'logs',
                        'type'      => 'text'
                    )
                ),

                'inserted_at' => array(
                    'DB' => array(
                        'column'    => 'inserted_at',
                        'type'      => 'int'
                    )
                ),
            )
        );

        // Sync new modules with DB
        foreach (File::rFolder('outcomes') as $file) {
            if (basename($file) == 'back.module.php') {
                $back = call_user_func(function () use ($file) {
                    return require($file);
                });

                if (
                    !empty($back['DB']) && is_array($back['DB']) && class_exists($back['DB']['table'])
                    && !empty($back['fields']) && is_array($back['fields'])
                ) {
                    Backend::buildDB($back['DB'], $back['features'], $back['fields']);
                }
            }
        }
    }
}
