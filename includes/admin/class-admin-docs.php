<?php
/**
 * Admin Documentation Page
 * Dokumentation und Shortcode-Referenz
 * PHP 8.3+ compatible
 * Version: 1.7.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin_Docs {
    
    public function __construct() {
        // Keine Abhängigkeiten benötigt
    }
    
    /**
     * Render documentation page
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap yaa-admin-wrap">
            <h1><?php esc_html_e('Dokumentation', 'yadore-amazon-api'); ?></h1>
            
            <!-- Quick Navigation -->
            <div class="yaa-card" style="background: #f0f6fc;">
                <h2 style="margin-top: 0;">📍 Schnellnavigation</h2>
                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <a href="#shortcodes" class="button">📝 Shortcodes</a>
                    <a href="#banner-shortcode" class="button">🎯 Banner (NEU)</a>
                    <a href="#css-classes" class="button">🎨 CSS Klassen</a>
                    <a href="#wp-config" class="button">⚙️ wp-config.php</a>
                    <a href="#cpc-info" class="button">💰 CPC-Informationen</a>
                    <a href="#merchant-filter" class="button">🏪 Händler-Filter</a>
                    <a href="#links" class="button">🔗 Links</a>
                </div>
            </div>
            
            <div id="shortcodes" class="yaa-card">
                <h2>📝 Shortcodes</h2>
                
                <h3>Yadore Produkte</h3>
                <div class="yaa-shortcode-box">[yadore_products keyword="Smartphone" limit="9"]</div>
                <div class="yaa-shortcode-box">[yadore_products keywords="Smartphone,Tablet,Laptop" limit="9"]</div>
                <div class="yaa-shortcode-box">[yadore_products keyword="Kopfhörer" local_images="yes"]</div>
                <div class="yaa-shortcode-box">[yadore_products keyword="Gaming" hide_no_image="yes"]</div>
                
                <h3>Yadore mit Händler-Filter</h3>
                <div class="yaa-shortcode-box">[yadore_products keyword="Laptop" merchant_whitelist="amazon,otto"]</div>
                <div class="yaa-shortcode-box">[yadore_products keyword="Handy" merchant_blacklist="aliexpress,wish"]</div>
                <div class="yaa-shortcode-box">[yadore_products keyword="Tablet" merchants="mediamarkt,saturn"]</div>
                
                <h3>Amazon Produkte</h3>
                <div class="yaa-shortcode-box">[amazon_products keyword="Laptop" category="Computers" limit="10"]</div>
                <div class="yaa-shortcode-box">[amazon_products asins="B08N5WRWNW,B09V3KXJPB"]</div>
                <div class="yaa-shortcode-box">[amazon_products keyword="Kopfhörer" min_price="50" max_price="200"]</div>
                
                <h3>Eigene Produkte</h3>
                <div class="yaa-shortcode-box">[custom_products ids="123,456,789"]</div>
                <div class="yaa-shortcode-box">[custom_products category="elektronik" limit="6"]</div>
                <div class="yaa-shortcode-box">[fuzzy_products keyword="Kopfhörer" limit="6"]</div>
                
                <h3>Kombiniert</h3>
                <div class="yaa-shortcode-box">[combined_products keyword="Kopfhörer" yadore_limit="6" amazon_limit="4"]</div>
                <div class="yaa-shortcode-box">[all_products keyword="Smartphone" total_limit="12" priority="custom,yadore,amazon"]</div>
                
                <h3>Mit eigenen Produkten mischen</h3>
                <div class="yaa-shortcode-box">[yadore_products keyword="Monitor" mix_custom="yes" custom_limit="2"]</div>
                <div class="yaa-shortcode-box">[amazon_products keyword="Tablet" mix_custom="yes" custom_position="alternate"]</div>
                
                <div class="yaa-tip">
                    <strong>Tipp:</strong> <code>custom_position</code> akzeptiert: <code>start</code>, <code>end</code>, <code>shuffle</code>, <code>alternate</code>
                </div>
            </div>
            
            <!-- NEU: Banner Shortcode Dokumentation -->
            <div id="banner-shortcode" class="yaa-card">
                <h2>🎯 Banner Shortcode <span class="yaa-badge-new">NEU in 1.7.0</span></h2>
                
                <p>Responsiver Affiliate-Banner als AdSense-Ersatz. Füllt die verfügbare Container-Breite und passt die Anzahl der sichtbaren Produkte automatisch an.</p>
                
                <h3>Grundlegende Verwendung</h3>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Laptop"]</div>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Smartphone" cta="Jetzt kaufen"]</div>
                
                <h3>Mit Quellenauswahl</h3>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Kopfhörer" source="yadore"]</div>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Gaming" source="amazon" category="VideoGames"]</div>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Monitor" source="all" limit="8"]</div>
                
                <h3>Style-Varianten</h3>
                <table class="widefat" style="margin: 15px 0;">
                    <thead>
                        <tr>
                            <th>Style</th>
                            <th>Höhe</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>horizontal</code></td>
                            <td>~90px</td>
                            <td>Klassischer Leaderboard-Ersatz mit Bild, Titel, Preis und CTA</td>
                        </tr>
                        <tr>
                            <td><code>compact</code></td>
                            <td>~70px</td>
                            <td>Kompakt mit Bild, Titel und Preis</td>
                        </tr>
                        <tr>
                            <td><code>minimal</code></td>
                            <td>Inline</td>
                            <td>Nur Text-Links, ideal für Fließtext</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="yaa-shortcode-box">[yaa_banner keyword="Tablet" style="horizontal"]</div>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Kamera" style="compact" limit="8"]</div>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Bücher" style="minimal" limit="5"]</div>
                
                <h3>Bild-Seitenverhältnis</h3>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Monitor" aspect="16:9"]</div>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Smartphone" aspect="1:1"]</div>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Tablet" aspect="4:3"]</div>
                
                <h3>Weitere Optionen</h3>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Laptop" show_price="no"]</div>
                <div class="yaa-shortcode-box">[yaa_banner keyword="Gaming" source="amazon" cta="Bei Amazon kaufen"]</div>
                
                <h3>Alle Attribute</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Attribut</th>
                            <th>Standard</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>keyword</code></td>
                            <td>—</td>
                            <td>Suchbegriff (Pflichtfeld)</td>
                        </tr>
                        <tr>
                            <td><code>source</code></td>
                            <td>yadore</td>
                            <td>Produktquelle: <code>yadore</code>, <code>amazon</code>, <code>custom</code>, <code>all</code></td>
                        </tr>
                        <tr>
                            <td><code>limit</code></td>
                            <td>6</td>
                            <td>Maximale Produktanzahl (1-12)</td>
                        </tr>
                        <tr>
                            <td><code>cta</code></td>
                            <td>Zum Angebot</td>
                            <td>Text des Call-to-Action Buttons</td>
                        </tr>
                        <tr>
                            <td><code>style</code></td>
                            <td>horizontal</td>
                            <td>Darstellung: <code>horizontal</code>, <code>compact</code>, <code>minimal</code></td>
                        </tr>
                        <tr>
                            <td><code>show_price</code></td>
                            <td>yes</td>
                            <td>Preisanzeige: <code>yes</code> oder <code>no</code></td>
                        </tr>
                        <tr>
                            <td><code>aspect</code></td>
                            <td>1:1</td>
                            <td>Bild-Seitenverhältnis: <code>1:1</code>, <code>16:9</code>, <code>4:3</code></td>
                        </tr>
                        <tr>
                            <td><code>market</code></td>
                            <td>Plugin-Standard</td>
                            <td>Yadore Market-Code (de, at, ch, etc.)</td>
                        </tr>
                        <tr>
                            <td><code>category</code></td>
                            <td>All</td>
                            <td>Amazon SearchIndex</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Responsive Verhalten</h3>
                <table class="widefat" style="margin: 15px 0;">
                    <thead>
                        <tr>
                            <th>Viewport</th>
                            <th>Sichtbare Produkte</th>
                            <th>Verhalten</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Desktop (≥1200px)</td>
                            <td>4-5</td>
                            <td>Nebeneinander</td>
                        </tr>
                        <tr>
                            <td>Tablet (768-1199px)</td>
                            <td>3-4</td>
                            <td>Nebeneinander</td>
                        </tr>
                        <tr>
                            <td>Mobile (&lt;768px)</td>
                            <td>1-2</td>
                            <td>Horizontal scrollbar mit Fade-Out und Pfeilen</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="yaa-feature-box info">
                    <h4 style="margin-top: 0;">💡 Tipp: Quellen-Markierung</h4>
                    <p>Banner-Items werden dezent nach Quelle markiert:</p>
                    <ul style="margin-bottom: 0;">
                        <li><span style="color: #10b981;">■</span> Grün = Yadore</li>
                        <li><span style="color: #f59e0b;">■</span> Orange = Amazon</li>
                        <li><span style="color: #8b5cf6;">■</span> Lila = Eigene Produkte</li>
                    </ul>
                </div>
            </div>
            
            <div id="merchant-filter" class="yaa-card">
                <h2>🏪 Händler-Filter Dokumentation</h2>
                
                <h3>Globale Einstellungen</h3>
                <p>Unter <strong>Yadore API → Händler-Filter</strong> kannst du globale Whitelist/Blacklist definieren.</p>
                
                <h3>Shortcode-Attribute</h3>
                <table class="widefat" style="margin: 15px 0;">
                    <thead>
                        <tr>
                            <th>Attribut</th>
                            <th>Alias</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>merchant_whitelist</code></td>
                            <td><code>merchants</code></td>
                            <td>NUR diese Händler anzeigen (Komma-separiert)</td>
                        </tr>
                        <tr>
                            <td><code>merchant_blacklist</code></td>
                            <td><code>exclude_merchants</code></td>
                            <td>Diese Händler ausschließen (Komma-separiert)</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="yaa-feature-box warning">
                    <h4 style="margin-top: 0;">⚠️ Wichtig: Whitelist hat IMMER Vorrang</h4>
                    <p>Wenn eine Whitelist (global oder per Shortcode) gesetzt ist, wird die Blacklist komplett ignoriert.</p>
                </div>
                
                <h3>Matching-Regeln</h3>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><strong>Case-insensitive:</strong> "Amazon" = "amazon" = "AMAZON"</li>
                    <li><strong>Partial Match:</strong> "amazon" findet auch "Amazon Marketplace"</li>
                    <li><strong>Priorität:</strong> Shortcode-Parameter > Globale Einstellungen</li>
                </ul>
            </div>
            
            <div id="cpc-info" class="yaa-card">
                <h2>💰 CPC-Informationen (Cost Per Click)</h2>
                
                <p>Das Plugin kann CPC-Daten von der Yadore Deeplink-API abrufen und anzeigen.</p>
                
                <h3>Was ist der CPC?</h3>
                <p>Der <strong>estimatedCpc</strong> ist der <strong>Brutto-CPC</strong>, den Yadore vom Händler erhält. 
                   Dein tatsächlicher Verdienst hängt von deinem Revenue-Share ab.</p>
                
                <h3>CPC-Quellen</h3>
                <table class="widefat" style="margin: 15px 0;">
                    <thead>
                        <tr>
                            <th>API Endpoint</th>
                            <th>CPC verfügbar</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>/v2/offer</code></td>
                            <td>✅ Ja (pro Angebot)</td>
                            <td>CPC für das spezifische Produkt/Angebot</td>
                        </tr>
                        <tr>
                            <td><code>/v2/deeplink/merchant</code></td>
                            <td>✅ Ja (pro Händler)</td>
                            <td>Durchschnittlicher CPC des Händlers</td>
                        </tr>
                        <tr>
                            <td><code>/v2/merchant</code></td>
                            <td>❌ Nein</td>
                            <td>Nur Händlerliste für Offer-API</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Händlerübersicht</h3>
                <p>Die neue <strong>Händlerübersicht</strong> (Menü: Yadore-Amazon → 🏪 Händler & CPC) zeigt:</p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li>Alle Offer-Händler aus <code>/v2/merchant</code></li>
                    <li>Alle Deeplink-Händler aus <code>/v2/deeplink/merchant</code> mit CPC</li>
                    <li>Kombinierte Ansicht mit Filteroptionen</li>
                    <li>Export als CSV</li>
                </ul>
                
                <div class="yaa-feature-box info">
                    <h4 style="margin-top: 0;">💡 Hinweis zur Revenue-Berechnung</h4>
                    <p>Um deinen tatsächlichen Verdienst zu berechnen:</p>
                    <p><strong>Dein CPC = Angezeigter CPC × Dein Revenue-Share</strong></p>
                    <p>Beispiel: 0.15€ × 70% = 0.105€ pro Klick</p>
                </div>
            </div>
            
            <div id="css-classes" class="yaa-card">
                <h2>🎨 CSS Klassen Referenz</h2>
                <p>Nutzen Sie diese Klassen, um das Design über <strong>Custom CSS</strong> in Ihrem Theme anzupassen.</p>
                
                <h3>Grid-Shortcodes</h3>
                <div class="yaa-code-block">
                    <pre>
/* Haupt-Container */
.yaa-grid-container { }

