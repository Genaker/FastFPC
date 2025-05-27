package main

import (
    "compress/gzip"
    "crypto/md5"
    "encoding/hex"
    _ "encoding/json"
    "encoding/json"
    "fmt"
    "io"
    "log"
    "net/http"
    "net/http/pprof"
    "os"
    "path/filepath"
    "runtime"
    "runtime/trace"
    "strings"
    "sync"
    "time"

    "github.com/fatih/color"
    "github.com/go-redis/redis/v8"
    "github.com/joho/godotenv"
    "github.com/patrickmn/go-cache"
    "golang.org/x/net/context"
    "golang.org/x/time/rate"
)

var (
    ctx         = context.Background()  // Context for Redis operations
    rdb         *redis.Client          // Redis client instance for persistent cache
    localCache  *cache.Cache           // In-memory cache for fast access
    corePrefix  = "zc:k:"             // Core prefix for all cache keys
    prefix      string                // Combined prefix (core + config) for cache keys
    debug       bool                  // Debug mode flag for verbose logging
    ignoredURLs = []string{          // URLs that should not be cached
        "/customer",
        "/media",
        "/admin",
        "/checkout",
		"/cf/",
    }
    cachedConfig *CacheConfig         // Cached configuration to avoid repeated env loads
    configOnce   sync.Once           // Ensures single configuration initialization

    // Add colored output formatters
    infoLog  = color.New(color.FgCyan).PrintfFunc()
    warnLog  = color.New(color.FgYellow).PrintfFunc()
    errorLog = color.New(color.FgRed).PrintfFunc()
    debugLog = color.New(color.FgGreen).PrintfFunc()

    requestLimiter = rate.NewLimiter(rate.Limit(250), 500) // 250 requests/second, burst of 500

    memStats = &runtime.MemStats{}  // Add memory stats tracking
)

type CacheConfig struct {
    RedisHost   string
    RedisPort   string
    RedisDB     int
    UseHTTPS    bool
    Host        string
    Prefix      string
    Debug       bool
    CacheTTL    time.Duration
    UseCache    bool
    UseStale    bool
    StaleExpiry time.Duration
    EnableProfile bool
    ProfilePort   string
    SecretKey    string
}

type CacheEntry struct {
    Content  string            `json:"content"`
    Headers  map[string]string `json:"headers"`
    Expired  bool             `json:"expired"`
}

// init initializes the FPC service with Redis and local cache configuration
func init() {
    if err := godotenv.Load(); err != nil {
        warnLog("Warning: .env file not found\n")
    }

    config := loadConfig()
    prefix = corePrefix + config.Prefix

    // Initialize Redis client
    rdb = redis.NewClient(&redis.Options{
        Addr:     fmt.Sprintf("%s:%s", config.RedisHost, config.RedisPort),
        DB:       config.RedisDB,
        Password: "",
    })

    // Test Redis connection
    _, err := rdb.Ping(ctx).Result()
    if err != nil {
        warnLog("Warning: Redis connection failed: %v. Working in proxy mode with local cache only.\n", err)
        rdb = nil // Set to nil to indicate Redis is unavailable
        config.UseCache = true // Force enable local cache in proxy mode
    }

    // Initialize local cache
    if config.UseCache {
        localCache = cache.New(config.CacheTTL, config.StaleExpiry)
        if config.UseStale {
            localCache.OnEvicted(func(key string, value interface{}) {
                if entry, ok := value.(CacheEntry); ok {
                    entry.Expired = true
                    localCache.Set(key, entry, config.StaleExpiry)
                }
            })
        }
    }
}

// loadConfig loads and caches environment configuration using sync.Once
func loadConfig() *CacheConfig {
    configOnce.Do(func() {
        cachedConfig = &CacheConfig{
            RedisHost:   getEnv("REDIS_HOST", "127.0.0.1"),
            RedisPort:   getEnv("REDIS_PORT", "6379"),
            RedisDB:     getEnvInt("REDIS_DB", 11),
            UseHTTPS:    getEnvBool("HTTPS", true),
            Host:        getEnv("HOST", ""),
            Prefix:      getEnv("PREFIX", "b30_"),
            Debug:       getEnvBool("DEBUG", false),
            CacheTTL:    time.Duration(getEnvInt("CACHE_TTL", 60)) * time.Second,
            UseCache:    getEnvBool("USE_CACHE", false),
            UseStale:    getEnvBool("USE_STALE", true),
            StaleExpiry: time.Duration(getEnvInt("STALE_TTL", 432000)) * time.Second, // 5 days = 432000 seconds
            EnableProfile: getEnvBool("ENABLE_PROFILE", true),
            ProfilePort:  getEnv("PROFILE_PORT", "6060"),
            SecretKey:    getEnv("SECRET_KEY", "changeme"),
        }
    })
    return cachedConfig
}

