<?php
/**
 * Klasa PDFMaster_Ajax_Handlers
 * 
 * Ta klasa jest centralnym punktem obsługi wszystkich żądań AJAX w naszej aplikacji.
 * Działa jak inteligentny dispatcher - odbiera żądania z frontendu, koordynuje
 * współpracę między różnymi komponentami systemu i zwraca sformatowane odpowiedzi.
 * 
 * Kluczowe odpowiedzialności tej klasy:
 * 1. Walidacja żądań AJAX (bezpieczeństwo, uprawnienia, format danych)
 * 2. Zarządzanie przepływem przetwarzania plików
 * 3. Koordynacja między API client, file manager i system kredytów
 * 4. Formatowanie odpowiedzi dla JavaScript frontend
 * 5. Logowanie operacji dla analityki biznesowej
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit('Bezpośredni dostęp zabroniony.');
}

class PDFMaster_Ajax_Handlers {
    
    /**
     * Instancje klas pomocniczych
     * Używamy dependency injection pattern - przekazujemy gotowe obiekty
     * zamiast tworzyć je wewnątrz klasy, co ułatwia testowanie i flexibility
     */
    private $pdf_api_client;
    private $file_manager;
    private $user_credits;
    
    /**
     * Statystyki sesji - pomocne do debugowania i analityki
     */
    private $operation_start_time;
    private $current_operation_id;
    
    /**
     * Konstruktor klasy
     *
     * @param PDFMaster_PDF_API_Client $pdf_api_client Klient do komunikacji z API
     * @param PDFMaster_File_Manager $file_manager Manager plików
     * @param PDFMaster_User_Credits $user_credits System kredytów użytkownika
     */
    public function __construct($pdf_api_client, $file_manager, $user_credits) {
        $this->pdf_api_client = $pdf_api_client;
        $this->file_manager = $file_manager;
        $this->user_credits = $user_credits;

        // Note: AJAX handlers are now registered in main plugin file to avoid duplicates
        // $this->register_ajax_handlers();
    }
    
    /**
     * Rejestracja handlers AJAX
     * WordPress wymaga oddzielnej rejestracji dla zalogowanych i niezalogowanych użytkowników
     */
    private function register_ajax_handlers() {
        // Handler dla kompresji PDF - dostępny dla wszystkich użytkowników
        add_action('wp_ajax_pdfmaster_compress', array($this, 'handle_pdf_compression'));
        add_action('wp_ajax_nopriv_pdfmaster_compress', array($this, 'handle_pdf_compression'));
        
        // Handler dla sprawdzania kredytów - pomocny dla UI
        add_action('wp_ajax_pdfmaster_check_credits', array($this, 'handle_credits_check'));
        add_action('wp_ajax_nopriv_pdfmaster_check_credits', array($this, 'handle_credits_check'));
        
        // Handler dla sprawdzania statusu operacji - do progress tracking
        add_action('wp_ajax_pdfmaster_operation_status', array($this, 'handle_operation_status'));
        add_action('wp_ajax_nopriv_pdfmaster_operation_status', array($this, 'handle_operation_status'));
    }
    
    /**
     * Główny handler dla kompresji PDF
     * Ta metoda orchestruje cały proces kompresji od początku do końca
     */
    public function handle_pdf_compression() {
        // Rozpoczęcie mierzenia czasu operacji dla analityki
        $this->operation_start_time = microtime(true);
        $this->current_operation_id = uniqid('compress_');
        
        try {
            // Krok 1: Podstawowa walidacja żądania (fix nonce action name)
            $this->validate_ajax_request('pdfmaster_compress_nonce');
            
            // Krok 2: Sprawdzenie czy API jest dostępne
            if (!$this->pdf_api_client || !method_exists($this->pdf_api_client, 'is_api_available')) {
                throw new Exception('Błąd konfiguracji API. Skontaktuj się z administratorem.');
            }

            if (!$this->pdf_api_client->is_api_available()) {
                throw new Exception('Serwis przetwarzania PDF jest tymczasowo niedostępny. Spróbuj ponownie za chwilę.');
            }
            
            // Krok 3: Walidacja przesłanego pliku
            $file_data = $this->validate_uploaded_file();
            
            // Krok 4: Sprawdzenie czy użytkownik ma dostępne kredyty
            // TEMPORARY: Skip credit check for debugging
            /*
            if (!$this->user_credits || !method_exists($this->user_credits, 'has_available_credits')) {
                throw new Exception('Błąd systemu kredytów. Skontaktuj się z administratorem.');
            }

            if (!$this->user_credits->has_available_credits()) {
                throw new Exception('Wykorzystałeś już wszystkie darmowe operacje. Kup plan Premium żeby kontynuować.');
            }
            */
            
            // Krok 5: Zapisanie pliku tymczasowo na serwerze
            if (!$this->file_manager || !method_exists($this->file_manager, 'save_temp_file')) {
                throw new Exception('Błąd managera plików. Skontaktuj się z administratorem.');
            }

            $temp_file_path = $this->file_manager->save_temp_file($file_data);
            if (!$temp_file_path) {
                throw new Exception('Nie udało się zapisać pliku na serwerze. Spróbuj ponownie.');
            }
            
            // Krok 6: Przygotowanie parametrów kompresji
            $compression_level = $this->get_compression_level();
            
            // Krok 7: Logowanie rozpoczęcia operacji
            $operation_log_id = $this->log_operation_start($file_data, 'compress');
            
            // Krok 8: Wykonanie kompresji przez API
            $compression_result = $this->pdf_api_client->compress_pdf($temp_file_path, $compression_level);
            
            // Krok 9: Sprawdzenie czy kompresja się udała
            if (!$compression_result['success']) {
                $this->cleanup_temp_file($temp_file_path);
                throw new Exception($compression_result['error']);
            }
            
            // Krok 10: Zapisanie skompresowanego pliku
            $output_filename = $this->file_manager->save_processed_file(
                $compression_result['data'], 
                'compressed_' . $file_data['name'],
                'application/pdf'
            );
            
            if (!$output_filename) {
                $this->cleanup_temp_file($temp_file_path);
                throw new Exception('Nie udało się zapisać skompresowanego pliku.');
            }
            
            // Krok 11: Odjęcie kredytu od użytkownika
            $this->user_credits->deduct_credit('compress');
            
            // Krok 12: Logowanie sukcesu operacji
            $this->log_operation_success($operation_log_id, $file_data, $compression_result, $output_filename);
            
            // Krok 13: Przygotowanie URL do pobrania
            $download_url = $this->file_manager->get_download_url($output_filename);
            
            // Krok 14: Czyszczenie pliku tymczasowego
            $this->cleanup_temp_file($temp_file_path);
            
            // Krok 15: Zwrócenie sukcesu do frontend
            $this->send_success_response(array(
                'download_url' => $download_url,
                'original_size' => $file_data['size'],
                'compressed_size' => strlen($compression_result['data']),
                'compression_ratio' => $this->calculate_compression_ratio($file_data['size'], strlen($compression_result['data'])),
                'operation_time' => round(microtime(true) - $this->operation_start_time, 2),
                'remaining_credits' => $this->user_credits->get_remaining_credits(),
                'filename' => $output_filename
            ));
            
        } catch (Exception $e) {
            // Obsługa błędów - zapewniamy że użytkownik dostanie jasny komunikat
            $this->handle_operation_error($e->getMessage());
        }
    }
    
    /**
     * Walidacja żądania AJAX
     * Sprawdza bezpieczeństwo i podstawowe wymagania żądania
     */
    private function validate_ajax_request($nonce_action) {
        // Sprawdzenie czy to żądanie AJAX
        if (!wp_doing_ajax()) {
            throw new Exception('Nieprawidłowy typ żądania.');
        }
        
        // Weryfikacja nonce dla bezpieczeństwa
        if (!wp_verify_nonce($_POST['nonce'] ?? '', $nonce_action)) {
            throw new Exception('Błąd bezpieczeństwa. Odśwież stronę i spróbuj ponownie.');
        }
        
        // Sprawdzenie method żądania
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception('Nieprawidłowa metoda żądania.');
        }
        
        // Rate limiting - TEMPORARY: disabled for debugging
        // $this->check_rate_limiting();
    }
    
    /**
     * Walidacja przesłanego pliku
     * Sprawdza czy plik został prawidłowo przesłany i spełnia wymagania
     */
    private function validate_uploaded_file() {
        // Sprawdzenie czy plik został przesłany
        if (!isset($_FILES['pdf_file']) || !is_array($_FILES['pdf_file'])) {
            throw new Exception('Nie wybrano pliku do przetworzenia.');
        }
        
        $file = $_FILES['pdf_file'];
        
        // Sprawdzenie czy nie było błędów podczas upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = $this->get_upload_error_message($file['error']);
            throw new Exception($error_message);
        }
        
        // Sprawdzenie czy plik rzeczywiście został przesłany
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Błąd bezpieczeństwa podczas przesyłania pliku.');
        }
        
        // Walidacja typu pliku
        $this->validate_file_type($file);
        
        // Walidacja rozmiaru pliku
        $this->validate_file_size($file);
        
        return $file;
    }
    
    /**
     * Walidacja typu pliku
     * Sprawdza czy przesłany plik to rzeczywiście PDF
     */
    private function validate_file_type($file) {
        // Sprawdzenie rozszerzenia pliku
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_extension !== 'pdf') {
            throw new Exception('Nieprawidłowy typ pliku. Wybierz plik PDF.');
        }
        
        // Sprawdzenie MIME type
        $allowed_mime_types = array('application/pdf');
        if (!in_array($file['type'], $allowed_mime_types)) {
            throw new Exception('Nieprawidłowy format pliku. Wybierz prawidłowy plik PDF.');
        }
        
        // Dodatkowa weryfikacja przez finfo (bardziej niezawodna)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if ($detected_type !== 'application/pdf') {
                throw new Exception('Plik nie jest prawidłowym dokumentem PDF.');
            }
        }
    }
    
    /**
     * Walidacja rozmiaru pliku
     * Sprawdza czy plik nie jest za duży
     */
    private function validate_file_size($file) {
        $max_size = $this->file_manager->get_max_file_size();
        
        if ($file['size'] > $max_size) {
            $max_size_mb = round($max_size / (1024 * 1024), 1);
            throw new Exception("Plik jest za duży. Maksymalny rozmiar to {$max_size_mb}MB.");
        }
        
        if ($file['size'] <= 0) {
            throw new Exception('Plik jest pusty lub uszkodzony.');
        }
    }
    
    /**
     * Pobranie poziomu kompresji z żądania
     * Z walidacją i wartością domyślną
     */
    private function get_compression_level() {
        $level = intval($_POST['compression_level'] ?? 2);
        
        // Walidacja - poziom musi być między 1 a 3
        if ($level < 1 || $level > 3) {
            $level = 2; // Wartość domyślna - średnia kompresja
        }
        
        return $level;
    }
    
    /**
     * Logowanie rozpoczęcia operacji
     * Zapisuje informacje o operacji do bazy danych dla analityki
     */
    private function log_operation_start($file_data, $operation_type) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_operations';
        
        $user_id = get_current_user_id();
        $session_id = $this->get_session_identifier(); 
        
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id ?: null,
                'session_id' => $session_id,
                'operation_type' => $operation_type,
                'original_filename' => sanitize_file_name($file_data['name']),
                'original_size' => $file_data['size'],
                'status' => 'processing',
                'ip_address' => $this->get_client_ip(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Logowanie sukcesu operacji
     * Aktualizuje rekord w bazie danych z wynikami operacji
     */
    private function log_operation_success($operation_id, $file_data, $result, $output_filename) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_operations';
        
        $processing_time = round(microtime(true) - $this->operation_start_time, 2);
        $compression_ratio = $this->calculate_compression_ratio($file_data['size'], strlen($result['data']));
        
        $wpdb->update(
            $table_name,
            array(
                'processed_size' => strlen($result['data']),
                'compression_ratio' => $compression_ratio,
                'processing_time' => $processing_time,
                'status' => 'completed',
                'completed_at' => current_time('mysql')
            ),
            array('id' => $operation_id),
            array('%d', '%f', '%f', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Obsługa błędów operacji
     * Loguje błąd i wysyła odpowiedź do frontend
     */
    private function handle_operation_error($error_message) {
        // Logowanie błędu
        error_log("PDFMaster operation error: {$error_message}");
        
        // Aktualizacja statusu w bazie danych jeśli operacja była rozpoczęta
        if ($this->current_operation_id) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'pdfmaster_operations';
            
            $wpdb->update(
                $table_name,
                array(
                    'status' => 'failed',
                    'error_message' => $error_message,
                    'completed_at' => current_time('mysql')
                ),
                array('id' => $this->current_operation_id),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }
        
        // Wysłanie błędu do frontend
        $this->send_error_response($error_message);
    }
    
    /**
     * Sprawdzanie rate limiting
     * Prosty mechanizm zapobiegania nadużyciom
     */
    private function check_rate_limiting() {
        // Używamy session identifier dla bardziej precyzyjnego rate limiting
        $session_id = $this->get_session_identifier();
        $rate_limit_key = 'pdfmaster_rate_limit_' . md5($session_id);
        
        $requests = get_transient($rate_limit_key) ?: 0;
        
        // Maksymalnie 10 żądań na godzinę dla jednego IP
        if ($requests >= 10) {
            throw new Exception('Zbyt wiele żądań. Spróbuj ponownie za godzinę.');
        }
        
        // Zwiększ licznik
        set_transient($rate_limit_key, $requests + 1, HOUR_IN_SECONDS);
    }
    
    /**
     * Obliczanie współczynnika kompresji
     * Zwraca procent redukcji rozmiaru
     */
    private function calculate_compression_ratio($original_size, $compressed_size) {
        if ($original_size <= 0) return 0;
        
        $reduction = (($original_size - $compressed_size) / $original_size) * 100;
        return round(max(0, $reduction), 1);
    }
    
    /**
     * Pobranie adresu IP klienta
     * Uwzględnia proxy i load balancers
     */
    private function get_client_ip() {
        $ip_headers = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    /**
     * Pobranie identyfikatora sesji kompatybilnego z WordPress
     * Używa różnych strategii dla zalogowanych i niezalogowanych użytkowników
     * 
     * @return string Unikalny identyfikator sesji
     */
    private function get_session_identifier() {
        if (is_user_logged_in()) {
            // Dla zalogowanych użytkowników: user ID + timestamp dnia dla grupowania sesji dziennych
            $user_id = get_current_user_id();
            $today = date('Y-m-d');
            return 'user_' . $user_id . '_' . $today;
        } else {
            // Dla gości: stabilny hash IP + User Agent + data
            // Ten identyfikator będzie ten sam w ramach jednego dnia dla tego samego gościa
            $ip = $this->get_client_ip();
            $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100);
            $today = date('Y-m-d');
            
            // Używamy hash dla prywatności - nie przechowujemy surowego IP
            $guest_hash = md5($ip . '|' . $user_agent . '|' . $today);
            return 'guest_' . $guest_hash;
        }
    }
    /**
     * Pobranie rozszerzonego identyfikatora sesji z dodatkowymi metadanymi
     * Przydatne do zaawansowanej analityki i troubleshooting
     * 
     * @return array Tablica z informacjami o sesji
     */
    private function get_session_metadata() {
        $base_identifier = $this->get_session_identifier();
        
        return array(
            'session_id' => $base_identifier,
            'user_type' => is_user_logged_in() ? 'registered' : 'guest',
            'user_id' => is_user_logged_in() ? get_current_user_id() : null,
            'ip_address' => $this->get_client_ip(),
            'user_agent_hash' => md5($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'timestamp' => current_time('mysql'),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'referer' => wp_get_referer() ?: 'direct'
        );
    }
    /**
     * Tłumaczenie kodów błędów upload na zrozumiałe komunikaty
     */
    private function get_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Plik jest za duży. Maksymalny rozmiar to 50MB.';
            case UPLOAD_ERR_PARTIAL:
                return 'Plik został przesłany tylko częściowo. Spróbuj ponownie.';
            case UPLOAD_ERR_NO_FILE:
                return 'Nie wybrano pliku do przesłania.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Błąd serwera - brak katalogu tymczasowego.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Błąd serwera - nie można zapisać pliku.';
            default:
                return 'Nieznany błąd podczas przesyłania pliku.';
        }
    }
    
    /**
     * Czyszczenie pliku tymczasowego
     * Bezpieczne usuwanie z sprawdzeniem ścieżki
     */
    private function cleanup_temp_file($file_path) {
        if ($file_path && file_exists($file_path) && is_file($file_path)) {
            unlink($file_path);
        }
    }
    
    /**
     * Wysłanie odpowiedzi sukcesu do frontend
     */
    private function send_success_response($data) {
        wp_send_json_success($data);
    }
    
    /**
     * Wysłanie odpowiedzi błędu do frontend
     */
    private function send_error_response($message) {
        wp_send_json_error(array('message' => $message));
    }
    
    /**
     * Handler dla sprawdzania kredytów użytkownika
     * Pomocny endpoint dla dynamicznego UI
     */
    public function handle_credits_check() {
        // Debug log
        error_log('PDFMaster: handle_credits_check called');

        try {
            // Use the same nonce as the main compression handler
            $this->validate_ajax_request('pdfmaster_compress_nonce');

            // TEMPORARY: Return default credits info for debugging
            $credits_info = array(
                'remaining_credits' => 3, // Default free credits
                'daily_limit' => 3,
                'is_premium' => false,
                'next_reset' => 'unknown'
            );

            /*
            // Check if user_credits is available
            if (!$this->user_credits || !method_exists($this->user_credits, 'get_remaining_credits')) {
                throw new Exception('System kredytów niedostępny.');
            }

            $credits_info = array(
                'remaining_credits' => $this->user_credits->get_remaining_credits(),
                'daily_limit' => method_exists($this->user_credits, 'get_daily_limit_for_user') ?
                    $this->user_credits->get_daily_limit_for_user() : 3,
                'is_premium' => method_exists($this->user_credits, 'is_premium_user') ?
                    $this->user_credits->is_premium_user() : false,
                'next_reset' => method_exists($this->user_credits, 'get_next_reset_time') ?
                    $this->user_credits->get_next_reset_time() : 'unknown'
            );
            */

            $this->send_success_response($credits_info);
        } catch (Exception $e) {
            $this->send_error_response($e->getMessage());
        }
    }
    
    /**
     * Handler dla sprawdzania statusu operacji
     * Możliwość monitorowania długotrwałych operacji
     */
    public function handle_operation_status() {
        $this->validate_ajax_request('pdfmaster_status_nonce');
        
        $operation_id = sanitize_text_field($_POST['operation_id'] ?? '');
        if (empty($operation_id)) {
            $this->send_error_response('Brak identyfikatora operacji.');
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'pdfmaster_operations';
        
        $operation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $operation_id
        ));
        
        if (!$operation) {
            $this->send_error_response('Nie znaleziono operacji.');
            return;
        }
        
        $this->send_success_response(array(
            'status' => $operation->status,
            'progress' => $this->calculate_operation_progress($operation),
            'error_message' => $operation->error_message
        ));
    }
    
    /**
     * Obliczanie postępu operacji
     * Prosty mechanizm dla progress bar
     */
    private function calculate_operation_progress($operation) {
        switch ($operation->status) {
            case 'processing':
                return 50; // Operacja w toku
            case 'completed':
                return 100; // Operacja zakończona
            case 'failed':
                return 0; // Operacja nie udana
            default:
                return 0; // Status nieznany
        }
    }
}
?>
