<?php
/**
 * Admin Coordinator Class
 * Koordiniert alle Admin-Submodule
 * PHP 8.3+ compatible
 * Version: 1.4.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin {
    
    private YAA_Cache_Handler $cache;
    private YAA_Yadore_API $yadore_api;
    private YAA_Amazon_PAAPI $amazon_api;
    
    // Submodule
    private ?YAA_Admin_Settings $settings = null;
    private ?YAA_Admin_Ajax $ajax = null;
    private ?YAA_Admin_Status $status = null;
    private ?YAA_Admin_Docs $docs = null;
    private ?YAA_Admin_Merchants $merchants = null;
    private ?YAA_Admin_Revenue $revenue = null;
    
    public function __construct(
        YAA_Cache_Handler $cache, 
        YAA_Yadore_API $yadore_api, 
        YAA_Amazon_PAAPI $amazon_api
    ) {
        $this->cache = $cache;
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        
        // Submodule initialisieren
        $this->init_submodules();
        
        // Hooks registrieren
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_button'], 100);
        add_action('admin_post_yaa_clear_cache', [$this, 'handle_clear_cache']);
        add_action('admin_post_yaa_clear_images', [$this, 'handle_clear_images']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
    }
    
    /**
     * Initialisiert alle Admin-Submodule
     */
    private function init_submodules(): void {
        // Settings-Modul
        $this->settings = new YAA_Admin_Settings($this->yadore_api, $this->amazon_api, $this->cache);
        
        // AJAX-Modul
        $this->ajax = new YAA_Admin_Ajax($this->cache, $this->yadore_api, $this->amazon_api);
        
        // Status-Modul
        $this->status = new YAA_Admin_Status($this->cache, $this->yadore_api, $this->amazon_api);
        
        // Dokumentation-Modul
        $this->docs = new YAA_Admin_Docs();
        
        // Händler-Modul
        $this->merchants = new YAA_Admin_Merchants($this->yadore_api, $this->cache);
        
        // NEU: Revenue-Kalkulator
        $this->revenue = new YAA_Admin_Revenue($this->yadore_api, $this->cache);
    }
    
    /**
     * Getter für Submodule (falls extern benötigt)
     */
    public function get_settings(): YAA_Admin_Settings {
        return $this->settings;
    }
    
    public function get_ajax(): YAA_Admin_Ajax {
        return $this->ajax;
    }
    
    public function get_status(): YAA_Admin_Status {
        return $this->status;
    }
    
    public function get_merchants(): YAA_Admin_Merchants {
        return $this->merchants;
    }
    
    public function get_revenue(): YAA_Admin_Revenue {
        return $this->revenue;
    }
    
    /**
     * Admin-Menü hinzufügen
     */
    public function add_menu(): void {
        // Hauptmenü
        add_menu_page(
            __('Yadore-Amazon-API', 'yadore-amazon-api'),
            __('Yadore-Amazon', 'yadore-amazon-api'),
            'manage_options',
            'yaa-settings',
            [$this->settings, 'render_page'],
            'dashicons-cart',
            30
        );
        
        // Untermenü: Einstellungen
        add_submenu_page(
            'yaa-settings',
            __('Einstellungen', 'yadore-amazon-api'),
            __('Einstellungen', 'yadore-amazon-api'),
            'manage_options',
            'yaa-settings',
            [$this->settings, 'render_page']
        );
        
        // Untermenü: Revenue-Kalkulator (NEU)
        add_submenu_page(
            'yaa-settings',
            __('Revenue-Kalkulator', 'yadore-amazon-api'),
            __('💰 Revenue-Kalkulator', 'yadore-amazon-api'),
            'manage_options',
            'yaa-revenue',
            [$this->revenue, 'render_page']
        );
        
        // Untermenü: Händlerübersicht
        add_submenu_page(
            'yaa-settings',
            __('Händlerübersicht', 'yadore-amazon-api'),
            __('🏪 Händler', 'yadore-amazon-api'),
            'manage_options',
            'yaa-merchants',
            [$this->merchants, 'render_page']
        );
        
        // Untermenü: Cache & Status
        add_submenu_page(
            'yaa-settings',
            __('Cache & Status', 'yadore-amazon-api'),
            __('Cache & Status', 'yadore-amazon-api'),
            'manage_options',
            'yaa-status',
            [$this->status, 'render_page']
        );
        
        // Untermenü: Dokumentation
        add_submenu_page(
            'yaa-settings',
            __('Dokumentation', 'yadore-amazon-api'),
            __('Dokumentation', 'yadore-amazon-api'),
            'manage_options',
            'yaa-docs',
            [$this->docs, 'render_page']
        );
    }
    
    /**
     * Settings registrieren
     */
    public function register_settings(): void {
        register_setting('yaa_settings', 'yaa_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this->settings, 'sanitize_settings'],
        ]);
    }
    
    /**
     * Admin Assets laden
     */
    public function enqueue_admin_assets(string $hook): void {
        // Prüfen ob dies eine YAA Admin-Seite ist
        $is_yaa_page = str_contains($hook, 'yaa-') 
                    || str_contains($hook, 'yaa_') 
                    || $hook === 'toplevel_page_yaa-settings';
        
        if (!$is_yaa_page) {
            return;
        }
        
        // Admin CSS
        wp_register_style('yaa-admin', false, [], YAA_VERSION);
        wp_enqueue_style('yaa-admin');
        wp_add_inline_style('yaa-admin', $this->get_admin_css());
        
        // Tab-JavaScript (unabhängig)
        wp_register_script('yaa-admin-tabs', false, ['jquery'], YAA_VERSION, true);
        wp_enqueue_script('yaa-admin-tabs');
        wp_add_inline_script('yaa-admin-tabs', $this->get_tab_js());
        
        // AJAX Localization
        wp_localize_script('yaa-admin-tabs', 'yaaAdmin', [
            'nonce'   => wp_create_nonce('yaa_admin_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
        
        // Color Picker auf Settings-Seite
        if ($hook === 'toplevel_page_yaa-settings' || str_ends_with($hook, '_page_yaa-settings')) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            
            wp_register_script('yaa-admin-extended', false, ['jquery', 'wp-color-picker', 'yaa-admin-tabs'], YAA_VERSION, true);
            wp_enqueue_script('yaa-admin-extended');
            wp_add_inline_script('yaa-admin-extended', $this->get_extended_admin_js());
        }
        
        // Händler-Seite spezifisches JS
        if (str_ends_with($hook, '_page_yaa-merchants')) {
            wp_register_script('yaa-admin-merchants', false, ['jquery', 'yaa-admin-tabs'], YAA_VERSION, true);
            wp_enqueue_script('yaa-admin-merchants');
            wp_add_inline_script('yaa-admin-merchants', $this->merchants->get_page_js());
        }
        
        // NEU: Revenue-Kalkulator JS
        if (str_ends_with($hook, '_page_yaa-revenue')) {
            wp_register_script('yaa-admin-revenue', false, ['jquery', 'yaa-admin-tabs'], YAA_VERSION, true);
            wp_enqueue_script('yaa-admin-revenue');
            wp_add_inline_script('yaa-admin-revenue', $this->revenue->get_page_js());
        }
    }
    
    /**
     * Admin Bar Button
     */
    public function add_admin_bar_button(\WP_Admin_Bar $admin_bar): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $admin_bar->add_menu([
            'id'    => 'yaa-clear-cache',
            'title' => '🗑️ YAA Cache leeren',
            'href'  => wp_nonce_url(admin_url('admin-post.php?action=yaa_clear_cache'), 'yaa_clear_cache_nonce'),
        ]);
    }
    
    /**
     * Cache leeren Handler
     */
    public function handle_clear_cache(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Keine Berechtigung', 'yadore-amazon-api'));
        }
        
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'yaa_clear_cache_nonce')) {
            wp_die(__('Sicherheitscheck fehlgeschlagen', 'yadore-amazon-api'));
        }
        
        $this->cache->clear_all();
        
        set_transient('yaa_admin_notice', [
            'type'    => 'success', 
            'message' => __('Cache erfolgreich geleert!', 'yadore-amazon-api'),
        ], 30);
        
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=yaa-status');
        wp_safe_redirect($redirect);
        exit;
    }
    
    /**
     * Bilder löschen Handler
     */
    public function handle_clear_images(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Keine Berechtigung', 'yadore-amazon-api'));
        }
        
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'yaa_clear_images_nonce')) {
            wp_die(__('Sicherheitscheck fehlgeschlagen', 'yadore-amazon-api'));
        }
        
        $upload_dir = wp_upload_dir();
        $image_dir = $upload_dir['basedir'] . '/yadore-amazon-api';
        
        $deleted_count = 0;
        
        if (is_dir($image_dir)) {
            $files = glob($image_dir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        if (unlink($file)) {
                            $deleted_count++;
                        }
                    }
                }
            }
        }
        
        set_transient('yaa_admin_notice', [
            'type'    => 'success', 
            'message' => sprintf(__('%d Bilder erfolgreich gelöscht!', 'yadore-amazon-api'), $deleted_count),
        ], 30);
        
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=yaa-status');
        wp_safe_redirect($redirect);
        exit;
    }
    
    /**
     * Admin Notices
     */
    public function admin_notices(): void {
        $notice = get_transient('yaa_admin_notice');
        
        if ($notice !== false && is_array($notice)) {
            delete_transient('yaa_admin_notice');
            $type = ($notice['type'] ?? '') === 'success' ? 'success' : 'error';
            $message = $notice['message'] ?? '';
            
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible">';
            echo '<p><strong>Yadore-Amazon-API:</strong> ' . esc_html($message) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Dashboard Widget hinzufügen
     */
    public function add_dashboard_widget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        wp_add_dashboard_widget(
            'yaa_status_widget',
            '📦 Yadore-Amazon-API Status',
            [$this->status, 'render_dashboard_widget']
        );
    }
    
    /**
     * Admin CSS
     */
    private function get_admin_css(): string {
        return '
            .yaa-admin-wrap { max-width: 1200px; }
            .yaa-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .yaa-card h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px; }
            .yaa-card h3 { margin-top: 20px; color: #1d2327; }
            .yaa-card h4 { margin: 15px 0 10px; color: #50575e; }
            .yaa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
            .yaa-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
            .yaa-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
            .yaa-status-badge { display: inline-block; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 600; }
            .yaa-status-success { background: #d4edda; color: #155724; }
            .yaa-status-warning { background: #fff3cd; color: #856404; }
            .yaa-status-error { background: #f8d7da; color: #721c24; }
            .yaa-status-info { background: #cce5ff; color: #004085; }
            .yaa-tabs { display: flex; flex-wrap: wrap; gap: 0; margin-bottom: 0; border-bottom: 1px solid #ccd0d4; background: #f6f7f7; }
            .yaa-tab { padding: 12px 20px; background: transparent; border: 1px solid transparent; border-bottom: none; cursor: pointer; margin-bottom: -1px; transition: all 0.2s; font-weight: 500; }
            .yaa-tab:hover { background: #fff; }
            .yaa-tab.active { background: #fff; border-color: #ccd0d4; border-bottom-color: #fff; font-weight: 600; }
            .yaa-tab-content { display: none; padding-top: 20px; }
            .yaa-tab-content.active { display: block; }
            .yaa-form-row { margin-bottom: 20px; }
            .yaa-form-row label { display: block; font-weight: 600; margin-bottom: 5px; color: #1d2327; }
            .yaa-form-row label.inline { display: inline; font-weight: normal; }
            .yaa-form-row input[type="text"],
            .yaa-form-row input[type="password"],
            .yaa-form-row input[type="number"],
            .yaa-form-row input[type="url"],
            .yaa-form-row select,
            .yaa-form-row textarea { width: 100%; max-width: 400px; padding: 8px 10px; border: 1px solid #8c8f94; border-radius: 4px; }
            .yaa-form-row textarea { min-height: 80px; max-width: 600px; }
            .yaa-form-row .description { color: #646970; font-size: 13px; margin-top: 6px; line-height: 1.5; }
            .yaa-form-row .description code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
            .yaa-shortcode-box { background: #f6f7f7; padding: 12px 15px; border-radius: 4px; font-family: Consolas, Monaco, monospace; font-size: 13px; margin: 10px 0; overflow-x: auto; border: 1px solid #dcdcde; }
            .yaa-test-result { margin-top: 10px; padding: 12px; border-radius: 4px; display: none; }
            .yaa-marketplace-info { background: #f0f6fc; border: 1px solid #c8d9e8; border-radius: 4px; padding: 15px; margin-top: 15px; }
            .yaa-marketplace-info h4 { margin: 0 0 10px 0; color: #1d2327; }
            .yaa-marketplace-info table { margin-top: 10px; }
            .yaa-preview-box { background: #1a1a1a; padding: 20px; border-radius: 8px; margin-top: 15px; }
            .yaa-preview-item { display: inline-block; padding: 15px 20px; margin: 5px; border-radius: 8px; text-align: center; }
            .yaa-code-block { background: #23282d; color: #fff; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.6; }
            .yaa-code-block code { color: #98c379; }
            .yaa-tip { background: #fff8e5; border-left: 4px solid #ffb900; padding: 12px 15px; margin: 15px 0; }
            .yaa-tip strong { color: #826200; }
            .yaa-columns-2 { column-count: 2; column-gap: 30px; }
            .yaa-columns-3 { column-count: 3; column-gap: 20px; }
            .yaa-feature-box { background: #f0faf0; border: 1px solid #c3e6c3; border-radius: 4px; padding: 15px; margin: 15px 0; }
            .yaa-feature-box.warning { background: #fff8e5; border-color: #ffcc00; }
            .yaa-feature-box.info { background: #f0f6fc; border-color: #c8d9e8; }
            .yaa-stat-box { background: #f6f7f7; padding: 15px; border-radius: 8px; text-align: center; }
            .yaa-stat-number { font-size: 2rem; font-weight: 700; line-height: 1.2; }
            .yaa-stat-label { color: #50575e; font-size: 13px; margin-top: 5px; }
            .yaa-merchant-select { width: 100%; max-width: 400px; min-height: 100px; }
            .yaa-seo-example { background: #e8f5e9; border: 1px solid #c8e6c9; border-radius: 4px; padding: 10px 15px; margin-top: 10px; font-family: monospace; font-size: 12px; }
            
            /* Merchant Table Styles */
            .yaa-merchant-table { width: 100%; border-collapse: collapse; }
            .yaa-merchant-table th { background: #f6f7f7; text-align: left; padding: 12px; border-bottom: 2px solid #ccd0d4; }
            .yaa-merchant-table td { padding: 10px 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
            .yaa-merchant-table tr:hover { background: #f9f9f9; }
            .yaa-merchant-logo { width: 40px; height: 40px; object-fit: contain; border-radius: 4px; }
            .yaa-filter-bar { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; padding: 15px; background: #f6f7f7; border-radius: 4px; }
            .yaa-filter-bar input, .yaa-filter-bar select { padding: 8px 12px; border: 1px solid #ccd0d4; border-radius: 4px; }
            .yaa-pagination { display: flex; gap: 5px; margin-top: 20px; justify-content: center; }
            .yaa-pagination a, .yaa-pagination span { padding: 8px 12px; border: 1px solid #ccd0d4; border-radius: 4px; text-decoration: none; }
            .yaa-pagination a:hover { background: #f0f0f1; }
            .yaa-pagination .current { background: #2271b1; color: #fff; border-color: #2271b1; }
            
            @media (max-width: 782px) { 
                .yaa-grid { grid-template-columns: 1fr; }
                .yaa-grid-3, .yaa-grid-4 { grid-template-columns: 1fr; }
                .yaa-columns-2, .yaa-columns-3 { column-count: 1; }
                .yaa-filter-bar { flex-direction: column; align-items: stretch; }
            }
        ';
    }
    
    /**
     * Tab JavaScript
     */
    private function get_tab_js(): string {
        return '
            jQuery(document).ready(function($) {
                $(".yaa-tab").on("click", function() {
                    var tab = $(this).data("tab");
                    if (!tab) return;
                    
                    $(".yaa-tab").removeClass("active");
                    $(this).addClass("active");
                    $(".yaa-tab-content").removeClass("active");
                    $("#yaa-tab-" + tab).addClass("active");
                    
                    try {
                        localStorage.setItem("yaa_active_tab", tab);
                    } catch(e) {}
                });
                
                try {
                    var savedTab = localStorage.getItem("yaa_active_tab");
                    if (savedTab) {
                        var $savedTabEl = $(".yaa-tab[data-tab=\"" + savedTab + "\"]");
                        if ($savedTabEl.length) {
                            $savedTabEl.trigger("click");
                        }
                    }
                } catch(e) {}
            });
        ';
    }
    
    /**
     * Extended Admin JavaScript
     */
    private function get_extended_admin_js(): string {
        return '
            jQuery(document).ready(function($) {
                if ($.fn.wpColorPicker) {
                    $(".yaa-color-picker").wpColorPicker();
                }
                
                $("select[name=\'yaa_settings[amazon_marketplace]\']").on("change", function() {
                    var marketplace = $(this).val();
                    $(".yaa-marketplace-detail").hide();
                    $("#marketplace-info-" + marketplace.replace(/\\./g, "-")).show();
                });
                
                $(".yaa-test-btn").on("click", function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var action = $btn.data("action");
                    var $result = $btn.siblings(".yaa-test-result");
                    
                    $btn.prop("disabled", true);
                    var originalText = $btn.text();
                    $btn.text("Teste...");
                    
                    var data = {
                        action: action,
                        nonce: yaaAdmin.nonce,
                        yadore_api_key: $("input[name=\'yaa_settings[yadore_api_key]\']").val(),
                        amazon_access_key: $("input[name=\'yaa_settings[amazon_access_key]\']").val(),
                        amazon_secret_key: $("input[name=\'yaa_settings[amazon_secret_key]\']").val(),
                        amazon_partner_tag: $("input[name=\'yaa_settings[amazon_partner_tag]\']").val(),
                        amazon_marketplace: $("select[name=\'yaa_settings[amazon_marketplace]\']").val(),
                        redis_host: $("input[name=\'yaa_settings[redis_host]\']").val(),
                        redis_port: $("input[name=\'yaa_settings[redis_port]\']").val(),
                        redis_password: $("input[name=\'yaa_settings[redis_password]\']").val(),
                        redis_database: $("input[name=\'yaa_settings[redis_database]\']").val()
                    };
                    
                    $.post(yaaAdmin.ajaxurl, data, function(response) {
                        $btn.prop("disabled", false).text(originalText);
                        if (response.success) {
                            $result.html("<div class=\'yaa-status-success\' style=\'padding:12px;border-radius:4px;\'>✅ " + response.data.message + "</div>").show();
                        } else {
                            $result.html("<div class=\'yaa-status-error\' style=\'padding:12px;border-radius:4px;\'>❌ " + response.data.message + "</div>").show();
                        }
                    }).fail(function() {
                        $btn.prop("disabled", false).text(originalText);
                        $result.html("<div class=\'yaa-status-error\' style=\'padding:12px;border-radius:4px;\'>❌ Verbindungsfehler</div>").show();
                    });
                });
                
                $("#yaa-refresh-merchants").on("click", function() {
                    var $btn = $(this);
                    var $status = $("#yaa-merchant-refresh-status");
                    
                    $btn.prop("disabled", true).text("Wird geladen...");
                    $status.html("");
                    
                    $.post(yaaAdmin.ajaxurl, {
                        action: "yaa_refresh_merchants",
                        nonce: yaaAdmin.nonce,
                        market: $("select[name=\'yaa_settings[yadore_market]\']").val()
                    }, function(response) {
                        $btn.prop("disabled", false).text("🔄 Händlerliste aktualisieren");
                        
                        if (response.success) {
                            $status.html("<span style=\'color:green;\'>✅ " + response.data.message + "</span>");
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            $status.html("<span style=\'color:red;\'>❌ " + response.data.message + "</span>");
                        }
                    }).fail(function() {
                        $btn.prop("disabled", false).text("🔄 Händlerliste aktualisieren");
                        $status.html("<span style=\'color:red;\'>❌ Verbindungsfehler</span>");
                    });
                });
                
                $("select.yaa-merchant-select").on("change", function() {
                    var $select = $(this);
                    var $input = $select.closest(".yaa-form-row").find("input[type=\'text\']");
                    var values = $select.val() || [];
                    $input.val(values.join(", "));
                });
            });
        ';
    }
}
