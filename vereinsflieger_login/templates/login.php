<?php
/**
 * SPDX-FileCopyrightText: 2026 Vereinsflieger Login contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
\OCP\Util::addStyle('vereinsflieger_login', 'login');
\OCP\Util::addScript('vereinsflieger_login', 'login');
?>
<div class="guest-box vf-login">
    <h2><?php p($l->t('Log in with Vereinsflieger')); ?></h2>
    <?php if ($_['message'] !== '') { ?><p class="vf-login-message" role="alert" <?php if (($_['retryAfter'] ?? 0) > 0) { ?>data-pause-seconds="<?php p($_['retryAfter']); ?>" data-pause-message="<?php p($l->t('Login temporarily paused. Try again in %s.', ['%s'])); ?>" data-pause-expired="<?php p($l->t('Login pause expired. You can try again.')); ?>" aria-live="off"<?php } ?>><?php p($_['message']); ?></p><?php } ?>
    <?php if ($_['enabled']) { ?>
        <form id="vf-login-form" method="post" action="<?php p($_['action']); ?>">
            <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
            <input type="hidden" name="redirect_url" value="<?php p($_['redirectUrl']); ?>">
            <div class="vf-field"><input id="vf-username" type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="254" autofocus placeholder=" "><label for="vf-username"><?php p($l->t('Account name or email')); ?></label></div>
            <div class="vf-field vf-password"><input id="vf-password" type="password" name="password" autocomplete="current-password" required maxlength="1024" placeholder=" "><label for="vf-password"><?php p($l->t('Password')); ?></label><button type="button" id="vf-password-toggle" aria-controls="vf-password" aria-pressed="false" aria-label="<?php p($l->t('Show password')); ?>" data-show="<?php p($l->t('Show password')); ?>" data-hide="<?php p($l->t('Hide password')); ?>" hidden><span class="vf-eye" aria-hidden="true"></span></button></div>
            <div class="vf-field"><input id="vf-otp" name="otp" autocomplete="one-time-code" maxlength="32" placeholder=" "><label for="vf-otp"><?php p($l->t('Vereinsflieger 2FA code (optional)')); ?></label></div>
            <?php if ($_['canRemember']) { ?><label class="vf-remember"><input type="checkbox" name="remember" value="1"<?php if ($_['rememberDefault'] ?? true) { ?> checked<?php } ?>><?php p($l->t('Remember me')); ?></label><?php } ?>
            <button type="submit" class="primary vf-submit" data-loading="<?php p($l->t('Logging in …')); ?>"><span class="vf-submit-label"><?php p($l->t('Log in')); ?></span><span class="vf-arrow" aria-hidden="true">→</span></button>
        </form>
    <?php } else { ?><p><?php p($l->t('Vereinsflieger login is currently disabled.')); ?></p><?php } ?>
    <?php if ($_['showLocalLoginLink']) { ?><a class="vf-local-login" href="<?php p($_['localLogin']); ?>"><?php p($l->t('Log in with a local Nextcloud account')); ?></a><?php } ?>
</div>
