<?php

namespace Initbiz\SeoStorm\Classes;

use Site;
use Cache;
use DOMElement;
use Carbon\Carbon;
use Cms\Classes\Page;
use Cms\Classes\Theme;
use System\Traits\EventEmitter;
use October\Rain\Database\Model;
use System\Classes\PluginManager;
use System\Models\SiteDefinition;
use October\Rain\Database\Collection;
use Initbiz\Seostorm\Models\SitemapItem;
use RainLab\Translate\Classes\Translator;
use RainLab\Pages\Classes\Page as StaticPage;
use Initbiz\SeoStorm\Classes\AbstractGenerator;
use Initbiz\SeoStorm\Classes\SitemapItemsCollection;

/**
 * This generator provides sitemaps for CMS pages as well as added by RainLab.Pages
 */
class PagesGenerator extends AbstractGenerator
{
    use EventEmitter;

    const HASH_PAGE_CACHE_KEY = 'initbiz.seostorm.pages_content_hashes';

    public function fillUrlSet(DOMElement $urlSet): DOMElement
    {
        $urlSet->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlSet->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $value = 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd';
        $urlSet->setAttribute('xsi:schemaLocation', $value);

        return $urlSet;
    }

    public function makeItems(): SitemapItemsCollection
    {
        $pages = $this->getEnabledCmsPages();

        $baseFilenamesToLeave = [];
        foreach ($pages as $page) {
            $baseFilenamesToLeave[] = $page['base_file_name'];

            if (!$this->isPageContentChanged($page['base_file_name'], $page['content'])) {
                continue;
            }

            // We use SitemapItem model as cache for all the items
            $items = $this->makeItemsForCmsPage($page);
            foreach ($items as $item) {
                $item->save();
            }

            $this->fireSystemEvent('initbiz.seostorm.cmsPageChanged', [$page]);
        }

        if (PluginManager::instance()->hasPlugin('RainLab.Pages')) {
            $staticPages = $this->getEnabledStaticPages();
            foreach ($staticPages as $staticPage) {
                $baseFilenamesToLeave[] = $staticPage->fileName;

                if ($this->isPageContentChanged($staticPage->fileName, $staticPage->getTwigContent())) {
                    continue;
                }

                $item = $this->makeItemForStaticPage($staticPage);
                $item->save();
            }
        }

        $this->fireSystemEvent('initbiz.seostorm.beforeClearingSitemapItems', [&$baseFilenamesToLeave]);

        // Remove all unused SitemapItems
        SitemapItem::whereNotIn('base_file_name', $baseFilenamesToLeave)->get()->delete();

        $sitemapItemsCollection = SitemapItem::active()->all();

        $this->fireSystemEvent('initbiz.seostorm.sitemapItems', [&$sitemapItemsCollection]);

        return $sitemapItemsCollection;
    }

    // CMS pages

    /**
     * Get CMS pages that have sitemap enabled
     *
     * @param array|Collection $pages
     * @param SiteDefinition|null $site
     * @return array<Page>
     */
    public function getEnabledCmsPages($pages = null, ?SiteDefinition $site = null): array
    {
        if (empty($pages)) {
            $pages = Page::listInTheme(Theme::getEditTheme());
        }

        if (empty($site)) {
            $site = Site::getActiveSite();
        }

        $pages = $pages->filter(function ($page) {
            return $page->seoOptionsEnabledInSitemap;
        })->sortByDesc('seoOptionsPriority');

        $enabledPages = [];
        foreach ($pages as $page) {
            if ($this->isCmsPageEnabledInSitemap($page, $site)) {
                $enabledPages[] = $page;
            }
        }

        return $enabledPages;
    }

    /**
     * Checks if the page has sitemap enabled
     *
     * @param Page $page
     * @param SiteDefinition|null $site
     * @return boolean
     */
    public function isCmsPageEnabledInSitemap(Page $page, ?SiteDefinition $site = null): bool
    {
        if (empty($site)) {
            $site = Site::getActiveSite();
        }

        if (isset($page->attributes["viewBag"]["localeSeoOptionsEnabledInSitemap"][$site->code])) {
            return (bool) $page->attributes["viewBag"]["localeSeoOptionsEnabledInSitemap"][$site->code];
        }

        return (bool) ($page->seoOptionsEnabledInSitemap ?? false);
    }

    /**
     * Make SitemapItems for provided CMS page
     *
     * @param Page $page
     * @param SiteDefinition|null $site
     * @return array<SitemapItem>
     */
    public function makeItemsForCmsPage(Page $page, ?SiteDefinition $site = null): array
    {
        if (is_null($site)) {
            $site = Site::getActiveSite();
        }

        $loc = $page->url;
        if (PluginManager::instance()->hasPlugin('RainLab.Translate')) {
            $translator = Translator::instance();
            $loc = $translator->getPageInLocale($page->base_file_name, $site) ?? $loc;
        }

        $sitemapItems = [];
        $modelClass = $page->seoOptionsModelClass;
        if (class_exists($modelClass)) {
            $scope = $page->seoOptionsModelScope;
            $models = $this->getModelObjects($modelClass, $scope);

            foreach ($models as $model) {
                $loc = $this->generateLocForModelAndCmsPage($model, $page);
                $loc = $this->trimOptionalParameters($loc);

                $lastmod = $page->lastmod ?: Carbon::createFromTimestamp($page->mtime);
                if ($page->seoOptionsUseUpdatedAt && isset($model->updated_at)) {
                    $lastmod = $model->updated_at;
                }

                $sitemapItem = new SitemapItem();
                $sitemapItem->fillFromArray([
                    'loc' => $loc,
                    'lastmod' => $lastmod,
                    'priority' => $page->seoOptionsPriority,
                    'changefreq' => $page->seoOptionsChangefreq,
                ]);

                $sitemapItems[] = $sitemapItem;
            }
        } else {
            $sitemapItem = new SitemapItem();
            $sitemapItem->fillFromArray([
                'priority' => $page->seoOptionsPriority,
                'changefreq' => $page->seoOptionsChangefreq,
                'lastmod' => $page->lastmod ?: Carbon::createFromTimestamp($page->mtime),
                'loc' => $this->trimOptionalParameters($loc),
            ]);

            $sitemapItems[] = $sitemapItem;
        }

        return $sitemapItems;
    }

