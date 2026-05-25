<?php

namespace App\Core;

class QueryCache
{
    private $cache = [];
    private $cacheTiers = ['hot' => [], 'warm' => [], 'cold' => []];
    private $cacheDependencies = [];
    private $cacheTTL = 600;
    private $cacheMaxSize = 500;
    private $hotCacheSize = 100;
    private $warmCacheSize = 200;
    private $cacheStats = ['hits' => 0, 'misses' => 0];
    private $enabled = true;

    public function get($key)
    {
        if (!$this->enabled || !isset($this->cache[$key])) {
            $this->cacheStats['misses']++;
            return null;
        }

        $entry = $this->cache[$key];
        if (time() - $entry['time'] >= $this->cacheTTL) {
            $this->remove($key);
            $this->cacheStats['misses']++;
            return null;
        }

        $this->moveToHot($key);
        $this->cacheStats['hits']++;
        return $entry['data'];
    }

    public function set($key, $data, $tags = [])
    {
        $this->cache[$key] = [
            'data' => $data,
            'time' => time(),
            'tags' => $tags,
            'hits' => 0,
        ];

        if (!empty($tags)) {
            foreach ($tags as $tag) {
                $this->cacheDependencies[$tag][] = $key;
            }
        }

        $this->addToCacheTier($key);
        $this->enforceCacheSize();
    }

    public function clear($pattern = null)
    {
        if ($pattern === null) {
            $this->cache = [];
            $this->cacheDependencies = [];
            $this->cacheTiers = ['hot' => [], 'warm' => [], 'cold' => []];
        } else {
            foreach ($this->cache as $key => $value) {
                if (strpos($key, $pattern) !== false) {
                    $this->removeFromCacheTiers($key);
                    unset($this->cache[$key]);
                }
            }
        }
    }

    public function clearByTags($tags)
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $keysToRemove = [];

        foreach ($tags as $tag) {
            if (isset($this->cacheDependencies[$tag])) {
                foreach ($this->cacheDependencies[$tag] as $key) {
                    $keysToRemove[$key] = true;
                }
                unset($this->cacheDependencies[$tag]);
            }
        }

        foreach (array_keys($keysToRemove) as $key) {
            $this->remove($key);
        }
    }

    public function getStats()
    {
        return $this->cacheStats;
    }

    public function getInfo()
    {
        return [
            'stats' => $this->cacheStats,
            'hit_rate' => ($this->cacheStats['hits'] + $this->cacheStats['misses']) > 0
                ? round($this->cacheStats['hits'] / ($this->cacheStats['hits'] + $this->cacheStats['misses']) * 100, 2)
                : 0,
            'size' => count($this->cache),
            'max_size' => $this->cacheMaxSize,
            'tiers' => [
                'hot' => count($this->cacheTiers['hot']),
                'warm' => count($this->cacheTiers['warm']),
                'cold' => count($this->cacheTiers['cold']),
            ],
            'dependencies' => count($this->cacheDependencies),
        ];
    }

    public function setEnabled($enabled)
    {
        $this->enabled = $enabled;
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    private function remove($key)
    {
        $this->removeFromCacheTiers($key);
        unset($this->cache[$key]);
    }

    private function addToCacheTier($key)
    {
        $this->removeFromCacheTiers($key);
        $this->cacheTiers['hot'][] = $key;

        if (count($this->cacheTiers['hot']) > $this->hotCacheSize) {
            $coldestKey = array_shift($this->cacheTiers['hot']);
            $this->cacheTiers['warm'][] = $coldestKey;

            if (count($this->cacheTiers['warm']) > $this->warmCacheSize) {
                $coldestKey = array_shift($this->cacheTiers['warm']);
                $this->cacheTiers['cold'][] = $coldestKey;

                if (count($this->cacheTiers['cold']) > ($this->cacheMaxSize - $this->hotCacheSize - $this->warmCacheSize)) {
                    $evictKey = array_shift($this->cacheTiers['cold']);
                    unset($this->cache[$evictKey]);
                }
            }
        }
    }

    private function removeFromCacheTiers($key)
    {
        foreach (['hot', 'warm', 'cold'] as $tier) {
            $index = array_search($key, $this->cacheTiers[$tier]);
            if ($index !== false) {
                unset($this->cacheTiers[$tier][$index]);
                $this->cacheTiers[$tier] = array_values($this->cacheTiers[$tier]);
            }
        }
    }

    private function moveToHot($key)
    {
        $this->removeFromCacheTiers($key);
        array_unshift($this->cacheTiers['hot'], $key);

        if (isset($this->cache[$key])) {
            $this->cache[$key]['hits'] = ($this->cache[$key]['hits'] ?? 0) + 1;
        }
    }

    private function enforceCacheSize()
    {
        $totalCached = count($this->cache);
        if ($totalCached > $this->cacheMaxSize) {
            $toEvict = $totalCached - $this->cacheMaxSize;
            for ($i = 0; $i < $toEvict; $i++) {
                if (!empty($this->cacheTiers['cold'])) {
                    $evictKey = array_shift($this->cacheTiers['cold']);
                    unset($this->cache[$evictKey]);
                } elseif (!empty($this->cacheTiers['warm'])) {
                    $evictKey = array_shift($this->cacheTiers['warm']);
                    unset($this->cache[$evictKey]);
                }
            }
        }
    }
}
