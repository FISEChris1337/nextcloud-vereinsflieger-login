<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Listener;

use OCA\VereinsfliegerLogin\Service\{AccountLinks, Configuration};
use OCP\EventDispatcher\{Event, IEventListener};
use OCP\User\Events\UserDeletedEvent;

final class DeletedAccountCleanup implements IEventListener
{
    public function __construct(private AccountLinks $links, private Configuration $settings)
    {
    }
    public function handle(Event $event): void
    {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }
        $uid = $event->getUser()->getUID();
        $this->links->forgetDeletedAccount($uid);
        $this->settings->forgetDeletedAccount($uid);
    }
}
