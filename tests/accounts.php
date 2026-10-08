<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Shares the isolated fakes and assertion functions from run.php.
use OCA\VereinsfliegerLogin\Service\{AccountLinks, AccountResolver, UserProvisioner, ReturnTarget, DefaultLoginRedirect, Configuration, Identity, RoleSynchronizer};
$accountDb = new MemoryConfig(); $accountUsers = new Users(); $accountGroups = new Groups();
$accountLinks = new AccountLinks(new SqlConnection());
$accountCfg = new Configuration($accountDb, new Crypto(), $accountUsers, $accountGroups, $catalog, $accountLinks);
$accountBase = array_replace($base, ['enabled' => true, 'createAccounts' => true, 'links' => [], 'roleMap' => [], 'excludedAccounts' => [], 'defaultLogin' => false]);
$accountCfg->save($accountBase);
check(!$accountCfg->isSsoAccount('alice'), 'unlinked local account remains local');
$localPasswords = new \OCA\VereinsfliegerLogin\Service\LocalAccountPassword(new class implements \OCP\EventDispatcher\IEventDispatcher {
    public function dispatchTyped(object $event): void {}
});
$provisioner = new UserProvisioner($accountCfg, $accountLinks, $accountUsers, $accountDb, $l, $localPasswords);
$resolver = new AccountResolver($accountCfg, $accountLinks, $accountUsers, $provisioner, $accountGroups);
$newIdentity = Identity::fromResponse(['uid' => 50, 'roles' => ['Mitglied'], 'email' => 'new@example.invalid', 'firstname' => 'Anna', 'lastname' => 'Beispiel']);
check($accountCfg->isEnabled(), 'provisioning allows first login without existing mappings');
rejects(fn() => $resolver->resolve(new Identity('51', [])), 'new account requires complete profile', 'missing_profile');
$accountUsers->items['alice']->email = 'existing@example.invalid';
rejects(fn() => $resolver->resolve(Identity::fromResponse(['uid' => 52, 'roles' => [], 'email' => 'EXISTING@example.invalid', 'firstname' => 'Other', 'lastname' => 'Person'])), 'mutable provider email cannot claim existing files', 'not_linked');
check($accountUsers->items['alice']->name === '' && count($accountUsers->items) === 3, 'email collision does not modify or create accounts');
$newUser = $resolver->resolve($newIdentity);
check($newUser->getUID() === 'vf_123_50' && $newUser->email === 'new@example.invalid' && $newUser->name === 'Anna Beispiel', 'new member gets stable ID name and email');
check($accountDb->user['vf_123_50']['lang'] === 'de_DE', 'new account inherits native login language instead of an English default');
check($accountDb->user['vf_123_50']['provisioned_vf_uid'] === '50' && $accountDb->user['vf_123_50']['provisioned_cid'] === '123', 'provider ID and club stored on new account');
check($accountLinks->get('123', '50')['nextcloudUid'] === 'vf_123_50', 'persistent provider identity resolves new account');
check($accountCfg->isSsoAccount('vf_123_50'), 'created provider account protected from native password login');
check(strlen($accountUsers->passwords['vf_123_50']) >= 64 && !str_contains(json_encode($accountDb->app), $accountUsers->passwords['vf_123_50']), 'random local password is not stored by app');
check(!isset($accountDb->user['vf_123_50']['snapshot']), 'creating account does not apply roles before finalizer');
$accountCfg->save(array_replace($accountBase, ['createAccounts' => false]));
check($resolver->resolve(new Identity('50', [])) === $newUser, 'provisioned user can log in when creation is later disabled');
rejects(fn() => $resolver->resolve($newIdentity = new Identity('53', [])), 'disabled creation rejects unmapped identity', 'not_linked');
$accountCfg->save(array_replace($accountBase, ['links' => [['vfUid' => '42', 'nextcloudUid' => 'alice']], 'excludedAccounts' => ['alice', 'vf_123_50']]));
rejects(fn() => $resolver->resolve(new Identity('42', [])), 'local-only exclusion overrides manual mapping', 'local_only');
rejects(fn() => $resolver->resolve(new Identity('50', [])), 'local-only exclusion overrides provisioned mapping', 'local_only');
check(!$accountCfg->isSsoAccount('alice') && !$accountCfg->isSsoAccount('vf_123_50'), 'explicit local-only exceptions also bypass native password guard');
$accountSync = new RoleSynchronizer($accountCfg, $accountDb, $accountGroups, $catalog);
try { $accountSync->apply($accountUsers->items['alice'], new Identity('42', ['Mitglied'])); check(false, 'excluded role finalizer must fail'); }
catch (\RuntimeException) { check(!isset($accountDb->user['alice']['snapshot']), 'excluded account cannot finalize role synchronization'); }
rejects(fn() => $accountCfg->save(array_replace($accountBase, ['excludedAccounts' => ['unknown']])), 'unknown local-only account rejected');
rejects(fn() => $accountCfg->save(array_replace($accountBase, ['links' => [['vfUid' => '50', 'nextcloudUid' => 'bob']]])), 'provisioned identity cannot be reassigned to other files');
$accountCfg->save(array_replace($accountBase, ['links' => [['vfUid' => '42', 'nextcloudUid' => 'alice']]]));
check($resolver->resolve(Identity::fromResponse(['uid' => 42, 'roles' => [], 'email' => 'changed@example.invalid'])) === $accountUsers->items['alice'], 'explicit identity survives email changes and incomplete profile');
check($accountUsers->items['alice']->email === 'existing@example.invalid', 'manual linking preserves local profile');
check($accountCfg->isSsoAccount('alice'), 'manual mapping also protects existing account from local password login');
$accountUsers->items['vf_123_60'] = new User('vf_123_60');
rejects(fn() => $resolver->resolve(Identity::fromResponse(['uid' => 60, 'roles' => [], 'email' => 'other@example.invalid', 'firstname' => 'A', 'lastname' => 'B'])), 'generated-looking existing local ID is never adopted', 'not_linked');
$accountLinks->claim('123', '50', 'vf_123_50');
rejects(fn() => $accountLinks->claim('123', '50', 'bob'), 'identity claim cannot overwrite stable mapping', 'credentials');
rejects(fn() => $accountLinks->claim('123', '70', 'vf_123_50'), 'one account cannot receive second provider identity', 'credentials');
$accountLinks->block('123', '50');
check($accountCfg->isSsoAccount('vf_123_50'), 'blocked provider identity cannot fall back to native password');
rejects(fn() => $resolver->resolve(new Identity('50', [])), 'blocked identity cannot recreate or relink account', 'local_only');

