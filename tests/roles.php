<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
require __DIR__ . '/counter_support.php';
require __DIR__ . '/../vereinsflieger_login/lib/Service/ApiException.php';
require __DIR__ . '/../vereinsflieger_login/lib/Service/RoleName.php';
require __DIR__ . '/../vereinsflieger_login/lib/Service/KnownRoles.php';
use OCA\VereinsfliegerLogin\Service\KnownRoles;
function check(bool $valid, string $label): void { if (!$valid) { throw new RuntimeException('FAIL ' . $label); } echo 'PASS ' . $label . "\n"; }
$db = new SqlConnection(); $catalog = new KnownRoles($db);
check($catalog->all('123') === [], 'catalog initially empty');
$catalog->remember('123', ['Mitglied', 'Technisches Personal', 'Mitglied']);
$catalog->remember('123', ['Technisches Personal']);
check($catalog->all('123') === ['Mitglied', 'Technisches Personal'], 'repeated logins accumulate unique exact names');
$catalog->remember('123', []);
check(count($catalog->all('123')) === 2, 'empty current response does not erase known choices');
$catalog->remember('456', ['Vorstand']);
check($catalog->all('456') === ['Vorstand'] && !in_array('Vorstand', $catalog->all('123'), true), 'catalog isolated by club');
unset($catalog); $catalog = new KnownRoles($db);
check(count($catalog->all('123')) === 2, 'catalog survives service recreation');
try { $catalog->remember('123', ['Accepted?', '']); throw new LogicException('Expected invalid role'); }
catch (InvalidArgumentException) { check(count($catalog->all('123')) === 2, 'invalid response rejected before storing partial roles'); }
echo "\n6 isolated role storage checks passed. No live API.\n";
