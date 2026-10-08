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

final class AccountLinks
{
    private const TABLE = '*PREFIX*vf_account_links';
    public function __construct(private IDBConnection $db)
    {
    }
    private function identifier(string $cid, string $vfUid): string
    {
        return hash('sha256', json_encode([$cid, $vfUid], JSON_THROW_ON_ERROR));
    }
    private function entry(array $row): array
    {
        return ['vfUid' => (string)$row['vfuid'], 'nextcloudUid' => (string)$row['ncuid'], 'blocked' => (bool)$row['blocked'], 'createdAt' => gmdate('c', (int)$row['createdat'])];
    }
    public function get(string $cid, string $vfUid): ?array
    {
        $result = $this->db->executeQuery('SELECT vfuid, ncuid, blocked, createdat FROM ' . self::TABLE . ' WHERE identifier = ?', [$this->identifier($cid, $vfUid)]);
        try {
            $row = $result->fetchAssociative();
            return $row === false ? null : $this->entry($row);
        } finally {
            $result->closeCursor();
        }
    }
    public function all(string $cid): array
    {
        if ($cid === '') {
            return [];
        }
        $result = $this->db->executeQuery('SELECT vfuid, ncuid, blocked, createdat FROM ' . self::TABLE . ' WHERE cid = ? ORDER BY ncuid', [$cid]);
        $links = [];
        try {
            while (($row = $result->fetchAssociative()) !== false) {
                $links[] = $this->entry($row);
            }
        } finally {
            $result->closeCursor();
        }
        return $links;
    }
    public function claim(string $cid, string $vfUid, string $ncUid): void
    {
        try {
            $this->db->executeStatement('INSERT INTO ' . self::TABLE . ' (identifier, cid, vfuid, ncuid, blocked, createdat) VALUES (?, ?, ?, ?, ?, ?)', [$this->identifier($cid, $vfUid), $cid, $vfUid, $ncUid, 0, time()]);
            return;
        } catch (UniqueConstraintViolationException) { /* Resolve racing claims below. */
        } catch (Exception $e) {
            if (!in_array($e->getReason(), [Exception::REASON_CONSTRAINT_VIOLATION, Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION], true)) {
                throw $e;
            }
        }
        $existing = $this->get($cid, $vfUid);
        if (!$existing || $existing['blocked'] || $existing['nextcloudUid'] !== $ncUid) {
            throw new ApiException('credentials');
        }
    }
    public function block(string $cid, string $vfUid): void
    {
        $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET blocked = ? WHERE identifier = ?', [1, $this->identifier($cid, $vfUid)]);
    }
    public function forgetDeletedAccount(string $uid): void
    {
        $this->db->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE ncuid = ?', [$uid]);
    }
}
