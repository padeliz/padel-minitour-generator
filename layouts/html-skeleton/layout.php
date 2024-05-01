<!DOCTYPE html>
<head>
    <title><?= Arshwell\Monolith\Meta::get('title') ?></title>

    <!-- favicon -->
    <link href="<?= Arshwell\Monolith\Web::site() ?>statics/media/favicon/site/favicon.ico" rel="shortcut icon" type="image/png" />

    <meta charset="utf-8">
    <meta name="language" http-equiv="content-language" content="<?= Arshavinel\PadelMiniTour\Language\LangSite::get() ?>">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <?php
    if (Arshwell\Monolith\Meta::exists('description')) { ?>
        <meta name="description" itemprop="description" content="<?= Arshwell\Monolith\Meta::get('description') ?>">
    <?php }
    if (Arshwell\Monolith\Meta::exists('keywords')) { ?>
        <meta name="keywords" itemprop="keywords" content="<?= Arshwell\Monolith\Meta::get('keywords') ?>">
    <?php } ?>
    <meta name="expires" content="never">
    <meta name="revisit-after" content="1 Days">

    <meta name="robots" content="noindex,nofollow" />
</head>
<body>
    [@frontend@]
</body>
</html>
