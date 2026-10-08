<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\{IGroupManager, IUser, IUserManager};

final class AccountResolver
{
    public function __construct(private Configuration $settings, private AccountLinks $links, private IUserManager $users, private UserProvisioner $provisioner, private IGroupManager $groups)
    {
    }
    public function resolve(Identity $identity): IUser
    {
        $data = $this->settings->read();
        $saved = $this->links->get($data['cid'], $identity->uid);
        if ($saved !== null && $saved['blocked']) {
            throw new ApiException('local_only');
        }
        $uid = $this->settings->linkedUid($identity->uid);
        if ($uid !== null) {
            if ($this->settings->isExcluded($uid)) {
                throw new ApiException('local_only');
            }
            $user = $this->users->get($uid);
            if (!$user || !$user->isEnabled()) {
                throw new ApiException('credentials');
            }
            return $user;
        }
        if ($data['linkExistingByEmail'] && $identity->email !== null) {
            $matches = $this->users->getByEmail(strtolower($identity->email));
            if (count($matches) > 1) {
                throw new ApiException('not_linked');
            }
            if (count($matches) === 1) {
                $user = reset($matches);
                $uid = $user->getUID();
                if ($this->settings->isExcluded($uid)) {
                    throw new ApiException('local_only');
                }
                // Administrators always require an explicit trusted mapping.
                $admin = $this->groups->get('admin');
                if (!$user->isEnabled()) {
                    throw new ApiException('not_linked');
                }
                if ($admin !== null && $admin->inGroup($user)) {
                    throw new ApiException('admin_mapping');
                }
                foreach ($this->settings->activeLinks() as $link) {
                    if ($link['nextcloudUid'] === $uid && $link['vfUid'] !== $identity->uid) {
                        throw new ApiException('not_linked');
                    }
                }
                // Unique database constraints prevent racing identities from
                // claiming the same account. Future logins use this stable ID,
                // never the mutable provider email again.
                $this->links->claim($data['cid'], $identity->uid, $uid);
                return $user;
            }
        }
        return $this->provisioner->create($identity);
    }
}
