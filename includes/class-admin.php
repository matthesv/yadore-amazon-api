<?php
/**
 * Admin Settings & Dashboard
 * PHP 8.3+ compatible with full backend configuration
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin {
    
    private YAA_Cache_Handler $cache;
    private YAA_Yadore_API $yadore_api;
    private YAA_Amazon_PAAPI $amazon_api;
    
    public function __construct(
        YAA_Cache_Handler $cache, 
        YAA_Yadore_API $yadore_api, 
        YAA_Amazon_PAAPI $amazon_api
    ) {
        $this->cache = $cache;
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_button'], 100);
        add_action('admin_post_yaa_clear_cache', [$this, 'handle_clear_cache']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        
        // AJAX handlers
        add_action('wp_ajax_yaa_test_yadore', [$this, 'ajax_test_yadore']);
        add_action('wp_ajax_yaa_test_amazon', [$this, 'ajax_test_amazon']);
        add_action('wp_ajax_yaa_test_redis', [$this, 'ajax_test_redis']);
    }
    
    /**
     * Add admin menu
     */
    public function add_menu(): void {
        add_menu_page(
            __('Yadore-Amazon-API', 'yadore-amazon-api'),
            __('Yadore-Amazon', 'yadore-amazon-api'),
            'manage_options',
            'yaa-settings',
            [$this, 'render_settings_page'],
            'dashicons-cart',
            30
        );
        
        add_submenu_page(
            'yaa-settings',
            __('Einstellungen', 'yadore-amazon-api'),
            __('Einstellungen', 'yadore-amazon-api'),
            'manage_options',
            'yaa-settings',
            [$this, 'render_settings_page']
        );
        
        add_submenu_page(
            'yaa-settings',
            __('Cache & Status', 'yadore-amazon-api'),
            __('Cache & Status', 'yadore-amazon-api'),
            'manage_options',
            'yaa-status',
            [$this, 'render_status_page']
        );
        
        add_submenu_page(
            'yaa-settings',
            __('Dokumentation', 'yadore-amazon-api'),
            __('Dokumentation', 'yadore-amazon-api'),
            'manage_options',
            'yaa-docs',
            [$this, 'render_docs_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings(): void {
        register_setting('yaa_settings', 'yaa_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }
    
    /**
     * Sanitize settings
     * 
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>
     */
    public function sanitize_settings(?array $input): array {
        if ($input === null) {
            $input = [];
        }
        
        $sanitized = [];
        
        // Yadore settings
        $sanitized['enable_yadore'] = isset($input['enable_yadore']) ? 'yes' : 'no';
        $sanitized['yadore_api_key'] = sanitize_text_field($input['yadore_api_key'] ?? '');
        $sanitized['yadore_market'] = sanitize_text_field($input['yadore_market'] ?? 'de');
        $sanitized['yadore_precision'] = in_array($input['yadore_precision'] ?? '', ['strict', 'fuzzy'], true) 
            ? $input['yadore_precision'] : 'fuzzy';
        $sanitized['yadore_default_limit'] = max(1, min(50, (int) ($input['yadore_default_limit'] ?? 9)));
        
        // Amazon settings
        $sanitized['enable_amazon'] = isset($input['enable_amazon']) ? 'yes' : 'no';
        $sanitized['amazon_access_key'] = sanitize_text_field($input['amazon_access_key'] ?? '');
        $sanitized['amazon_secret_key'] = $input['amazon_secret_key'] ?? ''; // Don't sanitize secret
        $sanitized['amazon_partner_tag'] = sanitize_text_field($input['amazon_partner_tag'] ?? '');
        $sanitized['amazon_marketplace'] = sanitize_text_field($input['amazon_marketplace'] ?? 'de');
        $sanitized['amazon_default_category'] = sanitize_text_field($input['amazon_default_category'] ?? 'All');
        $sanitized['amazon_language'] = sanitize_text_field($input['amazon_language'] ?? '');
        
        // Cache settings
        $sanitized['cache_duration'] = max(1, min(168, (int) ($input['cache_duration'] ?? 6)));
        $sanitized['fallback_duration'] = max(1, min(720, (int) ($input['fallback_duration'] ?? 24)));
        
        // Redis settings
        $sanitized['enable_redis'] = in_array($input['enable_redis'] ?? '', ['auto', 'yes', 'no'], true) 
            ? $input['enable_redis'] : 'auto';
        $sanitized['redis_host'] = sanitize_text_field($input['redis_host'] ?? '127.0.0.1');
        $sanitized['redis_port'] = max(1, min(65535, (int) ($input['redis_port'] ?? 6379)));
        $sanitized['redis_password'] = $input['redis_password'] ?? '';
        $sanitized['redis_database'] = max(0, min(15, (int) ($input['redis_database'] ?? 0)));
        
        // Display settings
        $sanitized['grid_columns_desktop'] = max(1, min(6, (int) ($input['grid_columns_desktop'] ?? 3)));
        $sanitized['grid_columns_tablet'] = max(1, min(4, (int) ($input['grid_columns_tablet'] ?? 2)));
        $sanitized['grid_columns_mobile'] = max(1, min(2, (int) ($input['grid_columns_mobile'] ?? 1)));
        $sanitized['button_text_yadore'] = sanitize_text_field($input['button_text_yadore'] ?? 'Zum Angebot');
        $sanitized['button_text_amazon'] = sanitize_text_field($input['button_text_amazon'] ?? 'Bei Amazon kaufen');
        $sanitized['show_prime_badge'] = isset($input['show_prime_badge']) ? 'yes' : 'no';
        $sanitized['show_merchant'] = isset($input['show_merchant']) ? 'yes' : 'no';
        $sanitized['show_description'] = isset($input['show_description']) ? 'yes' : 'no';
        $sanitized['color_primary'] = sanitize_hex_color($input['color_primary'] ?? '#ff00cc') ?: '#ff00cc';
        $sanitized['color_secondary'] = sanitize_hex_color($input['color_secondary'] ?? '#00ffff') ?: '#00ffff';
        $sanitized['color_amazon'] = sanitize_hex_color($input['color_amazon'] ?? '#ff9900') ?: '#ff9900';
        
        return $sanitized;
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets(string $hook): void {
        if (!str_contains($hook, 'yaa-') && $hook !== 'toplevel_page_yaa-settings') {
            return;
        }
        
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        
        // Inline admin styles
        $admin_css = $this->get_admin_css();
        wp_add_inline_style('wp-color-picker', $admin_css);
        
        // Admin JS
        $admin_js = $this->get_admin_js();
        wp_add_inline_script('wp-color-picker', $admin_js);
        
        wp_localize_script('wp-color-picker', 'yaaAdmin', [
            'nonce' => wp_create_nonce('yaa_admin_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }
    
    /**
     * Get admin CSS
     */
    private function get_admin_css(): string {
        return '
            .yaa-admin-wrap { max-width: 1200px; }
            .yaa-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
            .yaa-card h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
            .yaa-card h3 { margin-top: 20px; }
            .yaa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
            .yaa-status-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; }
            .yaa-status-success { background: #d4edda; color: #155724; }
            .yaa-status-warning { background: #fff3cd; color: #856404; }
            .yaa-status-error { background: #f8d7da; color: #721c24; }
            .yaa-tabs { display: flex; flex-wrap: wrap; gap: 0; margin-bottom: 0; border-bottom: 1px solid #ccd0d4; }
            .yaa-tab { padding: 10px 20px; background: #f1f1f1; border: 1px solid #ccd0d4; border-bottom: none; cursor: pointer; margin-bottom: -1px; transition: all 0.2s; }
            .yaa-tab:hover { background: #e5e5e5; }
            .yaa-tab.active { background: #fff; border-bottom: 1px solid #fff; font-weight: 600; }
            .yaa-tab-content { display: none; padding-top: 20px; }
            .yaa-tab-content.active { display: block; }
            .yaa-form-row { margin-bottom: 15px; }
            .yaa-form-row label { display: block; font-weight: 600; margin-bottom: 5px; }
            .yaa-form-row input[type="text"],
            .yaa-form-row input[type="password"],
            .yaa-form-row input[type="number"],
            .yaa-form-row select { width: 100%; max-width: 400px; padding: 8px; }
            .yaa-form-row .description { color: #666; font-size: 13px; margin-top: 5px; }
            .yaa-shortcode-box { background: #f5f5f5; padding: 10px 15px; border-radius: 4px; font-family: monospace; margin: 10px 0; overflow-x: auto; }
            .yaa-test-result { margin-top: 10px; padding: 10px; border-radius: 4px; display: none; }
            .yaa-marketplace-info { background: #f0f6fc; border: 1px solid #c8d9e8; border-radius: 4px; padding: 15px; margin-top: 15px; }
            .yaa-marketplace-info h4 { margin: 0 0 10px 0; }
        ';
    }
    
    /**
     * Get admin JavaScript
     */
    private function get_admin_js(): string {
        return '
            jQuery(document).ready(function($) {
                // Color pickers
                $(".yaa-color-picker").wpColorPicker();
                
                // Tabs
                $(".yaa-tab").on("click", function() {
                    var tab = $(this).data("tab");
                    $(".yaa-tab").removeClass("active");
                    $(this).addClass("active");
                    $(".yaa-tab-content").removeClass("active");
                    $("#yaa-tab-" + tab).addClass("active");
                });
                
                // Marketplace change - update info
                $("select[name=\'yaa_settings[amazon_marketplace]\']").on("change", function() {
                    var marketplace = $(this).val();
                    $(".yaa-marketplace-detail").hide();
                    $("#marketplace-info-" + marketplace.replace(".", "-")).show();
                });
                
                // Test buttons
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
                            $result.html("<div class=\'yaa-status-success\' style=\'padding:10px;border-radius:4px;\'>✅ " + response.data.message + "</div>").show();
                        } else {
                            $result.html("<div class=\'yaa-status-error\' style=\'padding:10px;border-radius:4px;\'>❌ " + response.data.message + "</div>").show();
                        }
                    }).fail(function() {
                        $btn.prop("disabled", false).text(originalText);
                        $result.html("<div class=\'yaa-status-error\' style=\'padding:10px;border-radius:4px;\'>❌ Verbindungsfehler</div>").show();
                    });
                });
            });
        ';
    }
    
    /**
     * Render main settings page
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $options = get_option('yaa_settings', []);
        if (!is_array($options)) {
            $options = [];
        }
        
        $cache_status = $this->cache->get_status();
        $marketplaces = $this->amazon_api->get_marketplace_options();
        $yadore_markets = $this->yadore_api->get_markets();
        
        ?>
        <div class="wrap yaa-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('yaa_settings'); ?>
                
                <!-- Tabs -->
                <div class="yaa-tabs">
                    <div class="yaa-tab active" data-tab="yadore">📦 Yadore API</div>
                    <div class="yaa-tab" data-tab="amazon">🛒 Amazon PA-API</div>
                    <div class="yaa-tab" data-tab="cache">⚡ Cache & Redis</div>
                    <div class="yaa-tab" data-tab="display">🎨 Darstellung</div>
                </div>
                
                <!-- Yadore Tab -->
                <div id="yaa-tab-yadore" class="yaa-tab-content active">
                    <?php $this->render_yadore_settings($options, $yadore_markets); ?>
                </div>
                
                <!-- Amazon Tab -->
                <div id="yaa-tab-amazon" class="yaa-tab-content">
                    <?php $this->render_amazon_settings($options, $marketplaces); ?>
                </div>
                
                <!-- Cache Tab -->
                <div id="yaa-tab-cache" class="yaa-tab-content">
                    <?php $this->render_cache_settings($options, $cache_status); ?>
                </div>
                
                <!-- Display Tab -->
                <div id="yaa-tab-display" class="yaa-tab-content">
                    <?php $this->render_display_settings($options); ?>
                </div>
                
                <?php submit_button(__('Einstellungen speichern', 'yadore-amazon-api')); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render Yadore settings
     * 
     * @param array<string, mixed> $options
     * @param array<string, string> $markets
     */
    private function render_yadore_settings(array $options, array $markets): void {
        ?>
        <div class="yaa-card">
            <h2>Yadore API Konfiguration</h2>
            <p class="description">
                Yadore bietet Zugang zu über 9.000 Shops und 250 Millionen Produkten. 
                <a href="https://www.yadore.com/publisher" target="_blank">Publisher-Account erstellen</a>
            </p>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[enable_yadore]" value="yes" 
                        <?php checked(($options['enable_yadore'] ?? 'yes'), 'yes'); ?>>
                    Yadore API aktivieren
                </label>
            </div>
            
            <div class="yaa-form-row">
                <label for="yadore_api_key">API-Key</label>
                <input type="text" id="yadore_api_key" name="yaa_settings[yadore_api_key]" 
                       value="<?php echo esc_attr($options['yadore_api_key'] ?? ''); ?>"
                       <?php echo defined('YADORE_API_KEY') ? 'disabled' : ''; ?>>
                <?php if (defined('YADORE_API_KEY')): ?>
                    <p class="description">✅ Via wp-config.php definiert (YADORE_API_KEY)</p>
                <?php else: ?>
                    <p class="description">Den API-Key erhältst du nach der Registrierung bei Yadore.</p>
                <?php endif; ?>
            </div>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="yadore_market">Standard-Markt</label>
                    <select id="yadore_market" name="yaa_settings[yadore_market]">
                        <?php foreach ($markets as $code => $name): ?>
                            <option value="<?php echo esc_attr($code); ?>" 
                                <?php selected(($options['yadore_market'] ?? 'de'), $code); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="yaa-form-row">
                    <label for="yadore_precision">Such-Genauigkeit</label>
                    <select id="yadore_precision" name="yaa_settings[yadore_precision]">
                        <option value="fuzzy" <?php selected(($options['yadore_precision'] ?? 'fuzzy'), 'fuzzy'); ?>>
                            Fuzzy (mehr Ergebnisse)
                        </option>
                        <option value="strict" <?php selected(($options['yadore_precision'] ?? 'fuzzy'), 'strict'); ?>>
                            Strict (exaktere Treffer)
                        </option>
                    </select>
                </div>
                
                <div class="yaa-form-row">
                    <label for="yadore_default_limit">Standard-Limit</label>
                    <input type="number" id="yadore_default_limit" name="yaa_settings[yadore_default_limit]" 
                           value="<?php echo esc_attr($options['yadore_default_limit'] ?? '9'); ?>"
                           min="1" max="50">
                </div>
            </div>
            
            <div class="yaa-form-row">
                <button type="button" class="button yaa-test-btn" data-action="yaa_test_yadore">
                    Verbindung testen
                </button>
                <div class="yaa-test-result"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Amazon settings
     * 
     * @param array<string, mixed> $options
     * @param array<string, string> $marketplaces
     */
    private function render_amazon_settings(array $options, array $marketplaces): void {
        $current_marketplace = $options['amazon_marketplace'] ?? 'de';
        $all_marketplaces = $this->amazon_api->get_marketplaces();
        ?>
        <div class="yaa-card">
            <h2>Amazon Product Advertising API 5.0</h2>
            <p class="description">
                Für die Amazon PA-API benötigst du ein Amazon Associates Konto. 
                <a href="https://webservices.amazon.com/paapi5/documentation/" target="_blank">Dokumentation</a> | 
                <a href="https://affiliate-program.amazon.de/" target="_blank">Amazon PartnerNet</a>
            </p>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[enable_amazon]" value="yes" 
                        <?php checked(($options['enable_amazon'] ?? 'yes'), 'yes'); ?>>
                    Amazon PA-API aktivieren
                </label>
            </div>
            
            <div class="yaa-form-row">
                <label for="amazon_access_key">Access Key</label>
                <input type="text" id="amazon_access_key" name="yaa_settings[amazon_access_key]" 
                       value="<?php echo esc_attr($options['amazon_access_key'] ?? ''); ?>"
                       <?php echo defined('AMAZON_PAAPI_ACCESS_KEY') ? 'disabled' : ''; ?>>
                <?php if (defined('AMAZON_PAAPI_ACCESS_KEY')): ?>
                    <p class="description">✅ Via wp-config.php definiert</p>
                <?php endif; ?>
            </div>
            
            <div class="yaa-form-row">
                <label for="amazon_secret_key">Secret Key</label>
                <input type="password" id="amazon_secret_key" name="yaa_settings[amazon_secret_key]" 
                       value="<?php echo esc_attr($options['amazon_secret_key'] ?? ''); ?>"
                       <?php echo defined('AMAZON_PAAPI_SECRET_KEY') ? 'disabled' : ''; ?>>
                <?php if (defined('AMAZON_PAAPI_SECRET_KEY')): ?>
                    <p class="description">✅ Via wp-config.php definiert</p>
                <?php endif; ?>
            </div>
            
            <div class="yaa-form-row">
                <label for="amazon_partner_tag">Partner Tag (Affiliate-Name)</label>
                <input type="text" id="amazon_partner_tag" name="yaa_settings[amazon_partner_tag]" 
                       value="<?php echo esc_attr($options['amazon_partner_tag'] ?? ''); ?>"
                       placeholder="deinshop-21"
                       <?php echo defined('AMAZON_PAAPI_PARTNER_TAG') ? 'disabled' : ''; ?>>
                <p class="description">
                    Deine Tracking-ID aus dem Amazon PartnerNet. Wird für die Provision verwendet.
                </p>
            </div>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="amazon_marketplace">Marketplace</label>
                    <select id="amazon_marketplace" name="yaa_settings[amazon_marketplace]">
                        <optgroup label="Europa">
                            <?php foreach (['de', 'fr', 'it', 'es', 'co.uk', 'nl', 'pl', 'se', 'be', 'com.tr'] as $code): ?>
                                <?php if (isset($marketplaces[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" 
                                    <?php selected($current_marketplace, $code); ?>>
                                    <?php echo esc_html($marketplaces[$code]); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Naher Osten & Afrika">
                            <?php foreach (['ae', 'sa', 'eg'] as $code): ?>
                                <?php if (isset($marketplaces[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" 
                                    <?php selected($current_marketplace, $code); ?>>
                                    <?php echo esc_html($marketplaces[$code]); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Nordamerika">
                            <?php foreach (['com', 'ca', 'com.mx', 'com.br'] as $code): ?>
                                <?php if (isset($marketplaces[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" 
                                    <?php selected($current_marketplace, $code); ?>>
                                    <?php echo esc_html($marketplaces[$code]); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Asien-Pazifik">
                            <?php foreach (['co.jp', 'in', 'com.au', 'sg'] as $code): ?>
                                <?php if (isset($marketplaces[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" 
                                    <?php selected($current_marketplace, $code); ?>>
                                    <?php echo esc_html($marketplaces[$code]); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                
                <div class="yaa-form-row">
                    <label for="amazon_default_category">Standard-Kategorie</label>
                    <select id="amazon_default_category" name="yaa_settings[amazon_default_category]">
                        <?php 
                        $search_indexes = $this->amazon_api->get_search_indexes();
                        foreach ($search_indexes as $code => $name): 
                        ?>
                            <option value="<?php echo esc_attr($code); ?>" 
                                <?php selected(($options['amazon_default_category'] ?? 'All'), $code); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Marketplace Info -->
            <div class="yaa-marketplace-info">
                <h4>📍 Marketplace-Details</h4>
                <?php foreach ($all_marketplaces as $code => $info): ?>
                <div id="marketplace-info-<?php echo esc_attr(str_replace('.', '-', $code)); ?>" 
                     class="yaa-marketplace-detail" 
                     style="<?php echo $code !== $current_marketplace ? 'display:none;' : ''; ?>">
                    <table class="widefat" style="margin-top: 10px;">
                        <tr><td><strong>Host:</strong></td><td><code><?php echo esc_html($info['host']); ?></code></td></tr>
                        <tr><td><strong>Region:</strong></td><td><?php echo esc_html($info['region']); ?></td></tr>
                        <tr><td><strong>Währung:</strong></td><td><?php echo esc_html($info['currency']); ?></td></tr>
                        <tr><td><strong>Sprache:</strong></td><td><?php echo esc_html($info['language']); ?></td></tr>
                        <tr><td><strong>Verfügbare Sprachen:</strong></td><td><?php echo esc_html(implode(', ', $info['languages'])); ?></td></tr>
                    </table>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="yaa-form-row" style="margin-top: 20px;">
                <button type="button" class="button yaa-test-btn" data-action="yaa_test_amazon">
                    Verbindung testen
                </button>
                <div class="yaa-test-result"></div>
            </div>
            
            <hr style="margin: 20px 0;">
            
            <h3>⚠️ Amazon API Einschränkungen</h3>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>Mindestens 1 qualifizierter Verkauf in den letzten 30 Tagen erforderlich</li>
                <li>Maximal 1 Request pro Sekunde (bei niedrigen Verkäufen)</li>
                <li>Maximal 10 Produkte pro API-Anfrage</li>
                <li>API-Zugang kann bei Inaktivität entzogen werden</li>
                <li>Partner Tag muss zum gewählten Marketplace passen</li>            
            </ul>
        </div>
        <?php
    }
    
    /**
     * Render cache settings
     * 
     * @param array<string, mixed> $options
     * @param array<string, mixed> $cache_status
     */
    private function render_cache_settings(array $options, array $cache_status): void {
        ?>
        <div class="yaa-card">
            <h2>Cache-Einstellungen</h2>
            
            <div class="yaa-grid">
                <div>
                    <div class="yaa-form-row">
                        <label for="cache_duration">Cache-Dauer (Stunden)</label>
                        <input type="number" id="cache_duration" name="yaa_settings[cache_duration]" 
                               value="<?php echo esc_attr($options['cache_duration'] ?? '6'); ?>"
                               min="1" max="168">
                        <p class="description">Wie lange Daten im Cache gehalten werden (1-168 Stunden).</p>
                    </div>
                    
                    <div class="yaa-form-row">
                        <label for="fallback_duration">Fallback-Dauer (Stunden)</label>
                        <input type="number" id="fallback_duration" name="yaa_settings[fallback_duration]" 
                               value="<?php echo esc_attr($options['fallback_duration'] ?? '24'); ?>"
                               min="1" max="720">
                        <p class="description">Backup-Cache bei API-Fehlern (1-720 Stunden).</p>
                    </div>
                </div>
                
                <div>
                    <h4>📊 Aktueller Status</h4>
                    <table class="widefat" style="margin-top: 10px;">
                        <tr>
                            <td><strong>Backend:</strong></td>
                            <td>
                                <span class="yaa-status-badge <?php echo $cache_status['redis_available'] ? 'yaa-status-success' : 'yaa-status-warning'; ?>">
                                    <?php echo esc_html($cache_status['cache_backend']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php if (!empty($cache_status['redis_info'])): ?>
                        <tr>
                            <td><strong>Redis Version:</strong></td>
                            <td><?php echo esc_html($cache_status['redis_info']['version']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Speichernutzung:</strong></td>
                            <td><?php echo esc_html($cache_status['redis_info']['used_memory']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>Redis-Konfiguration</h2>
            <p class="description">
                Redis bietet schnelleres Caching als WordPress-Transients. 
                Benötigt die PHP Redis-Extension oder Predis.
            </p>
            
            <div class="yaa-form-row">
                <label for="enable_redis">Redis verwenden</label>
                <select id="enable_redis" name="yaa_settings[enable_redis]">
                    <option value="auto" <?php selected(($options['enable_redis'] ?? 'auto'), 'auto'); ?>>
                        Automatisch erkennen
                    </option>
                    <option value="yes" <?php selected(($options['enable_redis'] ?? 'auto'), 'yes'); ?>>
                        Ja, Redis verwenden
                    </option>
                    <option value="no" <?php selected(($options['enable_redis'] ?? 'auto'), 'no'); ?>>
                        Nein, nur Transients
                    </option>
                </select>
                <p class="description">
                    "Automatisch" prüft zuerst WordPress Object Cache, dann direkte Redis-Verbindung.
                </p>
            </div>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="redis_host">Host</label>
                    <input type="text" id="redis_host" name="yaa_settings[redis_host]" 
                           value="<?php echo esc_attr($options['redis_host'] ?? '127.0.0.1'); ?>"
                           placeholder="127.0.0.1">
                </div>
                
                <div class="yaa-form-row">
                    <label for="redis_port">Port</label>
                    <input type="number" id="redis_port" name="yaa_settings[redis_port]" 
                           value="<?php echo esc_attr($options['redis_port'] ?? '6379'); ?>"
                           min="1" max="65535">
                </div>
                
                <div class="yaa-form-row">
                    <label for="redis_password">Passwort (optional)</label>
                    <input type="password" id="redis_password" name="yaa_settings[redis_password]" 
                           value="<?php echo esc_attr($options['redis_password'] ?? ''); ?>"
                           placeholder="Leer lassen wenn kein Passwort">
                </div>
                
                <div class="yaa-form-row">
                    <label for="redis_database">Datenbank (0-15)</label>
                    <input type="number" id="redis_database" name="yaa_settings[redis_database]" 
                           value="<?php echo esc_attr($options['redis_database'] ?? '0'); ?>" 
                           min="0" max="15">
                </div>
            </div>
            
            <div class="yaa-form-row">
                <button type="button" class="button yaa-test-btn" data-action="yaa_test_redis">
                    Redis-Verbindung testen
                </button>
                <div class="yaa-test-result"></div>
            </div>
            
            <div class="yaa-marketplace-info" style="margin-top: 20px;">
                <h4>💡 wp-config.php Konfiguration</h4>
                <p>Redis kann auch via wp-config.php konfiguriert werden (hat Priorität):</p>
                <pre style="background:#fff;padding:10px;border-radius:4px;overflow-x:auto;font-size:12px;">
define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_PASSWORD', ''); // optional
define('WP_REDIS_DATABASE', 0);  // optional</pre>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render display settings
     * 
     * @param array<string, mixed> $options
     */
    private function render_display_settings(array $options): void {
        ?>
        <div class="yaa-card">
            <h2>Grid-Layout</h2>
            <p class="description">Konfiguriere die Anzahl der Spalten für verschiedene Bildschirmgrößen.</p>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="grid_columns_desktop">Spalten Desktop (>1024px)</label>
                    <select id="grid_columns_desktop" name="yaa_settings[grid_columns_desktop]">
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected(($options['grid_columns_desktop'] ?? 3), $i); ?>>
                                <?php echo $i; ?> Spalte<?php echo $i > 1 ? 'n' : ''; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="yaa-form-row">
                    <label for="grid_columns_tablet">Spalten Tablet (601-1024px)</label>
                    <select id="grid_columns_tablet" name="yaa_settings[grid_columns_tablet]">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected(($options['grid_columns_tablet'] ?? 2), $i); ?>>
                                <?php echo $i; ?> Spalte<?php echo $i > 1 ? 'n' : ''; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="yaa-form-row">
                    <label for="grid_columns_mobile">Spalten Mobile (≤600px)</label>
                    <select id="grid_columns_mobile" name="yaa_settings[grid_columns_mobile]">
                        <?php for ($i = 1; $i <= 2; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected(($options['grid_columns_mobile'] ?? 1), $i); ?>>
                                <?php echo $i; ?> Spalte<?php echo $i > 1 ? 'n' : ''; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>Farben</h2>
            <p class="description">Passe die Farben an dein Theme an.</p>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="color_primary">Primärfarbe (Yadore)</label>
                    <input type="text" id="color_primary" name="yaa_settings[color_primary]" 
                           value="<?php echo esc_attr($options['color_primary'] ?? '#ff00cc'); ?>"
                           class="yaa-color-picker">
                    <p class="description">Für Buttons, Akzente bei Yadore-Produkten</p>
                </div>
                
                <div class="yaa-form-row">
                    <label for="color_secondary">Sekundärfarbe</label>
                    <input type="text" id="color_secondary" name="yaa_settings[color_secondary]" 
                           value="<?php echo esc_attr($options['color_secondary'] ?? '#00ffff'); ?>"
                           class="yaa-color-picker">
                    <p class="description">Für Hover-Effekte, alternierende Elemente</p>
                </div>
                
                <div class="yaa-form-row">
                    <label for="color_amazon">Amazon-Farbe</label>
                    <input type="text" id="color_amazon" name="yaa_settings[color_amazon]" 
                           value="<?php echo esc_attr($options['color_amazon'] ?? '#ff9900'); ?>"
                           class="yaa-color-picker">
                    <p class="description">Für Amazon-Produkte und Prime-Badge</p>
                </div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>Anzeige-Optionen</h2>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[show_prime_badge]" value="yes" 
                        <?php checked(($options['show_prime_badge'] ?? 'yes'), 'yes'); ?>>
                    Prime-Badge bei Amazon-Produkten anzeigen
                </label>
            </div>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[show_merchant]" value="yes" 
                        <?php checked(($options['show_merchant'] ?? 'yes'), 'yes'); ?>>
                    Händler-Namen anzeigen
                </label>
            </div>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[show_description]" value="yes" 
                        <?php checked(($options['show_description'] ?? 'yes'), 'yes'); ?>>
                    Produktbeschreibung anzeigen
                </label>
            </div>
            
            <hr style="margin: 20px 0;">
            
            <h3>Button-Texte</h3>
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="button_text_yadore">Button-Text (Yadore)</label>
                    <input type="text" id="button_text_yadore" name="yaa_settings[button_text_yadore]" 
                           value="<?php echo esc_attr($options['button_text_yadore'] ?? 'Zum Angebot'); ?>">
                </div>
                
                <div class="yaa-form-row">
                    <label for="button_text_amazon">Button-Text (Amazon)</label>
                    <input type="text" id="button_text_amazon" name="yaa_settings[button_text_amazon]" 
                           value="<?php echo esc_attr($options['button_text_amazon'] ?? 'Bei Amazon kaufen'); ?>">
                </div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>🎨 Vorschau</h2>
            <p>Die Änderungen werden nach dem Speichern auf allen Seiten mit Shortcodes sichtbar.</p>
            <div style="display: flex; gap: 20px; margin-top: 15px;">
                <div style="padding: 20px; background: #1a1a1a; border-radius: 8px; text-align: center;">
                    <div style="width: 100px; height: 100px; background: linear-gradient(135deg, <?php echo esc_attr($options['color_primary'] ?? '#ff00cc'); ?>, <?php echo esc_attr($options['color_secondary'] ?? '#00ffff'); ?>); border-radius: 8px; margin: 0 auto 10px;"></div>
                    <small style="color: #999;">Farbverlauf</small>
                </div>
                <div style="padding: 20px; background: #1a1a1a; border-radius: 8px; text-align: center;">
                    <button type="button" style="background: transparent; border: 2px solid <?php echo esc_attr($options['color_primary'] ?? '#ff00cc'); ?>; color: <?php echo esc_attr($options['color_primary'] ?? '#ff00cc'); ?>; padding: 10px 20px; border-radius: 25px; cursor: pointer;">
                        <?php echo esc_html($options['button_text_yadore'] ?? 'Zum Angebot'); ?>
                    </button>
                    <br><small style="color: #999; display: block; margin-top: 10px;">Yadore Button</small>
                </div>
                <div style="padding: 20px; background: #1a1a1a; border-radius: 8px; text-align: center;">
                    <button type="button" style="background: transparent; border: 2px solid <?php echo esc_attr($options['color_amazon'] ?? '#ff9900'); ?>; color: <?php echo esc_attr($options['color_amazon'] ?? '#ff9900'); ?>; padding: 10px 20px; border-radius: 25px; cursor: pointer;">
                        <?php echo esc_html($options['button_text_amazon'] ?? 'Bei Amazon kaufen'); ?>
                    </button>
                    <br><small style="color: #999; display: block; margin-top: 10px;">Amazon Button</small>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render status page
     */
    public function render_status_page(): void {
        global $wpdb;
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $cache_status = $this->cache->get_status();
        $cached_keywords = get_option('yaa_cached_keywords', []);
        
        if (!is_array($cached_keywords)) {
            $cached_keywords = [];
        }
        
        $next_cron = wp_next_scheduled('yaa_cache_refresh_event');
        
        // Count cache entries
        $cache_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_yaa_%' AND option_name NOT LIKE '_transient_timeout_%'"
        );
        
        ?>
        <div class="wrap yaa-admin-wrap">
            <h1><?php esc_html_e('Cache & Status', 'yadore-amazon-api'); ?></h1>
            
            <div class="yaa-grid">
                <div class="yaa-card">
                    <h2>📊 Cache-Status</h2>
                    <table class="widefat striped">
                        <tr>
                            <td><strong>Cache-Backend:</strong></td>
                            <td>
                                <span class="yaa-status-badge <?php echo $cache_status['redis_available'] ? 'yaa-status-success' : 'yaa-status-warning'; ?>">
                                    <?php echo esc_html($cache_status['cache_backend']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Cache-Einträge (Transients):</strong></td>
                            <td><?php echo $cache_count; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Getrackte Keywords:</strong></td>
                            <td><?php echo count($cached_keywords); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Nächster Cron-Lauf:</strong></td>
                            <td>
                                <?php 
                                if ($next_cron) {
                                    echo esc_html(date_i18n('d.m.Y H:i:s', $next_cron));
                                    $diff = $next_cron - time();
                                    if ($diff > 0) {
                                        $hours = floor($diff / 3600);
                                        $mins = floor(($diff % 3600) / 60);
                                        echo ' <small style="color:#666;">(in ' . $hours . 'h ' . $mins . 'm)</small>';
                                    }
                                } else {
                                    echo '<span class="yaa-status-badge yaa-status-error">Nicht geplant</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Cache-Dauer:</strong></td>
                            <td><?php echo (int) yaa_get_option('cache_duration', 6); ?> Stunden</td>
                        </tr>
                        <tr>
                            <td><strong>Fallback-Dauer:</strong></td>
                            <td><?php echo (int) yaa_get_option('fallback_duration', 24); ?> Stunden</td>
                        </tr>
                    </table>
                    
                    <p style="margin-top: 15px;">
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=yaa_clear_cache'), 'yaa_clear_cache_nonce')); ?>" 
                           class="button button-primary">
                            🗑️ Gesamten Cache leeren
                        </a>
                    </p>
                </div>
                
                <div class="yaa-card">
                    <h2>🔌 API-Status</h2>
                    <table class="widefat striped">
                        <tr>
                            <td><strong>Yadore API:</strong></td>
                            <td>
                                <?php if ($this->yadore_api->is_configured()): ?>
                                    <span class="yaa-status-badge yaa-status-success">✅ Konfiguriert & Aktiv</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-error">❌ Nicht konfiguriert</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Yadore Markt:</strong></td>
                            <td><?php echo esc_html(strtoupper((string) yaa_get_option('yadore_market', 'de'))); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Amazon PA-API:</strong></td>
                            <td>
                                <?php if ($this->amazon_api->is_configured()): ?>
                                    <span class="yaa-status-badge yaa-status-success">✅ Konfiguriert & Aktiv</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-error">❌ Nicht konfiguriert</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($this->amazon_api->is_configured()): ?>
                        <tr>
                            <td><strong>Amazon Partner-Tag:</strong></td>
                            <td><code><?php echo esc_html($this->amazon_api->get_partner_tag()); ?></code></td>
                        </tr>
                        <tr>
                            <td><strong>Amazon Marketplace:</strong></td>
                            <td>
                                <?php 
                                $mp = $this->amazon_api->get_current_marketplace();
                                echo $mp ? esc_html($mp['name']) : '-';
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Redis:</strong></td>
                            <td>
                                <?php if ($cache_status['redis_available']): ?>
                                    <span class="yaa-status-badge yaa-status-success">✅ Verbunden</span>
                                    <?php if (!empty($cache_status['redis_info'])): ?>
                                        <br><small>Version: <?php echo esc_html($cache_status['redis_info']['version']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-warning">⚠️ Nicht verfügbar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <?php if (!empty($cached_keywords)): ?>
            <div class="yaa-card">
                <h2>📝 Getrackte Keywords (für Cron-Preload)</h2>
                <p class="description">Diese Keywords werden automatisch beim nächsten Cron-Lauf vorgeladen.</p>
                <table class="widefat striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>Keyword</th>
                            <th>Quelle</th>
                            <th>Limit</th>
                            <th>Markt/Kategorie</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($cached_keywords) as $entry): ?>
                        <tr>
                            <td><code><?php echo esc_html($entry['keyword'] ?? '-'); ?></code></td>
                            <td>
                                <?php $source = $entry['source'] ?? 'yadore'; ?>
                                <?php if ($source === 'amazon'): ?>
                                    <span class="yaa-status-badge" style="background:#ff9900;color:#000;">Amazon</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge" style="background:#4CAF50;color:#fff;">Yadore</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int) ($entry['limit'] ?? 9); ?></td>
                            <td>
                                <?php 
                                if ($source === 'amazon') {
                                    echo esc_html($entry['category'] ?? 'All');
                                    if (!empty($entry['marketplace'])) {
                                        echo ' <small>(' . esc_html($entry['marketplace']) . ')</small>';
                                    }
                                } else {
                                    echo esc_html(strtoupper($entry['market'] ?? 'de'));
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="yaa-card">
                <h2>🛠️ System-Informationen</h2>
                <table class="widefat striped">
                    <tr>
                        <td><strong>PHP Version:</strong></td>
                        <td>
                            <?php echo esc_html(PHP_VERSION); ?>
                            <?php if (version_compare(PHP_VERSION, '8.1', '<')): ?>
                                <span class="yaa-status-badge yaa-status-error">Minimum 8.1 erforderlich!</span>
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-success">✓</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>WordPress Version:</strong></td>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Plugin Version:</strong></td>
                        <td><?php echo esc_html(YAA_VERSION); ?></td>
                    </tr>
                    <tr>
                        <td><strong>PHP Redis Extension:</strong></td>
                        <td>
                            <?php if (extension_loaded('redis')): ?>
                                <span class="yaa-status-badge yaa-status-success">✅ Installiert</span>
                                <?php 
                                if (class_exists('Redis')) {
                                    $redis = new \Redis();
                                    echo ' <small>(Version: ' . phpversion('redis') . ')</small>';
                                }
                                ?>
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-warning">Nicht installiert</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>cURL Extension:</strong></td>
                        <td>
                            <?php if (function_exists('curl_version')): ?>
                                <span class="yaa-status-badge yaa-status-success">✅ Installiert</span>
                                <?php $curl = curl_version(); ?>
                                <small>(<?php echo esc_html($curl['version']); ?>)</small>
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-error">Nicht installiert</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>JSON Extension:</strong></td>
                        <td>
                            <?php if (function_exists('json_encode')): ?>
                                <span class="yaa-status-badge yaa-status-success">✅ Installiert</span>
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-error">Nicht installiert</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>OpenSSL Extension:</strong></td>
                        <td>
                            <?php if (extension_loaded('openssl')): ?>
                                <span class="yaa-status-badge yaa-status-success">✅ Installiert</span>
                                <small>(<?php echo esc_html(OPENSSL_VERSION_TEXT); ?>)</small>
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-error">Nicht installiert (benötigt für Amazon API)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render documentation page
     */
    public function render_docs_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $search_indexes = $this->amazon_api->get_search_indexes();
        ?>
        <div class="wrap yaa-admin-wrap">
            <h1><?php esc_html_e('Dokumentation', 'yadore-amazon-api'); ?></h1>
            
            <div class="yaa-card">
                <h2>📝 Shortcodes</h2>
                
                <h3>Yadore Produkte</h3>
                <div class="yaa-shortcode-box">[yadore_products keyword="Smartphone" limit="9" market="de"]</div>
                <table class="widefat" style="margin-bottom: 20px;">
                    <thead>
                        <tr><th>Parameter</th><th>Beschreibung</th><th>Standard</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>keyword</code></td><td>Suchbegriff (erforderlich)</td><td>-</td></tr>
                        <tr><td><code>limit</code></td><td>Anzahl der Produkte (1-50)</td><td><?php echo (int) yaa_get_option('yadore_default_limit', 9); ?></td></tr>
                        <tr><td><code>market</code></td><td>Markt: de, at, ch, fr, it, es, nl, uk, us...</td><td><?php echo esc_html(yaa_get_option('yadore_market', 'de')); ?></td></tr>
                        <tr><td><code>precision</code></td><td><code>fuzzy</code> (mehr) oder <code>strict</code> (genauer)</td><td><?php echo esc_html(yaa_get_option('yadore_precision', 'fuzzy')); ?></td></tr>
                        <tr><td><code>columns</code></td><td>Spalten überschreiben (1-6)</td><td>-</td></tr>
                        <tr><td><code>class</code></td><td>Zusätzliche CSS-Klasse</td><td>-</td></tr>
                    </tbody>
                </table>
                
                <h3>Amazon Produkte</h3>
                <div class="yaa-shortcode-box">[amazon_products keyword="Laptop" category="Computers" limit="10"]</div>
                <table class="widefat" style="margin-bottom: 20px;">
                    <thead>
                        <tr><th>Parameter</th><th>Beschreibung</th><th>Standard</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>keyword</code></td><td>Suchbegriff</td><td>-</td></tr>
                        <tr><td><code>asins</code></td><td>Komma-getrennte ASINs (alternativ zu keyword)</td><td>-</td></tr>
                        <tr><td><code>category</code></td><td>Amazon-Kategorie (SearchIndex)</td><td><?php echo esc_html(yaa_get_option('amazon_default_category', 'All')); ?></td></tr>
                        <tr><td><code>limit</code></td><td>Anzahl (max. 10 pro Anfrage)</td><td>10</td></tr>
                        <tr><td><code>min_price</code></td><td>Mindestpreis in lokaler Währung</td><td>-</td></tr>
                        <tr><td><code>max_price</code></td><td>Maximalpreis in lokaler Währung</td><td>-</td></tr>
                        <tr><td><code>brand</code></td><td>Nach Marke filtern</td><td>-</td></tr>
                        <tr><td><code>sort</code></td><td>Sortierung: Price:LowToHigh, Price:HighToLow, etc.</td><td>-</td></tr>
                    </tbody>
                </table>
                
                <h3>Kombinierte Produkte (Yadore + Amazon)</h3>
                <div class="yaa-shortcode-box">[combined_products keyword="Kopfhörer" yadore_limit="6" amazon_limit="4" shuffle="yes"]</div>
                <table class="widefat">
                    <thead>
                        <tr><th>Parameter</th><th>Beschreibung</th><th>Standard</th></tr>
                    </thead>
                    <tbody>
                        <tr><td><code>keyword</code></td><td>Suchbegriff für beide APIs</td><td>-</td></tr>
                        <tr><td><code>yadore_limit</code></td><td>Anzahl Yadore-Produkte</td><td>6</td></tr>
                        <tr><td><code>amazon_limit</code></td><td>Anzahl Amazon-Produkte</td><td>4</td></tr>
                        <tr><td><code>shuffle</code></td><td><code>yes</code>/<code>no</code> - Produkte mischen</td><td>yes</td></tr>
                        <tr><td><code>market</code></td><td>Yadore-Markt</td><td>de</td></tr>
                        <tr><td><code>category</code></td><td>Amazon-Kategorie</td><td>All</td></tr>
                    </tbody>
                </table>
            </div>
            
            <div class="yaa-card">
                <h2>⚙️ wp-config.php Konstanten</h2>
                <p>API-Schlüssel können auch sicher in der wp-config.php definiert werden (empfohlen für Produktivsysteme):</p>
                <pre style="background:#f5f5f5; padding:15px; overflow:auto; border-radius:4px;">
&lt;?php
// Yadore API
define('YADORE_API_KEY', 'dein-api-key-hier');

// Amazon PA-API 5.0
define('AMAZON_PAAPI_ACCESS_KEY', 'AKIAXXXXXXXXXX');
define('AMAZON_PAAPI_SECRET_KEY', 'dein-secret-key-hier');
define('AMAZON_PAAPI_PARTNER_TAG', 'deinshop-21');

// Redis (optional - überschreibt Backend-Einstellungen)
define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_PASSWORD', '');
define('WP_REDIS_DATABASE', 0);
                </pre>
            </div>
            
            <div class="yaa-card">
                <h2>📚 Amazon Kategorien (SearchIndex) für <?php echo esc_html(strtoupper((string) yaa_get_option('amazon_marketplace', 'de'))); ?></h2>
                <p class="description">Diese Kategorien können im <code>category</code> Parameter verwendet werden:</p>
                <div style="column-count: 3; column-gap: 20px; margin-top: 15px;">
                    <?php foreach ($search_indexes as $code => $name): ?>
                        <div style="margin-bottom: 5px;">
                            <code style="background:#e0e0e0;padding:2px 6px;border-radius:3px;"><?php echo esc_html($code); ?></code>
                            <?php if ($code !== $name): ?>
                                <small style="color:#666;"> – <?php echo esc_html($name); ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="yaa-card">
                <h2>🌍 Verfügbare Amazon Marketplaces</h2>
                <p class="description">Der Partner-Tag muss zum jeweiligen Marketplace passen!</p>
                <table class="widefat striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Host</th>
                            <th>Währung</th>
                            <th>Region</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->amazon_api->get_marketplaces() as $code => $info): ?>
                        <tr>
                            <td><code><?php echo esc_html($code); ?></code></td>
                            <td><?php echo esc_html($info['name']); ?></td>
                            <td><small><?php echo esc_html($info['host']); ?></small></td>
                            <td><?php echo esc_html($info['currency']); ?></td>
                            <td><?php echo esc_html($info['region']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="yaa-card">
                <h2>🔗 Nützliche Links</h2>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><a href="https://www.yadore.com/publisher" target="_blank" rel="noopener">Yadore Publisher Portal</a> – Account & API-Key erstellen</li>
                    <li><a href="https://webservices.amazon.com/paapi5/documentation/" target="_blank" rel="noopener">Amazon PA-API 5.0 Dokumentation</a></li>
                    <li><a href="https://webservices.amazon.com/paapi5/documentation/locale-reference.html" target="_blank" rel="noopener">Amazon Locale Reference</a> – Alle Marketplaces</li>
                    <li><a href="https://affiliate-program.amazon.de/" target="_blank" rel="noopener">Amazon PartnerNet (DE)</a></li>
                    <li><a href="https://affiliate-program.amazon.com/" target="_blank" rel="noopener">Amazon Associates (US)</a></li>
                    <li><a href="https://webservices.amazon.com/paapi5/scratchpad/" target="_blank" rel="noopener">Amazon API Scratchpad</a> – API testen</li>
                </ul>
            </div>
            
            <div class="yaa-card">
                <h2>❓ FAQ & Troubleshooting</h2>
                
                <h4>Amazon API gibt "TooManyRequests" Fehler</h4>
                <p>Die Amazon PA-API hat strikte Rate-Limits. Bei wenigen Verkäufen ist nur 1 Request/Sekunde erlaubt. Das Plugin cached automatisch, aber bei vielen gleichzeitigen Besuchern kann das Limit erreicht werden.</p>
                
                <h4>Amazon API gibt "InvalidPartnerTag" Fehler</h4>
                <p>Der Partner-Tag muss zum gewählten Marketplace passen. Ein deutscher Tag (xxxxx-21) funktioniert nur mit amazon.de.</p>
                
                <h4>Keine Ergebnisse von Amazon</h4>
                <p>Mindestens 1 qualifizierter Verkauf in den letzten 30 Tagen ist erforderlich. Neue Associates haben oft noch keinen API-Zugang.</p>
                
                <h4>Redis funktioniert nicht</h4>
                <p>Prüfe ob die PHP Redis-Extension installiert ist: <code>php -m | grep redis</code>. Alternativ funktioniert auch die Predis-Library.</p>
                
                <h4>Cache wird nicht geleert</h4>
                <p>Bei Redis Object Cache muss ggf. auch der Object Cache geleert werden (z.B. über Redis Object Cache Plugin).</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Add admin bar button
     */
    public function add_admin_bar_button(\WP_Admin_Bar $admin_bar): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $admin_bar->add_menu([
            'id'    => 'yaa-clear-cache',
            'title' => '🗑️ YAA Cache leeren',
            'href'  => wp_nonce_url(admin_url('admin-post.php?action=yaa_clear_cache'), 'yaa_clear_cache_nonce'),
            'meta'  => [
                'title' => __('Yadore-Amazon-API Cache leeren', 'yadore-amazon-api'),
            ],
        ]);
    }
    
    /**
     * Handle clear cache action
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
        
        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=yaa-status');
        }
        
        wp_safe_redirect($redirect);
        exit;
    }
    
    /**
     * AJAX: Test Yadore connection
     */
    public function ajax_test_yadore(): void {
        check_ajax_referer('yaa_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung', 'yadore-amazon-api')]);
        }
        
        $api_key = sanitize_text_field($_POST['yadore_api_key'] ?? '');
        $result = $this->yadore_api->test_connection($api_key !== '' ? $api_key : null);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Test Amazon connection
     */
    public function ajax_test_amazon(): void {
        check_ajax_referer('yaa_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung', 'yadore-amazon-api')]);
        }
        
        $access_key = sanitize_text_field($_POST['amazon_access_key'] ?? '');
        $secret_key = $_POST['amazon_secret_key'] ?? '';
        $partner_tag = sanitize_text_field($_POST['amazon_partner_tag'] ?? '');
        $marketplace = sanitize_text_field($_POST['amazon_marketplace'] ?? 'de');
        
        $result = $this->amazon_api->test_connection(
            $access_key !== '' ? $access_key : null,
            $secret_key !== '' ? $secret_key : null,
            $partner_tag !== '' ? $partner_tag : null,
            $marketplace
        );
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Test Redis connection
     */
    public function ajax_test_redis(): void {
        check_ajax_referer('yaa_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung', 'yadore-amazon-api')]);
        }
        
        $host = sanitize_text_field($_POST['redis_host'] ?? '127.0.0.1');
        $port = (int) ($_POST['redis_port'] ?? 6379);
        $password = $_POST['redis_password'] ?? '';
        $database = (int) ($_POST['redis_database'] ?? 0);
        
        $result = $this->cache->test_connection($host, $port, $password, $database);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Admin notices
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
     * Dashboard widget
     */
    public function add_dashboard_widget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        wp_add_dashboard_widget(
            'yaa_status_widget',
            '📦 Yadore-Amazon-API Status',
            [$this, 'render_dashboard_widget']
        );
    }
    
    /**
     * Dashboard widget content
     */
    public function render_dashboard_widget(): void {
        $cache_status = $this->cache->get_status();
        $next_cron = wp_next_scheduled('yaa_cache_refresh_event');
        
        echo '<table class="widefat striped" style="border:none;">';
        echo '<tr><td>Yadore API:</td><td>' . ($this->yadore_api->is_configured() ? '✅ Aktiv' : '❌ Nicht konfiguriert') . '</td></tr>';
        echo '<tr><td>Amazon PA-API:</td><td>' . ($this->amazon_api->is_configured() ? '✅ Aktiv' : '❌ Nicht konfiguriert') . '</td></tr>';
        echo '<tr><td>Cache-Backend:</td><td>' . esc_html($cache_status['cache_backend']) . '</td></tr>';
        echo '<tr><td>Nächster Cron:</td><td>' . ($next_cron ? esc_html(date_i18n('d.m.Y H:i', $next_cron)) : 'Nicht geplant') . '</td></tr>';
        echo '</table>';
        
        echo '<p style="margin-top:15px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=yaa-settings')) . '" class="button">⚙️ Einstellungen</a> ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=yaa-status')) . '" class="button">📊 Status</a> ';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=yaa_clear_cache'), 'yaa_clear_cache_nonce')) . '" class="button">🗑️ Cache</a>';
        echo '</p>';
    }
}        