/* Einzelnes Produkt-Element */
.yaa-item { }
.yaa-item.yaa-amazon { }  /* Nur Amazon */
.yaa-item.yaa-custom { }  /* Nur Eigene */
.yaa-item.yaa-yadore { }  /* Nur Yadore */

/* Badges */
.yaa-prime-badge { }
.yaa-custom-badge { }

/* Bild-Bereich */
.yaa-image-wrapper { }
.yaa-image-wrapper img { }
.yaa-image-wrapper.yaa-image-error { }
.yaa-placeholder { }
.yaa-no-image { }

/* Inhalt */
.yaa-content { }
.yaa-title { }
.yaa-title a { }

/* Beschreibung */
.yaa-description-wrapper { }
.yaa-description { }
.yaa-description.expanded { }
.yaa-read-more { }

/* Preis & Meta */
.yaa-meta { }
.yaa-price { }
.yaa-merchant { }

/* Buttons */
.yaa-button-wrapper { }
.yaa-button { }

/* Status Meldungen */
.yaa-message { }
.yaa-error { }
.yaa-empty { }
                    </pre>
                </div>
                
                <h3>Banner-Shortcode (NEU)</h3>
                <div class="yaa-code-block">
                    <pre>
/* Container */
.yaa-banner-container { }
.yaa-banner-horizontal { }
.yaa-banner-compact { }
.yaa-banner-minimal { }

