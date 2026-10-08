<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\IL10N;

/** Keeps validation errors translatable, including individual mapping failures. */
final class ValidationException extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly array $parameters = [], public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function translated(IL10N $l10n): string
    {
        $messages = [];
        foreach ($this->details as $detail) {
            $messages[] = $detail->translated($l10n);
        }
        $messages[] = $l10n->t($this->getMessage(), $this->parameters);
        return implode(' ', array_unique($messages));
    }
}
