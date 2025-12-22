<?php
/**
 * Admin Documentation Page
 * Dokumentation und Shortcode-Referenz
 * PHP 8.3+ compatible
 * Version: 1.8.0
 * 
 * CHANGELOG 1.8.0:
 * - Vollständige CSS-Klassen-Referenz aus den aktuellen Stylesheets
 * - Search Shortcode Dokumentation ergänzt
 * - CSS Custom Properties für alle Shortcode-Typen dokumentiert
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
                    <a href="#search-shortcode" class="button">🔍 Search (NEU)</a>
                    <a href="#banner-shortcode" class="button">🎯 Banner</a>
                    <a href="#css-classes" class="button">🎨 CSS Klassen</a>
                    <a href="#css-variables" class="button">🔧 CSS Variablen</a>
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
                <div class="yaa-shortcode-box">[yadore_products keyword="Monitor" sort="cpc_desc"]</div>
                
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
            
            <!-- NEU: Search Shortcode Dokumentation -->
            <div id="search-shortcode" class="yaa-card">
                <h2>🔍 Search Shortcode <span class="yaa-badge-new">NEU in 1.8.0</span></h2>
                
                <p>Interaktive Live-Produktsuche mit Filter- und Sortierfunktionen. Perfekt für Produktvergleichsseiten und Affiliate-Portale.</p>
                
                <h3>Grundlegende Verwendung</h3>
                <div class="yaa-shortcode-box">[yadore_search]</div>
                <div class="yaa-shortcode-box">[yaa_search placeholder="Produkt suchen..."]</div>
                <div class="yaa-shortcode-box">[yaa_product_search source="all"]</div>
                
                <h3>Mit Initial-Produkten</h3>
                <div class="yaa-shortcode-box">[yadore_search initial_keywords="Laptop,Smartphone" initial_count="6"]</div>
                <div class="yaa-shortcode-box">[yadore_search show_initial="yes" initial_title="Unsere Empfehlungen"]</div>
                <div class="yaa-shortcode-box">[yadore_search initial_keywords="Bestseller" sort="cpc_desc"]</div>
                
                <h3>Mit Filtern</h3>
                <div class="yaa-shortcode-box">[yadore_search show_filters="yes" show_sort="yes"]</div>
                <div class="yaa-shortcode-box">[yadore_search show_source_filter="yes" show_prime_filter="yes"]</div>
                <div class="yaa-shortcode-box">[yadore_search show_price_filter="yes" min_price="10" max_price="500"]</div>
                
                <h3>Layout-Optionen</h3>
                <div class="yaa-shortcode-box">[yadore_search columns="4" columns_tablet="2" columns_mobile="1"]</div>
                <div class="yaa-shortcode-box">[yadore_search max_results="24" load_more="yes"]</div>
                
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
                            <td><code>source</code></td>
                            <td>yadore</td>
                            <td>Produktquelle: <code>yadore</code>, <code>amazon</code>, <code>all</code></td>
                        </tr>
                        <tr>
                            <td><code>placeholder</code></td>
                            <td>Produkt suchen...</td>
                            <td>Platzhalter-Text im Suchfeld</td>
                        </tr>
                        <tr>
                            <td><code>button_text</code></td>
                            <td>Suchen</td>
                            <td>Text des Such-Buttons</td>
                        </tr>
                        <tr>
                            <td><code>max_results</code></td>
                            <td>12</td>
                            <td>Max. Ergebnisse pro Seite</td>
                        </tr>
                        <tr>
                            <td><code>columns</code></td>
                            <td>3</td>
                            <td>Spalten (Desktop)</td>
                        </tr>
                        <tr>
                            <td><code>columns_tablet</code></td>
                            <td>2</td>
                            <td>Spalten (Tablet)</td>
                        </tr>
                        <tr>
                            <td><code>columns_mobile</code></td>
                            <td>1</td>
                            <td>Spalten (Mobile)</td>
                        </tr>
                        <tr>
                            <td><code>show_filters</code></td>
                            <td>no</td>
                            <td>Filter-Leiste anzeigen</td>
                        </tr>
                        <tr>
                            <td><code>show_sort</code></td>
                            <td>yes</td>
                            <td>Sortierung anzeigen</td>
                        </tr>
                        <tr>
                            <td><code>show_source_filter</code></td>
                            <td>no</td>
                            <td>Quellen-Filter (Yadore/Amazon)</td>
                        </tr>
                        <tr>
                            <td><code>show_prime_filter</code></td>
                            <td>no</td>
                            <td>Prime-Filter (Amazon)</td>
                        </tr>
                        <tr>
                            <td><code>show_price_filter</code></td>
                            <td>no</td>
                            <td>Preis-Filter</td>
                        </tr>
                        <tr>
                            <td><code>sort</code></td>
                            <td>rel_desc</td>
                            <td>Standard-Sortierung</td>
                        </tr>
                        <tr>
                            <td><code>show_initial</code></td>
                            <td>no</td>
                            <td>Initial-Produkte anzeigen</td>
                        </tr>
                        <tr>
                            <td><code>initial_keywords</code></td>
                            <td>—</td>
                            <td>Keywords für Initial-Produkte</td>
                        </tr>
                        <tr>
                            <td><code>initial_count</code></td>
                            <td>6</td>
                            <td>Anzahl Initial-Produkte</td>
                        </tr>
                        <tr>
                            <td><code>initial_title</code></td>
                            <td>Empfohlene Produkte</td>
                            <td>Überschrift für Initial-Bereich</td>
                        </tr>
                        <tr>
                            <td><code>load_more</code></td>
                            <td>yes</td>
                            <td>"Mehr laden" Button</td>
                        </tr>
                        <tr>
                            <td><code>market</code></td>
                            <td>Plugin-Standard</td>
                            <td>Yadore Market-Code</td>
                        </tr>
                        <tr>
                            <td><code>category</code></td>
                            <td>All</td>
                            <td>Amazon SearchIndex</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Sortier-Optionen</h3>
                <table class="widefat" style="margin: 15px 0;">
                    <thead>
                        <tr>
                            <th>Wert</th>
                            <th>Beschreibung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>rel_desc</code></td>
                            <td>Nach Relevanz (Standard)</td>
                        </tr>
                        <tr>
                            <td><code>price_asc</code></td>
                            <td>Preis aufsteigend (günstigste zuerst)</td>
                        </tr>
                        <tr>
                            <td><code>price_desc</code></td>
                            <td>Preis absteigend (teuerste zuerst)</td>
                        </tr>
                        <tr style="background: #e8f5e9;">
                            <td><code>cpc_desc</code></td>
                            <td><strong>💰 Höchste Vergütung zuerst</strong></td>
                        </tr>
                        <tr>
                            <td><code>cpc_asc</code></td>
                            <td>Niedrigste Vergütung zuerst</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="yaa-feature-box info">
                    <h4 style="margin-top: 0;">💡 Tipp: Maximale Einnahmen</h4>
                    <p>Mit <code>sort="cpc_desc"</code> werden Produkte mit der höchsten Vergütung pro Klick zuerst angezeigt – sowohl bei Initial-Produkten als auch bei Suchergebnissen!</p>
                </div>
            </div>
            
            <!-- Banner Shortcode Dokumentation -->
            <div id="banner-shortcode" class="yaa-card">
                <h2>🎯 Banner Shortcode</h2>
                
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
            
            <!-- CSS Klassen Referenz -->
            <div id="css-classes" class="yaa-card">
                <h2>🎨 CSS Klassen Referenz</h2>
                <p>Vollständige Liste aller CSS-Klassen für Custom Styling. Diese Klassen können im <strong>Custom CSS Tab</strong> überschrieben werden.</p>
                
                <!-- Grid Klassen -->
                <h3>📦 Grid-Shortcodes</h3>
                <p class="description">Für <code>[yadore_products]</code>, <code>[amazon_products]</code>, <code>[custom_products]</code>, etc.</p>
                
                <div class="yaa-css-section">
                    <h4>Container & Items</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Haupt-Container */
