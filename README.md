# FastFPC

## Installation 

Add nginx 

```
fastcgi_param PHP_VALUE "auto_prepend_file=/var/www/html/magento/app/code/Mage/FPC/FPC.php";
```
/var/www/html/magento/app/ shuld be changet to your magento path 

or 

add it as a first line to app/bootstrap.php or pub/index.php

Also if you are using composer to install this stuff your path will be something like : ../vendor/mage/fpc/src/Mage/FPC.php

Installation into app folder is preferable.  It is not a usless library. It is a part of your busines to keep your site FAST. 

```
require "../app/code/Mage/FPC.php";
```

Also this extension will work without this aditional interactions (jsut install and forget) but it will be slower becouse it will load all Magento 2 via autoloader.

or do next:

```
composer require mage/fpc
bin/magento setup:upgrade
bin/magento fpc:deploy
```

# Performance

FPC generation time is 0.000481128 second.
