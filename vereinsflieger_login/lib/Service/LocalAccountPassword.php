<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\Security\Events\GenerateSecurePasswordEvent;
use OCP\Security\Events\ValidatePasswordPolicyEvent;

/** Creates an unknown local password without bypassing the account policy. */
final class LocalAccountPassword
{
    public function __construct(private IEventDispatcher $events)
    {
    }

    public function generate(): string
    {
        $password = 'Aa1!' . bin2hex(random_bytes(30));
        try {
            $this->events->dispatchTyped(new ValidatePasswordPolicyEvent($password));
            return $password;
        } catch (HintException) {
            // A stricter policy may require a longer password or other characters.
            $event = new GenerateSecurePasswordEvent();
            $this->events->dispatchTyped($event);
            $password = $event->getPassword();
            if ($password === null || $password === '') {
                throw new \RuntimeException('No policy-compliant account password could be generated');
            }
            $this->events->dispatchTyped(new ValidatePasswordPolicyEvent($password));
            return $password;
        }
    }
}