$targets = new ReturnTarget();
check($targets->safe('/apps/files/?dir=/Documents') === '/apps/files/?dir=/Documents', 'local return path preserved');
foreach (['https://evil.invalid', '//evil.invalid', '/%2fevil.invalid', '/\\evil.invalid', "/apps/files\r\nLocation: https://evil.invalid", ['invalid']] as $target) {
    check($targets->safe($target) === null, 'external or malformed return target rejected');
}
$request = new class implements \OCP\IRequest {
    public string $method = 'GET'; public string $path = '/login'; public array $params = []; public array $headers = ['Accept' => 'text/html'];
    public function getMethod(): string { return $this->method; } public function getPathInfo(): string { return $this->path; }
    public function getParam(string $key): mixed { return $this->params[$key] ?? null; } public function getHeader(string $key): string { return $this->headers[$key] ?? ''; }
};
$session = new class implements \OCP\ISession { public array $items = []; public function get(string $key): mixed { return $this->items[$key] ?? null; } };
$userSession = new class implements \OCP\IUserSession { public bool $loggedIn = false; public function isLoggedIn(): bool { return $this->loggedIn; } };
$urls = new class implements \OCP\IURLGenerator { public function linkToRoute(string $route, array $params = []): string { return '/apps/vereinsflieger_login/login' . ($params === [] ? '' : '?' . http_build_query($params)); } };
$redirect = new DefaultLoginRedirect($accountCfg, $request, $userSession, $session, $urls, $targets);
check($redirect->target() === null, 'default login redirect starts disabled');
$accountCfg->save(array_replace($accountBase, ['defaultLogin' => true]));
check($redirect->target() === '/apps/vereinsflieger_login/login', 'enabled standard browser login redirects to VF form');
$request->params = ['direct' => '1']; check($redirect->target() === null, 'direct=1 always retains local login');
$request->params = []; $request->method = 'POST'; check($redirect->target() === null, 'local credential POST is never redirected');
$request->method = 'GET'; $request->path = '/login/v2'; check($redirect->target() === null, 'client login flow route not intercepted');
$request->path = '/login';
foreach (['Authorization', 'OCS-APIRequest', 'X-Requested-With'] as $header) { $request->headers[$header] = 'present'; check($redirect->target() === null, 'authenticated API and XHR requests not redirected'); unset($request->headers[$header]); }
$request->headers['Accept'] = 'application/json'; check($redirect->target() === null, 'JSON requests not redirected');
$request->headers['Accept'] = 'text/html'; $session->items['loginMessages'] = ['Invalid credentials']; check($redirect->target() === null, 'failed local login stays on local form');
$session->items = []; $userSession->loggedIn = true; check($redirect->target() === null, 'existing session is not redirected');
$userSession->loggedIn = false; $request->params = ['redirect_url' => '/apps/files/'];
check(str_contains($redirect->target(), 'redirect_url=%2Fapps%2Ffiles%2F'), 'safe original destination carried through login');
$request->params = ['redirect_url' => 'https://evil.invalid']; check($redirect->target() === '/apps/vereinsflieger_login/login', 'external destination dropped from login redirect');
$accountLinks->forgetDeletedAccount('vf_123_50');
check($accountLinks->get('123', '50') === null, 'deleted native account no longer leaves a stale provider link');
$accountCfg->forgetDeletedAccount('alice');
check($accountCfg->explicitUid('42') === null && !$accountCfg->isSsoAccount('alice') && $accountCfg->read()['enabled'], 'deletion cleans manual account link without changing login activation');

