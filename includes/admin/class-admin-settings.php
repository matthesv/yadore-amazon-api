<?php
/**
 * Admin Settings Page
 * Rendert alle Einstellungs-Tabs und verarbeitet Sanitization
 * PHP 8.3+ compatible
 * Version: 1.4.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin_Settings {
    
    private YAA_Yadore_API $yadore_api;
    private YAA_Amazon_PAAPI $amazon_api;
    private YAA_Cache_Handler $cache;
    
    public function __construct(
        YAA_Yadore_API $yadore_api,
        YAA_Amazon_PAAPI $amazon_api,
        YAA_Cache_Handler $cache
    ) {
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        $this->cache = $cache;
    }
    
    /**
     * Render main settings page
     */
    public function render_page(): void {
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
                    <div class="yaa-tab" data-tab="custom">⭐ Eigene Produkte</div>
                    <div class="yaa-tab" data-tab="cache">⚡ Cache & Bilder</div>
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
                
                <!-- Custom Products Tab -->
                <div id="yaa-tab-custom" class="yaa-tab-content">
                    <?php $this->render_custom_products_settings($options); ?>
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
        
        // Merchant Filter
        $sanitized['yadore_merchant_whitelist'] = $this->sanitize_merchant_list($input['yadore_merchant_whitelist'] ?? '');
        $sanitized['yadore_merchant_blacklist'] = $this->sanitize_merchant_list($input['yadore_merchant_blacklist'] ?? '');
        
        // Amazon settings
        $sanitized['enable_amazon'] = isset($input['enable_amazon']) ? 'yes' : 'no';
        $sanitized['amazon_access_key'] = sanitize_text_field($input['amazon_access_key'] ?? '');
        $sanitized['amazon_secret_key'] = $input['amazon_secret_key'] ?? '';
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
        
        // Local Image Storage
        $sanitized['enable_local_images'] = isset($input['enable_local_images']) ? 'yes' : 'no';
        
        // Bildgröße und SEO-Dateinamen
        $sanitized['preferred_image_size'] = in_array($input['preferred_image_size'] ?? '', ['Large', 'Medium', 'Small'], true) 
            ? $input['preferred_image_size'] : 'Large';
        $sanitized['image_filename_format'] = in_array($input['image_filename_format'] ?? '', ['seo', 'id'], true) 
            ? $input['image_filename_format'] : 'seo';
        $sanitized['image_resize_enabled'] = isset($input['image_resize_enabled']) ? 'yes' : 'no';
        
        // Fuzzy Search settings
        $sanitized['enable_fuzzy_search'] = isset($input['enable_fuzzy_search']) ? 'yes' : 'no';
        $sanitized['fuzzy_auto_mix'] = isset($input['fuzzy_auto_mix']) ? 'yes' : 'no';
        $sanitized['fuzzy_threshold'] = max(0, min(100, (int) ($input['fuzzy_threshold'] ?? 30)));
        $sanitized['fuzzy_weight_title'] = max(0, min(1, (float) ($input['fuzzy_weight_title'] ?? 0.40)));
        $sanitized['fuzzy_weight_description'] = max(0, min(1, (float) ($input['fuzzy_weight_description'] ?? 0.25)));
        $sanitized['fuzzy_weight_category'] = max(0, min(1, (float) ($input['fuzzy_weight_category'] ?? 0.20)));
        $sanitized['fuzzy_weight_merchant'] = max(0, min(1, (float) ($input['fuzzy_weight_merchant'] ?? 0.10)));
        $sanitized['fuzzy_weight_keywords'] = max(0, min(1, (float) ($input['fuzzy_weight_keywords'] ?? 0.05)));
        
        // Display settings
        $sanitized['disable_default_css'] = isset($input['disable_default_css']) ? 'yes' : 'no';
        $sanitized['grid_columns_desktop'] = max(1, min(6, (int) ($input['grid_columns_desktop'] ?? 3)));
        $sanitized['grid_columns_tablet'] = max(1, min(4, (int) ($input['grid_columns_tablet'] ?? 2)));
        $sanitized['grid_columns_mobile'] = max(1, min(2, (int) ($input['grid_columns_mobile'] ?? 1)));
        $sanitized['button_text_yadore'] = sanitize_text_field($input['button_text_yadore'] ?? 'Zum Angebot');
        $sanitized['button_text_amazon'] = sanitize_text_field($input['button_text_amazon'] ?? 'Bei Amazon kaufen');
        $sanitized['button_text_custom'] = sanitize_text_field($input['button_text_custom'] ?? 'Zum Angebot');
        $sanitized['show_prime_badge'] = isset($input['show_prime_badge']) ? 'yes' : 'no';
        $sanitized['show_merchant'] = isset($input['show_merchant']) ? 'yes' : 'no';
        $sanitized['show_description'] = isset($input['show_description']) ? 'yes' : 'no';
        $sanitized['show_custom_badge'] = isset($input['show_custom_badge']) ? 'yes' : 'no';
        $sanitized['custom_badge_text'] = sanitize_text_field($input['custom_badge_text'] ?? 'Empfohlen');
        $sanitized['color_primary'] = sanitize_hex_color($input['color_primary'] ?? '#ff00cc') ?: '#ff00cc';
        $sanitized['color_secondary'] = sanitize_hex_color($input['color_secondary'] ?? '#00ffff') ?: '#00ffff';
        $sanitized['color_amazon'] = sanitize_hex_color($input['color_amazon'] ?? '#ff9900') ?: '#ff9900';
        $sanitized['color_custom'] = sanitize_hex_color($input['color_custom'] ?? '#4CAF50') ?: '#4CAF50';
        
        // GitHub Token
        $sanitized['github_token'] = sanitize_text_field($input['github_token'] ?? '');
        
        return $sanitized;
    }
    
    /**
     * Sanitize merchant list (comma-separated)
     */
    private function sanitize_merchant_list(string $input): string {
        $list = array_map('trim', explode(',', $input));
        $list = array_filter($list, fn($item) => $item !== '');
        return implode(', ', $list);
    }
    
    /**
     * Render Yadore settings - MIT Merchant Filter
     *
     * @param array<string, mixed> $options
     * @param array<string, string> $markets
     */
    private function render_yadore_settings(array $options, array $markets): void {
        $current_market = $options['yadore_market'] ?? 'de';
        $merchants = class_exists('YAA_Merchant_Filter') ? YAA_Merchant_Filter::get_stored_merchants($current_market) : [];
        $last_update = class_exists('YAA_Merchant_Filter') ? YAA_Merchant_Filter::get_last_update($current_market) : null;
        
        $whitelist = $options['yadore_merchant_whitelist'] ?? '';
        $blacklist = $options['yadore_merchant_blacklist'] ?? '';
        ?>
        <div class="yaa-card">
            <h2>📦 Yadore API Konfiguration</h2>
            <p class="description">
                Yadore bietet Zugang zu über 9.000 Shops und 250 Millionen Produkten. 
                <a href="https://www.yadore.com/publisher" target="_blank" rel="noopener">Publisher-Account erstellen →</a>
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
                       <?php echo defined('YADORE_API_KEY') ? 'disabled' : ''; ?>
                       placeholder="Dein Yadore API-Key">
                <?php if (defined('YADORE_API_KEY')): ?>
                    <p class="description">✅ Via <code>wp-config.php</code> definiert (YADORE_API_KEY)</p>
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
                                <?php echo esc_html($name . ' (' . strtoupper($code) . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Kann im Shortcode mit <code>market="xx"</code> überschrieben werden.</p>
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
                    🔍 Verbindung testen
                </button>
                <div class="yaa-test-result"></div>
            </div>
        </div>
        
        <!-- Merchant Filter -->
        <div class="yaa-card">
            <h2>🏪 Händler-Filter (Whitelist / Blacklist)</h2>
            <p class="description">
                Filtere Produkte nach Händlern. <strong>Whitelist hat immer Vorrang</strong> vor der Blacklist.
                Das Matching erfolgt auf dem Händlernamen (case-insensitive, partial match).
            </p>
            
            <div class="yaa-feature-box info" style="margin-bottom: 20px;">
                <h4 style="margin-top: 0;">📋 Händlerliste</h4>
                <p>
                    <strong><?php echo count($merchants); ?></strong> Händler verfügbar
                    <?php if ($last_update): ?>
                        (zuletzt aktualisiert: <?php echo esc_html(date_i18n('d.m.Y H:i', $last_update)); ?>)
                    <?php endif; ?>
                </p>
                <p>
                    <button type="button" class="button" id="yaa-refresh-merchants">
                        🔄 Händlerliste aktualisieren
                    </button>
                    <span id="yaa-merchant-refresh-status" style="margin-left: 10px;"></span>
                </p>
                <p style="margin-top: 10px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=yaa-merchants')); ?>" class="button button-secondary">
                        📊 Händlerübersicht mit CPC öffnen
                    </a>
                </p>
            </div>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="yadore_merchant_whitelist">
                        ✅ Whitelist (nur diese Händler anzeigen)
                    </label>
                    <?php if (!empty($merchants)): ?>
                        <select name="yaa_settings_whitelist_select[]" multiple class="yaa-merchant-select">
                            <?php 
                            $whitelist_array = array_map('trim', explode(',', $whitelist));
                            foreach ($merchants as $merchant): 
                                $is_selected = in_array($merchant['name'], $whitelist_array, true);
                            ?>
                                <option value="<?php echo esc_attr($merchant['name']); ?>" <?php selected($is_selected); ?>>
                                    <?php echo esc_html($merchant['name']); ?>
                                    <?php if (!empty($merchant['offerCount'])): ?>
                                        (<?php echo number_format($merchant['offerCount'], 0, ',', '.'); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input type="text" id="yadore_merchant_whitelist" 
                           name="yaa_settings[yadore_merchant_whitelist]" 
                           value="<?php echo esc_attr($whitelist); ?>"
                           placeholder="amazon, otto, mediamarkt"
                           style="margin-top: 10px;">
                    <p class="description">
                        Komma-separierte Liste. Wenn gesetzt, werden <strong>NUR</strong> Produkte dieser Händler angezeigt.
                    </p>
                </div>
                
                <div class="yaa-form-row">
                    <label for="yadore_merchant_blacklist">
                        ❌ Blacklist (diese Händler ausschließen)
                    </label>
                    <?php if (!empty($merchants)): ?>
                        <select name="yaa_settings_blacklist_select[]" multiple class="yaa-merchant-select">
                            <?php 
                            $blacklist_array = array_map('trim', explode(',', $blacklist));
                            foreach ($merchants as $merchant): 
                                $is_selected = in_array($merchant['name'], $blacklist_array, true);
                            ?>
                                <option value="<?php echo esc_attr($merchant['name']); ?>" <?php selected($is_selected); ?>>
                                    <?php echo esc_html($merchant['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input type="text" id="yadore_merchant_blacklist" 
                           name="yaa_settings[yadore_merchant_blacklist]" 
                           value="<?php echo esc_attr($blacklist); ?>"
                           placeholder="aliexpress, wish"
                           style="margin-top: 10px;">
                    <p class="description">
                        Komma-separierte Liste. Produkte dieser Händler werden ausgeblendet (wenn keine Whitelist aktiv).
                    </p>
                </div>
            </div>
            
            <div class="yaa-tip" style="margin-top: 20px;">
                <strong>⚠️ Whitelist hat Vorrang:</strong> Wenn die Whitelist gesetzt ist, wird die Blacklist ignoriert.
            </div>
            
            <div class="yaa-feature-box" style="margin-top: 20px;">
                <h4 style="margin-top: 0;">📝 Shortcode-Override</h4>
                <p>Du kannst den Filter pro Shortcode überschreiben:</p>
                <div class="yaa-shortcode-box">[yadore_products keyword="Laptop" merchant_whitelist="amazon,otto"]</div>
                <div class="yaa-shortcode-box">[yadore_products keyword="Handy" merchant_blacklist="aliexpress,wish"]</div>
                <div class="yaa-shortcode-box">[yadore_products keyword="Tablet" merchants="mediamarkt,saturn"]</div>
                <p class="description" style="margin-top: 10px;">
                    <code>merchant_whitelist</code> / <code>merchants</code> = Whitelist<br>
                    <code>merchant_blacklist</code> / <code>exclude_merchants</code> = Blacklist
                </p>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>💡 Multi-Keyword Feature</h2>
            <p class="description">
                Du kannst mehrere Keywords in einem Shortcode verwenden. Das Limit wird automatisch aufgeteilt.
            </p>
            <div class="yaa-shortcode-box">[yadore_products keywords="Smartphone,Tablet,Laptop" limit="9"]</div>
            <div class="yaa-tip">
                <strong>Tipp:</strong> Bei 3 Keywords und limit="9" werden je 3 Produkte pro Keyword geladen.
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
            <h2>🛒 Amazon Product Advertising API 5.0</h2>
            <p class="description">
                Für die Amazon PA-API benötigst du ein Amazon Associates Konto mit mindestens 10 qualifizierten Verkäufen in 30 Tagen.
                <a href="https://webservices.amazon.com/paapi5/documentation/" target="_blank" rel="noopener">Dokumentation →</a>
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
                       <?php echo defined('AMAZON_PAAPI_ACCESS_KEY') ? 'disabled' : ''; ?>
                       placeholder="AKIAXXXXXXXXXX">
                <?php if (defined('AMAZON_PAAPI_ACCESS_KEY')): ?>
                    <p class="description">✅ Via <code>wp-config.php</code> definiert</p>
                <?php endif; ?>
            </div>
            
            <div class="yaa-form-row">
                <label for="amazon_secret_key">Secret Key</label>
                <input type="password" id="amazon_secret_key" name="yaa_settings[amazon_secret_key]" 
                       value="<?php echo esc_attr($options['amazon_secret_key'] ?? ''); ?>"
                       <?php echo defined('AMAZON_PAAPI_SECRET_KEY') ? 'disabled' : ''; ?>>
                <?php if (defined('AMAZON_PAAPI_SECRET_KEY')): ?>
                    <p class="description">✅ Via <code>wp-config.php</code> definiert</p>
                <?php endif; ?>
            </div>
            
            <div class="yaa-form-row">
                <label for="amazon_partner_tag">Partner Tag (Tracking-ID)</label>
                <input type="text" id="amazon_partner_tag" name="yaa_settings[amazon_partner_tag]" 
                       value="<?php echo esc_attr($options['amazon_partner_tag'] ?? ''); ?>"
                       placeholder="deinshop-21"
                       <?php echo defined('AMAZON_PAAPI_PARTNER_TAG') ? 'disabled' : ''; ?>>
                <p class="description">
                    Deine Tracking-ID aus dem Amazon PartnerNet. <strong>Muss zum Marketplace passen!</strong>
                </p>
            </div>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="amazon_marketplace">Marketplace</label>
                    <select id="amazon_marketplace" name="yaa_settings[amazon_marketplace]">
                        <optgroup label="🇪🇺 Europa">
                            <?php foreach (['de', 'fr', 'it', 'es', 'co.uk', 'nl', 'pl', 'se', 'be', 'com.tr'] as $code): ?>
                                <?php if (isset($marketplaces[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($current_marketplace, $code); ?>>
                                    <?php echo esc_html($marketplaces[$code]); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="🌍 Naher Osten & Afrika">
                            <?php foreach (['ae', 'sa', 'eg'] as $code): ?>
                                <?php if (isset($marketplaces[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($current_marketplace, $code); ?>>
                                    <?php echo esc_html($marketplaces[$code]); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="🌎 Amerika">
                            <?php foreach (['com', 'ca', 'com.mx', 'com.br'] as $code): ?>
                                <?php if (isset($marketplaces[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($current_marketplace, $code); ?>>
                                    <?php echo esc_html($marketplaces[$code]); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="🌏 Asien-Pazifik">
                            <?php foreach (['co.jp', 'in', 'com.au', 'sg'] as $code): ?>
                                <?php if (isset($marketplaces[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($current_marketplace, $code); ?>>
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
                            <option value="<?php echo esc_attr($code); ?>" <?php selected(($options['amazon_default_category'] ?? 'All'), $code); ?>>
                                <?php echo esc_html($code === $name ? $code : "$code - $name"); ?>
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
                        <tr><td width="150"><strong>Host:</strong></td><td><code><?php echo esc_html($info['host']); ?></code></td></tr>
                        <tr><td><strong>Region:</strong></td><td><?php echo esc_html($info['region']); ?></td></tr>
                        <tr><td><strong>Währung:</strong></td><td><?php echo esc_html($info['currency']); ?></td></tr>
                        <tr><td><strong>Sprache:</strong></td><td><?php echo esc_html($info['language']); ?></td></tr>
                    </table>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="yaa-form-row" style="margin-top: 20px;">
                <button type="button" class="button yaa-test-btn" data-action="yaa_test_amazon">
                    🔍 Verbindung testen
                </button>
                <div class="yaa-test-result"></div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>⚠️ Amazon API Einschränkungen</h2>
            <ul style="list-style: disc; margin-left: 20px; line-height: 1.8;">
                <li><strong>10 qualifizierte Verkäufe</strong> in den letzten 30 Tagen erforderlich (seit Nov. 2025)</li>
                <li>Maximal <strong>1 Request/Sekunde</strong> bei wenigen Verkäufen</li>
                <li>Maximal <strong>10 Produkte</strong> pro API-Anfrage</li>
                <li>Partner-Tag <strong>muss zum Marketplace</strong> passen</li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * Render custom products settings
     *
     * @param array<string, mixed> $options
     */
    private function render_custom_products_settings(array $options): void {
        $product_count = wp_count_posts(YAA_Custom_Products::get_post_type());
        $published_count = $product_count->publish ?? 0;
        
        $categories = get_terms([
            'taxonomy' => YAA_Custom_Products::get_taxonomy(),
            'hide_empty' => false,
        ]);
        $category_count = is_array($categories) ? count($categories) : 0;
        ?>
        <div class="yaa-card">
            <h2>⭐ Eigene Produkte</h2>
            <p class="description">
                Erstelle eigene Produkteinträge mit individuellen Links, Bildern und Preisen.
            </p>
            
            <div class="yaa-grid" style="margin: 20px 0;">
                <div class="yaa-stat-box" style="background: #f0f6fc;">
                    <div class="yaa-stat-number" style="color: #0073aa;"><?php echo (int) $published_count; ?></div>
                    <div class="yaa-stat-label">Veröffentlichte Produkte</div>
                </div>
                <div class="yaa-stat-box" style="background: #fef8ee;">
                    <div class="yaa-stat-number" style="color: #996800;"><?php echo (int) $category_count; ?></div>
                    <div class="yaa-stat-label">Kategorien</div>
                </div>
                <div class="yaa-stat-box">
                    <a href="<?php echo admin_url('post-new.php?post_type=' . YAA_Custom_Products::get_post_type()); ?>" 
                       class="button button-primary button-hero" style="margin-top: 10px;">
                        ➕ Neues Produkt
                    </a>
                </div>
            </div>
            
            <p>
                <a href="<?php echo admin_url('edit.php?post_type=' . YAA_Custom_Products::get_post_type()); ?>" class="button">
                    📋 Alle Produkte verwalten
                </a>
                <a href="<?php echo admin_url('edit-tags.php?taxonomy=' . YAA_Custom_Products::get_taxonomy() . '&post_type=' . YAA_Custom_Products::get_post_type()); ?>" class="button" style="margin-left: 10px;">
                    🏷️ Kategorien verwalten
                </a>
            </p>
        </div>
        
        <div class="yaa-card">
            <h2>🔍 Fuzzy-Suche Einstellungen</h2>
            <p class="description">
                Die Fuzzy-Suche findet eigene Produkte basierend auf ähnlichen Keywords, Titeln und Kategorien.
            </p>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[enable_fuzzy_search]" value="yes" 
                        <?php checked(($options['enable_fuzzy_search'] ?? 'yes'), 'yes'); ?>>
                    <strong>Fuzzy-Suche aktivieren</strong>
                </label>
                <p class="description">
                    Ermöglicht die Nutzung des <code>[fuzzy_products]</code> Shortcodes und der
                    <code>keyword=""</code> Suche bei eigenen Produkten.
                </p>
            </div>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[fuzzy_auto_mix]" value="yes" 
                        <?php checked(($options['fuzzy_auto_mix'] ?? 'no'), 'yes'); ?>>
                    <strong>Automatisch eigene Produkte einmischen</strong>
                </label>
                <p class="description">
                    Mischt automatisch passende eigene Produkte in Yadore/Amazon Suchergebnisse ein.
                    Kann auch per Shortcode mit <code>mix_custom="yes"</code> aktiviert werden.
                </p>
            </div>
            
            <div class="yaa-form-row">
                <label for="fuzzy_threshold">Mindest-Übereinstimmung (%)</label>
                <input type="number" id="fuzzy_threshold" name="yaa_settings[fuzzy_threshold]" 
                       value="<?php echo esc_attr($options['fuzzy_threshold'] ?? '30'); ?>"
                       min="0" max="100" step="5" style="max-width: 100px;">
                <p class="description">
                    Produkte mit niedrigerem Score werden nicht angezeigt. Empfohlen: 25-40%
                </p>
            </div>
            
            <h4>Gewichtungen</h4>
            <p class="description" style="margin-bottom: 15px;">Die Summe sollte ~1.0 (100%) ergeben.</p>
            
            <div class="yaa-grid-4">
                <div class="yaa-form-row">
                    <label for="fuzzy_weight_title">Titel</label>
                    <input type="number" id="fuzzy_weight_title" name="yaa_settings[fuzzy_weight_title]" 
                           value="<?php echo esc_attr($options['fuzzy_weight_title'] ?? '0.40'); ?>"
                           min="0" max="1" step="0.05" style="max-width: 80px;">
                </div>
                
                <div class="yaa-form-row">
                    <label for="fuzzy_weight_description">Beschreibung</label>
                    <input type="number" id="fuzzy_weight_description" name="yaa_settings[fuzzy_weight_description]" 
                           value="<?php echo esc_attr($options['fuzzy_weight_description'] ?? '0.25'); ?>"
                           min="0" max="1" step="0.05" style="max-width: 80px;">
                </div>
                
                <div class="yaa-form-row">
                    <label for="fuzzy_weight_category">Kategorie</label>
                    <input type="number" id="fuzzy_weight_category" name="yaa_settings[fuzzy_weight_category]" 
                           value="<?php echo esc_attr($options['fuzzy_weight_category'] ?? '0.20'); ?>"
                           min="0" max="1" step="0.05" style="max-width: 80px;">
                </div>
                
                <div class="yaa-form-row">
                    <label for="fuzzy_weight_keywords">Keywords</label>
                    <input type="number" id="fuzzy_weight_keywords" name="yaa_settings[fuzzy_weight_keywords]" 
                           value="<?php echo esc_attr($options['fuzzy_weight_keywords'] ?? '0.05'); ?>"
                           min="0" max="1" step="0.05" style="max-width: 80px;">
                </div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>📝 Shortcodes für eigene Produkte</h2>
            
            <h4>Nach IDs anzeigen:</h4>
            <div class="yaa-shortcode-box">[custom_products ids="123,456,789"]</div>
            
            <h4>Nach Kategorie anzeigen:</h4>
            <div class="yaa-shortcode-box">[custom_products category="elektronik" limit="6"]</div>
            
            <h4>Fuzzy-Suche:</h4>
            <div class="yaa-shortcode-box">[fuzzy_products keyword="Kopfhörer" limit="6"]</div>
            <div class="yaa-shortcode-box">[custom_products keyword="Smartphone" fuzzy="yes"]</div>
            
            <h4>Yadore/Amazon mit eigenen Produkten mischen:</h4>
            <div class="yaa-shortcode-box">[yadore_products keyword="Tablet" mix_custom="yes" custom_limit="2"]</div>
            <div class="yaa-shortcode-box">[amazon_products keyword="Monitor" mix_custom="yes" custom_position="alternate"]</div>
            
            <div class="yaa-tip">
                <strong>Tipp:</strong> Füge bei eigenen Produkten zusätzliche "Fuzzy-Keywords" hinzu, 
                um die Trefferquote zu verbessern. Diese findest du im Produkt-Bearbeitungsformular.
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>🎨 Darstellung eigener Produkte</h2>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[show_custom_badge]" value="yes" 
                        <?php checked(($options['show_custom_badge'] ?? 'yes'), 'yes'); ?>>
                    Badge bei eigenen Produkten anzeigen
                </label>
            </div>
            
            <div class="yaa-form-row">
                <label for="custom_badge_text">Badge-Text</label>
                <input type="text" id="custom_badge_text" name="yaa_settings[custom_badge_text]" 
                       value="<?php echo esc_attr($options['custom_badge_text'] ?? 'Empfohlen'); ?>"
                       placeholder="Empfohlen">
            </div>
            
            <div class="yaa-form-row">
                <label for="button_text_custom">Button-Text</label>
                <input type="text" id="button_text_custom" name="yaa_settings[button_text_custom]" 
                       value="<?php echo esc_attr($options['button_text_custom'] ?? 'Zum Angebot'); ?>">
            </div>
            
            <div class="yaa-form-row">
                <label for="color_custom">Farbe für eigene Produkte</label>
                <input type="text" id="color_custom" name="yaa_settings[color_custom]" 
                       value="<?php echo esc_attr($options['color_custom'] ?? '#4CAF50'); ?>"
                       class="yaa-color-picker">
            </div>
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
        $image_stats = $this->get_local_image_stats();
        $can_resize = class_exists('YAA_Image_Handler') && YAA_Image_Handler::can_resize_images();
        $image_library = class_exists('YAA_Image_Handler') ? YAA_Image_Handler::get_image_library() : 'Unknown';
        ?>
        <div class="yaa-card">
            <h2>⚡ Cache-Einstellungen</h2>
            
            <div class="yaa-grid">
                <div>
                    <div class="yaa-form-row">
                        <label for="cache_duration">Cache-Dauer (Stunden)</label>
                        <input type="number" id="cache_duration" name="yaa_settings[cache_duration]" 
                               value="<?php echo esc_attr($options['cache_duration'] ?? '6'); ?>"
                               min="1" max="168">
                        <p class="description">Wie lange API-Daten im Cache bleiben (1-168 Stunden).</p>
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
                    <h4 style="margin-top: 0;">📊 Aktueller Status</h4>
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
                            <td><strong>Speicher:</strong></td>
                            <td><?php echo esc_html($cache_status['redis_info']['used_memory']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>🖼️ Lokale Bilderspeicherung</h2>
            <p class="description">
                Produktbilder können lokal gespeichert werden, um die Ladezeit zu verbessern und externe Anfragen zu reduzieren.
            </p>
            
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" name="yaa_settings[enable_local_images]" value="yes" 
                        <?php checked(($options['enable_local_images'] ?? 'yes'), 'yes'); ?>>
                    <strong>Produktbilder lokal speichern</strong>
                </label>
                <p class="description">
                    Lädt Produktbilder von Amazon/Yadore herunter und speichert sie unter 
                    <code>/wp-content/uploads/yadore-amazon-api/</code>.
                </p>
            </div>
            
            <!-- Bildgrößen-Einstellung -->
            <div class="yaa-feature-box info" style="margin-top: 20px;">
                <h4 style="margin-top: 0;">🖼️ Bevorzugte Bildgröße</h4>
                <p class="description" style="margin-bottom: 15px;">
                    Diese Einstellung gilt für <strong>alle Quellen</strong> (Amazon, Yadore, eigene Produkte).
                </p>
                
                <div class="yaa-form-row">
                    <label for="preferred_image_size">Bildgröße auswählen</label>
                    <select id="preferred_image_size" name="yaa_settings[preferred_image_size]">
                        <option value="Large" <?php selected(($options['preferred_image_size'] ?? 'Large'), 'Large'); ?>>
                            🖼️ Large (max. 500px) – Beste Qualität
                        </option>
                        <option value="Medium" <?php selected(($options['preferred_image_size'] ?? 'Large'), 'Medium'); ?>>
                            🖼️ Medium (max. 160px) – Mittelgroß
                        </option>
                        <option value="Small" <?php selected(($options['preferred_image_size'] ?? 'Large'), 'Small'); ?>>
                            🖼️ Small (max. 75px) – Thumbnail
                        </option>
                    </select>
                </div>
                
                <div class="yaa-form-row">
                    <label>
                        <input type="checkbox" name="yaa_settings[image_resize_enabled]" value="yes" 
                            <?php checked(($options['image_resize_enabled'] ?? 'yes'), 'yes'); ?>
                            <?php echo !$can_resize ? 'disabled' : ''; ?>>
                        <strong>Bilder beim Speichern skalieren</strong>
                    </label>
                    <p class="description">
                        Skaliert heruntergeladene Bilder auf die gewählte Maximalgröße.
                    </p>
                    <?php if (!$can_resize): ?>
                        <p class="description" style="color: #dc3232;">
                            ⚠️ Bildverarbeitung nicht verfügbar. Installieren Sie GD oder Imagick.
                        </p>
                    <?php else: ?>
                        <p class="description">
                            ✅ Bildverarbeitung: <strong><?php echo esc_html($image_library); ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- SEO-Dateinamen -->
            <div class="yaa-form-row" style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-radius: 4px;">
                <label for="image_filename_format"><strong>📝 Dateiname-Format</strong></label>
                <select id="image_filename_format" name="yaa_settings[image_filename_format]" style="margin-top: 8px;">
                    <option value="seo" <?php selected(($options['image_filename_format'] ?? 'seo'), 'seo'); ?>>
                        🔍 SEO-optimiert (Produktname + Timestamp)
                    </option>
                    <option value="id" <?php selected(($options['image_filename_format'] ?? 'seo'), 'id'); ?>>
                        🔢 Technisch (Produkt-ID)
                    </option>
                </select>
                <p class="description" style="margin-top: 8px;">
                    <strong>SEO-optimiert:</strong> Die ersten 30 Zeichen des Produktnamens + Timestamp.
                </p>
                <div class="yaa-seo-example">
                    <strong>Beispiel SEO:</strong> samsung-galaxy-s24-ultra-smart_1734686220.jpg<br>
                    <strong>Beispiel ID:</strong> amazon_B0CXYZ12345.jpg
                </div>
            </div>
            
            <div class="yaa-grid" style="margin-top: 20px;">
                <div class="yaa-stat-box" style="background: #f0f6fc;">
                    <div class="yaa-stat-number" style="color: #0073aa;"><?php echo (int) $image_stats['count']; ?></div>
                    <div class="yaa-stat-label">Gespeicherte Bilder</div>
                </div>
                <div class="yaa-stat-box" style="background: #fef8ee;">
                    <div class="yaa-stat-number" style="color: #996800;"><?php echo esc_html($image_stats['size']); ?></div>
                    <div class="yaa-stat-label">Speicherverbrauch</div>
                </div>
                <div class="yaa-stat-box">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=yaa_clear_images'), 'yaa_clear_images_nonce')); ?>" 
                       class="button" 
                       onclick="return confirm('Alle lokal gespeicherten Bilder löschen?');">
                        🗑️ Bilder löschen
                    </a>
                </div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>🔴 Redis-Konfiguration</h2>
            <p class="description">
                Redis bietet schnelleres Caching als WordPress-Transients.
            </p>
            
            <div class="yaa-form-row">
                <label for="enable_redis">Redis verwenden</label>
                <select id="enable_redis" name="yaa_settings[enable_redis]">
                    <option value="auto" <?php selected(($options['enable_redis'] ?? 'auto'), 'auto'); ?>>
                        🔄 Automatisch erkennen
                    </option>
                    <option value="yes" <?php selected(($options['enable_redis'] ?? 'auto'), 'yes'); ?>>
                        ✅ Ja, Redis verwenden
                    </option>
                    <option value="no" <?php selected(($options['enable_redis'] ?? 'auto'), 'no'); ?>>
                        ❌ Nein, nur Transients
                    </option>
                </select>
            </div>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="redis_host">Host</label>
                    <input type="text" id="redis_host" name="yaa_settings[redis_host]" 
                           value="<?php echo esc_attr($options['redis_host'] ?? '127.0.0.1'); ?>">
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
                           value="<?php echo esc_attr($options['redis_password'] ?? ''); ?>">
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
                    🔍 Redis-Verbindung testen
                </button>
                <div class="yaa-test-result"></div>
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
            <h2>🎨 Styling Optionen</h2>
            
            <div class="yaa-form-row" style="background: #fff0f0; padding: 15px; border-left: 4px solid #ff4444;">
                <label>
                    <input type="checkbox" name="yaa_settings[disable_default_css]" value="yes" 
                        <?php checked(($options['disable_default_css'] ?? 'no'), 'yes'); ?>>
                    <strong>Standard-CSS komplett deaktivieren</strong>
                </label>
                <p class="description">
                    Aktivieren Sie diese Option, wenn Sie das Grid-Layout <strong>vollständig über Ihr Theme oder Custom CSS</strong> steuern möchten.
                </p>
            </div>
        </div>

        <div class="yaa-card">
            <h2>📐 Grid-Layout</h2>
            <p class="description">Diese Einstellungen werden nur angewendet, wenn das Standard-CSS aktiv ist.</p>
            
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
            <h2>🎨 Farben</h2>
            
            <div class="yaa-grid">
                <div class="yaa-form-row">
                    <label for="color_primary">Primärfarbe (Yadore)</label>
                    <input type="text" id="color_primary" name="yaa_settings[color_primary]" 
                           value="<?php echo esc_attr($options['color_primary'] ?? '#ff00cc'); ?>"
                           class="yaa-color-picker">
                </div>
                
                <div class="yaa-form-row">
                    <label for="color_secondary">Sekundärfarbe</label>
                    <input type="text" id="color_secondary" name="yaa_settings[color_secondary]" 
                           value="<?php echo esc_attr($options['color_secondary'] ?? '#00ffff'); ?>"
                           class="yaa-color-picker">
                </div>
                
                <div class="yaa-form-row">
                    <label for="color_amazon">Amazon-Farbe</label>
                    <input type="text" id="color_amazon" name="yaa_settings[color_amazon]" 
                           value="<?php echo esc_attr($options['color_amazon'] ?? '#ff9900'); ?>"
                           class="yaa-color-picker">
                </div>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>⚙️ Anzeige-Optionen</h2>
            
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
        <?php
    }
    
    /**
     * Get local image statistics
     * 
     * @return array{count: int, size: string}
     */
    private function get_local_image_stats(): array {
        $upload_dir = wp_upload_dir();
        $image_dir = $upload_dir['basedir'] . '/yadore-amazon-api';
        
        $count = 0;
        $total_size = 0;
        
        if (is_dir($image_dir)) {
            $files = glob($image_dir . '/*');
            if ($files !== false) {
                $count = count($files);
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $total_size += filesize($file);
                    }
                }
            }
        }
        
        if ($total_size < 1024) {
            $size = $total_size . ' B';
        } elseif ($total_size < 1024 * 1024) {
            $size = round($total_size / 1024, 1) . ' KB';
        } else {
            $size = round($total_size / (1024 * 1024), 2) . ' MB';
        }
        
        return ['count' => $count, 'size' => $size];
    }
}
