<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct($reason);
    }
}
