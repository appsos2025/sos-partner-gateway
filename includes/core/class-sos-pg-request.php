<?php
if (!defined('ABSPATH')) exit;

class SOS_PG_Request {
    public static function path() {
        return (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    }

    public static function method() {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    }

    public static function ip() {
        return sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    public static function headers() {
        $raw = function_exists('getallheaders') ? getallheaders() : [];
        return array_change_key_case((array) $raw, CASE_LOWER);
    }

    public static function header($name) {
        $headers = self::headers();
        $key = strtolower((string) $name);
        return $headers[$key] ?? null;
    }
}
