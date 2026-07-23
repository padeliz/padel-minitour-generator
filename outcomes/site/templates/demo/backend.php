<?php

use Arshavinel\PadelMiniTour\DTO\PdfPlayer;
use Arshavinel\PadelMiniTour\Service\TemplateDemoCatalog;
use Arshavinel\PadelMiniTour\Service\TemplateMatchesRepository;
use Arshavinel\PadelMiniTour\Table\Player;
use Arshwell\Monolith\Meta;
use Arshwell\Monolith\Web;

Meta::set('title', 'Template demo');
Meta::set('description', 'Browse template versions with Eligible/Usable status and open demo schedules.');
Meta::set('keywords', 'padel, templates, demo');

$version = (int) Web::param('version');
$catalogService = new TemplateDemoCatalog(new TemplateMatchesRepository());
$error = null;
$catalog = null;

try {
    $filters = $catalogService->parseFiltersFromQuery($_GET);

    $playerRows = Player::select([
        'columns' => 'id_player, name',
    ]);
    $playerIdPool = [];
    foreach ($playerRows as $player) {
        $id = $player->id();
        if ($id === null) {
            continue;
        }
        if (!PdfPlayer::hasStaticPhoto((string) $player->name)) {
            continue;
        }
        $playerIdPool[] = $id;
    }

    $catalog = $catalogService->buildCatalog($version, $filters, $playerIdPool);
} catch (\InvalidArgumentException | \RuntimeException $e) {
    $error = $e->getMessage();
}