// Optional first-login migration of existing accounts by provider email.
$emailDb = new MemoryConfig(); $emailUsers = new Users(); $emailGroups = new Groups();
$emailLinks = new AccountLinks(new SqlConnection());
$emailCfg = new Configuration($emailDb, new Crypto(), $emailUsers, $emailGroups, $catalog, $emailLinks);
check(!$emailCfg->read()['linkExistingByEmail'] && $emailCfg->read()['showLocalLoginLink'], 'existing installations retain manual mapping and visible local link by default');
$emailBase = array_replace($accountBase, ['createAccounts' => false, 'linkExistingByEmail' => true, 'showLocalLoginLink' => false]);
$emailCfg->save($emailBase); $emailCfg->checkLocally();
check($emailCfg->isEnabled(), 'email migration can operate without provisioning or pre-existing manual links');
$emailResolver = new AccountResolver($emailCfg, $emailLinks, $emailUsers, new UserProvisioner($emailCfg, $emailLinks, $emailUsers, $emailDb, $l, $localPasswords), $emailGroups);
$emailUsers->items['alice']->email = 'existing@example.invalid';
$emailIdentity = Identity::fromResponse(['uid' => 80, 'roles' => [], 'email' => 'EXISTING@example.invalid']);
check($emailResolver->resolve($emailIdentity) === $emailUsers->items['alice'], 'unique email links existing account without creating or requiring new-member profile');
check($emailCfg->linkedUid('80') === 'alice' && $emailCfg->isSsoAccount('alice'), 'email migration persists stable identity and protects native password login');
$emailGroups->items['admin']->addUser($emailUsers->items['alice']);
check($emailResolver->resolve(new Identity('80', [])) === $emailUsers->items['alice'] && $emailCfg->isSsoAccount('alice'), 'linked SSO member may receive admin rights and retain stable provider login');
$emailGroups->items['admin']->removeUser($emailUsers->items['alice']);
check(count($emailUsers->items) === 3 && $emailUsers->items['alice']->name === '' && $emailUsers->items['alice']->email === 'existing@example.invalid', 'email linking preserves existing files account and profile');
$emailCfg->save(array_replace($emailBase, ['linkExistingByEmail' => false]));
check($emailResolver->resolve(Identity::fromResponse(['uid' => 80, 'roles' => [], 'email' => 'changed@example.invalid'])) === $emailUsers->items['alice'], 'saved identity survives email changes and disabling migration');
$emailCfg->save($emailBase);
rejects(fn() => $emailResolver->resolve(Identity::fromResponse(['uid' => 81, 'roles' => [], 'email' => 'existing@example.invalid'])), 'different VF identity cannot claim account already linked by email', 'not_linked');
$emailUsers->items['bob']->email = 'existing@example.invalid';
rejects(fn() => $emailResolver->resolve(Identity::fromResponse(['uid' => 82, 'roles' => [], 'email' => 'existing@example.invalid'])), 'duplicate local emails are rejected rather than selecting one account', 'not_linked');
$emailUsers->items['bob']->email = 'protected@example.invalid';
$emailCfg->save(array_replace($emailBase, ['excludedAccounts' => ['bob']]));
rejects(fn() => $emailResolver->resolve(Identity::fromResponse(['uid' => 83, 'roles' => [], 'email' => 'protected@example.invalid'])), 'local-only account cannot be claimed by email', 'local_only');
$emailCfg->save($emailBase); $emailGroups->items['admin']->addUser($emailUsers->items['bob']);
rejects(fn() => $emailResolver->resolve(Identity::fromResponse(['uid' => 84, 'roles' => [], 'email' => 'protected@example.invalid'])), 'administrator cannot be claimed by email and receives specific mapping reason', 'admin_mapping');
$emailGroups->items['admin']->removeUser($emailUsers->items['bob']);
$emailCfg->save(array_replace($emailBase, ['links' => [['vfUid' => '85', 'nextcloudUid' => 'bob']]]));
rejects(fn() => $emailResolver->resolve(Identity::fromResponse(['uid' => 86, 'roles' => [], 'email' => 'protected@example.invalid'])), 'email cannot override another explicit VF identity', 'not_linked');
$emailCfg->save($emailBase); $emailUsers->items['disabled']->email = 'disabled@example.invalid';
rejects(fn() => $emailResolver->resolve(Identity::fromResponse(['uid' => 87, 'roles' => [], 'email' => 'disabled@example.invalid'])), 'disabled local account cannot be reactivated by email migration', 'not_linked');
rejects(fn() => $emailResolver->resolve(new Identity('88', [])), 'missing email cannot match or provision when account creation disabled', 'not_linked');
$emailLinks->block('123', '80');
rejects(fn() => $emailResolver->resolve($emailIdentity), 'blocked stable identity cannot bypass block using email migration', 'local_only');
$emailCfg->save(array_replace($emailBase, ['createAccounts' => true]));
check($emailResolver->resolve(Identity::fromResponse(['uid' => 89, 'roles' => [], 'email' => 'new-member@example.invalid', 'firstname' => 'New', 'lastname' => 'Member']))->getUID() === 'vf_123_89', 'no email match still allows separately enabled new account creation');
$emailCfg->save(array_diff_key($emailBase, array_flip(['showLocalLoginLink', 'linkExistingByEmail'])));
check(!$emailCfg->read()['showLocalLoginLink'] && $emailCfg->read()['linkExistingByEmail'], 'clients omitting new options preserve saved preferences');

