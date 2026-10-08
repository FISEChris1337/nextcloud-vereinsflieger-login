<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCP {
    interface IConfig {
        public function getAppValue(string $app, string $key, string $default = ''): string;
        public function setAppValue(string $app, string $key, string $value): void;
        public function getUserValue(string $uid, string $app, string $key, string $default = ''): string;
        public function setUserValue(string $uid, string $app, string $key, string $value): void;
    }
}
namespace {
    require __DIR__ . '/counter_support.php';
    foreach (['ApiException', 'RoleName', 'Identity', 'KnownRoles', 'RoleNameMigration'] as $class) {
        require __DIR__ . '/../vereinsflieger_login/lib/Service/' . $class . '.php';
    }
    use OCA\VereinsfliegerLogin\Service\KnownRoles;
    use OCA\VereinsfliegerLogin\Service\RoleNameMigration;
    const APP = 'vereinsflieger_login';
    final class MigrationConfig implements \OCP\IConfig {
        public function __construct(private SqlConnection $db) {
            $db->pdo->exec('CREATE TABLE oc_appconfig (appid TEXT, configkey TEXT, configvalue TEXT, PRIMARY KEY(appid, configkey))');
            $db->pdo->exec('CREATE TABLE oc_preferences (userid TEXT, appid TEXT, configkey TEXT, configvalue TEXT, PRIMARY KEY(userid, appid, configkey))');
        }
        public function getAppValue(string $app, string $key, string $default = ''): string {
            $value = $this->db->executeQuery('SELECT configvalue FROM oc_appconfig WHERE appid = ? AND configkey = ?', [$app, $key])->fetchOne();
            return $value === false ? $default : (string)$value;
        }
        public function setAppValue(string $app, string $key, string $value): void {
            $this->db->executeStatement('INSERT INTO oc_appconfig VALUES (?, ?, ?) ON CONFLICT(appid, configkey) DO UPDATE SET configvalue = excluded.configvalue', [$app, $key, $value]);
        }
        public function getUserValue(string $uid, string $app, string $key, string $default = ''): string {
            $value = $this->db->executeQuery('SELECT configvalue FROM oc_preferences WHERE userid = ? AND appid = ? AND configkey = ?', [$uid, $app, $key])->fetchOne();
            return $value === false ? $default : (string)$value;
        }
        public function setUserValue(string $uid, string $app, string $key, string $value): void {
            $this->db->executeStatement('INSERT INTO oc_preferences VALUES (?, ?, ?, ?) ON CONFLICT(userid, appid, configkey) DO UPDATE SET configvalue = excluded.configvalue', [$uid, $app, $key, $value]);
        }
    }
    $count = 0;
    function check(bool $valid, string $label): void {
        global $count;
        if (!$valid) { throw new RuntimeException('FAIL ' . $label); }
        ++$count; echo 'PASS ' . $label . "\n";
    }
    function fixture(): array {
        $db = new SqlConnection(); $cfg = new MigrationConfig($db); $catalog = new KnownRoles($db);
        $catalog->remember('123', ['Schatzmeister&#47;Kassenwart', 'Schatzmeister/Kassenwart', 'Literal &amp;amp;']);
        $catalog->remember('456', ['Schatzmeister&#47;Kassenwart']);
        $cfg->setAppValue(APP, 'settings', json_encode(['cid'=>'123', 'links'=>[['vfUid'=>'42','nextcloudUid'=>'alice']], 'roleMap'=>[['role'=>'Schatzmeister&#47;Kassenwart','group'=>'Finance']], 'enabled'=>true], JSON_THROW_ON_ERROR));
        $cfg->setAppValue(APP, 'appkey_encrypted', 'opaque-encrypted-fixture');
        $cfg->setUserValue('alice', APP, 'snapshot', json_encode(['vfUid'=>'42','cid'=>'123','roles'=>['Schatzmeister&#47;Kassenwart','Schatzmeister/Kassenwart'], 'capturedAt'=>'2026-01-01T00:00:00Z'], JSON_THROW_ON_ERROR));
        $cfg->setUserValue('bob', APP, 'snapshot', '{"vfUid":"43", "cid":"123", "roles":["Mitglied"]}');
        $cfg->setUserValue('alice', APP, 'owned_groups', '["Finance"]');
        return [$db, $cfg, $catalog];
    }
    [$db, $cfg, $catalog] = fixture();
    $oldSettings = json_decode($cfg->getAppValue(APP, 'settings'), true);
    $oldSnapshot = json_decode($cfg->getUserValue('alice', APP, 'snapshot'), true);
    $unchanged = $cfg->getUserValue('bob', APP, 'snapshot');
    $migration = new RoleNameMigration($db, $cfg); $migration->run();
    check($catalog->all('123') === ['Literal &amp;', 'Schatzmeister/Kassenwart'], 'catalog renames entities and merges an existing decoded alias');
    check($catalog->all('456') === ['Schatzmeister/Kassenwart'], 'role normalization remains isolated by club');
    $newSettings = json_decode($cfg->getAppValue(APP, 'settings'), true);
    check($newSettings['roleMap'] === [['role'=>'Schatzmeister/Kassenwart','group'=>'Finance']], 'existing role mapping keeps its target group');
    $oldSettings['roleMap'] = $newSettings['roleMap'];
    check($newSettings === $oldSettings && $cfg->getAppValue(APP, 'appkey_encrypted') === 'opaque-encrypted-fixture', 'account links, options and encrypted app key unchanged');
    $newSnapshot = json_decode($cfg->getUserValue('alice', APP, 'snapshot'), true);
    check($newSnapshot['roles'] === ['Schatzmeister/Kassenwart'], 'stored role snapshot decoded and deduplicated');
    $oldSnapshot['roles'] = $newSnapshot['roles'];
    check($newSnapshot === $oldSnapshot && $cfg->getUserValue('alice', APP, 'owned_groups') === '["Finance"]', 'identity, timestamp and group ownership preserved');
    check($cfg->getUserValue('bob', APP, 'snapshot') === $unchanged, 'unaffected snapshot bytes remain unchanged');
    $rows = $db->pdo->query('SELECT * FROM oc_vf_known_roles ORDER BY identifier')->fetchAll(PDO::FETCH_ASSOC);
    check($cfg->getAppValue(APP, 'role_names_decoded') === '1', 'completion marker recorded');
    $migration->run();
    check($db->pdo->query('SELECT * FROM oc_vf_known_roles ORDER BY identifier')->fetchAll(PDO::FETCH_ASSOC) === $rows, 'repeat migration does not decode literal entities again');

