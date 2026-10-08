<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OC\Authentication\Token\IProvider;
use OCP\Authentication\Token\IToken;
use OCP\ISession;

final class PasswordlessSession
{
    public function __construct(private IProvider $tokens, private ISession $session)
    {
    }
    public function complete(): void
    {
        // Called only by the authenticated finalizer, after native 2FA and UID
        // validation. Use Nextcloud's SSO scope instead of an invented password.
        $token = $this->tokens->getToken($this->session->getId());
        $scope = $token->getScopeAsArray();
        $scope[IToken::SCOPE_SKIP_PASSWORD_VALIDATION] = true;
        $token->setScope($scope);
        $this->tokens->updateToken($token);
    }
}
