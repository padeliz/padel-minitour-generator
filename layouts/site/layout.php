<!DOCTYPE html>
<!--[if lt IE 7]><html lang="<?= Arshavinel\PadelMiniTour\Language\LangSite::get() ?>" class="no-js lt-ie9 lt-ie8 lt-ie7"><![endif]-->
<!--[if (IE 7)&!(IEMobile)]><html lang="<?= Arshavinel\PadelMiniTour\Language\LangSite::get() ?>" class="no-js lt-ie9 lt-ie8"><![endif]-->
<!--[if (IE 8)&!(IEMobile)]><html lang="<?= Arshavinel\PadelMiniTour\Language\LangSite::get() ?>" class="no-js lt-ie9"><![endif]-->
<!--[if gt IE 8]><!-->
<html lang="<?= Arshavinel\PadelMiniTour\Language\LangSite::get() ?>" class="no-js"><!--<![endif]-->

<head>
    <title><?= Arshwell\Monolith\Meta::get('title') ?></title>

    <!-- favicon -->
    <link href="<?= $_GLOBAL['favicon'] ?>" rel="shortcut icon" type="image/png" />

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
    <meta name="robots" content="noindex, nofollow" />
    <meta name="googlebot" content="notranslate">

    [@css@]

    [@js-header@]
</head>

<body>
    <!-- change this header as you'd like -->
    <header class="header fixed-top">
        <div class="branding docs-branding">
            <div class="container-fluid position-relative py-2">
                <div class="docs-logo-wrapper">
                    <div class="site-logo">
                        <a class="navbar-brand" href="<?= \Arshwell\Monolith\Web::url('site.home') ?>">
                            <div class="d-flex align-items-end">
                                <div class="flex-shrink-0">
                                    <span class="theme-icon-holder bg-transparent">
                                        <i class="fas fa-baseball-ball fa-2x"></i>
                                    </span>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <span class="logo-text" style="display: block;">ARSH Padel MiniTour</span>
                                    <small class="text-alt" style="display: block; font-size: 14px; line-height: 14px;">#MakeFriendsPlayingPadel</small>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
                <div class="docs-top-utilities d-flex justify-content-end align-items-center">
                    <ul class="social-list list-inline mx-md-3 mx-lg-5 mb-0">
                        <li class="list-inline-item"><a href="https://github.com/arshwell/monolith" target="_blank" title="Arshwell repo"><i class="fab fa-github fa-fw"></i></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </header>

    <div id="app">
        [@frontend@]
    </div>

    [@js-footer@]
</body>

</html>
