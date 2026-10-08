<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class Section implements IIconSection
{
    public function __construct(private IURLGenerator $urls, private \OCP\IL10N $l10n)
    {
    }
    public function getID(): string
    {
        return 'vereinsflieger_login';
    }
    public function getName(): string
    {
        return $this->l10n->t('Vereinsflieger Login');
    }
    public function getPriority(): int
    {
        return 80;
    }
    public function getIcon(): string
    {
        return $this->urls->imagePath('vereinsflieger_login', 'app.svg');
    }
}
