<?php
/**
 * Plugin Name:       Phone Click Tracker
 * Description:       Śledzi kliknięcia w linki telefoniczne i wysyła dane do Zapiera przez bezpieczny endpoint AJAX.
 * Version:           1.5.0
 * Author:            MGlowacki
 * License:           GPLv2 or later
 * Text Domain:       phone-click-tracker
 */

// Zabezpieczenie przed bezpośrednim dostępem do pliku
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
                    return $ip;
                }
            }
        }
    }

    return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : false;
}

/**
 * Sprawdza, czy bieżący adres IP znajduje się na liście wykluczonych.
 * Lista jest modyfikowalna za pomocą filtra 'pct_excluded_ips'.
 *
 * @return bool True, jeśli IP jest wykluczone, w przeciwnym razie false.
 */
function pct_is_ip_excluded() {
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
    
    $current_ip = pct_get_user_ip();

    if ( ! $current_ip ) {
        return false;
    }

    return in_array($current_ip, $filtered_ips, true);
}

/**
 * Rejestruje i lokalizuje skrypt śledzący, ale tylko dla użytkowników, którzy nie są wykluczeni.
 */
function pct_enqueue_tracker_scripts() {
    
    // Skrypt JS nie zostanie w ogóle załadowany, jeśli IP jest na liście wykluczonych.
    if ( pct_is_ip_excluded() ) {
        return;
    }

    // --- POPRAWKA: Dynamiczne wersjonowanie pliku JS w celu ominięcia cache ---
    $script_path = plugin_dir_path( __FILE__ ) . 'js/tracker.js';
    // Używamy czasu ostatniej modyfikacji pliku jako jego wersji.
    // Zapewnia to, że przeglądarki zawsze pobiorą najnowszą wersję po każdej zmianie.
    $script_version = file_exists( $script_path ) ? filemtime( $script_path ) : '1.2.0';
    
    // 1. Rejestracja skryptu JavaScript
    wp_enqueue_script(
        'phone-tracker-script',
        plugin_dir_url( __FILE__ ) . 'js/tracker.js',
        ['jquery'],
        $script_version, // Użycie dynamicznej wersji
        true
    );

    // 2. Przekazanie danych z PHP do JavaScript
    wp_localize_script(
        'phone-tracker-script',
        'phone_tracker_config',
        [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('phone_tracker_nonce')
        ]
    );
}
add_action('wp_enqueue_scripts', 'pct_enqueue_tracker_scripts');

/**
 * Tworzy endpoint AJAX, który nasłuchuje na dane z frontendu.
 */
function pct_handle_phone_click_ajax() {
    if ( pct_is_ip_excluded() ) {
        wp_send_json_success('IP wykluczone. Dane nie zostały wysłane.');
        return;
    }

    // 1. Weryfikacja bezpieczeństwa (nonce)
    check_ajax_referer('phone_tracker_nonce', 'security');

    // 2. Sprawdzenie i pobranie danych
    if (!isset($_POST['payload']) || !is_array($_POST['payload'])) {
        wp_send_json_error('Brak danych.', 400);
    }
    $payload = stripslashes_deep($_POST['payload']);

    // 3. Pobranie URL-a do Zapiera z konfiguracji (wp-config.php) lub filtra
    $zapier_webhook_url = null;
    if ( defined('PCT_ZAPIER_WEBHOOK_URL') && PCT_ZAPIER_WEBHOOK_URL ) {
        $zapier_webhook_url = PCT_ZAPIER_WEBHOOK_URL;
    }
    // Pozwól nadpisać z zewnątrz (np. w prywatnym motywie/wtyczce)
    $zapier_webhook_url = apply_filters('pct_zapier_webhook_url', $zapier_webhook_url, $payload);

    if ( ! $zapier_webhook_url ) {
        wp_send_json_error('Brak konfiguracji webhooka Zapier (PCT_ZAPIER_WEBHOOK_URL).', 500);
    }

    // 4. Wysłanie danych z serwera do Zapiera
    $response = wp_remote_post($zapier_webhook_url, [
        'method'    => 'POST',
        'body'      => $payload,
        'timeout'   => 15,
    ]);

    // 5. Zwrócenie odpowiedzi
    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message(), 500);
    } else {
        wp_send_json_success('Dane wysłane.');
    }
}
// Działa dla zalogowanych i niezalogowanych użytkowników
add_action('wp_ajax_nopriv_track_phone_click', 'pct_handle_phone_click_ajax');
add_action('wp_ajax_track_phone_click', 'pct_handle_phone_click_ajax');
add_action('wp_ajax_nopriv_track_email_click', 'pct_handle_phone_click_ajax');
add_action('wp_ajax_track_email_click', 'pct_handle_phone_click_ajax');