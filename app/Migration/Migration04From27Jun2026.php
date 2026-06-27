<?php

namespace Arshavinel\PadelMiniTour\Migration;

use Arshavinel\PadelMiniTour\Table\Player;
use Arshwell\Monolith\DB;

class Migration04From27Jun2026
{
    private const TABLE_PLAYER_EMAILS = 'player_emails';
    private const TABLE_PLAYER_PHONES = 'player_phones';

    private const PRIMARY_KEY_PLAYER_EMAIL = 'id_player_email';
    private const PRIMARY_KEY_PLAYER_PHONE = 'id_player_phone';

    final public function goUp(): array
    {
        $logs = [];

        /**
         * CREATE TABLE player_emails
         */
        DB::createTable(self::TABLE_PLAYER_EMAILS, [
            "`" . self::PRIMARY_KEY_PLAYER_EMAIL . "` INT(11) AUTO_INCREMENT NOT NULL",
            "`player_id` INT(11) NOT NULL",
            "`email` VARCHAR(255) NOT NULL",
            "`inserted_at` INT(11) NOT NULL",
            "`updated_at` INT(11) NULL",
            "PRIMARY KEY (`" . self::PRIMARY_KEY_PLAYER_EMAIL . "`)",
            "CONSTRAINT `fk_player_email_player` FOREIGN KEY (`player_id`) REFERENCES " . Player::TABLE . " (`" . Player::PRIMARY_KEY . "`) ON DELETE CASCADE",
        ]);

        $logs[] = "CREATE TABLE `" . self::TABLE_PLAYER_EMAILS . "`";


        /**
         * CREATE TABLE player_phones
         */
        DB::createTable(self::TABLE_PLAYER_PHONES, [
            "`" . self::PRIMARY_KEY_PLAYER_PHONE . "` INT(11) AUTO_INCREMENT NOT NULL",
            "`player_id` INT(11) NOT NULL",
            "`phone` VARCHAR(30) NOT NULL",
            "`inserted_at` INT(11) NOT NULL",
            "`updated_at` INT(11) NULL",
            "PRIMARY KEY (`" . self::PRIMARY_KEY_PLAYER_PHONE . "`)",
            "CONSTRAINT `fk_player_phone_player` FOREIGN KEY (`player_id`) REFERENCES " . Player::TABLE . " (`" . Player::PRIMARY_KEY . "`) ON DELETE CASCADE",
        ]);

        $logs[] = "CREATE TABLE `" . self::TABLE_PLAYER_PHONES . "`";

        return $logs;
    }

    final public function goDown(): array
    {
        $logs = [];

        DB::dropTable(self::TABLE_PLAYER_PHONES);
        $logs[] = "DROP TABLE `" . self::TABLE_PLAYER_PHONES . "`";

        DB::dropTable(self::TABLE_PLAYER_EMAILS);
        $logs[] = "DROP TABLE `" . self::TABLE_PLAYER_EMAILS . "`";

        return $logs;
    }
}
