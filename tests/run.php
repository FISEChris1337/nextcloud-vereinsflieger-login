<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Isolated behavior tests. These intentionally do not claim a Nextcloud runtime test.
namespace OCP {
    class HintException extends \Exception {}
    final class Util { public static function addStyle(string $app, string $name): void {} public static function addScript(string $app, string $name): void {} }
    interface IL10N { public function t(string $text, array $parameters = []): string; public function getLanguageCode(): string; }
    interface IConfig { public function getAppValue(string $app, string $key, string $default = ''): string; public function setAppValue(string $app, string $key, string $value): void; public function getUserValue(string $uid, string $app, string $key, string $default = ''): string; public function setUserValue(string $uid, string $app, string $key, string $value): void; public function getSystemValue(string $key, mixed $default = ''): mixed; }
    interface IUser { public function getUID(): string; public function isEnabled(): bool; public function getDisplayName(): string; public function setDisplayName(string $name): bool; public function setEMailAddress(string $email): void; }
    interface IUserManager { public function get(string $uid): ?IUser; public function getByEmail(string $email): array; public function createUser(string $uid, string $password): ?IUser; }
    interface IRequest { public function getMethod(): string; public function getPathInfo(): string; public function getParam(string $key): mixed; public function getHeader(string $key): string; }
    interface IUserSession { public function isLoggedIn(): bool; }
    interface ISession { public function get(string $key): mixed; }
    interface IURLGenerator { public function linkToRoute(string $route, array $params = []): string; }
    interface IGroupManager { public function get(string $gid): ?object; }
}
namespace OCP\Security { interface ICrypto { public function encrypt(string $value): string; public function decrypt(string $value): string; } }
namespace OCP\EventDispatcher { interface IEventDispatcher { public function dispatchTyped(object $event): void; } }
namespace OCP\Security\Events {
    final class GenerateSecurePasswordEvent {
        private ?string $password = null;
        public function getPassword(): ?string { return $this->password; }
        public function setPassword(string $password): void { $this->password = $password; }
    }
    final class ValidatePasswordPolicyEvent {
        public function __construct(private string $password) {}
        public function getPassword(): string { return $this->password; }
    }
}
namespace OCP\Http\Client { interface IClientService { public function newClient(): object; } }
namespace {
    function p(mixed $value): void { echo htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    use OCA\VereinsfliegerLogin\Service\{Configuration, Identity, VereinsfliegerClient, RoleSynchronizer, ApiException};
    require __DIR__ . '/counter_support.php';
    foreach (['ApiException', 'ValidationException', 'LoginProtection', 'RoleName', 'Identity', 'Configuration', 'VereinsfliegerClient', 'RoleSynchronizer', 'CounterStore', 'RequestGuard', 'RequestPermit', 'KnownRoles', 'AccountLinks', 'LocalAccountPassword', 'UserProvisioner', 'AccountResolver', 'ReturnTarget', 'DefaultLoginRedirect', 'ProfileSynchronizer'] as $class) { require __DIR__ . '/../vereinsflieger_login/lib/Service/' . $class . '.php'; }
    final class TestL10N implements \OCP\IL10N {
        public function __construct(private string $language = 'de_DE') {}
        public function getLanguageCode(): string { return $this->language; }
        public function t(string $text, array $parameters = []): string {
            $catalog = json_decode(file_get_contents(__DIR__ . '/../vereinsflieger_login/l10n/' . $this->language . '.json'), true, 512, JSON_THROW_ON_ERROR)['translations'];
            return vsprintf($catalog[$text] ?? $text, $parameters);
        }
    }
    $l = new TestL10N();
    final class MemoryConfig implements \OCP\IConfig {
        public array $app = []; public array $user = [];
        public function getSystemValue(string $key, mixed $default = ''): mixed { return $key === 'secret' ? 'isolated-test-secret' : $default; }
        public function getAppValue(string $app, string $key, string $default = ''): string { return $this->app[$key] ?? $default; }
        public function setAppValue(string $app, string $key, string $value): void { $this->app[$key] = $value; }
        public function getUserValue(string $uid, string $app, string $key, string $default = ''): string { return $this->user[$uid][$key] ?? $default; }
        public function setUserValue(string $uid, string $app, string $key, string $value): void { $this->user[$uid][$key] = $value; }
    }
    final class User implements \OCP\IUser {
        public string $email = ''; public string $name = '';
        public function __construct(private string $uid, private bool $enabled = true) {}
        public function getUID(): string { return $this->uid; } public function isEnabled(): bool { return $this->enabled; }
        public function getDisplayName(): string { return $this->name; }
        public function setDisplayName(string $name): bool { $this->name = $name; return true; }
        public function setEMailAddress(string $email): void { $this->email = $email; }
    }
    final class Users implements \OCP\IUserManager {
        public array $items; public array $passwords = [];
        public function __construct() { $this->items = ['alice' => new User('alice'), 'bob' => new User('bob'), 'disabled' => new User('disabled', false)]; }
        public function get(string $uid): ?\OCP\IUser { return $this->items[strtolower($uid)] ?? null; }
        public function getByEmail(string $email): array { return array_values(array_filter($this->items, fn(User $user): bool => strtolower($user->email) === strtolower($email))); }
        public function createUser(string $uid, string $password): ?\OCP\IUser { if ($this->get($uid)) { return null; } $this->passwords[$uid] = $password; return $this->items[$uid] = new User($uid); }
    }
    final class Group {
        public array $members = [];
        public function inGroup(\OCP\IUser $user): bool { return isset($this->members[$user->getUID()]); }
        public function addUser(\OCP\IUser $user): void { $this->members[$user->getUID()] = true; }
        public function removeUser(\OCP\IUser $user): void { unset($this->members[$user->getUID()]); }
    }
    final class Groups implements \OCP\IGroupManager {
        public array $items;
        public function __construct() { $this->items = ['Mitglieder' => new Group(), 'Technik' => new Group(), 'admin' => new Group()]; }
        public function get(string $gid): ?object { return $this->items[$gid] ?? null; }
    }
    final class Crypto implements \OCP\Security\ICrypto {
        public function encrypt(string $value): string { return 'encrypted:' . base64_encode($value); }
        public function decrypt(string $value): string { return base64_decode(substr($value, 10), true); }
    }
    final class Http implements \OCP\Http\Client\IClientService {
        public array $calls = []; public array $queue = [];
        public function newClient(): object { return $this; }
        public function get(string $url, array $options): object { return $this->request('GET', $url, $options); }
        public function post(string $url, array $options): object { return $this->request('POST', $url, $options); }
        public function delete(string $url, array $options): object { return $this->request('DELETE', $url, $options); }
        private function request(string $method, string $url, array $options): object {
            $this->calls[] = [$method, $url, $options]; $next = array_shift($this->queue);
            if ($next instanceof \Throwable) { throw $next; }
            if (!$next) { throw new \RuntimeException('Unexpected request'); }
            return new class($next) {
                public function __construct(private array $data) {}
                public function getStatusCode(): int { return $this->data[0]; }
                public function getBody(): string { return is_string($this->data[1]) ? $this->data[1] : json_encode($this->data[1], JSON_THROW_ON_ERROR); }
            };
        }
    }
    $count = 0;
    function check(bool $valid, string $label): void { global $count; if (!$valid) { throw new \RuntimeException('FAIL: ' . $label); } ++$count; echo 'PASS ' . $label . "\n"; }
    function rejects(callable $action, string $label, ?string $reason = null): void {
        try { $action(); } catch (\Throwable $e) { check($reason === null ? $e instanceof \InvalidArgumentException : ($e instanceof ApiException && $e->reason === $reason), $label); return; }
        check(false, $label);
    }
    $catalog = new \OCA\VereinsfliegerLogin\Service\KnownRoles(new SqlConnection());
    $catalog->remember('123', ['Mitglied', 'Technisches Personal']);
    $db = new MemoryConfig(); $groups = new Groups(); $users = new Users(); $links = new \OCA\VereinsfliegerLogin\Service\AccountLinks(new SqlConnection()); $cfg = new Configuration($db, new Crypto(), $users, $groups, $catalog, $links);
    $base = ['cid' => '123', 'appkey' => 'test-key-only', 'enabled' => false, 'syncRoles' => false,
        'links' => [['vfUid' => '42', 'nextcloudUid' => 'alice']], 'roleMap' => [['role' => 'Mitglied', 'group' => 'Mitglieder'], ['role' => 'Technisches Personal', 'group' => 'Technik']]];
    check(!$cfg->isEnabled(), 'disabled by default');
    $cfg->save($base); check($cfg->key() === 'test-key-only', 'key roundtrip');
    check(!$cfg->isEnabled(), 'saved AppKey and links alone do not enable alternative login');
    check($db->app['appkey_encrypted'] !== 'test-key-only', 'uses crypto service for stored key');
    check(!str_contains(json_encode($cfg->publicData()), 'test-key-only'), 'key absent from admin payload');
    check($cfg->linkedUid('42') === 'alice' && $cfg->linkedUid('43') === null, 'explicit UID mapping only');
    $before = $db->app;
    rejects(fn() => $cfg->save(array_replace($base, ['cid' => '0'])), 'reject invalid CID');
    rejects(fn() => $cfg->save(array_replace($base, ['links' => [['vfUid' => '42', 'nextcloudUid' => 'unknown']]])), 'reject missing NC user');
    rejects(fn() => $cfg->save(array_replace($base, ['links' => [['vfUid' => '42', 'nextcloudUid' => 'disabled']]])), 'reject disabled NC user');
    rejects(fn() => $cfg->save(array_replace($base, ['links' => [['vfUid' => '42', 'nextcloudUid' => 'alice'], ['vfUid' => '42', 'nextcloudUid' => 'bob']]])), 'reject duplicate VF identity');
    rejects(fn() => $cfg->save(array_replace($base, ['links' => [['vfUid' => '42', 'nextcloudUid' => 'alice'], ['vfUid' => '43', 'nextcloudUid' => 'ALICE']]])), 'reject duplicate canonical NC identity');
    rejects(fn() => $cfg->save(array_replace($base, ['roleMap' => [['role' => 'Vorstand', 'group' => 'admin']]])), 'never map administrator group');
    rejects(fn() => $cfg->save(array_replace($base, ['roleMap' => [['role' => 'Vorstand', 'group' => 'ADMIN']]])), 'reject administrator group case variation');
    rejects(fn() => $cfg->save(array_replace($base, ['roleMap' => [['role' => 'Mitglied', 'group' => 'missing']]])), 'reject nonexistent target group');
    rejects(fn() => $cfg->save(array_replace($base, ['enabled' => true, 'links' => []])), 'activation requires link');
    rejects(fn() => $cfg->save(array_replace($base, ['cid' => '456'])), 'club cannot change with existing links');
    rejects(fn() => $cfg->save(array_replace($base, ['appkey' => "bad\nkey"])), 'reject whitespace key');
    rejects(fn() => $cfg->save(array_replace($base, ['dailyApiBudget' => 501])), 'budget cannot exceed documented provider quota');
    rejects(fn() => $cfg->save(array_replace($base, ['dailyApiBudget' => 3])), 'budget must accommodate one full login');
    rejects(fn() => $cfg->save(array_replace($base, ['roleMap' => [['role' => 'Mitglid', 'group' => 'Mitglieder']]])), 'server rejects misspelled or invented role');
    check($db->app === $before, 'failed validations preserve all saved settings');
    $cfg->save(array_replace($base, ['enabled' => true, 'appkey' => '']));
    check($cfg->isEnabled() && $cfg->key() === 'test-key-only', 'blank key retains saved key');
    $identity = Identity::fromResponse(['uid' => 42, 'roles' => ['Mitglied', 'Technisches Personal', 'Mitglied']]);
    check($identity->uid === '42' && count($identity->roles) === 2, 'stable numeric UID and distinct roles');
    rejects(fn() => Identity::fromResponse(['uid' => 42]), 'missing roles is an error', 'invalid_roles');
    rejects(fn() => Identity::fromResponse(['uid' => 42, 'roles' => ['name' => 'Mitglied']]), 'reject associative roles', 'invalid_roles');
    rejects(fn() => Identity::fromResponse(['uid' => 42, 'roles' => [12]]), 'reject malformed role entries', 'invalid_roles');
    rejects(fn() => Identity::fromResponse(['uid' => true, 'roles' => []]), 'reject boolean identity', 'invalid_identity');
    check(Identity::fromResponse(['uid' => 42, 'roles' => []])->roles === [], 'confirmed empty roles accepted');
    $alice = new User('alice'); $groups->items['Mitglieder']->addUser($alice); $groups->items['admin']->addUser($alice);
    $sync = new RoleSynchronizer($cfg, $db, $groups, $catalog); $sync->apply($alice, $identity);
    check(!$groups->items['Technik']->inGroup($alice), 'group sync off by default');
    check(json_decode($db->user['alice']['snapshot'], true)['vfUid'] === '42', 'verified role snapshot saved');
    $cfg->save(array_replace($base, ['enabled' => true, 'syncRoles' => true])); $sync->apply($alice, $identity);
    check($groups->items['Technik']->inGroup($alice), 'mapped role adds group');
    check(json_decode($db->user['alice']['owned_groups'], true) === ['Technik'], 'preexisting groups never taken into ownership');
    $sync->apply($alice, new Identity('42', []));
    check(!$groups->items['Technik']->inGroup($alice), 'confirmed removed role revokes app-added group');
    check($groups->items['Mitglieder']->inGroup($alice) && $groups->items['admin']->inGroup($alice), 'local member and admin groups preserved');
    check(json_decode($db->user['alice']['snapshot'], true)['roles'] === [], 'empty snapshot replaces old roles');
    $db->user['alice']['owned_groups'] = '["admin"]'; $sync->apply($alice, new Identity('42', []));
    check($groups->items['admin']->inGroup($alice), 'administrator membership protected even in old ownership history');
    check($cfg->publicData()['knownRoles'] === ['Mitglied', 'Technisches Personal'], 'observed role selection survives empty current roles');
    $unseenCatalog = new \OCA\VereinsfliegerLogin\Service\KnownRoles(new SqlConnection());
    $unseenDb = new MemoryConfig(); $unseenCfg = new Configuration($unseenDb, new Crypto(), new Users(), $groups, $unseenCatalog, new \OCA\VereinsfliegerLogin\Service\AccountLinks(new SqlConnection()));
    $unseenCfg->save(array_replace($base, ['roleMap' => []]));
    check($unseenCfg->publicData()['knownRoles'] === [], 'no selectable roles before first verified login');
    rejects(fn() => $unseenCfg->save($base), 'unobserved role cannot be mapped before first login');
    $unseenSync = new RoleSynchronizer($unseenCfg, $unseenDb, $groups, $unseenCatalog);
    $unseenSync->apply($alice, $identity); $unseenCfg->save($base);
    check($unseenCfg->publicData()['knownRoles'] === $cfg->publicData()['knownRoles'], 'successful finalizer stores roles while group sync is off');
    $unseenCfg->save(array_replace($base, ['links' => []]));
    check(count($unseenCfg->publicData()['knownRoles']) === 2, 'role catalog persists after account is unlinked');
    $unseenCfg->save(array_replace($base, ['cid' => '456', 'links' => [], 'roleMap' => []]));
    check($unseenCfg->publicData()['knownRoles'] === [], 'club change does not expose another club role catalog');
    $unseenDb->app['settings'] = json_encode(array_replace($base, ['appkey' => '', 'roleMap' => [['role' => 'Old unverified role', 'group' => 'Mitglieder']]]));
    $legacy = array_replace($base, ['roleMap' => [['role' => 'Old unverified role', 'group' => 'Mitglieder']]]);
    $unseenCfg->save($legacy);
    check(!$unseenCfg->isEnabled(), 'unchanged legacy mapping allows disabling login during upgrade');
    rejects(fn() => $unseenCfg->save(array_replace($legacy, ['roleMap' => [['role' => 'Old unverified role', 'group' => 'Technik']]])), 'unconfirmed legacy role cannot create changed mapping');
    $freshDb = new MemoryConfig(); $freshGroups = new Groups(); $freshCfg = new Configuration($freshDb, new Crypto(), new Users(), $freshGroups, $catalog, new \OCA\VereinsfliegerLogin\Service\AccountLinks(new SqlConnection()));
    $freshCfg->save(array_replace($base, ['enabled' => true, 'syncRoles' => true])); unset($freshGroups->items['Technik']);
    $freshSync = new RoleSynchronizer($freshCfg, $freshDb, $freshGroups, $catalog);
    $freshSync->apply($alice, $identity);
    check(!$freshGroups->items['Mitglieder']->inGroup($alice) && isset($freshDb->user['alice']['snapshot']) && isset($freshCfg->publicData()['syncWarnings']['alice']), 'missing group skips membership changes while preserving confirmed login role capture and admin warning');
    check(VereinsfliegerClient::passwordHash('password') === md5('password'), 'ASCII hashing follows API');
    check(VereinsfliegerClient::passwordHash('päss') === md5("p\xE4ss"), 'Latin-1 hashing follows supplied example');
    rejects(fn() => VereinsfliegerClient::passwordHash('€password'), 'reject lossy password encoding', 'password_encoding');
    $http = new Http(); $clock = new Clock(); $guard = new \OCA\VereinsfliegerLogin\Service\RequestGuard(new \OCA\VereinsfliegerLogin\Service\CounterStore(new SqlConnection()), $db, $clock);
    $api = new VereinsfliegerClient($http, $cfg, $guard);
    $http->queue = [[200, ['accesstoken' => 'fake_token_12345678']], [200, ''], [200, ['uid' => 42, 'roles' => ['Mitglied']]], [200, '']];
    check($api->authenticate('vf-user', 'password', '654321', '192.0.2.1')->uid === '42', 'complete mocked API login');
    check(array_column($http->calls, 0) === ['GET', 'POST', 'POST', 'DELETE'], 'correct four HTTP methods');
    parse_str($http->calls[1][2]['body'], $posted);
    check($posted['cid'] === '123' && $posted['auth_secret'] === '654321' && $posted['password'] === md5('password'), 'CID and 2FA sent with password hash');
    check(!isset($db->user['alice']['password']) && !str_contains(json_encode($db->app), 'fake_token_12345678'), 'credentials not persisted');
    check($http->calls[1][2]['verify'] && !$http->calls[1][2]['allow_redirects'] && $http->calls[1][2]['timeout'] === 15, 'TLS verification, no redirects, bounded timeout');
    $http->calls = []; $http->queue = [[200, ['accesstoken' => 'fake_token_12345678']], [403, ['need_2fa' => 1]], [200, '']];
    rejects(fn() => $api->authenticate('vf-user', 'password', '', '192.0.2.1'), 'recognize VF 2FA challenge', 'two_factor');
    check(array_column($http->calls, 0) === ['GET', 'POST', 'DELETE'], '2FA failure signs out without fetching roles');
    $http->calls = []; $http->queue = [[200, ['accesstoken' => 'fake_token_12345678']], [403, ['error' => 'bad password']], new \RuntimeException('secret error')];
    rejects(fn() => $api->authenticate('vf-user', 'password', '', '192.0.2.1'), 'signout error never replaces credential failure', 'credentials');
    $http->queue = [[200, ['accesstoken' => 'fake_token_12345678']], [200, ''], [200, ['uid' => 42]], [200, '']];
    rejects(fn() => $api->authenticate('vf-user', 'password', '', '192.0.2.1'), 'malformed roles fail login instead of revoking groups', 'invalid_roles');
    $http->queue = [[429, []]];
    rejects(fn() => $api->authenticate('vf-user', 'password', '', '192.0.2.1'), 'rate limit surfaced', 'rate_limit');
    $clock->now += 901;
    $http->queue = [new \RuntimeException('contains password and token')];
    rejects(fn() => $api->authenticate('vf-user', 'password', '', '192.0.2.1'), 'transport exceptions are redacted', 'unavailable');
    $http->calls = []; $http->queue = []; $cfg->checkLocally();
    check($http->calls === [], 'saved configuration check consumes zero API requests');
    check($cfg->read()['dailyApiBudget'] === 400, 'conservative default budget retained');
    $clock->now += 86400;
    for ($i = 0; $i < 3; ++$i) {
        $http->queue = [[200, ['accesstoken' => 'fake_token_12345678']], [403, ['error' => 'bad password']], [200, '']];
        rejects(fn() => $api->authenticate('failed-user', 'wrong', '', '192.0.2.9'), 'failed login tracked ' . ($i + 1), $i === 2 ? 'login_pause' : 'credentials');
    }
    check($guard->usage($cfg->key())['used'] === 9, 'three failed logins count their nine actual API attempts');
    $http->calls = []; $http->queue = [];
    rejects(fn() => $api->authenticate('failed-user', 'wrong', '', '192.0.2.9'), 'fourth bad login rejected before HTTP', 'login_pause');
    check($http->calls === [] && $guard->usage($cfg->key())['used'] === 9, 'failure lock produces no API requests');
    $clock->now += 86400; $cfg->save(array_replace($base, ['enabled' => true, 'dailyApiBudget' => 4]));
    $http->queue = [[200, ['accesstoken' => 'fake_token_12345678']], [200, ''], [200, ['uid' => 42, 'roles' => []]], [200, '']];
    $api->authenticate('budget-user', 'password', '', '192.0.2.8'); $http->calls = [];
    rejects(fn() => $api->authenticate('budget-user', 'password', '', '192.0.2.8'), 'exhausted budget rejects client before HTTP', 'local_budget');
    check($http->calls === [], 'budget exhaustion does not spend provider quota');
    $cfg->save(array_replace($base, ['links' => []])); $cfg->save(array_replace($base, ['cid' => '456', 'links' => [], 'roleMap' => [], 'enabled' => false]));
    check($cfg->read()['cid'] === '456', 'explicit unlink permits club change');
    $numericDb = new MemoryConfig(); $numericGroups = new Groups(); $numericGroups->items['42'] = new Group();
    $numericCfg = new Configuration($numericDb, new Crypto(), new Users(), $numericGroups, $catalog, new \OCA\VereinsfliegerLogin\Service\AccountLinks(new SqlConnection()));
    $numericCfg->save(array_replace($base, ['enabled' => true, 'syncRoles' => true, 'roleMap' => [['role' => 'Mitglied', 'group' => '42']]]));
    $numericSync = new RoleSynchronizer($numericCfg, $numericDb, $numericGroups, $catalog); $numericSync->apply($alice, new Identity('42', ['Mitglied']));
    check(json_decode($numericDb->user['alice']['owned_groups'], true) === ['42'], 'numeric group IDs retain string ownership');
    $numericSync->apply($alice, new Identity('42', []));
    check(!$numericGroups->items['42']->inGroup($alice), 'numeric app-owned group can be revoked');
    require __DIR__ . '/accounts.php';
    require __DIR__ . '/passwords.php';
    require __DIR__ . '/protection.php';
    require __DIR__ . '/profiles.php';
    require __DIR__ . '/l10n.php';
    require __DIR__ . '/security.php';
    echo "\n$count behavior checks passed. No live API calls.\n";
}
