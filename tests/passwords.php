<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

use OCA\VereinsfliegerLogin\Service\LocalAccountPassword;
use OCP\Security\Events\GenerateSecurePasswordEvent;
use OCP\Security\Events\ValidatePasswordPolicyEvent;

$passwordEvents = new class implements \OCP\EventDispatcher\IEventDispatcher {
    public int $minLength = 0;
    public ?string $generated = null;
    public bool $generationFails = false;
    public array $calls = [];
    public function dispatchTyped(object $event): void {
        $this->calls[] = get_class($event);
        if ($event instanceof ValidatePasswordPolicyEvent && strlen($event->getPassword()) < $this->minLength) {
            throw new \OCP\HintException('Password is too short');
        }
        if ($event instanceof GenerateSecurePasswordEvent) {
            if ($this->generationFails) { throw new \RuntimeException('Policy generation failed'); }
            if ($this->generated !== null) { $event->setPassword($this->generated); }
        }
    }
};
$policyPasswords = new LocalAccountPassword($passwordEvents);
$firstPassword = $policyPasswords->generate();
check(strlen($firstPassword) === 64 && $passwordEvents->calls === [ValidatePasswordPolicyEvent::class], 'strong default is validated against native account policy');
check($policyPasswords->generate() !== $firstPassword, 'local account passwords are independently randomized');
$passwordEvents->minLength = 128;
$passwordEvents->generated = 'Aa1!' . str_repeat('x', 124);
$passwordEvents->calls = [];
check($policyPasswords->generate() === $passwordEvents->generated && $passwordEvents->calls === [ValidatePasswordPolicyEvent::class, GenerateSecurePasswordEvent::class, ValidatePasswordPolicyEvent::class], 'stricter policy uses native generator and validates its result');
$passwordEvents->generated = 'too-short';
try { $policyPasswords->generate(); check(false, 'invalid policy-generated password cannot pass'); }
catch (\OCP\HintException) { check(true, 'invalid generator output cannot bypass password policy'); }
$passwordEvents->generated = null;
try { $policyPasswords->generate(); check(false, 'missing policy generator cannot pass'); }
catch (\RuntimeException) { check(true, 'missing generator cannot bypass an enforcing policy'); }
$passwordEvents->generationFails = true;
try { $policyPasswords->generate(); check(false, 'failing policy generator cannot pass'); }
catch (\RuntimeException) { check(true, 'generator failure cannot fall back to a rejected password'); }
$policyProvisioner = new \OCA\VereinsfliegerLogin\Service\UserProvisioner($emailCfg, $emailLinks, $emailUsers, $emailDb, $l, $policyPasswords);
$beforeUsers = count($emailUsers->items);
try { $policyProvisioner->create(\OCA\VereinsfliegerLogin\Service\Identity::fromResponse(['uid' => 95, 'roles' => [], 'firstname' => 'Policy', 'lastname' => 'Fixture', 'email' => 'policy@example.invalid'])); check(false, 'policy error cannot create an account'); }
catch (\RuntimeException) { check(count($emailUsers->items) === $beforeUsers && $emailLinks->get('123', '95') === null, 'policy generation failure creates neither user nor identity link'); }
