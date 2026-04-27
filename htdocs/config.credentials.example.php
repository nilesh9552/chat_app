<?php
// Copy this file to config.credentials.php and set your live DB details.
// This file is optional. config.php will load it automatically if present.

define('DB_HOST', 'sqlXXX.byetcluster.com');
define('DB_USER', 'if0_xxxxxxxx');
define('DB_PASS', 'YOUR_DB_PASSWORD');
define('DB_NAME', 'if0_xxxxxxxx_chat_app');

// Keep this false on shared hosting. Use true only for local development.
define('DB_AUTO_INIT', false);

// Keep false in production to preserve chat history.
define('APP_CLEAR_ON_LOAD', false);

// Optional admin token used by clear.php. Keep this secret.
define('ADMIN_CLEAR_TOKEN', '');

// Owner login secret and display name.
define('APP_CHAT_SECRET_OWNER', '');
define('APP_CHAT_OWNER_NAME', 'Owner');

// User login secret and display name.
define('APP_CHAT_SECRET_USER', '');
define('APP_CHAT_USER_NAME', 'User');

// Legacy single-secret fallback (optional):
// define('APP_CHAT_SECRET', '');
// define('APP_CHAT_DISPLAY_NAME', 'Owner');
