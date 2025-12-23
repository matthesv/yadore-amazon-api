<?php
/**
 * Plugin Name: Yadore-Amazon-API
 * Plugin URI: https://github.com/matthesv/yadore-amazon-api
 * Description: Universelles Affiliate-Plugin für Yadore und Amazon PA-API 5.0 mit Redis-Caching, eigenen Produkten und vollständiger Backend-Konfiguration.
 * Version: 1.8.2
 * Author: Matthes Vogel
 * Author URI: https://example.com
 * Text Domain: yadore-amazon-api
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * GitHub Plugin URI: https://github.com/matthesv/yadore-amazon-api
 * Primary Branch: main
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('YAA_VERSION', '1.8.2');
define('YAA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('YAA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('YAA_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('YAA_PLUGIN_FILE', __FILE__);

/**
 * Load plugin textdomain
 */
function yaa_load_textdomain(): void {
    load_plugin_textdomain('yadore-amazon-api', false, dirname(YAA_PLUGIN_BASENAME) . '/languages');
}
add_action('plugins_loaded', 'yaa_load_textdomain');

/**
 * Get plugin option with default
 * 
 * @param string $key Option key
 * @param mixed $default Default value
 * @return mixed Option value or default
 */
function yaa_get_option(string $key, mixed $default = ''): mixed {
    $options = get_option('yaa_settings', []);
    return $options[$key] ?? $default;
}

/**
 * Get cache time settings
 */
function yaa_get_cache_time(): int {
    $hours = (int) yaa_get_option('cache_duration', 6);
    return max(1, $hours) * 3600;
}

/**
 * Get fallback cache time
 */
function yaa_get_fallback_time(): int {
    $hours = (int) yaa_get_option('fallback_duration', 24);
    return max(1, $hours) * 3600;
}

// =========================================
// AUTOLOADER
// =========================================
require_once YAA_PLUGIN_PATH . 'includes/class-autoloader.php';
YAA_Autoloader::register(YAA_PLUGIN_PATH);
YAA_Autoloader::add_directory('includes/admin');

// =========================================
// PLUGIN UPDATE CHECKER (GitHub)
// =========================================
if (file_exists(YAA_PLUGIN_PATH . 'includes/plugin-update-checker/plugin-update-checker.php')) {
    require_once YAA_PLUGIN_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';
    
    $yaaUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/matthesv/yadore-amazon-api/',
        YAA_PLUGIN_FILE,
        'yadore-amazon-api'
    );
    
    $yaaUpdateChecker->setBranch('main');
    
    $github_token = defined('YAA_GITHUB_TOKEN') ? YAA_GITHUB_TOKEN : yaa_get_option('github_token', '');
    if (!empty($github_token)) {
        $yaaUpdateChecker->setAuthentication($github_token);
    }
}

/**
 * Main Plugin Class - PHP 8.3+ compatible
 * Version 1.8.0 - Mit separatem Custom CSS pro Shortcode-Typ
 */
final class Yadore_Amazon_API_Plugin {
    
    /**
     * Singleton instance
     */
    private static ?self $instance = null;
    
    /**
     * Plugin components
     */
    public readonly YAA_Cache_Handler $cache;
    public readonly YAA_Yadore_API $yadore_api;
    public readonly YAA_Amazon_PAAPI $amazon_api;
    public readonly YAA_Custom_Products $custom_products;
    public readonly YAA_Shortcode_Renderer $shortcode;
    public readonly YAA_Admin $admin;
    public readonly YAA_Image_Proxy $image_proxy;
    public readonly YAA_Search_Shortcode $search_shortcode;
    public readonly YAA_Banner_Shortcode $banner_shortcode;
    
    /**
     * Shortcode-Typen die auf der Seite gefunden wurden
     * @var array<string, bool>
     */
    private array $detected_shortcode_types = [
        'grid'   => false,
        'banner' => false,
        'search' => false,
    ];

    /**
     * Get singleton instance
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor (Singleton)
     */
    private function __construct() {
        $this->init_components();
        $this->register_hooks();
    }
    
