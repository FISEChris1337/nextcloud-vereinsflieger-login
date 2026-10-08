<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Listener;

use OC\Authentication\Exceptions\PasswordLoginForbiddenException;
use OC\User\LoginException;
use OCA\VereinsfliegerLogin\Service\{Configuration, LoginProof};
use OCP\EventDispatcher\{Event, IEventListener};
use OCP\{IRequest, IUserSession};
use OCP\User\Events\PostLoginEvent;

final class NativeLoginGuard implements IEventListener
{
    public function __construct(private Configuration $settings, private LoginProof $proof, private IUserSession $users, private IRequest $request, private \OCP\IL10N $l10n)
    {
    }
    public function handle(Event $event): void
    {
        if (!$event instanceof PostLoginEvent) {
            return;
        }
        $uid = $event->getUser()->getUID();
        if (!$this->settings->isSsoAccount($uid) || $event->isTokenLogin() || $this->proof->permits($uid)) {
            return;
        }
        // Native completeLogin has already set the active user. Clear it before
        // throwing so the failed request cannot retain a partially logged-in user.
        $this->users->setUser(null);
        if ($this->request->getHeader('Authorization') !== '') {
            throw new PasswordLoginForbiddenException();
        }
        throw new LoginException($this->l10n->t('This account must log in through Vereinsflieger.'));
    }
}
