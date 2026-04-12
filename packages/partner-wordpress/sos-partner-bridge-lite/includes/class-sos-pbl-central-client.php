<?php

if (!defined('ABSPATH')) {
    exit;
}

class SOS_PBL_Central_Client {
    /** @var SOS_PBL_Config */
    private $config;

    public function __construct(SOS_PBL_Config $config) {
        $this->config = $config;
    }

    public function post($path, array $body = [], array $headers = []) {
        return $this->request('POST', $path, $body, $headers);
    }

    public function post_form($path, array $body = [], array $headers = []) {
        $url = $this->build_url($path);

        $final_headers = $this->build_headers($headers);
        $final_headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return wp_remote_post($url, [
            'headers' => $final_headers,
            'body' => $body,
            'redirection' => 0,
            'timeout' => 20,
        ]);
    }

    public function get($path, array $query = [], array $headers = []) {
        $url = $this->build_url($path);
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        return wp_remote_get($url, [
            'headers' => $this->build_headers($headers),
            'timeout' => 15,
        ]);
    }

    public function request($method, $path, array $body = [], array $headers = []) {
        $url = $this->build_url($path);

        return wp_remote_request($url, [
            'method' => strtoupper((string) $method),
            'headers' => $this->build_headers($headers),
            'body' => wp_json_encode($body),
            'timeout' => 20,
        ]);
    }

    private function build_url($path) {
        $settings = $this->config->get();
        $base = rtrim((string) ($settings['central_base_url'] ?? ''), '/');
        $path = '/' . ltrim((string) $path, '/');

        return $base . $path;
    }

    private function build_headers(array $headers) {
        $settings = $this->config->get();
        $default_headers = [
            'Content-Type' => 'application/json',
            'X-SOS-Partner-ID' => (string) ($settings['partner_id'] ?? ''),
            'X-SOS-Partner-Token' => (string) ($settings['shared_secret'] ?? ''),
        ];

        return array_merge($default_headers, $headers);
    }
}
