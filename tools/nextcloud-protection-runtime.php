<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// CLI only: uses native services and unique fixture counters. No configuration,
// user account or provider API changes. Cleans only its own HMAC fixture keys.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require '/var/www/html/lib/base.php';
\OC_App::loadApp('vereinsflieger_login');
$container = (new \OCA\VereinsfliegerLogin\AppInfo\Application())->getContainer();
$store = $container->get(\OCA\VereinsfliegerLogin\Service\CounterStore::class);
$guard = $container->get(\OCA\VereinsfliegerLogin\Service\RequestGuard::class);
$config = \OCP\Server::get(\OCP\IConfig::class);
$clock = \OCP\Server::get(\OCP\AppFramework\Utility\ITimeFactory::class);
$fixtureKey = 'vf-quota-fixture-' . bin2hex(random_bytes(12));
$ip = '192.0.2.200'; $start = $clock->getTime(); $count = 0; $permit = null;
$policy = ['hourlyApiBudget' => 4, 'loginFailureLimit' => 1, 'loginPauseMinutes' => 2, 'providerPauseMinutes' => 3];
function verifyProtection(bool $condition, string $label): void {
    global $count;
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    ++$count; echo 'PASS ' . $label . "\n";
}
try {
    $permit = $guard->admit('fixture-user', $ip, $fixtureKey, 400, $policy);
    $usage = $guard->usage($fixtureKey);
    verifyProtection($usage['used'] === 4 && $usage['hourUsed'] === 4, 'native database reserves daily and hourly flow');
    try { $guard->admit('other-fixture', $ip, $fixtureKey, 400, $policy); throw new RuntimeException('Expected hourly rejection'); }
    catch (\OCA\VereinsfliegerLogin\Service\ApiException $e) { verifyProtection($e->reason === 'local_hour_budget' && $e->retryAfter > 0, 'native hourly rejection has retry time'); }
    verifyProtection($guard->usage($fixtureKey)['used'] === 4, 'native hourly rejection refunds daily reservation');
    $permit->beforeRequest(); $permit->close(); $permit->close();
    $usage = $guard->usage($fixtureKey);
    verifyProtection($usage['used'] === 1 && $usage['hourUsed'] === 1, 'native unused reservations refunded in both counters exactly once');
    $guard->failed('fixture-user', $ip, $fixtureKey, $policy);
    try { $guard->admit('fixture-user', $ip, $fixtureKey, 400, $policy); throw new RuntimeException('Expected failure pause'); }
    catch (\OCA\VereinsfliegerLogin\Service\ApiException $e) { verifyProtection($e->reason === 'login_pause' && $e->retryAfter > 0 && $e->retryAfter <= 120, 'native configurable failure pause is enforced'); }
    try { $guard->admit('fixture-user', '192.0.2.201', $fixtureKey, 400, $policy); throw new RuntimeException('Expected global username pause'); }
    catch (\OCA\VereinsfliegerLogin\Service\ApiException $e) { verifyProtection($e->reason === 'login_pause', 'native username pause applies across IPs'); }
    $records = $guard->pauses($fixtureKey);
    verifyProtection(count($records) === 1 && $records[0]['username'] === 'fixture-user', 'native admin pause metadata available');
    verifyProtection(!$guard->releasePause('wrong-fixture-key', $records[0]['id']), 'native pause removal rejects wrong AppKey scope');
    verifyProtection($guard->releasePause($fixtureKey, $records[0]['id']), 'native admin removes username pause');
    foreach (['rotate-one', 'rotate-two', 'rotate-three'] as $name) { $guard->failed($name, '2001:db8::200', $fixtureKey); }
    try { $guard->admit('rotate-four', '2001:0db8:0:0:0:0:0:0200', $fixtureKey, 400); throw new RuntimeException('Expected shared IPv6 pause'); }
    catch (\OCA\VereinsfliegerLogin\Service\ApiException $e) { verifyProtection($e->reason === 'login_pause', 'native IPv6 pause blocks rotating usernames and equivalent addresses'); }
    $records = $guard->pauses($fixtureKey);
    verifyProtection(count($records) === 1 && $records[0]['ip'] === '2001:db8::200', 'native IP pause stores canonical address');
    verifyProtection($guard->releasePause($fixtureKey, $records[0]['id']) && $guard->pauses($fixtureKey) === [], 'native IP pause removed with metadata');
    $pause = $guard->providerLimited($fixtureKey, $policy);
    verifyProtection($pause > 0 && $pause <= 180 && $guard->usage($fixtureKey)['providerPaused'], 'native configurable provider pause is visible');
    try { $guard->admit('other-fixture', $ip, $fixtureKey, 400, $policy); throw new RuntimeException('Expected provider pause'); }
    catch (\OCA\VereinsfliegerLogin\Service\ApiException $e) { verifyProtection($e->reason === 'provider_pause' && $e->retryAfter > 0, 'native provider pause prevents admission'); }
} finally {
    $permit?->close();
    $end = $clock->getTime(); $kinds = ['provider_pause', 'failures', 'ip_failures', 'ip_attempts', 'pair_attempts'];
    foreach ([$start, $end] as $time) { $kinds[] = 'budget:' . gmdate('Y-m-d', $time); $kinds[] = 'hour_budget:' . gmdate('Y-m-d-H', $time); }
    foreach (array_unique($kinds) as $kind) {
        foreach (['', 'fixture-user', 'other-fixture', 'rotate-one', 'rotate-two', 'rotate-three', 'rotate-four'] as $name) {
            foreach (['', bin2hex(inet_pton($ip)), bin2hex(inet_pton('192.0.2.201')), bin2hex(inet_pton('2001:db8::200'))] as $address) {
                $key = hash_hmac('sha256', json_encode([$fixtureKey, $kind, $name, $address], JSON_THROW_ON_ERROR), (string)$config->getSystemValue('secret', ''));
                $store->clear($key);
            }
        }
    }
}
verifyProtection($guard->usage($fixtureKey)['used'] === 0 && $guard->usage($fixtureKey)['hourUsed'] === 0 && !$guard->usage($fixtureKey)['providerPaused'], 'native fixture counters removed');
echo $count . " native quota checks passed. No live Vereinsflieger calls or operator configuration writes.\n";
