import http from "http";
import Redis from "ioredis";
import NodeCache from "node-cache";
import dotenv from "dotenv";
//import { gunzipSync } from "zlib";
import { gunzip } from "zlib";
import crypto from "crypto";
import { minify } from "html-minifier-terser";

dotenv.config();

const corePrefix = "zc:k:";
const prefix = corePrefix + (process.env.PREFIX || 'b30_');

const redis = new Redis({
    host: process.env.REDIS_HOST || "127.0.0.1",
    port: parseInt(process.env.REDIS_PORT) || 6379,
    db: parseInt(process.env.REDIS_DB) || 11,
    keyPrefix: prefix // Magento Redis Prefix
});

// Config Options
const DEBUG = getEnvBoolean("DEBUG", false);
const USE_CACHE = getEnvBoolean("USE_CACHE", false); // Enable in-memory cache
const CACHE_TTL = parseInt(process.env.CACHE_TTL) || 60;
const IGNORED_URLS = ["/customer", "/media", "/admin", "/checkout"];
const HTTPS = getEnvBoolean("HTTPS", true);
const HOST = process.env.HOST || false;
const MINIFY = getEnvBoolean("MINIFY");

// APCu Equivalent (Node.js in-memory cache)
const cache = new NodeCache({ stdTTL: CACHE_TTL }); // 5 min cache

// Start HTTP Server
const server = http.createServer(async (req, res) => {
    const startTime = process.hrtime();

    if (!isCached(req)) {
        if (DEBUG) {
            res.setHeader("Fast-Cache", "FALSE");
            console.log("URL:" + req.url);
        }
        return sendNotFound(res);
    }

    try {
        const cacheKey = getCacheKey(req);
        if (DEBUG) {
            res.setHeader("FPC-KEY", cacheKey);
            console.log("KEY:" + prefix + cacheKey);
        }

        // Try APCu like (NodeCache) First
        let cachedPage = USE_CACHE ? cache.get(cacheKey) : null;

        if (!cachedPage) {
            const redisStartTime = process.hrtime();
            cachedPage = await getRedisValue(cacheKey, "d");
            const redisEndTime = process.hrtime(redisStartTime);

            if (DEBUG) res.setHeader("Redis-Time", `${(redisEndTime[1] / 1e6).toFixed(2)}ms`);

            if (!cachedPage) {
                if (DEBUG) res.setHeader("Fast-Cache", "MISS");
                return sendNotFound(res);
            }

            cachedPage = await uncompress(cachedPage);
            cachedPage = JSON.parse(cachedPage);
            if (USE_CACHE) cache.set(cacheKey, cachedPage, CACHE_TTL);
        } else {
            if (DEBUG) res.setHeader("Fast-Cache", "HIT (NodeCACHE)");
        }

        // Set Cached Headers
        if (cachedPage.headers) {
            for (const [header, value] of Object.entries(cachedPage.headers)) {
                res.setHeader(header, value);
            }
        }

        // Measure Total Execution Time
        const endTime = process.hrtime(startTime);
        res.setHeader("FPC-TIME", `${(endTime[1] / 1e6).toFixed(2)}ms`);


        let content = cachedPage.content;
        if (MINIFY && !cachedPage.minified) {
            content = await minifyHTML(content);
            cachedPage.content = content;
            cachedPage.minified = true;
            if (USE_CACHE) cache.set(cacheKey, cachedPage, CACHE_TTL);
            //Do to resave minified to Redis ;)
        }

        console.log("FPC-TIME:[" + req.url + "]->" + (endTime[1] / 1e6).toFixed(2) + "ms");

        res.writeHead(200, { "Content-Type": "text/html" });
        res.end(content);
    } catch (err) {
        if (DEBUG) {
            res.setHeader("FPC-ERROR", "Exception");
            console.error("FPC Error:", err);
        }
        sendNotFound(res);
    }
});

// Function to Check if Request is Cacheable
function isCached(req) {
    if (req.method !== "GET") return false;
    return !IGNORED_URLS.some(url => req.url.startsWith(url));
}

// Generate Cache Key (Same as Magento)
function getCacheKey(req) {
    const httpsFlag = req.headers["x-forwarded-proto"] === "https" || HTTPS;
    const varyString = req.headers["cookie"]?.includes("X-Magento-Vary") || null;
    return hashData([httpsFlag, getUrl(req), varyString]);
}

// Get Full Request URL
function getUrl(req) {
    let scheme = req.headers["x-forwarded-proto"] || "http";
    if (HTTPS) {
        scheme = "https";
    }
    let host = req.headers.host;
    if (HOST) {
        host = HOST;
    }
    // [true,"https:\/\/react-luma.cnxt.link\/",null]
    const url = (scheme + "://" + host + req.url)
    if (DEBUG) {
        console.log(JSON.stringify(url));
    }
    return url;
}

// Gzip Decompression for Cached Content
async function uncompress(page) {
    return await decompressGzippedBase64(page);
}

// Generate SHA1 Hash for Cache Keys
function hashData(data) {
    // to match PHP json_encode
    var jsonString = JSON.stringify(data).replace(/\//g, "/")
        .replace(/\//g, "\\/") // Escape slashes like PHP (\/)
        .replace(/[\u007f-\uffff]/g, c => `\\u${c.charCodeAt(0).toString(16).padStart(4, "0")}`); // Unicode fix

    if (DEBUG) {
        console.log("HASH-DATA:" + jsonString);
    }

    return crypto.createHash("sha1").update(jsonString).digest("hex").toUpperCase();
}

// Send 404 Not Found Response
function sendNotFound(res) {
    res.writeHead(404, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ error: "Not Cached" }));
}

async function getRedisValue(key, field) {
    try {
        const value = await redis.hget(key, field); // Use HGET instead of HGETALL
        // console.log("HGET Response:", JSON.parse(value));
        return value;
    } catch (err) {
        console.error("Redis Error:", err);
    }
}

function getEnvBoolean(key, defaultValue) {
    return process.env[key]?.toLowerCase() === "true"
        ? true
        : process.env[key]?.toLowerCase() === "false"
            ? false
            : defaultValue;
};

function decompressGzippedBase64(page) {
    return new Promise((resolve, reject) => {
        // For now we are just supporting GZip 
        if (!page.startsWith("gz")) {
            return resolve(page); // Return original if not gzipped
        }

        const buffer = Buffer.from(page, "base64");
        console.log("REDIS-GZIPed");

        gunzip(buffer, (err, decompressed) => {
            if (err) {
                reject(err);
            } else {
                resolve(decompressed.toString());
            }
        });
    });
}

async function minifyHTML(htmlContent) {
    return await minify(htmlContent, {
        collapseWhitespace: true,  // Remove unnecessary spaces
        removeComments: true,      // Remove HTML comments
        removeRedundantAttributes: true, // Remove default attributes (e.g., `<input type="text">`)
        removeEmptyAttributes: true, // Remove empty attributes
        minifyCSS: true,  // Minify inline CSS
        minifyJS: true,   // Minify inline JS
    });
}

// Start Server
const PORT = process.env.PORT || 3001;
server.listen(PORT, () => console.log(`🚀 Node.js FPC Server running on port ${PORT}`));
