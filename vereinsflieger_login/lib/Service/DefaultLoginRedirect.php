<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\{IRequest, ISession, IURLGenerator, IUserSession};

final class DefaultLoginRedirect
{
    public function __construct(private Configuration $settings, private IRequest $request, private IUserSession $users, private ISession $session, private IURLGenerator $urls, private ReturnTarget $targets)
    {
    }
    public function target(): ?string
    {
        try {
            if ($this->request->getMethod() !== 'GET' || $this->request->getPathInfo() !== '/login' || $this->users->isLoggedIn() || !$this->settings->isEnabled() || !$this->settings->read()['defaultLogin']) {
                return null;
            }
            $direct = $this->request->getParam('direct');
            if ($direct === '1' || $direct === 1 || $this->session->get('loginMessages') !== null) {
                return null;
            }
            foreach (['Authorization', 'OCS-APIRequest', 'X-Requested-With'] as $header) {
                if ($this->request->getHeader($header) !== '') {
                    return null;
                }
            }
            if (!str_contains(strtolower($this->request->getHeader('Accept')), 'text/html')) {
                return null;
            }
            $mode = $this->request->getHeader('Sec-Fetch-Mode');
            $dest = $this->request->getHeader('Sec-Fetch-Dest');
            if (($mode !== '' && $mode !== 'navigate') || ($dest !== '' && $dest !== 'document')) {
                return null;
            }
            $target = $this->targets->safe($this->request->getParam('redirect_url'));
            return $this->urls->linkToRoute('vereinsflieger_login.login.form', $target === null ? [] : ['redirect_url' => $target]);
        } catch (\Throwable) {
            return null;
        } // Keep local login available on configuration errors.
    }
}
