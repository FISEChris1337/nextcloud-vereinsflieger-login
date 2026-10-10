<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ICrypto;

final class Configuration
{
    public function __construct(private IConfig $config, private ICrypto $crypto, private IUserManager $users, private IGroupManager $groups, private KnownRoles $knownRoles, private AccountLinks $accountLinks)
    {
    }
    public function read(): array
    {
        $raw = $this->config->getAppValue('vereinsflieger_login', 'settings', '{}');
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid stored configuration.');
        }
        return $data + ['enabled' => false, 'cid' => '', 'syncRoles' => false, 'links' => [], 'roleMap' => [], 'dailyApiBudget' => 400, 'defaultLogin' => false, 'createAccounts' => false, 'excludedAccounts' => [], 'showLocalLoginLink' => true, 'linkExistingByEmail' => false, 'rememberMeDefault' => true] + LoginProtection::DEFAULTS;
    }
    public function isEnabled(): bool
    {
        try {
            return $this->read()['enabled'] === true && $this->hasKey();
        } catch (\Throwable) {
            return false;
        }
    }
    public function hasKey(): bool
    {
        return $this->config->getAppValue('vereinsflieger_login', 'appkey_encrypted', '') !== '';
    }
    public function key(): string
    {
        $value = $this->config->getAppValue('vereinsflieger_login', 'appkey_encrypted', '');
        if ($value === '') {
            throw new \RuntimeException('AppKey missing.');
        }
        return $this->crypto->decrypt($value);
    }
    public function publicData(bool $includeLists = true): array
    {
        $data = $this->read();
        $data['knownRoles'] = $this->knownRoles->all($data['cid']);
        $data['provisionedLinks'] = $includeLists ? $this->accountLinks->all($data['cid']) : [];
        $data['hasKey'] = $this->hasKey();
        $data['snapshots'] = [];
        $data['syncWarnings'] = [];
        if (!$includeLists) {
            return $data;
        }
        foreach ($this->activeLinks() as $link) {
            $warning = json_decode($this->config->getUserValue($link['nextcloudUid'], 'vereinsflieger_login', 'group_sync_warning', ''), true);
            if (is_array($warning) && ($warning['vfUid'] ?? '') === $link['vfUid'] && ($warning['cid'] ?? '') === $data['cid'] && is_array($warning['groups'] ?? null) && is_string($warning['capturedAt'] ?? null)) {
                $data['syncWarnings'][$link['nextcloudUid']] = $warning;
            }
            $raw = $this->config->getUserValue($link['nextcloudUid'], 'vereinsflieger_login', 'snapshot', '');
            if ($raw !== '') {
                $snapshot = json_decode($raw, true);
                if (is_array($snapshot) && ($snapshot['vfUid'] ?? '') === $link['vfUid'] && ($snapshot['cid'] ?? '') === $data['cid']) {
                    $data['snapshots'][$link['nextcloudUid']] = $snapshot;
                }
            }
        }
        return $data;
    }
    public function save(array $input): void
    {
        $cid = trim((string)($input['cid'] ?? ''));
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $cid)) {
            throw new ValidationException('Enter a valid club ID.');
        }
        $links = $input['links'] ?? [];
        $roleMap = $input['roleMap'] ?? [];
        if (!is_array($links) || !array_is_list($links) || count($links) > 1000 || !is_array($roleMap) || !array_is_list($roleMap) || count($roleMap) > 100) {
            throw new ValidationException('Invalid mapping list.');
        }
        $seenVf = [];
        $seenNc = [];
        $cleanLinks = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                throw new ValidationException('Invalid account link.');
            }
            $vf = trim((string)($link['vfUid'] ?? ''));
            $nc = trim((string)($link['nextcloudUid'] ?? ''));
            $user = $this->users->get($nc);
            if (!preg_match('/^[1-9][0-9]{0,15}$/D', $vf) || !$user || !$user->isEnabled() || isset($seenVf[$vf]) || isset($seenNc[$user->getUID()])) {
                throw new ValidationException('Each Vereinsflieger ID and active Nextcloud account may only be linked once.');
            }
            $seenVf[$vf] = true;
            $seenNc[$user->getUID()] = true;
            $cleanLinks[] = ['vfUid' => $vf, 'nextcloudUid' => $user->getUID()];
        }
        $current = $this->read();
        $known = $this->knownRoles->all($cid);
        $excluded = $input['excludedAccounts'] ?? $current['excludedAccounts'];
        if (!is_array($excluded) || !array_is_list($excluded) || count($excluded) > 1000) {
            throw new ValidationException('Invalid list of local accounts.');
        }
        $cleanExcluded = [];
        foreach ($excluded as $uid) {
            if (!is_string($uid) || !($user = $this->users->get($uid))) {
                throw new ValidationException('A local-only account was not found.');
            }
            $cleanExcluded[] = $user->getUID();
        }
        $cleanExcluded = array_values(array_unique($cleanExcluded));
        foreach ($this->accountLinks->all($cid) as $saved) {
            if ($saved['blocked']) {
                continue;
            }
            foreach ($cleanLinks as $link) {
                if (($link['vfUid'] === $saved['vfUid'] && $link['nextcloudUid'] !== $saved['nextcloudUid']) || ($link['nextcloudUid'] === $saved['nextcloudUid'] && $link['vfUid'] !== $saved['vfUid'])) {
                    throw new ValidationException('This identity is already linked and cannot be assigned to another account. Select the account under “Local login only” to block VF login.');
                }
            }
        }
        $cleanMap = [];
        $seenRoles = [];
        foreach ($roleMap as $entry) {
            if (!is_array($entry)) {
                throw new ValidationException('Invalid role mapping.');
            }
            $role = (string)($entry['role'] ?? '');
            $group = trim((string)($entry['group'] ?? ''));
            if ($role === '' || strlen($role) > 200 || isset($seenRoles[$role]) || strcasecmp($group, 'admin') === 0 || !$this->groups->get($group)) {
                throw new ValidationException('Roles must be unique and refer to existing groups. The admin group is excluded.');
            }
            // Preserve unchanged old mappings when upgrading from 0.1.0. New or
            // changed mappings must use an exact role observed from this club.
            $legacy = $current['cid'] === $cid && in_array(['role' => $role, 'group' => $group], $current['roleMap'], true);
            if (!in_array($role, $known, true) && !$legacy) {
                throw new ValidationException('Choose a role already received from Vereinsflieger.');
            }
            $seenRoles[$role] = true;
            $cleanMap[] = ['role' => $role, 'group' => $group];
        }
        $enabled = ($input['enabled'] ?? false) === true;
        $sync = ($input['syncRoles'] ?? false) === true;
        $key = trim((string)($input['appkey'] ?? ''));
        if (strlen($key) > 512 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new ValidationException('Invalid AppKey.');
        }
        $defaultLogin = ($input['defaultLogin'] ?? $current['defaultLogin']) === true;
        $createAccounts = ($input['createAccounts'] ?? $current['createAccounts']) === true;
        $showLocalLoginLink = ($input['showLocalLoginLink'] ?? $current['showLocalLoginLink']) === true;
        $rememberMeDefault = ($input['rememberMeDefault'] ?? $current['rememberMeDefault']) === true;
        $linkExistingByEmail = ($input['linkExistingByEmail'] ?? $current['linkExistingByEmail']) === true;
        $hasProvisionedLinks = array_filter($this->accountLinks->all($cid), static fn (array $link): bool => !$link['blocked']) !== [];
        if ($enabled && ((!$this->hasKey() && $key === '') || ($cleanLinks === [] && !$hasProvisionedLinks && !$createAccounts && !$linkExistingByEmail))) {
            throw new ValidationException('Before enabling login, save an AppKey and an account link, or enable account creation or email linking.');
        }
        if ($current['cid'] !== '' && $current['cid'] !== $cid && ($this->activeLinks() !== [] || $cleanLinks !== [])) {
            throw new ValidationException('Before changing clubs, disable login, remove all account links and save.');
        }
        $budget = filter_var($input['dailyApiBudget'] ?? $current['dailyApiBudget'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 4, 'max_range' => 500]]);
        if ($budget === false) {
            throw new ValidationException('The daily budget must be between 4 and 500 API calls.');
        }
        $data = ['enabled' => $enabled, 'cid' => $cid, 'syncRoles' => $sync, 'links' => $cleanLinks, 'roleMap' => $cleanMap, 'dailyApiBudget' => $budget, 'defaultLogin' => $defaultLogin, 'createAccounts' => $createAccounts, 'excludedAccounts' => $cleanExcluded, 'showLocalLoginLink' => $showLocalLoginLink, 'linkExistingByEmail' => $linkExistingByEmail, 'rememberMeDefault' => $rememberMeDefault] + LoginProtection::validate($input, $current);
        // Validate everything before storing a new key. Never return it through the UI.
        if ($key !== '') {
            $this->config->setAppValue('vereinsflieger_login', 'appkey_encrypted', $this->crypto->encrypt($key));
        }
        $this->config->setAppValue('vereinsflieger_login', 'settings', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    public function explicitUid(string $vfUid): ?string
    {
        foreach ($this->read()['links'] as $link) {
            if ($link['vfUid'] === $vfUid) {
                return $link['nextcloudUid'];
            }
        }
        return null;
    }
    public function linkedUid(string $vfUid): ?string
    {
        $link = $this->accountLinks->get($this->read()['cid'], $vfUid);
        if ($link !== null && $link['blocked']) {
            return null;
        }
        $explicit = $this->explicitUid($vfUid);
        if ($explicit !== null) {
            return $explicit;
        }
        return $link === null || $link['blocked'] ? null : $link['nextcloudUid'];
    }
    public function activeLinks(): array
    {
        $data = $this->read();
        $links = $data['links'];
        $seen = array_column($links, 'vfUid');
        foreach ($this->accountLinks->all($data['cid']) as $link) {
            if (!$link['blocked'] && !in_array($link['vfUid'], $seen, true)) {
                $links[] = ['vfUid' => $link['vfUid'], 'nextcloudUid' => $link['nextcloudUid']];
            }
        }
        return $links;
    }
    public function isExcluded(string $uid): bool
    {
        return in_array($uid, $this->read()['excludedAccounts'], true);
    }
    public function isSsoAccount(string $uid): bool
    {
        if ($this->isExcluded($uid)) {
            return false;
        }
        $data = $this->read();
        foreach ($data['links'] as $link) {
            if ($link['nextcloudUid'] === $uid) {
                return true;
            }
        }
        // Keep created accounts protected even when their provider link is blocked.
        foreach ($this->accountLinks->all($data['cid']) as $link) {
            if ($link['nextcloudUid'] === $uid) {
                return true;
            }
        }
        return false;
    }
    public function forgetDeletedAccount(string $uid): void
    {
        $data = $this->read();
        $links = array_values(array_filter($data['links'], static fn (array $link): bool => $link['nextcloudUid'] !== $uid));
        $excluded = array_values(array_filter($data['excludedAccounts'], static fn (string $account): bool => $account !== $uid));
        if ($links === $data['links'] && $excluded === $data['excludedAccounts']) {
            return;
        }
        $data['links'] = $links;
        $data['excludedAccounts'] = $excluded;
        $this->config->setAppValue('vereinsflieger_login', 'settings', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    public function checkLocally(): void
    {
        $data = $this->read();
        LoginProtection::validate($data);
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $data['cid']) || $this->key() === '' || ($this->activeLinks() === [] && !$data['createAccounts'] && !$data['linkExistingByEmail'])) {
            throw new ValidationException('Save the club ID, AppKey and an account link, or permission to create or link accounts by email first.');
        }
        foreach ($this->activeLinks() as $link) {
            $user = $this->users->get($link['nextcloudUid']);
            if (!$user || !$user->isEnabled()) {
                throw new ValidationException('A linked Nextcloud account is missing or disabled.');
            }
        }
        $mappingErrors = [];
        $known = $this->knownRoles->all($data['cid']);
        foreach ($data['roleMap'] as $entry) {
            if (!$this->groups->get($entry['group'])) {
                $mappingErrors[] = new ValidationException('Target group “%s” is missing.', [$entry['group']]);
            }
            if (strcasecmp($entry['group'], 'admin') === 0) {
                $mappingErrors[] = new ValidationException('The admin group is excluded from role mapping.');
            }
            if (!in_array($entry['role'], $known, true)) {
                $mappingErrors[] = new ValidationException('Role “%s” has not been verified for this club.', [$entry['role']]);
            }
        }
        if ($mappingErrors !== []) {
            throw new ValidationException('Check the role mappings.', [], $mappingErrors);
        }
    }
}
