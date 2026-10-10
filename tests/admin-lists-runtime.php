<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require '/var/www/html/lib/base.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\VereinsfliegerLogin\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../vereinsflieger_login/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
use OCA\VereinsfliegerLogin\Service\{AccountLinks, AdminLists, Configuration, CounterStore, KnownRoles, RequestGuard, ValidationException};

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$config = \OCP\Server::get(\OCP\IConfig::class);
$settings = new Configuration($config, \OCP\Server::get(\OCP\Security\ICrypto::class), \OCP\Server::get(\OCP\IUserManager::class), \OCP\Server::get(\OCP\IGroupManager::class), new KnownRoles($db), new AccountLinks($db));
$store = new CounterStore($db);
$lists = new AdminLists($db, $settings, new RequestGuard($store, $config, \OCP\Server::get(\OCP\AppFramework\Utility\ITimeFactory::class)));
$cid = $settings->read()['cid'];
if ($cid === '') {
    throw new RuntimeException('Use an already configured development instance.');
}
$prefix = 'vf_page_' . bin2hex(random_bytes(6));
$now = time();
$scope = hash('sha256', $prefix);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$db->beginTransaction();
try {
    for ($i = 0; $i < 61; ++$i) {
        $uid = $prefix . '_' . sprintf('%03d', $i);
        // Decimal API UID; no real user object, jobs or files are created.
        $vf = (string)(980000000000000 + $i);
        $id = hash('sha256', json_encode([$cid, $vf], JSON_THROW_ON_ERROR));
        $db->executeStatement('INSERT INTO *PREFIX*vf_account_links (identifier, cid, vfuid, ncuid, blocked, createdat) VALUES (?, ?, ?, ?, ?, ?)', [$id, $cid, $vf, $uid, 0, $now]);
        foreach (['snapshot' => ['roles' => ['Mitglied', '<unsafe>']], 'group_sync_warning' => ['groups' => ['missing-group']]] as $key => $names) {
            $value = ['cid' => $cid, 'vfUid' => $vf, 'capturedAt' => gmdate('c', $now)] + $names;
            $db->executeStatement('INSERT INTO *PREFIX*preferences (userid, appid, configkey, configvalue) VALUES (?, ?, ?, ?)', [$uid, 'vereinsflieger_login', $key, json_encode($value)]);
        }
        $pause = hash('sha256', $uid);
        $store->consume($pause, 1, 1, $now, $now + 900);
        $store->rememberPause($pause, $scope, $uid, '2001:db8::' . dechex($i + 1), 'failures', [$pause], $now + 900);
    }
    foreach (['identities', 'snapshots', 'warnings'] as $kind) {
        $first = $lists->page($kind, 1, $prefix);
        $second = $lists->page($kind, 2, $prefix);
        $third = $lists->page($kind, 3, $prefix);
        $check(count($first['items']) === 25 && $first['hasMore'], $kind . ': bounded first page');
        $check(count($second['items']) === 25 && $second['hasMore'], $kind . ': bounded second page');
        $check(count($third['items']) === 11 && !$third['hasMore'], $kind . ': last page preserves remaining records');
        $all = [...$first['items'], ...$second['items'], ...$third['items']];
        $check(count(array_unique(array_column($all, 'uid'))) === 61, $kind . ': no duplicates or dropped rows');
        $check(count($lists->page($kind, 1, $prefix . '_000')['items']) === 1, $kind . ': literal underscore search');
        $check($lists->page($kind, 1, $prefix . "%'")['items'] === [], $kind . ': wildcard and quote cannot broaden search');
    }
    $first = $store->pausePage($scope, $now, 1, $prefix);
    $last = $store->pausePage($scope, $now, 3, $prefix);
    $check(count($first['items']) === 25 && $first['hasMore'] && count($last['items']) === 11 && !$last['hasMore'], 'pauses are database-paged');
    $check(count($store->pausePage($scope, $now, 1, '2001:db8::1')['items']) > 0, 'IPv6 pause search');
    $check($store->pausePage(hash('sha256', 'other-scope'), $now, 1, $prefix)['items'] === [], 'pause scope isolation');
    $check($store->pausePage($scope, $now + 901, 1, $prefix)['items'] === [], 'expired pauses omitted');
    $store->releasePause($scope, $first['items'][0]['id']);
    $check(count($store->pausePage($scope, $now, 3, $prefix)['items']) === 10, 'unlock remains functional and updates last page');
    $public = $settings->publicData(false);
    $check($public['snapshots'] === [] && $public['syncWarnings'] === [] && $public['provisionedLinks'] === [], 'admin initial payload omits full lists');
    foreach ([['invalid', 1, ''], ['identities', 0, ''], ['snapshots', 100001, ''], ['warnings', 1, str_repeat('x', 129)], ['snapshots', 1, "\xFF"]] as $args) {
        try {
            $lists->page(...$args);
            throw new RuntimeException('Invalid page accepted');
        } catch (ValidationException) {
            ++$checks;
        }
    }
    echo json_encode(['checks' => $checks, 'liveVfCalls' => 0, 'fixturesRolledBack' => true], JSON_THROW_ON_ERROR);
} finally {
    $db->rollBack();
}
