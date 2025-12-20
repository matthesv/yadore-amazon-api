<?php
/**
 * Admin Status Page
 * Cache & Status Übersicht + Dashboard Widget
 * PHP 8.3+ compatible
 * Version: 1.4.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin_Status {
    
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
    }
    
    /**
     * Render status page
     */
    public function render_page(): void {
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
        
        $cache_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_yaa_%' AND option_name NOT LIKE '_transient_timeout_%'"
        );
        
        $image_stats = $this->get_local_image_stats();
        
        // CPC-Statistiken laden
        $cpc_stats = $this->yadore_api->get_cpc_statistics(yaa_get_option('yadore_market', 'de'));
        
        ?>
        <div class="wrap yaa-admin-wrap">
            <h1><?php esc_html_e('Cache & Status', 'yadore-amazon-api'); ?></h1>
            
            <div class="yaa-grid">
                <div class="yaa-card">
                    <h2>📊 Cache-Status</h2>
                    <table class="widefat striped">
                        <tr>
                            <td><strong>Backend:</strong></td>
                            <td>
                                <span class="yaa-status-badge <?php echo $cache_status['redis_available'] ? 'yaa-status-success' : 'yaa-status-warning'; ?>">
                                    <?php echo esc_html($cache_status['cache_backend']); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Cache-Einträge:</strong></td>
                            <td><?php echo $cache_count; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Getrackte Keywords:</strong></td>
                            <td><?php echo count($cached_keywords); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Nächster Cron:</strong></td>
                            <td><?php echo $next_cron ? esc_html(date_i18n('d.m.Y H:i', $next_cron)) : 'Nicht geplant'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Lokale Bilder:</strong></td>
                            <td><?php echo (int) $image_stats['count']; ?> (<?php echo esc_html($image_stats['size']); ?>)</td>
                        </tr>
                        <tr>
                            <td><strong>Bildformat:</strong></td>
                            <td>
                                <?php 
                                $format = yaa_get_option('image_filename_format', 'seo');
                                echo $format === 'seo' ? '🔍 SEO-optimiert' : '🔢 Technisch (ID)';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Bildgröße:</strong></td>
                            <td><?php echo esc_html(yaa_get_option('preferred_image_size', 'Large')); ?></td>
                        </tr>
                    </table>
                    
                    <p style="margin-top: 15px;">
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=yaa_clear_cache'), 'yaa_clear_cache_nonce')); ?>" 
                           class="button button-primary">
                            🗑️ Cache leeren
                        </a>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=yaa_clear_images'), 'yaa_clear_images_nonce')); ?>" 
                           class="button"
                           onclick="return confirm('Alle lokal gespeicherten Bilder löschen?');">
                            🖼️ Bilder löschen
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
                                    <span class="yaa-status-badge yaa-status-success">✅ Aktiv</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-error">❌ Nicht konfiguriert</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Amazon PA-API:</strong></td>
                            <td>
                                <?php if ($this->amazon_api->is_configured()): ?>
                                    <span class="yaa-status-badge yaa-status-success">✅ Aktiv</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-error">❌ Nicht konfiguriert</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Redis:</strong></td>
                            <td>
                                <?php if ($cache_status['redis_available']): ?>
                                    <span class="yaa-status-badge yaa-status-success">✅ Verbunden</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-warning">⚠️ Nicht verfügbar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Lokale Bilder:</strong></td>
                            <td>
                                <?php if (yaa_get_option('enable_local_images', 'yes') === 'yes'): ?>
                                    <span class="yaa-status-badge yaa-status-success">✅ Aktiviert</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-warning">⚠️ Deaktiviert</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Fuzzy-Suche:</strong></td>
                            <td>
                                <?php if (yaa_get_option('enable_fuzzy_search', 'yes') === 'yes'): ?>
                                    <span class="yaa-status-badge yaa-status-success">✅ Aktiviert</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-warning">⚠️ Deaktiviert</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Merchant Filter:</strong></td>
                            <td>
                                <?php 
                                $whitelist = yaa_get_option('yadore_merchant_whitelist', '');
                                $blacklist = yaa_get_option('yadore_merchant_blacklist', '');
                                if ($whitelist !== ''): ?>
                                    <span class="yaa-status-badge yaa-status-info">Whitelist aktiv</span>
                                <?php elseif ($blacklist !== ''): ?>
                                    <span class="yaa-status-badge yaa-status-warning">Blacklist aktiv</span>
                                <?php else: ?>
                                    <span class="yaa-status-badge yaa-status-info">Kein Filter</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- CPC-Statistiken -->
            <div class="yaa-card">
                <h2>💰 CPC-Statistiken (Yadore Deeplink-Händler)</h2>
                
                <?php if ($cpc_stats['total'] > 0): ?>
                    <div class="yaa-grid-4" style="margin: 20px 0;">
                        <div class="yaa-stat-box" style="background: #f0f6fc;">
                            <div class="yaa-stat-number" style="color: #2271b1;"><?php echo $cpc_stats['total']; ?></div>
                            <div class="yaa-stat-label">Deeplink-Händler gesamt</div>
                        </div>
                        <div class="yaa-stat-box" style="background: #d4edda;">
                            <div class="yaa-stat-number" style="color: #155724;"><?php echo $cpc_stats['with_cpc']; ?></div>
                            <div class="yaa-stat-label">Mit CPC-Daten</div>
                        </div>
                        <div class="yaa-stat-box" style="background: #fff3cd;">
                            <div class="yaa-stat-number" style="color: #856404;">
                                <?php echo number_format($cpc_stats['avg_cpc'], 4, ',', '.'); ?> €
                            </div>
                            <div class="yaa-stat-label">Durchschnitt CPC</div>
                        </div>
                        <div class="yaa-stat-box" style="background: #f0faf0;">
                            <div class="yaa-stat-number" style="color: #155724;">
                                <?php echo number_format($cpc_stats['max_cpc'], 4, ',', '.'); ?> €
                            </div>
                            <div class="yaa-stat-label">Höchster CPC</div>
                        </div>
                    </div>
                    
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=yaa-merchants')); ?>" class="button button-primary">
                            📊 Händlerübersicht mit CPC öffnen
                        </a>
                    </p>
                <?php else: ?>
                    <p>
                        Keine Deeplink-Händler-Daten vorhanden. 
                        <a href="<?php echo esc_url(admin_url('admin.php?page=yaa-merchants')); ?>">Händlerübersicht öffnen</a> und Daten laden.
                    </p>
                <?php endif; ?>
            </div>
            
            <div class="yaa-card">
                <h2>🛠️ System-Informationen</h2>
                <table class="widefat striped">
                    <tr>
                        <td><strong>PHP Version:</strong></td>
                        <td><?php echo esc_html(PHP_VERSION); ?>
                            <?php if (version_compare(PHP_VERSION, '8.1', '>=')): ?>
                                <span class="yaa-status-badge yaa-status-success">✓</span>
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-error">Minimum 8.1 erforderlich</span>
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
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-warning">Nicht installiert</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Bildverarbeitung:</strong></td>
                        <td>
                            <?php 
                            $image_library = class_exists('YAA_Image_Handler') ? YAA_Image_Handler::get_image_library() : 'Unknown';
                            if ($image_library !== 'None'): ?>
                                <span class="yaa-status-badge yaa-status-success">✅ <?php echo esc_html($image_library); ?></span>
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-warning">Nicht verfügbar</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Bild-Verzeichnis:</strong></td>
                        <td>
                            <?php 
                            $upload_dir = wp_upload_dir();
                            $image_dir = $upload_dir['basedir'] . '/yadore-amazon-api';
                            if (is_dir($image_dir) && is_writable($image_dir)): ?>
                                <span class="yaa-status-badge yaa-status-success">✅ Beschreibbar</span>
                                <code style="margin-left: 10px;"><?php echo esc_html($image_dir); ?></code>
                            <?php elseif (is_dir($image_dir)): ?>
                                <span class="yaa-status-badge yaa-status-error">❌ Nicht beschreibbar</span>
                            <?php else: ?>
                                <span class="yaa-status-badge yaa-status-info">📁 Wird bei Bedarf erstellt</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Top CPC Händler -->
            <?php 
            $top_merchants = $this->yadore_api->get_merchants_by_cpc(yaa_get_option('yadore_market', 'de'), 10);
            if (!empty($top_merchants)):
            ?>
            <div class="yaa-card">
                <h2>🏆 Top 10 Händler nach CPC</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Händler</th>
                            <th>CPC</th>
                            <th>Smartlink</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_merchants as $index => $merchant): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><strong><?php echo esc_html($merchant['name']); ?></strong></td>
                            <td>
                                <span class="yaa-status-badge yaa-status-success">
                                    <?php echo number_format($merchant['cpc_amount'], 4, ',', '.'); ?> €
                                </span>
                            </td>
                            <td>
                                <?php if ($merchant['is_smartlink']): ?>
                                    <span class="yaa-status-badge yaa-status-info">Ja</span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Dashboard widget content
     */
    public function render_dashboard_widget(): void {
        $cache_status = $this->cache->get_status();
        $next_cron = wp_next_scheduled('yaa_cache_refresh_event');
        $image_stats = $this->get_local_image_stats();
        
        echo '<table class="widefat striped" style="border:none;">';
        echo '<tr><td>Yadore API:</td><td>' . ($this->yadore_api->is_configured() ? '✅ Aktiv' : '❌') . '</td></tr>';
        echo '<tr><td>Amazon PA-API:</td><td>' . ($this->amazon_api->is_configured() ? '✅ Aktiv' : '❌') . '</td></tr>';
        echo '<tr><td>Cache:</td><td>' . esc_html($cache_status['cache_backend']) . '</td></tr>';
        echo '<tr><td>Lokale Bilder:</td><td>' . (int) $image_stats['count'] . ' (' . esc_html($image_stats['size']) . ')</td></tr>';
        echo '<tr><td>Nächster Cron:</td><td>' . ($next_cron ? esc_html(date_i18n('d.m.Y H:i', $next_cron)) : '-') . '</td></tr>';
        echo '</table>';
        
        echo '<p style="margin-top:15px;">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=yaa-settings')) . '" class="button">⚙️ Einstellungen</a> ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=yaa-merchants')) . '" class="button">🏪 Händler</a> ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=yaa-status')) . '" class="button">📊 Status</a>';
        echo '</p>';
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