.yaa-grid-container { }

/* Einzelnes Produkt-Element */
.yaa-item { }
.yaa-item:nth-child(even) { }   /* Alternierende Farben */

/* Quellen-spezifische Items */
.yaa-item.yaa-amazon { }        /* Amazon Produkt */
.yaa-item.yaa-custom { }        /* Eigenes Produkt */
.yaa-item.yaa-yadore { }        /* Yadore Produkt */

/* Hover-Effekte */
.yaa-item:hover { }</pre>
                    </div>
                    
                    <h4>Badges</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Prime Badge (Amazon) */
.yaa-prime-badge { }

/* Custom Badge (Eigene Produkte) */
.yaa-custom-badge { }</pre>
                    </div>
                    
                    <h4>Bilder</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Bild-Wrapper */
.yaa-image-wrapper { }
.yaa-image-wrapper a { }
.yaa-image-wrapper img { }

/* Bild-Zustände */
.yaa-image-wrapper img.yaa-img-loaded { }
.yaa-image-wrapper img.yaa-img-loading { }
.yaa-image-wrapper.yaa-image-error { }

/* Platzhalter für fehlende Bilder */
.yaa-placeholder { }
.yaa-placeholder-amazon { }
.yaa-placeholder-custom { }
.yaa-placeholder-yadore { }
.yaa-no-image { }

