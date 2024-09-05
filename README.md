# FastFPC
this extension requires Redis Magento Builtin cache enabled and  php_redis php extension installed.
The **phpredis** extension provides a native PHP API for communicating with the Redis key-value store. 

*Tested With: 2.4.7 Magento version*
```
#RHEL / CentOS
#Installation of the php-pecl-redis package, from the EPEL repository:

yum install php-pecl-redis
```
## Cloud Flare CDN FPC Cache Microservice Layer 

Works great together with this Cloud Flare Worker FPC cache Layer:
https://github.com/Genaker/CloudFlare_FPC_Worker


## The Idea behind this Magento 2 FPC performance extension 

When I developed a Shopware 6-based website I noticed fast sub 1ms performance of the FPC cache. I checked the code and it amazed me. It is simple and made a right PHP  way! You don't need Varnish to run your FPC cache fast. You need just fast code without reusing the Magento 2 junk core framework.

## Installation 

Nginx 

```
fastcgi_param PHP_VALUE "auto_prepend_file=/var/www/html/magento/app/code/Mage/FPC/FPC.php";
```
/var/www/html/magento/app/ shuld be changet to your magento path 

or 

add it as a first line to app/bootstrap.php or pub/index.php

Also if you are using composer to install this stuff your path will be something like : ../vendor/mage/fpc/src/Mage/FPC.php

Installation into the app folder is preferable.  It is not a useless library. It is a part of your business to keep your site FAST. 

```
require "../app/code/Mage/FPC.php";
```

Also, this extension will work without this additional interaction (just install and forget) but it will be slower because it will load all Magento 2 via autoloader.

or do next:

```
composer require mage/fpc
bin/magento setup:upgrade
bin/magento fpc:deploy
```

# Performance

FPC generation time is 0.000481128 second.
