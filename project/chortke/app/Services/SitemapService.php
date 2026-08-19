<?php

namespace App\Services;

use App\Models\Page;
use Core\Cache;
use Core\PathResolver;
use Core\UrlGenerator;

class SitemapService
{
    private Page $pageModel;
    private UrlGenerator $urlGenerator;
    private PathResolver $paths;
    private const CACHE_KEY = 'sitemap_xml_content';

    private \Core\Cache $cache;
    public function __construct(
        \Core\Cache $cache,
        \App\Models\Page $pageModel,
        UrlGenerator $urlGenerator,
        PathResolver $paths
    ) {
        $this->cache = $cache;
        $this->pageModel = $pageModel;
        $this->urlGenerator = $urlGenerator;
        $this->paths = $paths;
    }
    
    /**
     * تولید Sitemap
     */
    public function generate(): string
    {
        $baseUrl = $this->urlGenerator->base();
        $pages = $this->pageModel->getAll();
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        // صفحه اصلی (آخرین تغییر یکی از صفحات را به عنوان تاریخ اصلی در نظر می‌گیریم)
        $lastUpdate = date('Y-m-d');
        if (!empty($pages)) {
            $updates = array_map(fn($p) => strtotime($p->updated_at), $pages);
            $lastUpdate = date('Y-m-d', max($updates));
        }
        $xml .= $this->addUrl($baseUrl, '1.0', 'daily', $lastUpdate);
        
        // صفحات استاتیک
        foreach ($pages as $page) {
            if ($page->is_active) {
                $url = $baseUrl . '/pages/' . $page->slug;
                $xml .= $this->addUrl($url, '0.8', 'weekly', date('Y-m-d', (strtotime($page->updated_at) ?: time())));
            }
        }
        
        // سایر صفحات عمومی
        $publicPages = [
            '/login' => ['priority' => '0.7', 'changefreq' => 'monthly'],
            '/register' => ['priority' => '0.7', 'changefreq' => 'monthly']
        ];
        
        foreach ((array)$publicPages as $path => $config) {
            $xml .= $this->addUrl(
                $baseUrl . $path,
                $config['priority'],
                $config['changefreq'],
                date('Y-m-d')
            );
        }
        
        $xml .= '</urlset>';
        
        return $xml;
    }

    public function getXml(): string
    {
        // H-05 Fix: Stale-While-Revalidate cache stampede mitigation
        $xml = $this->cache->get(self::CACHE_KEY);
        if (is_string($xml)) {
            return $xml;
        }
        if ($xml !== null) {
            $this->cache->forget(self::CACHE_KEY);
        }

        // Attempt to lock with 180s TTL, waiting up to 5s
        $lockKey = 'sitemap_gen_mutex';
        if ($this->cache->lock($lockKey, 180, 5)) {
            try {
                // Double check
                $xml = $this->cache->get(self::CACHE_KEY);
                if (is_string($xml)) {
                    return $xml;
                }
                if ($xml !== null) {
                    $this->cache->forget(self::CACHE_KEY);
                }

                $xml = $this->generate();
                $this->cache->put(self::CACHE_KEY, $xml, 30); // 30 minutes cache
                $this->cache->forever(self::CACHE_KEY . '_stale', $xml); // Stale version
                return $xml;
            } finally {
                $this->cache->unlock($lockKey);
            }
        }

        // Return stale version to avoid waiting/DB overload if lock cannot be acquired
        return str_value($this->cache->get(self::CACHE_KEY . '_stale') ?? '');
    }
    
    /**
     * افزودن URL
     */
    private function addUrl(string $loc, string $priority, string $changefreq, string $lastmod): string
    {
        $xml = "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . "</loc>\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";
        $xml .= "  </url>\n";
        
        return $xml;
    }
    
    /**
     * ذخیره فایل
     */
    public function save(): bool
    {
        $xml = $this->generate();
        $path = $this->paths->public('sitemap.xml');
        
        return file_put_contents($path, $xml) !== false;
    }
}
