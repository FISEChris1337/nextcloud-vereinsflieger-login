<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

// Request-local proof, never a browser parameter or persisted user preference.
final class LoginProof
{
    private ?string $uid = null;
    public function begin(string $uid): void
    {
        $this->uid = $uid;
    }
    public function permits(string $uid): bool
    {
        return $this->uid === $uid;
    }
    public function clear(): void
    {
        $this->uid = null;
    }
}
