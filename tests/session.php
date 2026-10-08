<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCP {
    interface IConfig { public function getSystemValue(string $key, mixed $default = ''): mixed; }
    interface IUser { public function getUID(): string; }
    interface IRequest {}
    interface IURLGenerator { public function linkToRoute(string $name, array $params = []): string; }
}
namespace OCP\Authentication\Token { interface IToken { public const DO_NOT_REMEMBER = 0; public const REMEMBER = 1; } }
namespace OCP\EventDispatcher { interface IEventDispatcher { public function dispatchTyped(object $event): void; } }
namespace Psr\Log { interface LoggerInterface { public function error(string $message, array $context = []): void; } }
namespace OCP\User\Events {
    final class BeforeUserLoggedInEvent { public function __construct(public string $username, public ?string $password) {} }
}
namespace OC\User {
    final class Session {
        public array $calls = []; public bool $tokenWorks = true; public bool $loginWorks = true;
        public function completeLogin(\OCP\IUser $user, array $details): bool { $this->calls[] = ['complete', $details]; return $this->loginWorks; }
        public function createSessionToken(\OCP\IRequest $request, string $uid, string $loginName, ?string $password, int $remember): bool { $this->calls[] = ['token', $password, $remember]; return $this->tokenWorks; }
        public function createRememberMeToken(\OCP\IUser $user): void { $this->calls[] = ['remember', $user->getUID()]; }
        public function logout(): void { $this->calls[] = ['logout']; }
    }
}
namespace OC\Authentication\TwoFactorAuth {
    final class Manager {
        public bool $required = false; public bool $missing = false; public bool $fail = false; public array $providers = []; public array $setup = []; public array $prepared = [];
        public function isTwoFactorAuthenticated(\OCP\IUser $user): bool { return $this->required; }
        public function prepareTwoFactorLogin(\OCP\IUser $user, bool $remember): void { if ($this->fail) { throw new \RuntimeException('2FA failure'); } $this->prepared[] = [$user->getUID(), $remember]; }
        public function getProviderSet(\OCP\IUser $user): object { return new class($this->providers, $this->missing) {
            public function __construct(private array $providers, private bool $missing) {}
            public function getPrimaryProviders(): array { return $this->providers; }
            public function isProviderMissing(): bool { return $this->missing; }
        }; }
        public function getLoginSetupProviders(\OCP\IUser $user): array { return $this->setup; }
    }
    final class MandatoryTwoFactor { public bool $enforced = false; public function isEnforcedFor(\OCP\IUser $user): bool { return $this->enforced; } }
}
namespace {
    require __DIR__ . '/../vereinsflieger_login/lib/Service/LoginSession.php';
    require __DIR__ . '/../vereinsflieger_login/lib/Service/LoginProof.php';
    $session = new \OC\User\Session(); $twoFactor = new \OC\Authentication\TwoFactorAuth\Manager(); $mandatory = new \OC\Authentication\TwoFactorAuth\MandatoryTwoFactor();
    $urls = new class implements \OCP\IURLGenerator {
        public function linkToRoute(string $name, array $params = []): string { return $name . ($params === [] ? '' : '?' . http_build_query($params)); }
    };
    $events = new class implements \OCP\EventDispatcher\IEventDispatcher {
        public array $events = []; public function dispatchTyped(object $event): void { $this->events[] = $event; }
    };
    $user = new class implements \OCP\IUser { public function getUID(): string { return 'existing-account'; } };
    $request = new class implements \OCP\IRequest {};
    $config = new class implements \OCP\IConfig {
        public int $lifetime = 1296000;
        public function getSystemValue(string $key, mixed $default = ''): mixed { return $key === 'remember_login_cookie_lifetime' ? $this->lifetime : $default; }
    };
    $logger = new class implements \Psr\Log\LoggerInterface { public array $entries = []; public function error(string $message, array $context = []): void { $this->entries[] = [$message, $context]; } };
    $proof = new \OCA\VereinsfliegerLogin\Service\LoginProof();
    $login = new \OCA\VereinsfliegerLogin\Service\LoginSession($session, $twoFactor, $mandatory, $urls, $events, $config, $logger, $proof);
    $count = 0;
    function check(bool $valid, string $name): void { global $count; if (!$valid) { throw new \RuntimeException('FAIL ' . $name); } ++$count; echo 'PASS ' . $name . "\n"; }
    check($login->start($user, $request) === 'vereinsflieger_login.login.finish', 'login proceeds through authenticated finalizer');
    check($session->calls[0][1]['password'] === '' && $session->calls[1][1] === null, 'legacy login event receives empty string and native token remains passwordless');
    check($events->events[0]->password === null, 'pre-login event contains no credential');
    check($twoFactor->prepared === [], 'no second factor fabricated for non-2FA user');
    check($session->calls[1][2] === 0 && count($session->calls) === 2, 'unchecked remember creates only session token');
    $session->calls = []; $login->start($user, $request, true);
    check($session->calls[1][2] === 1 && $session->calls[2] === ['remember', 'existing-account'], 'remember creates native persistent token without VF password');
    $config->lifetime = 0; $session->calls = []; $login->start($user, $request, true);
    check(!$login->canRemember() && $session->calls[1][2] === 0 && count($session->calls) === 2, 'server can disable remember even for forged checkbox');
    $config->lifetime = 1296000;
    $twoFactor->required = true;
    $provider = new class { public function getId(): string { return 'totp'; } }; $twoFactor->providers = [$provider];
    $target = $login->start($user, $request);
    check(str_starts_with($target, 'core.TwoFactorChallenge.showChallenge?'), 'native single-provider challenge enforced');
    check(str_contains($target, 'challengeProviderId=totp') && str_contains($target, 'redirect_url=vereinsflieger_login.login.finish'), 'native challenge returns to authenticated finalizer');
    check($twoFactor->prepared[0] === ['existing-account', false], '2FA pending session created without remember-me bypass');
    $session->calls = []; $login->start($user, $request, true);
    check(end($twoFactor->prepared) === ['existing-account', true] && $session->calls[1][2] === 1, 'remember preference passes to native 2FA');
    check(count($session->calls) === 2, 'remember cookie deferred until native 2FA succeeds');
    $twoFactor->missing = true;
    check(str_starts_with($login->start($user, $request), 'core.TwoFactorChallenge.selectChallenge?'), 'missing native provider cannot bypass challenge');
    $twoFactor->missing = false; $twoFactor->providers = [$provider, $provider];
    check(str_starts_with($login->start($user, $request), 'core.TwoFactorChallenge.selectChallenge?'), 'multiple providers route to selection');
    $twoFactor->providers = []; $twoFactor->setup = [$provider]; $mandatory->enforced = true;
    check(str_starts_with($login->start($user, $request), 'core.TwoFactorChallenge.setupProviders?'), 'enforced 2FA without enrolled provider requires setup');
    $twoFactor->fail = true;
    try { $login->start($user, $request); throw new \LogicException('Expected error'); }
    catch (\RuntimeException) { check(end($session->calls)[0] === 'logout', '2FA setup failure removes partial session'); }
    $twoFactor->fail = false; $twoFactor->required = false; $session->tokenWorks = false;
    try { $login->start($user, $request); throw new \LogicException('Expected error'); }
    catch (\RuntimeException) { check(end($session->calls)[0] === 'logout', 'token creation failure removes partial session'); }
    $session->tokenWorks = true; $session->loginWorks = false;
    try { $login->start($user, $request); throw new \LogicException('Expected error'); }
    catch (\RuntimeException) { check(end($session->calls)[0] === 'logout', 'canceled login removes partial session'); }
    check($logger->entries[0][1] === ['app' => 'vereinsflieger_login', 'stage' => 'two_factor', 'exceptionType' => \RuntimeException::class], 'failure logging contains only fixed stage and exception class');
    check(!$proof->permits('existing-account'), 'request-local login proof cleared after success and failures');
    echo "\n$count isolated session checks passed. These checks use isolated session doubles.\n";
}