    /**
     * Initialize all plugin components
     */
    private function init_components(): void {
        // Core Components
        $this->cache           = new YAA_Cache_Handler();
        $this->yadore_api      = new YAA_Yadore_API($this->cache);
        $this->amazon_api      = new YAA_Amazon_PAAPI($this->cache);
        $this->custom_products = new YAA_Custom_Products();
        $this->shortcode       = new YAA_Shortcode_Renderer(
            $this->yadore_api, 
            $this->amazon_api, 
            $this->cache,
            $this->custom_products
        );
        
        // Image Proxy Component
        $this->image_proxy = new YAA_Image_Proxy();

        // Search Shortcode Component
        $this->search_shortcode = new YAA_Search_Shortcode(
            $this->yadore_api,
            $this->amazon_api
        );
        
        // Banner Shortcode Component
        $this->banner_shortcode = new YAA_Banner_Shortcode(
            $this->yadore_api,
            $this->amazon_api,
            $this->custom_products
        );

        // Admin Component
        $this->admin = new YAA_Admin($this->cache, $this->yadore_api, $this->amazon_api);
    }
    
    /**
     * Register plugin hooks
     */
    private function register_hooks(): void {
        register_activation_hook(YAA_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(YAA_PLUGIN_FILE, [$this, 'deactivate']);
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('yaa_cache_refresh_event', [$this, 'preload_cache']);
    }
    
    /**
     * Plugin activation
     */
    public function activate(): void {
        // Default settings - NEU: Custom CSS Felder hinzugefügt
        $defaults = [
            // Yadore Settings
            'enable_yadore'          => 'yes',
            'yadore_api_key'         => '',
            'yadore_market'          => 'de',
            'yadore_precision'       => 'fuzzy',
            'yadore_default_limit'   => 9,
            'yadore_default_sort'    => 'rel_desc',
            'search_default_sort'    => '',
            'yadore_featured_keywords' => '',
            
            // Amazon Settings
            'enable_amazon'          => 'yes',
            'amazon_access_key'      => '',
            'amazon_secret_key'      => '',
            'amazon_partner_tag'     => '',
            'amazon_marketplace'     => 'de',
            'amazon_default_category'=> 'All',
            'amazon_language'        => '',
            'amazon_image_size'      => 'Large',
            
            // Cache Settings
            'cache_duration'         => 6,
            'fallback_duration'      => 24,
            'enable_redis'           => 'auto',
            'redis_host'             => '127.0.0.1',
            'redis_port'             => 6379,
            'redis_password'         => '',
            'redis_database'         => 0,
            
            // Local Image Storage
            'enable_local_images'    => 'yes',
            'image_filename_format'  => 'seo',
            'image_resize_enabled'   => 'yes',
            'preferred_image_size'   => 'Large',
            
            // Image Proxy Settings
            'enable_image_proxy'     => 'yes',
            'image_proxy_cache'      => 24,
            
            // Fuzzy Search settings
            'enable_fuzzy_search'    => 'yes',
            'fuzzy_auto_mix'         => 'no',
            'fuzzy_threshold'        => 30,
            'fuzzy_weight_title'     => 0.40,
            'fuzzy_weight_description' => 0.25,
            'fuzzy_weight_category'  => 0.20,
            'fuzzy_weight_merchant'  => 0.10,
            'fuzzy_weight_keywords'  => 0.05,
            
            // Display Settings
            'disable_default_css'    => 'no',
            'grid_columns_desktop'   => 3,
            'grid_columns_tablet'    => 2,
            'grid_columns_mobile'    => 1,
            'button_text_yadore'     => 'Zum Angebot',
            'button_text_amazon'     => 'Bei Amazon kaufen',
            'button_text_custom'     => 'Zum Angebot',
            'show_prime_badge'       => 'yes',
            'show_merchant'          => 'yes',
            'show_description'       => 'yes',
            'show_custom_badge'      => 'yes',
            'custom_badge_text'      => 'Empfohlen',
            'color_primary'          => '#ff00cc',
            'color_secondary'        => '#00ffff',
            'color_amazon'           => '#ff9900',
            'color_custom'           => '#4CAF50',
            
            // NEU: Custom CSS für jeden Shortcode-Typ (wenn Default-CSS deaktiviert)
            'custom_css_grid'        => '',
            'custom_css_banner'      => '',
            'custom_css_search'      => '',
            
            // Search Shortcode Settings
            'yadore_featured_keywords' => '',
            
            // Update Settings
            'github_token'           => '',
        ];
        
        $existing = get_option('yaa_settings', []);
        $merged = array_merge($defaults, $existing);
        update_option('yaa_settings', $merged);
        
        // Admin-Verzeichnis erstellen
        $admin_dir = YAA_PLUGIN_PATH . 'includes/admin';
        if (!is_dir($admin_dir)) {
            wp_mkdir_p($admin_dir);
        }
        
        // Bilder-Verzeichnis erstellen
        $upload_dir = wp_upload_dir();
        $image_dir = $upload_dir['basedir'] . '/yadore-amazon-api';
        if (!is_dir($image_dir)) {
            wp_mkdir_p($image_dir);
            file_put_contents($image_dir . '/index.php', '<?php // Silence is golden');
        }
        
        // Schedule cron
        if (!wp_next_scheduled('yaa_cache_refresh_event')) {
            wp_schedule_event(time(), 'twicedaily', 'yaa_cache_refresh_event');
        }
        
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        wp_clear_scheduled_hook('yaa_cache_refresh_event');
        flush_rewrite_rules();
    }
    
    /**
     * Enqueue frontend assets when shortcodes are present
     * NEU: Erkennt und speichert welche Shortcode-Typen vorhanden sind
     */
    public function enqueue_frontend_assets(): void {
        global $post;
        
        if (!($post instanceof WP_Post)) {
            return;
        }
        
        // Grid Shortcodes
        $grid_shortcodes = [
            'yaa_products', 
            'yadore_products', 
            'amazon_products', 
            'combined_products',
            'custom_products',
            'all_products',
            'fuzzy_products',
        ];
        
        // Banner Shortcode
        $banner_shortcodes = ['yaa_banner'];
        
        // Search Shortcode
        $search_shortcodes = ['yadore_search', 'yaa_search', 'yaa_product_search'];
        
        // Prüfen welche Shortcode-Typen vorhanden sind
        foreach ($grid_shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                $this->detected_shortcode_types['grid'] = true;
                break;
            }
        }
        
        foreach ($banner_shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                $this->detected_shortcode_types['banner'] = true;
                break;
            }
        }
        
