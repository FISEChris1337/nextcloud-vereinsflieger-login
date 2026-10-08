<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

final class Identity
{
    public function __construct(public readonly string $uid, public readonly array $roles, public readonly ?string $email = null, public readonly ?string $firstname = null, public readonly ?string $lastname = null)
    {
    }
    private static function name(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' && strlen($value) <= 200 && mb_check_encoding($value, 'UTF-8') && !preg_match('/\p{Cc}/u', $value) ? $value : null;
    }
    public static function fromResponse(array $data): self
    {
        return self::fromData($data, true);
    }
    public static function fromSession(array $data): self
    {
        return self::fromData($data, false);
    }
    private static function fromData(array $data, bool $providerRoles): self
    {
        $uid = $data['uid'] ?? null;
        if ((!is_int($uid) && !is_string($uid)) || !preg_match('/^[1-9][0-9]{0,15}$/D', (string)$uid)) {
            throw new ApiException('invalid_identity');
        }
        if (!isset($data['roles']) || !is_array($data['roles']) || !array_is_list($data['roles']) || count($data['roles']) > 100) {
            throw new ApiException('invalid_roles');
        }
        $roles = [];
        foreach ($data['roles'] as $role) {
            $roles[] = $providerRoles ? RoleName::fromProvider($role) : RoleName::validate($role);
        }
        $email = $data['email'] ?? null;
        if (!is_string($email) || strlen($email) > 254 || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            $email = null;
        }
        return new self((string)$uid, array_values(array_unique($roles)), $email === null ? null : trim($email), self::name($data['firstname'] ?? null), self::name($data['lastname'] ?? null));
    }
}
