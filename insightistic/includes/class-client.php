<?php

namespace Insightistic\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Signs and sends requests to the Insightistic SaaS API.
 *
 * Canonical signing string (MUST match the server's ConnectorAuth middleware):
 *   METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256_hex(body)
 *
 * The secret is used only to compute the HMAC locally; it is never sent.
 * `/api/plugin/activate` is the one call made before any secret exists — it
 * authenticates by the license key in the body instead, so it goes out
 * unsigned via unsigned().
 */
class Client
{
    /**
     * HMAC-signed request using the stored per-site connector credentials.
     * Used for everything after activation: /api/plugin/validate|features|
     * deactivate and all /api/connector/v1/* sync endpoints.
     *
     * @param array|null $body JSON body (null/[] for empty)
     * @return array{ok:bool,status:int,data:mixed,error:string|null}
     */
    public static function request(string $method, string $path, ?array $body = null): array
    {
        $settings = Settings::all();
        $keyId    = $settings['key_id'];
        $secret   = $settings['secret'];
        $baseUrl  = rtrim($settings['api_base'] ?: $settings['saas_url'], '/');

        if (empty($keyId) || empty($secret) || empty($baseUrl)) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Missing connector credentials or API base URL.'];
        }

        return self::send($method, $baseUrl, $path, $body, $keyId, $secret);
    }

    /**
     * Unsigned request — only /api/plugin/activate should ever use this,
     * since it is the sole call made before connector credentials exist.
     *
     * @return array{ok:bool,status:int,data:mixed,error:string|null}
     */
    public static function unsigned(string $method, string $baseUrl, string $path, ?array $body = null): array
    {
        return self::send($method, rtrim($baseUrl, '/'), $path, $body, null, null);
    }

    private static function send(string $method, string $baseUrl, string $path, ?array $body, ?string $keyId, ?string $secret): array
    {
        $method = strtoupper($method);
        $url    = $baseUrl . $path;
        $json   = ($body === null) ? '' : wp_json_encode($body);

        $headers = ['Accept' => 'application/json'];
        if ($json !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        if ($keyId && $secret) {
            $signPath  = wp_parse_url($url, PHP_URL_PATH); // exactly what the server sees
            $timestamp = (string) time();
            $nonce     = wp_generate_uuid4();
            $bodyHash  = hash('sha256', $json);

            $canonical = implode("\n", [$method, $signPath, $timestamp, $nonce, $bodyHash]);
            $signature = hash_hmac('sha256', $canonical, $secret);

            $headers['X-INS-Key-Id']    = $keyId;
            $headers['X-INS-Timestamp'] = $timestamp;
            $headers['X-INS-Nonce']     = $nonce;
            $headers['X-INS-Signature'] = $signature;
        }

        $args = ['timeout' => 30, 'headers' => $headers];

        if ($method === 'GET') {
            $response = wp_remote_get($url, $args);
        } elseif ($method === 'POST') {
            $args['body'] = $json;
            $response = wp_remote_post($url, $args);
        } else {
            $args['method'] = $method;
            $args['body']   = $json;
            $response = wp_remote_request($url, $args);
        }

        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $ok   = $code >= 200 && $code < 300;

        return [
            'ok'     => $ok,
            'status' => $code,
            'data'   => $data,
            'error'  => $ok ? null : (is_array($data) && isset($data['message']) ? $data['message'] : 'HTTP ' . $code),
        ];
    }
}