/* Shop-Logo Fallback */
.yaa-image-wrapper img.yaa-shop-logo-fallback { }</pre>
                    </div>
                    
                    <h4>Inhalt</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Content-Container */
.yaa-content { }

/* Titel */
.yaa-title { }
.yaa-title a { }
.yaa-title a:hover { }

/* Beschreibung */
.yaa-description-wrapper { }
.yaa-description { }
.yaa-description::after { }           /* Fade-Out Gradient */
.yaa-description.expanded { }
.yaa-no-description { }

/* Mehr lesen Button */
.yaa-read-more { }
.yaa-read-more::after { }             /* Pfeil-Icon */
.yaa-read-more.expanded { }
.yaa-read-more.expanded::after { }</pre>
                    </div>
                    
                    <h4>Preis & Meta</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Meta-Container */
.yaa-meta { }

/* Preis */
.yaa-price { }
.yaa-item.yaa-amazon .yaa-price { }
.yaa-item.yaa-custom .yaa-price { }

/* Händler */
.yaa-merchant { }
.yaa-merchant::before { }             /* "via " Prefix */</pre>
                    </div>
                    
                    <h4>Buttons</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Button-Wrapper */
.yaa-button-wrapper { }

/* CTA Button */
.yaa-button { }
.yaa-button:hover { }

/* Quellen-spezifische Button-Farben */
.yaa-item:nth-child(even) .yaa-button { }
.yaa-item.yaa-amazon .yaa-button { }
.yaa-item.yaa-custom .yaa-button { }</pre>
                    </div>
                    
                    <h4>Status-Meldungen</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Meldungs-Container */
.yaa-message { }
.yaa-message .yaa-icon { }
.yaa-message p { }

/* Meldungs-Typen */
.yaa-error { }
.yaa-empty { }

/* Lade-Animation */
.yaa-loading { }
.yaa-spinner { }</pre>
                    </div>
                    
                    <h4>Theme-Varianten</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Light Mode (manuell) */
.yaa-grid-container.yaa-theme-light { }
.yaa-grid-container.yaa-theme-light .yaa-item { }
.yaa-grid-container.yaa-theme-light .yaa-description::after { }
.yaa-grid-container.yaa-theme-light .yaa-placeholder { }

/* Light Mode (Auto via prefers-color-scheme) */
.yaa-grid-container.yaa-light-mode { }</pre>
                    </div>
                </div>
                
                <!-- Banner Klassen -->
                <h3>🎯 Banner-Shortcode</h3>
                <p class="description">Für <code>[yaa_banner]</code></p>
                
                <div class="yaa-css-section">
                    <h4>Container & Wrapper</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Haupt-Container */
