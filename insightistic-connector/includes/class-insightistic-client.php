<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Signs and sends requests to the Insightistic SaaS connector API.
 *
 * Canonical signing string (MUST match the server's ConnectorAuth middleware):
 *   METHOD \n PATH \n TIMESTAMP \n NONCE \n sha256_hex(body)
 *
 * The secret is used only to compute the HMAC locally; it is never sent.
 */
class Insightistic_Client
{
    /**
     * @param string     $path  API path, e.g. "/api/connector/v1/orders/bulk"
     * @param array|null $body  JSON body (null/[] for empty)
     * @return array{ok:bool,status:int,data:mixed,error:string|null}
     */
    public static function post(string $path, ?array $body = null): array
    {
        $settings = Insightistic_Settings::all();
        $keyId    = $settings['key_id'];
        $secret   = $settings['secret'];
        $baseUrl  = rtrim($settings['saas_url'], '/');

        if (empty($keyId) || empty($secret) || empty($baseUrl)) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Missing connector credentials or SaaS URL.'];
        }

        $url      = $baseUrl . $path;
        $signPath = wp_parse_url($url, PHP_URL_PATH); // exactly what the server sees
        $json     = ($body === null) ? '' : wp_json_encode($body);
        $timestamp = (string) time();
        $nonce     = wp_generate_uuid4();
        $bodyHash  = hash('sha256', $json);

        $canonical = implode("\n", [
            'POST',
            $signPath,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
                'X-INS-Key-Id'    => $keyId,
                'X-INS-Timestamp' => $timestamp,
                'X-INS-Nonce'     => $nonce,
                'X-INS-Signature' => $signature,
            ],
            'body' => $json,
        ]);

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
