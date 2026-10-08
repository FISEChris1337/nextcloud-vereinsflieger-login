<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
$root = dirname(__DIR__);
function execute(array $command): void {
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) { exit(1); }
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/vereinsflieger_login', FilesystemIterator::SKIP_DOTS));
$syntaxCount = 0;
foreach ($files as $file) {
    if ($file->getExtension() === 'php') { execute([PHP_BINARY, '-l', $file->getPathname()]); ++$syntaxCount; }
}
if (!extension_loaded('mbstring') || !extension_loaded('pdo_sqlite')) { fwrite(STDERR, "Test extensions mbstring and pdo_sqlite are required.\n"); exit(1); }
require $root . '/tests/run.php';
if (is_file($root . '/tests/session.php')) { execute([PHP_BINARY, $root . '/tests/session.php']); }
execute([PHP_BINARY, $root . '/tests/native-guard.php']);
execute([PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite', $root . '/tests/limits.php']);
execute([PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite', $root . '/tests/roles.php']);
execute([PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite', $root . '/tests/role-migration.php']);
echo "\n$syntaxCount app PHP files checked.\n";
