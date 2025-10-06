<?php

/**
 * Configuration file for Sana Bot
 * 
 * Load environment variables from .env file if it exists
 */

// Load .env file if it exists
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
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

// Configuration constants
define('BLUESKY_IDENTIFIER', getenv('BLUESKY_IDENTIFIER') ?: '');
define('BLUESKY_PASSWORD', getenv('BLUESKY_PASSWORD') ?: '');
define('RSS_FEED_URL', getenv('RSS_FEED_URL') ?: 'https://sana.sy/rss');
define('DB_PATH', __DIR__ . '/sent_links.sqlite');
define('LOG_PATH', __DIR__ . '/bot.log');
define('BLUESKY_API_URL', 'https://bsky.social/xrpc');

// Validate required configuration
if (empty(BLUESKY_IDENTIFIER) || empty(BLUESKY_PASSWORD)) {
    error_log('ERROR: Bluesky credentials not configured. Please set BLUESKY_IDENTIFIER and BLUESKY_PASSWORD in .env file');
}

