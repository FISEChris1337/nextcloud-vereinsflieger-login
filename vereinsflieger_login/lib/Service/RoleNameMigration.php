<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\IConfig;
use OCP\IDBConnection;

final class RoleNameMigration
{
    private const APP = 'vereinsflieger_login';
    public function __construct(private IDBConnection $db, private IConfig $config)
    {
    }
    public function run(): void
    {
        if ($this->config->getAppValue(self::APP, 'role_names_decoded', '') === '1') {
            return;
        }
        // Validate the complete migration before changing any catalog or mapping.
        $result = $this->db->executeQuery('SELECT identifier, cid, rolename FROM *PREFIX*vf_known_roles');
        $roles = [];
        try {
            while (($row = $result->fetchAssociative()) !== false) {
                $name = RoleName::fromProvider($row['rolename']);
                if ($name !== $row['rolename']) {
                    $roles[] = ['identifier' => $row['identifier'], 'cid' => $row['cid'], 'name' => $name];
                }
            }
        } finally {
            $result->closeCursor();
        }
        $original = $this->config->getAppValue(self::APP, 'settings', '');
        $settings = $original === '' ? [] : json_decode($original, true, 32, JSON_THROW_ON_ERROR);
        $mapping = [];
        $groups = [];
        foreach ($settings['roleMap'] ?? [] as $entry) {
            $entry['role'] = RoleName::fromProvider($entry['role']);
            if (isset($groups[$entry['role']])) {
                if ($groups[$entry['role']] !== $entry['group']) {
                    throw new \RuntimeException('Decoded role names have conflicting group mappings. Review the mappings before updating.');
                }
                continue;
            }
            $groups[$entry['role']] = $entry['group'];
            $mapping[] = $entry;
        }
        if (array_key_exists('roleMap', $settings)) {
            $settings['roleMap'] = $mapping;
        }
        $updated = $original === '' ? '' : json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $result = $this->db->executeQuery('SELECT userid, configvalue FROM *PREFIX*preferences WHERE appid = ? AND configkey = ?', [self::APP, 'snapshot']);
        $snapshots = [];
        try {
            while (($row = $result->fetchAssociative()) !== false) {
                $snapshot = json_decode($row['configvalue'], true, 32, JSON_THROW_ON_ERROR);
                $normalized = Identity::fromResponse(['uid' => $snapshot['vfUid'], 'roles' => $snapshot['roles']])->roles;
                if ($normalized !== $snapshot['roles']) {
                    $snapshot['roles'] = $normalized;
                    $snapshots[$row['userid']] = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
            }
        } finally {
            $result->closeCursor();
        }
        $this->db->beginTransaction();
        try {
            foreach ($roles as $role) {
                $this->db->executeStatement('DELETE FROM *PREFIX*vf_known_roles WHERE identifier = ?', [$role['identifier']]);
            }
            $catalog = new KnownRoles($this->db);
            foreach ($roles as $role) {
                $catalog->remember($role['cid'], [$role['name']]);
            }
            // Preserve the original settings bytes unless a role actually changed.
            if ($original !== '' && $settings !== json_decode($original, true, 32, JSON_THROW_ON_ERROR)) {
                $this->config->setAppValue(self::APP, 'settings', $updated);
            }
            foreach ($snapshots as $uid => $value) {
                $this->config->setUserValue($uid, self::APP, 'snapshot', $value);
            }
            // The marker makes retries safe even for names containing literal entities.
            $this->config->setAppValue(self::APP, 'role_names_decoded', '1');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
