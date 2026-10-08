<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Compare exact, separately downloaded upstream tags. This is source verification,
// not a running Nextcloud test; refuse changes that need a new manual review.
$cache = $argv[1] ?? '';
if (!is_dir($cache)) { fwrite(STDERR, "Usage: php tools/compatibility.php <source-cache-with-v34.0.4-and-v35.0.1>\n"); exit(1); }
function method(string $source, string $name): string {
    $tokens = token_get_all($source); $ignore = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    for ($i = 0; $i < count($tokens); ++$i) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $next = $i + 1;
        while (is_array($tokens[$next]) && in_array($tokens[$next][0], $ignore, true)) { ++$next; }
        if (!is_array($tokens[$next]) || $tokens[$next][1] !== $name) { continue; }
        $text = ''; $depth = 0; $body = false;
        for ($j = $i; $j < count($tokens); ++$j) {
            $token = $tokens[$j];
            if (is_array($token) && in_array($token[0], $ignore, true)) { continue; }
            $text .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { ++$depth; $body = true; }
            if ($token === '}' && --$depth === 0) { return $text; }
            if (!$body && $token === ';') { return $text; }
        }
    }
    throw new RuntimeException('Missing upstream method ' . $name);
}
$methods = [
    'lib/private/User/Session.php' => ['completeLogin', 'createSessionToken', 'createRememberMeToken', 'loginWithCookie', 'setMagicInCookie', 'logout'],
    'lib/private/Authentication/TwoFactorAuth/Manager.php' => ['isTwoFactorAuthenticated', 'prepareTwoFactorLogin', 'getProviderSet', 'getLoginSetupProviders', 'verifyChallenge'],
    'lib/private/Authentication/TwoFactorAuth/MandatoryTwoFactor.php' => ['isEnforcedFor'],
    'lib/public/User/Events/BeforeUserLoggedInEvent.php' => ['__construct'],
    'lib/public/User/Events/UserLoggedInEvent.php' => ['__construct'],
    'lib/public/User/Events/PostLoginEvent.php' => ['__construct'],
    'lib/public/IDBConnection.php' => ['executeQuery', 'executeStatement'],
    'lib/public/Migration/SimpleMigrationStep.php' => ['changeSchema'],
    'lib/public/AppFramework/Bootstrap/IRegistrationContext.php' => ['registerAlternativeLogin'],
    'lib/public/Authentication/IAlternativeLogin.php' => ['getLabel', 'getLink', 'getClass'],
    'lib/public/Settings/ISettings.php' => ['getForm', 'getSection', 'getPriority'],
];
$checks = []; $sources = []; $reviewed33 = ['lib/private/User/Session.php::completeLogin', 'lib/private/User/Session.php::setMagicInCookie', 'lib/private/Authentication/TwoFactorAuth/Manager.php::verifyChallenge'];
foreach ($methods as $path => $names) {
    $source = [];
    foreach (['v33.0.9', 'v34.0.4', 'v35.0.1'] as $tag) {
        $source[$tag] = file_get_contents($cache . '/' . $tag . '/' . $path);
        $sources[$tag . '/' . $path] = ['url' => 'https://github.com/nextcloud/server/blob/' . $tag . '/' . $path, 'sha256' => hash('sha256', $source[$tag])];
    }
    foreach ($names as $name) {
        $a = method($source['v34.0.4'], $name); $b = method($source['v35.0.1'], $name);
        $c = method($source['v33.0.9'], $name);
        if ($c !== $a && !in_array($path . '::' . $name, $reviewed33, true)) { throw new RuntimeException('NC33 method changed; review required: ' . $path . '::' . $name); }
        if ($a !== $b) { throw new RuntimeException('Upstream method changed; review required: ' . $path . '::' . $name); }
        $checks[] = ['method' => $path . '::' . $name, 'unchanged34to35IgnoringCommentsAndFormatting' => true, 'unchanged33to34' => $c === $a, 'sha256' => hash('sha256', $b), 'sha256nc33' => hash('sha256', $c)];
    }
}
$table = file_get_contents($cache . '/v35.0.1/lib/public/DB/Schema/ITable.php');
foreach (['addColumn', 'setPrimaryKey', 'addIndex', 'addUniqueIndex'] as $name) { method($table, $name); }
$sources['v35.0.1/lib/public/DB/Schema/ITable.php'] = ['url' => 'https://github.com/nextcloud/server/blob/v35.0.1/lib/public/DB/Schema/ITable.php', 'sha256' => hash('sha256', $table)];
echo json_encode(['date' => '2026-10-07', 'versions' => ['33.0.9', '34.0.4', '35.0.1'], 'runtimeTested' => false,
    'unchangedMethodCount' => count($checks), 'checks' => $checks, 'sources' => $sources,
    'manualReview' => ['completeLogin dispatches legacy PostLoginEvent requiring string: pass empty string there, but null to createSessionToken for passwordless token. NC33 additionally initializes the first-login filesystem; NC34 removes that block.',
        'NC33 setMagicInCookie uses fully qualified CookieHelper and OC server request access; NC34 uses imports and Server::get. Same cookie security and lifetime semantics.',
        'NC33 verifyChallenge obtains the native user session via OC server; NC34 obtains it via Server::get. Both create remember tokens only after a passed challenge.',
        'NC35 schema returns ITable: addColumn(string type), primary key and index calls remain supported.',
        'Security middleware uses ControllerMethodReflector; PublicPage, NoAdminRequired and CSRF rules retained.',
        'Remember cookie setting defaults to 15 days; NC35 minimum PHP 8.3 matches app minimum.']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