/* Scroll-Wrapper */
.yaa-banner-scroll-wrapper { }
.yaa-banner-track { }

/* Einzelnes Banner-Item */
.yaa-banner-item { }
.yaa-banner-item.yaa-yadore { }    /* Grüner Akzent */
.yaa-banner-item.yaa-amazon { }    /* Oranger Akzent */
.yaa-banner-item.yaa-custom { }    /* Lila Akzent */

/* Item-Komponenten */
.yaa-banner-link { }
.yaa-banner-image { }
.yaa-banner-content { }
.yaa-banner-title { }
.yaa-banner-price { }
.yaa-banner-cta { }

/* Minimal-Style */
.yaa-banner-minimal-item { }
.yaa-banner-minimal-link { }
.yaa-banner-minimal-price { }

/* Aspect Ratios */
.yaa-banner-aspect-1-1 { }
.yaa-banner-aspect-16-9 { }
.yaa-banner-aspect-4-3 { }

/* Scroll-Indikatoren */
.yaa-banner-fade { }
.yaa-banner-fade-left { }
.yaa-banner-fade-right { }
.yaa-banner-arrow { }
.yaa-banner-arrow-left { }
.yaa-banner-arrow-right { }

/* Zustandsklassen (via JS) */
.yaa-banner-container.has-scroll-left { }
.yaa-banner-container.has-scroll-right { }
                    </pre>
                </div>
                
                <h3>CSS Custom Properties (Variablen)</h3>
                <div class="yaa-code-block">
                    <pre>
