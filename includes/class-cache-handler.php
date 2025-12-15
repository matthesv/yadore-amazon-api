<?php
/**
 * Cache Handler - Redis detection and caching logic
 * PHP 8.3+ compatible
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
                
                // PHP 8.3+ compatible connection
                $connected = @$redis->connect($host, $port, 2.0);
                
                if ($connected) {
                    if ($password !== '') {
                        $redis->auth($password);
                    }
                    if ($database > 0) {
                        $redis->select($database);
                    }
                    
                    // Test connection
                    $pong = $redis->ping();
                    if ($pong === true || $pong === '+PONG' || $pong === 'PONG') {
                        $this->redis_client = $redis;
                        $this->redis_available = true;
                        return;
                    }
                }
            } catch (\RedisException|\Exception $e) {
                error_log('YAA Redis Error: ' . $e->getMessage());
            }
        }
        
        // Try Predis (if available)
        if (!$this->redis_available && class_exists('Predis\Client')) {
            try {
                $config = [
                    'scheme' => 'tcp',
                    'host'   => $host,
                    'port'   => $port,
                ];
                
                if ($password !== '') {
                    $config['password'] = $password;
                }
                if ($database > 0) {
                    $config['database'] = $database;
                }
                
                /** @var \Predis\Client $predis */
                $predis = new \Predis\Client($config);
                $predis->ping();
                
                $this->predis_client = $predis;
                $this->redis_available = true;
            } catch (\Exception $e) {
                error_log('YAA Predis Error: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Get Redis configuration value
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
                    $data = $this->redis_client->get($prefixed_key);
                    if ($data !== false && $data !== null) {
                        return maybe_unserialize($data);
                    }
                } catch (\RedisException|\Exception $e) {
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
                    $this->redis_client->setex($prefixed_key, $expiration, $serialized);
                } catch (\RedisException|\Exception $e) {
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
     */
    public function delete(string $key): void {
        $prefixed_key = 'yaa_' . $key;
        
        if ($this->redis_available) {
            if ($this->using_object_cache) {
                wp_cache_delete($prefixed_key, 'yaa');
            } elseif ($this->redis_client instanceof \Redis) {
                try {
                    $this->redis_client->del($prefixed_key);
                } catch (\RedisException|\Exception $e) {
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
     * Clear all YAA cache entries
     */
    public function clear_all(): bool {
        global $wpdb;
        
        // Clear Redis
        if ($this->redis_available && !$this->using_object_cache) {
            try {
                if ($this->redis_client instanceof \Redis) {
                    $keys = $this->redis_client->keys('yaa_*');
                    if (!empty($keys)) {
                        $this->redis_client->del($keys);
                    }
                } elseif ($this->predis_client !== null) {
                    $keys = $this->predis_client->keys('yaa_*');
                    if (!empty($keys)) {
                        $this->predis_client->del($keys);
                    }
                }
            } catch (\Exception $e) {
                error_log('YAA Redis CLEAR Error: ' . $e->getMessage());
            }
        }
        
        // Clear object cache group
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('yaa');
        }
        
        // Clear transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yaa_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yaa_%'");
        
        return true;
    }
    
    /**
     * Get detailed cache status
     * 
     * @return array<string, mixed>
     */
    public function get_status(): array {
        $status = [
            'redis_available'    => $this->redis_available,
            'using_object_cache' => $this->using_object_cache,
            'cache_backend'      => 'WordPress Transients',
            'redis_info'         => null,
        ];
        
        if ($this->redis_available) {
            $status['cache_backend'] = $this->using_object_cache 
                ? 'WordPress Object Cache (Redis)' 
                : 'Direct Redis Connection';
            
            if ($this->redis_client instanceof \Redis) {
                try {
                    $info = $this->redis_client->info();
                    $status['redis_info'] = [
                        'version'           => $info['redis_version'] ?? 'Unknown',
                        'used_memory'       => $info['used_memory_human'] ?? 'Unknown',
                        'connected_clients' => $info['connected_clients'] ?? 'Unknown',
                    ];
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }
        
        return $status;
    }
    
    /**
     * Test Redis connection with custom settings
     * 
     * @return array{success: bool, message: string}
     */
    public function test_connection(string $host, int $port, string $password = '', int $database = 0): array {
        if (!extension_loaded('redis') || !class_exists('Redis')) {
            return [
                'success' => false,
                'message' => __('PHP Redis Extension nicht installiert.', 'yadore-amazon-api'),
            ];
        }
        
        try {
            $redis = new \Redis();
            
            $connected = @$redis->connect($host, $port, 2.0);
            
            if (!$connected) {
                return [
                    'success' => false,
                    'message' => __('Verbindung zu Redis fehlgeschlagen.', 'yadore-amazon-api'),
                ];
            }
            
            if ($password !== '') {
                $auth_result = $redis->auth($password);
                if (!$auth_result) {
                    return [
                        'success' => false,
                        'message' => __('Redis-Authentifizierung fehlgeschlagen.', 'yadore-amazon-api'),
                    ];
                }
            }
            
            if ($database > 0) {
                $redis->select($database);
            }
            
            $pong = $redis->ping();
            $redis->close();
            
            $success = $pong === true || $pong === '+PONG' || $pong === 'PONG';
            
            return [
                'success' => $success,
                'message' => $success 
                    ? __('Redis-Verbindung erfolgreich!', 'yadore-amazon-api')
                    : __('Redis-Ping fehlgeschlagen.', 'yadore-amazon-api'),
            ];
        } catch (\RedisException|\Exception $e) {
            return [
                'success' => false,
                'message' => 'Fehler: ' . $e->getMessage(),
            ];
        }
    }
}
