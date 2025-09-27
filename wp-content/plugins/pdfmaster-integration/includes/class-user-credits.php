<?php
/**
 * Klasa PDFMaster_User_Credits
 * 
 * Ta klasa zarządza systemem kredytów i ograniczeń użytkowników w aplikacji PDFMaster.
 * Implementuje model biznesowy freemium, gdzie użytkownicy otrzymują ograniczoną liczbę
 * darmowych operacji, a następnie mogą kupić dodatkowe kredyty lub subskrypcje Premium.
 * 
 * Kluczowe funkcjonalności:
 * 1. Zarządzanie dziennymi limitami dla użytkowników darmowych
 * 2. Obsługa kont Premium z nielimitowanym dostępem  
 * 3. System jednorazowych pakietów kredytów
 * 4. Tracking użytkowania dla analityki biznesowej
 * 5. Integracja z WordPress user system
 * 6. Grace periods i user-friendly error handling
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit('Bezpośredni dostęp zabroniony.');
}

class PDFMaster_User_Credits {
    
    /**
     * Konfiguracja systemu kredytów
     * Te wartości można dostosować w zależności od strategii biznesowej
     */
    private $free_daily_limit;        // Dzienny limit dla użytkowników darmowych
    private $guest_daily_limit;       // Limit dla niezalogowanych użytkowników
    private $premium_unlimited;       // Czy użytkownicy Premium mają nieograniczony dostęp
    private $grace_period_hours;      // Okres grace po przekroczeniu limitu
    
    /**
     * Typy użytkowników w systemie
     */
    const USER_TYPE_GUEST = 'guest';
    const USER_TYPE_FREE = 'free';
    const USER_TYPE_PREMIUM = 'premium';
    const USER_TYPE_ENTERPRISE = 'enterprise';
    
    /**
     * Typy operacji PDF
     */
    const OPERATION_COMPRESS = 'compress';
    const OPERATION_SPLIT = 'split';
    const OPERATION_MERGE = 'merge';
    const OPERATION_CONVERT = 'convert';
    
    /**
     * Konstruktor klasy
     * Inicjalizuje ustawienia systemu kredytów
     */
    public function __construct() {
        // Pobranie ustawień z opcji WordPress (z wartościami domyślnymi)
        $this->free_daily_limit = get_option('pdfmaster_free_daily_limit', 3);
        $this->guest_daily_limit = get_option('pdfmaster_guest_daily_limit', 2);
        $this->premium_unlimited = get_option('pdfmaster_premium_unlimited', true);
        $this->grace_period_hours = get_option('pdfmaster_grace_period_hours', 1);
        
        // Rejestracja zadania czyszczenia statystyk użytkowania
        $this->setup_cleanup_schedule();
        
        // Hook do resetowania dziennych limitów o północy
        add_action('pdfmaster_reset_daily_limits', array($this, 'reset_all_daily_limits'));
        
        // Sprawdzenie czy zadanie resetowania jest zaplanowane
        if (!wp_next_scheduled('pdfmaster_reset_daily_limits')) {
            // Zaplanowanie resetowania o północy każdego dnia
            $tomorrow_midnight = strtotime('tomorrow 00:00:00');
            wp_schedule_event($tomorrow_midnight, 'daily', 'pdfmaster_reset_daily_limits');
        }
    }
    
    /**
     * Sprawdzenie czy użytkownik ma dostępne kredyty
     * Główna metoda używana przed każdą operacją PDF
     * 
     * @param string $operation_type Typ operacji PDF
     * @return bool True jeśli użytkownik może wykonać operację
     */
    public function has_available_credits($operation_type = self::OPERATION_COMPRESS) {
        $user_type = $this->get_user_type();
        
        // Użytkownicy Premium mają zawsze dostęp (w podstawowej wersji)
        if ($user_type === self::USER_TYPE_PREMIUM || $user_type === self::USER_TYPE_ENTERPRISE) {
            return true;
        }
        
        // Sprawdzenie czy użytkownik nie przekroczył dziennego limitu
        $daily_usage = $this->get_daily_usage();
        $daily_limit = $this->get_daily_limit($user_type);
        
        if ($daily_usage >= $daily_limit) {
            // Sprawdzenie czy użytkownik jest w grace period
            if ($this->is_in_grace_period()) {
                return true;
            }
            return false;
        }
        
        // Sprawdzenie czy użytkownik ma pakietowe kredyty (jeśli to zalogowany użytkownik)
        if (is_user_logged_in()) {
            $purchased_credits = $this->get_purchased_credits();
            if ($purchased_credits > 0) {
                return true;
            }
        }
        
        // Jeśli nie przekroczył dziennego limitu, może wykonać operację
        return true;
    }
    
    /**
     * Odjęcie kredytu po wykonaniu operacji
     * Aktualizuje countery użytkowania
     * 
     * @param string $operation_type Typ wykonanej operacji
     * @param array $operation_metadata Dodatkowe informacje o operacji
     * @return bool True jeśli operacja się udała
     */
    public function deduct_credit($operation_type = self::OPERATION_COMPRESS, $operation_metadata = array()) {
        $user_type = $this->get_user_type();
        
        // Użytkownicy Premium nie tracą kredytów (w podstawowej wersji)
        if ($user_type === self::USER_TYPE_PREMIUM || $user_type === self::USER_TYPE_ENTERPRISE) {
            $this->log_usage($operation_type, $operation_metadata, 0); // 0 = no credit deducted
            return true;
        }
        
        // Najpierw próbuj odjąć z pakietowych kredytów (jeśli są dostępne)
        if (is_user_logged_in()) {
            $purchased_credits = $this->get_purchased_credits();
            if ($purchased_credits > 0) {
                $this->deduct_purchased_credit();
                $this->log_usage($operation_type, $operation_metadata, 1, 'purchased');
                return true;
            }
        }
        
        // Inaczej odejmij z dziennego limitu
        $this->increment_daily_usage();
        $this->log_usage($operation_type, $operation_metadata, 1, 'daily');
        
        return true;
    }
    
    /**
     * Pobranie typu użytkownika
     * Determinuje uprawnienia i limity
     */
    private function get_user_type() {
        if (!is_user_logged_in()) {
            return self::USER_TYPE_GUEST;
        }
        
        $user_id = get_current_user_id();
        
        // Sprawdzenie czy użytkownik ma aktywną subskrypcję Premium
        $subscription_status = get_user_meta($user_id, 'pdfmaster_subscription_status', true);
        if ($subscription_status === 'premium') {
            return self::USER_TYPE_PREMIUM;
        }
        
        if ($subscription_status === 'enterprise') {
            return self::USER_TYPE_ENTERPRISE;
        }
        
        // Domyślnie zalogowani użytkownicy to free tier
        return self::USER_TYPE_FREE;
    }
    
    /**
     * Pobranie dziennego limitu dla określonego typu użytkownika
     */
    private function get_daily_limit($user_type) {
        switch ($user_type) {
            case self::USER_TYPE_GUEST:
                return $this->guest_daily_limit;
            case self::USER_TYPE_FREE:
                return $this->free_daily_limit;
            case self::USER_TYPE_PREMIUM:
            case self::USER_TYPE_ENTERPRISE:
                return $this->premium_unlimited ? PHP_INT_MAX : 50; // Bardzo wysoki limit dla Premium
            default:
                return 0;
        }
    }
    
    /**
     * Pobranie aktualnego dziennego użytkowania
     * Różne mechaniznmy dla zalogowanych i niezalogowanych użytkowników
     */
    private function get_daily_usage() {
        if (is_user_logged_in()) {
            // Dla zalogowanych użytkowników używamy user meta
            $user_id = get_current_user_id();
            $today = date('Y-m-d');
            $usage_key = 'pdfmaster_daily_usage_' . $today;
            
            return intval(get_user_meta($user_id, $usage_key, true));
        } else {
            // Dla gości używamy transients z IP jako identyfikatorem
            $guest_identifier = $this->get_guest_identifier();
            $today = date('Y-m-d');
            $usage_key = 'pdfmaster_guest_usage_' . $guest_identifier . '_' . $today;
            
            return intval(get_transient($usage_key));
        }
    }
    
    /**
     * Zwiększenie licznika dziennego użytkowania
     */
    private function increment_daily_usage() {
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $today = date('Y-m-d');
            $usage_key = 'pdfmaster_daily_usage_' . $today;
            
            $current_usage = intval(get_user_meta($user_id, $usage_key, true));
            update_user_meta($user_id, $usage_key, $current_usage + 1);
        } else {
            $guest_identifier = $this->get_guest_identifier();
            $today = date('Y-m-d');
            $usage_key = 'pdfmaster_guest_usage_' . $guest_identifier . '_' . $today;
            
            $current_usage = intval(get_transient($usage_key));
            set_transient($usage_key, $current_usage + 1, DAY_IN_SECONDS);
        }
    }
    
    /**
     * Generowanie identyfikatora dla gości
     * Używa IP + User Agent dla rozpoznawania w ciągu dnia
     */
    private function get_guest_identifier() {
        $ip = $this->get_client_ip();
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100);
        return md5($ip . $user_agent);
    }
    
    /**
     * Pobranie adresu IP klienta (uwzględnia proxy)
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
     * Sprawdzenie czy użytkownik jest w grace period
     * Grace period pozwala na dodatkowe operacje przez krótki czas po przekroczeniu limitu
     */
    private function is_in_grace_period() {
        if (!is_user_logged_in()) {
            return false; // Grace period tylko dla zalogowanych użytkowników
        }
        
        $user_id = get_current_user_id();
        $grace_start = get_user_meta($user_id, 'pdfmaster_grace_period_start', true);
        
        if (empty($grace_start)) {
            // Użytkownik pierwszy raz przekracza limit - rozpocznij grace period
            update_user_meta($user_id, 'pdfmaster_grace_period_start', current_time('mysql'));
            return true;
        }
        
        // Sprawdź czy grace period jeszcze trwa
        $grace_end = strtotime($grace_start) + ($this->grace_period_hours * HOUR_IN_SECONDS);
        return time() < $grace_end;
    }
    
    /**
     * Zarządzanie pakietowymi kredytami (purchased credits)
     */
    public function get_purchased_credits() {
        if (!is_user_logged_in()) {
            return 0;
        }
        
        $user_id = get_current_user_id();
        return intval(get_user_meta($user_id, 'pdfmaster_purchased_credits', true));
    }
    
    /**
     * Dodanie pakietowych kredytów (po zakupie)
     */
    public function add_purchased_credits($amount, $transaction_id = null) {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $user_id = get_current_user_id();
        $current_credits = $this->get_purchased_credits();
        $new_total = $current_credits + $amount;
        
        update_user_meta($user_id, 'pdfmaster_purchased_credits', $new_total);
        
        // Logowanie transakcji
        $this->log_credit_transaction('purchase', $amount, $transaction_id);
        
        return true;
    }
    
    /**
     * Odjęcie pakietowego kredytu
     */
    private function deduct_purchased_credit() {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $user_id = get_current_user_id();
        $current_credits = $this->get_purchased_credits();
        
        if ($current_credits > 0) {
            update_user_meta($user_id, 'pdfmaster_purchased_credits', $current_credits - 1);
            return true;
        }
        
        return false;
    }
    
    /**
     * Pobranie informacji o pozostałych kredytach dla UI
     */
    public function get_remaining_credits() {
        $user_type = $this->get_user_type();
        
        if ($user_type === self::USER_TYPE_PREMIUM || $user_type === self::USER_TYPE_ENTERPRISE) {
            return array(
                'type' => 'unlimited',
                'remaining' => -1,
                'daily_limit' => -1,
                'purchased' => 0
            );
        }
        
        $daily_usage = $this->get_daily_usage();
        $daily_limit = $this->get_daily_limit($user_type);
        $daily_remaining = max(0, $daily_limit - $daily_usage);
        
        $purchased = is_user_logged_in() ? $this->get_purchased_credits() : 0;
        
        return array(
            'type' => 'limited',
            'remaining' => $daily_remaining,
            'daily_limit' => $daily_limit,
            'purchased' => $purchased,
            'total_available' => $daily_remaining + $purchased
        );
    }
    
    /**
     * Sprawdzenie czy użytkownik ma subskrypcję Premium
     */
    public function is_premium_user() {
        $user_type = $this->get_user_type();
        return in_array($user_type, array(self::USER_TYPE_PREMIUM, self::USER_TYPE_ENTERPRISE));
    }
    
    /**
     * Pobranie czasu do następnego resetu dziennych limitów
     */
    public function get_next_reset_time() {
        $tomorrow_midnight = strtotime('tomorrow 00:00:00');
        return date('Y-m-d H:i:s', $tomorrow_midnight);
    }
    
    /**
     * Reset dziennych limitów dla wszystkich użytkowników
     * Wywoływane przez WordPress Cron o północy
     */
    public function reset_all_daily_limits() {
        global $wpdb;
        
        // Usunięcie wszystkich meta keys dziennego użytkowania starszych niż 7 dni
        $seven_days_ago = date('Y-m-d', strtotime('-7 days'));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} 
             WHERE meta_key LIKE 'pdfmaster_daily_usage_%' 
             AND meta_key < %s",
            'pdfmaster_daily_usage_' . $seven_days_ago
        ));
        
        // Reset grace periods
        $wpdb->delete($wpdb->usermeta, array('meta_key' => 'pdfmaster_grace_period_start'));
        
        error_log('PDFMaster: Zresetowano dzienne limity użytkowników');
    }
    
    /**
     * Logowanie użytkowania do analityki
     */
    private function log_usage($operation_type, $metadata, $credits_deducted, $credit_source = 'daily') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_usage_log';
        $this->ensure_usage_log_table();
        
        $user_id = is_user_logged_in() ? get_current_user_id() : null;
        $guest_id = !is_user_logged_in() ? $this->get_guest_identifier() : null;
        
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'guest_id' => $guest_id,
                'operation_type' => $operation_type,
                'credits_deducted' => $credits_deducted,
                'credit_source' => $credit_source,
                'ip_address' => $this->get_client_ip(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                'metadata' => json_encode($metadata),
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Logowanie transakcji kredytowych
     */
    private function log_credit_transaction($transaction_type, $amount, $transaction_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_credit_transactions';
        $this->ensure_credit_transactions_table();
        
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => get_current_user_id(),
                'transaction_type' => $transaction_type,
                'amount' => $amount,
                'transaction_id' => $transaction_id,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );
    }
    
    /**
     * Utworzenie tabeli logowania użytkowania
     */
    private function ensure_usage_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_usage_log';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
     * Utworzenie tabeli transakcji kredytowych
     */
    private function ensure_credit_transactions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_credit_transactions';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
     * Konfiguracja harmonogramu czyszczenia
     */
    private function setup_cleanup_schedule() {
        if (!wp_next_scheduled('pdfmaster_cleanup_usage_logs')) {
            wp_schedule_event(time(), 'weekly', 'pdfmaster_cleanup_usage_logs');
        }
        
        add_action('pdfmaster_cleanup_usage_logs', array($this, 'cleanup_old_usage_logs'));
    }
    
    /**
     * Czyszczenie starych logów (starszych niż 90 dni)
     */
    public function cleanup_old_usage_logs() {
        global $wpdb;
        
        $ninety_days_ago = date('Y-m-d H:i:s', strtotime('-90 days'));
        
        $usage_table = $wpdb->prefix . 'pdfmaster_usage_log';
        $transactions_table = $wpdb->prefix . 'pdfmaster_credit_transactions';
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$usage_table} WHERE created_at < %s",
            $ninety_days_ago
        ));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$transactions_table} WHERE created_at < %s",
            $ninety_days_ago
        ));
        
        error_log('PDFMaster: Wyczyszczono stare logi użytkowania');
    }
    
    /**
     * Pobranie statystyk użytkowania dla admina
     */
    public function get_usage_statistics($days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pdfmaster_usage_log';
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = array();
        
        // Ogólne statystyki
        $stats['total_operations'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
            $start_date
        ));
        
        // Operacje według typu
        $operations_by_type = $wpdb->get_results($wpdb->prepare(
            "SELECT operation_type, COUNT(*) as count 
             FROM {$table_name} 
             WHERE created_at >= %s 
             GROUP BY operation_type 
             ORDER BY count DESC",
            $start_date
        ));
        
        $stats['operations_by_type'] = $operations_by_type;
        
        // Użytkownicy zalogowani vs goście
        $user_types = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                CASE WHEN user_id IS NOT NULL THEN 'logged_in' ELSE 'guest' END as user_type,
                COUNT(*) as count
             FROM {$table_name} 
             WHERE created_at >= %s 
             GROUP BY user_type",
            $start_date
        ));
        
        $stats['users_by_type'] = $user_types;
        
        return $stats;
    }
    
    /**
     * Pobranie dzienny limitu dla publicznego API
     */
    public function get_daily_limit_for_user() {
        $user_type = $this->get_user_type();
        return $this->get_daily_limit($user_type);
    }
}
?>
