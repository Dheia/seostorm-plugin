<?php

namespace Initbiz\SeoStorm\Classes;

use Site;
use Initbiz\SeoStorm\Classes\SitemapItem;
use Initbiz\SeoStorm\Classes\SitemapGenerator;
use Initbiz\Seostorm\Models\SitemapItem as ModelSitemapItem;

class SitemapImagesGenerator extends SitemapGenerator
{
    protected $sitemapItemModels;

    protected function makeUrlSet()
    {
        if ($this->urlSet !== null) {
            return $this->urlSet;
        }

        $xml = $this->makeRoot();
        $urlSet = $xml->createElement('urlset');
        $urlSet->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlSet->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $urlSet->setAttribute('xsi:schemaLocation', 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd');
        $urlSet->setAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        $xml->appendChild($urlSet);

        return $this->urlSet = $urlSet;
    }

    public function makeItems($pages = []): void
    {
        $site = Site::getActiveSite();
        $sitemapItemsModel = ModelSitemapItem::where('site_definition_id', $site->id)->whereHas('media', function ($query) {
            $query->where('type', 'image');
        })->with('media')->get();

        foreach ($sitemapItemsModel as $sitemapItemModel) {
            $sitemapItem = new SitemapItem();
            $sitemapItem->loc = $sitemapItemModel->loc;
            foreach ($sitemapItemModel->media as $media) {
                if ($media->type === 'image') {
                    $sitemapItem->images[] = $media->values;
                }
            }
            $this->addItemToSet($sitemapItem);
        }
    }
}
