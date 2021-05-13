<?php

function fast_cache() {


$request = $_SERVER;
//var_dump($_SERVER['REQUEST_URI']);
//die();
if (!isset($request['REQUEST_METHOD']) || $request['REQUEST_METHOD'] !== 'GET' || strpos($_SERVER['REQUEST_URI'], '/customer') === 0
    || strpos($_SERVER['REQUEST_URI'], '/media') === 0 || strpos($_SERVER['REQUEST_URI'], '/admin') === 0 || strpos($_SERVER['REQUEST_URI'], '/checkout')){
//use only with web not cli
if(isset($request['REQUEST_METHOD'])) {
  @header('SaaS-Cache: FALSE');}
  return false;
}
  
// We are usin native Redis we are not using Magento Broken Framework
// if you don't have native redis instaleed this extension will not work
if (!class_exists('\Redis')) {
  return false;
}

$config = require __DIR__.'/../app/etc/env.php';
$_cache = [];
//https://github.com/phpredis/phpredis/blob/develop/INSTALL.markdown

$redisLoc = new Redis();
$redisLoc->pconnect($config['cache']['frontend']['page_cache']['backend_options']['server'],
  $config['cache']['frontend']['page_cache']['backend_options']['port'], 0, 'FPC');

//$redis->select($config['cache']['frontend']['page_cache']['backend_options']['database']);

//var_dump($_SERVER);
//die();
$request = $_SERVER;

try{
   $prefix = 'zc:k:' . $config['cache']['frontend']['page_cache']['id_prefix'];
   $cacheKey = getCacheKey($request);
//   echo "\n $cacheKey";

  //uncomment to enable APCU cache 
  $apcu_value = false; //apcu_fetch($cacheKey);

if (!isset($_cache[$cacheKey]) || $apcu_value === false ){

  $page = $redisLoc->multi(Redis::PIPELINE)
	  ->select($config['cache']['frontend']['page_cache']['backend_options']['database'])
          ->hget($prefix.strtoupper($cacheKey),'d')
 	  ->exec()[1];

//var_dump($page);

if ($page === false) {

 unset($_COOKIES);
 header('SaaS-Cache: MISS');
 return false;
}
  $arch = substr($page, 0, 2);
  if ($arch === 'gz'){
  $page = gzuncompress(substr($page,5));
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

       die();
      //return $result;

} catch (\Throwable $e){
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
 if(isset($_GET['testFPC']))
 var_dump($data);
        return sha1(json_encode($data));
}



function getUrl($request){
$query  = $request['QUERY_STRING'] === '' ? '' : '?'.$request['QUERY_STRING'];
//var_dump($request);

return $request['HTTP_X_FORWARDED_PROTO'].'://'.$request['HTTP_HOST'].''.$request['REQUEST_URI'];

}

function getUrl($request){
$query  = $request['QUERY_STRING'] === '' ? '' : '?'.$request['QUERY_STRING'];
//var_dump($request);

return $request['HTTP_X_FORWARDED_PROTO'].'://'.$request['HTTP_HOST'].''.$request['REQUEST_URI'];

}

// https://github.com/magento/magento2/blob/ba89f12cb331ffe8c9b9cb952ef15eccf762329e/app/code/Magento/PageCache/etc/varnish6.vcl#L121
// https://github.com/magento/magento2/blob/9544fb243d5848a497d4ea7b88e08609376ac39e/lib/internal/Magento/Framework/App/PageCache/Identifier.php
 function getCacheKey($request){
   
        $data = [
            isset($request['SCHEME_HTTPS']) ? true : false, //https://github.com/magento/magento2/blob/2.3/lib/internal/Magento/Framework/HTTP/PhpEnvironment/Request.php#L386
            getUrl($request),
            //https://github.com/magento/magento2/blob/ba89f12cb331ffe8c9b9cb952ef15eccf762329e/app/code/Magento/PageCache/etc/varnish6.vcl#L121
          //TODO: $_COOKIES['X-Magento-Vary'] cache will not work for customers group etc.. 
            NULL //$request->getCookieParams('X-Magento-Vary')
                 //?: $this->context->getVaryString()
        ];
   
    if(isset($_GET['testFPC'])){
      var_dump($data);
    }
   
  return sha1(json_encode($data));
}


fast_cache();
