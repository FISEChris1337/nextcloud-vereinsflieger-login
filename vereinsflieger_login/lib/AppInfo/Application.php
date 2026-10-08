<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\AppInfo;

use OCA\VereinsfliegerLogin\Service\AlternativeLogin;
use OCA\VereinsfliegerLogin\Service\Configuration;
use OCA\VereinsfliegerLogin\Service\DefaultLoginRedirect;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap
{
    public const APP_ID = 'vereinsflieger_login';
    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }
    public function register(IRegistrationContext $context): void
    {
        $context->registerEventListener(\OCP\User\Events\PostLoginEvent::class, \OCA\VereinsfliegerLogin\Listener\NativeLoginGuard::class);
        $context->registerEventListener(\OCP\User\Events\UserDeletedEvent::class, \OCA\VereinsfliegerLogin\Listener\DeletedAccountCleanup::class);
        if ($this->getContainer()->get(Configuration::class)->isEnabled()) {
            $context->registerAlternativeLogin(AlternativeLogin::class);
        }
    }
    public function boot(IBootContext $context): void
    {
        $context->injectFn(function (DefaultLoginRedirect $redirect): void {
            $target = $redirect->target();
            if ($target !== null) {
                header('Cache-Control: no-store');
                header('Location: ' . $target, true, 302);
                exit;
            }
        });
    }
}
