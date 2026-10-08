<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Run only on an explicitly authorized disposable development instance.
// Uses real Nextcloud DI, users, groups, configuration and MariaDB; no VF HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = $argv[1] ?? '/var/www/html';
require $root . '/lib/base.php';
$version = \OCP\Util::getVersion();
if (!in_array((int)$version[0], [33, 34, 35], true) || !\OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForUser('vereinsflieger_login')) { throw new RuntimeException('Requires an enabled app on an isolated supported development instance.'); }
\OC_App::loadApp('vereinsflieger_login');
$container = (new \OCA\VereinsfliegerLogin\AppInfo\Application())->getContainer();
$settings = $container->get(\OCA\VereinsfliegerLogin\Service\Configuration::class);
$resolver = $container->get(\OCA\VereinsfliegerLogin\Service\AccountResolver::class);
$links = $container->get(\OCA\VereinsfliegerLogin\Service\AccountLinks::class);
$sync = $container->get(\OCA\VereinsfliegerLogin\Service\RoleSynchronizer::class);
$known = $container->get(\OCA\VereinsfliegerLogin\Service\KnownRoles::class);
$config = \OCP\Server::get(\OCP\IConfig::class);
$users = \OCP\Server::get(\OCP\IUserManager::class);
$groups = \OCP\Server::get(\OCP\IGroupManager::class);
$database = \OCP\Server::get(\OCP\IDBConnection::class);
$original = $config->getAppValue('vereinsflieger_login', 'settings', '');
$originalKey = $config->getAppValue('vereinsflieger_login', 'appkey_encrypted', '');
if (($original !== '' && $original !== '{}') || $originalKey !== '') { throw new RuntimeException('Refusing to replace operator configuration. Use an unconfigured disposable instance.'); }
$cid = '9999999999'; $vf = '9000000000000001'; $created = []; $madeGroup = false; $count = 0;
function verify(bool $valid, string $name): void { global $count; if (!$valid) { throw new RuntimeException('FAIL ' . $name); } ++$count; echo 'PASS ' . $name . "\n"; }
function denied(callable $action, string $reason, string $name): void { try { $action(); } catch (\OCA\VereinsfliegerLogin\Service\ApiException $e) { verify($e->reason === $reason, $name); return; } throw new RuntimeException('FAIL ' . $name); }
try {
    foreach (['vf_request_limits', 'vf_known_roles', 'vf_account_links'] as $table) { $r = $database->executeQuery('SELECT COUNT(*) FROM *PREFIX*' . $table); $r->fetchOne(); $r->closeCursor(); verify(true, 'migration table ' . $table . ' readable'); }
    verify(!$settings->isEnabled() && !$settings->read()['defaultLogin'] && !$settings->read()['createAccounts'], 'installed app does not enable provider login');
    foreach ([\OCA\VereinsfliegerLogin\Controller\AdminController::class, \OCA\VereinsfliegerLogin\Controller\LoginController::class, \OCA\VereinsfliegerLogin\Service\LoginSession::class] as $class) { verify($container->get($class) instanceof $class, 'real Nextcloud dependency injection ' . basename(str_replace('\\', '/', $class))); }
    $admins = $groups->get('admin')->getUsers(); $admin = reset($admins); verify($admin !== null && $admin->isEnabled() && $groups->get('admin')->inGroup($admin), 'existing administrator remains active');
    $groupId = 'vf_runtime_test'; verify($groups->get($groupId) === null, 'fixture group does not overwrite existing group');
    $group = $groups->createGroup($groupId); $madeGroup = true;
    $fixtureId = 'vf_runtime_existing'; verify($users->get($fixtureId) === null, 'fixture account does not overwrite existing account');
    $existing = $users->createUser($fixtureId, 'Aa1!' . bin2hex(random_bytes(30))); $created[] = $fixtureId;
    $existing->setEMailAddress('existing@vf-runtime.invalid');
    $base = ['cid' => $cid, 'appkey' => 'runtime-fixture-not-a-real-appkey', 'enabled' => true, 'createAccounts' => true, 'defaultLogin' => false,
        'syncRoles' => false, 'links' => [['vfUid' => '9000000000000002', 'nextcloudUid' => $fixtureId]], 'roleMap' => [], 'excludedAccounts' => [$admin->getUID()]];
    $settings->save($base); $settings->checkLocally(); verify(true, 'local configuration validation on MariaDB');
    $identity = \OCA\VereinsfliegerLogin\Service\Identity::fromResponse(['uid' => $vf, 'roles' => ['Runtime test role'], 'firstname' => 'Anna', 'lastname' => 'Runtime', 'email' => 'new@vf-runtime.invalid']);
    $expected = 'vf_' . $cid . '_' . $vf; verify($users->get($expected) === null, 'new fixture provider account absent before test');
    $new = $resolver->resolve($identity); $created[] = $new->getUID();
    verify($new->getUID() === $expected && $new->getDisplayName() === 'Anna Runtime' && $new->getEMailAddress() === 'new@vf-runtime.invalid', 'native account creation stores profile');
    verify($config->getUserValue($expected, 'vereinsflieger_login', 'provisioned_vf_uid') === $vf && $config->getUserValue($expected, 'vereinsflieger_login', 'provisioned_cid') === $cid, 'provider provenance stored in native user configuration');
    $config->setUserValue($expected, 'core', 'lang', 'de_DE');
    $profile = $container->get(\OCA\VereinsfliegerLogin\Service\ProfileSynchronizer::class);
    $profile->apply($new, new \OCA\VereinsfliegerLogin\Service\Identity($vf, [], null, 'Updated', 'Member'));
    verify($new->getDisplayName() === 'Updated Member' && $config->getUserValue($expected, 'core', 'lang') === 'de_DE', 'native existing profile adopts verified names and preserves language');
    verify($links->get($cid, $vf)['nextcloudUid'] === $expected && $settings->linkedUid($vf) === $expected, 'MariaDB persistent identity resolution');
    verify($config->getUserValue($expected, 'vereinsflieger_login', 'snapshot', '') === '', 'account creation does not apply role permissions');
    verify($resolver->resolve(new \OCA\VereinsfliegerLogin\Service\Identity('9000000000000002', []))->getUID() === $fixtureId, 'manual mapping retains native account ID');
    denied(fn() => $resolver->resolve(\OCA\VereinsfliegerLogin\Service\Identity::fromResponse(['uid' => '9000000000000003', 'roles' => [], 'firstname' => 'Other', 'lastname' => 'Person', 'email' => 'existing@vf-runtime.invalid'])), 'not_linked', 'native email lookup cannot claim existing account');
    $sync->apply($new, $identity); verify($known->all($cid) === ['Runtime test role'], 'role catalog persists on MariaDB with sync disabled');
    verify(!$group->inGroup($new), 'disabled role sync preserves groups');
    $settings->save(array_replace($base, ['syncRoles' => true, 'roleMap' => [['role' => 'Runtime test role', 'group' => $groupId]]]));
    $sync->apply($new, $identity); verify($group->inGroup($new) && !$groups->get('admin')->inGroup($new), 'mapped role grants native group without admin');
    $sync->apply($new, new \OCA\VereinsfliegerLogin\Service\Identity($vf, [])); verify(!$group->inGroup($new), 'removed role removes only app-owned membership');
    $settings->save(array_replace($base, ['excludedAccounts' => [$admin->getUID(), $expected, $fixtureId]]));
    denied(fn() => $resolver->resolve(new \OCA\VereinsfliegerLogin\Service\Identity($vf, [])), 'local_only', 'native exclusion overrides provisioned account');
    denied(fn() => $resolver->resolve(new \OCA\VereinsfliegerLogin\Service\Identity('9000000000000002', [])), 'local_only', 'native exclusion overrides manual account');
    verify($groups->get('admin')->inGroup($admin), 'existing admin membership preserved after tests');
} finally {
    foreach (array_reverse($created) as $uid) { $users->get($uid)?->delete(); }
    if ($madeGroup) { $groups->get('vf_runtime_test')?->delete(); }
    $database->executeStatement('DELETE FROM *PREFIX*vf_account_links WHERE cid = ?', [$cid]);
    $database->executeStatement('DELETE FROM *PREFIX*vf_known_roles WHERE cid = ?', [$cid]);
    if ($original === '') { $config->deleteAppValue('vereinsflieger_login', 'settings'); } else { $config->setAppValue('vereinsflieger_login', 'settings', $original); }
    if ($originalKey === '') { $config->deleteAppValue('vereinsflieger_login', 'appkey_encrypted'); } else { $config->setAppValue('vereinsflieger_login', 'appkey_encrypted', $originalKey); }
}
echo json_encode(['checks' => $count, 'version' => implode('.', $version), 'database' => $config->getSystemValue('dbtype'), 'liveVfCalls' => 0, 'nativeSessionAnd2faBrowserTest' => false, 'fixturesRemoved' => true], JSON_THROW_ON_ERROR) . "\n";