.yaa-banner-container { }

/* Scroll-Wrapper */
.yaa-banner-scroll-wrapper { }
.yaa-banner-scroll-wrapper::-webkit-scrollbar { }

/* Track (Flex-Container) */
.yaa-banner-track { }</pre>
                    </div>
                    
                    <h4>Style-Varianten</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Horizontal Style (Standard) */
.yaa-banner-horizontal { }
.yaa-banner-horizontal .yaa-banner-item { }
.yaa-banner-horizontal .yaa-banner-image { }
.yaa-banner-horizontal .yaa-banner-content { }
.yaa-banner-horizontal .yaa-banner-title { }
.yaa-banner-horizontal .yaa-banner-price { }
.yaa-banner-horizontal .yaa-banner-cta { }

/* Compact Style */
.yaa-banner-compact { }
.yaa-banner-compact .yaa-banner-item { }
.yaa-banner-compact .yaa-banner-image { }
.yaa-banner-compact .yaa-banner-content { }
.yaa-banner-compact .yaa-banner-title { }
.yaa-banner-compact .yaa-banner-price { }

/* Minimal Style (Text-Links) */
.yaa-banner-minimal { }
.yaa-banner-minimal .yaa-banner-track { }
.yaa-banner-minimal-item { }
.yaa-banner-minimal-link { }
.yaa-banner-minimal-link:hover { }
.yaa-banner-minimal-price { }</pre>
                    </div>
                    
                    <h4>Banner Items</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Einzelnes Item */
.yaa-banner-item { }
.yaa-banner-item:hover { }

/* Quellen-Markierung (linker Rand) */
.yaa-banner-item.yaa-yadore { }       /* Grün */
.yaa-banner-item.yaa-amazon { }       /* Orange */
.yaa-banner-item.yaa-custom { }       /* Lila */

/* Item-Komponenten */
.yaa-banner-link { }
.yaa-banner-image { }
.yaa-banner-image img { }
.yaa-banner-content { }
.yaa-banner-title { }
.yaa-banner-price { }
.yaa-banner-cta { }</pre>
                    </div>
                    
                    <h4>Aspect Ratios</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Bild-Seitenverhältnisse */
.yaa-banner-aspect-1-1 .yaa-banner-image { }
.yaa-banner-aspect-16-9 .yaa-banner-image { }
.yaa-banner-aspect-4-3 .yaa-banner-image { }</pre>
                    </div>
                    
                    <h4>Scroll-Indikatoren</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Fade-Out Effekte */
.yaa-banner-fade { }
.yaa-banner-fade-left { }
.yaa-banner-fade-right { }

/* Navigation Pfeile */
.yaa-banner-arrow { }
.yaa-banner-arrow-left { }
.yaa-banner-arrow-right { }
.yaa-banner-arrow:hover { }
.yaa-banner-arrow svg { }

/* Zustands-Klassen (via JavaScript) */
.yaa-banner-container.has-scroll-left { }
.yaa-banner-container.has-scroll-right { }
.yaa-banner-container.has-scroll-left .yaa-banner-fade-left { }
.yaa-banner-container.has-scroll-right .yaa-banner-fade-right { }
.yaa-banner-container.has-scroll-left .yaa-banner-arrow-left { }
.yaa-banner-container.has-scroll-right .yaa-banner-arrow-right { }</pre>
                    </div>
                    
                    <h4>Fehler-Meldung</h4>
                    <div class="yaa-code-block">
                        <pre>
.yaa-banner-error { }</pre>
                    </div>
                </div>
                
                <!-- Search Klassen -->
                <h3>🔍 Search-Shortcode</h3>
                <p class="description">Für <code>[yadore_search]</code>, <code>[yaa_search]</code></p>
                
                <div class="yaa-css-section">
                    <h4>Haupt-Wrapper</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Wrapper */
.yaa-search-wrapper { }
.yaa-search-wrapper.yaa-is-loading { }</pre>
                    </div>
                    
                    <h4>Suchformular</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Formular */
.yaa-search-form { }
.yaa-search-input-wrapper { }