var httpClient = &http.Client{
    Transport: &http.Transport{
        MaxIdleConns:        100,
        IdleConnTimeout:     90 * time.Second,
        DisableCompression:  false,
        MaxConnsPerHost:     100,
        MaxIdleConnsPerHost: 10,
    },
    Timeout: time.Second * 30,
}

func main() {
    port := getEnv("PORT", "8080")
    config := loadConfig()

	if (false == config.EnableProfile) {
    // Initialize profiler if enabled
    initProfiler(config)
	}

    // Register HTTP handler
    http.HandleFunc("/", handleRequest)

    // Register cache listing endpoint
    http.HandleFunc("/cache/list", handleSecuredCacheList)

    // Log startup information
    infoLog("FPC Server starting:\n")
    infoLog("- Port: %s\n", port)
    infoLog("- Backend: %s://%s\n", map[bool]string{true: "https", false: "http"}[config.UseHTTPS], config.Host)
    infoLog("- Redis: %s:%s (DB: %d)\n", config.RedisHost, config.RedisPort, config.RedisDB)
    infoLog("- Cache: %v (TTL: %.0fs)\n", config.UseCache, config.CacheTTL.Seconds())
    infoLog("- Cache List URL: http://localhost:%s/cache/list (Secret Key Required)\n", port)
    infoLog("- Cache List JSON: http://localhost:%s/cache/list?format=json\n", port)


	fmt.Println(`
	╔═══════════════════════════════════════════════════════════════════════════════════════╗
	║                                                                                       ║
	║ ███╗   ███╗ █████╗  ██████╗ ███████╗███╗   ██╗████████╗ ██████╗    ██████╗  ██████╗   ║
	║ ████╗ ████║██╔══██╗██╔════╝ ██╔════╝████╗  ██║╚══██╔══╝██╔═══██╗   ██╔════╝ ██╔═══██╗ ║
	║ ██╔████╔██║███████║██║  ███╗█████╗  ██╔██╗ ██║   ██║   ██║   ██║   ██║  ███╗██║   ██║ ║
	║ ██║╚██╔╝██║██╔══██║██║   ██║██╔══╝  ██║╚██╗██║   ██║   ██║   ██║   ██║   ██║██║   ██║ ║
	║ ██║ ╚═╝ ██║██║  ██║╚██████╔╝███████╗██║ ╚████║   ██║   ╚██████╔╝   ╚██████╔╝╚██████╔╝ ║
	║ ╚═╝     ╚═╝╚═╝  ╚═╝ ╚═════╝ ╚══════╝╚═╝  ╚═══╝   ╚═╝    ╚═════╝  ░░ ╚═════╝  ╚═════╝  ║
	║                                                                                       ║
	╚═══════════════════════════════════════════════════════════════════════════════════════╝
	Magento GO(GoGento) Cache Server V1.0.1
	`)
    
    log.Fatal(http.ListenAndServe(":"+port, nil))
}

