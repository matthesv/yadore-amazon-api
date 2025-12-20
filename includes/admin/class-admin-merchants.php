<?php
/**
 * Admin Merchants Page - Händlerübersicht mit CPC
 * PHP 8.3+ compatible
 * Version: 1.4.0
 * 
 * Zeigt alle Händler aus beiden Yadore APIs:
 * - /v2/merchant (Offer-Händler)
 * - /v2/deeplink/merchant (Deeplink-Händler mit CPC)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin_Merchants {
    
    private YAA_Yadore_API $yadore_api;
    private YAA_Cache_Handler $cache;
    
    /**
     * Items pro Seite
     */
    private const PER_PAGE = 50;
    
    public function __construct(YAA_Yadore_API $yadore_api, YAA_Cache_Handler $cache) {
        $this->yadore_api = $yadore_api;
        $this->cache = $cache;
    }
    
    /**
     * Render der Händlerübersicht
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $market = sanitize_text_field($_GET['market'] ?? yaa_get_option('yadore_market', 'de'));
        $view = sanitize_text_field($_GET['view'] ?? 'all');
        $search = sanitize_text_field($_GET['s'] ?? '');
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $sort = sanitize_text_field($_GET['sort'] ?? 'name');
        $order = strtoupper(sanitize_text_field($_GET['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        
        // Händler laden
        $all_merchants = $this->get_combined_merchants($market, $view);
        
        // Suche anwenden
        if ($search !== '') {
            $all_merchants = array_filter($all_merchants, function($m) use ($search) {
                return stripos($m['name'] ?? '', $search) !== false;
            });
            $all_merchants = array_values($all_merchants);
        }
        
        // Sortieren
        $all_merchants = $this->sort_merchants($all_merchants, $sort, $order);
        
        // Paginierung
        $total = count($all_merchants);
        $total_pages = (int) ceil($total / self::PER_PAGE);
        $offset = ($paged - 1) * self::PER_PAGE;
        $merchants = array_slice($all_merchants, $offset, self::PER_PAGE);
        
        // Statistiken
        $stats = $this->get_stats($all_merchants);
        
        ?>
        <div class="wrap yaa-admin-wrap">
            <h1>🏪 <?php esc_html_e('Händlerübersicht mit CPC', 'yadore-amazon-api'); ?></h1>
            
            <?php if (!$this->yadore_api->is_configured()): ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e('Yadore API ist nicht konfiguriert. Bitte API-Key in den Einstellungen hinterlegen.', 'yadore-amazon-api'); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>
            
            <!-- Statistik-Karten -->
            <div class="yaa-grid-4" style="margin-bottom: 20px;">
                <div class="yaa-stat-box">
                    <div class="yaa-stat-number" style="color: #2271b1;"><?php echo (int) $stats['total']; ?></div>
                    <div class="yaa-stat-label"><?php esc_html_e('Händler gesamt', 'yadore-amazon-api'); ?></div>
                </div>
                <div class="yaa-stat-box">
                    <div class="yaa-stat-number" style="color: #00a32a;"><?php echo (int) $stats['with_cpc']; ?></div>
                    <div class="yaa-stat-label"><?php esc_html_e('Mit CPC-Daten', 'yadore-amazon-api'); ?></div>
                </div>
                <div class="yaa-stat-box">
                    <div class="yaa-stat-number" style="color: #dba617;">
                        <?php echo $stats['avg_cpc'] > 0 ? number_format($stats['avg_cpc'], 4, ',', '.') . ' €' : '—'; ?>
                    </div>
                    <div class="yaa-stat-label"><?php esc_html_e('Ø CPC', 'yadore-amazon-api'); ?></div>
                </div>
                <div class="yaa-stat-box">
                    <div class="yaa-stat-number" style="color: #135e96;"><?php echo (int) $stats['smartlink']; ?></div>
                    <div class="yaa-stat-label"><?php esc_html_e('Smartlink-Händler', 'yadore-amazon-api'); ?></div>
                </div>
            </div>
            
            <!-- Filter-Leiste -->
            <div class="yaa-card" style="margin-bottom: 20px;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="yaa-merchants">
                    
                    <div class="yaa-filter-bar">
                        <label>
                            <strong><?php esc_html_e('Markt:', 'yadore-amazon-api'); ?></strong>
                            <select name="market" onchange="this.form.submit()">
                                <?php foreach ($this->yadore_api->get_markets() as $code => $name): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($market, $code); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        
                        <label>
                            <strong><?php esc_html_e('Ansicht:', 'yadore-amazon-api'); ?></strong>
                            <select name="view" onchange="this.form.submit()">
                                <option value="all" <?php selected($view, 'all'); ?>><?php esc_html_e('Alle Händler', 'yadore-amazon-api'); ?></option>
                                <option value="offer" <?php selected($view, 'offer'); ?>><?php esc_html_e('Nur Offer-Händler', 'yadore-amazon-api'); ?></option>
                                <option value="deeplink" <?php selected($view, 'deeplink'); ?>><?php esc_html_e('Nur Deeplink-Händler', 'yadore-amazon-api'); ?></option>
                                <option value="smartlink" <?php selected($view, 'smartlink'); ?>><?php esc_html_e('Nur Smartlink-Händler', 'yadore-amazon-api'); ?></option>
                                <option value="with_cpc" <?php selected($view, 'with_cpc'); ?>><?php esc_html_e('Nur mit CPC', 'yadore-amazon-api'); ?></option>
                            </select>
                        </label>
                        
                        <label>
                            <strong><?php esc_html_e('Suche:', 'yadore-amazon-api'); ?></strong>
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Händlername...', 'yadore-amazon-api'); ?>">
                        </label>
                        
                        <button type="submit" class="button"><?php esc_html_e('Filtern', 'yadore-amazon-api'); ?></button>
                        
                        <button type="button" class="button" id="yaa-refresh-all-merchants">
                            🔄 <?php esc_html_e('Daten aktualisieren', 'yadore-amazon-api'); ?>
                        </button>
                        
                        <span id="yaa-refresh-status" style="margin-left: 10px;"></span>
                    </div>
                </form>
            </div>
            
            <!-- Info-Box -->
            <div class="yaa-feature-box info" style="margin-bottom: 20px;">
                <h4 style="margin-top: 0;">💡 <?php esc_html_e('CPC-Informationen', 'yadore-amazon-api'); ?></h4>
                <p>
                    <?php esc_html_e('Der angezeigte CPC ist der Brutto-CPC, den Yadore vom Händler erhält. Dein tatsächlicher Verdienst hängt von deinem Revenue-Share ab.', 'yadore-amazon-api'); ?>
                    <br>
                    <strong><?php esc_html_e('Quellen:', 'yadore-amazon-api'); ?></strong>
                </p>
                <ul style="margin: 10px 0 0 20px;">
                    <li><strong><?php esc_html_e('Offer-Händler:', 'yadore-amazon-api'); ?></strong> <?php esc_html_e('CPC wird pro Produkt/Angebot berechnet', 'yadore-amazon-api'); ?></li>
                    <li><strong><?php esc_html_e('Deeplink-Händler:', 'yadore-amazon-api'); ?></strong> <?php esc_html_e('CPC ist direkt am Händler hinterlegt', 'yadore-amazon-api'); ?></li>
                </ul>
            </div>
            
            <!-- Händler-Tabelle -->
            <div class="yaa-card">
                <h2 style="margin-bottom: 15px;">
                    📋 <?php esc_html_e('Händlerliste', 'yadore-amazon-api'); ?>
                    <span style="font-size: 14px; font-weight: normal; color: #666;">
                        (<?php echo $total; ?> <?php esc_html_e('Ergebnisse', 'yadore-amazon-api'); ?>)
                    </span>
                </h2>
                
                <?php if (empty($merchants)): ?>
                    <p><?php esc_html_e('Keine Händler gefunden. Bitte Daten aktualisieren.', 'yadore-amazon-api'); ?></p>
                <?php else: ?>
                    <table class="yaa-merchant-table widefat">
                        <thead>
                            <tr>
                                <th width="50"><?php esc_html_e('Logo', 'yadore-amazon-api'); ?></th>
                                <th>
                                    <?php echo $this->sortable_header('name', __('Händler', 'yadore-amazon-api'), $sort, $order); ?>
                                </th>
                                <th>
                                    <?php echo $this->sortable_header('type', __('Typ', 'yadore-amazon-api'), $sort, $order); ?>
                                </th>
                                <th>
                                    <?php echo $this->sortable_header('cpc', __('CPC (Brutto)', 'yadore-amazon-api'), $sort, $order); ?>
                                </th>
                                <th>
                                    <?php echo $this->sortable_header('offers', __('Angebote', 'yadore-amazon-api'), $sort, $order); ?>
                                </th>
                                <th><?php esc_html_e('Features', 'yadore-amazon-api'); ?></th>
                                <th><?php esc_html_e('ID', 'yadore-amazon-api'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($merchants as $merchant): 
                                $cpc_amount = (float) ($merchant['cpc_amount'] ?? 0);
                                $cpc_class = $this->get_cpc_class($cpc_amount);
                            ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($merchant['logo']) && !empty($merchant['has_logo'])): ?>
                                            <img src="<?php echo esc_url($merchant['logo']); ?>" 
                                                 alt="<?php echo esc_attr($merchant['name']); ?>" 
                                                 class="yaa-merchant-logo">
                                        <?php else: ?>
                                            <span style="font-size: 24px;">🏪</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($merchant['name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo $this->render_type_badges($merchant); ?>
                                    </td>
                                    <td>
                                        <?php if ($cpc_amount > 0): ?>
                                            <span class="yaa-cpc-badge <?php echo esc_attr($cpc_class); ?>">
                                                <?php echo number_format($cpc_amount, 4, ',', '.'); ?> €
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $offers = (int) ($merchant['offer_count'] ?? 0);
                                        echo $offers > 0 ? number_format($offers, 0, ',', '.') : '—';
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo $this->render_feature_badges($merchant); ?>
                                    </td>
                                    <td>
                                        <code style="font-size: 10px;" title="<?php echo esc_attr($merchant['id'] ?? ''); ?>">
                                            <?php echo esc_html(substr($merchant['id'] ?? '', 0, 12) . '...'); ?>
                                        </code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Paginierung -->
                    <?php if ($total_pages > 1): ?>
                        <div class="yaa-pagination">
                            <?php
                            $base_url = add_query_arg([
                                'page' => 'yaa-merchants',
                                'market' => $market,
                                'view' => $view,
                                's' => $search,
                                'sort' => $sort,
                                'order' => $order,
                            ], admin_url('admin.php'));
                            
                            // Previous
                            if ($paged > 1) {
                                echo '<a href="' . esc_url(add_query_arg('paged', $paged - 1, $base_url)) . '">« ' . esc_html__('Zurück', 'yadore-amazon-api') . '</a>';
                            }
                            
                            // Page numbers
                            $range = 2;
                            for ($i = max(1, $paged - $range); $i <= min($total_pages, $paged + $range); $i++) {
                                if ($i === $paged) {
                                    echo '<span class="current">' . $i . '</span>';
                                } else {
                                    echo '<a href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '">' . $i . '</a>';
                                }
                            }
                            
                            // Next
                            if ($paged < $total_pages) {
                                echo '<a href="' . esc_url(add_query_arg('paged', $paged + 1, $base_url)) . '">' . esc_html__('Weiter', 'yadore-amazon-api') . ' »</a>';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- Export-Optionen -->
            <div class="yaa-card">
                <h2>📥 <?php esc_html_e('Export', 'yadore-amazon-api'); ?></h2>
                <p>
                    <button type="button" class="button" id="yaa-export-csv">
                        📄 <?php esc_html_e('Als CSV exportieren', 'yadore-amazon-api'); ?>
                    </button>
                    <button type="button" class="button" id="yaa-copy-merchant-ids">
                        📋 <?php esc_html_e('Händler-IDs kopieren', 'yadore-amazon-api'); ?>
                    </button>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Kombiniert Händler aus beiden APIs
     * 
     * @param string $market
     * @param string $view
     * @return array<int, array<string, mixed>>
     */
    private function get_combined_merchants(string $market, string $view): array {
        $offer_merchants = [];
        $deeplink_merchants = [];
        
        // Offer-Händler laden (wenn nicht nur Deeplink-Ansicht)
        if ($view !== 'deeplink' && $view !== 'smartlink') {
            $offer_result = YAA_Merchant_Filter::get_stored_merchants($market);
            foreach ($offer_result as $m) {
                $offer_merchants[$m['id']] = [
                    'id'          => $m['id'],
                    'name'        => $m['name'],
                    'logo'        => $m['logo'] ?? '',
                    'has_logo'    => $m['hasLogo'] ?? false,
                    'offer_count' => $m['offerCount'] ?? 0,
                    'type'        => 'offer',
                    'is_smartlink' => false,
                    'is_deeplink' => false,
                    'has_homepage' => false,
                    'cpc_amount'  => 0,
                    'cpc_currency' => 'EUR',
                ];
            }
        }
        
        // Deeplink-Händler laden (wenn nicht nur Offer-Ansicht)
        if ($view !== 'offer') {
            $smartlink_only = ($view === 'smartlink');
            $deeplink_result = $this->get_stored_deeplink_merchants($market);
            
            foreach ($deeplink_result as $m) {
                $is_smartlink = !empty($m['isSmartlink']);
                
                // Bei Smartlink-Filter nur Smartlinks zeigen
                if ($smartlink_only && !$is_smartlink) {
                    continue;
                }
                
                $id = $m['id'];
                
                // CPC extrahieren
                $cpc_amount = 0;
                $cpc_currency = 'EUR';
                if (!empty($m['estimatedCpc']['amount'])) {
                    $cpc_amount = (float) $m['estimatedCpc']['amount'];
                    $cpc_currency = $m['estimatedCpc']['currency'] ?? 'EUR';
                }
                
                // Existiert bereits als Offer-Händler?
                if (isset($offer_merchants[$id])) {
                    // Merge: CPC und Deeplink-Info hinzufügen
                    $offer_merchants[$id]['is_deeplink'] = true;
                    $offer_merchants[$id]['is_smartlink'] = $is_smartlink;
                    $offer_merchants[$id]['has_homepage'] = !empty($m['hasSmartlinkHomepage']) || !empty($m['hasExternalHomepage']);
                    $offer_merchants[$id]['deeplink_count'] = $m['deeplinkCount'] ?? 0;
                    
                    if ($cpc_amount > 0) {
                        $offer_merchants[$id]['cpc_amount'] = $cpc_amount;
                        $offer_merchants[$id]['cpc_currency'] = $cpc_currency;
                    }
                } else {
                    // Neuer Händler (nur Deeplink)
                    $deeplink_merchants[$id] = [
                        'id'            => $id,
                        'name'          => $m['name'],
                        'logo'          => $m['logo']['url'] ?? '',
                        'has_logo'      => $m['logo']['exists'] ?? false,
                        'offer_count'   => 0,
                        'deeplink_count'=> $m['deeplinkCount'] ?? 0,
                        'type'          => 'deeplink',
                        'is_smartlink'  => $is_smartlink,
                        'is_deeplink'   => true,
                        'has_homepage'  => !empty($m['hasSmartlinkHomepage']) || !empty($m['hasExternalHomepage']),
                        'cpc_amount'    => $cpc_amount,
                        'cpc_currency'  => $cpc_currency,
                    ];
                }
            }
        }
        
        // Zusammenführen
        $all = array_merge(array_values($offer_merchants), array_values($deeplink_merchants));
        
        // Filter: Nur mit CPC
        if ($view === 'with_cpc') {
            $all = array_filter($all, fn($m) => ($m['cpc_amount'] ?? 0) > 0);
            $all = array_values($all);
        }
        
        return $all;
    }
    
    /**
     * Holt gespeicherte Deeplink-Händler
     * 
     * @return array<int, array<string, mixed>>
     */
    private function get_stored_deeplink_merchants(string $market): array {
        $merchants = get_option('yaa_yadore_deeplink_merchants_' . $market, []);
        return is_array($merchants) ? $merchants : [];
    }
    
    /**
     * Sortiert Händler
     * 
     * @param array<int, array<string, mixed>> $merchants
     * @return array<int, array<string, mixed>>
     */
    private function sort_merchants(array $merchants, string $sort, string $order): array {
        usort($merchants, function($a, $b) use ($sort, $order) {
            $val_a = match($sort) {
                'cpc' => (float) ($a['cpc_amount'] ?? 0),
                'offers' => (int) ($a['offer_count'] ?? 0),
                'type' => $a['type'] ?? '',
                default => strtolower($a['name'] ?? ''),
            };
            
            $val_b = match($sort) {
                'cpc' => (float) ($b['cpc_amount'] ?? 0),
                'offers' => (int) ($b['offer_count'] ?? 0),
                'type' => $b['type'] ?? '',
                default => strtolower($b['name'] ?? ''),
            };
            
            if (is_numeric($val_a) && is_numeric($val_b)) {
                $cmp = $val_a <=> $val_b;
            } else {
                $cmp = strcmp((string) $val_a, (string) $val_b);
            }
            
            return $order === 'DESC' ? -$cmp : $cmp;
        });
        
        return $merchants;
    }
    
    /**
     * Statistiken berechnen
     * 
     * @param array<int, array<string, mixed>> $merchants
     * @return array<string, mixed>
     */
    private function get_stats(array $merchants): array {
        $total = count($merchants);
        $with_cpc = 0;
        $total_cpc = 0;
        $smartlink = 0;
        
        foreach ($merchants as $m) {
            $cpc = (float) ($m['cpc_amount'] ?? 0);
            if ($cpc > 0) {
                $with_cpc++;
                $total_cpc += $cpc;
            }
            if (!empty($m['is_smartlink'])) {
                $smartlink++;
            }
        }
        
        return [
            'total'    => $total,
            'with_cpc' => $with_cpc,
            'avg_cpc'  => $with_cpc > 0 ? $total_cpc / $with_cpc : 0,
            'smartlink' => $smartlink,
        ];
    }
    
    /**
     * CPC-Klasse ermitteln
     */
    private function get_cpc_class(float $cpc): string {
        if ($cpc >= 0.10) return 'yaa-cpc-high';
        if ($cpc >= 0.05) return 'yaa-cpc-medium';
        return 'yaa-cpc-low';
    }
    
    /**
     * Sortierbare Spaltenüberschrift
     */
    private function sortable_header(string $column, string $label, string $current_sort, string $current_order): string {
        $new_order = ($current_sort === $column && $current_order === 'ASC') ? 'DESC' : 'ASC';
        $arrow = '';
        
        if ($current_sort === $column) {
            $arrow = $current_order === 'ASC' ? ' ↑' : ' ↓';
        }
        
        $url = add_query_arg([
            'sort' => $column,
            'order' => $new_order,
        ]);
        
        return '<a href="' . esc_url($url) . '">' . esc_html($label) . $arrow . '</a>';
    }
    
    /**
     * Typ-Badges rendern
     */
    private function render_type_badges(array $merchant): string {
        $badges = [];
        
        if ($merchant['type'] === 'offer' || !empty($merchant['offer_count'])) {
            $badges[] = '<span class="yaa-status-badge yaa-status-info">Offer</span>';
        }
        
        if (!empty($merchant['is_deeplink'])) {
            $badges[] = '<span class="yaa-status-badge yaa-status-warning">Deeplink</span>';
        }
        
        if (!empty($merchant['is_smartlink'])) {
            $badges[] = '<span class="yaa-status-badge yaa-status-success">Smartlink</span>';
        }
        
        return implode(' ', $badges);
    }
    
    /**
     * Feature-Badges rendern
     */
    private function render_feature_badges(array $merchant): string {
        $badges = [];
        
        if (!empty($merchant['has_homepage'])) {
            $badges[] = '<span title="' . esc_attr__('Homepage-Link möglich', 'yadore-amazon-api') . '">🏠</span>';
        }
        
        if (!empty($merchant['deeplink_count']) && $merchant['deeplink_count'] > 0) {
            $badges[] = '<span title="' . esc_attr(sprintf(__('%d Deeplinks', 'yadore-amazon-api'), $merchant['deeplink_count'])) . '">🔗</span>';
        }
        
        return implode(' ', $badges) ?: '—';
    }
    
    /**
     * JavaScript für die Seite
     */
    public function get_page_js(): string {
        return '
            jQuery(document).ready(function($) {
                // Alle Händler aktualisieren
                $("#yaa-refresh-all-merchants").on("click", function() {
                    var $btn = $(this);
                    var $status = $("#yaa-refresh-status");
                    var market = $("select[name=\'market\']").val();
                    
                    $btn.prop("disabled", true).text("Wird geladen...");
                    $status.html("");
                    
                    // 1. Offer-Händler laden
                    $.post(yaaAdmin.ajaxurl, {
                        action: "yaa_refresh_merchants",
                        nonce: yaaAdmin.nonce,
                        market: market
                    }).then(function(response1) {
                        $status.html("<span style=\'color:blue;\'>⏳ Offer-Händler geladen, lade Deeplinks...</span>");
                        
                        // 2. Deeplink-Händler laden
                        return $.post(yaaAdmin.ajaxurl, {
                            action: "yaa_fetch_deeplink_merchants",
                            nonce: yaaAdmin.nonce,
                            market: market
                        });
                    }).then(function(response2) {
