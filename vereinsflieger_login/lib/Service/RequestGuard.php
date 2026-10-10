<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

final class RequestGuard
{
    public function __construct(private CounterStore $store, private IConfig $config, private ITimeFactory $clock)
    {
    }
    private function key(string $appkey, string $kind, string $username = '', string $ip = ''): string
    {
        $secret = (string)$this->config->getSystemValue('secret', '');
        if ($secret === '') {
            throw new \RuntimeException('Server secret unavailable');
        }
        $ip = inet_pton($ip) === false ? $ip : bin2hex(inet_pton($ip));
        return hash_hmac('sha256', json_encode([$appkey, $kind, mb_strtolower(trim($username), 'UTF-8'), $ip], JSON_THROW_ON_ERROR), $secret);
    }
    private function budgetKey(string $appkey, int $now): string
    {
        return $this->key($appkey, 'budget:' . gmdate('Y-m-d', $now));
    }
    private function hourlyKey(string $appkey, int $now): string
    {
        return $this->key($appkey, 'hour_budget:' . gmdate('Y-m-d-H', $now));
    }
    public function admit(string $username, string $ip, string $appkey, int $budget, array $settings = []): RequestPermit
    {
        $policy = LoginProtection::validate($settings);
        $now = $this->clock->getTime();
        $this->store->prune($now);
        $pauseKey = $this->key($appkey, 'provider_pause');
        if ($this->store->value($pauseKey, $now) > 0) {
            throw new ApiException('provider_pause', $this->store->remainingSeconds($pauseKey, $now));
        }
        $ipFailureKey = $this->key($appkey, 'ip_failures', '', $ip);
        if ($this->store->value($ipFailureKey, $now) >= $policy['loginIpFailureLimit']) {
            $this->rememberPause($appkey, 'ip_failures', '', $ip);
            throw new ApiException('login_pause', $this->store->remainingSeconds($ipFailureKey, $now));
        }
        $failureKey = $this->key($appkey, 'failures', $username);
        if ($this->store->value($failureKey, $now) >= $policy['loginFailureLimit']) {
            $this->rememberPause($appkey, 'failures', $username, $ip);
            throw new ApiException('login_pause', $this->store->remainingSeconds($failureKey, $now));
        }
        $budgetKey = $this->budgetKey($appkey, $now);
        // Reserve the entire flow before any network call, including its signout.
        if (!$this->store->consume($budgetKey, 4, $budget, $now, (int)(floor($now / 86400) + 2) * 86400)) {
            throw new ApiException('local_budget', 86400 - $now % 86400);
        }
        $budgetKeys = [$budgetKey];
        try {
            if ($policy['hourlyApiBudget'] > 0) {
                $hourKey = $this->hourlyKey($appkey, $now);
                if (!$this->store->consume($hourKey, 4, $policy['hourlyApiBudget'], $now, (int)(floor($now / 3600) + 2) * 3600)) {
                    throw new ApiException('local_hour_budget', 3600 - $now % 3600);
                }
                $budgetKeys[] = $hourKey;
            }
        } catch (\Throwable $e) {
            $this->store->refund($budgetKey, 4);
            throw $e;
        }
        $permit = new RequestPermit($this->store, $budgetKeys);
        try {
            foreach ([[$this->key($appkey, 'ip_attempts', '', $ip), $policy['loginIpAttemptLimit'], 'ip_attempts'],
                [$this->key($appkey, 'pair_attempts', $username, $ip), $policy['loginPairAttemptLimit'], 'pair_attempts']] as [$key, $limit, $kind]) {
                if (!$this->store->consume($key, 1, $limit, $now, $now + 60 * $policy['loginAttemptWindowMinutes'])) {
                    $this->rememberPause($appkey, $kind, $username, $ip);
                    throw new ApiException('login_pause', $this->store->remainingSeconds($key, $now));
                }
            }
            return $permit;
        } catch (\Throwable $e) {
            $permit->close();
            throw $e;
        }
    }
    public function failed(string $username, string $ip, string $appkey, array $settings = []): int
    {
        $policy = LoginProtection::validate($settings);
        $now = $this->clock->getTime();
        $key = $this->key($appkey, 'failures', $username);
        $this->store->consume($key, 1, $policy['loginFailureLimit'], $now, $now + 60 * $policy['loginPauseMinutes'], true);
        $ipKey = $this->key($appkey, 'ip_failures', '', $ip);
        $this->store->consume($ipKey, 1, $policy['loginIpFailureLimit'], $now, $now + 60 * $policy['loginPauseMinutes'], true);
        $remaining = 0;
        if ($this->store->value($key, $now) >= $policy['loginFailureLimit']) {
            $this->rememberPause($appkey, 'failures', $username, $ip);
            $remaining = $this->store->remainingSeconds($key, $now);
        }
        if ($this->store->value($ipKey, $now) >= $policy['loginIpFailureLimit']) {
            $this->rememberPause($appkey, 'ip_failures', '', $ip);
            $remaining = max($remaining, $this->store->remainingSeconds($ipKey, $now));
        }
        return $remaining;
    }
    public function succeeded(string $username, string $ip, string $appkey): void
    {
        $this->store->clear($this->key($appkey, 'failures', $username));
    }
    public function providerLimited(string $appkey, array $settings = []): int
    {
        $policy = LoginProtection::validate($settings);
        $now = $this->clock->getTime();
        $this->store->consume($this->key($appkey, 'provider_pause'), 1, 100, $now, $now + 60 * $policy['providerPauseMinutes'], true);
        return $this->store->remainingSeconds($this->key($appkey, 'provider_pause'), $now);
    }
    public function usage(string $appkey): array
    {
        $now = $this->clock->getTime();
        $pause = $this->store->remainingSeconds($this->key($appkey, 'provider_pause'), $now);
        return ['dateUtc' => gmdate('Y-m-d', $now), 'used' => $this->store->value($this->budgetKey($appkey, $now), $now),
            'hourUtc' => gmdate('Y-m-d H:00', $now), 'hourUsed' => $this->store->value($this->hourlyKey($appkey, $now), $now),
            'providerPaused' => $pause > 0, 'providerPauseRemaining' => $pause];
    }
    private function rememberPause(string $appkey, string $kind, string $username, string $ip): void
    {
        $now = $this->clock->getTime();
        $shared = in_array($kind, ['ip_attempts', 'ip_failures'], true);
        $key = $this->key($appkey, $kind, $shared ? '' : $username, $kind === 'failures' ? '' : $ip);
        $resetKeys = $shared ? [$this->key($appkey, 'ip_failures', '', $ip), $this->key($appkey, 'ip_attempts', '', $ip)] : [$this->key($appkey, 'failures', $username), $this->key($appkey, 'pair_attempts', $username, $ip)];
        $this->store->rememberPause($key, $this->key($appkey, 'pause_scope'), $shared ? '' : $username, $ip, $kind, $resetKeys, $now + $this->store->remainingSeconds($key, $now));
    }
    public function pauses(string $appkey): array
    {
        $now = $this->clock->getTime();
        $this->store->prune($now);
        return $this->store->pauses($this->key($appkey, 'pause_scope'), $now);
    }
    public function releasePause(string $appkey, string $identifier): bool
    {
        return $this->store->releasePause($this->key($appkey, 'pause_scope'), $identifier);
    }
    public function pausePage(string $appkey, int $page, string $search): array
    {
        $now = $this->clock->getTime();
        $this->store->prune($now);
        return $this->store->pausePage($this->key($appkey, 'pause_scope'), $now, $page, $search);
    }
}
