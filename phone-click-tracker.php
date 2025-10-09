<?php
/**
 * Plugin Name:       Phone Click Tracker
 * Description:       Śledzi kliknięcia w linki telefoniczne i wysyła dane do Zapiera przez bezpieczny endpoint AJAX.
 * Version:           1.6.0
 * Author:            MGlowacki
 * License:           GPLv2 or later
 * Text Domain:       phone-click-tracker
 */

// --- DEBUG FLAG ---
// Ustaw na 'true', aby włączyć logowanie do pliku /wp-content/debug.log
// Ustaw na 'false' na środowisku produkcyjnym.
define('PCT_DEBUG_MODE', true);

// Zabezpieczenie przed bezpośrednim dostępem do pliku
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dedykowana funkcja logująca dla wtyczki.
 * Zapisuje wiadomości do /wp-content/debug.log, jeśli PCT_DEBUG_MODE jest włączone.
 *
 * @param mixed $message Wiadomość lub obiekt/tablica do zalogowania.
 */
function pct_log($message) {
    if (defined('PCT_DEBUG_MODE') && PCT_DEBUG_MODE === true) {
        $log_message = '[' . date('Y-m-d H:i:s') . ' - Phone Click Tracker] ';
        if (is_array($message) || is_object($message)) {
            $log_message .= print_r($message, true);
        } else {
            $log_message .= $message;
        }
        // Używa error_log do bezpiecznego zapisu do pliku z odpowiednimi uprawnieniami
        error_log($log_message . "\n", 3, WP_CONTENT_DIR . '/debug.log');
    }
}

pct_log('Wtyczka załadowana.');

/**
 * Pobiera adres IP użytkownika w sposób niezawodny, uwzględniając proxy.
 *
 * @return string|false Adres IP użytkownika lub false w przypadku niepowodzenia.
 */
function pct_get_user_ip() {
    $ip_keys = [
        'HTTP_CLIENT_IP', 
        'HTTP_X_FORWARDED_FOR', 
        'HTTP_X_FORWARDED', 
        'HTTP_X_CLUSTER_CLIENT_IP', 
        'HTTP_FORWARDED_FOR', 
        'HTTP_FORWARDED', 
        'REMOTE_ADDR'
    ];

    foreach ( $ip_keys as $key ) {
        if ( array_key_exists( $key, $_SERVER ) === true ) {
            foreach ( explode( ',', $_SERVER[ $key ] ) as $ip ) {
                $ip = trim( $ip );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ) {
                    pct_log("Znaleziono IP ($ip) w kluczu $_SERVER[$key].");
                    return $ip;
                }
            }
        }
    }

    $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : false;
    pct_log("Nie znaleziono IP w nagłówkach proxy, używam REMOTE_ADDR: " . ($remote_addr ? $remote_addr : 'Brak'));
    return $remote_addr;
}

/**
 * Sprawdza, czy bieżący adres IP znajduje się na liście wykluczonych.
 * Lista jest modyfikowalna za pomocą filtra 'pct_excluded_ips'.
 *
 * @return bool True, jeśli IP jest wykluczone, w przeciwnym razie false.
 */
function pct_is_ip_excluded() {
    $current_ip = pct_get_user_ip();

    // W trybie debugowania, zezwalaj na testowanie z określonych adresów IP, nawet jeśli są na liście wykluczonych.
    if (defined('PCT_DEBUG_MODE') && PCT_DEBUG_MODE === true) {
        $debug_allowed_ips = ['194.11.193.241', '171.25.230.115'];
        if (in_array($current_ip, $debug_allowed_ips, true)) {
            pct_log("Tryb debugowania jest włączony. IP $current_ip jest dozwolone do testów i nie będzie blokowane.");
            return false; // Nie wykluczaj tego IP
        }
    }

    // Domyślna lista wykluczonych adresów IP (np. biuro, dom)
    $excluded_ips = [
        '127.0.0.1',
        '::1',
        '194.11.193.241',
        '171.25.230.115',
        '62.3.27.82',
    ];

    // Pozwalamy na modyfikację listy z zewnątrz (np. przez inną wtyczkę lub functions.php)
    $filtered_ips = apply_filters('pct_excluded_ips', $excluded_ips);
    
    pct_log("Sprawdzanie wykluczenia dla IP: " . ($current_ip ? $current_ip : 'Nieznane'));

    if ( ! $current_ip ) {
        pct_log("Nie udało się uzyskać IP, nie można sprawdzić wykluczenia.");
        return false;
    }

    $is_excluded = in_array($current_ip, $filtered_ips, true);
    if ($is_excluded) {
        pct_log("IP $current_ip znajduje się na liście wykluczonych.");
    }

    return $is_excluded;
}

/**
 * Rejestruje i lokalizuje skrypt śledzący, ale tylko dla użytkowników, którzy nie są wykluczeni.
 */
