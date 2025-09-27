<?php
/**
 * Plugin Name: PDFMaster Integration
 * Plugin URI: https://pdfmaster.pl
 * Description: Integracja z Stirling PDF API dla profesjonalnego przetwarzania plików PDF
 * Version: 1.0.0
 * Author: PDFMaster Team
 * Text Domain: pdfmaster
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * Network: false
 * License: GPL v2 or later
 */

// Zabezpieczenie przed bezpośrednim dostępem - kluczowe dla bezpieczeństwa
if (!defined('ABSPATH')) {
    exit('Bezpośredni dostęp zabroniony.');
}

// Definicja stałych plugin - ułatwia zarządzanie ścieżkami i konfiguracją
define('PDFMASTER_PLUGIN_VERSION', '1.0.0');
define('PDFMASTER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PDFMASTER_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PDFMASTER_STIRLING_API_URL', 'http://localhost:8080'); // URL do naszego API

// Ładowanie wymaganych plików - modularna architektura ułatwia rozwój
require_once PDFMASTER_PLUGIN_PATH . 'includes/class-pdf-api-client.php';
require_once PDFMASTER_PLUGIN_PATH . 'includes/class-ajax-handlers.php';
require_once PDFMASTER_PLUGIN_PATH . 'includes/class-file-manager.php';
require_once PDFMASTER_PLUGIN_PATH . 'includes/class-user-credits.php';

/**
 * Główna klasa plugin - centralny punkt kontroli całej aplikacji
 * 
 * Ta klasa odpowiada za inicjalizację wszystkich komponentów plugin,
 * rejestrację hooks WordPress i zarządzanie cyklem życia plugin.
 */
class PDFMaster_Integration {
    
    /**
     * Singleton instance - zapewnia że plugin ma tylko jedną instancję
     */
    private static $instance = null;
    
    /**
     * Komponenty plugin
     */
    private $pdf_api_client;
    private $ajax_handlers;
    private $file_manager;
    private $user_credits;
    
    /**
     * Metoda getInstance - implementacja wzorca Singleton
     * Zapewnia że plugin ma globalnie jedną instancję
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Konstruktor - inicjalizuje plugin
     */
    private function __construct() {
        // Rejestracja hook aktywacji plugin
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Inicjalizacja plugin po załadowaniu WordPress
        add_action('init', array($this, 'init'));
        
        // Rejestracja skryptów i stylów
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX endpoints will be registered after components are initialized
        add_action('init', array($this, 'register_ajax_endpoints'), 20);
    }
    
    /**
     * Inicjalizacja plugin - uruchamia się po załadowaniu WordPress
     */
    public function init() {
        // Inicjalizacja komponentów plugin with error checking
        try {
            if (class_exists('PDFMaster_PDF_API_Client')) {
                $this->pdf_api_client = new PDFMaster_PDF_API_Client();
            } else {
                error_log('PDFMaster: Missing PDFMaster_PDF_API_Client class');
                return;
            }

            if (class_exists('PDFMaster_File_Manager')) {
                $this->file_manager = new PDFMaster_File_Manager();
            } else {
                error_log('PDFMaster: Missing PDFMaster_File_Manager class');
                return;
            }

            if (class_exists('PDFMaster_User_Credits')) {
                $this->user_credits = new PDFMaster_User_Credits();
            } else {
                error_log('PDFMaster: Missing PDFMaster_User_Credits class');
                return;
            }

            if (class_exists('PDFMaster_Ajax_Handlers')) {
                $this->ajax_handlers = new PDFMaster_Ajax_Handlers($this->pdf_api_client, $this->file_manager, $this->user_credits);
            } else {
                error_log('PDFMaster: Missing PDFMaster_Ajax_Handlers class');
                return;
            }
        } catch (Exception $e) {
            error_log('PDFMaster initialization error: ' . $e->getMessage());
            return;
        }
        
        // Rejestracja shortcode dla Elementor
        add_shortcode('pdfmaster_compress', array($this, 'render_compress_shortcode'));
        
        // Ustawienia internationalization
        load_plugin_textdomain('pdfmaster', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Rejestracja skryptów i stylów
     * Ładujemy tylko na stronach gdzie są potrzebne dla optymalizacji wydajności
     */
    public function enqueue_scripts() {
        // Check multiple conditions for when to load scripts
        $should_load = false;

        // Check for shortcode in post content
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'pdfmaster_compress')) {
            $should_load = true;
        }

        // Check for Elementor (which loads content via AJAX)
        if (class_exists('\Elementor\Plugin')) {
            // Load on all pages that might have Elementor content
            if (is_page() || is_single() || is_front_page()) {
                $should_load = true;
            }
        }

        // Check query string for force loading (useful for testing)
        if (isset($_GET['pdfmaster_load']) || isset($_GET['elementor-preview'])) {
            $should_load = true;
        }

        if ($should_load) {
            
            // Ensure jQuery is loaded first
            wp_enqueue_script('jquery');

            // JavaScript dla obsługi kompresji PDF
            wp_enqueue_script(
                'pdfmaster-compress-js',
                PDFMASTER_PLUGIN_URL . 'assets/js/pdf-compress.js',
                array('jquery'), // Zależność od jQuery
                PDFMASTER_PLUGIN_VERSION,
                true // Ładuj w footer dla lepszej wydajności
            );
            
            // Przekazanie danych z PHP do JavaScript - bezpieczna komunikacja
            wp_localize_script('pdfmaster-compress-js', 'pdfmaster_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pdfmaster_compress_nonce'),
                'max_file_size' => '50MB',
                'allowed_types' => 'application/pdf',
                'messages' => array(
                    'uploading' => __('Przesyłanie pliku...', 'pdfmaster'),
                    'processing' => __('Kompresowanie PDF...', 'pdfmaster'),
                    'success' => __('Plik został skompresowany!', 'pdfmaster'),
                    'error_size' => __('Plik jest za duży (maksymalnie 50MB)', 'pdfmaster'),
                    'error_type' => __('Nieprawidłowy typ pliku. Wybierz plik PDF.', 'pdfmaster'),
                    'error_network' => __('Błąd sieci. Spróbuj ponownie.', 'pdfmaster')
                )
            ));
            
