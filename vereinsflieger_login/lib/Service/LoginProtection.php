<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

final class LoginProtection
{
    public const DEFAULTS = ['loginFailureLimit' => 3, 'loginIpFailureLimit' => 3, 'loginPauseMinutes' => 15,
        'loginAttemptWindowMinutes' => 15, 'loginPairAttemptLimit' => 6,
        'loginIpAttemptLimit' => 30, 'providerPauseMinutes' => 15, 'hourlyApiBudget' => 0];
    private const RANGES = ['loginFailureLimit' => [1, 20], 'loginIpFailureLimit' => [1, 100], 'loginPauseMinutes' => [1, 1440],
        'loginAttemptWindowMinutes' => [1, 1440], 'loginPairAttemptLimit' => [1, 100],
        'loginIpAttemptLimit' => [1, 1000], 'providerPauseMinutes' => [1, 1440], 'hourlyApiBudget' => [0, 500]];
    public static function validate(array $input, array $current = []): array
    {
        $result = [];
        foreach (self::DEFAULTS as $name => $default) {
            $raw = $input[$name] ?? $current[$name] ?? $default;
            [$min, $max] = self::RANGES[$name];
            $value = (is_int($raw) || is_string($raw)) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) : false;
            if ($value === false || ($name === 'hourlyApiBudget' && $value > 0 && $value < 4)) {
                throw new ValidationException('Invalid protection limit: %s. Observe the allowed ranges.', [$name]);
            }
            $result[$name] = $value;
        }
        return $result;
    }
}
