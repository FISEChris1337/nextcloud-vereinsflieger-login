<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCP { interface IConfig { public function getSystemValue(string $key, mixed $default = ''): mixed; } }
namespace {
    require __DIR__ . '/counter_support.php';
    foreach (['ApiException', 'ValidationException', 'LoginProtection', 'CounterStore', 'RequestGuard', 'RequestPermit'] as $class) { require __DIR__ . '/../vereinsflieger_login/lib/Service/' . $class . '.php'; }
    use OCA\VereinsfliegerLogin\Service\{ApiException, CounterStore, RequestGuard};
    // Child processes contend on the same SQLite database, without HTTP or sleeps.
    if (($argv[1] ?? '') === 'worker') {
        $store = new CounterStore(new SqlConnection($argv[2])); $accepted = 0;
        for ($i = 0; $i < 80; ++$i) { if ($store->consume('concurrent', 4, 100, 1000, 2000)) { ++$accepted; } }
        echo $accepted; exit;
    }
    $count = 0;
    function check(bool $valid, string $label): void { global $count; if (!$valid) { throw new \RuntimeException('FAIL ' . $label); } ++$count; echo 'PASS ' . $label . "\n"; }
    function rejects(callable $action, string $reason, string $label): void {
        try { $action(); } catch (ApiException $e) { check($e->reason === $reason, $label); return; } check(false, $label);
    }
    $config = new class implements \OCP\IConfig { public function getSystemValue(string $key, mixed $default = ''): mixed { return $key === 'secret' ? 'isolated-secret' : $default; } };
    $db = new SqlConnection(); $store = new CounterStore($db); $clock = new Clock(); $guard = new RequestGuard($store, $config, $clock);
    $appkey = 'fake-app-key'; $ip = '192.0.2.4';
    $permit = $guard->admit('person@example.test', $ip, $appkey, 4);
    check($guard->usage($appkey)['used'] === 4, 'four calls reserved before transport');
    rejects(fn() => $guard->admit('someone', $ip, $appkey, 4), 'local_budget', 'in-flight reservation prevents overspending');
    $permit->beforeRequest(); $permit->close(); $permit->close();
    check($guard->usage($appkey)['used'] === 1, 'unused calls refunded exactly once');
    rejects(fn() => $guard->admit('someone', $ip, $appkey, 4), 'local_budget', 'remaining three calls cannot start incomplete login');
    $clock->now = (int)(floor($clock->now / 86400) + 1) * 86400;
    check($guard->usage($appkey)['used'] === 0, 'next UTC date starts fresh budget');
    $permit = $guard->admit('user', $ip, $appkey, 4);
    for ($i = 0; $i < 4; ++$i) { $permit->beforeRequest(); } $permit->close();
    check($guard->usage($appkey)['used'] === 4, 'full successful flow stays counted');
    check($guard->usage('different-key')['used'] === 0, 'AppKey budgets are independent');
    $clock->now += 86400;
    $permit = $guard->admit('midnight', $ip, $appkey, 4); $permit->beforeRequest();
    $clock->now += 86400; $other = $guard->admit('newday', $ip, $appkey, 4); $other->beforeRequest(); $other->close(); $permit->close();
    check($guard->usage($appkey)['used'] === 1, 'previous-day refund cannot reduce new-day usage');
    for ($i = 0; $i < 3; ++$i) { $guard->failed('PERSON@example.test', $ip, $appkey); }
    $before = $guard->usage($appkey)['used'];
    rejects(fn() => $guard->admit(' person@EXAMPLE.test ', $ip, $appkey, 400), 'login_pause', 'three failures block normalized username or IP');
    check($guard->usage($appkey)['used'] === $before, 'blocked login consumes zero quota');
    rejects(fn() => $guard->admit('person@example.test', '192.0.2.5', $appkey, 400), 'login_pause', 'same username stays locked when the IP changes');
    $clock->now += 899;
    rejects(fn() => $guard->admit('person@example.test', $ip, $appkey, 400), 'login_pause', 'failure pause lasts full fifteen minutes');
    $clock->now += 1; $other = $guard->admit('person@example.test', $ip, $appkey, 400); $other->close();
    check($guard->usage($appkey)['used'] === $before, 'login admitted automatically after failure pause');
    $guard->failed('person@example.test', $ip, $appkey); $guard->failed('person@example.test', $ip, $appkey);
    $guard->succeeded('person@example.test', $ip, $appkey); $guard->failed('person@example.test', $ip, $appkey);
    rejects(fn() => $guard->admit('person@example.test', $ip, $appkey, 400), 'login_pause', 'successful authentication cannot erase shared IP failures');
    $other = $guard->admit('person@example.test', '192.0.2.8', $appkey, 400); $other->close();
    check(true, 'successful authentication resets global username failures');
    $guard->providerLimited($appkey);
    rejects(fn() => $guard->admit('other', '192.0.2.99', $appkey, 400), 'provider_pause', 'provider 429 pauses all identities without transport');
    check($guard->usage($appkey)['providerPaused'], 'provider pause visible to admin');
    $clock->now += 900;
    check(!$guard->usage($appkey)['providerPaused'], 'provider pause expires automatically');
    for ($i = 0; $i < 6; ++$i) { $p = $guard->admit('challenge', $ip, $appkey, 400); $p->close(); $guard->succeeded('challenge', $ip, $appkey); }
    rejects(fn() => $guard->admit('challenge', $ip, $appkey, 400), 'login_pause', 'six pair attempts cap repeated challenges and successful-login loops');
    $clock->now += 900;
    for ($i = 0; $i < 30; ++$i) { $p = $guard->admit('user' . $i, $ip, $appkey, 400); $p->close(); }
    rejects(fn() => $guard->admit('different-user', $ip, $appkey, 400), 'login_pause', 'IP attempt cap bounds rotating usernames');
    check($guard->usage($appkey)['used'] === $before, 'attempt caps and unused permits leave quota unchanged');
    $clock->now += 900;
    $policy = ['loginFailureLimit' => 2, 'loginPauseMinutes' => 7, 'loginAttemptWindowMinutes' => 2, 'loginPairAttemptLimit' => 2, 'loginIpAttemptLimit' => 4, 'providerPauseMinutes' => 3];
    $guard->failed('custom', $ip, $appkey, $policy); $guard->failed('custom', $ip, $appkey, $policy);
    try { $guard->admit('custom', $ip, $appkey, 400, $policy); check(false, 'custom failure limit must block'); }
    catch (ApiException $e) { check($e->reason === 'login_pause' && $e->retryAfter === 420, 'configured failure threshold and seven-minute pause produce exact retry time'); }
    $clock->now += 419;
    rejects(fn() => $guard->admit('custom', $ip, $appkey, 400, $policy), 'login_pause', 'configured failure pause does not expire early');
    $clock->now += 1; $p = $guard->admit('custom', $ip, $appkey, 400, $policy); $p->close();
    check(true, 'configured failure pause expires at its boundary');
    $guard->providerLimited($appkey, $policy);
    check($guard->usage($appkey)['providerPauseRemaining'] === 180, 'configured provider pause visible with remaining seconds');
    $clock->now += 179;
    rejects(fn() => $guard->admit('another', $ip, $appkey, 400, $policy), 'provider_pause', 'custom provider pause blocks without transport');
    $clock->now += 1;
    check(!$guard->usage($appkey)['providerPaused'], 'custom provider pause expires after three minutes');
    for ($i = 0; $i < 2; ++$i) { $p = $guard->admit('pair-custom', $ip, $appkey, 400, $policy); $p->close(); }
    try { $guard->admit('pair-custom', $ip, $appkey, 400, $policy); check(false, 'custom attempt limit must block'); }
    catch (ApiException $e) { check($e->reason === 'login_pause' && $e->retryAfter === 120, 'configured pair limit uses configured two-minute window'); }
    $clock->now += 120; $p = $guard->admit('pair-custom', $ip, $appkey, 400, $policy); $p->close();
    check(true, 'attempt window is not prolonged by blocked attempts');
    $clock->now += 120;
    for ($i = 0; $i < 4; ++$i) { $p = $guard->admit('custom-user-' . $i, $ip, $appkey, 400, $policy); $p->close(); }
    rejects(fn() => $guard->admit('rotated', $ip, $appkey, 400, $policy), 'login_pause', 'configured IP limit caps different usernames');


