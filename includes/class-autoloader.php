<?php
/**
 * PSR-4 Compatible Autoloader for Yadore-Amazon-API Plugin
 * PHP 8.1+ compatible
 * Version: 1.4.0
 *
 * Lädt Plugin-Klassen automatisch basierend auf Klassennamen.
 * Unterstützt das Namensschema: YAA_Class_Name -> class-class-name.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Autoloader {
    
    /**
     * Basis-Verzeichnis für Klassen
     */
    private static string $base_dir = '';
    
    /**
     * Ob der Autoloader bereits registriert wurde
     */
    private static bool $registered = false;
    
    /**
     * Mapping von Klassennamen zu Dateipfaden (für Sonderfälle)
     * @var array<string, string>
     */
    private static array $class_map = [];
    
    /**
     * Verzeichnisse in denen nach Klassen gesucht wird
     * @var array<string>
     */
    private static array $directories = [
        'includes',
        'includes/admin',
    ];
    
    /**
     * Registriert den Autoloader
     *
     * @param string $base_dir Basis-Plugin-Verzeichnis
     * @return bool True wenn erfolgreich registriert
     */
    public static function register(string $base_dir): bool {
        if (self::$registered) {
            return true;
        }
        
        self::$base_dir = rtrim($base_dir, '/\\');
        
        // Admin-Verzeichnis erstellen falls nicht vorhanden
        $admin_dir = self::$base_dir . '/includes/admin';
        if (!is_dir($admin_dir)) {
            wp_mkdir_p($admin_dir);
        }
        
        // Spezielle Klassen-Mappings (falls Dateiname nicht dem Standard entspricht)
        self::$class_map = [
            // Beispiel: 'YAA_Special_Class' => 'includes/special/class-special.php',
        ];
        
        $result = spl_autoload_register([self::class, 'autoload'], true, true);
        
        if ($result) {
            self::$registered = true;
        }
        
        return $result;
    }
    
    /**
     * Deregistriert den Autoloader
     */
    public static function unregister(): bool {
        if (!self::$registered) {
            return true;
        }
        
        $result = spl_autoload_unregister([self::class, 'autoload']);
        
        if ($result) {
            self::$registered = false;
        }
        
        return $result;
    }
    
    /**
     * Autoload-Callback Funktion
     *
     * @param string $class_name Vollständiger Klassenname
     */
    public static function autoload(string $class_name): void {
        // Nur YAA_ Klassen laden
        if (!str_starts_with($class_name, 'YAA_')) {
            return;
        }
        
        // 1. Prüfen ob spezielles Mapping existiert
        if (isset(self::$class_map[$class_name])) {
            $file = self::$base_dir . '/' . self::$class_map[$class_name];
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        
        // 2. Dateinamen aus Klassennamen generieren
        // YAA_Admin_Settings -> class-admin-settings.php
        // YAA_Cache_Handler -> class-cache-handler.php
        $file_name = self::class_to_filename($class_name);
        
        // 3. In allen registrierten Verzeichnissen suchen
        foreach (self::$directories as $directory) {
            $file = self::$base_dir . '/' . $directory . '/' . $file_name;
            
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        
        // 4. Fallback: Direkt im includes Verzeichnis
        $file = self::$base_dir . '/includes/' . $file_name;
        if (file_exists($file)) {
            require_once $file;
        }
    }
    
    /**
     * Konvertiert Klassennamen zu Dateinamen
     *
     * YAA_Admin_Settings -> class-admin-settings.php
     * YAA_Cache_Handler -> class-cache-handler.php
     * YAA_Amazon_PAAPI -> class-amazon-paapi.php
     *
     * @param string $class_name
     * @return string
     */
    private static function class_to_filename(string $class_name): string {
        // Prefix entfernen
        $name = str_replace('YAA_', '', $class_name);
        
        // CamelCase/PascalCase zu kebab-case konvertieren
        // Aber Akronyme wie PAAPI, API zusammenhalten
        $name = preg_replace('/([a-z])([A-Z])/', '$1-$2', $name) ?? $name;
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $name) ?? $name;
        
        // Unterstriche zu Bindestrichen
        $name = str_replace('_', '-', $name);
        
        // Lowercase
        $name = strtolower($name);
        
        // Doppelte Bindestriche entfernen
        $name = preg_replace('/-+/', '-', $name) ?? $name;
        
        return 'class-' . trim($name, '-') . '.php';
    }
    
    /**
     * Fügt ein Verzeichnis zur Suche hinzu
     *
     * @param string $directory Relativ zum Base-Dir
     */
    public static function add_directory(string $directory): void {
        $directory = trim($directory, '/\\');
        
        if (!in_array($directory, self::$directories, true)) {
            self::$directories[] = $directory;
        }
    }
    
    /**
     * Fügt ein Klassen-Mapping hinzu
     *
     * @param string $class_name Vollständiger Klassenname
     * @param string $file_path Relativer Pfad zur Datei
     */
    public static function add_class_map(string $class_name, string $file_path): void {
        self::$class_map[$class_name] = $file_path;
    }
    
    /**
     * Prüft ob eine Klasse durch den Autoloader geladen werden kann
     *
     * @param string $class_name
     * @return bool
     */
    public static function can_load(string $class_name): bool {
        if (!str_starts_with($class_name, 'YAA_')) {
            return false;
        }
        
        // Spezielles Mapping prüfen
        if (isset(self::$class_map[$class_name])) {
            return file_exists(self::$base_dir . '/' . self::$class_map[$class_name]);
        }
        
        // Dateinamen generieren und prüfen
        $file_name = self::class_to_filename($class_name);
        
        foreach (self::$directories as $directory) {
            $file = self::$base_dir . '/' . $directory . '/' . $file_name;
            if (file_exists($file)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Gibt alle registrierten Verzeichnisse zurück
     *
     * @return array<string>
     */
    public static function get_directories(): array {
        return self::$directories;
    }
    
    /**
     * Debug: Zeigt welche Datei für eine Klasse geladen würde
     *
     * @param string $class_name
     * @return string|null Dateipfad oder null
     */
    public static function resolve_class(string $class_name): ?string {
        if (!str_starts_with($class_name, 'YAA_')) {
            return null;
        }
        
        if (isset(self::$class_map[$class_name])) {
            $file = self::$base_dir . '/' . self::$class_map[$class_name];
            return file_exists($file) ? $file : null;
        }
        
        $file_name = self::class_to_filename($class_name);
        
        foreach (self::$directories as $directory) {
            $file = self::$base_dir . '/' . $directory . '/' . $file_name;
            if (file_exists($file)) {
                return $file;
            }
        }
        
        return null;
    }
}
