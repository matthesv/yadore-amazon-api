<?php
/**
 * Admin CSS Settings
 * Enthält die Custom CSS Eingabefelder für jeden Shortcode-Typ
 * PHP 8.3+ compatible
 * Version: 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin_CSS_Settings {
    
    /**
     * Render CSS Settings Tab Content
     * Wird in class-admin-settings.php aufgerufen
     */
    public static function render_tab(): void {
        ?>
        <div class="yaa-card">
            <h2>🎨 <?php esc_html_e('Custom CSS Einstellungen', 'yadore-amazon-api'); ?></h2>
            
            <div class="yaa-feature-box info" style="margin-bottom: 20px;">
                <h4 style="margin-top: 0;">💡 <?php esc_html_e('Wie funktioniert Custom CSS?', 'yadore-amazon-api'); ?></h4>
                <p>
                    <?php esc_html_e('Wenn du "Standard-CSS deaktivieren" aktivierst, werden die Plugin-Styles nicht geladen. Stattdessen wird das hier eingetragene Custom CSS verwendet.', 'yadore-amazon-api'); ?>
                </p>
                <ul style="margin: 10px 0 0 20px;">
                    <li><strong><?php esc_html_e('Grid-CSS:', 'yadore-amazon-api'); ?></strong> <?php esc_html_e('Für Produkt-Grids (yadore_products, amazon_products, etc.)', 'yadore-amazon-api'); ?></li>
                    <li><strong><?php esc_html_e('Banner-CSS:', 'yadore-amazon-api'); ?></strong> <?php esc_html_e('Für den Banner-Shortcode (yaa_banner)', 'yadore-amazon-api'); ?></li>
                    <li><strong><?php esc_html_e('Search-CSS:', 'yadore-amazon-api'); ?></strong> <?php esc_html_e('Für die Produktsuche (yadore_search)', 'yadore-amazon-api'); ?></li>
                </ul>
                <p style="margin-top: 10px;">
                    <strong><?php esc_html_e('Wichtig:', 'yadore-amazon-api'); ?></strong> 
                    <?php esc_html_e('Das CSS wird nur geladen, wenn der entsprechende Shortcode auf der Seite vorhanden ist.', 'yadore-amazon-api'); ?>
                </p>
            </div>
            
            <!-- Aktivierung -->
            <div class="yaa-form-row">
                <label>
                    <input type="checkbox" 
                           name="yaa_settings[disable_default_css]" 
                           value="yes"
                           <?php checked(yaa_get_option('disable_default_css', 'no'), 'yes'); ?>>
                    <strong><?php esc_html_e('Standard-CSS deaktivieren', 'yadore-amazon-api'); ?></strong>
                </label>
                <p class="description">
                    <?php esc_html_e('Wenn aktiviert, werden die Plugin-Stylesheets nicht geladen. Nutze das Custom CSS unten für eigene Styles.', 'yadore-amazon-api'); ?>
                </p>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <!-- Grid CSS -->
            <div class="yaa-form-row">
                <label for="yaa_custom_css_grid">
                    <strong>📦 <?php esc_html_e('Custom CSS für Produkt-Grids', 'yadore-amazon-api'); ?></strong>
                </label>
                <p class="description" style="margin-bottom: 10px;">
                    <?php esc_html_e('Für Shortcodes:', 'yadore-amazon-api'); ?> 
                    <code>[yadore_products]</code>, <code>[amazon_products]</code>, <code>[custom_products]</code>, 
                    <code>[combined_products]</code>, <code>[all_products]</code>, <code>[fuzzy_products]</code>
                </p>
                <textarea id="yaa_custom_css_grid" 
                          name="yaa_settings[custom_css_grid]" 
                          rows="15" 
                          style="width: 100%; max-width: 800px; font-family: monospace; font-size: 13px;"
                          placeholder="<?php esc_attr_e('/* Dein Grid CSS hier */', 'yadore-amazon-api'); ?>"
                ><?php echo esc_textarea(yaa_get_option('custom_css_grid', '')); ?></textarea>
                
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; color: #0073aa;">
                        <?php esc_html_e('📋 Beispiel CSS für Grid anzeigen', 'yadore-amazon-api'); ?>
                    </summary>
                    <pre style="background: #f5f5f5; padding: 15px; margin-top: 10px; overflow-x: auto; font-size: 12px;">
.yaa-grid-container {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.yaa-item {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    transition: box-shadow 0.3s;
}

.yaa-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.yaa-title {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 10px;
}

.yaa-price {
    font-size: 1.2rem;
    font-weight: 700;
    color: #e00;
}

.yaa-button {
    display: inline-block;
    background: #0073aa;
    color: #fff;
    padding: 10px 20px;
    border-radius: 4px;
    text-decoration: none;
}

@media (max-width: 768px) {
    .yaa-grid-container {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .yaa-grid-container {
        grid-template-columns: 1fr;
    }
}</pre>
                </details>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <!-- Banner CSS -->
            <div class="yaa-form-row">
                <label for="yaa_custom_css_banner">
                    <strong>🎯 <?php esc_html_e('Custom CSS für Banner', 'yadore-amazon-api'); ?></strong>
                </label>
                <p class="description" style="margin-bottom: 10px;">
                    <?php esc_html_e('Für Shortcode:', 'yadore-amazon-api'); ?> <code>[yaa_banner]</code>
                </p>
                <textarea id="yaa_custom_css_banner" 
                          name="yaa_settings[custom_css_banner]" 
                          rows="15" 
                          style="width: 100%; max-width: 800px; font-family: monospace; font-size: 13px;"
                          placeholder="<?php esc_attr_e('/* Dein Banner CSS hier */', 'yadore-amazon-api'); ?>"
                ><?php echo esc_textarea(yaa_get_option('custom_css_banner', '')); ?></textarea>
                
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; color: #0073aa;">
                        <?php esc_html_e('📋 Beispiel CSS für Banner anzeigen', 'yadore-amazon-api'); ?>
                    </summary>
                    <pre style="background: #f5f5f5; padding: 15px; margin-top: 10px; overflow-x: auto; font-size: 12px;">
.yaa-banner-container {
    position: relative;
    width: 100%;
    margin: 1rem 0;
    overflow: hidden;
}

.yaa-banner-scroll-wrapper {
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: none;
}

.yaa-banner-scroll-wrapper::-webkit-scrollbar {
    display: none;
}

.yaa-banner-track {
    display: flex;
    gap: 12px;
    padding: 4px;
}

.yaa-banner-item {
    flex: 0 0 auto;
    min-width: 280px;
    max-width: 320px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    transition: transform 0.2s;
}

.yaa-banner-item:hover {
    transform: translateY(-2px);
}

.yaa-banner-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    text-decoration: none;
    color: inherit;
}

.yaa-banner-image {
    width: 60px;
    height: 60px;
    border-radius: 6px;
    overflow: hidden;
}

.yaa-banner-image img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.yaa-banner-title {
    font-size: 0.875rem;
    font-weight: 500;
}

.yaa-banner-price {
    font-weight: 600;
    color: #e00;
}</pre>
                </details>
            </div>
            
            <hr style="margin: 30px 0;">
            
            <!-- Search CSS -->
            <div class="yaa-form-row">
                <label for="yaa_custom_css_search">
                    <strong>🔍 <?php esc_html_e('Custom CSS für Produktsuche', 'yadore-amazon-api'); ?></strong>
                </label>
                <p class="description" style="margin-bottom: 10px;">
                    <?php esc_html_e('Für Shortcodes:', 'yadore-amazon-api'); ?> 
                    <code>[yadore_search]</code>, <code>[yaa_search]</code>, <code>[yaa_product_search]</code>
                </p>
                <textarea id="yaa_custom_css_search" 
                          name="yaa_settings[custom_css_search]" 
                          rows="15" 
                          style="width: 100%; max-width: 800px; font-family: monospace; font-size: 13px;"
                          placeholder="<?php esc_attr_e('/* Dein Search CSS hier */', 'yadore-amazon-api'); ?>"
                ><?php echo esc_textarea(yaa_get_option('custom_css_search', '')); ?></textarea>
                
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer; color: #0073aa;">
                        <?php esc_html_e('📋 Beispiel CSS für Search anzeigen', 'yadore-amazon-api'); ?>
                    </summary>
                    <pre style="background: #f5f5f5; padding: 15px; margin-top: 10px; overflow-x: auto; font-size: 12px;">
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
    max-width: 600px;
}

.yaa-search-input {
    flex: 1;
    padding: 0.875rem 1rem;
    border: 2px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
}

.yaa-search-input:focus {
    border-color: #0073aa;
    outline: none;
}

.yaa-search-button {
    padding: 0.875rem 1.5rem;
    background: #0073aa;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.yaa-search-button:hover {
    background: #005a8c;
}

.yaa-search-results-inner {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
}

.yaa-product-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
    transition: box-shadow 0.2s;
}

