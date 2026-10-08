<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\{IOutput, SimpleMigrationStep};

final class Version000300Date20261007000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('vf_account_links')) {
            $table = $schema->createTable('vf_account_links');
            $table->addColumn('identifier', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('cid', 'string', ['notnull' => true, 'length' => 10]);
            $table->addColumn('vfuid', 'string', ['notnull' => true, 'length' => 16]);
            $table->addColumn('ncuid', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('blocked', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('createdat', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['identifier']);
            $table->addUniqueIndex(['cid', 'ncuid'], 'vf_link_user');
        }
        return $schema;
    }
}