$_ = ['message' => '', 'enabled' => false, 'localLogin' => '/login?direct=1', 'showLocalLoginLink' => false];
ob_start(); require __DIR__ . '/../vereinsflieger_login/templates/login.php'; $hiddenForm = ob_get_clean();
check(!str_contains($hiddenForm, 'vf-local-login'), 'hidden local-login link does not appear even on disabled VF form');
$_['showLocalLoginLink'] = true;
ob_start(); require __DIR__ . '/../vereinsflieger_login/templates/login.php'; $visibleForm = ob_get_clean();
check(str_contains($visibleForm, 'href="/login?direct=1"'), 'visible local-login link retains direct escape URL');

check($emailCfg->read()['rememberMeDefault'] === true, 'remember preselection enabled by default on existing configuration');
$emailCfg->save(array_replace($emailBase, ['rememberMeDefault' => false]));
check($emailCfg->read()['rememberMeDefault'] === false, 'admin can disable remember preselection');
$emailCfg->save($emailBase);
check($emailCfg->read()['rememberMeDefault'] === false, 'clients omitting remember preference preserve its saved value');
$_ = ['message'=>'', 'enabled'=>true, 'action'=>'/login', 'requesttoken'=>'fixture', 'redirectUrl'=>'', 'canRemember'=>true, 'rememberDefault'=>false, 'showLocalLoginLink'=>false];
ob_start(); require __DIR__ . '/../vereinsflieger_login/templates/login.php'; $login = ob_get_clean();
preg_match('/<input[^>]*name="remember"[^>]*>/', $login, $rememberInput);
check(isset($rememberInput[0]) && !str_contains($rememberInput[0], 'checked'), 'remember input remains available when default selection is off');
$_['rememberDefault'] = true;
ob_start(); require __DIR__ . '/../vereinsflieger_login/templates/login.php'; $login = ob_get_clean();
preg_match('/<input[^>]*name="remember"[^>]*>/', $login, $rememberInput);
check(isset($rememberInput[0]) && str_contains($rememberInput[0], 'checked'), 'remember input initially selected when admin default is on');
