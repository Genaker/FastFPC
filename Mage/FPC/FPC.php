<?php

/**
 * Improved Magento Full Page Cache (FPC)
 * 
 * - Uses native PHP Redis extension for speed.
 * - Optionally uses APCu for in-memory caching.
 * - Designed for CLI-free, fast, and simple deployment.
 * 
 * Requirements:
 * - PHP Redis extension (https://github.com/phpredis/phpredis)
 * - (Optional) APCu extension
 * 
 * @author Your Name
 */

class FPC
{
    public const REDIS_PREFIX = 'zc:k:';

    public $debug = true;
    public $apcu = false;

    // URLs to ignore for caching (e.g., customer, admin, checkout)
    public $ignoreURL = [
        '/customer',
        '/media',
        '/admin',
        '/checkout'
    ];

    public function __construct(bool $debug = true, bool $apcu = false)
    {
        if ($apcu && !function_exists('apcu_enabled')) {
            header('FPC-APCU: APCU extension is not installed');
            $apcu = false;
        }
        $this->debug = $debug;
        $this->apcu = $apcu;
    }

    /**
     * Main entry point: checks cache and serves if available.
     */
    public function fly()
    {
        $time_start = microtime(true);
        $request = $_SERVER;

        if (!$this->isCached($request)) {
            if (isset($request['REQUEST_METHOD']) && $this->debug) {
                @header('Fast-Cache: FALSE');
            }
            return false;
        }

        // We are usin native PHP Redis we are not using Magento Framework
        // if you don't have native redis instaleed this extension will not work
        if (!class_exists('\Redis')) {
            header('FPC-ERROR: PHP Redis Extension is not installed');
            return false;
        }

        // Try to load Magento config (supporting multiple possible locations)
        $config = @include __DIR__ . '/../../../../app/etc/env.php';
        if (!$config) {
            //For Composer Folder ToDO: remove src from the composer to make app = to vendor
            $config = @include __DIR__ . '/../../../../../app/etc/env.php';
        }

        if (!$config) {
            // check include path
            header('FPC-ERROR: Config not found');
            return false;
        }

        // Check for Redis config in Magento env.php
        if (
            !isset($config['cache']['frontend']['page_cache']['backend_options']['server']) ||
            !isset($config['cache']['frontend']['page_cache']['backend_options']['port'])
        ) {
            header('FPC-ERROR: Redis config is not set');
            return false;
        }

        // Connect to Redis
        $redisLoc = new \Redis();
        $redisLoc->pconnect(
            $config['cache']['frontend']['page_cache']['backend_options']['server'],
            $config['cache']['frontend']['page_cache']['backend_options']['port'],
            0,
            'FPC'
        );

        try {
            $prefix = self::REDIS_PREFIX . @$config['cache']['frontend']['page_cache']['id_prefix'];
            $cacheKey = $this->getCacheKey($request);
            if ($this->debug) header('FPC-KEY: ' . $cacheKey);

            // Try APCu first if enabled
            $apcu_value = false;
            if ($this->apcu && function_exists('apcu_fetch')) {
                $apcu_value = apcu_fetch($cacheKey);
            }

            if ($apcu_value === false) {

                $timeRedisStart = microtime(true);

                $page = $redisLoc->multi(\Redis::PIPELINE)
                    ->select($config['cache']['frontend']['page_cache']['backend_options']['database'])
                    ->hget($prefix . strtoupper($cacheKey), 'd')
                    ->exec()[1];

                $timeRedisEnd = microtime(true);
                if ($this->debug) header('Redis-Time: ' . ($timeRedisEnd - $timeRedisStart));

                if ($page === false) {
                    if ($this->debug) header('Fast-Cache: MISS');
                    return false;
                }

                $page = $this->uncompress($page);
                if ($page === false) return false;

                $page = json_decode($page, true);
                if ($this->apcu && function_exists('apcu_add')) {
                    apcu_add($cacheKey, $page);
                }
            } else {
                // Value from APCU;
                $page = $apcu_value;
            }

            if ($this->debug) header('Fast-Cache: HIT');
            $time_end = microtime(true);
            if ($this->debug) header('FPC-TIME: ' . ($time_end - $time_start));

            // Output headers from cache
            if (isset($page['headers']) && is_array($page['headers'])) {
                foreach ($page['headers'] as $header => $value) {
                    header($header . ': ' . $value);
                }
            }

            echo $page['content'];
            // Output cached page and die 
            die();
        } catch (\Throwable $e) {
            if ($this->debug) header('FPC-ERROR: Exception');
            // Optionally log $e->getMessage() somewhere
            return false;
        }
    }

