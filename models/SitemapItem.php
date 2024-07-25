<?php

namespace Initbiz\Seostorm\Models;

use Model;
use Carbon\Carbon;
use Initbiz\SeoStorm\Classes\SitemapItemsCollection;
use System\Models\SiteDefinition;
use Initbiz\SeoStorm\Contracts\Changefreq;
use Initbiz\SeoStorm\Contracts\ConvertingToSitemapXml;

class SitemapItem extends Model implements ConvertingToSitemapXml
{
    use \October\Rain\Database\Traits\Validation;

    /**
     * @var string table name
     */
    public $table = 'initbiz_seostorm_sitemap_items';

    /**
     * @var array rules for validation
     */
    public $rules = [
        'loc' => 'required',
        'priority' => 'nullable|float',
        'changefreq' => 'nullable|in:always,hourly,daily,weekly,monthly,yearly,never',
        'lastmod' => 'nullable|date',
    ];

    protected $casts = [
        'priority' => 'float',
    ];

    protected $dates = [
        'lastmod',
        'created_at',
        'updated_at'
    ];

    public $belongsToMany = [
        'images' => [
            SitemapMedia::class,
            'table' => 'initbiz_seostorm_sitemap_items_media',
            'scope' => 'onlyImages',
        ],

        'videos' => [
            SitemapMedia::class,
            'table' => 'initbiz_seostorm_sitemap_items_media',
            'scope' => 'onlyVideos',
        ],
    ];

    public $belongsTo = [
        'siteDefinition' => SiteDefinition::class
    ];


    /**
     * Get Loc attribute
     *
     * @return string
     */
    public function getLoc(): string
    {
        return $this->loc;
    }

    /**
     * Get lastmod attribute
     *
     * @return Carbon|null
     */
    public function getLastmod(): ?Carbon
    {
        return $this->lastmod;
    }

    /**
     * Get changefreq attribute
     *
     * @return Changefreq|null
     */
    public function getChangefreq(): ?Changefreq
    {
        return Changefreq::tryFrom($this->changefreq);
    }

    /**
     * Get priority attribute
     *
     * @return float|null
     */
    public function getPriority(): ?float
    {
        return $this->priority;
    }

    /**
     * Set loc attribute
     *
     * @param string $loc
     * @return ConvertingToSitemapXml
     */
    public function setLoc(string $loc): ConvertingToSitemapXml
    {
        $this->loc = $loc;
        return $this;
    }

    /**
     * Set Lastmod attribute
     *
     * @param string|Carbon $lastmod
     * @return ConvertingToSitemapXml
     */
    public function setLastmod(string|Carbon $lastmod): ConvertingToSitemapXml
    {
        if (is_string($lastmod)) {
            $lastmod = Carbon::parse($lastmod);
        }

        $this->lastmod = $lastmod;
        return $this;
    }

    /**
     * Set Changefreq attribute
     *
     * @param string|Changefreq $changefreq
     * @return ConvertingToSitemapXml
     */
    public function setChangefreq(string|Changefreq $changefreq): ConvertingToSitemapXml
    {
        if ($changefreq instanceof Changefreq) {
            $changefreq = $changefreq->value;
        }

        $this->changeFreq = $changefreq;

        return $this;
    }

    /**
     * Set priority attribute
     *
     * @param string|float $priority
     * @return ConvertingToSitemapXml
     */
    public function setPriority(string|float $priority): ConvertingToSitemapXml
    {
        $this->priority = (float) $priority;
        return $this;
    }

    /**
     * Fill from array - it should accept strings as keys and values to parse the item
     *
     * @param array $data
     * @return ConvertingToSitemapXml
     */
    public function fillFromArray(array $data): ConvertingToSitemapXml
    {
        $attributes = [
            'loc',
            'lastmod',
            'changefreq',
            'priority',
        ];

        foreach ($attributes as $attribute) {
            if (isset($data[$attribute])) {
                $methodName = 'set' . studly_case($attribute);
                $this->{$methodName}($data[$attribute]);
            }
        }

        return $this;
    }

    /**
     * Every time when creating collection Eloquent will build this collection
     *
     * @param array $models
     * @return SitemapItemsCollection
     */
    public function newCollection(array $models = []): SitemapItemsCollection
    {
        return new SitemapItemsCollection($models);
    }
}
