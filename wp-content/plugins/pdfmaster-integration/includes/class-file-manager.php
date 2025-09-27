<?php
/**
 * Klasa PDFMaster_File_Manager
 * 
 * Ta klasa jest odpowiedzialna za bezpieczne i wydajne zarządzanie wszystkimi
 * plikami w naszej aplikacji PDFMaster. Obsługuje pełny cykl życia pliku:
 * od momentu przesłania przez użytkownika, przez tymczasowe przechowywanie,
 * przetwarzanie, udostępnianie wyników, aż po bezpieczne usunięcie.
 * 
 * Kluczowe funkcjonalności:
 * 1. Bezpieczne przechowywanie plików poza web-accessible directory
 * 2. Generowanie unikalnych, nieprzewidywalnych nazw plików
 * 3. Kontrolowany dostęp do plików przez specjalne endpoint
 * 4. Automatyczne czyszczenie starych plików
 * 5. Monitoring wykorzystania przestrzeni dyskowej
 * 6. Optymalizacja wydajności operacji na plikach
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit('Bezpośredni dostęp zabroniony.');
}

class PDFMaster_File_Manager {
    
    /**
     * Konfiguracja katalogów i limitów
     * Te wartości można dostosować w zależności od potrzeb serwera
     */
    private $base_upload_dir;      // Główny katalog upload WordPress
    private $plugin_upload_dir;    // Katalog dedykowany dla naszego plugin
    private $temp_dir;            // Katalog dla plików tymczasowych
    private $processed_dir;       // Katalog dla przetworzonych plików
    private $max_file_size;       // Maksymalny rozmiar pliku w bajtach
    private $file_retention_time; // Czas przechowywania plików w sekundach
    private $allowed_mime_types;  // Dozwolone typy MIME
    
    /**
     * Statystyki i monitoring
     */
    private $operation_stats;
    
    /**
     * Konstruktor klasy
     * Inicjalizuje wszystkie katalogi i ustawienia zarządzania plikami
     */
    public function __construct() {
        // Pobranie standardowego katalogu upload WordPress
        $wp_upload_dir = wp_upload_dir();
        $this->base_upload_dir = $wp_upload_dir['basedir'];
        
        // Konfiguracja katalogów specyficznych dla naszego plugin
        $this->plugin_upload_dir = $this->base_upload_dir . '/pdfmaster';
        $this->temp_dir = $this->plugin_upload_dir . '/temp';
        $this->processed_dir = $this->plugin_upload_dir . '/processed';
        
        // Ustawienia limitów i konfiguracji
        $this->max_file_size = 50 * 1024 * 1024; // 50MB
        $this->file_retention_time = 2 * HOUR_IN_SECONDS; // 2 godziny
        $this->allowed_mime_types = array('application/pdf');
        
        // Inicjalizacja statystyk
        $this->operation_stats = array();
        
        // Utworzenie niezbędnych katalogów przy pierwszym uruchomieniu
        $this->ensure_directories_exist();
        
        // Zabezpieczenie katalogów przed bezpośrednim dostępem
        $this->secure_directories();
        
        // Rejestracja zadań automatycznego czyszczenia
        $this->setup_cleanup_schedule();
        
        // Rejestracja endpoint do pobierania plików - CRITICAL: Early priority
        add_action('init', array($this, 'register_download_endpoint'), 5);
        add_action('template_redirect', array($this, 'handle_download_request'));

        // Force query vars registration at the very beginning
        add_action('plugins_loaded', array($this, 'force_register_query_vars'), 1);
    }
    
    /**
     * Tworzenie struktury katalogów
     * Sprawdza czy wszystkie potrzebne katalogi istnieją i tworzy je w razie potrzeby
     */
    private function ensure_directories_exist() {
        $directories = array(
            $this->plugin_upload_dir,
            $this->temp_dir,
            $this->processed_dir
        );
        
        foreach ($directories as $directory) {
            if (!file_exists($directory)) {
                // Utworzenie katalogu z odpowiednimi uprawnieniami
                $created = wp_mkdir_p($directory);
                
                if (!$created) {
                    error_log("PDFMaster: Nie udało się utworzyć katalogu: {$directory}");
                    throw new Exception("Błąd konfiguracji serwera - nie można utworzyć katalogów roboczych.");
                }
                
                // Ustawienie odpowiednich uprawnień (bezpieczne dla serwera www)
                chmod($directory, 0755);
            }
        }
    }
    
    /**
     * Zabezpieczenie katalogów przed bezpośrednim dostępem
     * Tworzy pliki .htaccess i index.php blokujące dostęp z przeglądarki
     */
    private function secure_directories() {
        $directories = array($this->temp_dir, $this->processed_dir);
        
        foreach ($directories as $directory) {
            // Tworzenie pliku .htaccess blokującego dostęp
            $htaccess_file = $directory . '/.htaccess';
            if (!file_exists($htaccess_file)) {
                $htaccess_content = "# PDFMaster Security\n";
                $htaccess_content .= "Order deny,allow\n";
                $htaccess_content .= "Deny from all\n";
                $htaccess_content .= "<Files ~ \"\\.(php|html|htm|js)$\">\n";
                $htaccess_content .= "    Deny from all\n";
                $htaccess_content .= "</Files>\n";
                
                file_put_contents($htaccess_file, $htaccess_content);
            }
            
            // Tworzenie pustego pliku index.php zapobiegającego listowaniu katalogów
            $index_file = $directory . '/index.php';
            if (!file_exists($index_file)) {
                file_put_contents($index_file, '<?php // Silence is golden.');
            }
        }
    }
    
    /**
     * Konfiguracja automatycznego czyszczenia plików
     * Używa WordPress Cron do regularnego usuwania starych plików
     */
    private function setup_cleanup_schedule() {
        // Sprawdzenie czy zadanie cron jest już zaplanowane
        if (!wp_next_scheduled('pdfmaster_cleanup_files')) {
            // Zaplanowanie czyszczenia co godzinę
            wp_schedule_event(time(), 'hourly', 'pdfmaster_cleanup_files');
        }
        
        // Rejestracja funkcji wykonującej czyszczenie
        add_action('pdfmaster_cleanup_files', array($this, 'cleanup_old_files'));
        
        // Dodatkowe czyszczenie przy dezaktywacji plugin
        register_deactivation_hook(PDFMASTER_PLUGIN_PATH . 'pdfmaster-integration.php', array($this, 'cleanup_all_files'));
    }
    
    /**
     * Zapisywanie pliku tymczasowego
     * Przyjmuje dane pliku z $_FILES i zapisuje go w bezpiecznej lokalizacji
     * 
     * @param array $file_data Tablica danych pliku z $_FILES
     * @return string|false Ścieżka do zapisanego pliku lub false przy błędzie
     */
    public function save_temp_file($file_data) {
        try {
            // Generowanie unikalnej nazwy pliku
            $unique_filename = $this->generate_unique_filename($file_data['name'], 'temp');
            $temp_file_path = $this->temp_dir . '/' . $unique_filename;
            
            // Przeniesienie pliku z lokalizacji tymczasowej do naszego katalogu
            if (!move_uploaded_file($file_data['tmp_name'], $temp_file_path)) {
                error_log("PDFMaster: Nie udało się przenieść pliku tymczasowego do: {$temp_file_path}");
                return false;
            }
            
            // Ustawienie odpowiednich uprawnień pliku
            chmod($temp_file_path, 0644);
            
            // Sprawdzenie czy plik został rzeczywiście zapisany
            if (!file_exists($temp_file_path) || filesize($temp_file_path) !== $file_data['size']) {
                error_log("PDFMaster: Weryfikacja zapisanego pliku nie powiodła się: {$temp_file_path}");
                return false;
            }
            
            // Logowanie operacji dla monitoringu
            $this->log_file_operation('temp_save', $unique_filename, $file_data['size']);
            
            return $temp_file_path;
            
        } catch (Exception $e) {
            error_log("PDFMaster: Błąd podczas zapisywania pliku tymczasowego: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Zapisywanie przetworzonego pliku
     * Przyjmuje binarne dane pliku i zapisuje je z bezpieczną nazwą
     * 
     * @param string $file_content Binarna zawartość pliku
     * @param string $original_filename Oryginalna nazwa pliku (dla ekstensji)
     * @param string $mime_type Typ MIME pliku
     * @return string|false Nazwa zapisanego pliku lub false przy błędzie
     */
    public function save_processed_file($file_content, $original_filename, $mime_type) {
        try {
            // Generowanie unikalnej nazwy dla przetworzonego pliku
            $unique_filename = $this->generate_unique_filename($original_filename, 'processed');
            $processed_file_path = $this->processed_dir . '/' . $unique_filename;
            
            // Zapisanie zawartości pliku
            $bytes_written = file_put_contents($processed_file_path, $file_content);
            
            if ($bytes_written === false || $bytes_written === 0) {
                error_log("PDFMaster: Nie udało się zapisać przetworzonego pliku: {$processed_file_path}");
                return false;
            }
            
            // Ustawienie odpowiednich uprawnień
            chmod($processed_file_path, 0644);
            
            // Zapisanie metadanych pliku w systemie
            $this->save_file_metadata($unique_filename, array(
                'original_filename' => basename($original_filename),
                'mime_type' => $mime_type,
                'file_size' => $bytes_written,
                'created_at' => current_time('mysql'),
                'expires_at' => date('Y-m-d H:i:s', time() + $this->file_retention_time)
            ));
            
            // Logowanie operacji
            $this->log_file_operation('processed_save', $unique_filename, $bytes_written);
            
            return $unique_filename;
            
        } catch (Exception $e) {
            error_log("PDFMaster: Błąd podczas zapisywania przetworzonego pliku: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generowanie unikalnej nazwy pliku
     * Tworzy nieprzewidywalną nazwę zachowując oryginalne rozszerzenie
     * 
     * @param string $original_filename Oryginalna nazwa pliku
     * @param string $type Typ pliku (temp/processed)
     * @return string Unikalna nazwa pliku
     */
    private function generate_unique_filename($original_filename, $type) {
        // Pobranie rozszerzenia pliku
        $extension = pathinfo($original_filename, PATHINFO_EXTENSION);
        
        // Generowanie unikalnego identyfikatora
        $unique_id = uniqid($type . '_', true);
        $random_hash = substr(md5(rand()), 0, 8);
        $timestamp = time();
        
        // Kombinacja elementów zapewniająca unikalność
        $unique_name = $unique_id . '_' . $random_hash . '_' . $timestamp;
        
        // Dodanie rozszerzenia jeśli istnieje
        if (!empty($extension)) {
            $unique_name .= '.' . strtolower($extension);
        }
        
        return $unique_name;
    }
    
    /**
     * Zapisywanie metadanych pliku
     * Przechowuje informacje o pliku w bazie danych dla lepszego zarządzania
     */
    private function save_file_metadata($filename, $metadata) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_files';
        
        // Sprawdzenie czy tabela istnieje, jeśli nie - utworzenie
        $this->ensure_files_table_exists();
        
        $wpdb->insert(
            $table_name,
            array(
                'filename' => $filename,
                'original_filename' => $metadata['original_filename'],
                'mime_type' => $metadata['mime_type'],
                'file_size' => $metadata['file_size'],
                'created_at' => $metadata['created_at'],
                'expires_at' => $metadata['expires_at'],
                'download_count' => 0
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%d')
        );
    }
    
    /**
     * Tworzenie tabeli metadanych plików jeśli nie istnieje
     */
    private function ensure_files_table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_files';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            filename varchar(255) NOT NULL,
            original_filename varchar(255) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_size bigint(20) NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            download_count int(11) DEFAULT 0,
            last_downloaded datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY filename (filename),
            KEY expires_at (expires_at),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Generowanie bezpiecznego URL do pobrania pliku
     * Tworzy tymczasowy, autoryzowany link do pobrania
     * 
     * @param string $filename Nazwa pliku w systemie
     * @return string URL do pobrania
     */
    public function get_download_url($filename) {
        // Generowanie tokenu autoryzacyjnego ważnego przez 1 godzinę
        $token = $this->generate_download_token($filename);
        
        // Utworzenie URL z endpoint WordPress
        $download_url = home_url('pdfmaster-download/' . $filename . '/' . $token);
        
        return $download_url;
    }
    
    /**
     * Generowanie tokenu do autoryzacji pobierania
     * Token jest unikalny dla każdego pliku i ma ograniczony czas ważności
     */
    private function generate_download_token($filename) {
        $secret_key = defined('AUTH_KEY') ? AUTH_KEY : 'pdfmaster-secret';
        $expiry_time = time() + HOUR_IN_SECONDS; // Token ważny przez godzinę
        
        $token_data = $filename . '|' . $expiry_time;
        $token = hash_hmac('sha256', $token_data, $secret_key);
        
        // Zapisanie tokenu w cache na czas jego ważności
        set_transient('pdfmaster_token_' . $token, $filename, HOUR_IN_SECONDS);
        
        return $token . '|' . $expiry_time;
    }
    
    /**
     * Rejestracja endpoint do pobierania plików
     * Dodaje niestandardowy URL pattern do WordPress
     */
    public function register_download_endpoint() {
        // Add rewrite rule
        add_rewrite_rule(
            '^pdfmaster-download/([^/]+)/([^/]+)/?$',
            'index.php?pdfmaster_download=1&filename=$matches[1]&token=$matches[2]',
            'top'
        );

        // Add query vars - CRITICAL: Use higher priority to ensure it runs
        add_filter('query_vars', array($this, 'add_query_vars'), 5);

        // Check if we need to flush rewrite rules (only once)
        if (get_transient('pdfmaster_rewrite_rules_flushed') !== 'yes') {
            flush_rewrite_rules(true); // Hard flush
            set_transient('pdfmaster_rewrite_rules_flushed', 'yes', WEEK_IN_SECONDS);
            error_log('PDFMaster: Rewrite rules flushed and endpoint registered');
        }
    }

    /**
     * Add query vars for download endpoint
     * Separate method for better hook management
     */
    public function add_query_vars($vars) {
        $vars[] = 'pdfmaster_download';
        $vars[] = 'filename';
        $vars[] = 'token';
        return $vars;
    }

    /**
     * Force register query vars early in WordPress loading process
     * This ensures our query vars are available when rewrite rules are processed
     */
    public function force_register_query_vars() {
        global $wp;
        if (!in_array('pdfmaster_download', $wp->public_query_vars)) {
            $wp->add_query_var('pdfmaster_download');
        }
        if (!in_array('filename', $wp->public_query_vars)) {
            $wp->add_query_var('filename');
        }
        if (!in_array('token', $wp->public_query_vars)) {
            $wp->add_query_var('token');
        }

        // Also add the filter hook as a backup
        add_filter('query_vars', array($this, 'add_query_vars'), 1);
    }
    
    /**
     * Obsługa żądań pobierania plików
     * Sprawdza autoryzację i udostępnia plik do pobrania
     */
    public function handle_download_request() {
        if (!get_query_var('pdfmaster_download')) {
            return; // To nie jest nasze żądanie
        }
        
        $filename = get_query_var('filename');
        $token = get_query_var('token');
        
        try {
            // Walidacja tokenu
            if (!$this->validate_download_token($filename, $token)) {
                wp_die('Nieprawidłowy lub wygasły link do pobrania.', 'Błąd autoryzacji', array('response' => 403));
                return;
            }
            
            // Sprawdzenie czy plik istnieje
            $file_path = $this->processed_dir . '/' . $filename;
            if (!file_exists($file_path)) {
                wp_die('Plik nie został znaleziony lub już wygasł.', 'Plik niedostępny', array('response' => 404));
                return;
            }
            
            // Pobranie metadanych pliku
            $file_metadata = $this->get_file_metadata($filename);
            
            // Zwiększenie licznika pobrań
            $this->increment_download_counter($filename);
            
            // Przygotowanie headers dla pobrania
            $this->send_file_headers($file_metadata);
            
            // Wysłanie pliku do przeglądarki
            readfile($file_path);
            
            // Zatrzymanie dalszego przetwarzania WordPress
            exit;
            
        } catch (Exception $e) {
            error_log("PDFMaster: Błąd podczas pobierania pliku: " . $e->getMessage());
            wp_die('Wystąpił błąd podczas pobierania pliku.', 'Błąd serwera', array('response' => 500));
        }
    }
    
    /**
     * Walidacja tokenu pobierania
     * Sprawdza czy token jest prawidłowy i nie wygasł
     */
    private function validate_download_token($filename, $token) {
        // Podział tokenu na hash i czas wygaśnięcia
        $token_parts = explode('|', $token);
        if (count($token_parts) !== 2) {
            return false;
        }
        
        list($token_hash, $expiry_time) = $token_parts;
        
        // Sprawdzenie czy token nie wygasł
        if (time() > intval($expiry_time)) {
            return false;
        }
        
        // Sprawdzenie czy token istnieje w cache
        $cached_filename = get_transient('pdfmaster_token_' . $token_hash);
        if ($cached_filename !== $filename) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Pobranie metadanych pliku z bazy danych
     */
    private function get_file_metadata($filename) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_files';
        
        $metadata = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE filename = %s",
            $filename
        ));
        
        return $metadata;
    }
    
    /**
     * Zwiększenie licznika pobrań pliku
     */
    private function increment_download_counter($filename) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_files';
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} SET download_count = download_count + 1, last_downloaded = %s WHERE filename = %s",
            current_time('mysql'),
            $filename
        ));
    }
    
    /**
     * Wysłanie odpowiednich headers HTTP dla pobrania pliku
     */
    private function send_file_headers($file_metadata) {
        // Czyszczenie poprzednich headers
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Podstawowe headers
        header('Content-Type: ' . $file_metadata->mime_type);
        header('Content-Length: ' . $file_metadata->file_size);
        header('Content-Disposition: attachment; filename="' . $file_metadata->original_filename . '"');
        
        // Headers cache i bezpieczeństwa
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Header bezpieczeństwa
        header('X-Content-Type-Options: nosniff');
    }
    
    /**
     * Czyszczenie starych plików
     * Usuwa pliki starsze niż określony czas przechowywania
     */
    public function cleanup_old_files() {
        try {
            $current_time = time();
            $files_removed = 0;
            $space_freed = 0;
            
            // Czyszczenie plików tymczasowych
            $temp_files = glob($this->temp_dir . '/*');
            foreach ($temp_files as $file) {
                if (is_file($file) && ($current_time - filemtime($file)) > $this->file_retention_time) {
                    $file_size = filesize($file);
                    unlink($file);
                    $files_removed++;
                    $space_freed += $file_size;
                }
            }
            
            // Czyszczenie przetworzonych plików na podstawie metadanych
            global $wpdb;
            $table_name = $wpdb->prefix . 'pdfmaster_files';
            
            $expired_files = $wpdb->get_results($wpdb->prepare(
                "SELECT filename FROM {$table_name} WHERE expires_at < %s",
                current_time('mysql')
            ));
            
            foreach ($expired_files as $file_record) {
                $file_path = $this->processed_dir . '/' . $file_record->filename;
                if (file_exists($file_path)) {
                    $file_size = filesize($file_path);
                    unlink($file_path);
                    $space_freed += $file_size;
                    $files_removed++;
                }
            }
            
            // Usunięcie rekordów wygasłych plików z bazy danych
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table_name} WHERE expires_at < %s",
                current_time('mysql')
            ));
            
            // Logowanie rezultatów czyszczenia
            if ($files_removed > 0) {
                $space_freed_mb = round($space_freed / (1024 * 1024), 2);
                error_log("PDFMaster: Wyczyszczono {$files_removed} plików, zwolniono {$space_freed_mb}MB miejsca");
            }
            
        } catch (Exception $e) {
            error_log("PDFMaster: Błąd podczas czyszczenia plików: " . $e->getMessage());
        }
    }
    
    /**
     * Czyszczenie wszystkich plików plugin (przy dezaktywacji)
     */
    public function cleanup_all_files() {
        try {
            // Usunięcie wszystkich plików z katalogów plugin
            $this->remove_directory_contents($this->temp_dir);
            $this->remove_directory_contents($this->processed_dir);
            
            // Usunięcie metadanych z bazy danych
            global $wpdb;
            $table_name = $wpdb->prefix . 'pdfmaster_files';
            $wpdb->query("DELETE FROM {$table_name}");
            
            error_log("PDFMaster: Wszystkie pliki plugin zostały wyczyszczone przy dezaktywacji");
            
        } catch (Exception $e) {
            error_log("PDFMaster: Błąd podczas pełnego czyszczenia: " . $e->getMessage());
        }
    }
    
    /**
     * Usuwanie zawartości katalogu
     */
    private function remove_directory_contents($directory) {
        if (!is_dir($directory)) {
            return;
        }
        
        $files = glob($directory . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    
    /**
     * Logowanie operacji na plikach
     * Pomocne do monitorowania i debugowania
     */
    private function log_file_operation($operation, $filename, $file_size) {
        $log_entry = array(
            'operation' => $operation,
            'filename' => $filename,
            'file_size' => $file_size,
            'timestamp' => current_time('mysql'),
            'memory_usage' => memory_get_usage(true)
        );
        
        $this->operation_stats[] = $log_entry;
        
        // Log do error_log dla debugging
        error_log("PDFMaster File Operation: {$operation} - {$filename} - " . round($file_size / 1024, 2) . "KB");
    }
    
    /**
     * Gettery dla konfiguracji
     */
    public function get_max_file_size() {
        return $this->max_file_size;
    }
    
    public function get_allowed_mime_types() {
        return $this->allowed_mime_types;
    }
    
    public function get_file_retention_time() {
        return $this->file_retention_time;
    }
    
    /**
     * Pobranie statystyk wykorzystania
     * Przydatne do monitorowania i optymalizacji
     */
    public function get_usage_stats() {
        try {
            // Statystyki przestrzeni dyskowej
            $temp_size = $this->get_directory_size($this->temp_dir);
            $processed_size = $this->get_directory_size($this->processed_dir);
            
            // Liczba plików
            $temp_count = count(glob($this->temp_dir . '/*'));
            $processed_count = count(glob($this->processed_dir . '/*'));
            
            return array(
                'temp_files_count' => $temp_count,
                'processed_files_count' => $processed_count,
                'temp_size_mb' => round($temp_size / (1024 * 1024), 2),
                'processed_size_mb' => round($processed_size / (1024 * 1024), 2),
                'total_size_mb' => round(($temp_size + $processed_size) / (1024 * 1024), 2),
                'operation_stats' => $this->operation_stats
            );
            
        } catch (Exception $e) {
            error_log("PDFMaster: Błąd podczas pobierania statystyk: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Obliczanie rozmiaru katalogu
     */
    private function get_directory_size($directory) {
        $size = 0;
        $files = glob($directory . '/*');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $size += filesize($file);
            }
        }
        
        return $size;
    }
}
?>