// initProfiler initializes the pprof profiler if enabled in the configuration
func initProfiler(config *CacheConfig) {
    if !config.EnableProfile {
        return
    }

    // Enable runtime tracing
    tracePath := "trace.out"
    f, err := os.Create(tracePath)
    if err != nil {
        errorLog("Failed to create trace file: %v\n", err)
        return
    }
    if err := trace.Start(f); err != nil {
        errorLog("Failed to start trace: %v\n", err)
        f.Close()
        return
    }

    // Create a new mux for profiling endpoints
    mux := http.NewServeMux()

    // Add runtime stats endpoint
    mux.HandleFunc("/debug/stats", func(w http.ResponseWriter, r *http.Request) {
        runtime.ReadMemStats(memStats)
        fmt.Fprintf(w, "Memory Stats:\n")
        fmt.Fprintf(w, "Alloc = %v MiB\n", memStats.Alloc/1024/1024)
        fmt.Fprintf(w, "TotalAlloc = %v MiB\n", memStats.TotalAlloc/1024/1024)
        fmt.Fprintf(w, "Sys = %v MiB\n", memStats.Sys/1024/1024)
        fmt.Fprintf(w, "NumGC = %v\n", memStats.NumGC)
        fmt.Fprintf(w, "Goroutines = %d\n", runtime.NumGoroutine())
    })

    // Register pprof handlers
    mux.HandleFunc("/debug/pprof/", pprof.Index)
    mux.HandleFunc("/debug/pprof/cmdline", pprof.Cmdline)
    mux.HandleFunc("/debug/pprof/profile", pprof.Profile)
    mux.HandleFunc("/debug/pprof/symbol", pprof.Symbol)
    mux.HandleFunc("/debug/pprof/trace", pprof.Trace)
    mux.Handle("/debug/pprof/heap", pprof.Handler("heap"))
    mux.Handle("/debug/pprof/goroutine", pprof.Handler("goroutine"))
    mux.Handle("/debug/pprof/threadcreate", pprof.Handler("threadcreate"))
    mux.Handle("/debug/pprof/block", pprof.Handler("block"))

    // Start profiler server
    go func() {
        infoLog("Profiler running on port %s\n", config.ProfilePort)
        if err := http.ListenAndServe(":"+config.ProfilePort, mux); err != nil {
            errorLog("Profiler error: %v\n", err)
        }
    }()
}

// handleRequest processes HTTP requests with multi-level caching strategy
func handleRequest(w http.ResponseWriter, r *http.Request) {
    // Wait for rate limiter
    if err := requestLimiter.Wait(context.Background()); err != nil {
        errorLog("Rate limit exceeded: %v\n", err)
        w.WriteHeader(http.StatusServiceUnavailable)
        return
    }

    startTime := time.Now()
    config := loadConfig()  // Load once at the start

    // This deferred function will run at the end of handleRequest
    defer func() {
        duration := time.Since(startTime)
        w.Header().Set("X-Response-Time", fmt.Sprintf("%.2fms", float64(duration.Microseconds())/1000.0))
        if config.Debug {
            debugLog("Request processed in %.2fms [%s] %s\n", 
                float64(duration.Microseconds())/1000.0,
                r.Method,
                r.URL.Path)
        }
    }()

    // If request is not cacheable (e.g., /media, /admin, non-GET), proxy directly to backend
    if !isCacheable(r) {
        // Track timing for direct proxy requests
        requestStart := time.Now()

        // Add debug headers and logging for non-cacheable URLs
        if config.Debug {
            w.Header().Set("Fast-Cache", "FALSE")
            debugLog("REQUEST URL: %s", r.URL.Path)
        }

        // Forward request to backend server
        entry, err := proxyRequest(w, r)
        if err != nil {
            errorLog("Proxy error: %v\n", err)
            w.WriteHeader(http.StatusBadGateway)
            return
        }

        // Add proxy timing information to response headers
        w.Header().Set("X-Proxy-Time", fmt.Sprintf("%.2fms", time.Since(requestStart).Seconds()*1000))

        // Serve the proxied content directly without caching
        serveContent(w, *entry, startTime)
        return
    }

    // Pass config to functions that need it
    cacheKey := getCacheKeyWithConfig(r, config)
    if config.Debug {
        debugLog("\n🔑 Cache Key: %s\n", cacheKey)
        debugLog("📍 URL: %s\n", getUrl(r))
    }

    // Try local cache first
    if config.UseCache {
        cacheStart := time.Now()
        if entry, found := localCache.Get(cacheKey); found {
            cacheEntry := entry.(CacheEntry)
            w.Header().Set("X-Cache-Lookup-Time", fmt.Sprintf("%.2fms", time.Since(cacheStart).Seconds()*1000))
            
            if config.Debug {
                infoLog("✅ Cache HIT (Local) in %.4fms\n", time.Since(cacheStart).Seconds()*1000)
                if cacheEntry.Expired {
                    warnLog("⚠️  Serving stale content (TTL: %.0fs)\n", config.StaleExpiry.Seconds())
                }
            }

            // Async refresh for stale content
            if cacheEntry.Expired {
                go func() {
                    if entry, err := proxyRequest(w, r); err == nil {
                        localCache.Set(cacheKey, *entry, config.CacheTTL)
                    }
                }()
            }

            serveContent(w, cacheEntry, startTime)
            return
        } else if config.Debug {
            warnLog("❌ Cache MISS (Local)\n")
        }
    }

    // Try Redis if available
    if rdb != nil {
        redisStart := time.Now()
        content, err := rdb.Get(ctx, cacheKey).Bytes()
        if err == nil {
            if config.Debug {
                infoLog("✅ Cache HIT (Redis) in %.2fms\n", time.Since(redisStart).Seconds()*1000)
            }
            reader, _ := gzip.NewReader(strings.NewReader(string(content)))
            if reader != nil {
                defer reader.Close()
                if decompressed, err := io.ReadAll(reader); err == nil {
                    entry := CacheEntry{
                        Content: string(decompressed),
                        Headers: map[string]string{
                            "Content-Type": "text/html; charset=UTF-8",
                        },
                        Expired: false,
                    }
                    if config.UseCache {
                        localCache.Set(cacheKey, entry, config.CacheTTL)
                    }
                    serveContent(w, entry, startTime)
                    return
                }
            }
        } else if config.Debug {
            warnLog("❌ Cache MISS (Redis)\n")
        }
    }

    // If we get here, proxy the request
    if config.Debug {
        errorLog("❌ Cache MISS (All) - Proxying to backend\n")
    }
    proxyStart := time.Now()
    entry, err := proxyRequest(w, r)
    if err != nil {
        errorLog("Proxy error: %v\n", err)
        w.WriteHeader(http.StatusBadGateway)
        return
    }
    w.Header().Set("X-Proxy-Time", fmt.Sprintf("%.2fms", time.Since(proxyStart).Seconds()*1000))

    // Store in local cache
    if config.UseCache {
        localCache.Set(cacheKey, *entry, config.CacheTTL)
    }

    serveContent(w, *entry, startTime)
}

