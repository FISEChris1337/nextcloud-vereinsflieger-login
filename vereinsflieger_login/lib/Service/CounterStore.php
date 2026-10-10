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

final class CounterStore
{
    private const TABLE = '*PREFIX*vf_request_limits';
    private const PAUSES = '*PREFIX*vf_login_pauses';
    public function __construct(private IDBConnection $db)
    {
    }
    public function value(string $key, int $now): int
    {
        $result = $this->db->executeQuery('SELECT countervalue FROM ' . self::TABLE . ' WHERE identifier = ? AND expiresat > ?', [$key, $now]);
        try {
            return (int)($result->fetchOne() ?: 0);
        } finally {
            $result->closeCursor();
        }
    }
    public function remainingSeconds(string $key, int $now): int
    {
        $result = $this->db->executeQuery('SELECT expiresat FROM ' . self::TABLE . ' WHERE identifier = ? AND expiresat > ?', [$key, $now]);
        try {
            return max(0, (int)($result->fetchOne() ?: 0) - $now);
        } finally {
            $result->closeCursor();
        }
    }
    public function consume(string $key, int $amount, int $limit, int $now, int $expires, bool $extend = false): bool
    {
        if ($amount < 1 || $amount > $limit || $expires <= $now) {
            return false;
        }
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            if ($this->db->executeStatement('UPDATE ' . self::TABLE . ' SET countervalue = ?, expiresat = ? WHERE identifier = ? AND expiresat <= ?', [$amount, $expires, $key, $now]) === 1) {
                return true;
            }
            $sql = 'UPDATE ' . self::TABLE . ' SET countervalue = countervalue + ?';
            $params = [$amount];
            if ($extend) {
                $sql .= ', expiresat = ?';
                $params[] = $expires;
            }
            $sql .= ' WHERE identifier = ? AND expiresat > ? AND countervalue <= ?';
            if ($this->db->executeStatement($sql, array_merge($params, [$key, $now, $limit - $amount])) === 1) {
                return true;
            }
            $result = $this->db->executeQuery('SELECT identifier FROM ' . self::TABLE . ' WHERE identifier = ?', [$key]);
            try {
                $exists = $result->fetchOne() !== false;
            } finally {
                $result->closeCursor();
            }
            if ($exists) {
                return false;
            }
            try {
                $this->db->executeStatement('INSERT INTO ' . self::TABLE . ' (identifier, countervalue, expiresat) VALUES (?, ?, ?)', [$key, $amount, $expires]);
                return true;
            } catch (UniqueConstraintViolationException) { /* Retry racing first creation. */
            } catch (Exception $e) {
                if (!in_array($e->getReason(), [Exception::REASON_CONSTRAINT_VIOLATION, Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION], true)) {
                    throw $e;
                }
            }
        }
        return false;
    }
    public function refund(string $key, int $amount): void
    {
        if ($amount > 0) {
            $this->db->executeStatement('UPDATE ' . self::TABLE . ' SET countervalue = countervalue - ? WHERE identifier = ? AND countervalue >= ?', [$amount, $key, $amount]);
        }
    }
    public function clear(string $key): void
    {
        $this->db->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE identifier = ?', [$key]);
    }
    public function prune(int $now): void
    {
        $this->db->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE expiresat <= ?', [$now]);
        $this->db->executeStatement('DELETE FROM ' . self::PAUSES . ' WHERE expiresat <= ?', [$now]);
    }
    public function rememberPause(string $key, string $scope, string $username, string $ip, string $kind, array $resetKeys, int $expires): void
    {
        $ip = inet_pton($ip) === false ? $ip : inet_ntop(inet_pton($ip));
        $values = [$scope, mb_strcut(mb_strtolower(trim($username), 'UTF-8'), 0, 254, 'UTF-8'), substr($ip, 0, 45), $kind, json_encode($resetKeys, JSON_THROW_ON_ERROR), $expires];
        $this->db->executeStatement('UPDATE ' . self::PAUSES . ' SET appscope = ?, username = ?, ipaddress = ?, kind = ?, resetkeys = ?, expiresat = ? WHERE identifier = ?', [...$values, $key]);
        try {
            $this->db->executeStatement('INSERT INTO ' . self::PAUSES . ' (appscope, username, ipaddress, kind, resetkeys, expiresat, identifier) VALUES (?, ?, ?, ?, ?, ?, ?)', [...$values, $key]);
        } catch (UniqueConstraintViolationException) {
            $this->db->executeStatement('UPDATE ' . self::PAUSES . ' SET appscope = ?, username = ?, ipaddress = ?, kind = ?, resetkeys = ?, expiresat = ? WHERE identifier = ?', [...$values, $key]);
        } catch (Exception $e) {
            if (!in_array($e->getReason(), [Exception::REASON_CONSTRAINT_VIOLATION, Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION], true)) {
                throw $e;
            }
            $this->db->executeStatement('UPDATE ' . self::PAUSES . ' SET appscope = ?, username = ?, ipaddress = ?, kind = ?, resetkeys = ?, expiresat = ? WHERE identifier = ?', [...$values, $key]);
        }
    }
    public function pauses(string $scope, int $now): array
    {
        $result = $this->db->executeQuery('SELECT identifier, username, ipaddress, kind, expiresat FROM ' . self::PAUSES . ' WHERE appscope = ? AND expiresat > ? ORDER BY expiresat', [$scope, $now]);
        $rows = [];
        try {
            while (($row = $result->fetchAssociative()) !== false) {
                $seconds = $this->remainingSeconds($row['identifier'], $now);
                if ($seconds > 0) {
                    $rows[] = ['id' => $row['identifier'], 'username' => $row['username'], 'ip' => $row['ipaddress'], 'kind' => $row['kind'], 'seconds' => $seconds, 'expiresAt' => $now + $seconds];
                }
            }
        } finally {
            $result->closeCursor();
        }
        return $rows;
    }
    public function releasePause(string $scope, string $identifier): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $identifier)) {
            return false;
        }
        $result = $this->db->executeQuery('SELECT resetkeys, kind, ipaddress FROM ' . self::PAUSES . ' WHERE appscope = ? AND identifier = ?', [$scope, $identifier]);
        try {
            $row = $result->fetchAssociative();
        } finally {
            $result->closeCursor();
        }
        if ($row === false) {
            return false;
        }
        $records = [$identifier => $row['resetkeys']];
        if (in_array($row['kind'], ['ip_failures', 'ip_attempts'], true)) {
            $result = $this->db->executeQuery('SELECT identifier, resetkeys FROM ' . self::PAUSES . ' WHERE appscope = ? AND ipaddress = ?', [$scope, $row['ipaddress']]);
            try {
                while (($related = $result->fetchAssociative()) !== false) {
                    $records[$related['identifier']] = $related['resetkeys'];
                }
            } finally {
                $result->closeCursor();
            }
        }
        $keys = [];
        foreach ($records as $raw) {
            $reset = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($reset) || !array_is_list($reset) || count($reset) > 3) {
                throw new \RuntimeException('Invalid login pause record');
            }
            $keys = array_merge($keys, $reset);
        }
        foreach ($keys as $key) {
            if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) {
                throw new \RuntimeException('Invalid login pause counter');
            }
        }
        foreach ($keys as $key) {
            $this->clear($key);
        }
        foreach (array_keys($records) as $id) {
            $this->db->executeStatement('DELETE FROM ' . self::PAUSES . ' WHERE appscope = ? AND identifier = ?', [$scope, $id]);
        }
        return true;
    }
    public function pausePage(string $scope, int $now, int $page, string $search): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('p.identifier', 'p.username', 'p.ipaddress', 'p.kind', 'c.expiresat')
            ->from('vf_login_pauses', 'p')
            ->innerJoin('p', 'vf_request_limits', 'c', $query->expr()->eq('p.identifier', 'c.identifier'))
            ->where($query->expr()->eq('p.appscope', $query->createNamedParameter($scope)))
            ->andWhere($query->expr()->gt('p.expiresat', $query->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->gt('c.expiresat', $query->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('p.expiresat')->addOrderBy('p.identifier');
        AdminLists::search($query, ['p.username', 'p.ipaddress'], $search, $this->db);
        $query->setFirstResult(($page - 1) * AdminLists::PAGE_SIZE)->setMaxResults(AdminLists::PAGE_SIZE + 1);
        $result = $query->executeQuery();
        try {
            $rows = $result->fetchAllAssociative();
        } finally {
            $result->closeCursor();
        }
        $items = [];
        foreach (array_slice($rows, 0, AdminLists::PAGE_SIZE) as $row) {
            $items[] = ['id' => (string)$row['identifier'], 'username' => (string)$row['username'], 'ip' => (string)$row['ipaddress'], 'kind' => (string)$row['kind'], 'seconds' => (int)$row['expiresat'] - $now, 'expiresAt' => (int)$row['expiresat']];
        }
        return ['items' => $items, 'page' => $page, 'hasMore' => count($rows) > AdminLists::PAGE_SIZE];
    }
}
