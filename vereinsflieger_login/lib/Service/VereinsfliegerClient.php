<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\Http\Client\IClientService;

final class VereinsfliegerClient
{
    private const BASE = 'https://www.vereinsflieger.de/interface/rest/';
    public function __construct(private IClientService $http, private Configuration $config, private RequestGuard $guard)
    {
    }
    private function request(RequestPermit $permit, string $method, string $path, array $data = []): array
    {
        $options = ['timeout' => 15, 'connect_timeout' => 5, 'verify' => true, 'allow_redirects' => false,
            'http_errors' => false, 'headers' => ['Accept' => 'application/json']];
        if ($data !== []) {
            $options['body'] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        try {
            $client = $this->http->newClient();
            $permit->beforeRequest();
            $response = match ($method) {
                'GET' => $client->get(self::BASE . $path, $options),
                'POST' => $client->post(self::BASE . $path, $options),
                'DELETE' => $client->delete(self::BASE . $path, $options),
                default => throw new \LogicException('Unsupported API method'),
            };
            if ($response->getStatusCode() === 429) {
                throw new ApiException('rate_limit', $this->guard->providerLimited($this->config->key(), $this->config->read()));
            }
            if ($response->getStatusCode() >= 500) {
                throw new ApiException('unavailable');
            }
            $body = (string)$response->getBody();
            if (strlen($body) > 65536) {
                throw new ApiException('invalid_response');
            }
            $json = $body === '' ? [] : json_decode($body, true);
            return ['status' => $response->getStatusCode(), 'data' => is_array($json) ? $json : []];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ApiException('unavailable');
        }
    }
    private function token(RequestPermit $permit): string
    {
        $r = $this->request($permit, 'GET', 'auth/accesstoken');
        $token = $r['data']['accesstoken'] ?? '';
        if ($r['status'] !== 200 || !is_string($token) || !preg_match('/^[A-Za-z0-9_-]{8,256}$/D', $token)) {
            throw new ApiException($r['status'] === 429 ? 'rate_limit' : 'invalid_response');
        }
        return $token;
    }
    private function signout(RequestPermit $permit, string $token): void
    {
        try {
            $this->request($permit, 'DELETE', 'auth/signout/' . rawurlencode($token), ['accesstoken' => $token]);
        } catch (ApiException) { /* Never replace the original result with a cleanup error. */
        }
    }
    public static function passwordHash(string $password): string
    {
        // Match the supplied official PHP example, without silently replacing characters.
        if (!mb_check_encoding($password, 'UTF-8')) {
            throw new ApiException('password_encoding');
        }
        $encoded = mb_convert_encoding($password, 'ISO-8859-1', 'UTF-8');
        if (mb_convert_encoding($encoded, 'UTF-8', 'ISO-8859-1') !== $password) {
            throw new ApiException('password_encoding');
        }
        return md5($encoded);
    }
    public function authenticate(string $username, string $password, string $otp, string $ip): Identity
    {
        if (strlen($username) > 254 || strlen($password) > 1024 || strlen($otp) > 32) {
            throw new ApiException('input_format');
        }
        foreach ([$username, $password, $otp] as $value) {
            if (!mb_check_encoding($value, 'UTF-8') || preg_match('/\p{Cc}/u', $value)) {
                throw new ApiException('input_format');
            }
        }
        $username = trim($username);
        $otp = trim($otp);
        if ($username === '' || $password === '') {
            throw new ApiException('input_format');
        }
        // All input checks and lossless conversion precede quota reservation.
        $hash = self::passwordHash($password);
        $settings = $this->config->read();
        $key = $this->config->key();
        $permit = $this->guard->admit($username, $ip, $key, $settings['dailyApiBudget'], $settings);
        $token = null;
        try {
            $token = $this->token($permit);
            $r = $this->request($permit, 'POST', 'auth/signin', ['accesstoken' => $token, 'username' => $username,
                'password' => $hash, 'appkey' => $key, 'cid' => $settings['cid'], 'auth_secret' => $otp]);
            if ($r['status'] === 403 && (int)($r['data']['need_2fa'] ?? 0) === 1) {
                throw new ApiException('two_factor');
            }
            if ($r['status'] !== 200) {
                throw new ApiException($r['status'] === 429 ? 'rate_limit' : 'credentials');
            }
            $r = $this->request($permit, 'POST', 'auth/getuser', ['accesstoken' => $token]);
            if ($r['status'] !== 200) {
                throw new ApiException($r['status'] === 429 ? 'rate_limit' : 'unavailable');
            }
            $identity = Identity::fromResponse($r['data']);
            $this->guard->succeeded($username, $ip, $key);
            return $identity;
        } catch (ApiException $e) {
            if ($e->reason === 'credentials' || ($e->reason === 'two_factor' && $otp !== '')) {
                $retryAfter = $this->guard->failed($username, $ip, $key, $settings);
                if ($retryAfter > 0) {
                    throw new ApiException('login_pause', $retryAfter);
                }
            }
            throw $e;
        } finally {
            try {
                if ($token !== null) {
                    $this->signout($permit, $token);
                }
            } finally {
                $permit->close();
            }
        }
    }
}
