<?php
/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
\OCP\Util::addScript('vereinsflieger_login', 'admin');
\OCP\Util::addStyle('vereinsflieger_login', 'style');
?>
<div id="vf-admin" class="vf-admin" data-state="<?php p(json_encode($_, JSON_THROW_ON_ERROR)); ?>">
    <header class="vf-header">
        <div><span class="vf-eyebrow">VEREINSFLIEGER LOGIN</span><h2><?php p($l->t('Login & club roles')); ?></h2><small><?php p($l->t('Independent community app. Not an official Vereinsflieger product.')); ?></small><p><?php p($l->t('Connect existing accounts. Assign club roles to groups.')); ?></p></div>
        <span class="vf-badge"><?php p($_['settings']['enabled'] ? $l->t('Login enabled') : $l->t('Login disabled')); ?></span>
    </header>
    <nav class="vf-nav" aria-label="<?php p($l->t('Settings sections')); ?>"><a href="#vf-connection"><?php p($l->t('Connection')); ?></a><a href="#vf-protection"><?php p($l->t('Login protection')); ?></a><a href="#vf-accounts"><?php p($l->t('Accounts')); ?></a><a href="#vf-roles"><?php p($l->t('Roles')); ?></a><a href="#vf-maintenance"><?php p($l->t('Maintenance')); ?></a></nav>
    <form id="vf-settings-form">
        <section class="vf-card" id="vf-connection">
            <h3><?php p($l->t('Connection to Vereinsflieger')); ?></h3><p><?php p($l->t('Find your AppKey in Vereinsflieger under Stammdaten → Einstellungen → REST Interface.')); ?></p>
            <div class="vf-fields">
                <label><?php p($l->t('Club ID (CID)')); ?><input name="cid" inputmode="numeric" required pattern="[1-9][0-9]*" value="<?php p($_['settings']['cid']); ?>" placeholder="<?php p($l->t('Club ID')); ?>"></label>
                <label>AppKey<input type="password" name="appkey" autocomplete="new-password" maxlength="512" placeholder="<?php p($_['settings']['hasKey'] ? $l->t('Saved – leave empty to keep') : $l->t('Enter AppKey')); ?>"><small><?php p($l->t('Stored encrypted. The existing key is never displayed.')); ?></small></label>
            </div>
            <label class="vf-check"><input type="checkbox" name="enabled" <?php if ($_['settings']['enabled']) {
                print_unescaped('checked');
            } ?>><?php p($l->t('Offer Vereinsflieger login')); ?></label>
            <p class="vf-hint"><?php p($l->t('Login is initially disabled. Set up the admin page first. Local Nextcloud login remains available.')); ?></p>
            <label class="vf-check"><input type="checkbox" name="defaultLogin" <?php if ($_['settings']['defaultLogin']) {
                print_unescaped('checked');
            } ?>><?php p($l->t('Use Vereinsflieger as the default browser login')); ?></label>
            <p class="vf-hint"><?php p($l->t('Only applies when Vereinsflieger login is enabled. Local access:')); ?><a href="<?php p($_['localLoginUrl']); ?>"><?php p($_['localLoginUrl']); ?></a>.</p>
            <label class="vf-check"><input type="checkbox" name="rememberMeDefault" <?php if ($_['settings']['rememberMeDefault']) { ?>checked<?php } ?>><?php p($l->t('Select Remember me by default')); ?></label>
            <p class="vf-hint"><?php p($l->t('Users can select or deselect Remember me. This setting only controls the initial selection.')); ?></p>
            <label class="vf-check"><input type="checkbox" name="showLocalLoginLink" <?php if ($_['settings']['showLocalLoginLink']) {
                print_unescaped('checked');
            } ?>><?php p($l->t('Show the local Nextcloud login link in the Vereinsflieger form')); ?></label>
            <p class="vf-hint"><?php p($l->t('Hiding the link does not disable ?direct=1 for local and emergency accounts.')); ?></p>
            <button type="button" id="vf-check"><?php p($l->t('Check saved configuration')); ?></button><small class="vf-hint"><?php p($l->t('Checks saved settings, account links, known roles and target groups locally. No API calls. Save changes first. A real login confirms the AppKey and club ID with Vereinsflieger.')); ?></small>
            <p id="vf-check-result" class="vf-check-result" role="status" aria-live="polite" hidden></p>
            <div class="vf-usage">
                <strong><?php p($l->t('%s / %s API calls', [$_['usage']['used'], $_['settings']['dailyApiBudget']])); ?></strong>
                <p><?php p($l->t('%s calls left in this app’s budget.', [max(0, $_['settings']['dailyApiBudget'] - $_['usage']['used'])])); ?></p>
                <p><?php p($l->t('%s · This app’s counter (UTC). Up to four calls are reserved per ongoing login.', [$_['usage']['dateUtc']])); ?></p>
                <label><?php p($l->t('Daily API budget')); ?><input type="number" name="dailyApiBudget" min="4" max="500" required value="<?php p($_['settings']['dailyApiBudget']); ?>"></label>
                <?php if ($_['settings']['hourlyApiBudget'] > 0) { ?><p><?php p($l->t('%s / %s calls in the hourly budget · %s UTC', [$_['usage']['hourUsed'], $_['settings']['hourlyApiBudget'], $_['usage']['hourUtc']])); ?></p><?php } ?>
                <p><?php p($_['usage']['providerPaused'] ? $l->t('Vereinsflieger limited further attempts. Login is paused for about %s more minutes.', [max(1, (int)ceil($_['usage']['providerPauseRemaining'] / 60))]) : ($_['usage']['used'] + 4 > $_['settings']['dailyApiBudget'] ? $l->t('The daily budget cannot cover another login. New Vereinsflieger logins are paused.') : ($_['settings']['hourlyApiBudget'] > 0 && $_['usage']['hourUsed'] + 4 > $_['settings']['hourlyApiBudget'] ? $l->t('The hourly budget cannot cover another login. New logins are paused until the next UTC hour.') : $l->t('Budget available for further Vereinsflieger logins.')))); ?></p>
                <small><?php p($l->t('Other integrations are not counted. Existing sessions and local accounts remain usable at the limit; SSO accounts cannot use a local account password instead.')); ?></small>
            </div>
        </section>
        <section class="vf-card" id="vf-protection">
            <h3><?php p($l->t('Login protection & API budget')); ?></h3>
            <p><?php p($l->t('These limits prevent further API calls. Nextcloud’s own brute-force protection also remains active.')); ?></p>
            <div class="vf-fields">
                <label><?php p($l->t('Failed logins per IP')); ?><input type="number" name="loginIpFailureLimit" min="1" max="100" required value="<?php p($_['settings']['loginIpFailureLimit']); ?>"><small><?php p($l->t('Counts wrong credentials from this IP even when login names change.')); ?></small></label>
                <label><?php p($l->t('Failures before pausing')); ?><input type="number" name="loginFailureLimit" min="1" max="20" required value="<?php p($_['settings']['loginFailureLimit']); ?>"><small><?php p($l->t('Per username, across all IPs. Either username or IP can trigger a pause. Default: 3.')); ?></small></label>
                <label><?php p($l->t('Pause after failed logins (minutes)')); ?><input type="number" name="loginPauseMinutes" min="1" max="1440" required value="<?php p($_['settings']['loginPauseMinutes']); ?>"><small><?php p($l->t('Default: 15. Successful VF authentication resets username failures; IP failures expire after the pause.')); ?></small></label>
                <label><?php p($l->t('Attempt window (minutes)')); ?><input type="number" name="loginAttemptWindowMinutes" min="1" max="1440" required value="<?php p($_['settings']['loginAttemptWindowMinutes']); ?>"><small><?php p($l->t('Default: 15, starting with the first attempt.')); ?></small></label>
                <label><?php p($l->t('Maximum attempts per username and IP')); ?><input type="number" name="loginPairAttemptLimit" min="1" max="100" required value="<?php p($_['settings']['loginPairAttemptLimit']); ?>"><small><?php p($l->t('Successful logins and 2FA attempts also count. Default: 6.')); ?></small></label>
                <label><?php p($l->t('Maximum attempts per IP')); ?><input type="number" name="loginIpAttemptLimit" min="1" max="1000" required value="<?php p($_['settings']['loginIpAttemptLimit']); ?>"><small><?php p($l->t('Default: 30. Members sharing an internet connection share this limit.')); ?></small></label>
                <label><?php p($l->t('Pause after an API limit response (minutes)')); ?><input type="number" name="providerPauseMinutes" min="1" max="1440" required value="<?php p($_['settings']['providerPauseMinutes']); ?>"><small><?php p($l->t('After HTTP 429 from Vereinsflieger, per AppKey. Default: 15.')); ?></small></label>
                <label><?php p($l->t('Additional hourly API budget')); ?><input type="number" name="hourlyApiBudget" min="0" max="500" required value="<?php p($_['settings']['hourlyApiBudget']); ?>"><small><?php p($l->t('0 = disabled. Otherwise 4–500 calls per UTC hour. The daily budget also applies.')); ?></small></label>
            </div>
            <p class="vf-hint"><?php p($l->t('Four calls are reserved before login; unused calls are refunded. Empty or excessive inputs, control characters, invalid UTF-8 and unsupported password characters are rejected without an API call. Usernames and email addresses are accepted. No automatic reachability probes.')); ?></p>
            <p class="vf-hint"><?php p($l->t('Changes apply to new attempts. Existing pauses keep their expiry time. The check uses saved settings; save changes first.')); ?></p>
            <h4><?php p($l->t('Active login pauses')); ?></h4>
            <p class="vf-hint"><?php p($l->t('Username pauses apply across all IPs; attempt pauses apply to a username and IP. IP pauses apply to all login names from that address. Removing a pause does not reset API budgets, provider pauses or Nextcloud’s own brute-force protection.')); ?></p>
            <div id="vf-pauses" data-vf-list="pauses"></div>
        </section>
        <section class="vf-card" id="vf-accounts">
            <div class="vf-card-heading"><div><h3><?php p($l->t('Account links')); ?></h3><p><?php p($l->t('Explicitly link a Vereinsflieger ID to an existing Nextcloud account.')); ?></p></div><button type="button" id="vf-add-link"><?php p($l->t('+ Link account')); ?></button></div>
            <div class="vf-row-labels"><span><?php p($l->t('Vereinsflieger ID')); ?></span><span><?php p($l->t('Nextcloud account ID')); ?></span><span></span></div>
            <div id="vf-links"></div>
            <p class="vf-hint"><?php p($l->t('Use the internal account ID. The Vereinsflieger ID is the API uid, not the membership number. Files, account ID and admin rights are preserved. Verified first and last names are updated after a completed VF login.')); ?></p>
            <label class="vf-check"><input type="checkbox" name="linkExistingByEmail" <?php if ($_['settings']['linkExistingByEmail']) {
                print_unescaped('checked');
            } ?>><?php p($l->t('Link existing accounts by matching email on first Vereinsflieger login')); ?></label>
            <p class="vf-hint"><?php p($l->t('Disabled by default. Requires one active account with a unique matching email. Administrators and local-only accounts are excluded. The stable VF ID is saved; later email changes do not switch accounts.')); ?></p>
            <p class="vf-hint"><?php p($l->t('Members can change their Vereinsflieger email. While enabled, a matching VF email grants access to the existing account’s files. Enable temporarily for a controlled migration, then disable.')); ?></p>
            <p class="vf-hint"><?php p($l->t('Linked SSO accounts cannot log in with a local account password, even after a password reset. Existing sessions, remember cookies and Nextcloud app passwords follow Nextcloud’s rules. Explicitly excluded accounts remain local.')); ?></p>
            <label class="vf-check"><input type="checkbox" name="createAccounts" <?php if ($_['settings']['createAccounts']) {
                print_unescaped('checked');
            } ?>><?php p($l->t('Create new members on their first successful Vereinsflieger login')); ?></label>
            <p class="vf-hint"><?php p($l->t('Requires first name, last name and a valid email. Club ID and stable VF uid are saved. Existing email addresses require a manual link or explicitly enabled email migration.')); ?></p>
            <label><?php p($l->t('Local login only – exclude from Vereinsflieger')); ?><select name="excludedAccounts" multiple size="6" aria-describedby="vf-excluded-hint"><?php foreach ($_['accounts'] as $uid => $name) { ?><option value="<?php p($uid); ?>" <?php if (in_array((string)$uid, $_['settings']['excludedAccounts'], true)) {
                print_unescaped('selected');
            } ?>><?php p($name . ' (' . $uid . ')'); ?></option><?php } ?></select></label>
            <p class="vf-hint" id="vf-excluded-hint"><?php p($l->t('Select multiple accounts with Ctrl or ⌘. Include emergency administrators. Exclusion takes precedence over account links. Changes affect future logins; running sessions are not revoked.')); ?></p>
            <h4 id="vf-identities-heading"><?php p($l->t('Automatically saved identities')); ?></h4>
            <div id="vf-identities" data-vf-list="identities" aria-labelledby="vf-identities-heading"></div>
        </section>
        <section class="vf-card" id="vf-roles">
            <div class="vf-card-heading"><div><h3><?php p($l->t('Roles to Nextcloud groups')); ?></h3><p><?php p($l->t('Only explicitly mapped roles change group memberships.')); ?></p></div><button type="button" id="vf-add-role"><?php p($l->t('+ Map role')); ?></button></div>
            <label class="vf-check"><input type="checkbox" name="syncRoles" <?php if ($_['settings']['syncRoles']) {
                print_unescaped('checked');
            } ?>><?php p($l->t('Update groups after successful login')); ?></label>
            <div class="vf-row-labels"><span><?php p($l->t('Vereinsflieger role')); ?></span><span><?php p($l->t('Nextcloud group')); ?></span><span></span></div>
            <div id="vf-role-map"></div>
            <?php if ($_['missingGroups'] !== []) { ?><p class="vf-mapping-warning" role="alert"><?php p($l->t('Missing target groups: %s. Fix or remove these mappings. Login remains possible; all memberships are preserved when an affected sync is skipped.', [implode(', ', $_['missingGroups'])])); ?></p><?php } ?>
            <div id="vf-sync-warnings" data-vf-list="warnings"></div>
            <?php if ($_['settings']['knownRoles'] === []) { ?><p class="vf-empty"><?php p($l->t('No roles received yet. Log in with a linked account while group synchronization is disabled. Received roles then become available.')); ?></p><?php } ?>
            <p class="vf-hint"><?php p($l->t('The admin group is excluded. Existing local memberships are preserved; only memberships added by this app can be removed. Changes apply at the next VF login.')); ?></p>
            <p class="vf-hint"><?php p($l->t('Roles are saved per club after successful login. Mappings use only these exact names. A saved role grants no membership unless it is returned again at that user’s login.')); ?></p>
            <h4 id="vf-snapshots-heading"><?php p($l->t('Last verified roles')); ?></h4>
            <p class="vf-hint"><?php p($l->t("One latest role snapshot per account. A successful login replaces the previous snapshot.")); ?></p>
            <div id="vf-snapshots" data-vf-list="snapshots" aria-labelledby="vf-snapshots-heading"></div>
        </section>
        <section class="vf-card" id="vf-maintenance">
            <h3><?php p($l->t('Maintenance & updates')); ?></h3><dl class="vf-facts"><dt><?php p($l->t('App version')); ?></dt><dd>0.6.6</dd><dt>Nextcloud</dt><dd>33–35 · PHP ≥ 8.3</dd><dt><?php p($l->t('Local login')); ?></dt><dd><?php p($l->t('Available for local accounts with ?direct=1')); ?></dd><dt><?php p($l->t('Remember me')); ?></dt><dd><?php p($l->t('Nextcloud cookie according to server settings')); ?></dd><dt><?php p($l->t('Source code')); ?></dt><dd><?php p($l->t('Separate Git repository with release packages')); ?></dd></dl>
            <p><?php p($l->t('Back up app configuration and database before updating. Test new Nextcloud major versions before use.')); ?></p>
        </section>
        <footer class="vf-actions"><p id="vf-message" role="status" aria-live="polite"><?php p($l->t('Changes are applied when you save.')); ?></p><button type="submit" class="primary"><?php p($l->t('Save settings')); ?></button></footer>
    </form>
</div>
