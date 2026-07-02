<?php
/**
 * Troiana admin config.
 *
 * SETUP (do this on the server, once):
 *   1. Copy this file to "config.php" (same folder).
 *   2. Change the password below to something only you know.
 *   3. config.php is gitignored, so it never goes to GitHub.
 *
 * The admin console lives at  /admin/  (e.g. https://web.troiana.net/admin/).
 */
return [
    // Password you'll type to log into /admin/
    'admin_password' => 'change-this-now',

    // Email that gets a copy of every submission (backup).
    'notify_email'   => 'hello@troiana.net',
];
