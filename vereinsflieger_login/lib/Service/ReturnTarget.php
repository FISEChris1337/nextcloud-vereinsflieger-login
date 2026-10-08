<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

final class ReturnTarget
{
    public function safe(mixed $target): ?string
    {
        if (!is_string($target) || $target === '' || strlen($target) > 2048 || !str_starts_with($target, '/')) {
            return null;
        }
        $decoded = rawurldecode($target);
        if (str_starts_with($decoded, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
            return null;
        }
        $parts = parse_url($target);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
            return null;
        }
        return $target;
    }
}