// proxyRequest forwards requests to backend server and handles gzip compression
func proxyRequest(w http.ResponseWriter, r *http.Request) (*CacheEntry, error) {
    config := loadConfig()
    scheme := "http"
    if config.UseHTTPS {
        scheme = "https"
    }
    backendURL := fmt.Sprintf("%s://%s%s", scheme, config.Host, r.URL.Path)
    
    // Create new request
    proxyReq, err := http.NewRequest(r.Method, backendURL, nil)
    if err != nil {
        return nil, err
    }

    // Copy original headers
    proxyReq.Header = r.Header
    // Add Accept-Encoding header to handle gzip
    proxyReq.Header.Set("Accept-Encoding", "gzip")

    // Execute request
    resp, err := httpClient.Do(proxyReq)  // Use pooled client
    if err != nil {
        return nil, err
    }
    defer resp.Body.Close()

    var reader io.ReadCloser = resp.Body
    var isGzipped bool

    // Check if response is gzipped
    if resp.Header.Get("Content-Encoding") == "gzip" {
        reader, err = gzip.NewReader(resp.Body)
        if err != nil {
            return nil, err
        }
        defer reader.Close()
        isGzipped = true
    }

    // Read body
    body, err := io.ReadAll(reader)
    if err != nil {
        return nil, err
    }

    if config.Debug {
        debugLog("Response size: %d bytes from %s\n", len(body), backendURL)
    }

    // Create cache entry
    entry := &CacheEntry{
        Content: string(body),
        Headers: map[string]string{
            "Content-Type": resp.Header.Get("Content-Type"),
        },
        Expired: false,
    }

    // Don't store gzip header in cache
    if isGzipped {
        delete(entry.Headers, "Content-Encoding")
    }

    return entry, nil
}

