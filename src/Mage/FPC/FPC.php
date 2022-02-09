<?php

//Improved Magento FPC
class FPC
{

  public const REDIS_PREFIX = 'zc:k:';

  public $debug = true;
  public $apcu = false;

  public $ignoreURL = [
    '/customer',
    '/media',
    '/admin',
    '/checkout'
  ];

  public function __construct(bool $debug = true, bool $apcu = false)
  {
    if ($apcu === true && !function_exists('apcu_enabled')) {
      header('FPC-APCU: APCU extension is not installed');
      $apcu = false;
    }
    $this->debug = $debug;
    $this->apcu = $apcu;
  }

  public function fly()
  {

    $time_start = microtime(true);
    $request = $_SERVER;

    if (!$this->isCached($request)) {
      //use only with web not cli
      if (isset($request['REQUEST_METHOD'])) {
        if ($this->debug)
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

    //For app folder
    //$config = require __DIR__ . '/../../../../app/etc/env.php';
    //For Composer Folder ToDO: remove src from the composer to make app = to vendor
    $config = require __DIR__ . '/../../../../../../app/etc/env.php';

    //https://github.com/phpredis/phpredis/blob/develop/INSTALL.markdown

    // Cache works only With PHP Redis extension! 
    if (!isset($config['cache']['frontend']['page_cache']['backend_options']['server']) && !isset($config['cache']['frontend']['page_cache']['backend_options']['port'])) {
      header('FPC-ERROR: Redis config is not set');
      return false;
    }

    //Using native PHP Redis extension
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
      if ($this->debug)
        header('FPC-KEY: ' . $cacheKey);

      //uncomment to enable APCU cache 
      if ($this->apcu === true) {
        $apcu_value = apcu_fetch($cacheKey);
      } else {
        $apcu_value = false;
      }

      if ($apcu_value === false) {

        $timeRedisStart = microtime(true);

        $page = $redisLoc->multi(\Redis::PIPELINE)
          ->select($config['cache']['frontend']['page_cache']['backend_options']['database'])
          ->hget($prefix . strtoupper($cacheKey), 'd')
          ->exec()[1];

        $timeRedisEnd = microtime(true);

        if ($this->debug)
          header('Redis-Time: ' . ($timeRedisEnd - $timeRedisStart));

        if ($page === false) {
          //unset($_COOKIES);
          if ($this->debug) {
            header('Fast-Cache: MISS');
          }
          return false;
        }

        $page = $this->uncompress($page);

        if ($page === false) {
          return false;
        }

        $page = json_decode($page, true);
        if ($this->apcu === true) {
          apcu_add($cacheKey, $page);
        }
      } else {
        // Value from APCU;
        $page = $apcu_value;
      }

      if ($this->debug)
        header('Fast-Cache: HIT');

      $time_end = microtime(true);
      $time = $time_end - $time_start;
      if ($this->debug)
        header('FPC-TIME: ' . (string)$time);

      echo $page['content'];
      die();
    } catch (\Throwable $e) {
      if ($this->debug)
        header('FPC-ERROR: Exception');
      return false;
      echo $e->__toString();
    }
  }

  // https://github.com/magento/magento2/blob/ba89f12cb331ffe8c9b9cb952ef15eccf762329e/app/code/Magento/PageCache/etc/varnish6.vcl#L121
  // https://github.com/magento/magento2/blob/9544fb243d5848a497d4ea7b88e08609376ac39e/lib/internal/Magento/Framework/App/PageCache/Identifier.php
  public function getCacheKey(array $request): string
  {
    $httpsFlag = false;
    $shema = $this->HTTPShema($request);
    if ($shema === 'https') {
      $httpsFlag = true;
    }

    $getVaryString = NULL;
    if (isset($_COOKIE['X-Magento-Vary'])) {
      $getVaryString = $_COOKIE['X-Magento-Vary'];
    }

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

    return sha1(json_encode($data));
  }

  public function HTTPShema(array $request): string
  {
    $shema = 'http'; //or https 
    if (isset($request['HTTP_X_FORWARDED_PROTO'])) {
      $shema = $request['HTTP_X_FORWARDED_PROTO'];
    } else if (isset($request['HTTP_CLOUDFRONT_FORWARDED_PROTO'])) {
      $shema = $request['HTTP_CLOUDFRONT_FORWARDED_PROTO'];
    } else if (isset($request['REQUEST_SCHEME'])) {
      $shema = $request['REQUEST_SCHEME'];
    }
    return $shema;
  }

  public function getUrl(array $request): string
  {
    //$query = $request['QUERY_STRING'] === '' ? '' : '?' . $request['QUERY_STRING'];
    $shema = $this->HTTPShema($request);

    return $shema . '://' . $request['HTTP_HOST'] . '' . $request['REQUEST_URI'];
  }

  public function uncompress($page)
  {
    $arch = substr($page, 0, 2);

    if ($arch === 'gz') {
      return $page = gzuncompress(substr($page, 5));
    } else if ($arch === '{"') {
      return $page;
    } else {
      if ($this->debug)
        header('FPC-ERROR: Compression method issue');
      return false;
    }
  }

  // check if request is cached
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

$FPC = new FPC();
$FPC->fly();
