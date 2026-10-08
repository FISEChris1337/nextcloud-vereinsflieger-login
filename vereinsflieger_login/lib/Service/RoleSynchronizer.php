<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;

final class RoleSynchronizer
{
    public function __construct(private Configuration $settings, private IConfig $config, private IGroupManager $groups, private KnownRoles $knownRoles)
    {
    }
    public function apply(IUser $user, Identity $identity): void
    {
        $data = $this->settings->read();
        if ($this->settings->linkedUid($identity->uid) !== $user->getUID() || $this->settings->isExcluded($user->getUID())) {
            throw new \RuntimeException('Account link changed or account is excluded from SSO.');
        }
        if ($data['syncRoles']) {
            $this->synchronizeGroups($user, $identity, $data);
        }
        $this->knownRoles->remember($data['cid'], $identity->roles);
        $snapshot = ['vfUid' => $identity->uid, 'cid' => $data['cid'], 'roles' => $identity->roles, 'capturedAt' => gmdate('c')];
        $this->config->setUserValue($user->getUID(), 'vereinsflieger_login', 'snapshot', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    private function synchronizeGroups(IUser $user, Identity $identity, array $data): void
    {
        $wanted = [];
        foreach ($data['roleMap'] as $entry) {
            if (strcasecmp($entry['group'], 'admin') !== 0 && in_array($entry['role'], $identity->roles, true)) {
                $wanted[] = $entry['group'];
            }
        }
        $wanted = array_values(array_unique($wanted));
        $resolved = [];
        $missing = [];
        foreach ($wanted as $gid) {
            $group = $this->groups->get($gid);
            if (!$group) {
                $missing[] = $gid;
                continue;
            }
            $resolved[$gid] = $group;
        }
        if ($missing !== []) {
            // Preserve every membership and ownership record for this login.
            // Provider authentication and role capture may still complete.
            $warning = ['vfUid' => $identity->uid, 'cid' => $data['cid'], 'groups' => $missing, 'capturedAt' => gmdate('c')];
            $this->config->setUserValue($user->getUID(), 'vereinsflieger_login', 'group_sync_warning', json_encode($warning, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return;
        }
        $owned = json_decode($this->config->getUserValue($user->getUID(), 'vereinsflieger_login', 'owned_groups', '[]'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($owned) || !array_is_list($owned)) {
            throw new \RuntimeException('Invalid group history.');
        }
        foreach ($resolved as $gid => $group) {
            $gid = (string)$gid;
            if (!$group->inGroup($user)) {
                // Record ownership before the mutation, so a partial failure remains recoverable.
                if (!in_array($gid, $owned, true)) {
                    $owned[] = $gid;
                    $this->storeOwned($user, $owned);
                }
                if ($group->addUser($user) === false) {
                    throw new \RuntimeException('Target group could not be updated.');
                }
            }
        }
        foreach ($owned as $gid) {
            if (!is_string($gid) || strcasecmp($gid, 'admin') === 0 || in_array($gid, $wanted, true)) {
                continue;
            }
            if ($this->groups->get($gid)?->removeUser($user) === false) {
                throw new \RuntimeException('Target group could not be updated.');
            }
        }
        $this->storeOwned($user, array_values(array_intersect($owned, $wanted)));
        $this->config->setUserValue($user->getUID(), 'vereinsflieger_login', 'group_sync_warning', '');
    }
    private function storeOwned(IUser $user, array $groups): void
    {
        $this->config->setUserValue($user->getUID(), 'vereinsflieger_login', 'owned_groups', json_encode($groups, JSON_THROW_ON_ERROR));
    }
}
