<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

final class RoleName
{
    public static function validate(mixed $role): string
    {
        if (!is_string($role) || $role === '' || strlen($role) > 200 || !mb_check_encoding($role, 'UTF-8') || preg_match('/\p{Cc}/u', $role)) {
            throw new ApiException('invalid_roles');
        }
        return $role;
    }

    public static function fromProvider(mixed $role): string
    {
        // Decode exactly once at the provider boundary. Keep custom names as text.
        return self::validate(html_entity_decode(self::validate($role), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
