<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000400Date20261008000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('vf_login_pauses')) {
            $table = $schema->createTable('vf_login_pauses');
            $table->addColumn('identifier', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('appscope', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('username', 'string', ['notnull' => true, 'length' => 254]);
            $table->addColumn('ipaddress', 'string', ['notnull' => true, 'length' => 45]);
            $table->addColumn('kind', 'string', ['notnull' => true, 'length' => 16]);
            $table->addColumn('resetkeys', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('expiresat', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['identifier']);
            $table->addIndex(['appscope', 'expiresat'], 'vf_pause_scope');
        }
        return $schema;
    }
}
