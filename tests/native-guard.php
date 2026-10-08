<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCP { interface IUserSession { public function setUser(?object $user): void; } interface IRequest { public function getHeader(string $name): string; } interface IL10N { public function t(string $text): string; } }
namespace OCP\EventDispatcher { class Event {} interface IEventListener { public function handle(Event $event): void; } }
namespace OCP\User\Events {
    class PostLoginEvent extends \OCP\EventDispatcher\Event {
        public function __construct(private object $user, private bool $token = false) {}
        public function getUser(): object { return $this->user; } public function isTokenLogin(): bool { return $this->token; }
    }
}
namespace OC\User { class LoginException extends \RuntimeException {} }
namespace OC\Authentication\Exceptions { class PasswordLoginForbiddenException extends \RuntimeException {} }
namespace OCA\VereinsfliegerLogin\Service {
    class Configuration { public function isSsoAccount(string $uid): bool { return $uid === 'sso'; } }
}
namespace {
    require __DIR__ . '/../vereinsflieger_login/lib/Service/LoginProof.php';
    require __DIR__ . '/../vereinsflieger_login/lib/Listener/NativeLoginGuard.php';
    $proof = new \OCA\VereinsfliegerLogin\Service\LoginProof();
    $users = new class implements \OCP\IUserSession { public ?object $active = null; public function setUser(?object $user): void { $this->active = $user; } };
    $request = new class implements \OCP\IRequest { public string $authorization = ''; public function getHeader(string $name): string { return $this->authorization; } };
    $l10n = new class implements \OCP\IL10N { public function t(string $text): string { return $text; } };
    $guard = new \OCA\VereinsfliegerLogin\Listener\NativeLoginGuard(new \OCA\VereinsfliegerLogin\Service\Configuration(), $proof, $users, $request, $l10n);
    $sso = new class { public function getUID(): string { return 'sso'; } };
    $local = new class { public function getUID(): string { return 'admin'; } };
    $count = 0;
    function check(bool $valid, string $name): void { global $count; if (!$valid) { throw new \RuntimeException($name); } ++$count; echo 'PASS ' . $name . "\n"; }
    $users->setUser($sso);
    try { $guard->handle(new \OCP\User\Events\PostLoginEvent($sso)); throw new \LogicException('Local SSO login accepted'); }
    catch (\OC\User\LoginException) { check($users->active === null, 'native password login rejected and partially active user cleared'); }
    $guard->handle(new \OCP\User\Events\PostLoginEvent($local)); check(true, 'local administrator stays available');
    $guard->handle(new \OCP\User\Events\PostLoginEvent($sso, true)); check(true, 'native delegated app tokens remain available');
    $proof->begin('other');
    try { $guard->handle(new \OCP\User\Events\PostLoginEvent($sso)); throw new \LogicException('Foreign proof accepted'); }
    catch (\OC\User\LoginException) { check(true, 'login proof cannot authorize different account'); }
    $proof->begin('sso'); $guard->handle(new \OCP\User\Events\PostLoginEvent($sso)); check(true, 'verified request-local VF path accepted');
    $proof->clear();
    try { $guard->handle(new \OCP\User\Events\PostLoginEvent($sso)); throw new \LogicException('Expired proof accepted'); }
    catch (\OC\User\LoginException) { check(true, 'completed VF request does not authorize later local password login'); }
    $request->authorization = 'Basic test';
    try { $guard->handle(new \OCP\User\Events\PostLoginEvent($sso)); throw new \LogicException('Local basic password accepted'); }
    catch (\OC\Authentication\Exceptions\PasswordLoginForbiddenException) { check(true, 'native DAV password prohibition uses supported client exception'); }
    echo "$count isolated native-login guard checks passed.\n";
}
