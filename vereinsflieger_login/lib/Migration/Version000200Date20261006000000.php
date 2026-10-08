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

final class Version000200Date20261006000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('vf_request_limits')) {
            $table = $schema->createTable('vf_request_limits');
            $table->addColumn('identifier', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('countervalue', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('expiresat', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['identifier']);
            $table->addIndex(['expiresat'], 'vf_limits_expiry');
        }
        if (!$schema->hasTable('vf_known_roles')) {
            $table = $schema->createTable('vf_known_roles');
            $table->addColumn('identifier', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('cid', 'string', ['notnull' => true, 'length' => 10]);
            $table->addColumn('rolename', 'string', ['notnull' => true, 'length' => 200]);
            $table->setPrimaryKey(['identifier']);
            $table->addIndex(['cid'], 'vf_roles_cid');
        }
        return $schema;
    }
}