/* Eingabefeld */
.yaa-search-input { }
.yaa-search-input:focus { }
.yaa-search-input::placeholder { }

/* Clear Button */
.yaa-search-clear { }
.yaa-search-clear:hover { }

/* Such-Button */
.yaa-search-button { }
.yaa-search-button:hover:not(:disabled) { }
.yaa-search-button:active:not(:disabled) { }
.yaa-search-button:disabled { }
.yaa-search-button-spinner { }
.yaa-search-button-text { }

/* Button-Varianten */
.yaa-button-secondary { }
.yaa-button-outline { }</pre>
                    </div>
                    
                    <h4>Filter</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Filter-Container */
.yaa-search-filters { }
.yaa-filters-row { }
.yaa-filter-label { }

/* Filter-Wrapper */
.yaa-sort-wrapper { }
.yaa-source-wrapper { }
.yaa-prime-wrapper { }

/* Dropdowns */
.yaa-sort-select { }
.yaa-source-select { }
.yaa-sort-select:focus { }

/* Checkboxen */
.yaa-checkbox-label { }
.yaa-prime-checkbox { }
.yaa-prime-badge { }

/* Erweiterte Filter */
.yaa-advanced-filters { }
.yaa-toggle-filters { }
.yaa-toggle-filters[aria-expanded="true"] { }
.yaa-toggle-icon { }
.yaa-advanced-filters-content { }

/* Preis- & Rating-Filter */
.yaa-price-filter { }
.yaa-rating-filter { }
.yaa-min-price { }
.yaa-max-price { }
.yaa-price-separator { }
.yaa-min-rating-select { }</pre>
                    </div>
                    
                    <h4>Suchvorschläge</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Vorschläge-Dropdown */
.yaa-search-suggestions { }
.yaa-suggestion-item { }
.yaa-suggestion-item:hover { }
.yaa-suggestion-item.active { }
.yaa-suggestion-icon { }
.yaa-suggestion-text { }</pre>
                    </div>
                    
                    <h4>Ergebnis-Info & Status</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Ergebnis-Info */
.yaa-search-results-info { }
.yaa-search-results-count { }
.yaa-search-results-query { }

/* Status-Meldungen */
.yaa-status { }
.yaa-status-loading { }
.yaa-status-error { }
.yaa-status-empty { }
.yaa-status-info { }

/* Lade-Animation */
.yaa-search-loading { }
.yaa-search-loading .yaa-spinner { }</pre>
                    </div>
                    
                    <h4>Ergebnis-Grid</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Grid-Container */
.yaa-search-results { }
.yaa-search-results-inner { }

/* Spalten-Varianten */
.yaa-columns-1 .yaa-search-results-inner { }
.yaa-columns-2 .yaa-search-results-inner { }
.yaa-columns-3 .yaa-search-results-inner { }
.yaa-columns-4 .yaa-search-results-inner { }
.yaa-columns-5 .yaa-search-results-inner { }
.yaa-columns-6 .yaa-search-results-inner { }

/* Responsive Spalten */
.yaa-columns-tablet-1 .yaa-search-results-inner { }
.yaa-columns-tablet-2 .yaa-search-results-inner { }
.yaa-columns-tablet-3 .yaa-search-results-inner { }
.yaa-columns-mobile-1 .yaa-search-results-inner { }
.yaa-columns-mobile-2 .yaa-search-results-inner { }</pre>
                    </div>
                    
                    <h4>Produkt-Karten</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Karte */
.yaa-product-card { }
.yaa-product-card:hover { }
.yaa-product-card:focus { }
.yaa-product-link { }

/* Bild */
.yaa-product-image-wrapper { }
.yaa-product-image { }
.yaa-product-no-image { }

/* Badges */
.yaa-prime-badge { }
.yaa-discount-badge { }
.yaa-source-badge { }
.yaa-source-yadore { }
.yaa-source-amazon { }

/* Inhalt */
.yaa-product-content { }
.yaa-product-title { }
.yaa-product-title a { }

