<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
use OCA\VereinsfliegerLogin\Service\{Configuration, AccountLinks, CounterStore, RequestGuard, VereinsfliegerClient, LoginProtection, RoleSynchronizer, Identity};
$protectDb = new MemoryConfig(); $protectGroups = new Groups(); $protectUsers = new Users();
$protectCfg = new Configuration($protectDb, new Crypto(), $protectUsers, $protectGroups, $catalog, new AccountLinks(new SqlConnection()));
$protectBase = array_replace($base, ['enabled' => true]); $protectCfg->save($protectBase);
check(array_intersect_key($protectCfg->read(), LoginProtection::DEFAULTS) === LoginProtection::DEFAULTS, 'old settings keep existing guard defaults and no hourly budget');
$protectPolicy = ['loginFailureLimit' => '4', 'loginPauseMinutes' => '10', 'loginAttemptWindowMinutes' => '5', 'loginPairAttemptLimit' => '8', 'loginIpAttemptLimit' => '40', 'providerPauseMinutes' => '20', 'hourlyApiBudget' => '100'];
$protectCfg->save($protectBase + $protectPolicy); $protectCfg->save($protectBase);
check($protectCfg->read()['loginFailureLimit'] === 4 && $protectCfg->read()['hourlyApiBudget'] === 100 && $protectCfg->read()['providerPauseMinutes'] === 20, 'guard preferences save as integers and survive clients omitting new fields');
$protectBefore = $protectDb->app;
foreach ([['loginFailureLimit' => 0], ['loginFailureLimit' => true], ['loginFailureLimit' => 1.5], ['loginPauseMinutes' => 1441], ['loginAttemptWindowMinutes' => []], ['loginPairAttemptLimit' => -1], ['loginIpAttemptLimit' => 1001], ['providerPauseMinutes' => 0], ['hourlyApiBudget' => 3], ['hourlyApiBudget' => 501]] as $invalid) {
    rejects(fn() => $protectCfg->save(array_replace($protectBase, ['appkey' => 'replacement-key'], $invalid)), 'invalid protection setting rejected before storing changes');
}
check($protectDb->app === $protectBefore, 'invalid protection settings preserve encrypted key and complete saved configuration');
$protectCfg->save($protectBase + LoginProtection::DEFAULTS);
$protectClock = new Clock(); $protectGuard = new RequestGuard(new CounterStore(new SqlConnection()), $protectDb, $protectClock);
$protectHttp = new Http(); $protectApi = new VereinsfliegerClient($protectHttp, $protectCfg, $protectGuard);
foreach ([['', 'password', ''], ['   ', 'password', ''], [str_repeat('x', 255), 'password', ''], ["user\n", 'password', ''], ["\xc3(", 'password', ''], ['user', '', ''], ['user', str_repeat('x', 1025), ''], ['user', "password\0", ''], ['user', "pa\tss", ''], ['user', "pa\u{0085}ss", ''], ['user', 'password', str_repeat('x', 33)], ['user', 'password', "123\r456"], ['user', 'password', "\xc3("]] as [$username, $password, $otp]) {
    rejects(fn() => $protectApi->authenticate($username, $password, $otp, '192.0.2.8'), 'invalid credential input rejected before API transport', 'input_format');
}
rejects(fn() => $protectApi->authenticate('user', '€password', '', '192.0.2.8'), 'unrepresentable password rejected before token request', 'password_encoding');
check($protectHttp->calls === [] && $protectGuard->usage($protectCfg->key())['used'] === 0 && $protectGuard->usage($protectCfg->key())['hourUsed'] === 0, 'all preflight failures consume zero requests and zero quota');
$protectHttp->queue = [[200, ['accesstoken' => 'fake_token_12345678']], [200, ''], [200, ['uid' => 42, 'roles' => []]], [200, '']];
$protectApi->authenticate('  Flug Mitglied@club  ', ' p#&=äss ', ' backup-code ', '192.0.2.8');
parse_str($protectHttp->calls[1][2]['body'], $protectPosted);
check($protectPosted['username'] === 'Flug Mitglied@club' && $protectPosted['auth_secret'] === 'backup-code' && $protectPosted['password'] === md5(" p#&=\xE4ss "), 'non-email account names and special characters remain usable without trimming password');
$protectCfg->save(array_replace($protectBase, ['syncRoles' => true]));
$protectGroups->items['Lokale Gruppe'] = new Group();
$protectGroups->items['Lokale Gruppe']->addUser($protectUsers->items['alice']);
$protectGroups->items['admin']->addUser($protectUsers->items['alice']);
unset($protectGroups->items['Technik']);
rejects(fn() => $protectCfg->checkLocally(), 'local check detects deleted mapped group');
$protectSync = new RoleSynchronizer($protectCfg, $protectDb, $protectGroups, $catalog);
$protectDb->user['alice']['owned_groups'] = '["Lokale Gruppe"]';
$protectSync->apply($protectUsers->items['alice'], new Identity('42', ['Mitglied', 'Technisches Personal']));
check($protectDb->user['alice']['owned_groups'] === '["Lokale Gruppe"]' && !$protectGroups->items['Mitglieder']->inGroup($protectUsers->items['alice']) && $protectGroups->items['Lokale Gruppe']->inGroup($protectUsers->items['alice']) && $protectGroups->items['admin']->inGroup($protectUsers->items['alice']), 'deleted target skips all additions removals and ownership changes while login continues');
check($protectCfg->publicData()['syncWarnings']['alice']['groups'] === ['Technik'] && isset($protectDb->user['alice']['snapshot']), 'skipped sync stores persistent admin diagnostic and captures verified roles');
$protectGroups->items['Technik'] = new Group();
$protectSync->apply($protectUsers->items['alice'], new Identity('42', ['Mitglied', 'Technisches Personal']));
check($protectGroups->items['Technik']->inGroup($protectUsers->items['alice']) && $protectCfg->publicData()['syncWarnings'] === [], 'repairing missing group restores synchronization and clears diagnostic after successful sync');
$protectCfg->checkLocally();
check(true, 'local check accepts repaired role and account mappings');
$protectStored = $protectDb->app['settings']; $protectData = json_decode($protectStored, true);
$protectDb->app['settings'] = json_encode(array_replace($protectData, ['roleMap' => [['role' => 'Mitglied', 'group' => 'admin']]]));
rejects(fn() => $protectCfg->checkLocally(), 'local check rejects forbidden administrator matching even in old stored settings');
$protectDb->app['settings'] = json_encode(array_replace($protectData, ['roleMap' => [['role' => 'Unknown role', 'group' => 'Mitglieder']]]));
rejects(fn() => $protectCfg->checkLocally(), 'local check identifies unconfirmed historical role mapping');
$protectDb->app['settings'] = $protectStored;
