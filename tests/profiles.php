<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
use OCA\VereinsfliegerLogin\Service\Identity;

$profileConfig = new MemoryConfig();
$profileUser = new User('existing-member');
$profileUser->name = 'Old name';
$profileUser->email = 'local@example.invalid';
$profileConfig->user['existing-member']['lang'] = 'de_DE';
$profiles = new \OCA\VereinsfliegerLogin\Service\ProfileSynchronizer($profileConfig);
$profiles->apply($profileUser, Identity::fromResponse(['uid' => 42, 'roles' => [], 'firstname' => 'Änne', 'lastname' => 'Pilot', 'email' => 'changed@example.invalid']));
check($profileUser->name === 'Änne Pilot' && $profileConfig->user['existing-member']['firstname'] === 'Änne' && $profileConfig->user['existing-member']['lastname'] === 'Pilot', 'verified VF names update an existing linked account');
check($profileUser->getUID() === 'existing-member' && $profileUser->email === 'local@example.invalid' && $profileConfig->user['existing-member']['lang'] === 'de_DE', 'profile sync preserves account ID email and language preference');
$profiles->apply($profileUser, new Identity('42', [], null, 'Partial', null));
check($profileUser->name === 'Änne Pilot', 'incomplete provider names do not erase an existing profile');
$profiles->apply($profileUser, new Identity('42', [], null, str_repeat('Ä', 80), 'Pilot'));
check(mb_strlen($profileUser->name, 'UTF-8') === 64 && mb_check_encoding($profileUser->name, 'UTF-8'), 'display name respects native character limit without splitting Unicode');
