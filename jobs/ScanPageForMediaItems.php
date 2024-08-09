<?php

namespace Initbiz\SeoStorm\Jobs;

use Site;
use Cache;
use Queue;
use Request;
use Cms\Classes\CmsController;
use Initbiz\SeoStorm\Models\Settings;
use Initbiz\SeoStorm\Models\SitemapItem;
use Illuminate\Http\Request as HttpRequest;
use Initbiz\Sitemap\DOMElements\ImageDOMElement;
use Initbiz\Sitemap\DOMElements\VideoDOMElement;

class ScanPageForMediaItems
{
    public function fire($job, $data)
    {
        $loc = $data['loc'];

        $this->scan($loc);

        self::unmarkAsPending($loc);

        $job->delete();
    }

    /**
     * The method protects us from creating too many queue jobs
     *
     * @param string $loc
     * @return void
     */
    public function pushForLoc(string $loc): void
    {
        if (self::isPending($loc)) {
            return;
        }

        $settings = Settings::instance();
        $imagesEnabledInSitemap = $settings->get('enable_images_sitemap') ?? false;
        $videosEnabledInSitemap = $settings->get('enable_videos_sitemap') ?? false;

        if ($imagesEnabledInSitemap || $videosEnabledInSitemap) {
            self::markAsPending($loc);
            Queue::push(ScanPageForMediaItems::class, ['loc' => $loc]);
        }
    }

    public function scan($loc): void
    {
        $sitemapItem = SitemapItem::where('loc', $loc)->first();
        if (!$sitemapItem) {
            return;
        }

        // We need to temporarily replace request with faked one to get valid URLs
        $originalRequest = Request::getFacadeRoot();
        $request = new HttpRequest();
        Request::swap($request);

        $currentSite = Site::getActiveSite();
        $controller = new CmsController();
        try {
            $parsedUrl = parse_url($loc);
            $url = $parsedUrl['path'] ?? '/';
            $response = $controller->run($url);
        } catch (\Throwable $th) {
            Request::swap($originalRequest);
            Site::applyActiveSite($currentSite);
            trace_log('Problem with parsing page ' . $loc);
            // In case of any issue in the page, we need to ignore it and proceed
            return;
        }
        Site::applyActiveSite($currentSite);

        if ($response->getStatusCode() !== 200) {
            return;
        }

        $content = $response->getContent();

        $dom = new \DOMDocument();
        $dom->loadHTML($content ?? ' ', LIBXML_NOERROR);

        $settings = Settings::instance();

        $imagesEnabledInSitemap = $settings->get('enable_images_sitemap') ?? false;
        if ($imagesEnabledInSitemap) {
            $images = $this->getImagesFromDOM($dom);
            if (!empty($images)) {
                $sitemapItem->syncImages($images);
            }
        }

        $videosEnabledInSitemap = $settings->get('enable_videos_sitemap') ?? false;
        if ($videosEnabledInSitemap) {
            $videos = $this->getVideosFromDOM($dom);
            if (!empty($videos)) {
                $sitemapItem->syncVideos($videos);
            }
        }

        Request::swap($originalRequest);
    }

    /**
     * Get image objects from DOMDocument
     *
     * @param \DOMDocument $dom
     * @return array<ImageDOMElement>
     */
    public function getImagesFromDOM(\DOMDocument $dom): array
    {
        $imageDOMElements = [];

        $finder = new \DomXPath($dom);
        $nodes = $finder->query("//img");
        foreach ($nodes as $node) {
            $src = $node->getAttribute('src');
            if (blank($src)) {
                continue;
            }

            $imageDOMElement = new ImageDOMElement();
            $imageDOMElement->setLoc(url($src));
            $imageDOMElements[] = $imageDOMElement;
        }

        return $imageDOMElements;
    }

    /**
     * Get Video objects from DOMDocument
     * We're taking only videos that have itemtype defined as VideoObject
     *
     * @param \DOMDocument $dom
     * @return array
     */
    protected function getVideosFromDOM(\DOMDocument $dom): array
    {
        $finder = new \DomXPath($dom);
        $nodes = $finder->query("//*[contains(@itemtype, 'https://schema.org/VideoObject')]");

        $videos = [];
        foreach ($nodes as $node) {
            $videoDOMElement = new VideoDOMElement();
            foreach ($node->childNodes as $childNode) {
                if (!$childNode instanceof \DOMElement) {
                    continue;
                }

                if ($childNode->tagName !== 'meta') {
                    continue;
                }

                $propertyName = $childNode->getAttribute('itemprop');
                $methodName = 'set' . studly_case($propertyName);
                if (method_exists($videoDOMElement, $methodName)) {
                    $videoDOMElement->$methodName($childNode->getAttribute('content'));
                }

                if ($propertyName === 'embedUrl') {
                    $videoDOMElement->setPlayerLoc($childNode->getAttribute('content'));
                } elseif ($propertyName === 'uploadDate') {
                    $videoDOMElement->setPublicationDate(new \DateTime($childNode->getAttribute('content')));
                } elseif ($propertyName === 'thumbnailUrl') {
                    $videoDOMElement->setThumbnailLoc($childNode->getAttribute('content'));
                } elseif ($propertyName === 'name') {
                    $videoDOMElement->setTitle($childNode->getAttribute('content'));
                }
            }

            $videos[] = $videoDOMElement;
        }

        return $videos;
    }

    /**
     * Check if provided loc is pending or not
     *
     * @param string $loc
     * @return boolean
     */
    public static function isPending(string $loc): bool
    {
        $key = self::getCacheKey();

        $waitingForScan = Cache::get($key, []);

        return in_array($loc, $waitingForScan);
    }

    /**
     * Mark provided loc as pending - this will protect us from adding the loc again
     *
     * @param string $loc
     * @return void
     */
    public static function markAsPending(string $loc): void
    {
        if (self::isPending($loc)) {
            return;
        }

        $key = self::getCacheKey();
        $waitingForScan = Cache::get($key, []);

        $waitingForScan[] = $loc;

        Cache::pull($key);
        Cache::put($key, $waitingForScan);
    }

    /**
     * Unmark the provided loc as pending - make it available for pushing again
     *
     * @param string $loc
     * @return void
     */
    public static function unmarkAsPending(string $loc): void
    {
        if (!self::isPending($loc)) {
            return;
        }

        $key = self::getCacheKey();

        $waitingForScan = Cache::get($key, []);

        if (($key = array_search($loc, $waitingForScan, true)) !== false) {
            unset($waitingForScan[$key]);
        }

        Cache::put($key, []);
    }

    /**
     * Get cache key
     *
     * @return string
     */
    public static function getCacheKey(): string
    {
        return 'initbiz_seostorm_waiting_for_scan';
    }
}