    $independentKey = 'independent-lock-tests';
    check($guard->failed('random-one', '192.0.2.51', $independentKey) === 0, 'first wrong username does not pause');
    $guard->failed('random-two', '192.0.2.51', $independentKey);
    check($guard->failed('random-three', '192.0.2.51', $independentKey) === 900, 'third wrong login pauses IP despite changing usernames');
    rejects(fn() => $guard->admit('random-four', '192.0.2.51', $independentKey, 400), 'login_pause', 'rotating usernames cannot bypass IP failure threshold');
    check($guard->usage($independentKey)['used'] === 0, 'paused rotating usernames consume no API quota');
    $p = $guard->admit('random-four', '192.0.2.52', $independentKey, 400); $p->close();
    check(true, 'unrelated username and IP stay available');
    foreach (['192.0.2.61', '192.0.2.62', '192.0.2.63'] as $source) { $guard->failed('Rotating-IP', $source, $independentKey); }
    rejects(fn() => $guard->admit(' rotating-ip ', '192.0.2.64', $independentKey, 400), 'login_pause', 'username failure threshold spans three different IPs');
    foreach (['v6-one', 'v6-two', 'v6-three'] as $name) { $guard->failed($name, '2001:0db8:0000:0000:0000:0000:0000:0001', $independentKey); }
    rejects(fn() => $guard->admit('v6-four', '2001:db8::1', $independentKey, 400), 'login_pause', 'equivalent IPv6 spellings share their IP failure counter');
    $p = $guard->admit('v6-four', '2001:db8::2', $independentKey, 400); $p->close();
    check(true, 'different IPv6 address is independent');
    $pauses = $guard->pauses($independentKey);
    check(count($pauses) === 3, 'admin lists independent username IPv4 and IPv6 pauses');
    check($guard->pauses('wrong-scope') === [], 'pause metadata is isolated per AppKey');
    $row = array_values(array_filter($pauses, fn($v) => $v['ip'] === '192.0.2.51'))[0];
    check(!$guard->releasePause('wrong-scope', $row['id']), 'different AppKey cannot remove a pause');
    check(!$guard->releasePause($independentKey, '../not-an-id'), 'malformed pause identifier rejected');
    check($guard->releasePause($independentKey, $row['id']), 'admin can remove a selected IP pause');
    $p = $guard->admit('new-user', '192.0.2.51', $independentKey, 400); $p->close();
    check(true, 'removed IP pause permits new login immediately');
    rejects(fn() => $guard->admit('v6-four', '2001:db8::1', $independentKey, 400), 'login_pause', 'removing IPv4 pause leaves IPv6 pause in place');
    $row = array_values(array_filter($guard->pauses($independentKey), fn($v) => $v['kind'] === 'failures'))[0];
    check($guard->releasePause($independentKey, $row['id']), 'admin can remove a global username pause');
    $p = $guard->admit('rotating-ip', '192.0.2.64', $independentKey, 400); $p->close();
    check(true, 'removed username pause permits login from another IP');
    $guard->providerLimited($independentKey);
    $row = $guard->pauses($independentKey)[0];
    check($guard->releasePause($independentKey, $row['id']), 'remaining IPv6 pause removable');
    check($guard->usage($independentKey)['providerPaused'], 'admin login unlock does not erase provider pause');
    $clock->now += 900; $guard->pauses($independentKey);
    check((int)$db->pdo->query('SELECT COUNT(*) FROM oc_vf_login_pauses')->fetchColumn() === 0, 'expired pause records including personal metadata are pruned');

