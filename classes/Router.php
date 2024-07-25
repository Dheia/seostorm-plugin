<?php

namespace Initbiz\SeoStorm\Classes;

use Route;
use Initbiz\SeoStorm\Models\Settings;
use Initbiz\SeoStorm\Controllers\RobotsController;
use Initbiz\SeoStorm\Controllers\FaviconController;
use Initbiz\SeoStorm\Controllers\SitemapController;

/**
 * SEO Storm router - will register routings for sitemaps, robots, etc.
 * if enabled in settings
 */
class Router
{
    public function register(): void
    {
        $settings = Settings::instance();

        if ($settings->get('enable_sitemap')) {
            $this->registerSitemapRouting();
        }

        if ($settings->get('enable_robots_txt')) {
            $this->registerRobotsRouting();
        }

        if ($settings->get('favicon_enabled')) {
            $this->registerFaviconRouting();
        }
    }

    public function registerSitemapRouting(): void
    {
        $settings = Settings::instance();
        $sites = $settings->getSitesEnabledInSitemap();

        foreach ($sites as $site) {
            $prefix = $site->is_prefixed ? $site->route_prefix : '';

            Route::get($prefix . '/sitemap.xml', [SitemapController::class, 'sitemap']);

            if ($settings->get('enable_index_sitemap')) {
                Route::get($prefix . '/sitemap_index.xml', [SitemapController::class, 'index']);
            }

            if ($settings->get('enable_index_sitemap_videos')) {
                Route::get($prefix . '/sitemap_videos.xml', [SitemapController::class, 'videos']);
            }

            if ($settings->get('enable_index_sitemap_images')) {
                Route::get($prefix . '/sitemap_images.xml', [SitemapController::class, 'images']);
            }
        }
    }

    public function registerRobotsRouting(): void
    {
        Route::get('robots.txt', [RobotsController::class, 'index']);
    }

    public function registerFaviconRouting(): void
    {
        Route::get('favicon.ico', [FaviconController::class, 'index']);
    }
}
