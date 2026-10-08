<?php

/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\VereinsfliegerLogin\Service;

final class RequestPermit
{
    private int $used = 0;
    private bool $closed = false;
    public function __construct(private CounterStore $store, private array $budgetKeys)
    {
    }
    public function beforeRequest(): void
    {
        if ($this->closed || $this->used >= 4) {
            throw new \LogicException('API request outside reservation');
        }
        ++$this->used;
    }
    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            foreach ($this->budgetKeys as $key) {
                $this->store->refund($key, 4 - $this->used);
            }
        }
    }
}
