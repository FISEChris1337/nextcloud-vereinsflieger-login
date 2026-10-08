<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCP { interface IDBConnection { public function executeStatement($sql, array $params = [], array $types = []): int; public function executeQuery(string $sql, array $params = [], $types = []): object; } }
namespace OCP\DB {
    class Exception extends \RuntimeException { public const REASON_CONSTRAINT_VIOLATION = 2; public const REASON_UNIQUE_CONSTRAINT_VIOLATION = 14; public function getReason(): int { return self::REASON_UNIQUE_CONSTRAINT_VIOLATION; } }
}
namespace OCP\AppFramework\Utility { interface ITimeFactory { public function getTime(): int; } }
namespace {
    final class SqlConnection implements \OCP\IDBConnection {
        public \PDO $pdo;
        public function __construct(string $file = ':memory:') {
            $this->pdo = new \PDO('sqlite:' . $file);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA busy_timeout = 10000');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS oc_vf_request_limits (identifier TEXT PRIMARY KEY, countervalue INTEGER NOT NULL DEFAULT 0, expiresat INTEGER NOT NULL)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS oc_vf_login_pauses (identifier TEXT PRIMARY KEY, appscope TEXT NOT NULL, username TEXT NOT NULL, ipaddress TEXT NOT NULL, kind TEXT NOT NULL, resetkeys TEXT NOT NULL, expiresat INTEGER NOT NULL)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS oc_vf_known_roles (identifier TEXT PRIMARY KEY, cid TEXT NOT NULL, rolename TEXT NOT NULL)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS oc_vf_account_links (identifier TEXT PRIMARY KEY, cid TEXT NOT NULL, vfuid TEXT NOT NULL, ncuid TEXT NOT NULL, blocked INTEGER NOT NULL DEFAULT 0, createdat INTEGER NOT NULL, UNIQUE(cid, ncuid))');
        }
        public function executeStatement($sql, array $params = [], array $types = []): int {
            try { $stmt = $this->pdo->prepare(str_replace('*PREFIX*', 'oc_', $sql)); $stmt->execute($params); return $stmt->rowCount(); }
            catch (\PDOException $e) { if ($e->getCode() === '23000') { throw new \OCP\DB\Exception('constraint'); } throw $e; }
        }
        public function beginTransaction(): void { $this->pdo->beginTransaction(); }
        public function commit(): void { $this->pdo->commit(); }
        public function rollBack(): void { $this->pdo->rollBack(); }
        public function executeQuery(string $sql, array $params = [], $types = []): object {
            $stmt = $this->pdo->prepare(str_replace('*PREFIX*', 'oc_', $sql)); $stmt->execute($params);
            return new class($stmt) {
                public function __construct(private \PDOStatement $stmt) {}
                public function fetchOne(): mixed { return $this->stmt->fetchColumn(); }
                public function fetchAssociative(): array|false { return $this->stmt->fetch(\PDO::FETCH_ASSOC); }
                public function closeCursor(): void { $this->stmt->closeCursor(); }
            };
        }
    }
    final class Clock implements \OCP\AppFramework\Utility\ITimeFactory {
        public function __construct(public int $now = 1791288000) {}
        public function getTime(): int { return $this->now; }
    }
}
