<?php

// Load .env if present
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }
    }
}

define('BLUESKY_IDENTIFIER', getenv('BLUESKY_IDENTIFIER') ?: '');
define('BLUESKY_PASSWORD', getenv('BLUESKY_PASSWORD') ?: '');
define('TELEGRAPH_ACCESS_TOKEN', getenv('TELEGRAPH_ACCESS_TOKEN') ?: '');
define('TELEGRAPH_AUTHOR_NAME', getenv('TELEGRAPH_AUTHOR_NAME') ?: 'Thread Compiler');
define('TELEGRAPH_AUTHOR_URL', getenv('TELEGRAPH_AUTHOR_URL') ?: '');

// Comma-separated trigger words
$triggerWordsEnv = getenv('TRIGGER_WORDS') ?: 'رتبها,سرد,ترتيب,رتب';
define('TRIGGER_WORDS', array_values(array_filter(array_map('trim', explode(',', $triggerWordsEnv)))));

define('DB_PATH', getenv('DB_PATH') ?: __DIR__ . '/processed.sqlite');
define('LOG_PATH', getenv('LOG_PATH') ?: __DIR__ . '/bot.log');
define('BLUESKY_API_URL', getenv('BLUESKY_API_URL') ?: 'https://bsky.social/xrpc');
define('TELEGRAPH_API_URL', 'https://api.telegra.ph');

// Optional: cap processed mentions per run
define('MAX_MENTIONS_PER_RUN', (int)(getenv('MAX_MENTIONS_PER_RUN') ?: 3));
define('DRY_RUN', (bool)(getenv('DRY_RUN') ?: false));
define('REPLY_HASHTAG', getenv('REPLY_HASHTAG') ?: '');

if (empty(BLUESKY_IDENTIFIER) || empty(BLUESKY_PASSWORD)) {
    error_log('ERROR: Missing Bluesky credentials in .env');
}
// TELEGRAPH_ACCESS_TOKEN is optional - bot will create anonymous account if not provided


