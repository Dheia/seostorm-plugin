<?php

declare(strict_types=1);

namespace Initbiz\SeoStorm\Sitemap\Generators;

use Cache;
use Event;
use Queue;
use Config;
use Carbon\Carbon;
use Cms\Classes\Page;
use Cms\Classes\Theme;
use System\Traits\EventEmitter;
use October\Rain\Database\Model;
use System\Classes\PluginManager;
use System\Models\SiteDefinition;
use October\Rain\Database\Collection;
use Initbiz\Seostorm\Models\SitemapItem;
use Initbiz\Seostorm\Models\SitemapMedia;
use RainLab\Pages\Classes\Page as StaticPage;
use Initbiz\SeoStorm\Jobs\ScanPageForMediaItems;
use Initbiz\Sitemap\DOMElements\UrlsetDOMElement;
use Initbiz\Sitemap\Generators\AbstractGenerator;
use October\Rain\Support\Collection as SupportCollection;

/**
 * This generator provides sitemaps for CMS pages as well as added by RainLab.Pages
 */
class PagesGenerator extends AbstractGenerator
{
    use EventEmitter;

    const HASH_PAGE_CACHE_KEY = 'initbiz.seostorm.pages_content_hashes';

    /**
     * Collection of pages to parse, if not set, will be taken from the current theme
     *
     * @var Collection
     */
    protected $pages;

    /**
     * SiteDefinition
     *
     * @var SiteDefinition
     */
    protected SiteDefinition $site;

    public function __construct(SiteDefinition $site)
    {
        $this->site = $site;

        parent::__construct();
    }

    /**
     * Get the value of site
     *
     * @return SiteDefinition
     */
    public function getSite(): SiteDefinition
    {
        return $this->site;
    }

    /**
     * Make DOMElements listed in the sitemap
     *
     * @return array
     */
    public function makeDOMElements(): array
    {
        $site = $this->getSite();
        $pages = $this->getEnabledCmsPages($this->getPages());

        $baseFilenamesToLeave = [];
        foreach ($pages as $page) {
            $baseFilenamesToLeave[] = $page->base_file_name;

            if (!$this->isPageContentChanged($page)) {
                continue;
            }

            $this->refreshForCmsPage($page);
        }

        $pluginManager = PluginManager::instance();
        if ($pluginManager->hasPlugin('RainLab.Pages') && !$pluginManager->isDisabled('RainLab.Pages')) {
            $staticPages = $this->getEnabledStaticPages();
            foreach ($staticPages as $staticPage) {
                $baseFilenamesToLeave[] = $staticPage->fileName;

                if (!$this->isPageContentChanged($staticPage)) {
                    continue;
                }

                $this->refreshForStaticPage($staticPage);
            }
        }

        $this->fireSystemEvent('initbiz.seostorm.beforeClearingSitemapItems', [&$baseFilenamesToLeave]);

        // Remove all unused SitemapUrls
        $sitemapItemsToDelete = SitemapItem::whereNotIn('base_file_name', $baseFilenamesToLeave)->withSite($site)->get();
        foreach ($sitemapItemsToDelete as $sitemapItemToDelete) {
            $sitemapItemToDelete->delete();
        }

        $sitemapItems = SitemapItem::enabled()->withSite($site)->get();

        $this->fireSystemEvent('initbiz.seostorm.sitemapItems', [&$sitemapItems]);

        $urlDOMElements = [];
        foreach ($sitemapItems as $sitemapItem) {
            $urlDOMElements[] = $sitemapItem->toUrlDOMElement();
        }

        $urlSetDOMElement = new UrlsetDOMElement();
        $urlSetDOMElement->setUrls($urlDOMElements);

        return [$urlSetDOMElement];
    }

    // CMS pages

    /**
     * Get CMS pages that have sitemap enabled
     *
     * @param array|Collection $pages
     * @return array<Page>
     */
    public function getEnabledCmsPages($pages = null): array
    {
        if (empty($pages)) {
            $pages = $this->getPages();
        }

        $pages = $pages->filter(function ($page) {
            return (bool) $page->seoOptionsEnabledInSitemap;
        })->sortByDesc('seoOptionsPriority');

        $enabledPages = [];
        foreach ($pages as $page) {
            if ($this->isCmsPageEnabledInSitemap($page)) {
                $enabledPages[] = $page;
            }
        }

        return $enabledPages;
    }

