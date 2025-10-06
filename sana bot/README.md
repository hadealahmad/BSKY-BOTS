# Sana Bot - RSS to Bluesky Poster

Automated PHP bot that posts updates from the sana.sy RSS feed to Bluesky every minute.

## Features

- Fetches RSS feed from https://sana.sy/rss
- Posts link preview cards to Bluesky with hashtag `#سانا_زون`
- Automatically extracts Open Graph metadata from articles:
  - Article title (og:title)
  - Article description (og:description)
  - Article thumbnail image (og:image)
- Uploads thumbnail images to Bluesky blob storage
- Creates rich `app.bsky.embed.external` embeds for preview cards
- Tracks posted links in SQLite to prevent duplicates
- Runs via cron every minute
- Proper error handling and logging
- Fallback to RSS title/description if metadata unavailable

## Requirements

- PHP 7.4 or higher
- PHP extensions: `sqlite3`, `curl`, `json`, `mbstring`, `simplexml`, `dom`
- Cron access

### Installing Required PHP Extensions

If you're missing the `sqlite3` extension:

**Arch Linux / CachyOS:**
```bash
sudo pacman -S php-sqlite
```

**Ubuntu/Debian:**
```bash
sudo apt-get install php-sqlite3
```

**Verify extensions are installed:**
```bash
php -r "echo extension_loaded('sqlite3') ? 'sqlite3: OK' : 'sqlite3: MISSING'; echo PHP_EOL;"
```

## Installation

1. **Configure credentials:**
   ```bash
   cp env-example .env
   nano .env
   ```
   
   Edit the `.env` file with your Bluesky credentials:
   - `BLUESKY_IDENTIFIER`: Your Bluesky handle (e.g., `yourname.bsky.social`)
   - `BLUESKY_PASSWORD`: Your App Password (create one in Bluesky Settings > App Passwords)

2. **Test the bot:**
   ```bash
   php bot.php
   ```

3. **Set up cron job:**
   ```bash
   crontab -e
   ```
   
   Add this line to run every minute:
   ```
   * * * * * /usr/bin/php "/run/media/hadi/SSD2/Coding/BSKY BOTS/sana bot/bot.php" >> "/run/media/hadi/SSD2/Coding/BSKY BOTS/sana bot/cron.log" 2>&1
   ```

## File Structure

```
sana bot/
├── bot.php              # Main bot script
├── config.php           # Configuration loader
├── composer.json        # PHP extension requirements
├── .env                 # Your credentials (create from env-example)
├── env-example          # Template for credentials
├── .gitignore           # Git ignore file
├── README.md            # This file
├── TASKS.md             # Development tasks and progress
├── sent_links.sqlite    # Auto-created database
└── bot.log              # Auto-created log file
```

## How It Works

1. Bot is triggered by cron every minute
2. Authenticates with Bluesky using App Password
3. Fetches RSS feed from sana.sy
4. For each new item:
   - Extracts article URL from RSS
   - Checks against SQLite database for duplicates
   - Fetches article webpage to extract Open Graph metadata
   - Downloads thumbnail image from og:image tag
   - Uploads thumbnail to Bluesky blob storage
   - Creates post with hashtag `#سانا_زون` and `app.bsky.embed.external` embed
5. Post appears with clickable hashtag and rich preview card (title, description, image)
6. Stores posted link in database
7. Logs all activity to `bot.log`

## Configuration

Edit `.env` file to customize:

- `BLUESKY_IDENTIFIER`: Your Bluesky handle
- `BLUESKY_PASSWORD`: Your App Password (NOT main password)
- `RSS_FEED_URL`: RSS feed URL (default: https://sana.sy/rss)

## Logging

All activity is logged to `bot.log` in the same directory. Check this file for:
- Successful posts
- Authentication status
- Errors and warnings

## Troubleshooting

**Authentication fails:**
- Verify your handle is correct (include `.bsky.social`)
- Use an App Password, not your main password
- Create App Password at: Bluesky Settings > App Passwords

**No posts appearing:**
- Check `bot.log` for errors
- Verify RSS feed is accessible
- Ensure cron is running: `systemctl status cron`

**Preview cards not showing:**
- Check `bot.log` for metadata extraction issues
- Bot requires Open Graph tags (og:title, og:description, og:image) on target pages
- If metadata fetch fails, bot uses RSS feed title as fallback
- Thumbnail upload is optional - card will show without image if upload fails

**Database errors:**
- Ensure directory is writable
- Check `sent_links.sqlite` exists and has proper permissions

## Rate Limiting

Bot posts maximum 1 item per run to respect Bluesky's rate limits. With cron running every minute, this allows up to 60 posts per hour.

## Security

- Never commit `.env` file to version control
- Use App Passwords instead of main account password
- Keep bot logs secure as they may contain sensitive info

## License

Free to use and modify.

