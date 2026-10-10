# Configuration

## Connection and emergency access

Save the club ID (CID) and AppKey from Vereinsflieger's REST interface settings. The AppKey is encrypted with Nextcloud's crypto service and never returned to the browser.

Select emergency administrators under **Local login only** before enabling provider login. Exclusion overrides any identity link. Local accounts can reach `/login?direct=1`; optionally hide this link in the provider form without disabling the URL.

**Check saved configuration** validates the stored settings, active account links, known roles and existing target groups locally. It sends no provider requests. Save form changes before checking. Only a real provider login can confirm that the AppKey and club ID are accepted.

## Account links

Manual links use the provider's API `uid`, not its membership number, and Nextcloud's internal account ID, not its display name. Each provider identity and account can be linked only once within the club.

Optional email migration is disabled by default. After successful provider authentication it links exactly one active account with the same email, excluding administrators, disabled accounts, local-only accounts and already linked identities. Members can change their provider email, so matching email is not proof of ownership of an existing Nextcloud account. Enable this only for a controlled migration and disable it afterwards. The saved provider ID remains authoritative after migration; later email changes do not switch accounts.

Optional provisioning creates a new account when no existing account is linked or matches. It requires a verified first name, last name and valid email. Generated IDs follow `vf_CID_UID`. The app stores the club and stable provider identity. It does not store the provider password.

The unknown local account password is validated against Nextcloud's active account password policy. If the initial random password is rejected, the app requests a password from Nextcloud's policy-aware generator and validates it before account creation. A policy or generation failure prevents creation; the policy is never bypassed. Vereinsflieger credentials remain subject to the provider's rules.

After a completed login, including required Nextcloud two-factor authentication, verified first and last names update the linked account's display name. Existing account ID, files, email, language preference and administrator rights are preserved. Newly provisioned accounts inherit the login language.

While the app is enabled, linked SSO accounts cannot authenticate with their local account password, even after resetting it. Native Nextcloud app passwords remain usable. Explicitly excluded accounts retain local password login.

## Roles and groups

Received roles are saved per club. Choose mappings from this catalog; role names cannot be entered freely. A stored role grants no membership unless it is returned again for that member at login. The `admin` group is excluded from automated mapping. Administrators may grant admin rights manually after linking.

Only memberships added by this app may later be removed by it. Existing local memberships remain. If a relevant target group has been deleted, the entire group sync for that login is skipped, all memberships are preserved, login continues and a warning is shown in the admin page. A corrected mapping is applied at the next provider login.

## API protection

The documented provider allowance is 500 calls per AppKey per day. A successful authentication uses four calls. This app counts and reserves its own calls; it cannot see calls made by other integrations sharing the key. The default daily budget is 400; an optional hourly budget starts disabled.

| Setting | Default | Range |
| --- | ---: | ---: |
| Failed logins per username, across all IPs | 3 | 1–20 |
| Failed logins per IP, across all usernames | 3 | 1–100 |
| Failed-login pause, minutes | 15 | 1–1440 |
| Attempt window, minutes | 15 | 1–1440 |
| Attempts per username and IP | 6 | 1–100 |
| Attempts per IP | 30 | 1–1000 |
| Pause after provider HTTP 429, minutes | 15 | 1–1440 |
| Daily API budget | 400 | 4–500 |
| Additional hourly budget | Disabled | 0 or 4–500 |

Either failure counter triggers a pause independently: changing usernames does not avoid the IP threshold, and changing IPs does not avoid the username threshold. IPv4 and IPv6 addresses are supported; equivalent IPv6 spellings share a counter. Members sharing an internet connection share its IP limits. Successful authentication resets username failures; IP failures expire or require an admin to remove the pause. Existing pauses retain their expiry when settings change. Reservations prevent concurrent logins from overspending; unused calls are refunded. UTC day/hour boundaries reset budget periods. Invalid credential formats are rejected before any API request. Usernames and email addresses are supported; no undocumented username blacklist is imposed.

When a budget is exhausted or the provider limits calls, new provider authentication pauses. Existing sessions and local accounts remain available. SSO accounts do not gain a local-password fallback.

## Sessions and leaving the club

The admin option **Select Remember me by default** is enabled by default. Disabling it shows an unchecked checkbox; users can still select or deselect it. Nextcloud's server settings determine whether remember cookies are available.

Sessions, remember cookies and app passwords follow Nextcloud's own lifetime and revocation rules. Changing a provider password or leaving the club does not automatically terminate existing Nextcloud sessions or app passwords. Disable the Nextcloud account and revoke access when a member leaves. Role changes are applied at the next successful provider login.

This integration authenticates using Vereinsflieger credentials. It does not transfer an already authenticated Vereinsflieger browser session.

## Provider terms

The provider’s API terms apply separately from this app’s AGPL license. The supplied API specification prohibits commercial use of the interface. Provider documentation and sample code are not distributed with this app.

Active pauses show a countdown on the login page and can be removed individually in the admin settings. Request counters use keyed hashes. Active pause records additionally contain the login name and IP for administration and are pruned after expiry when the protection is used. Refused sign-ins log the login name, IP and reason in Nextcloud’s log. Completed sign-ins log the Nextcloud account and IP at info level after any native second factor. Nextcloud’s log threshold or a matching `log.condition` must allow info entries; its retention policy applies. Passwords, 2FA codes and AppKeys are never logged.

## Admin lists

Saved identities, confirmed role snapshots, synchronization warnings and active login pauses are loaded in pages of 25 entries. Use the search and Previous/Next controls to find other records; each panel has a bounded scroll area. Account links are retained for authentication, and each account stores only its latest role snapshot. Pagination does not delete records or change group mappings. Dates use the browser locale and time zone.