    /**
     * Generate the XML
     *
     * @return string|false
     */
    public function generate(?SupportCollection $pages = null): string|false
    {
        if (!is_null($pages)) {
            $this->pages = $pages;
        }

        return parent::generate();
    }

    /**
     * Get Pages attribute
     *
     * @return Collection
     */
    public function getPages()
    {
        if (isset($this->pages)) {
            return $this->pages;
        }

        $this->pages = Page::listInTheme(Theme::getEditTheme());

        return $this->pages;
    }

    /**
     * Checks if the page has sitemap enabled
     *
     * @param Page $page
     * @return boolean
     */
    public function isCmsPageEnabledInSitemap(Page $page): bool
    {
        $site = $this->getSite();

        if (!PluginManager::instance()->hasPlugin('RainLab.Translate')) {
            return (bool) $page->seoOptionsEnabledInSitemap;
        }

        if (!isset($page->attributes["viewBag"]["localeSeoOptionsEnabledInSitemap"])) {
            return (bool) $page->seoOptionsEnabledInSitemap;
        }

        $locale = $site->locale ?? null;
        if (is_null($locale)) {
            return (bool) $page->seoOptionsEnabledInSitemap;
        }

        if (!isset($page->attributes["viewBag"]["localeSeoOptionsEnabledInSitemap"][$locale])) {
            return (bool) $page->seoOptionsEnabledInSitemap;
        }

        return (bool) $page->attributes["viewBag"]["localeSeoOptionsEnabledInSitemap"][$locale];
    }

    /**
     * Make SitemapItems for provided CMS page
     *
     * @param Page $page
     * @return array<SitemapItem>
     */
    public function makeItemsForCmsPage(Page $page): array
    {
        $site = $this->getSite();

        $urlPattern = $this->makeUrlPattern($page);

        $sitemapItems = [];
        $modelClass = $page->seoOptionsModelClass ?? "";
        $lastmod = $this->getLastmodForCmsPage($page);

        if (class_exists($modelClass)) {
            // If there a model class specified we'll iterate over them
            // and generate URLs separately for every single one

            $scope = $page->seoOptionsModelScope;
            $models = $this->getModelObjects($modelClass, $scope);

            foreach ($models as $model) {
                if (($model->seo_options['enabled_in_sitemap'] ?? null) === "0") {
                    continue;
                }

                $params = $this->generateParamsToUrl($page->seoOptionsModelParams, $model);
                $loc = $this->fillUrlPatternWithParams($urlPattern, $params);

                if ($page->seoOptionsUseUpdatedAt && isset($model->updated_at)) {
                    $lastmod = $model->updated_at;
                }

                $sitemapItem = SitemapItem::where('loc', $loc)->withSite($site)->first();
                if (!$sitemapItem) {
                    $sitemapItem = new SitemapItem();
                    $sitemapItem->loc = $loc;
                }

                $sitemapItem->lastmod = $lastmod;
                $sitemapItem->priority = $page->seoOptionsPriority;
                $sitemapItem->changefreq = $page->seoOptionsChangefreq;
                $sitemapItem->base_file_name = $page->base_file_name;
                $sitemapItem->site_definition_id = $site->id;
                $sitemapItem->save();

                $sitemapItems[] = $sitemapItem;
            }
        } else {
            // If there is no model class specified - we'll add just a single record
            $loc = $this->fillUrlPatternWithParams($urlPattern);
            $sitemapItem = SitemapItem::where('loc', $loc)->withSite($site)->first();
            if (!$sitemapItem) {
                $sitemapItem = new SitemapItem();
                $sitemapItem->loc = $loc;
            }

            $sitemapItem->base_file_name = $page->base_file_name;
            $sitemapItem->site_definition_id = $site->id;
            $sitemapItem->priority = $page->seoOptionsPriority;
            $sitemapItem->changefreq = $page->seoOptionsChangefreq;
            $sitemapItem->lastmod = $lastmod;
            $sitemapItem->save();

            $sitemapItems[] = $sitemapItem;
        }

        return $sitemapItems;
    }

