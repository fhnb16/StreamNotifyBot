<?php
// Twitch API configuration
define('TWITCH_CLIENT_ID', '');
define('TWITCH_SECRET', '');
define('TWITCH_CALLBACK_URL', 'https://example.com/twitch_webhook_handler.php'); // Your webhook handler URL

// Youtube Data API configuration
define('YOUTUBE_API_KEY', '');
define('YOUTUBE_CALLBACK_URL', 'https://example.com/youtube_webhook_handler.php'); // Your webhook handler URL

// Telegram API configuration
define('ADMIN_ID', ''); // Main channel for all notifications (optional) example: 1234567890
define('TELEGRAM_BOT_TOKEN', '');
define('MAIN_CHAT_ID', ''); // Main channel for all notifications (optional) example: -1001234567890
define('TELEGRAM_CALLBACK_URL', 'https://example.com/index.php'); // Your callback handler URL
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN);
define('TELEGRAM_NOTIFY_EDITOR_URL', 'https://example.com/editNotify.php'); // Your notify editor url

// EventSub Twitch API version
define('TWITCH_EVENTSUB_VERSION', '1');

// Other configuration
define('TWITCH_WEBHOOK_LEASE_DAYS', 10); // 10 days for webhook lease
define('TWITCH_WEBHOOK_LEASE_SECONDS', TWITCH_WEBHOOK_LEASE_DAYS * 86400);
define('LOG_FILE', 'log.txt'); // Path to log file
define('ERR_FILE', 'error.txt'); // Path to error file
define('DEBUG', true);


?>