.yaa-grid-container,
.yaa-banner-container {
    --yaa-primary: #ff00cc;
    --yaa-secondary: #00ffff;
    --yaa-amazon: #ff9900;
    --yaa-custom: #4CAF50;
    --yaa-columns-desktop: 3;
    --yaa-columns-tablet: 2;
    --yaa-columns-mobile: 1;
}

/* Banner-spezifische Variablen */
.yaa-banner-container {
    --yaa-banner-bg: #1a1a2e;
    --yaa-banner-text: #e4e4e7;
    --yaa-banner-text-muted: #a1a1aa;
    --yaa-banner-accent: #6366f1;
    --yaa-banner-border: #27273a;
    --yaa-banner-radius: 8px;
    --yaa-banner-gap: 12px;
}
                    </pre>
                </div>
            </div>
            
            <div id="wp-config" class="yaa-card">
                <h2>⚙️ wp-config.php Konstanten</h2>
                <p>API-Keys können sicher in der <code>wp-config.php</code> definiert werden:</p>
                
                <div class="yaa-code-block">
                    <pre>
// Yadore API
define('YADORE_API_KEY', 'dein-api-key');

// Amazon PA-API 5.0
define('AMAZON_PAAPI_ACCESS_KEY', 'AKIAXXXXXXXXXX');
define('AMAZON_PAAPI_SECRET_KEY', 'dein-secret-key');
define('AMAZON_PAAPI_PARTNER_TAG', 'deinshop-21');

// Redis (optional)
define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_PASSWORD', ''); // Optional
define('WP_REDIS_DATABASE', 0);  // 0-15

