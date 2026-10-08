# Third-party notices

Vereinsflieger Login is distributed under AGPL-3.0-or-later. The license text
is included in `COPYING`.

The login layout follows the dimensions and arrangement of Nextcloud Server
33.0.9 `core/src/views/Login.vue` and `core/src/components/login/LoginForm.vue`:
copyright 2019 Nextcloud GmbH and Nextcloud contributors, AGPL-3.0-or-later.
These upstream layout details informed `css/login.css` and `templates/login.php`.
The component implementations are not bundled. Colors, guest layout, background
and theming are supplied by the installed Nextcloud server.

- https://github.com/nextcloud/server/blob/v33.0.9/core/src/views/Login.vue
- https://github.com/nextcloud/server/blob/v33.0.9/core/src/components/login/LoginForm.vue

The native login sequence in `lib/Service/LoginSession.php` was developed using
the Nextcloud `user_oidc` 8.11.0 login controller as a reference:
copyright 2020 Nextcloud GmbH and Nextcloud contributors, AGPL-3.0-or-later.
This app calls Nextcloud's session APIs; it does not bundle the OIDC controller.
Attribution is retained here and in the session adapter.

- https://github.com/nextcloud/user_oidc/blob/v8.11.0/lib/Controller/LoginController.php

The app's PHP classes, JavaScript, translations and airplane SVG were created
for this project. The SVG contains no Vereinsflieger or Nextcloud logo.
Vereinsflieger API documentation and its sample implementation are not included
in this distribution. The client independently implements the documented
protocol, including the specified password encoding and digest.

Nextcloud and Vereinsflieger names identify the systems being integrated.
This project is an independent community integration and is not presented as
an official Vereinsflieger product.

No third-party runtime dependency is vendored in the app package. Nextcloud
supplies the framework and services. Development tools are installed separately
and are not included in the app archive.