    /**
     * Get Objects for provided model class, using scope definition
     *
     * @param string $modelClass
     * @param string|null $scopeDef, for example isPublished:yesterday
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

        $query = $modelClass::with(['seostorm_options'])->{$scopeName}($scopeParameter);

        return $query->get();
    }

    /**
     * Generate parameters array to use in URL using a definition and a model object
     *
     * @param string $paramsDef
     * @param Model $model
     * @return array
     */
    public function generateParamsToUrl(string $paramsDef, Model $model): array
    {
        $params = [];
        $paramsDefs = explode('|', $paramsDef);
        foreach ($paramsDefs as $modelParam) {
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

        return $params;
    }

    /**
     * Use page's lastmod or mtime attributes, if none of them set, use "now" as the lastmod
     *
     * @param Page $page
     * @return Carbon
     */
    public function getLastmodForCmsPage(Page $page): Carbon
    {
        if (!is_null($page->lastmod)) {
            return Carbon::parse($page->lastmod);
        }

        if (!is_null($page->mtime)) {
            return Carbon::createFromTimestamp($page->mtime);
        }

        return Carbon::now();
    }

    /**
     * Refresh SitemapItem table records for a CMS page
     *
     * @param Page $page
     * @return void
     */
    public function refreshForCmsPage(Page $page): void
    {
        $site = $this->getSite();

        $items = $this->makeItemsForCmsPage($page);

        $idsToLeave = [];
        $baseFileNamesToScan = [];
        foreach ($items as $item) {
            $idsToLeave[] = $item->id;
            $baseFileNamesToScan[] = $item->base_file_name;
            Queue::push(ScanPageForMediaItems::class, ['loc' => $item->loc]);
        }

        // Remove old records, for example when a model in the parameter was removed
        $ghostSitemapItems = SitemapItem::whereIn('base_file_name', $baseFileNamesToScan)
            ->whereNotIn('id', $idsToLeave)
            ->withSite($site)
            ->get();

        foreach ($ghostSitemapItems as $ghostSitemapItem) {
            $ghostSitemapItem->delete();
        }

        SitemapMedia::deleteGhosts();

        Event::fire('initbiz.seostorm.sitemapItemForCmsPageRefreshed', [$page]);
    }

    // RainLab.Pages

    /**
     * List Static pages that are enabled in the Sitemap
     *
     * @param Theme|null $theme
     * @return array<StaticPage>
     */
    public function getEnabledStaticPages(?Theme $theme = null): array
    {
        if (empty($theme)) {
            $theme = Theme::getActiveTheme();
        }

        $staticPages = StaticPage::listInTheme($theme, true);

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
     * Makes SitemapItem object for this static page
     *
     * @param StaticPage $staticPage
     * @return SitemapItem
     */
    public function makeItemForStaticPage(StaticPage $staticPage): SitemapItem
    {
        $site = $this->getSite();

        $viewBag = $staticPage->getViewBag();

        $loc = $this->makeUrlPattern($staticPage);

        $sitemapItem = SitemapItem::where('loc', $loc)->withSite($site)->first();

        if (!$sitemapItem) {
            $sitemapItem = new SitemapItem();
            $sitemapItem->loc = $loc;
        }

        $sitemapItem->lastmod = $viewBag->property('lastmod') ?: $staticPage->mtime;
        $sitemapItem->priority = $viewBag->property('priority');
        $sitemapItem->changefreq = $viewBag->property('changefreq');
        $sitemapItem->base_file_name = $staticPage->fileName;
        $sitemapItem->site_definition_id = $site->id;
        $sitemapItem->save();

        $sitemapItemsToDelete = SitemapItem::where('base_file_name', $staticPage->fileName)
            ->where('id', '!=', $sitemapItem->id)
            ->withSite($site)
            ->get();

        foreach ($sitemapItemsToDelete as $sitemapItemToDelete) {
            $sitemapItemToDelete->delete();
        }

        return $sitemapItem;
    }

    /**
     * Refresh SitemapItem table record for a single static page
     *
     * @param StaticPage $staticPage
     * @return void
     */
    public function refreshForStaticPage(StaticPage $staticPage): void
    {
        $item = $this->makeItemForStaticPage($staticPage);
        Queue::push(ScanPageForMediaItems::class, ['loc' => $item->loc]);

        SitemapMedia::deleteGhosts();

        Event::fire('initbiz.seostorm.sitemapItemForStaticPageRefreshed', [$staticPage]);
    }

    // Helpers

    /**
     * Make URL pattern - raw URL with params ready to be filled
     * e.g. https://init.biz/:category/:slug?
     *
     * @param StaticPage|Page $page
     * @return string For example: https://init.biz/:category/:slug?
     */
    public function makeUrlPattern(StaticPage|Page $page): string
    {
        $site = $this->getSite();

        $urlPattern = $page->url;

        // We're restoring ending / if the page is a "root" page
        $restoreSlash = false;
        if ($urlPattern === '/') {
            $restoreSlash = true;
        }

        if (PluginManager::instance()->hasPlugin('RainLab.Translate')) {
            $urlPattern = array_get($page->attributes, 'viewBag.localeUrl.' . $site->locale, $urlPattern);
        }

        $urlPattern = $site->attachRoutePrefix(ltrim($urlPattern, '/'));

        $urlPattern = rtrim(Config::get('app.url'), '/') . '/' . ltrim($urlPattern, '/');

        if ($restoreSlash && !str_ends_with($urlPattern, '/')) {
            $urlPattern .= '/';
        }

        return $urlPattern;
    }

    /**
     * Fill the parameters in pattern using provided params array
     * if not provided, all optional params like :slug? will be removed
     * if not provided, all required params like :slug will be replaced with default
     *
     * @param string $urlPattern
     * @param array $params
     * @return string
     */
    public function fillUrlPatternWithParams(string $urlPattern, array $params = []): string
    {
        $url = $urlPattern;

        // replace parameters with the provided params
        foreach ($params as $param => $value) {
            // Parameters like /:slug/
            $pattern = '/\/\:' . $param . '\?{0,1}\//i';
            $toReplace = empty($value) ? "" : '/' . $value . '/';
            $url = preg_replace($pattern, $toReplace, $url);

            // Parameters at the end of the string, like /:slug
            $pattern = '/\/\:' . $param . '\?{0,1}$/i';
            $toReplace = empty($value) ? "" : '/' . $value;
            $url = preg_replace($pattern, $toReplace, $url);
        }

        // Remove empty optional parameters that didn't have any parameters
        $pattern = '/\/\:.+\?/i';
        $url = preg_replace($pattern, '', $url);

        // Replace :param with default
        $pattern = '/\/\:.+$/i';
        $url = preg_replace($pattern, '/default', $url);

        $pattern = '/\/\:.+\//i';
        $url = preg_replace($pattern, '/default/', $url);

        return $url;
    }

    /**
     * The method checks if the page was changed at the file level and if so, it'll store the content's hash
     * in the cache, so that it knows next time that the content has changed or not.
     *
     * The method is particularly useful for re-generating items to not touch records that were not changed
     *
     * @param Page|StaticPage $page
     * @return boolean
     */
    public function isPageContentChanged(Page|StaticPage $page): bool
    {
        $site = $this->getSite();

        $key = $site->code . '-';
        $content = '';

        if ($page instanceof StaticPage) {
            $baseFileName = $page->fileName;
            $content = $page->getContent();
        } else {
            $baseFileName = $page->base_file_name;
            $content = $page['content'];
        }

        $key .= $baseFileName;

        $cacheArray = [];
        if (Cache::has(self::HASH_PAGE_CACHE_KEY)) {
            $cacheArray = json_decode(Cache::get(self::HASH_PAGE_CACHE_KEY), true);
        }

        $md5 = md5($content);
        if (
            !isset($cacheArray[$key]) ||
            $cacheArray[$key] !== $md5
        ) {
            $cacheArray[$key] = $md5;
            Cache::put(self::HASH_PAGE_CACHE_KEY, json_encode($cacheArray));

            return true;
        }

        return false;
    }

    /**
     * Forget cache that stores info about files being changed or not
     *
     * @return void
     */
    public static function resetCache(): void
    {
        Cache::forget(self::HASH_PAGE_CACHE_KEY);
    }
}
