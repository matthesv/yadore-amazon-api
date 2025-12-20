<?php
/**
 * Admin Revenue Calculator Page
 * Berechnet den geschätzten Revenue basierend auf Shortcode-Parametern
 * PHP 8.3+ compatible
 * Version: 1.4.0
 * 
 * Nutzt die /v2/offer API, die CPC pro Produkt/Angebot liefert
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin_Revenue {
    
    private YAA_Yadore_API $yadore_api;
    private YAA_Cache_Handler $cache;
    
    public function __construct(YAA_Yadore_API $yadore_api, YAA_Cache_Handler $cache) {
        $this->yadore_api = $yadore_api;
        $this->cache = $cache;
    }
    
    /**
     * Render der Revenue-Kalkulator Seite
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $markets = $this->yadore_api->get_markets();
        $default_market = yaa_get_option('yadore_market', 'de');
        $default_limit = (int) yaa_get_option('yadore_default_limit', 9);
        
        ?>
        <div class="wrap yaa-admin-wrap">
            <h1>💰 <?php esc_html_e('Revenue-Kalkulator', 'yadore-amazon-api'); ?></h1>
            
            <?php if (!$this->yadore_api->is_configured()): ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e('Yadore API ist nicht konfiguriert. Bitte API-Key in den Einstellungen hinterlegen.', 'yadore-amazon-api'); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>
            
            <!-- Info-Box -->
            <div class="yaa-feature-box info" style="margin-bottom: 20px;">
                <h4 style="margin-top: 0;">💡 <?php esc_html_e('So funktioniert der Revenue-Kalkulator', 'yadore-amazon-api'); ?></h4>
                <p>
                    <?php esc_html_e('Dieser Kalkulator simuliert einen Shortcode-Aufruf und zeigt die CPC-Werte der gefundenen Produkte.', 'yadore-amazon-api'); ?>
                </p>
                <ul style="margin: 10px 0 0 20px;">
                    <li><strong><?php esc_html_e('Brutto-CPC:', 'yadore-amazon-api'); ?></strong> <?php esc_html_e('Der CPC, den Yadore vom Händler erhält', 'yadore-amazon-api'); ?></li>
                    <li><strong><?php esc_html_e('Dein CPC:', 'yadore-amazon-api'); ?></strong> <?php esc_html_e('Brutto-CPC × Dein Revenue-Share', 'yadore-amazon-api'); ?></li>
                    <li><strong><?php esc_html_e('Quelle:', 'yadore-amazon-api'); ?></strong> <code>/v2/offer</code> API - <?php esc_html_e('CPC wird pro Produkt/Angebot berechnet', 'yadore-amazon-api'); ?></li>
                </ul>
            </div>
            
            <!-- Eingabe-Formular -->
            <div class="yaa-card">
                <h2>🔍 <?php esc_html_e('Shortcode-Parameter eingeben', 'yadore-amazon-api'); ?></h2>
                
                <div class="yaa-revenue-form">
                    <div class="yaa-grid">
                        <div class="yaa-form-row">
                            <label for="yaa-revenue-keyword">
                                <strong><?php esc_html_e('Keyword', 'yadore-amazon-api'); ?></strong> <span style="color: red;">*</span>
                            </label>
                            <input type="text" id="yaa-revenue-keyword" 
                                   placeholder="<?php esc_attr_e('z.B. Smartphone, Laptop, Kopfhörer', 'yadore-amazon-api'); ?>"
                                   style="width: 100%; max-width: 400px;">
                            <p class="description"><?php esc_html_e('Der Suchbegriff für die Produktsuche.', 'yadore-amazon-api'); ?></p>
                        </div>
                        
                        <div class="yaa-form-row">
                            <label for="yaa-revenue-market">
                                <strong><?php esc_html_e('Markt', 'yadore-amazon-api'); ?></strong>
                            </label>
                            <select id="yaa-revenue-market" style="width: 100%; max-width: 200px;">
                                <?php foreach ($markets as $code => $name): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($default_market, $code); ?>>
                                        <?php echo esc_html($name . ' (' . strtoupper($code) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="yaa-form-row">
                            <label for="yaa-revenue-limit">
                                <strong><?php esc_html_e('Limit', 'yadore-amazon-api'); ?></strong>
                            </label>
                            <input type="number" id="yaa-revenue-limit" 
                                   value="<?php echo esc_attr($default_limit); ?>"
                                   min="1" max="100"
                                   style="width: 100px;">
                            <p class="description"><?php esc_html_e('Anzahl der Produkte (1-100).', 'yadore-amazon-api'); ?></p>
                        </div>
                        
                        <div class="yaa-form-row">
                            <label for="yaa-revenue-share">
                                <strong><?php esc_html_e('Dein Revenue-Share (%)', 'yadore-amazon-api'); ?></strong>
                            </label>
                            <input type="number" id="yaa-revenue-share" 
                                   value="70"
                                   min="0" max="100" step="1"
                                   style="width: 100px;">
                            <p class="description"><?php esc_html_e('Dein Anteil am CPC (frag deinen Account Manager).', 'yadore-amazon-api'); ?></p>
                        </div>
                    </div>
                    
                    <div class="yaa-form-row" style="margin-top: 20px;">
                        <button type="button" class="button button-primary button-hero" id="yaa-fetch-revenue">
                            🔍 <?php esc_html_e('Produkte abrufen & CPC berechnen', 'yadore-amazon-api'); ?>
                        </button>
                        <span id="yaa-revenue-status" style="margin-left: 15px;"></span>
                    </div>
                </div>
            </div>
            
            <!-- Ergebnis-Bereich -->
            <div class="yaa-card" id="yaa-revenue-results" style="display: none;">
                <h2>📊 <?php esc_html_e('Ergebnisse', 'yadore-amazon-api'); ?></h2>
                
                <!-- Statistik-Karten -->
                <div class="yaa-grid-4" id="yaa-revenue-stats" style="margin-bottom: 20px;">
                    <!-- Wird per JavaScript gefüllt -->
                </div>
                
                <!-- Shortcode-Vorschau -->
                <div class="yaa-feature-box" style="margin-bottom: 20px;">
                    <h4 style="margin-top: 0;">📝 <?php esc_html_e('Entsprechender Shortcode', 'yadore-amazon-api'); ?></h4>
                    <div class="yaa-shortcode-box" id="yaa-generated-shortcode"></div>
                </div>
                
                <!-- Produkt-Tabelle -->
                <div id="yaa-revenue-table-container">
                    <!-- Wird per JavaScript gefüllt -->
                </div>
            </div>
            
            <!-- Keine Ergebnisse -->
            <div class="yaa-card" id="yaa-revenue-no-results" style="display: none;">
                <p>😕 <?php esc_html_e('Keine Produkte für diese Suche gefunden.', 'yadore-amazon-api'); ?></p>
            </div>
        </div>
        
        <style>
            .yaa-revenue-form { max-width: 800px; }
            .yaa-cpc-cell { font-family: monospace; }
            .yaa-cpc-positive { color: #155724; background: #d4edda; }
            .yaa-cpc-zero { color: #856404; background: #fff3cd; }
            .yaa-product-image { width: 50px; height: 50px; object-fit: contain; border-radius: 4px; }
            .yaa-revenue-table { width: 100%; border-collapse: collapse; }
            .yaa-revenue-table th { background: #f6f7f7; text-align: left; padding: 12px; border-bottom: 2px solid #ccd0d4; }
            .yaa-revenue-table td { padding: 10px 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
            .yaa-revenue-table tr:hover { background: #f9f9f9; }
            .yaa-revenue-table .yaa-cpc-badge { 
                display: inline-block; 
                padding: 4px 8px; 
                border-radius: 4px; 
                font-weight: 600; 
                font-size: 12px;
                font-family: monospace;
            }
        </style>
        <?php
    }
    
    /**
     * JavaScript für die Seite
     */
    public function get_page_js(): string {
        $no_keyword_text = esc_js(__('Bitte ein Keyword eingeben.', 'yadore-amazon-api'));
        $loading_text = esc_js(__('Lade Produkte...', 'yadore-amazon-api'));
        $error_text = esc_js(__('Fehler beim Laden', 'yadore-amazon-api'));
        $products_found_text = esc_js(__('Produkte gefunden', 'yadore-amazon-api'));
        $avg_cpc_text = esc_js(__('Ø Brutto-CPC', 'yadore-amazon-api'));
        $your_avg_cpc_text = esc_js(__('Ø Dein CPC', 'yadore-amazon-api'));
        $with_cpc_text = esc_js(__('Mit CPC-Daten', 'yadore-amazon-api'));
        $total_potential_text = esc_js(__('Potenzial (alle Klicks)', 'yadore-amazon-api'));
        
        return <<<JS
jQuery(document).ready(function($) {
    
    $("#yaa-fetch-revenue").on("click", function() {
        var keyword = $("#yaa-revenue-keyword").val().trim();
        var market = $("#yaa-revenue-market").val();
        var limit = parseInt($("#yaa-revenue-limit").val()) || 9;
        var revenueShare = parseFloat($("#yaa-revenue-share").val()) / 100 || 0.7;
        
        if (!keyword) {
            alert("{$no_keyword_text}");
            return;
        }
        
        var \$btn = $(this);
        var \$status = $("#yaa-revenue-status");
        var \$results = $("#yaa-revenue-results");
        var \$noResults = $("#yaa-revenue-no-results");
        
        \$btn.prop("disabled", true);
        \$status.html('<span style="color: blue;">⏳ {$loading_text}</span>');
        \$results.hide();
        \$noResults.hide();
        
        $.post(yaaAdmin.ajaxurl, {
            action: "yaa_fetch_offers_with_cpc",
            nonce: yaaAdmin.nonce,
            keyword: keyword,
            market: market,
            limit: limit
        }, function(response) {
            \$btn.prop("disabled", false);
            \$status.html("");
            
            if (response.success && response.data.offers && response.data.offers.length > 0) {
                var offers = response.data.offers;
                
                // Statistiken berechnen
                var totalCpc = 0;
                var withCpc = 0;
                
                offers.forEach(function(offer) {
                    var cpc = parseFloat(offer.cpc_amount) || 0;
                    if (cpc > 0) {
                        totalCpc += cpc;
                        withCpc++;
                    }
                });
                
                var avgCpc = withCpc > 0 ? totalCpc / withCpc : 0;
                var yourAvgCpc = avgCpc * revenueShare;
                var totalPotential = totalCpc * revenueShare;
                
                // Statistik-Karten
                $("#yaa-revenue-stats").html(
                    '<div class="yaa-stat-box" style="background: #f0f6fc;">' +
                        '<div class="yaa-stat-number" style="color: #2271b1;">' + offers.length + '</div>' +
                        '<div class="yaa-stat-label">{$products_found_text}</div>' +
                    '</div>' +
                    '<div class="yaa-stat-box" style="background: #d4edda;">' +
                        '<div class="yaa-stat-number" style="color: #155724;">' + withCpc + '</div>' +
                        '<div class="yaa-stat-label">{$with_cpc_text}</div>' +
                    '</div>' +
                    '<div class="yaa-stat-box" style="background: #fff3cd;">' +
                        '<div class="yaa-stat-number" style="color: #856404;">' + avgCpc.toFixed(4) + ' €</div>' +
                        '<div class="yaa-stat-label">{$avg_cpc_text}</div>' +
                    '</div>' +
                    '<div class="yaa-stat-box" style="background: #d4edda;">' +
                        '<div class="yaa-stat-number" style="color: #155724;">' + yourAvgCpc.toFixed(4) + ' €</div>' +
                        '<div class="yaa-stat-label">{$your_avg_cpc_text}</div>' +
                    '</div>'
                );
                
                // Shortcode generieren
                $("#yaa-generated-shortcode").text(
                    '[yadore_products keyword="' + keyword + '" market="' + market + '" limit="' + limit + '"]'
                );
                
                // Tabelle erstellen
                var tableHtml = '<table class="yaa-revenue-table widefat">' +
                    '<thead><tr>' +
                        '<th width="60">Bild</th>' +
                        '<th>Produkt</th>' +
                        '<th>Händler</th>' +
                        '<th>Preis</th>' +
                        '<th>Brutto-CPC</th>' +
                        '<th>Dein CPC (' + (revenueShare * 100) + '%)</th>' +
                    '</tr></thead><tbody>';
                
                offers.forEach(function(offer, index) {
                    var cpc = parseFloat(offer.cpc_amount) || 0;
                    var yourCpc = cpc * revenueShare;
                    var cpcClass = cpc > 0 ? 'yaa-cpc-positive' : 'yaa-cpc-zero';
                    
                    var imageHtml = offer.image_url 
                        ? '<img src="' + offer.image_url + '" class="yaa-product-image" alt="">'
                        : '<span style="font-size: 24px;">📦</span>';
                    
                    var priceDisplay = offer.price_display || (offer.price_amount ? offer.price_amount + ' ' + offer.price_currency : '—');
                    
                    tableHtml += '<tr>' +
                        '<td>' + imageHtml + '</td>' +
                        '<td><strong>' + (offer.title || '—').substring(0, 60) + (offer.title && offer.title.length > 60 ? '...' : '') + '</strong></td>' +
                        '<td>' + (offer.merchant_name || '—') + '</td>' +
                        '<td>' + priceDisplay + '</td>' +
                        '<td><span class="yaa-cpc-badge ' + cpcClass + '">' + cpc.toFixed(4) + ' €</span></td>' +
                        '<td><span class="yaa-cpc-badge ' + cpcClass + '">' + yourCpc.toFixed(4) + ' €</span></td>' +
                    '</tr>';
                });
                
                tableHtml += '</tbody></table>';
                
                // Summenzeile
                tableHtml += '<div style="margin-top: 15px; padding: 15px; background: #f0f6fc; border-radius: 4px;">' +
                    '<strong>{$total_potential_text}:</strong> ' +
                    'Wenn jedes Produkt 1× geklickt wird: <strong>' + totalPotential.toFixed(4) + ' €</strong> ' +
                    '(Brutto: ' + totalCpc.toFixed(4) + ' €)' +
                '</div>';
                
                $("#yaa-revenue-table-container").html(tableHtml);
                \$results.show();
                
            } else {
                \$noResults.show();
            }
            
        }).fail(function() {
            \$btn.prop("disabled", false);
            \$status.html('<span style="color: red;">❌ {$error_text}</span>');
        });
    });
    
    // Enter-Taste im Keyword-Feld
    $("#yaa-revenue-keyword").on("keypress", function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $("#yaa-fetch-revenue").click();
        }
    });
});
JS;
    }
}