/* Bewertung */
.yaa-product-rating { }
.yaa-stars { }
.yaa-reviews-count { }

/* Beschreibung */
.yaa-product-description { }

/* Preis */
.yaa-product-price-wrapper { }
.yaa-product-price { }
.yaa-product-price-old { }

/* Verfügbarkeit */
.yaa-product-availability { }
.yaa-availability-in-stock { }
.yaa-availability-low-stock { }
.yaa-availability-out-of-stock { }

/* Sponsored Label */
.yaa-sponsored-label { }

/* Aktionen */
.yaa-product-actions { }
.yaa-product-button { }
.yaa-product-button:hover { }
.yaa-external-icon { }</pre>
                    </div>
                    
                    <h4>Keine Ergebnisse / Fehler</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Keine Ergebnisse */
.yaa-no-results { }
.yaa-no-results-icon { }
.yaa-no-results h3 { }
.yaa-no-results p { }

/* Fehler */
.yaa-error { }
.yaa-error-icon { }
.yaa-error h3 { }
.yaa-error p { }

/* Retry Button */
.yaa-retry-button { }
.yaa-retry-button:hover { }</pre>
                    </div>
                    
                    <h4>Pagination</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Pagination-Container */
.yaa-search-pagination { }

/* Mehr laden Button */
.yaa-load-more { }
.yaa-load-more:disabled { }
.yaa-load-more-spinner { }
.yaa-load-more-text { }

/* Info */
.yaa-pagination-info { }

/* Reset Button */
.yaa-reset-button { }
.yaa-reset-button:hover { }</pre>
                    </div>
                    
                    <h4>Hinweise</h4>
                    <div class="yaa-code-block">
                        <pre>
/* Notices */
.yaa-notice { }
.yaa-notice-warning { }
.yaa-notice a { }</pre>
                    </div>
                </div>
                
                <!-- Accessibility -->
                <h3>♿ Barrierefreiheit</h3>
                <div class="yaa-code-block">
                    <pre>
/* Screen Reader Only */
.screen-reader-text { }

/* Focus Visible */
.yaa-search-input:focus-visible { }
.yaa-search-button:focus-visible { }
.yaa-sort-select:focus-visible { }
.yaa-product-card:focus-visible { }
.yaa-button:focus { }
.yaa-read-more:focus { }
.yaa-title a:focus { }</pre>
                </div>
            </div>
            
            <!-- CSS Variablen -->
            <div id="css-variables" class="yaa-card">
                <h2>🔧 CSS Custom Properties (Variablen)</h2>
                <p>Diese CSS-Variablen können im Custom CSS überschrieben werden, um das gesamte Farbschema anzupassen.</p>
                
                <h3>📦 Grid-Variablen</h3>
                <div class="yaa-code-block">
                    <pre>
.yaa-grid-container {
    /* Farben */
    --yaa-primary: #ff00cc;           /* Yadore Primärfarbe */
    --yaa-secondary: #00ffff;         /* Sekundärfarbe */
    --yaa-amazon: #ff9900;            /* Amazon Orange */
    --yaa-custom: #4CAF50;            /* Eigene Produkte Grün */
    
    /* Hintergrund & Borders (Dark Theme) */
    --yaa-dark-bg: #0a0a0a;
    --yaa-glass-bg: rgba(255, 255, 255, 0.05);
    --yaa-glass-border: rgba(255, 255, 255, 0.1);
    
    /* Text */
    --yaa-text-main: #ffffff;
    --yaa-text-muted: #aaaaaa;
    
    /* Grid-Spalten */
    --yaa-columns-desktop: 3;
    --yaa-columns-tablet: 2;
    --yaa-columns-mobile: 1;
}</pre>
                </div>
                
                <h3>🎯 Banner-Variablen</h3>
                <div class="yaa-code-block">
                    <pre>
