<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
return ['routes' => [
    ['name' => 'login#form', 'url' => '/login', 'verb' => 'GET'],
    ['name' => 'login#submit', 'url' => '/login', 'verb' => 'POST'],
    ['name' => 'login#finish', 'url' => '/finish', 'verb' => 'GET'],
    ['name' => 'admin#save', 'url' => '/admin/settings', 'verb' => 'POST'],
    ['name' => 'admin#check', 'url' => '/admin/check', 'verb' => 'POST'],
    ['name' => 'admin#unpause', 'url' => '/admin/unpause', 'verb' => 'POST'],
    ['name' => 'admin#listPage', 'url' => '/admin/list', 'verb' => 'POST'],
]];
