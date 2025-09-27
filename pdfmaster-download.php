<?php
/**
 * PDFMaster Download Handler
 * Direct download endpoint that bypasses WordPress rewrite complexity
 */

// Parse the request URL to extract filename and token
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove base path
$path = str_replace('/pdfmaster/', '', $path);

// Check if this is our download endpoint
if (!preg_match('~^pdfmaster-download/([^/]+)/(.+)$~', $path, $matches)) {
    // Not our endpoint, let WordPress handle it normally
    return;
}

$filename = $matches[1];
$token = urldecode($matches[2]); // Decode URL encoding

// Bootstrap WordPress minimal setup
define('WP_USE_THEMES', false);
require_once('./wp-load.php');

// Create file manager instance
if (!class_exists('PDFMaster_File_Manager')) {
    http_response_code(500);
    die('File manager not available');
}

$file_manager = new PDFMaster_File_Manager();

// Use reflection to access private method for token validation
$reflection = new ReflectionClass($file_manager);

// Validate token
$validate_method = $reflection->getMethod('validate_download_token');
$validate_method->setAccessible(true);

if (!$validate_method->invoke($file_manager, $filename, $token)) {
    wp_die('Nieprawidłowy lub wygasły link do pobrania.', 'Błąd autoryzacji', array('response' => 403));
    exit;
}

// Get processed directory
$processed_dir_property = $reflection->getProperty('processed_dir');
$processed_dir_property->setAccessible(true);
$processed_dir = $processed_dir_property->getValue($file_manager);

// Check if file exists
$file_path = $processed_dir . '/' . $filename;
if (!file_exists($file_path)) {
    wp_die('Plik nie został znaleziony lub już wygasł.', 'Plik niedostępny', array('response' => 404));
    exit;
}

// Get file metadata
$get_metadata_method = $reflection->getMethod('get_file_metadata');
$get_metadata_method->setAccessible(true);
$file_metadata = $get_metadata_method->invoke($file_manager, $filename);

if (!$file_metadata) {
    wp_die('Brak metadanych pliku.', 'Błąd serwera', array('response' => 500));
    exit;
}

// Increment download counter
$increment_counter_method = $reflection->getMethod('increment_download_counter');
$increment_counter_method->setAccessible(true);
$increment_counter_method->invoke($file_manager, $filename);

// Send file headers
$send_headers_method = $reflection->getMethod('send_file_headers');
$send_headers_method->setAccessible(true);
$send_headers_method->invoke($file_manager, $file_metadata);

// Send file content
readfile($file_path);
exit;
?>