.yaa-banner-container {
    /* Farben (nutzen Theme-Variablen als Fallback) */
    --yaa-banner-bg: var(--yaa-card-bg, #1a1a2e);
    --yaa-banner-text: var(--yaa-text-color, #e4e4e7);
    --yaa-banner-text-muted: var(--yaa-text-muted, #a1a1aa);
    --yaa-banner-accent: var(--yaa-accent-color, #6366f1);
    --yaa-banner-border: var(--yaa-border-color, #27273a);
    
    /* Layout */
    --yaa-banner-radius: 8px;
    --yaa-banner-gap: 12px;
}</pre>
                </div>
                
                <h3>🔍 Search-Variablen</h3>
                <div class="yaa-code-block">
                    <pre>
.yaa-search-wrapper {
    /* Primärfarben */
    --yaa-primary: #2563eb;
    --yaa-primary-hover: #1d4ed8;
    --yaa-primary-light: #dbeafe;
    
    /* Text */
    --yaa-text: #1f2937;
    --yaa-text-muted: #6b7280;
    --yaa-text-light: #9ca3af;
    
    /* Borders */
    --yaa-border: #e5e7eb;
    --yaa-border-focus: #2563eb;
    
    /* Hintergründe */
    --yaa-bg: #ffffff;
    --yaa-bg-hover: #f9fafb;
    --yaa-bg-alt: #f3f4f6;
    
    /* Status-Farben */
    --yaa-success: #10b981;
    --yaa-warning: #f59e0b;
    --yaa-error: #ef4444;
    --yaa-prime: #00a8e1;
    
    /* Radien */
    --yaa-radius: 8px;
    --yaa-radius-sm: 4px;
    --yaa-radius-lg: 12px;
    
    /* Schatten */
    --yaa-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    --yaa-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --yaa-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    
    /* Animation */
    --yaa-transition: 0.2s ease;
    
    /* Schriftart */
    --yaa-font: -apple-system, BlinkMacSystemFont, "Segoe UI", 
                Roboto, "Helvetica Neue", Arial, sans-serif;
}</pre>
                </div>
                
                <h3>🎨 Beispiel: Farben anpassen</h3>
                <div class="yaa-code-block">
                    <pre>
/* Im Custom CSS Tab oder Theme */
.yaa-grid-container {
    --yaa-primary: #e91e63;       /* Pink statt Magenta */
    --yaa-secondary: #03a9f4;     /* Hellblau statt Cyan */
}

.yaa-search-wrapper {
    --yaa-primary: #7c3aed;       /* Violett */
    --yaa-primary-hover: #6d28d9;
}

.yaa-banner-container {
    --yaa-banner-accent: #10b981; /* Grün */
}</pre>
                </div>
                
                <div class="yaa-tip">
                    <strong>💡 Tipp:</strong> Nutze die Browser-Entwicklertools (F12 → Elements → Styles), 
                    um CSS-Variablen live zu testen, bevor du sie im Custom CSS Tab speicherst.
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
define('YAA_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxx');</pre>
                </div>
                
                <div class="yaa-tip">
                    <strong>🔒 Sicherheitshinweis:</strong> Wenn Konstanten in <code>wp-config.php</code> definiert sind, 
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
                        <tr>
                            <td><code>sort</code></td>
                            <td>rel_desc</td>
                            <td>Sortierung (rel_desc, price_asc, price_desc, cpc_desc, cpc_asc)</td>
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
            
            .yaa-css-section {
                margin-bottom: 30px;
            }
            
            .yaa-css-section h4 {
                margin: 20px 0 10px 0;
                padding-bottom: 5px;
                border-bottom: 1px solid #ddd;
                color: #23282d;
            }
            
            .yaa-code-block {
                background: #1e1e1e;
                border-radius: 6px;
                overflow: hidden;
                margin: 10px 0;
            }
            
            .yaa-code-block pre {
                color: #d4d4d4;
                font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
                font-size: 12px;
                line-height: 1.5;
                padding: 15px 20px;
                margin: 0;
                overflow-x: auto;
                white-space: pre;
            }
            
            @media (max-width: 782px) {
                .yaa-code-block pre {
                    font-size: 11px;
                    padding: 10px 15px;
                }
            }
        </style>
        <?php
    }
}
