<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCA\VereinsfliegerLogin\Service\ValidationException;

$german = new TestL10N('de_DE');
$english = new TestL10N('en');
$error = new ValidationException('Check the role mappings.', [], [
    new ValidationException('Target group “%s” is missing.', ['<group>']),
    new ValidationException('Role “%s” has not been verified for this club.', ['Pilot']),
]);
check(str_contains($error->translated($german), 'Zielgruppe „<group>“ fehlt.') && str_contains($error->translated($german), 'Rolle „Pilot“'), 'validation errors translate complete messages with parameters');
check(str_contains($error->translated($english), 'Target group “<group>” is missing.') && !str_contains($error->translated($english), 'Zielgruppe'), 'English validation does not contain German fragments');
$_['enabled'] = true;
$_ += ['canRemember' => true, 'action' => '/submit', 'requesttoken' => 'test-token', 'redirectUrl' => ''];
$l = $english;
ob_start(); require __DIR__ . '/../vereinsflieger_login/templates/login.php'; $englishLogin = ob_get_clean();
check(str_contains($englishLogin, 'Log in with Vereinsflieger') && str_contains($englishLogin, 'Account name or email') && !str_contains($englishLogin, 'Angemeldet bleiben'), 'login template follows the supplied English language');
$l = $german;
ob_start(); require __DIR__ . '/../vereinsflieger_login/templates/login.php'; $germanLogin = ob_get_clean();
check(str_contains($germanLogin, 'Anmelden mit Vereinsflieger') && str_contains($germanLogin, 'Kontoname oder E-Mail') && str_contains($germanLogin, 'Angemeldet bleiben'), 'login template translates all visible login controls into German');
check(!str_contains($germanLogin, 'lost-password') && !str_contains($germanLogin, 'href="/lostpassword"'), 'provider form never offers a local password-reset link');
