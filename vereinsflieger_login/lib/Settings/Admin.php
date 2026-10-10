<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Settings;

use OCA\VereinsfliegerLogin\Service\Configuration;
use OCA\VereinsfliegerLogin\Service\RequestGuard;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\{IGroupManager, IURLGenerator, IUserManager};
use OCP\Settings\ISettings;

final class Admin implements ISettings
{
    public function __construct(private Configuration $settings, private IURLGenerator $urls, private IGroupManager $groups, private RequestGuard $guard, private IUserManager $users)
    {
    }
    public function getForm(): TemplateResponse
    {
        $groups = [];
        foreach ($this->groups->search('', 500) as $group) {
            if (strcasecmp($group->getGID(), 'admin') !== 0) {
                $groups[] = $group->getGID();
            }
        }
        $usage = $this->settings->hasKey() ? $this->guard->usage($this->settings->key()) : ['dateUtc' => gmdate('Y-m-d'), 'used' => 0, 'providerPaused' => false, 'hourUtc' => gmdate('Y-m-d H:00'), 'hourUsed' => 0, 'providerPauseRemaining' => 0];
        $settings = $this->settings->publicData(false);
        $accounts = [];
        $missingGroups = [];
        foreach ($settings['roleMap'] as $entry) {
            if (!$this->groups->get($entry['group'])) {
                $missingGroups[] = $entry['group'];
            } elseif (strcasecmp($entry['group'], 'admin') !== 0 && !in_array($entry['group'], $groups, true)) {
                $groups[] = $entry['group'];
            }
        }
        $missingGroups = array_values(array_unique($missingGroups));
        foreach ($this->users->search('', 1000) as $user) {
            $accounts[$user->getUID()] = $user->getDisplayName();
        }
        foreach ($settings['excludedAccounts'] as $uid) {
            $accounts[$uid] ??= $uid;
        }
        ksort($accounts);
        return new TemplateResponse('vereinsflieger_login', 'admin', ['settings' => $settings, 'groups' => $groups, 'missingGroups' => $missingGroups, 'usage' => $usage, 'accounts' => $accounts,
            'localLoginUrl' => $this->urls->linkToRoute('core.login.showLoginForm', ['direct' => '1']),
            'saveUrl' => $this->urls->linkToRoute('vereinsflieger_login.admin.save'), 'checkUrl' => $this->urls->linkToRoute('vereinsflieger_login.admin.check'),
            'listUrl' => $this->urls->linkToRoute('vereinsflieger_login.admin.listPage'),
            'pauseUrl' => $this->urls->linkToRoute('vereinsflieger_login.admin.unpause')]);
    }
    public function getSection(): string
    {
        return 'vereinsflieger_login';
    }
    public function getPriority(): int
    {
        return 50;
    }
}