// serveContent writes cache entry content to HTTP response with headers
func serveContent(w http.ResponseWriter, entry CacheEntry, startTime time.Time) {
    // Set cached headers
    for key, value := range entry.Headers {
        w.Header().Set(key, value)
    }

    w.Header().Set("Content-Type", "text/html; charset=UTF-8")
    w.Header().Set("Fast-Cache", "HIT")
    w.Header().Set("Fast-Cache-Time", fmt.Sprintf("%.2fms", time.Since(startTime).Seconds()*1000))
    w.Header().Set("Fast-Cache-Length", fmt.Sprintf("%d", len(entry.Content)))

    // Add debug information at the end of HTML content
    content := entry.Content
    if config := loadConfig(); config.Debug {
        debugInfo := fmt.Sprintf(`
<!-- Fast-Cache Debug Info:
     Time: %.2fms
     Size: %d bytes
     Cache: %s
     Key: %s
     URL: %s
-->`,
            time.Since(startTime).Seconds()*1000,
            len(content),
            w.Header().Get("Fast-Cache"),
            w.Header().Get("Fast-Cache-Key"),
            w.Header().Get("Fast-Cache-URL"))
        
        content = strings.Replace(content, "</body>", debugInfo+"</body>", 1)
    }

    // Write content with debug info
    w.Write([]byte(content))
}

// getCacheKey generates cache key using Magento's logic
func getCacheKey(r *http.Request) string {
    config := loadConfig()  // Get config instance

    // Check HTTPS flag
    httpsFlag := r.Header.Get("X-Forwarded-Proto") == "https" || config.UseHTTPS

    // Get URL with scheme and host
    url := getUrl(r)

    // Check for Magento vary cookie
    var varyString interface{}
    cookie, err := r.Cookie("X-Magento-Vary")
    if err == nil {
        varyString = cookie.Value
    }

    // Create cache key array similar to Magento
    keyData := []interface{}{
        httpsFlag,
        url,
        varyString,
    }

    // Generate JSON string and hash it
    jsonBytes, _ := json.Marshal(keyData)
    jsonStr := strings.Replace(string(jsonBytes), "/", "\\/", -1)

    if config.Debug {
        log.Printf("HASH-DATA: %s", jsonStr)
    }

    // Create SHA1 hash
    h := md5.New()
    h.Write([]byte(jsonStr))
    return strings.ToUpper(hex.EncodeToString(h.Sum(nil)))
}

// getCacheKeyWithConfig generates cache key using Magento's logic with provided config
func getCacheKeyWithConfig(r *http.Request, config *CacheConfig) string {
    // Check HTTPS flag
    httpsFlag := r.Header.Get("X-Forwarded-Proto") == "https" || config.UseHTTPS

    // Get URL with scheme and host
    url := getUrl(r)

    // Check for Magento vary cookie
    var varyString interface{}
    cookie, err := r.Cookie("X-Magento-Vary")
    if err == nil {
        varyString = cookie.Value
    }

    // Create cache key array similar to Magento
    keyData := []interface{}{
        httpsFlag,
        url,
        varyString,
    }

    // Generate JSON string and hash it
    jsonBytes, _ := json.Marshal(keyData)
    jsonStr := strings.Replace(string(jsonBytes), "/", "\\/", -1)

    if config.Debug {
        log.Printf("HASH-DATA: %s", jsonStr)
    }

    // Create SHA1 hash
    h := md5.New()
    h.Write([]byte(jsonStr))
    return strings.ToUpper(hex.EncodeToString(h.Sum(nil)))
}

// getUrl constructs full URL with scheme and host
func getUrl(r *http.Request) string {
    config := loadConfig()  // Get config instance

    scheme := "http"
    if r.Header.Get("X-Forwarded-Proto") == "https" || config.UseHTTPS {
        scheme = "https"
    }

    host := r.Host
    if config.Host != "" {
        host = config.Host
    }

    url := fmt.Sprintf("%s://%s%s", scheme, host, r.URL.Path)
    if config.Debug {
        log.Printf("URL: %s", url)
    }
    return url
}