            // CSS dla stylingowania komponentów
            wp_enqueue_style(
                'pdfmaster-compress-css',
                PDFMASTER_PLUGIN_URL . 'assets/css/pdf-compress.css',
                array(),
                PDFMASTER_PLUGIN_VERSION
            );
        }
    }
    
    /**
     * Register AJAX endpoints after components are initialized
     */
    public function register_ajax_endpoints() {
        error_log('PDFMaster: Registering AJAX endpoints, ajax_handlers available: ' . ($this->ajax_handlers ? 'yes' : 'no'));

        // Rejestracja AJAX endpoints dla zalogowanych i niezalogowanych użytkowników
        add_action('wp_ajax_pdfmaster_compress', array($this, 'handle_compression_ajax'));
        add_action('wp_ajax_nopriv_pdfmaster_compress', array($this, 'handle_compression_ajax'));

        // Additional AJAX endpoints - with fallback
        if ($this->ajax_handlers && method_exists($this->ajax_handlers, 'handle_credits_check')) {
            add_action('wp_ajax_pdfmaster_check_credits', array($this->ajax_handlers, 'handle_credits_check'));
            add_action('wp_ajax_nopriv_pdfmaster_check_credits', array($this->ajax_handlers, 'handle_credits_check'));
        } else {
            // Fallback handler if ajax_handlers is not available
            add_action('wp_ajax_pdfmaster_check_credits', array($this, 'handle_credits_check_fallback'));
            add_action('wp_ajax_nopriv_pdfmaster_check_credits', array($this, 'handle_credits_check_fallback'));
        }
    }

    /**
     * Obsługa AJAX żądań kompresji
     * Ten endpoint odbiera pliki z frontendu i zarządza całym procesem kompresji
     */
    public function handle_compression_ajax() {
        // Przekazanie żądania do specjalistycznej klasy
        if ($this->ajax_handlers && method_exists($this->ajax_handlers, 'handle_pdf_compression')) {
            $this->ajax_handlers->handle_pdf_compression();
        } else {
            wp_send_json_error(array('message' => 'Błąd inicjalizacji systemu. Odśwież stronę i spróbuj ponownie.'));
        }
    }

    /**
     * Fallback handler for credits check if main ajax handler is not available
     */
    public function handle_credits_check_fallback() {
        error_log('PDFMaster: Using fallback credits check handler');

        // Simple response with default values
        $credits_info = array(
            'remaining_credits' => 3, // Default free credits
            'daily_limit' => 3,
            'is_premium' => false,
            'next_reset' => 'unknown'
        );

        wp_send_json_success($credits_info);
    }

    /**
     * Renderowanie shortcode dla kompresji PDF
     * Ten shortcode będzie używany w kartach Elementor Pro
     */
    public function render_compress_shortcode($atts) {
        // Parsowanie atrybutów shortcode z wartościami domyślnymi
        $attributes = shortcode_atts(array(
            'title' => __('Kompresuj PDF', 'pdfmaster'),
            'description' => __('Zmniejsz rozmiar pliku PDF o 60-90% bez utraty jakości', 'pdfmaster'),
            'button_text' => __('Kompresuj PDF', 'pdfmaster'),
            'max_size' => '50MB'
        ), $atts);
        
        // Rozpoczęcie output buffering dla zwracania HTML
        ob_start();
        
        // Ładowanie template z możliwością override przez theme
        $template_path = locate_template('pdfmaster/compress-form.php');
        if (!$template_path) {
            $template_path = PDFMASTER_PLUGIN_PATH . 'templates/compress-form.php';
        }
        
        // Przekazanie zmiennych do template
        include $template_path;
        
        return ob_get_clean();
    }
    
    /**
     * Aktywacja plugin - tworzy potrzebne tabele i ustawienia
     * Ta metoda jest wywoływana tylko raz, gdy administrator aktywuje plugin
     */
    public function activate() {
        // Tworzenie wszystkich tabel potrzebnych przez aplikację
        // Robimy to w określonej kolejności ze względu na zależności między tabelami
        $this->create_database_tables();        // Tabela operacji PDF
        $this->create_files_table();           // Tabela metadanych plików
        $this->create_usage_log_table();       // Tabela logowania użytkowania
        $this->create_credit_transactions_table(); // Tabela transakcji kredytowych
        
        // Ustawienia domyślne aplikacji
        // Te wartości będą używane jako fallback, jeśli admin nie skonfiguruje własnych
        add_option('pdfmaster_free_credits_per_day', 3);
        add_option('pdfmaster_guest_daily_limit', 2);
        add_option('pdfmaster_max_file_size', 50); // MB
        add_option('pdfmaster_premium_unlimited', true);
        add_option('pdfmaster_grace_period_hours', 1);
        
        // KRYTYCZNE: Flush rewrite rules dla niestandardowych URL
        // Bez tego nasze endpoint do pobierania plików nie będą działać
        flush_rewrite_rules();
        
        // Ustawienie flagi że plugin został poprawnie zainicjalizowany
        update_option('pdfmaster_plugin_initialized', true);
        
        // Logowanie sukcesu aktywacji
        error_log('PDFMaster: Plugin został pomyślnie aktywowany');
    }
    
    /**
     * Deaktywacja plugin - czyszczenie tymczasowe
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Czyszczenie cache i tymczasowych plików
        $this->cleanup_temp_files();
    }
    
    /**
     * Tworzenie tabel bazy danych dla plugin
     */
    private function create_database_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_operations';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            session_id varchar(255) DEFAULT NULL,
            operation_type varchar(50) NOT NULL,
            original_filename varchar(255) NOT NULL,
            original_size bigint(20) NOT NULL,
            processed_size bigint(20) DEFAULT NULL,
            compression_ratio decimal(5,2) DEFAULT NULL,
            processing_time int(11) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_message text DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY operation_type (operation_type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    /**
     * Tworzenie tabeli metadanych plików
     * Używana przez PDFMaster_File_Manager do zarządzania informacjami o plikach
     */
    private function create_files_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_files';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
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
     * Tworzenie tabeli logowania użytkowania
     * Używana do analityki biznesowej i monitorowania wzorców użytkowania
     */
    private function create_usage_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_usage_log';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            guest_id varchar(32) DEFAULT NULL,
            operation_type varchar(50) NOT NULL,
            credits_deducted int(11) NOT NULL DEFAULT 0,
            credit_source varchar(20) NOT NULL DEFAULT 'daily',
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            metadata text DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY guest_id (guest_id),
            KEY operation_type (operation_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Tworzenie tabeli transakcji kredytowych
     * Używana do śledzenia zakupów pakietów kredytów przez użytkowników
     */
    private function create_credit_transactions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_credit_transactions';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            transaction_type varchar(20) NOT NULL,
            amount int(11) NOT NULL,
            transaction_id varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY transaction_type (transaction_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    /**
     * Czyszczenie tymczasowych plików starszych niż 2 godziny
     */
    private function cleanup_temp_files() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/pdfmaster-temp/';
        
        if (is_dir($temp_dir)) {
            $files = glob($temp_dir . '*');
            $now = time();
            
            foreach ($files as $file) {
                if (is_file($file) && ($now - filemtime($file)) > 7200) { // 2 godziny
                    unlink($file);
                }
            }
        }
    }
}

// Inicjalizacja plugin - uruchomienie całego systemu
function pdfmaster_init() {
    return PDFMaster_Integration::getInstance();
}

// Hook do uruchomienia plugin po załadowaniu WordPress
add_action('plugins_loaded', 'pdfmaster_init');

// Dodatkowe zabezpieczenie - sprawdzenie czy WordPress jest w pełni załadowany
if (defined('ABSPATH')) {
    pdfmaster_init();
}
?>
