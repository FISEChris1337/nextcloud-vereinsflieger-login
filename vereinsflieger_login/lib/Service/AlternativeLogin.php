<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\Authentication\IAlternativeLogin;
use OCP\IURLGenerator;

final class AlternativeLogin implements IAlternativeLogin
{
    public function __construct(private IURLGenerator $urls, private \OCP\IRequest $request, private ReturnTarget $targets, private \OCP\IL10N $l10n)
    {
    }
    public function getLabel(): string
    {
        return $this->l10n->t('Log in with Vereinsflieger');
    }
    public function getLink(): string
    {
        $target = $this->targets->safe($this->request->getParam('redirect_url'));
        return $this->urls->linkToRoute('vereinsflieger_login.login.form', $target === null ? [] : ['redirect_url' => $target]);
    }
    public function getClass(): string
    {
        return '';
    }
    public function load(): void
    {
    }
}