    [$db, $cfg, $catalog] = fixture();
    $s = json_decode($cfg->getAppValue(APP, 'settings'), true);
    $s['roleMap'][] = ['role'=>'Schatzmeister/Kassenwart','group'=>'Finance'];
    $cfg->setAppValue(APP, 'settings', json_encode($s));
    (new RoleNameMigration($db, $cfg))->run();
    check(count(json_decode($cfg->getAppValue(APP, 'settings'), true)['roleMap']) === 1, 'equivalent mappings to the same group are merged');

    foreach (['conflict', 'control', 'write failure'] as $failure) {
        [$db, $cfg, $catalog] = fixture();
        if ($failure === 'conflict') {
            $s = json_decode($cfg->getAppValue(APP, 'settings'), true); $s['roleMap'][] = ['role'=>'Schatzmeister/Kassenwart','group'=>'Other']; $cfg->setAppValue(APP, 'settings', json_encode($s));
        } elseif ($failure === 'control') {
            $catalog->remember('123', ['Role&NewLine;Injected']);
        } else {
            $db->pdo->exec("CREATE TRIGGER fail_snapshot BEFORE UPDATE ON oc_preferences BEGIN SELECT RAISE(ABORT, 'simulated persistence failure'); END");
        }
        $beforeRoles = $catalog->all('123'); $beforeSettings = $cfg->getAppValue(APP, 'settings'); $beforeSnapshot = $cfg->getUserValue('alice', APP, 'snapshot');
        try { (new RoleNameMigration($db, $cfg))->run(); throw new LogicException('Expected migration refusal'); }
        catch (LogicException $e) { throw $e; }
        catch (Throwable) {
            check($catalog->all('123') === $beforeRoles && $cfg->getAppValue(APP, 'settings') === $beforeSettings && $cfg->getUserValue('alice', APP, 'snapshot') === $beforeSnapshot && $cfg->getAppValue(APP, 'role_names_decoded') === '', $failure . ' leaves role data, configuration and marker unchanged');
        }
    }
    $db = new SqlConnection(); $cfg = new MigrationConfig($db);
    $original = '{ "cid": "123", "roleMap": [{"role":"Mitglied","group":"Members"}] }';
    $cfg->setAppValue(APP, 'settings', $original);
    (new RoleNameMigration($db, $cfg))->run();
    check($cfg->getAppValue(APP, 'settings') === $original, 'unaffected settings bytes remain unchanged');
    $db = new SqlConnection(); $cfg = new MigrationConfig($db);
    (new RoleNameMigration($db, $cfg))->run();
    check($cfg->getAppValue(APP, 'settings') === '' && $cfg->getAppValue(APP, 'role_names_decoded') === '1', 'fresh install creates no operator configuration');
    echo "\n$count transactional role migration checks passed. No live API.\n";
}