    // https://github.com/magento/magento2/blob/ba89f12cb331ffe8c9b9cb952ef15eccf762329e/app/code/Magento/PageCache/etc/varnish6.vcl#L121
    // https://github.com/magento/magento2/blob/9544fb243d5848a497d4ea7b88e08609376ac39e/lib/internal/Magento/Framework/App/PageCache/Identifier.php
    /**
    * Generate a cache key based on request and cookies.
    */
    public function getCacheKey(array $request): string
    {
        $httpsFlag = $this->HTTPShema($request) === 'https';
        $getVaryString = $_COOKIE['X-Magento-Vary'] ?? null;

        $data = [
            $httpsFlag, //https://github.com/magento/magento2/blob/2.3/lib/internal/Magento/Framework/HTTP/PhpEnvironment/Request.php#L386
            $this->getUrl($request),
            //https://github.com/magento/magento2/blob/ba89f12cb331ffe8c9b9cb952ef15eccf762329e/app/code/Magento/PageCache/etc/varnish6.vcl#L121
            $getVaryString //$request->getCookieParams('X-Magento-Vary')
            //?: $this->context->getVaryString()
        ];

        if (isset($_GET['testFPC']) && $this->debug) {
            var_dump($data);
            echo '<h2>Server:</h2>';
            var_dump($_SERVER);
        }
        @header('HASH-DATA: ' . json_encode($data));
        return sha1(json_encode($data));
    }

    /**
     * Detect HTTP/HTTPS scheme from request.
     */
    public function HTTPShema(array $request): string
    {
        if (isset($request['HTTP_X_FORWARDED_PROTO'])) {
            return $request['HTTP_X_FORWARDED_PROTO'];
        } elseif (isset($request['HTTP_CLOUDFRONT_FORWARDED_PROTO'])) {
            return $request['HTTP_CLOUDFRONT_FORWARDED_PROTO'];
        } elseif (isset($request['REQUEST_SCHEME'])) {
            return $request['REQUEST_SCHEME'];
        }
        return 'http';
    }

    /**
     * Build the full request URL.
     */
    public function getUrl(array $request): string
    {
        //$query = $request['QUERY_STRING'] === '' ? '' : '?' . $request['QUERY_STRING'];
        $shema = $this->HTTPShema($request);
        return $shema . '://' . $request['HTTP_HOST'] . $request['REQUEST_URI'];
    }

    /**
     * Uncompress page data if needed.
     */
    public function uncompress($page)
    {
        $arch = substr($page, 0, 2);

        if ($arch === 'gz') {
            return gzuncompress(substr($page, 5));
        } elseif ($arch === '{"') {
            return $page;
        } else {
            if ($this->debug) header('FPC-ERROR: Unsupported Compression method - ' . $arch);
            return false;
        }
    }

    /**
     * Check if the current request is cacheable.
     */
    public function isCached($request)
    {
        // check cache only GET method
        if (!isset($request['REQUEST_METHOD']) || $request['REQUEST_METHOD'] !== 'GET')
            return false;

        foreach ($this->ignoreURL as $url) {
            if (strpos($request['REQUEST_URI'], $url) === 0)
                return false;
        }
        return true;
    }
}

// Instantiate and run FPC
$FPC = new FPC();
$FPC->fly();
