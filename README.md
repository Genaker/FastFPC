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

# Test 

Test Magento Headers: 

<img width="675" alt="image" src="https://github.com/user-attachments/assets/52656300-096c-4e9c-8900-1f5bd9b1c882" />
</br>
In this case, we need install php-redis extension:
```
sudo apt-get install php-redis 
```

# NodeJS implementation

```
npm install ioredis node-cache dotenv
or nmp install
node FPC.js
```
Replace Nginx:
```
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```
With:
```
# Try Node.js first, fallback to static files, then PHP
location / {
    # Try serving from Node.js first
    proxy_pass http://127.0.0.1:3001;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

    # If Node.js fails, fallback to static files and then PHP
    error_page 406 502 504 = @fallback;
}

# Fallback to Static Files or PHP if Node.js Fails
location @fallback {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

# Understanding Stale-While-Revalidate
The stale-while-revalidate caching strategy is designed to minimize latency by serving cached content immediately, even if it’s slightly outdated, while simultaneously updating the cache with fresh data in the background. This approach ensures that users receive a quick response and the cache remains up-to-date without blocking the main request flow.

# Performance Test : 
```
FPC-TIME:0.88ms
FPC-TIME:0.99ms
FPC-TIME:0.79ms
FPC-TIME:0.98ms
FPC-TIME:1.51ms
FPC-TIME:1.08ms
FPC-TIME:0.98ms
```
With in-memory cache: 
```
FPC-TIME:0.25ms
FPC-TIME:0.22ms
FPC-TIME:0.23ms
FPC-TIME:0.62ms
FPC-TIME:0.24ms
FPC-TIME:0.46ms
FPC-TIME:0.42ms
FPC-TIME:0.29ms
FPC-TIME:0.28ms
FPC-TIME:/->0.14ms
FPC-TIME:/->0.15ms
FPC-TIME:/->0.14ms
FPC-TIME:/->0.15ms
FPC-TIME:0.25ms
FPC-TIME:0.22ms
FPC-TIME:0.20ms
```

# Magento GO FPC (Full Page Cache)
A high-performance caching solution for Magento built with Go, featuring multi-layer caching, goroutines for concurrent processing, and Redis integration.<\br>

Features:
- Multi-layer caching (Local memory + Redis)
- Concurrent request handling with goroutines
- Rate limiting (250 req/s with 500 burst)
- Stale cache management
- Profiling and monitoring
- Gzip compression support
- Static file caching
- Debug mode with detailed logging

## Running the Server

```
# Build the binary
go build -o fpc FPC.go

# Run the server
./fpc

# Or run directly
go run FPC.go
```