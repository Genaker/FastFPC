import http from "http";
import Redis from "ioredis";
import NodeCache from "node-cache";
import dotenv from "dotenv";
import { gunzipSync } from "zlib";
import crypto from "crypto";
import stringify from "fast-json-stable-stringify";

dotenv.config();

const prefix = "zc:k:" + (process.env.PREFIX || 'b30_');

const redis = new Redis({
    host: process.env.REDIS_HOST || "127.0.0.1",
    port: parseInt(process.env.REDIS_PORT) || 6379,
    db: parseInt(process.env.REDIS_DB) || 11,
    keyPrefix: prefix // Magento Redis Prefix
});

// APCu Equivalent (Node.js in-memory cache)
const cache = new NodeCache({ stdTTL: 300 }); // 5 min cache

// Config Options
const DEBUG = true;
const USE_APCU = false; // Enable in-memory cache
const IGNORED_URLS = ["/customer", "/media", "/admin", "/checkout"];
const HTTPS = getEnvBoolean("HTTPS", true);
const HOST = process.env.HOST || false;

// Start HTTP Server
const server = http.createServer(async (req, res) => {
    const startTime = process.hrtime();
    console.log("URL:" + req.url);
    if (!isCached(req)) {
        if (DEBUG) res.setHeader("Fast-Cache", "FALSE");
        return sendNotFound(res);
    }

    try {
        const cacheKey = getCacheKey(req);
        console.log("KEY:" + prefix + cacheKey);
        if (DEBUG) res.setHeader("FPC-KEY", cacheKey);

        // Try APCu (NodeCache) First
        let cachedPage = USE_APCU ? cache.get(cacheKey) : null;

        if (!cachedPage) {
            const redisStartTime = process.hrtime();
            cachedPage = await getRedisValue(cacheKey, "d");
            const redisEndTime = process.hrtime(redisStartTime);

            if (DEBUG) res.setHeader("Redis-Time", `${(redisEndTime[1] / 1e6).toFixed(2)}ms`);

            if (!cachedPage) {
                if (DEBUG) res.setHeader("Fast-Cache", "MISS");
                return sendNotFound(res);
            }

            cachedPage = uncompress(cachedPage);
            if (USE_APCU) cache.set(cacheKey, cachedPage);
        } else {
            if (DEBUG) res.setHeader("Fast-Cache", "HIT (APCu)");
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
        console.log("FPC-TIME:" + (endTime[1] / 1e6).toFixed(2) + "ms");

        res.writeHead(200, { "Content-Type": "text/html" });
        res.end(cachedPage.content);
    } catch (err) {
        if (DEBUG) res.setHeader("FPC-ERROR", "Exception");
        console.error("FPC Error:", err);
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
    console.log(JSON.stringify(url));
    return url;
}

// Gzip Decompression for Cached Content
function uncompress(page) {
    if (page.startsWith("gz")) {
        return gunzipSync(Buffer.from(page, "base64")).toString();
    }
    return JSON.parse(page);
}

// Generate SHA1 Hash for Cache Keys
function hashData(data) {
    // to match json_encode
    var jsonString = stringify(data).replace(/\//g, "/")
        .replace(/\//g, "\\/") // Escape slashes like PHP (\/)
        .replace(/[\u007f-\uffff]/g, c => `\\u${c.charCodeAt(0).toString(16).padStart(4, "0")}`); // Unicode fix

    console.log("HASH-DATA:" + jsonString);

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

// Start Server
const PORT = process.env.PORT || 3000;
server.listen(PORT, () => console.log(`🚀 Node.js FPC Server running on port ${PORT}`));
