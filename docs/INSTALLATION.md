# Installation and updates

Use a separate development instance before deploying a new version. Keep a working local emergency administrator account and a consistent backup of the database, Nextcloud configuration and previous app directory.

## Install

1. Obtain the app archive and SHA-256 checksum from a trusted release and verify the checksum.
2. Extract the contained `vereinsflieger_login` directory into a writable Nextcloud app directory, normally `custom_apps`.
3. Set ownership and permissions for your Nextcloud web-server user.
4. Enable the app in the app manager or with `php occ app:enable vereinsflieger_login`.
5. Open **Administration → Vereinsflieger Login** and follow [Configuration](CONFIGURATION.md).

Enabling the app exposes its administration page. Vereinsflieger login, automatic account creation, email migration, default-login redirection and group synchronization initially remain disabled. No provider API calls are made during installation or local configuration checking.

HTTPS is required for provider login. When using a reverse proxy, configure Nextcloud’s trusted domains, trusted proxy addresses and HTTPS overwrite settings for your deployment.

## Update

Back up first. Replace the app directory with the verified archive, preserving Nextcloud’s configuration and database. Run `php occ upgrade` when required, then restart or reload the PHP worker to invalidate OPcache. Verify both provider login and the local emergency login.

Schema migrations create the request-counter, role-catalog and account-link tables through Nextcloud. Do not delete these tables or downgrade across migrations without a consistent restoration plan.

## Disable or remove

`php occ app:disable vereinsflieger_login` disables this integration. It does not remove accounts, files or memberships, and does not revoke existing sessions or app passwords. The local-password guard is also inactive while the app is disabled. Review linked account passwords and tokens before disabling the app.

Uninstallation is not a membership-offboarding workflow. Disable a departed member’s Nextcloud account and revoke their sessions and app passwords explicitly.