// isCacheable determines if request should be cached based on method and path
func isCacheable(r *http.Request) bool {
    config := loadConfig()

    if config.Debug {
        debugLog("Checking URL: %s\n", r.URL.Path)
    }

    // Check HTTP method first
    if r.Method != http.MethodGet {
        if config.Debug {
            warnLog("Not cacheable - Method %s not allowed\n", r.Method)
        }
        return false
    }

    // Normalize path for consistent checking
    path := strings.TrimRight(r.URL.Path, "/")

    // Check against ignored URLs with early return
    for _, pattern := range ignoredURLs {
        // Remove trailing slash from pattern for comparison
        pattern = strings.TrimRight(pattern, "/")
        if strings.HasPrefix(path, pattern) {
            if config.Debug {
                warnLog("Not cacheable - URL %s matches excluded pattern %s\n", path, pattern)
            }
            return false
        }
    }

    // Special handling for static files
    ext := strings.ToLower(filepath.Ext(r.URL.Path))
    staticExts := map[string]bool{
        ".css":  true,
        ".js":   true,
        ".png":  true,
        ".jpg":  true,
        ".jpeg": true,
        ".gif":  true,
        ".svg":  true,
    }
    
    if staticExts[ext] {
        if config.Debug {
            infoLog("Static file detected: %s\n", r.URL.Path)
        }
        return true
    }

    // If we got here, the URL is cacheable
    if config.Debug {
        infoLog("Cacheable - URL %s passed all checks\n", path)
    }
    return true
}

// getEnv retrieves environment variable with default fallback value
func getEnv(key, defaultValue string) string {
    if value := os.Getenv(key); value != "" {
        return value
    }
    return defaultValue
}

// getEnvBool converts environment variable to boolean with default fallback
func getEnvBool(key string, defaultValue bool) bool {
    if value := os.Getenv(key); value != "" {
        return value == "true" || value == "1"
    }
    return defaultValue
}

// getEnvInt converts environment variable to integer with default fallback
func getEnvInt(key string, defaultValue int) int {
    if value := os.Getenv(key); value != "" {
        if i, err := fmt.Sscanf(value, "%d"); err == nil {
            return i
        }
    }
    return defaultValue
}

// First, define a common struct type to use in both places
type CacheKeyInfo struct {
    Key       string    `json:"key"`
    Size      int       `json:"size"`
    ExpiredAt string    `json:"expired_at,omitempty"`
    IsStale   bool      `json:"is_stale"`
}

// Update the handleSecuredCacheList function
func handleSecuredCacheList(w http.ResponseWriter, r *http.Request) {
    config := loadConfig()
    
    // Check secret key from header or query parameter
    secretKey := r.Header.Get("X-Secret-Key")
    if secretKey == "" {
		// as GET query parameter
        secretKey = r.URL.Query().Get("key")
    }

    if secretKey != config.SecretKey {
        w.WriteHeader(http.StatusUnauthorized)
        errorLog("Unauthorized cache list access attempt\n")
        return
    }

    format := r.URL.Query().Get("format")
    var cacheKeys []CacheKeyInfo

    // Get items from local cache
    for k, item := range localCache.Items() {
        if entry, ok := item.Object.(CacheEntry); ok {
            cacheKeys = append(cacheKeys, CacheKeyInfo{
                Key:       k,
                Size:      len(entry.Content),
                ExpiredAt: time.Unix(0, item.Expiration).Format(time.RFC3339),
                IsStale:   entry.Expired,
            })
        }
    }

    switch format {
    case "json":
        w.Header().Set("Content-Type", "application/json")
        json.NewEncoder(w).Encode(cacheKeys)
    default:
        w.Header().Set("Content-Type", "text/html")
        serveHTMLCacheList(w, cacheKeys)
    }
}

func serveHTMLCacheList(w http.ResponseWriter, keys []CacheKeyInfo) {
    tmpl := `<!DOCTYPE html>
    <html>
    <head>
        <title>FPC Cache Keys</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            th { background-color: #4CAF50; color: white; }
            .stale { color: #ff9800; }
            .active { color: #4CAF50; }
            .count { font-size: 1.2em; margin-bottom: 20px; }
        </style>
    </head>
    <body>
        <h1>FPC Cache Keys</h1>
        <div class="count">Total Keys: %d</div>
        <table>
            <tr>
                <th>Key</th>
                <th>Size</th>
                <th>Expires</th>
                <th>Status</th>
            </tr>
            %s
        </table>
    </body>
    </html>`

    var rows string
    for _, k := range keys {
        status := "active"
        statusClass := "active"
        if k.IsStale {
            status = "stale"
            statusClass = "stale"
        }
        rows += fmt.Sprintf("<tr><td>%s</td><td>%d</td><td>%s</td><td class='%s'>%s</td></tr>",
            k.Key,
            k.Size,
            k.ExpiredAt,
            statusClass,
            status)
    }

    fmt.Fprintf(w, tmpl, len(keys), rows)
}
