<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\Exception;
use OCP\IDBConnection;

final class KnownRoles
{
    private const TABLE = '*PREFIX*vf_known_roles';
    public function __construct(private IDBConnection $db)
    {
    }
    public function all(string $cid): array
    {
        if ($cid === '') {
            return [];
        }
        $result = $this->db->executeQuery('SELECT rolename FROM ' . self::TABLE . ' WHERE cid = ? ORDER BY rolename', [$cid]);
        $roles = [];
        try {
            while (($role = $result->fetchOne()) !== false) {
                $roles[] = (string)$role;
            }
        } finally {
            $result->closeCursor();
        }
        return $roles;
    }
    public function remember(string $cid, array $roles): void
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $cid)) {
            throw new \InvalidArgumentException('Invalid club');
        }
        foreach ($roles as $role) {
            try {
                RoleName::validate($role);
            } catch (ApiException $e) {
                throw new \InvalidArgumentException('Invalid role', 0, $e);
            }
        }
        foreach (array_unique($roles) as $role) {
            $id = hash('sha256', json_encode([$cid, $role], JSON_THROW_ON_ERROR));
            $result = $this->db->executeQuery('SELECT identifier FROM ' . self::TABLE . ' WHERE identifier = ?', [$id]);
            try {
                $exists = $result->fetchOne() !== false;
            } finally {
                $result->closeCursor();
            }
            if ($exists) {
                continue;
            }
            try {
                $this->db->executeStatement('INSERT INTO ' . self::TABLE . ' (identifier, cid, rolename) VALUES (?, ?, ?)', [$id, $cid, $role]);
            } catch (UniqueConstraintViolationException) { /* Another successful login stored the same role. */
            } catch (Exception $e) {
                if (!in_array($e->getReason(), [Exception::REASON_CONSTRAINT_VIOLATION, Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION], true)) {
                    throw $e;
                }
            }
        }
    }
}
