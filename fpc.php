<?php

//Cache Entry point function 
function fast_cache()
{

    $time_start = microtime(true);
    $request = $_SERVER;
    //var_dump($_SERVER['REQUEST_URI']);
    //die();
    if (
        !isset($request['REQUEST_METHOD']) ||
        $request['REQUEST_METHOD'] !== 'GET' ||
        strpos($_SERVER['REQUEST_URI'], '/customer') === 0 ||
        strpos($_SERVER['REQUEST_URI'], '/media') === 0 ||
        strpos($_SERVER['REQUEST_URI'], '/admin') === 0 ||
        strpos($_SERVER['REQUEST_URI'], '/checkout')
    ) {
        //use only with web not cli
        if (isset($request['REQUEST_METHOD'])) {
            @header('SaaS-Cache: FALSE');
        }
        return false;
    }

    // We are using native Redis we are not using Magento 2 Broken Framework
    //If you don't have native Redis installed this extension will not work
    if (!class_exists('\Redis')) {
        //  die("Redis Extension is not installed");
        return false;
    }

    $config = require __DIR__ . '/../app/etc/env.php';
    $_cache = [];
    //https://github.com/phpredis/phpredis/blob/develop/INSTALL.markdown

    // It works only With Redis! 
    if (
        !isset($config['cache']['frontend']['page_cache']['backend_options']['server']) &&
        !isset($config['cache']['frontend']['page_cache']['backend_options']['port'])
    ) {
        //	die("config is not done");
        return false;
    }

    $redisLoc = new Redis();
    $redisLoc->pconnect(
        $config['cache']['frontend']['page_cache']['backend_options']['server'],
        $config['cache']['frontend']['page_cache']['backend_options']['port'],
        0,
        'FPC'
    );

    //$redis->select($config['cache']['frontend']['page_cache']['backend_options']['database']);

    //var_dump($_SERVER);
    //die();
    $request = $_SERVER;

    try {
        $prefix = 'zc:k:' . $config['cache']['frontend']['page_cache']['id_prefix'];
        $cacheKey = getCacheKey($request);
        //echo "\n $cacheKey";

        //uncomment to enable APCU cache 
        $apcu_value = false; //apcu_fetch($cacheKey);

        if (!isset($_cache[$cacheKey]) || $apcu_value === false) {

            $page = $redisLoc->multi(Redis::PIPELINE)
                ->select($config['cache']['frontend']['page_cache']['backend_options']['database'])
                ->hget($prefix . strtoupper($cacheKey), 'd')
                ->exec()[1];

            //var_dump($page);

            if ($page === false) {
                //unset($_COOKIES);
                header('SaaS-Cache: MISS');
                return false;
            }

            $arch = substr($page, 0, 2);

            if ($arch === 'gz') {
                $page = gzuncompress(substr($page, 5));
            } else if ($arch === '{"') {
                $page = $page;
            } else {
                return false;
            }

            $page = json_decode($page, true);
            $_cache[$cacheKey] = $page;
            //  apcu_add($cacheKey, $page);
        } else {
            //echo "\n from APCU";

            $page = $_cache[$cacheKey];
            //$page = $apcu_value;
        }

        //echo "fast:cache";
        header('SaaS-Cache: HIT');

        echo $page['content'];

        $time_end = microtime(true);
        $time = $time_end - $time_start;

        echo 'Did FPC in ' . $time . ' seconds';

        die();
        //return $result;

    } catch (\Throwable $e) {
        echo $e->__toString();
    }
}

// https://github.com/magento/magento2/blob/ba89f12cb331ffe8c9b9cb952ef15eccf762329e/app/code/Magento/PageCache/etc/varnish6.vcl#L121
// https://github.com/magento/magento2/blob/9544fb243d5848a497d4ea7b88e08609376ac39e/lib/internal/Magento/Framework/App/PageCache/Identifier.php
function getCacheKey($request)
{
    $data = [
        isset($request['SCHEME_HTTPS']) ? true : false, //https://github.com/magento/magento2/blob/2.3/lib/internal/Magento/Framework/HTTP/PhpEnvironment/Request.php#L386
        getUrl($request),
        //https://github.com/magento/magento2/blob/ba89f12cb331ffe8c9b9cb952ef15eccf762329e/app/code/Magento/PageCache/etc/varnish6.vcl#L121
        NULL //$request->getCookieParams('X-Magento-Vary')
        //?: $this->context->getVaryString()
    ];
    if (isset($_GET['testFPC']))
        var_dump($data);

    return sha1(json_encode($data));
}

function getUrl($request)
{
    $query  = $request['QUERY_STRING'] === '' ? '' : '?' . $request['QUERY_STRING'];
    //var_dump($request);

    if (isset($request['HTTP_X_FORWARDED_PROTO'])) {
        $shema = $request['HTTP_X_FORWARDED_PROTO'];
    } else if (isset($request['REQUEST_SCHEME'])) {
        $shema = $request['REQUEST_SCHEME'];
    }

    //var_dump($request);

    return $shema . '://' . $request['HTTP_HOST'] . '' . $request['REQUEST_URI'];
}

//Start Fast Cache 
fast_cache();
