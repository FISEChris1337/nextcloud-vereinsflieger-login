<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Controller;

use OCA\VereinsfliegerLogin\Service\Configuration;
use OCA\VereinsfliegerLogin\Service\RequestGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

// Default middleware requires administrator, login, completed 2FA and CSRF.
final class AdminController extends Controller
{
    public function __construct(IRequest $request, private Configuration $settings, private \OCP\IL10N $l10n, private RequestGuard $guard)
    {
        parent::__construct('vereinsflieger_login', $request);
    }
    public function save(array $settings = []): JSONResponse
    {
        try {
            $this->settings->save($settings);
            return new JSONResponse(['message' => $this->l10n->t('Settings saved.')]);
        } catch (\OCA\VereinsfliegerLogin\Service\ValidationException $e) {
            return new JSONResponse(['message' => $e->translated($this->l10n)], 400);
        } catch (\Throwable) {
            return new JSONResponse(['message' => $this->l10n->t('Saving failed.')], 500);
        }
    }
    public function check(): JSONResponse
    {
        try {
            $this->settings->checkLocally();
            return new JSONResponse(['message' => $this->l10n->t('Saved configuration is valid locally. No Vereinsflieger request was sent. A real login is required to confirm the AppKey and club ID with Vereinsflieger.')]);
        } catch (\OCA\VereinsfliegerLogin\Service\ValidationException $e) {
            return new JSONResponse(['message' => $e->translated($this->l10n)], 400);
        } catch (\Throwable) {
            return new JSONResponse(['message' => $this->l10n->t('The saved configuration could not be checked locally.')], 400);
        }
    }
    public function unpause(string $identifier = ''): JSONResponse
    {
        try {
            if (!$this->settings->hasKey() || !$this->guard->releasePause($this->settings->key(), $identifier)) {
                return new JSONResponse(['message' => $this->l10n->t('Login pause not found or already expired.')], 404);
            }
            return new JSONResponse(['message' => $this->l10n->t('Login pause removed.'), 'pauses' => $this->guard->pauses($this->settings->key())]);
        } catch (\Throwable) {
            return new JSONResponse(['message' => $this->l10n->t('The login pause could not be removed.')], 500);
        }
    }
}
