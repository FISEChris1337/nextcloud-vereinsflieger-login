<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\{IConfig, IUser, IUserManager};

final class UserProvisioner
{
    public function __construct(private Configuration $settings, private AccountLinks $links, private IUserManager $users, private IConfig $config, private \OCP\IL10N $l10n, private LocalAccountPassword $passwords)
    {
    }
    public function create(Identity $identity): IUser
    {
        $data = $this->settings->read();
        if (!$data['createAccounts']) {
            throw new ApiException('not_linked');
        }
        if ($identity->email === null || $identity->firstname === null || $identity->lastname === null) {
            throw new ApiException('missing_profile');
        }
        $uid = 'vf_' . $data['cid'] . '_' . $identity->uid;
        if ($this->settings->isExcluded($uid)) {
            throw new ApiException('local_only');
        }
        $user = $this->users->get($uid);
        if ($user !== null) {
            // Recover only an account demonstrably created by this app. Never
            // adopt a pre-existing account just because its ID looks generated.
            if ($this->config->getUserValue($uid, 'vereinsflieger_login', 'provisioned_cid') !== $data['cid'] ||
                $this->config->getUserValue($uid, 'vereinsflieger_login', 'provisioned_vf_uid') !== $identity->uid || !$user->isEnabled()) {
                throw new ApiException('not_linked');
            }
        } else {
            // A mutable provider email is never proof of ownership of old files.
            if ($this->users->getByEmail(strtolower($identity->email)) !== []) {
                throw new ApiException('not_linked');
            }
            // Resolve the guest's native language before user creation hooks run.
            $language = $this->l10n->getLanguageCode();
            $user = $this->users->createUser($uid, $this->passwords->generate());
            if (!$user || $user->getUID() !== $uid || !$user->isEnabled()) {
                throw new \RuntimeException('Account creation failed');
            }
            $this->config->setUserValue($uid, 'core', 'lang', $language);
        }
        $this->config->setUserValue($uid, 'vereinsflieger_login', 'provisioned_cid', $data['cid']);
        $this->config->setUserValue($uid, 'vereinsflieger_login', 'provisioned_vf_uid', $identity->uid);
        $this->config->setUserValue($uid, 'vereinsflieger_login', 'firstname', $identity->firstname);
        $this->config->setUserValue($uid, 'vereinsflieger_login', 'lastname', $identity->lastname);
        $name = mb_substr($identity->firstname . ' ' . $identity->lastname, 0, 64, 'UTF-8');
        if ($user->getDisplayName() !== $name && !$user->setDisplayName($name)) {
            throw new \RuntimeException('Account display name could not be set');
        }
        $user->setEMailAddress($identity->email);
        $this->links->claim($data['cid'], $identity->uid, $uid);
        return $user;
    }
}