// GitHub Token für Plugin-Updates (optional)
define('YAA_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx');
                    </pre>
                </div>
                
                <div class="yaa-tip">
                    <strong>Sicherheitshinweis:</strong> Wenn Konstanten in <code>wp-config.php</code> definiert sind, 
                    werden die Eingabefelder im Admin-Bereich deaktiviert und die Werte dort nicht angezeigt.
                </div>
            </div>
            
            <div class="yaa-card">
                <h2>📊 Shortcode-Attribute Übersicht</h2>
                
                <h3>Allgemeine Attribute (alle Shortcodes)</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Attribut</th>
                            <th>Standard</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>limit</code></td>
                            <td>9/10</td>
                            <td>Max. Anzahl der Produkte</td>
                        </tr>
                        <tr>
                            <td><code>columns</code></td>
                            <td>3</td>
                            <td>Spalten im Grid (1-6)</td>
                        </tr>
                        <tr>
                            <td><code>class</code></td>
                            <td>—</td>
                            <td>Zusätzliche CSS-Klasse</td>
                        </tr>
                        <tr>
                            <td><code>local_images</code></td>
                            <td>Einstellung</td>
                            <td><code>yes</code>/<code>no</code> - Bilder lokal speichern</td>
                        </tr>
                        <tr>
                            <td><code>hide_no_image</code></td>
                            <td>no</td>
                            <td><code>yes</code> - Produkte ohne Bild ausblenden</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Yadore-spezifische Attribute</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Attribut</th>
                            <th>Standard</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>keyword</code> / <code>keywords</code></td>
                            <td>—</td>
                            <td>Suchbegriff(e), Komma-separiert</td>
                        </tr>
                        <tr>
                            <td><code>market</code></td>
                            <td>de</td>
                            <td>Markt (de, at, ch, fr, it, es, nl, be, pl, uk, us, br)</td>
                        </tr>
                        <tr>
                            <td><code>precision</code></td>
                            <td>fuzzy</td>
                            <td><code>fuzzy</code>/<code>strict</code></td>
                        </tr>
                        <tr>
                            <td><code>merchant_whitelist</code></td>
                            <td>—</td>
                            <td>Nur diese Händler (Komma-separiert)</td>
                        </tr>
                        <tr>
                            <td><code>merchant_blacklist</code></td>
                            <td>—</td>
                            <td>Diese Händler ausschließen</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Amazon-spezifische Attribute</h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Attribut</th>
                            <th>Standard</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>keyword</code></td>
                            <td>—</td>
                            <td>Suchbegriff</td>
                        </tr>
                        <tr>
                            <td><code>asins</code></td>
                            <td>—</td>
                            <td>ASINs Komma-separiert (max. 10)</td>
                        </tr>
                        <tr>
                            <td><code>category</code></td>
                            <td>All</td>
                            <td>Amazon SearchIndex</td>
                        </tr>
                        <tr>
                            <td><code>min_price</code> / <code>max_price</code></td>
                            <td>—</td>
                            <td>Preisfilter in Euro</td>
                        </tr>
                        <tr>
                            <td><code>brand</code></td>
                            <td>—</td>
                            <td>Nach Marke filtern</td>
                        </tr>
                        <tr>
                            <td><code>sort</code></td>
                            <td>—</td>
                            <td>Sortierung (Price:LowToHigh, etc.)</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div id="links" class="yaa-card">
                <h2>🔗 Nützliche Links</h2>
                <ul style="list-style: disc; margin-left: 20px; line-height: 2;">
                    <li><a href="https://www.yadore.com/publisher" target="_blank" rel="noopener">Yadore Publisher Portal</a></li>
                    <li><a href="https://api.yadore.com/docs" target="_blank" rel="noopener">Yadore API Dokumentation</a></li>
                    <li><a href="https://webservices.amazon.com/paapi5/documentation/" target="_blank" rel="noopener">Amazon PA-API 5.0 Dokumentation</a></li>
                    <li><a href="https://affiliate-program.amazon.de/" target="_blank" rel="noopener">Amazon PartnerNet (DE)</a></li>
                    <li><a href="https://github.com/matthesv/yadore-amazon-api" target="_blank" rel="noopener">Plugin GitHub Repository</a></li>
                </ul>
            </div>
            
            <div class="yaa-card">
                <h2>📧 Support</h2>
                <p>Bei Fragen oder Problemen:</p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li>GitHub Issues: <a href="https://github.com/matthesv/yadore-amazon-api/issues" target="_blank">github.com/matthesv/yadore-amazon-api/issues</a></li>
                    <li>Plugin Version: <strong><?php echo esc_html(YAA_VERSION); ?></strong></li>
                </ul>
            </div>
        </div>
        
        <style>
            .yaa-badge-new {
                display: inline-block;
                background: linear-gradient(135deg, #6366f1, #8b5cf6);
                color: #fff;
                font-size: 0.7rem;
                font-weight: 600;
                padding: 3px 8px;
                border-radius: 4px;
                margin-left: 8px;
                vertical-align: middle;
                text-transform: uppercase;
            }
        </style>
        <?php
    }
}
