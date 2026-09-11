<?php
/**
 * Shared, function_exists-guarded stubs for the WordPress shims the plugin
 * adopted for wordpress.org Plugin Check compliance (wp_parse_url instead of
 * parse_url, wp_unslash + sanitize_* on superglobal reads, translated +
 * escaped strings). Each WP logic test still defines its OWN behavioral stubs
 * (options, HTTP, nonces); this file only backfills the mechanical ones, and
 * must be required AFTER the test's own stubs so a test's specific version
 * always wins.
 */

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim(preg_replace('/[\r\n\t ]+/', ' ', wp_strip_all_tags_stub((string) $str))); }
}
if (!function_exists('wp_strip_all_tags_stub')) {
    function wp_strip_all_tags_stub(string $str): string { return strip_tags($str); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($name) { return trim((string) $name); }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('wp_delete_file')) {
    function wp_delete_file($file) { @unlink($file); }
}
