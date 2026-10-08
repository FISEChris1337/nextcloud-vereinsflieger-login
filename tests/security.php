<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCA\VereinsfliegerLogin\Service\Identity;
use OCA\VereinsfliegerLogin\Service\ReturnTarget;

foreach (["Role\u{0085}Injected", "Role\0Injected", "\xFF"] as $role) {
    rejects(fn() => Identity::fromResponse(['uid' => 42, 'roles' => [$role]]), 'provider role rejects invalid UTF-8 and control characters', 'invalid_roles');
}
check(Identity::fromResponse(['uid' => 42, 'roles' => ['Schatzmeister&#47;Kassenwart', 'Schatzmeister/Kassenwart', 'Pr&uuml;fer &amp; Vorstand', 'Hilfe&#x2f;Technik']])->roles === ['Schatzmeister/Kassenwart', 'Prüfer & Vorstand', 'Hilfe/Technik'], 'provider role entities decoded once and duplicate decoded names merged');
check(Identity::fromResponse(['uid' => 42, 'roles' => ['Prüfer / Technik – Süd', 'Custom role: "A" + B']])->roles === ['Prüfer / Technik – Süd', 'Custom role: "A" + B'], 'custom role names preserve Unicode, punctuation, case and spaces');
$nestedRoles = Identity::fromResponse(['uid' => 42, 'roles' => ['Literal &amp;#47;', 'Literal &amp;amp;']])->roles;
check($nestedRoles === ['Literal &#47;', 'Literal &amp;'] && Identity::fromSession(['uid' => 42, 'roles' => $nestedRoles])->roles === $nestedRoles, 'native second-factor session preserves already decoded roles without another decode');
foreach (['Role&#10;Injected', 'Role&#x9;Injected', 'Role&NewLine;Injected', "Role\rInjected", str_repeat('x', 201)] as $role) {
    rejects(fn() => Identity::fromResponse(['uid' => 42, 'roles' => [$role]]), 'encoded controls and excessive role names rejected before storage or group sync', 'invalid_roles');
}
$decodedPayload = Identity::fromResponse(['uid' => 42, 'roles' => ['&lt;img src=x onerror=&quot;window.injected=true&quot;&gt;']])->roles[0];
ob_start(); p($decodedPayload); $escapedRole = ob_get_clean();
check(str_contains($escapedRole, '&lt;img') && !str_contains($escapedRole, '<img'), 'decoded provider HTML remains escaped role text');
$invalidName = Identity::fromResponse(['uid' => 42, 'roles' => [], 'firstname' => "Name\u{0085}Injected", 'lastname' => 'Member']);
check($invalidName->firstname === null, 'provider names reject Unicode control characters');
foreach (['/%0d%0aLocation:%20https://example.invalid', '/%5cexample.invalid', '/%2fexample.invalid', '//example.invalid', 'javascript:alert(1)'] as $target) {
    check((new ReturnTarget())->safe($target) === null, 'encoded redirect injection rejected');
}
$payload = '"><script>window.injected=true</script><img src=x onerror=alert(1)>';
$_['message'] = $payload;
$l = new TestL10N('de_DE');
ob_start(); require __DIR__ . '/../vereinsflieger_login/templates/login.php'; $escaped = ob_get_clean();
check(!str_contains($escaped, '<script>window.injected') && str_contains($escaped, '&lt;script&gt;'), 'login error payload is rendered as escaped text');
$roleJson = json_encode(['roles' => [$payload]], JSON_THROW_ON_ERROR);
ob_start(); p($roleJson); $escapedState = ob_get_clean();
check(!str_contains($escapedState, '<script>') && !str_contains($escapedState, '"><'), 'JSON embedded in template attributes is HTML escaped');
