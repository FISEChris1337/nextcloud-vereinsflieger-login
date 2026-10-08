<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\IConfig;
use OCP\IUser;

/** Updates the verified name after both authentication stages have completed. */
final class ProfileSynchronizer
{
    public function __construct(private IConfig $config)
    {
    }

    public function apply(IUser $user, Identity $identity): void
    {
        if ($identity->firstname === null || $identity->lastname === null) {
            return;
        }
        $name = mb_substr($identity->firstname . ' ' . $identity->lastname, 0, 64, 'UTF-8');
        if ($user->getDisplayName() !== $name && !$user->setDisplayName($name)) {
            throw new \RuntimeException('Account display name could not be set');
        }
        $this->config->setUserValue($user->getUID(), 'vereinsflieger_login', 'firstname', $identity->firstname);
        $this->config->setUserValue($user->getUID(), 'vereinsflieger_login', 'lastname', $identity->lastname);
    }
}
