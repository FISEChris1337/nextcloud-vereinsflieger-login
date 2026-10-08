<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Migration;

use Closure;
use OCA\VereinsfliegerLogin\Service\RoleNameMigration;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000500Date20261008010000 extends SimpleMigrationStep
{
    public function __construct(private IDBConnection $db, private IConfig $config)
    {
    }
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        (new RoleNameMigration($this->db, $this->config))->run();
    }
}