.yaa-product-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}</pre>
                </details>
            </div>
        </div>
        
        <div class="yaa-card">
            <h2>📚 <?php esc_html_e('CSS Klassen Referenz', 'yadore-amazon-api'); ?></h2>
            <p><?php esc_html_e('Eine vollständige Liste aller CSS-Klassen findest du in der', 'yadore-amazon-api'); ?> 
               <a href="<?php echo esc_url(admin_url('admin.php?page=yaa-docs#css-classes')); ?>">
                   <?php esc_html_e('Dokumentation', 'yadore-amazon-api'); ?>
               </a>.
            </p>
        </div>
        <?php
    }
    
    /**
     * Sanitize CSS input
     * 
     * @param string $css Raw CSS input
     * @return string Sanitized CSS
     */
    public static function sanitize_css(string $css): string {
        // Entferne potenziell gefährliche Inhalte
        $css = wp_strip_all_tags($css);
        
        // Entferne JavaScript-Ausdrücke
        $css = preg_replace('/expression\s*\(/i', '', $css) ?? $css;
        $css = preg_replace('/javascript\s*:/i', '', $css) ?? $css;
        $css = preg_replace('/behavior\s*:/i', '', $css) ?? $css;
        $css = preg_replace('/@import/i', '', $css) ?? $css;
        
        return $css;
    }
}
