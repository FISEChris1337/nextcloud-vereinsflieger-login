<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class AdminLists
{
    public const PAGE_SIZE = 25;

    public function __construct(private IDBConnection $db, private Configuration $settings, private RequestGuard $guard)
    {
    }

    public function page(string $kind, int $page = 1, string $search = ''): array
    {
        if (!in_array($kind, ['identities', 'snapshots', 'warnings', 'pauses'], true) || $page < 1 || $page > 100000 || strlen($search) > 128 || !mb_check_encoding($search, 'UTF-8') || preg_match('/[\p{C}]/u', $search)) {
            throw new ValidationException('Invalid list request.');
        }
        $data = $this->settings->read();
        $query = $this->db->getQueryBuilder();
        if ($kind === 'pauses') {
            if (!$this->settings->hasKey()) {
                return ['items' => [], 'page' => $page, 'hasMore' => false];
            }
            return $this->guard->pausePage($this->settings->key(), $page, trim($search));
        }
        if ($data['cid'] === '') {
            return ['items' => [], 'page' => $page, 'hasMore' => false];
        }
        if ($kind === 'identities') {
            $query->select('vfuid', 'ncuid', 'blocked')->from('vf_account_links')
                ->where($query->expr()->eq('cid', $query->createNamedParameter($data['cid'])))
                ->orderBy('ncuid')->addOrderBy('identifier');
            $columns = ['ncuid', 'vfuid'];
        } else {
            // Select linked accounts in SQL, rather than loading every account's preferences.
            $query->select('p.userid', 'p.configvalue')->from('preferences', 'p')
                ->leftJoin('p', 'vf_account_links', 'a', $query->expr()->andX(
                    $query->expr()->eq('a.ncuid', 'p.userid'),
                    $query->expr()->eq('a.cid', $query->createNamedParameter($data['cid'])),
                    $query->expr()->eq('a.blocked', $query->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
                ))
                ->where($query->expr()->eq('p.appid', $query->createNamedParameter('vereinsflieger_login')))
                ->andWhere($query->expr()->eq('p.configkey', $query->createNamedParameter($kind === 'snapshots' ? 'snapshot' : 'group_sync_warning')));
            $linked = $query->expr()->isNotNull('a.identifier');
            $manual = array_column($data['links'], 'nextcloudUid');
            if ($manual !== []) {
                $linked = $query->expr()->orX($linked, $query->expr()->in('p.userid', $query->createNamedParameter($manual, IQueryBuilder::PARAM_STR_ARRAY)));
            }
            $query->andWhere($linked)->orderBy('p.userid');
            $columns = ['p.userid'];
        }
        if ($kind === 'snapshots' && trim($search) !== '') {
            return $this->snapshotSearchPage($query, $data['cid'], $page, trim($search));
        }
        self::search($query, $columns, trim($search), $this->db);
        $query->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE + 1);
        $result = $query->executeQuery();
        try {
            $rows = $result->fetchAllAssociative();
        } finally {
            $result->closeCursor();
        }
        $hasMore = count($rows) > self::PAGE_SIZE;
        $items = [];
        foreach (array_slice($rows, 0, self::PAGE_SIZE) as $row) {
            if ($kind === 'identities') {
                $items[] = ['uid' => (string)$row['ncuid'], 'vfUid' => (string)$row['vfuid'], 'blocked' => (bool)$row['blocked']];
                continue;
            }
            $item = $this->snapshotItem($row, $data['cid'], $kind === 'snapshots' ? 'roles' : 'groups');
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return ['items' => $items, 'page' => $page, 'hasMore' => $hasMore];
    }

    private function snapshotSearchPage(IQueryBuilder $query, string $cid, int $page, string $search): array
    {
        // Match decoded role names, including Unicode in legacy escaped JSON.
        // Read bounded database batches; paginate matching accounts, not raw rows.
        $skip = ($page - 1) * self::PAGE_SIZE;
        $items = [];
        $offset = 0;
        do {
            $query->setFirstResult($offset)->setMaxResults(100);
            $result = $query->executeQuery();
            try {
                $rows = $result->fetchAllAssociative();
            } finally {
                $result->closeCursor();
            }
            foreach ($rows as $row) {
                $item = $this->snapshotItem($row, $cid, 'roles');
                if ($item === null || !self::matchesSnapshot($item, $search)) {
                    continue;
                }
                if ($skip > 0) {
                    --$skip;
                    continue;
                }
                $items[] = $item;
                if (count($items) > self::PAGE_SIZE) {
                    return ['items' => array_slice($items, 0, self::PAGE_SIZE), 'page' => $page, 'hasMore' => true];
                }
            }
            $offset += count($rows);
        } while (count($rows) === 100);
        return ['items' => $items, 'page' => $page, 'hasMore' => false];
    }

    private static function matchesSnapshot(array $item, string $search): bool
    {
        foreach ([$item['uid'], ...$item['names']] as $value) {
            if (mb_stripos($value, $search, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    private function snapshotItem(array $row, string $cid, string $field): ?array
    {
        $value = json_decode($row['configvalue'], true);
        $uid = (string)$row['userid'];
        if (!is_array($value) || ($value['cid'] ?? '') !== $cid || !is_string($value['vfUid'] ?? null) || $this->settings->linkedUid($value['vfUid']) !== $uid || !is_string($value['capturedAt'] ?? null)) {
            return null;
        }
        $names = $value[$field] ?? null;
        if (!is_array($names) || !array_is_list($names) || array_filter($names, static fn ($name): bool => !is_string($name)) !== []) {
            return null;
        }
        // Return only display data, never other stored preferences or credentials.
        return ['uid' => $uid, 'capturedAt' => $value['capturedAt'], 'names' => $names];
    }

    public static function search(IQueryBuilder $query, array $columns, string $search, IDBConnection $db): void
    {
        if ($search === '') {
            return;
        }
        $conditions = [];
        foreach ($columns as $column) {
            $conditions[] = $query->expr()->iLike($column, $query->createNamedParameter('%' . $db->escapeLikeParameter($search) . '%'));
        }
        $query->andWhere($query->expr()->orX(...$conditions));
    }
}
