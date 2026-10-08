<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Controller;

use OCA\VereinsfliegerLogin\Service\{AccountResolver, ApiException, Configuration, Identity, LoginSession, PasswordlessSession, ProfileSynchronizer, ReturnTarget, RoleSynchronizer, VereinsfliegerClient};
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\{NoAdminRequired, NoCSRFRequired, PublicPage, UseSession};
use OCP\AppFramework\Http\{RedirectResponse, TemplateResponse};
use OCP\EventDispatcher\IEventDispatcher;
use OCP\{IRequest, ISession, IURLGenerator, IUserManager, IUserSession};
use OCP\Security\Bruteforce\IThrottler;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

final class LoginController extends Controller
{
    private const PENDING = 'vereinsflieger_login.pending';
    public function __construct(
        IRequest $request,
        private Configuration $settings,
        private VereinsfliegerClient $api,
        private ISession $session,
        private IUserManager $users,
        private IUserSession $userSession,
        private IURLGenerator $urls,
        private IThrottler $throttler,
        private LoginSession $loginSession,
        private RoleSynchronizer $roles,
        private IEventDispatcher $events,
        private AccountResolver $accounts,
        private ReturnTarget $targets,
        private LoggerInterface $logger,
        private PasswordlessSession $passwordless,
        private ProfileSynchronizer $profiles,
        private \OCP\IL10N $l10n
    ) {
        parent::__construct('vereinsflieger_login', $request);
    }
    private function page(string $message = '', int $status = 200, int $retryAfter = 0): TemplateResponse
    {
        $showLocalLoginLink = true;
        $rememberDefault = true;
        try {
            $data = $this->settings->read();
            $showLocalLoginLink = $data['showLocalLoginLink'];
            $rememberDefault = $data['rememberMeDefault'] === true;
        } catch (\Throwable) { /* Keep the local escape visible if settings are unreadable. */
        }
        $response = new TemplateResponse('vereinsflieger_login', 'login', [
            'message' => $message, 'enabled' => $this->settings->isEnabled(),
            'showLocalLoginLink' => $showLocalLoginLink,
            'action' => $this->urls->linkToRoute('vereinsflieger_login.login.submit'),
            'localLogin' => $this->urls->linkToRoute('core.login.showLoginForm', ['direct' => '1'] + (($target = $this->targets->safe($this->request->getParam('redirect_url'))) === null ? [] : ['redirect_url' => $target])),
            'redirectUrl' => $target ?? '',
            'requesttoken' => \OCP\Server::get(\OC\Security\CSRF\CsrfTokenManager::class)->getToken()->getEncryptedValue(),
            'canRemember' => $this->loginSession->canRemember(),
            'rememberDefault' => $rememberDefault,
            'retryAfter' => $retryAfter,
        ], 'guest');
        $response->setStatus($status);
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
    #[PublicPage] #[NoCSRFRequired] #[UseSession]
    public function form(): TemplateResponse|RedirectResponse
    {
        if ($this->userSession->isLoggedIn()) {
            return new RedirectResponse($this->urls->linkToDefaultPageUrl());
        }
        return $this->page();
    }
    #[PublicPage] #[UseSession]
    public function submit(string $username = '', string $password = '', string $otp = '', bool $remember = false): TemplateResponse|RedirectResponse
    {
        if ($this->userSession->isLoggedIn()) {
            return new RedirectResponse($this->urls->linkToDefaultPageUrl());
        }
        $this->session->remove(self::PENDING);
        if (!$this->settings->isEnabled()) {
            return $this->page($this->l10n->t('Vereinsflieger login is currently disabled.'), 503);
        }
        if ($this->request->getServerProtocol() !== 'https') {
            return $this->page($this->l10n->t('HTTPS is required to log in.'), 403);
        }
        $ip = $this->request->getRemoteAddress();
        try {
            $this->throttler->sleepDelayOrThrowOnMax($ip, 'vereinsflieger_login');
        } catch (\OCP\Security\Bruteforce\MaxDelayReached) {
            return $this->page($this->l10n->t('Too many login attempts. Please try again later.'), 429);
        }
        $started = false;
        $cid = $this->settings->read()['cid'];
        try {
            $identity = $this->api->authenticate($username, $password, $otp, $ip);
            if ($cid !== $this->settings->read()['cid'] || !$this->settings->isEnabled()) {
                throw new ApiException('credentials');
            }
            $user = $this->accounts->resolve($identity);
            if ($this->settings->isExcluded($user->getUID())) {
                throw new ApiException('local_only');
            }
            $target = $this->loginSession->start($user, $this->request, $remember);
            $started = true;
            $this->session->set(self::PENDING, ['uid' => $user->getUID(), 'vfUid' => $identity->uid,
                'cid' => $cid, 'roles' => $identity->roles, 'rolesDecoded' => true, 'firstname' => $identity->firstname, 'lastname' => $identity->lastname,
                'created' => time(), 'redirectUrl' => $this->targets->safe($this->request->getParam('redirect_url'))]);
            $this->throttler->resetDelay($ip, 'vereinsflieger_login', []);
            return new RedirectResponse($target);
        } catch (ApiException $e) {
            $safeUsername = mb_check_encoding($username, 'UTF-8') ? preg_replace('/\p{C}/u', '', mb_strcut(trim($username), 0, 254, 'UTF-8')) : '[invalid UTF-8]';
            $this->logger->warning(sprintf('sign-in refused for "%s" from %s (%s)', $safeUsername, $ip, $e->reason), ['app' => 'vereinsflieger_login', 'reason' => $e->reason, 'username' => $safeUsername, 'ip' => $ip]);
            $messages = ['two_factor' => $this->l10n->t('Enter your password and current Vereinsflieger 2FA code.'),
                'local_only' => $this->l10n->t('This account may only use local Nextcloud login.'),
                'not_linked' => $this->l10n->t('This account is not linked yet. Ask your club administrator to link your Vereinsflieger ID or use local login.'),
                'admin_mapping' => $this->l10n->t('This Nextcloud account has administrator rights and requires an explicit manual Vereinsflieger link. Automatic email linking is for member accounts.'),
                'missing_profile' => $this->l10n->t('New accounts require a Vereinsflieger first name, last name and valid email address.'),
                'input_format' => $this->l10n->t('Check your username, password and optional 2FA code. Empty, excessive or invalid inputs are rejected without contacting Vereinsflieger.'),
                'login_pause' => $this->l10n->t('Login temporarily paused. Try again in %s.', [sprintf('%02d:%02d', intdiv($e->retryAfter, 60), $e->retryAfter % 60)]),
                'local_budget' => $this->l10n->t('This app’s daily budget is exhausted. Local accounts can still use local login.'),
                'local_hour_budget' => $this->l10n->t('This app’s hourly budget is exhausted. Please try again after the next UTC hour starts.'),
                'provider_pause' => $this->l10n->t('Vereinsflieger limited further API calls. Login is temporarily paused; local accounts remain available.'),
                'unavailable' => $this->l10n->t('Vereinsflieger is currently unavailable. Please try again later.'),
                'rate_limit' => $this->l10n->t('The API quota is currently exhausted. Please try again later.'),
                'password_encoding' => $this->l10n->t('This password contains characters unsupported by the documented API procedure.')];
            if (in_array($e->reason, ['credentials', 'two_factor'], true)) {
                $this->throttler->registerAttempt('vereinsflieger_login', $ip);
            }
            $status = in_array($e->reason, ['login_pause', 'local_budget', 'local_hour_budget', 'provider_pause', 'rate_limit'], true) ? 429 : 403;
            $response = $this->page($messages[$e->reason] ?? $this->l10n->t('Login failed. Check your credentials or account link.'), $status, $e->reason === 'login_pause' ? $e->retryAfter : 0);
            if ($status === 429) {
                $response->addHeader('Retry-After', (string)max(1, $e->retryAfter));
            }
            return $response;
        } catch (\Throwable) {
            if ($started) {
                $this->userSession->logout();
            }
            // Do not log exceptions: HTTP client exceptions can contain credentials.
            return $this->page($this->l10n->t('Login could not be completed. Local accounts can use local login.'), 503);
        }
    }
    #[NoAdminRequired] #[NoCSRFRequired] #[UseSession]
    public function finish(): TemplateResponse|RedirectResponse
    {
        $pending = $this->session->get(self::PENDING);
        $this->session->remove(self::PENDING);
        $user = $this->userSession->getUser();
        if ($pending === null) {
            return new RedirectResponse($this->urls->linkToDefaultPageUrl());
        }
        try {
            if (!is_array($pending) || !$user || ($pending['uid'] ?? '') !== $user->getUID() || time() - ($pending['created'] ?? 0) > 300 ||
                !$this->settings->isEnabled() || ($pending['cid'] ?? '') !== $this->settings->read()['cid'] ||
                $this->settings->linkedUid((string)$pending['vfUid']) !== $user->getUID() || $this->settings->isExcluded($user->getUID())) {
                throw new \RuntimeException('Login process expired.');
            }
            $identityData = ['uid' => $pending['vfUid'], 'roles' => $pending['roles'],
                'firstname' => $pending['firstname'] ?? null, 'lastname' => $pending['lastname'] ?? null];
            // Older in-flight logins still contain the provider's encoded role names.
            $identity = ($pending['rolesDecoded'] ?? false) === true ? Identity::fromSession($identityData) : Identity::fromResponse($identityData);
            $this->profiles->apply($user, $identity);
            $this->roles->apply($user, $identity);
            $this->passwordless->complete();
            $this->events->dispatchTyped(new UserLoggedInEvent($user, $user->getUID(), null, false));
            $ip = $this->request->getRemoteAddress();
            $this->logger->info(sprintf('sign-in granted for "%s" from %s', $user->getUID(), $ip), ['app' => 'vereinsflieger_login', 'username' => $user->getUID(), 'ip' => $ip]);
            return new RedirectResponse($this->targets->safe($pending['redirectUrl'] ?? null) ?? $this->urls->linkToDefaultPageUrl());
        } catch (\Throwable) {
            $this->userSession->logout();
            return $this->page($this->l10n->t('Login not completed. Please log in again.'), 403);
        }
    }
}
