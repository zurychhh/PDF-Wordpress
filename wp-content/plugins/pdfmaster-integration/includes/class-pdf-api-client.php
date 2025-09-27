<?php
/**
 * Klasa PDFMaster_PDF_API_Client
 * 
 * Ta klasa odpowiada za całą komunikację z Stirling PDF API.
 * Enkapsuluje wszystkie szczegóły techniczne komunikacji HTTP,
 * pozwalając reszcie aplikacji używać prostych metod do przetwarzania PDF.
 * 
 * Dlaczego ta separacja jest ważna:
 * 1. Jeśli API Stirling PDF zmieni się, modyfikujemy tylko tę klasę
 * 2. Możemy łatwo dodać cache'owanie lub retry logic
 * 3. Łatwiejsze testowanie - możemy mockować API podczas testów
 * 4. Lepsze zarządzanie błędami - wszystkie błędy API w jednym miejscu
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit('Bezpośredni dostęp zabroniony.');
}

class PDFMaster_PDF_API_Client {
    
    /**
     * URL bazowy do API Stirling PDF
     * Używamy stałej zdefiniowanej w głównym pliku plugin
     */
    private $api_base_url;
    
    /**
     * Timeout dla żądań HTTP w sekundach
     * 300 sekund (5 minut) powinno wystarczyć nawet dla dużych plików
     */
    private $request_timeout;
    
    /**
     * Maksymalny rozmiar pliku w bajtach
     * 50MB powinno wystarczyć dla większości przypadków użycia
     */
    private $max_file_size;
    
    /**
     * Konstruktor klasy
     * Inicjalizuje podstawowe ustawienia dla komunikacji z API
     */
    public function __construct() {
        $this->api_base_url = PDFMASTER_STIRLING_API_URL;
        $this->request_timeout = 300; // 5 minut
        $this->max_file_size = 50 * 1024 * 1024; // 50MB w bajtach
        
        // Sprawdzenie czy API jest dostępne przy inicjalizacji
        add_action('init', array($this, 'check_api_health'), 15);
    }
    
    /**
     * Sprawdzenie zdrowia API
     * Ta metoda jest wywoływana przy każdym ładowaniu strony,
     * ale tylko raz dziennie faktycznie sprawdza API (cache)
     */
    public function check_api_health() {
        // Sprawdź cache - nie chcemy sprawdzać API przy każdym żądaniu
        $last_check = get_transient('pdfmaster_api_health_check');
        if ($last_check !== false) {
            return $last_check; // API sprawdzone w ciągu ostatniej godziny
        }
        
        // Sprawdzenie czy API odpowiada
        $health_url = $this->api_base_url . '/api/v1/info/status';
        $response = wp_remote_get($health_url, array(
            'timeout' => 10, // Krótki timeout dla health check
            'sslverify' => false // Dla środowiska lokalnego
        ));
        
        $is_healthy = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        
        // Cache wyników na godzinę
        set_transient('pdfmaster_api_health_check', $is_healthy, HOUR_IN_SECONDS);
        
        // Logowanie problemów z API
        if (!$is_healthy) {
            error_log('PDFMaster: API Stirling PDF nie odpowiada. URL: ' . $health_url);
        }
        
        return $is_healthy;
    }
    
    /**
     * Kompresja pliku PDF
     * 
     * Ta metoda przyjmuje ścieżkę do pliku PDF i poziom kompresji,
     * wysyła plik do API Stirling PDF i zwraca skompresowany plik.
     * 
     * @param string $file_path Ścieżka do pliku PDF na serwerze
     * @param int $compression_level Poziom kompresji (1=niska, 2=średnia, 3=wysoka)
     * @return array Tablica z wynikiem operacji
     */
    public function compress_pdf($file_path, $compression_level = 2) {
        // Walidacja parametrów wejściowych
        $validation_result = $this->validate_compression_params($file_path, $compression_level);
        if (!$validation_result['valid']) {
            return $this->create_error_response($validation_result['message']);
        }
        
        // Przygotowanie danych do wysłania - correct Stirling PDF endpoint
        $endpoint = '/api/v1/misc/compress-pdf';
        $url = $this->api_base_url . $endpoint;
        
        // Tworzenie struktury danych multipart/form-data - correct Stirling PDF parameters
        $boundary = wp_generate_uuid4();
        $post_data = $this->build_multipart_data($file_path, array(
            'optimizeLevel' => $compression_level, // 1-3 PDF compression, 4-6 lite image, 7-9 intense image
            'grayscale' => 'false', // Keep color by default
            'expectedOutputSize' => '' // Optional field, leave empty
        ), $boundary);
        
        // Wykonanie żądania HTTP
        $response = $this->make_http_request($url, $post_data, $boundary);
        
        // Przetworzenie odpowiedzi
        return $this->process_api_response($response, 'compress');
    }
    
    /**
     * Walidacja parametrów dla kompresji PDF
     * Sprawdza czy plik istnieje, czy ma odpowiedni typ i rozmiar
     */
    private function validate_compression_params($file_path, $compression_level) {
        // Sprawdzenie czy plik istnieje
        if (!file_exists($file_path)) {
            return array('valid' => false, 'message' => 'Plik nie został znaleziony na serwerze');
        }
        
        // Sprawdzenie typu pliku
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $file_path);
        finfo_close($file_info);
        
        if ($mime_type !== 'application/pdf') {
            return array('valid' => false, 'message' => 'Plik nie jest prawidłowym dokumentem PDF');
        }
        
        // Sprawdzenie rozmiaru pliku
        $file_size = filesize($file_path);
        if ($file_size > $this->max_file_size) {
            $max_mb = $this->max_file_size / (1024 * 1024);
            return array('valid' => false, 'message' => "Plik jest za duży (maksymalnie {$max_mb}MB)");
        }
        
        // Sprawdzenie poziomu kompresji
        if (!in_array($compression_level, array(1, 2, 3))) {
            return array('valid' => false, 'message' => 'Nieprawidłowy poziom kompresji');
        }
        
        return array('valid' => true);
    }
    
    /**
     * Obliczanie oczekiwanej redukcji rozmiaru na podstawie poziomu kompresji
     * Te wartości są oparte na testach i mogą być dostosowane
     */
    private function calculate_expected_reduction($compression_level) {
        switch ($compression_level) {
            case 1: return 20; // Niska kompresja - 20% redukcji
            case 2: return 40; // Średnia kompresja - 40% redukcji
            case 3: return 60; // Wysoka kompresja - 60% redukcji
            default: return 40;
        }
    }
    
    /**
     * Budowanie danych multipart/form-data
     * API Stirling PDF wymaga tego formatu do przesyłania plików
     */
    private function build_multipart_data($file_path, $additional_fields, $boundary) {
        $data = '';
        
        // Dodawanie dodatkowych pól formularza
        foreach ($additional_fields as $name => $value) {
            $data .= "--{$boundary}\r\n";
            $data .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $data .= "{$value}\r\n";
        }
        
        // Dodawanie pliku
        $filename = basename($file_path);
        $data .= "--{$boundary}\r\n";
        $data .= "Content-Disposition: form-data; name=\"fileInput\"; filename=\"{$filename}\"\r\n";
        $data .= "Content-Type: application/pdf\r\n\r\n";
        $data .= file_get_contents($file_path) . "\r\n";
        $data .= "--{$boundary}--\r\n";
        
        return $data;
    }
    
    /**
     * Wykonywanie żądania HTTP do API
     * Używamy WordPress HTTP API dla lepszej kompatybilności
     */
    private function make_http_request($url, $post_data, $boundary) {
        $headers = array(
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Content-Length' => strlen($post_data)
        );
        
        $args = array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => $post_data,
            'timeout' => $this->request_timeout,
            'sslverify' => false, // Dla środowiska lokalnego
            'data_format' => 'body'
        );
        
        // Logowanie żądania dla debugowania
        error_log('PDFMaster: Wysyłanie żądania do API: ' . $url);
        
        $response = wp_remote_post($url, $args);
        
        // Logowanie odpowiedzi
        if (is_wp_error($response)) {
            error_log('PDFMaster: Błąd komunikacji z API: ' . $response->get_error_message());
        } else {
            error_log('PDFMaster: Odpowiedź API - kod: ' . wp_remote_retrieve_response_code($response));
        }
        
        return $response;
    }
    
    /**
     * Przetwarzanie odpowiedzi z API
     * Sprawdza czy operacja się udała i formatuje wynik
     */
    private function process_api_response($response, $operation_type) {
        // Sprawdzenie czy wystąpiły błędy komunikacji
        if (is_wp_error($response)) {
            return $this->create_error_response('Błąd komunikacji z serwerem: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Sprawdzenie kodu odpowiedzi HTTP
        if ($response_code !== 200) {
            $error_message = $this->parse_api_error($response_body, $response_code);
            return $this->create_error_response($error_message);
        }
        
        // Sprawdzenie czy odpowiedź zawiera plik PDF
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (strpos($content_type, 'application/pdf') === false) {
            return $this->create_error_response('Serwer zwrócił nieprawidłowy format pliku');
        }
        
        // Sukces - zwracamy dane pliku
        return array(
            'success' => true,
            'data' => $response_body,
            'operation' => $operation_type,
            'original_size' => null, // Zostanie wypełnione przez wywołującą metodę
            'processed_size' => strlen($response_body),
            'content_type' => $content_type
        );
    }
    
    /**
     * Parsowanie błędów z API
     * Próbuje wyciągnąć użyteczną informację z odpowiedzi błędu
     */
    private function parse_api_error($response_body, $response_code) {
        // Próba parsowania JSON-a (niektóre błędy API są w tym formacie)
        $json_data = json_decode($response_body, true);
        if ($json_data && isset($json_data['message'])) {
            return 'Błąd API: ' . $json_data['message'];
        }
        
        // Standardowe komunikaty dla kodów HTTP
        switch ($response_code) {
            case 400:
                return 'Nieprawidłowe żądanie - sprawdź czy plik PDF nie jest uszkodzony';
            case 413:
                return 'Plik jest za duży dla serwera';
            case 500:
                return 'Błąd wewnętrzny serwera przetwarzania PDF';
            case 503:
                return 'Serwer przetwarzania PDF jest tymczasowo niedostępny';
            default:
                return "Nieoczekiwany błąd serwera (kod: {$response_code})";
        }
    }
    
    /**
     * Tworzenie standardowej struktury odpowiedzi błędu
     */
    private function create_error_response($message) {
        return array(
            'success' => false,
            'error' => $message,
            'operation' => null,
            'data' => null
        );
    }
    
    /**
     * Sprawdzenie czy API jest dostępne
     * Publiczna metoda do sprawdzania statusu API
     */
    public function is_api_available() {
        return $this->check_api_health();
    }
    
    /**
     * Pobranie informacji o API
     * Zwraca podstawowe informacje o konfiguracji i dostępności
     */
    public function get_api_info() {
        return array(
            'base_url' => $this->api_base_url,
            'timeout' => $this->request_timeout,
            'max_file_size' => $this->max_file_size,
            'max_file_size_mb' => round($this->max_file_size / (1024 * 1024), 1),
            'is_available' => $this->is_api_available()
        );
    }
}
?>
