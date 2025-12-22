<?php
/**
 * Cache Handler - Redis detection and caching logic
 * PHP 8.3+ compatible
 * 
 * @package Yadore_Amazon_API
 * @since 1.6.0
 * 
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Cache_Handler {
    
    private bool $redis_available = false;
    private ?\Redis $redis_client = null;
    private bool $using_object_cache = false;
    private ?object $predis_client = null;
    
    /**
     * Redis Ping Timeout in Sekunden
     */
    private const REDIS_TIMEOUT = 2.0;
    
    /**
     * Redis Read Timeout in Sekunden
     */
    private const REDIS_READ_TIMEOUT = 2.0;
    
    public function __construct() {
        $this->detect_redis();
    }
    
    /**
     * Detect if Redis is available
     */
    private function detect_redis(): void {
        $enable_redis = (string) yaa_get_option('enable_redis', 'auto');
        
        if ($enable_redis === 'no') {
            $this->redis_available = false;
            return;
        }
        
        // Method 1: Check WordPress object cache
        if (wp_using_ext_object_cache()) {
            global $wp_object_cache;
            
            $redis_detected = (defined('WP_REDIS_DISABLED') && !WP_REDIS_DISABLED)
                || (class_exists('Redis') && is_object($wp_object_cache) && property_exists($wp_object_cache, 'redis'))
                || defined('WP_REDIS_CLIENT');
            
            if ($redis_detected) {
                $this->redis_available = true;
                $this->using_object_cache = true;
                return;
            }
        }
        
        // Method 2: Direct Redis connection
        if ($enable_redis === 'yes' || $enable_redis === 'auto') {
            $this->try_direct_redis_connection();
        }
    }
    
    /**
     * Try direct Redis connection
     */
    private function try_direct_redis_connection(): void {
        $host = $this->get_redis_config('host', '127.0.0.1');
        $port = (int) $this->get_redis_config('port', 6379);
        $password = $this->get_redis_config('password', '');
        $database = (int) $this->get_redis_config('database', 0);
        
        // Try native Redis extension
        if (extension_loaded('redis') && class_exists('Redis')) {
            try {
                $redis = new \Redis();
                
                // PHP 8.3+ compatible connection mit Timeout
                $connected = @$redis->connect(
                    $host, 
                    $port, 
                    self::REDIS_TIMEOUT,
                    null,
                    0,
                    self::REDIS_READ_TIMEOUT
                );
                
                if (!$connected) {
                    throw new \RedisException('Connection failed');
                }
                
                // Authentifizierung (kann Exception werfen)
                if ($password !== '') {
                    $auth_result = $redis->auth($password);
                    if ($auth_result !== true) {
                        throw new \RedisException('Authentication failed');
                    }
                }
                
                // Datenbank auswählen (kann Exception werfen)
                if ($database > 0) {
                    $select_result = $redis->select($database);
                    if ($select_result !== true) {
                        throw new \RedisException('Database selection failed');
                    }
                }
                
                // Test connection mit robustem ping() Handling
                if ($this->verify_redis_connection($redis)) {
                    $this->redis_client = $redis;
                    $this->redis_available = true;
                    return;
                }
                
                // Verbindung fehlgeschlagen - aufräumen
                $this->safe_redis_close($redis);
                
            } catch (\RedisException $e) {
                error_log('YAA Redis Error (RedisException): ' . $e->getMessage());
            } catch (\Exception $e) {
                error_log('YAA Redis Error (Exception): ' . $e->getMessage());
            } catch (\Throwable $e) {
                // PHP 8.x kann auch Error werfen
                error_log('YAA Redis Error (Throwable): ' . $e->getMessage());
            }
        }
        
        // Try Predis (if available)
        if (!$this->redis_available && class_exists('Predis\Client')) {
            $this->try_predis_connection($host, $port, $password, $database);
        }
    }
    
    /**
     * Verify Redis connection with robust ping handling
     * 
     * Handles verschiedene Rückgabetypen von ping():
     * - PHP-Redis 4.x: string "+PONG" oder "PONG"
     * - PHP-Redis 5.x: string "+PONG", "PONG" oder bool true
     * - PHP-Redis 6.x: bool true oder Redis (bei Pipelining)
     * 
     * @param \Redis $redis Redis instance
     * @return bool True wenn Verbindung verifiziert
     */
    private function verify_redis_connection(\Redis $redis): bool {
        try {
            // Suppress warnings für den Fall dass ping() fehlschlägt
            $pong = @$redis->ping();
            
            // Typ-sichere Prüfung aller möglichen Rückgabewerte
            if ($pong === true) {
                return true;
            }
            
            if (is_string($pong)) {
                $pong_upper = strtoupper(trim($pong, '+ '));
                if ($pong_upper === 'PONG') {
                    return true;
                }
            }
            
            // PHP-Redis 6.x kann bei bestimmten Konfigurationen 
            // das Redis-Objekt selbst zurückgeben (Fluent Interface)
            if ($pong instanceof \Redis) {
                return true;
            }
            
            // Fallback: Wenn wir hier ankommen, war ping() nicht erfolgreich
            error_log('YAA Redis: Unexpected ping response type: ' . gettype($pong));
            return false;
            
        } catch (\RedisException $e) {
            error_log('YAA Redis ping() Exception: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            error_log('YAA Redis ping() Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Safely close Redis connection
     * 
     * @param \Redis $redis Redis instance
     */
    private function safe_redis_close(\Redis $redis): void {
        try {
            @$redis->close();
        } catch (\Throwable $e) {
            // Ignore close errors
        }
    }
    
    /**
     * Try Predis connection
     * 
     * @param string $host Redis host
     * @param int $port Redis port
     * @param string $password Redis password
     * @param int $database Redis database
     */
    private function try_predis_connection(string $host, int $port, string $password, int $database): void {
        try {
            $config = [
                'scheme'  => 'tcp',
                'host'    => $host,
                'port'    => $port,
                'timeout' => self::REDIS_TIMEOUT,
            ];
            
            if ($password !== '') {
                $config['password'] = $password;
            }
            if ($database > 0) {
                $config['database'] = $database;
            }
            
            /** @var \Predis\Client $predis */
            $predis = new \Predis\Client($config);
            
            // Predis ping() wirft Exception bei Fehlern
            $pong = $predis->ping();
            
            // Predis gibt "PONG" als String zurück
            if ($pong === 'PONG' || $pong === true) {
                $this->predis_client = $predis;
                $this->redis_available = true;
            }
            
        } catch (\Exception $e) {
            error_log('YAA Predis Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get Redis configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    private function get_redis_config(string $key, mixed $default): mixed {
        // Constants take priority
        $constant_map = [
            'host'     => 'WP_REDIS_HOST',
            'port'     => 'WP_REDIS_PORT',
            'password' => 'WP_REDIS_PASSWORD',
            'database' => 'WP_REDIS_DATABASE',
        ];
        
        if (isset($constant_map[$key]) && defined($constant_map[$key])) {
            return constant($constant_map[$key]);
        }
        
        return yaa_get_option('redis_' . $key, $default);
    }
    
    /**
     * Check if Redis is available
     */
    public function is_redis_available(): bool {
        return $this->redis_available;
    }
    
    /**
     * Get cached data
     * 
     * @param string $key Cache key
     * @return mixed Cached data or false
     */
    public function get(string $key): mixed {
        $prefixed_key = 'yaa_' . $key;
        
        if ($this->redis_available) {
            if ($this->using_object_cache) {
                $data = wp_cache_get($prefixed_key, 'yaa');
                if ($data !== false) {
                    return $data;
                }
            } elseif ($this->redis_client instanceof \Redis) {
                try {
                    $data = @$this->redis_client->get($prefixed_key);
                    if ($data !== false && $data !== null) {
                        return maybe_unserialize($data);
                    }
                } catch (\RedisException $e) {
                    error_log('YAA Redis GET Error (RedisException): ' . $e->getMessage());
                    $this->handle_connection_loss();
                } catch (\Throwable $e) {
                    error_log('YAA Redis GET Error: ' . $e->getMessage());
                }
            } elseif ($this->predis_client !== null) {
                try {
                    $data = $this->predis_client->get($prefixed_key);
                    if ($data !== null) {
                        return maybe_unserialize($data);
                    }
                } catch (\Exception $e) {
                    error_log('YAA Predis GET Error: ' . $e->getMessage());
                }
            }
        }
        
        return get_transient($prefixed_key);
    }
    
    /**
     * Set cached data
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int|null $expiration Expiration in seconds
     * @return bool Success
     */
    public function set(string $key, mixed $data, ?int $expiration = null): bool {
        $expiration ??= yaa_get_cache_time();
        $prefixed_key = 'yaa_' . $key;
        
        if ($this->redis_available) {
            if ($this->using_object_cache) {
                wp_cache_set($prefixed_key, $data, 'yaa', $expiration);
            } elseif ($this->redis_client instanceof \Redis) {
                try {
                    $serialized = maybe_serialize($data);
                    @$this->redis_client->setex($prefixed_key, $expiration, $serialized);
                } catch (\RedisException $e) {
                    error_log('YAA Redis SET Error (RedisException): ' . $e->getMessage());
                    $this->handle_connection_loss();
                } catch (\Throwable $e) {
                    error_log('YAA Redis SET Error: ' . $e->getMessage());
                }
            } elseif ($this->predis_client !== null) {
                try {
                    $serialized = maybe_serialize($data);
                    $this->predis_client->setex($prefixed_key, $expiration, $serialized);
                } catch (\Exception $e) {
                    error_log('YAA Predis SET Error: ' . $e->getMessage());
                }
            }
        }
        
        return set_transient($prefixed_key, $data, $expiration);
    }
    
    /**
     * Delete cached data
     * 
     * @param string $key Cache key
     */
    public function delete(string $key): void {
        $prefixed_key = 'yaa_' . $key;
        
        if ($this->redis_available) {
            if ($this->using_object_cache) {
                wp_cache_delete($prefixed_key, 'yaa');
            } elseif ($this->redis_client instanceof \Redis) {
                try {
                    @$this->redis_client->del($prefixed_key);
                } catch (\RedisException $e) {
                    error_log('YAA Redis DEL Error (RedisException): ' . $e->getMessage());
                    $this->handle_connection_loss();
                } catch (\Throwable $e) {
                    error_log('YAA Redis DEL Error: ' . $e->getMessage());
                }
            } elseif ($this->predis_client !== null) {
                try {
                    $this->predis_client->del([$prefixed_key]);
                } catch (\Exception $e) {
                    error_log('YAA Predis DEL Error: ' . $e->getMessage());
                }
            }
        }
        
        delete_transient($prefixed_key);
    }
    
    /**
     * Handle Redis connection loss
     * 
     * Bei Verbindungsverlust: Fallback auf Transients
     */
    private function handle_connection_loss(): void {
        $this->redis_available = false;
        $this->redis_client = null;
        error_log('YAA: Redis connection lost, falling back to transients');
    }
    
    /**
     * Clear all YAA cache entries
     * 
     * @return bool Success
     */
    public function clear_all(): bool {
        global $wpdb;
        
        // Clear Redis
        if ($this->redis_available && !$this->using_object_cache) {
            try {
                if ($this->redis_client instanceof \Redis) {
                    $keys = @$this->redis_client->keys('yaa_*');
                    if (!empty($keys) && is_array($keys)) {
                        @$this->redis_client->del($keys);
                    }
                } elseif ($this->predis_client !== null) {
                    $keys = $this->predis_client->keys('yaa_*');
                    if (!empty($keys) && is_array($keys)) {
                        $this->predis_client->del($keys);
                    }
                }
            } catch (\RedisException $e) {
                error_log('YAA Redis CLEAR Error (RedisException): ' . $e->getMessage());
            } catch (\Throwable $e) {
                error_log('YAA Redis CLEAR Error: ' . $e->getMessage());
            }
        }
        
        // Clear object cache group
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('yaa');
        }
        
        // Clear transients (escaped query)
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_yaa_%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_yaa_%'
            )
        );
        
        return true;
    }
    
    /**
     * Get detailed cache status
     * 
     * @return array<string, mixed> Cache status
     */
    public function get_status(): array {
        $status = [
            'redis_available'    => $this->redis_available,
            'using_object_cache' => $this->using_object_cache,
            'cache_backend'      => 'WordPress Transients',
            'redis_info'         => null,
            'php_redis_version'  => null,
        ];
        
        // PHP Redis Extension Version
        if (extension_loaded('redis')) {
            $status['php_redis_version'] = phpversion('redis') ?: 'Unknown';
        }
        
        if ($this->redis_available) {
            $status['cache_backend'] = $this->using_object_cache 
                ? 'WordPress Object Cache (Redis)' 
                : 'Direct Redis Connection';
            
            if ($this->redis_client instanceof \Redis) {
                try {
                    $info = @$this->redis_client->info();
                    if (is_array($info)) {
                        $status['redis_info'] = [
                            'version'           => $info['redis_version'] ?? 'Unknown',
                            'used_memory'       => $info['used_memory_human'] ?? 'Unknown',
                            'connected_clients' => $info['connected_clients'] ?? 'Unknown',
                            'uptime_days'       => isset($info['uptime_in_days']) 
                                ? (int) $info['uptime_in_days'] 
                                : null,
                        ];
                    }
                } catch (\Throwable $e) {
                    $status['redis_info'] = ['error' => $e->getMessage()];
                }
            }
        }
        
        return $status;
    }
    
    /**
     * Test Redis connection with custom settings
     * 
     * @param string $host Redis host
     * @param int $port Redis port
     * @param string $password Redis password
     * @param int $database Redis database
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    public function test_connection(string $host, int $port, string $password = '', int $database = 0): array {
        // Check extension
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            return [
                'success' => false,
                'message' => __('PHP Redis Extension nicht installiert.', 'yadore-amazon-api'),
            ];
        }
        
        $redis = null;
        
        try {
            $redis = new \Redis();
            
            // Connection mit Timeout
            $connected = @$redis->connect(
                $host, 
                $port, 
                self::REDIS_TIMEOUT,
                null,
                0,
                self::REDIS_READ_TIMEOUT
            );
            
            if (!$connected) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        __('Verbindung zu Redis fehlgeschlagen (%s:%d).', 'yadore-amazon-api'),
                        $host,
                        $port
                    ),
                ];
            }
            
            // Authentication
            if ($password !== '') {
                try {
                    $auth_result = @$redis->auth($password);
                    if ($auth_result !== true) {
                        $this->safe_redis_close($redis);
                        return [
                            'success' => false,
                            'message' => __('Redis-Authentifizierung fehlgeschlagen.', 'yadore-amazon-api'),
                        ];
                    }
                } catch (\RedisException $e) {
                    $this->safe_redis_close($redis);
                    return [
                        'success' => false,
                        'message' => __('Redis-Authentifizierung fehlgeschlagen: ', 'yadore-amazon-api') . $e->getMessage(),
                    ];
                }
            }
            
            // Database selection
            if ($database > 0) {
                try {
                    $select_result = @$redis->select($database);
                    if ($select_result !== true) {
                        $this->safe_redis_close($redis);
                        return [
                            'success' => false,
                            'message' => sprintf(
                                __('Redis-Datenbank %d konnte nicht ausgewählt werden.', 'yadore-amazon-api'),
                                $database
                            ),
                        ];
                    }
                } catch (\RedisException $e) {
                    $this->safe_redis_close($redis);
                    return [
                        'success' => false,
                        'message' => __('Redis-Datenbank Auswahl fehlgeschlagen: ', 'yadore-amazon-api') . $e->getMessage(),
                    ];
                }
            }
            
            // Ping Test mit robustem Handling
            if (!$this->verify_redis_connection($redis)) {
                $this->safe_redis_close($redis);
                return [
                    'success' => false,
                    'message' => __('Redis-Ping fehlgeschlagen.', 'yadore-amazon-api'),
                ];
            }
            
            // Hole Server-Info für Details
            $details = [];
            try {
                $info = @$redis->info();
                if (is_array($info)) {
                    $details = [
                        'redis_version' => $info['redis_version'] ?? 'Unknown',
                        'os'            => $info['os'] ?? 'Unknown',
                        'used_memory'   => $info['used_memory_human'] ?? 'Unknown',
                    ];
                }
            } catch (\Throwable $e) {
                // Info ist optional, ignoriere Fehler
            }
            
            $this->safe_redis_close($redis);
            
            return [
                'success' => true,
                'message' => __('Redis-Verbindung erfolgreich!', 'yadore-amazon-api'),
                'details' => $details,
            ];
            
        } catch (\RedisException $e) {
            if ($redis instanceof \Redis) {
                $this->safe_redis_close($redis);
            }
            return [
                'success' => false,
                'message' => 'RedisException: ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            if ($redis instanceof \Redis) {
                $this->safe_redis_close($redis);
            }
            return [
                'success' => false,
                'message' => 'Fehler: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check if Redis connection is still alive
     * 
     * @return bool True if connected
     */
    public function is_connected(): bool {
        if (!$this->redis_available) {
            return false;
        }
        
        if ($this->using_object_cache) {
            return true; // Object cache handles this
        }
        
        if ($this->redis_client instanceof \Redis) {
            return $this->verify_redis_connection($this->redis_client);
        }
        
        if ($this->predis_client !== null) {
            try {
                $pong = $this->predis_client->ping();
                return $pong === 'PONG' || $pong === true;
            } catch (\Exception $e) {
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Reconnect to Redis if connection was lost
     * 
     * @return bool Success
     */
    public function reconnect(): bool {
        $this->redis_available = false;
        $this->redis_client = null;
        $this->predis_client = null;
        $this->using_object_cache = false;
        
        $this->detect_redis();
        
        return $this->redis_available;
    }
}