    $hourKey = 'hour-app-key'; $hourPolicy = ['hourlyApiBudget' => 4];
    $p = $guard->admit('hour-user', $ip, $hourKey, 400, $hourPolicy);
    check($guard->usage($hourKey)['used'] === 4 && $guard->usage($hourKey)['hourUsed'] === 4, 'hour and day budgets reserve the whole flow together');
    rejects(fn() => $guard->admit('hour-other', $ip, $hourKey, 400, $hourPolicy), 'local_hour_budget', 'parallel flow cannot exceed reserved hourly budget');
    check($guard->usage($hourKey)['used'] === 4, 'rejected hourly reservation refunds the daily reservation');
    $p->beforeRequest(); $p->close(); $p->close();
    check($guard->usage($hourKey)['used'] === 1 && $guard->usage($hourKey)['hourUsed'] === 1, 'partial flow refunds unused calls in both budgets exactly once');
    rejects(fn() => $guard->admit('hour-other', $ip, $hourKey, 400, $hourPolicy), 'local_hour_budget', 'remaining three hourly calls cannot admit incomplete flow');
    $clock->now = (int)(floor($clock->now / 3600) + 1) * 3600;
    $p = $guard->admit('cross-hour', $ip, $hourKey, 400, $hourPolicy); $p->beforeRequest();
    $clock->now += 3600; $q = $guard->admit('next-hour', $ip, $hourKey, 400, $hourPolicy); $q->beforeRequest(); $q->close(); $p->close();
    check($guard->usage($hourKey)['hourUsed'] === 1 && $guard->usage($hourKey)['used'] === 3, 'old-hour refund does not reduce new-hour usage or lose daily charges');
    $p = $guard->admit('hour-disabled', $ip, $hourKey, 400); $p->close();
    check($guard->usage($hourKey)['hourUsed'] === 1, 'disabling hourly budget retains usage and still uses daily budget');
    $rows = $db->pdo->query('SELECT identifier FROM oc_vf_request_limits')->fetchAll(\PDO::FETCH_COLUMN);
    check(count($rows) > 0 && array_all_compatible($rows), 'only keyed hashes stored without email IP or AppKey');
    $db->pdo->exec('DROP TABLE oc_vf_request_limits');
    try { $guard->admit('user', $ip, $appkey, 400); check(false, 'database outage must reject admission'); }
    catch (\PDOException) { check(true, 'database outage fails closed before transport'); }
    $file = tempnam(sys_get_temp_dir(), 'vf-counter-'); $connection = new SqlConnection($file); $children = [];
    try {
        for ($i = 0; $i < 4; ++$i) {
            $cmd = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_sqlite', __FILE__, 'worker', $file];
            $child = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($child)) { throw new \RuntimeException('Worker failed to start'); } fclose($pipes[0]); $children[] = [$child, $pipes];
        }
        $accepted = 0;
        foreach ($children as [$child, $pipes]) {
            $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($child) !== 0 || $error !== '' || !ctype_digit($out)) { throw new \RuntimeException('Concurrency worker failed: ' . $error); } $accepted += (int)$out;
        }
        check($accepted === 25 && (new CounterStore($connection))->value('concurrent', 1000) === 100, 'four competing processes reserve exactly the shared budget');
    } finally { unset($connection); unlink($file); }
    echo "\n$count isolated quota checks passed, including real SQLite concurrency. No live API.\n";
    function array_all_compatible(array $rows): bool { foreach ($rows as $row) { if (!preg_match('/^[a-f0-9]{64}$/D', $row)) { return false; } } return true; }
}
