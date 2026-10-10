<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Uses the isolated fakes from run.php and SQLite batches; not a native OCP test.
namespace OCP\DB\QueryBuilder { interface IQueryBuilder {} }
namespace {
    use OCA\VereinsfliegerLogin\Service\{AccountLinks, AdminLists, Configuration, CounterStore, KnownRoles, RequestGuard};
    require __DIR__ . '/../vereinsflieger_login/lib/Service/AdminLists.php';
    final class SnapshotBatchQuery implements \OCP\DB\QueryBuilder\IQueryBuilder {
        private int $offset = 0;
        private int $limit = 0;
        public int $largestBatch = 0;
        public function __construct(private \PDO $pdo) {}
        public function setFirstResult(int $offset): self { $this->offset = $offset; return $this; }
        public function setMaxResults(int $limit): self { $this->limit = $limit; return $this; }
        public function executeQuery(): object {
            $statement = $this->pdo->query('SELECT userid, configvalue FROM search_snapshots ORDER BY userid LIMIT ' . $this->limit . ' OFFSET ' . $this->offset);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
            $this->largestBatch = max($this->largestBatch, count($rows));
            return new class($rows) {
                public function __construct(private array $rows) {}
                public function fetchAllAssociative(): array { return $this->rows; }
                public function closeCursor(): void {}
            };
        }
    }
    $searchDb = new SqlConnection();
    $searchDb->pdo->exec('CREATE TABLE search_snapshots (userid TEXT PRIMARY KEY, configvalue TEXT NOT NULL)');
    $searchConfig = new MemoryConfig();
    $searchConfig->app['settings'] = json_encode(['cid' => 'search-club']);
    $searchSettings = new Configuration($searchConfig, new Crypto(), new Users(), new Groups(), new KnownRoles($searchDb), new AccountLinks($searchDb));
    $searchLists = new AdminLists($searchDb, $searchSettings, new RequestGuard(new CounterStore($searchDb), $searchConfig, new Clock()));
    for ($i = 0; $i < 261; ++$i) {
        $uid = 'search_member_' . sprintf('%03d', $i);
        $vfUid = (string) (900000 + $i);
        (new AccountLinks($searchDb))->claim('search-club', $vfUid, $uid);
        $roles = $i % 4 === 0 ? ['Technisches Personal', 'Technischer Dienst'] : ['Mitglied'];
        if ($i === 259) { $roles[] = "Ärztin & 50%_ 'Vorstand'"; }
        $snapshot = ['cid' => 'search-club', 'vfUid' => $vfUid, 'capturedAt' => '2026-10-10T06:10:05+00:00', 'roles' => $roles];
        $searchDb->pdo->prepare('INSERT INTO search_snapshots VALUES (?, ?)')->execute([$uid, json_encode($snapshot)]);
    }
    $query = new SnapshotBatchQuery($searchDb->pdo);
    $searchPage = new \ReflectionMethod(AdminLists::class, 'snapshotSearchPage');
    $page = static fn(int $number, string $term): array => $searchPage->invoke($searchLists, $query, 'search-club', $number, $term);
    $one = $page(1, 'TECHNISCH'); $two = $page(2, 'technisch'); $three = $page(3, 'Technisch');
    check(count($one['items']) === 25 && $one['hasMore'] && count($two['items']) === 25 && $two['hasMore'] && count($three['items']) === 16 && !$three['hasMore'], 'role search paginates matching accounts across database batches');
    $matches = [...$one['items'], ...$two['items'], ...$three['items']];
    check(count(array_unique(array_column($matches, 'uid'))) === 66 && $matches[65]['uid'] === 'search_member_260', 'multiple matching roles do not duplicate accounts or drop late matches');
    check(count($page(1, 'search_member_259')['items']) === 1, 'role search also finds the existing account ID');
    check($page(1, 'ärztin')['items'][0]['uid'] === 'search_member_259', 'legacy escaped JSON role names match Unicode without case sensitivity');
    check(count($page(1, "50%_ '")['items']) === 1 && $page(1, "%_not-present'")['items'] === [], 'role search treats percent underscore and quotes as literal text');
    check($page(1, '2026-10-10')['items'] === [] && $page(1, 'search-club')['items'] === [], 'role search ignores snapshot timestamps and other metadata');
    check($page(4, 'Technisch')['items'] === [] && !$page(4, 'Technisch')['hasMore'], 'role search beyond last matching page is empty');
    check($query->largestBatch <= 100, 'role search bounds each database read');
    $searchDb->pdo->exec("UPDATE search_snapshots SET configvalue = '{invalid-json' WHERE userid = 'search_member_259'");
    check($page(1, 'ärztin')['items'] === [], 'invalid snapshots are omitted during role search');
}
