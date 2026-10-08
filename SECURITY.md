# Security

Report vulnerabilities privately to the repository maintainers. Include affected versions, impact and a minimal reproduction without real passwords, AppKeys, tokens or member data. Avoid publishing an exploit before maintainers have assessed it.

The application’s security boundaries include Nextcloud authorization and CSRF middleware, native two-factor enforcement, fixed HTTPS provider endpoints, bounded inputs, parameterized database queries, escaped HTML output and local redirect validation. Provider credentials are not stored in the session or application configuration and are not logged.

Email-based account migration is an explicit administrative trust decision because members can change their provider email. It is disabled by default and excludes administrators and local-only accounts. It does not establish independent proof of ownership of existing Nextcloud files.

Provider status is checked during authentication. Existing Nextcloud sessions and app passwords are not periodically revalidated against the provider. Administrators must disable Nextcloud access when members leave.