function pct_enqueue_tracker_scripts() {
    pct_log('Inicjalizacja skryptów na stronie: ' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A'));
    
    // Skrypt JS nie zostanie w ogóle załadowany, jeśli IP jest na liście wykluczonych.
    if ( pct_is_ip_excluded() ) {
        pct_log('IP wykluczone. Skrypt śledzący nie zostanie załadowany.');
        return;
    }

    // --- POPRAWKA: Dynamiczne wersjonowanie pliku JS w celu ominięcia cache ---
    $script_path = plugin_dir_path( __FILE__ ) . 'js/tracker.js';
    // Używamy czasu ostatniej modyfikacji pliku jako jego wersji.
    // Zapewnia to, że przeglądarki zawsze pobiorą najnowszą wersję po każdej zmianie.
    $script_version = file_exists( $script_path ) ? filemtime( $script_path ) : '1.6.0';
    
    // 1. Rejestracja skryptu JavaScript
    wp_enqueue_script(
        'phone-tracker-script',
        plugin_dir_url( __FILE__ ) . 'js/tracker.js',
        ['jquery'],
        $script_version, // Użycie dynamicznej wersji
        true
    );

    // 2. Przekazanie danych z PHP do JavaScript
    $config_data = [
        'ajax_url'   => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('phone_tracker_nonce'),
        'debug_mode' => defined('PCT_DEBUG_MODE') && PCT_DEBUG_MODE,
    ];

    pct_log('Skrypt JS dodany do kolejki. Przekazywane dane konfiguracyjne:');
    pct_log($config_data);

    wp_localize_script(
        'phone-tracker-script',
        'phone_tracker_config',
        $config_data
    );
}
add_action('wp_enqueue_scripts', 'pct_enqueue_tracker_scripts');

/**
 * Tworzy endpoint AJAX, który nasłuchuje na dane z frontendu.
 */
function pct_handle_phone_click_ajax() {
    pct_log('Otrzymano żądanie AJAX.');

    if ( pct_is_ip_excluded() ) {
        pct_log('Żądanie AJAX odrzucone - IP jest wykluczone.');
        wp_send_json_success('IP wykluczone. Dane nie zostały wysłane.');
        return;
    }

    // 1. Weryfikacja bezpieczeństwa (nonce)
    if (!check_ajax_referer('phone_tracker_nonce', 'security', false)) {
        pct_log('BŁĄD: Weryfikacja nonce nie powiodła się.');
        wp_send_json_error('Błąd bezpieczeństwa (nonce).', 403);
        return;
    }
    pct_log('Weryfikacja nonce pomyślna.');

    // 2. Sprawdzenie i pobranie danych
    if (!isset($_POST['payload']) || !is_array($_POST['payload'])) {
        pct_log('BŁĄD: Brak danych "payload" w żądaniu AJAX.');
        wp_send_json_error('Brak danych.', 400);
        return;
    }
    $payload = stripslashes_deep($_POST['payload']);
    pct_log('Odebrano dane (payload):');
    pct_log($payload);

    // 3. Pobranie URL-a do Zapiera z konfiguracji (wp-config.php) lub filtra
    $zapier_webhook_url = null;
    if ( defined('PCT_ZAPIER_WEBHOOK_URL') && PCT_ZAPIER_WEBHOOK_URL ) {
        $zapier_webhook_url = PCT_ZAPIER_WEBHOOK_URL;
        pct_log('Znaleziono URL webhooka w stałej PCT_ZAPIER_WEBHOOK_URL.');
    }
    // Pozwól nadpisać z zewnątrz (np. w prywatnym motywie/wtyczce)
    $zapier_webhook_url = apply_filters('pct_zapier_webhook_url', $zapier_webhook_url, $payload);
    pct_log('URL webhooka po zastosowaniu filtrów: ' . ($zapier_webhook_url ? $zapier_webhook_url : 'Brak'));

    if ( ! $zapier_webhook_url ) {
        pct_log('KRYTYCZNY BŁĄD: Brak konfiguracji webhooka Zapier (PCT_ZAPIER_WEBHOOK_URL). Nie można wysłać danych.');
        wp_send_json_error('Brak konfiguracji webhooka Zapier (PCT_ZAPIER_WEBHOOK_URL).', 500);
        return;
    }

    // 4. Wysłanie danych z serwera do Zapiera
    pct_log('Próba wysłania danych do Zapiera.');
    $response = wp_remote_post($zapier_webhook_url, [
        'method'    => 'POST',
        'body'      => $payload,
        'timeout'   => 15,
    ]);

    // 5. Zwrócenie odpowiedzi
    if (is_wp_error($response)) {
        pct_log('BŁĄD WP_Error podczas wysyłki do Zapiera: ' . $response->get_error_message());
        wp_send_json_error($response->get_error_message(), 500);
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        pct_log("Dane pomyślnie wysłane do Zapiera. Kod odpowiedzi: $response_code");
        pct_log("Odpowiedź z Zapiera: " . $response_body);
        wp_send_json_success('Dane wysłane.');
    }
}
// Działa dla zalogowanych i niezalogowanych użytkowników
add_action('wp_ajax_nopriv_track_phone_click', 'pct_handle_phone_click_ajax');
add_action('wp_ajax_track_phone_click', 'pct_handle_phone_click_ajax');
add_action('wp_ajax_nopriv_track_email_click', 'pct_handle_phone_click_ajax');
add_action('wp_ajax_track_email_click', 'pct_handle_phone_click_ajax');