    /**
     * Get Objects for provided model class, using scope definition
     *
     * @param string $modelClass
     * @param string|null $scopeDef
     * @return Collection
     */
    public function getModelObjects(string $modelClass, ?string $scopeDef = null): Collection
    {
        if (empty($scopeDef)) {
            return $modelClass::all();
        }

        $params = explode(':', $scopeDef);
        $scopeName = $params[0];
        $scopeParameter = $params[1] ?? null;
        return $modelClass::{$scopeName}($scopeParameter)->get();
    }

    /**
     * Generate URL (loc) for provided model and CMS page
     *
     * @param Model $model
     * @param Page $page
     * @return string
     */
    public function generateLocForModelAndCmsPage(Model $model, Page $page): string
    {
        $baseFileName = $page->base_file_name;

        $modelParams = $page->seoOptionsModelParams;
        if (empty($modelParams)) {
            return \Cms::pageUrl($baseFileName);
        }

        $params = [];
        $modelParams = explode('|', $modelParams);
        foreach ($modelParams as $modelParam) {
            list($urlParam, $modelParam) = explode(':', $modelParam);

            $replacement = '';
            if (strpos($modelParam, '.') === false) {
                $replacement = $model->$modelParam;
            } else {
                // parameter with dot -> try to find by relation
                list($relationMethod, $relatedAttribute) = explode('.', $modelParam);
                if ($relatedObject = $model->$relationMethod()->first()) {
                    $replacement = $relatedObject->$relatedAttribute ?? 'default';
                }
                $replacement = empty($replacement) ? 'default' : $replacement;
            }
            $params[$urlParam] = $replacement;
        }

        if (PluginManager::instance()->hasPlugin('RainLab.Translate')) {
            $translator = Translator::instance();
            $loc = $translator->getPageInLocale($baseFileName, null, $params);
        } else {
            $loc = \Cms::pageUrl($baseFileName, $params);
        }

        return $loc;
    }

    // RainLab.Pages

    public function getEnabledStaticPages(?Theme $theme = null): array
    {
        if (empty($theme)) {
            $theme = Theme::getActiveTheme();
        }

        $staticPages = StaticPage::listInTheme($theme);

        $enabledPages = [];

        foreach ($staticPages as $staticPage) {
            $viewBag = $staticPage->getViewBag();
            if ($viewBag->property('enabled_in_sitemap')) {
                $enabledPages[] = $staticPage;
            }
        }

        return $enabledPages;
    }

    /**
     * Get SitemapItem object for this static page
     *
     * @param StaticPage $staticPage
     * @return SitemapItem
     */
    public function makeItemForStaticPage(StaticPage $staticPage): SitemapItem
    {
        $viewBag = $staticPage->getViewBag();

        $loc = StaticPage::url($staticPage->fileName);
        $sitemapItem = SitemapItem::where('loc', $loc)->first();
        if (!$sitemapItem) {
            $sitemapItem = new SitemapItem();
        }

        $sitemapItem->loc = $loc;
        $sitemapItem->lastmod = $viewBag->property('lastmod') ?: $staticPage->mtime;
        $sitemapItem->priority = $viewBag->property('priority');
        $sitemapItem->changefreq = $viewBag->property('changefreq');

        return $sitemapItem;
    }


    // Helpers

    /**
     * Remove optional parameters from URL - this method is used for last check
     * if the sitemap has an optional parameter left in the URL
     *
     * @param string $loc
     * @return string
     */
    public function trimOptionalParameters(string $loc): string
    {
        // Remove empty optional parameters that don't have any models
        $pattern = '/\:.+\?/i';
        $loc = preg_replace($pattern, '', $loc);

        return $loc;
    }

    public function isPageContentChanged(string $baseFileName, string $content): bool
    {
        $cacheArray = json_decode(Cache::get(self::HASH_PAGE_CACHE_KEY, '{}'));

        $md5 = md5($content);
        if (
            !isset($cacheArray[$baseFileName]) ||
            $cacheArray[$baseFileName] !== $md5
        ) {
            $cacheArray[$baseFileName] = $md5;
            Cache::rememberForever(self::HASH_PAGE_CACHE_KEY, json_encode($cacheArray));

            return true;
        }

        return false;
    }

    public static function resetCache(): void
    {
        Cache::forget(self::HASH_PAGE_CACHE_KEY);
    }
}