        foreach ($search_shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                $this->detected_shortcode_types['search'] = true;
                break;
            }
        }
        
        // Wenn mindestens ein Shortcode gefunden wurde, Assets laden
        if ($this->detected_shortcode_types['grid'] || 
            $this->detected_shortcode_types['banner'] || 
            $this->detected_shortcode_types['search']) {
            $this->load_assets();
        }
    }
    
    /**
     * Load frontend assets
     * NEU: Lädt Custom CSS pro Shortcode-Typ wenn Default-CSS deaktiviert
     */
    public function load_assets(): void {
        $disable_css = yaa_get_option('disable_default_css', 'no') === 'yes';
        
        if (!$disable_css) {
            // Standard-CSS laden (wie bisher)
            $this->load_default_css();
        } else {
            // Custom CSS laden - nur für vorhandene Shortcode-Typen
            $this->load_custom_css();
        }
        
        // Frontend JavaScript (immer laden)
        wp_enqueue_script(
            'yaa-frontend-grid',
            YAA_PLUGIN_URL . 'assets/js/frontend-grid.js',
            [],
            YAA_VERSION,
            true
        );
        
        // Proxy-URL ans Frontend übergeben
        wp_localize_script('yaa-frontend-grid', 'yaaProxy', [
            'enabled'  => yaa_get_option('enable_image_proxy', 'yes') === 'yes',
            'endpoint' => admin_url('admin-ajax.php'),
            'action'   => 'yaa_proxy_image',
        ]);
    }
    
    /**
     * Load default CSS (Standard-Verhalten)
     */
    private function load_default_css(): void {
        // Grid CSS
        if ($this->detected_shortcode_types['grid']) {
            wp_enqueue_style(
                'yaa-frontend-grid',
                YAA_PLUGIN_URL . 'assets/css/frontend-grid.css',
                [],
                YAA_VERSION
            );
            
            // CSS Variablen hinzufügen
            $this->add_css_variables('yaa-frontend-grid');
        }
        
        // Banner CSS
        if ($this->detected_shortcode_types['banner']) {
            wp_enqueue_style(
                'yaa-banner',
                YAA_PLUGIN_URL . 'assets/css/yaa-banner.css',
                $this->detected_shortcode_types['grid'] ? ['yaa-frontend-grid'] : [],
                YAA_VERSION
            );
        }
        
        // Search CSS
        if ($this->detected_shortcode_types['search']) {
            wp_enqueue_style(
                'yaa-search',
                YAA_PLUGIN_URL . 'assets/css/yaa-search.css',
                [],
                YAA_VERSION
            );
        }
    }
    
    /**
     * Load custom CSS when default CSS is disabled
     * NEU: Lädt nur CSS für Shortcodes die auf der Seite sind
     */
    private function load_custom_css(): void {
        // Minimales funktionales CSS (immer nötig für JS-Funktionalität)
        $minimal_css = "
            .yaa-description {
                overflow: hidden;
                position: relative;
                max-height: 4.8em; 
                transition: max-height 0.4s ease-out;
            }
            .yaa-description.expanded {
                max-height: none !important;
                -webkit-line-clamp: unset !important;
                line-clamp: unset !important;
                display: block !important;
                overflow: visible !important;
            }
            .yaa-read-more { cursor: pointer; display: inline-block; }
        ";
        
        // Grid Custom CSS
        if ($this->detected_shortcode_types['grid']) {
            $custom_css_grid = yaa_get_option('custom_css_grid', '');
            
            wp_register_style('yaa-custom-grid', false, [], YAA_VERSION);
            wp_enqueue_style('yaa-custom-grid');
            
            // Minimales CSS + Custom CSS kombinieren
            $grid_css = $minimal_css;
            
            // Fallback Grid-Layout wenn kein Custom CSS
            if (empty(trim($custom_css_grid))) {
                $grid_css .= "
                    .yaa-grid-container {
                        display: grid;
                        gap: 20px;
                        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                    }
                ";
            } else {
                $grid_css .= "\n" . $custom_css_grid;
            }
            
            wp_add_inline_style('yaa-custom-grid', $grid_css);
        }
        
        // Banner Custom CSS
        if ($this->detected_shortcode_types['banner']) {
            $custom_css_banner = yaa_get_option('custom_css_banner', '');
            
            wp_register_style('yaa-custom-banner', false, [], YAA_VERSION);
            wp_enqueue_style('yaa-custom-banner');
            
            // Fallback Banner-Layout wenn kein Custom CSS
            $banner_css = '';
            if (empty(trim($custom_css_banner))) {
                $banner_css = "
                    .yaa-banner-container {
                        position: relative;
                        width: 100%;
                        margin: 1rem 0;
                    }
                    .yaa-banner-scroll-wrapper {
                        overflow-x: auto;
                        scroll-behavior: smooth;
                    }
                    .yaa-banner-track {
                        display: flex;
                        gap: 12px;
                    }
                    .yaa-banner-item {
                        flex: 0 0 auto;
                        min-width: 200px;
                    }
                ";
            } else {
                $banner_css = $custom_css_banner;
            }
            
            wp_add_inline_style('yaa-custom-banner', $banner_css);
        }
        
        // Search Custom CSS
        if ($this->detected_shortcode_types['search']) {
            $custom_css_search = yaa_get_option('custom_css_search', '');
            
            wp_register_style('yaa-custom-search', false, [], YAA_VERSION);
            wp_enqueue_style('yaa-custom-search');
            
            // Fallback Search-Layout wenn kein Custom CSS
            $search_css = '';
            if (empty(trim($custom_css_search))) {
                $search_css = "
                    .yaa-search-wrapper {
                        max-width: 100%;
                        margin: 0 auto;
                    }
                    .yaa-search-form {
                        margin-bottom: 1.5rem;
                    }
                    .yaa-search-input-wrapper {
                        display: flex;
                        gap: 0.5rem;
                    }
                    .yaa-search-input {
                        flex: 1;
                        padding: 0.75rem 1rem;
                        border: 1px solid #ccc;
                        border-radius: 4px;
                    }
                    .yaa-search-button {
                        padding: 0.75rem 1.5rem;
                        background: #0073aa;
                        color: #fff;
                        border: none;
                        border-radius: 4px;
                        cursor: pointer;
                    }
                    .yaa-search-results-inner {
                        display: grid;
                        gap: 1rem;
                        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                    }
                ";
            } else {
                $search_css = $custom_css_search;
            }
            
            wp_add_inline_style('yaa-custom-search', $search_css);
        }
    }
    
    /**
     * Add CSS variables to a stylesheet
     */
    private function add_css_variables(string $handle): void {
        $primary = esc_attr((string) yaa_get_option('color_primary', '#ff00cc'));
        $secondary = esc_attr((string) yaa_get_option('color_secondary', '#00ffff'));
        $amazon = esc_attr((string) yaa_get_option('color_amazon', '#ff9900'));
        $custom = esc_attr((string) yaa_get_option('color_custom', '#4CAF50'));
        $columns_desktop = (int) yaa_get_option('grid_columns_desktop', 3);
        $columns_tablet = (int) yaa_get_option('grid_columns_tablet', 2);
        $columns_mobile = (int) yaa_get_option('grid_columns_mobile', 1);
        
        $custom_css = "
            .yaa-grid-container {
                --yaa-primary: {$primary};
                --yaa-secondary: {$secondary};
                --yaa-amazon: {$amazon};
                --yaa-custom: {$custom};
                --yaa-columns-desktop: {$columns_desktop};
                --yaa-columns-tablet: {$columns_tablet};
                --yaa-columns-mobile: {$columns_mobile};
            }
        ";
        wp_add_inline_style($handle, $custom_css);
    }
    
    /**
     * Preload cache via cron
     */
    public function preload_cache(): void {
        $cached_keywords = get_option('yaa_cached_keywords', []);
        
        if (empty($cached_keywords) || !is_array($cached_keywords)) {
            return;
        }
        
        foreach ($cached_keywords as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            
            $source = $entry['source'] ?? 'yadore';
            
            if ($source === 'amazon' && $this->amazon_api->is_configured()) {
                $this->amazon_api->search_items(
                    $entry['keyword'] ?? '',
                    $entry['category'] ?? 'All',
                    $entry['limit'] ?? 10
                );
            } elseif ($this->yadore_api->is_configured()) {
                $this->yadore_api->fetch([
                    'keyword' => $entry['keyword'] ?? '',
                    'limit'   => $entry['limit'] ?? 9,
                    'market'  => $entry['market'] ?? yaa_get_option('yadore_market', 'de'),
                ]);
            }
            
            // Rate-Limiting
            sleep(1);
        }
    }
}

/**
 * Initialize Plugin
 */
function yaa_init(): Yadore_Amazon_API_Plugin {
    return Yadore_Amazon_API_Plugin::get_instance();
}
add_action('plugins_loaded', 'yaa_init', 10);

/**
 * Plugin action links
 */
function yaa_plugin_action_links(array $links): array {
    $settings_link = '<a href="' . admin_url('admin.php?page=yaa-settings') . '">' . 
                     __('Einstellungen', 'yadore-amazon-api') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . YAA_PLUGIN_BASENAME, 'yaa_plugin_action_links');
