# FastFPC

## Installation 

Add nginx 

```
fastcgi_param PHP_VALUE "auto_prepend_file=/var/www/html/magento/app/fpc.php";
```
/var/www/html/magento/app/ shuld be changet to your magento path 

or 

add it as a first line to app/bootstrap.php

```
require './fpc.php';
```

# Performance

FPC generation time is 0.000481128 second.


