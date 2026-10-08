<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * Native login API sequence referenced from user_oidc 8.11.0:
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OC\Authentication\TwoFactorAuth\Manager;
use OC\Authentication\TwoFactorAuth\MandatoryTwoFactor;
use OC\User\Session;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\User\Events\BeforeUserLoggedInEvent;
use Psr\Log\LoggerInterface;

final class LoginSession
{
    public function __construct(private Session $userSession, private Manager $twoFactor, private MandatoryTwoFactor $mandatory, private IURLGenerator $urls, private IEventDispatcher $events, private IConfig $config, private LoggerInterface $logger, private LoginProof $proof)
    {
    }
    public function canRemember(): bool
    {
        return (int)$this->config->getSystemValue('remember_login_cookie_lifetime', 1296000) > 0;
    }
    public function start(IUser $user, IRequest $request, bool $remember = false): string
    {
        $remember = $remember && $this->canRemember();
        $stage = 'before_login';
        $this->proof->begin($user->getUID());
        try {
            $this->events->dispatchTyped(new BeforeUserLoggedInEvent($user->getUID(), null));
            $stage = 'complete_login';
            // Legacy PostLoginEvent requires string, unlike BeforeUserLoggedInEvent
            // and the session-token API. An empty event value is no credential.
            if (!$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => ''])) {
                throw new \RuntimeException('Session could not be created.');
            }
            $stage = 'session_token';
            if (!$this->userSession->createSessionToken($request, $user->getUID(), $user->getUID(), null, $remember ? IToken::REMEMBER : IToken::DO_NOT_REMEMBER)) {
                throw new \RuntimeException('Session could not be created.');
            }
            $stage = 'two_factor';
            if ($this->twoFactor->isTwoFactorAuthenticated($user)) {
                $this->twoFactor->prepareTwoFactorLogin($user, $remember);
                $providerSet = $this->twoFactor->getProviderSet($user);
                $setup = $this->twoFactor->getLoginSetupProviders($user);
                $providers = $providerSet->getPrimaryProviders();
                $params = ['redirect_url' => $this->urls->linkToRoute('vereinsflieger_login.login.finish')];
                if ($providers === [] && !$providerSet->isProviderMissing() && $setup !== [] && $this->mandatory->isEnforcedFor($user)) {
                    return $this->urls->linkToRoute('core.TwoFactorChallenge.setupProviders', $params);
                }
                if (!$providerSet->isProviderMissing() && count($providers) === 1) {
                    $params['challengeProviderId'] = reset($providers)->getId();
                    return $this->urls->linkToRoute('core.TwoFactorChallenge.showChallenge', $params);
                }
                return $this->urls->linkToRoute('core.TwoFactorChallenge.selectChallenge', $params);
            }
            $stage = 'remember_cookie';
            if ($remember) {
                $this->userSession->createRememberMeToken($user);
            }
            return $this->urls->linkToRoute('vereinsflieger_login.login.finish');
        } catch (\Throwable $e) {
            // Log only fixed stages and class names. Never exception messages,
            // arguments, requests, stacks or credentials.
            $this->logger->error('native session failed', ['app' => 'vereinsflieger_login', 'stage' => $stage, 'exceptionType' => get_class($e)]);
            $this->userSession->logout();
            throw $e;
        } finally {
            $this->proof->clear();
        }
    }
}
