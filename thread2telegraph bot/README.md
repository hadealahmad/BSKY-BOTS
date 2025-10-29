# Bluesky Thread→Telegraph Bot

Automated PHP bot that converts Bluesky threads into Telegraph articles. When mentioned in a Bluesky thread with a trigger word, the bot collects all posts from the root to the mention, extracts text and images, creates a formatted Telegraph page, and replies with a rich URL card.

## Features

- **Mention Detection**: Monitors Bluesky notifications for mentions with optimized filtering
- **Trigger Words**: Responds to Arabic trigger words (`رتبها`, `سرد`, `ترتيب`, `رتب` by default, configurable)
- **Thread Collection**: Collects entire thread from root post to mention (excluding the mention post itself)
- **Content Preservation**:
  - Post text with formatting preserved
  - Links, mentions, and hashtags converted to clickable links
  - Images automatically uploaded to Telegraph
  - External link cards preserved
- **Telegraph Integration**:
  - Creates formatted Telegraph pages with proper author attribution
  - Uses thread root author's name and profile link
  - Uploads images to Telegraph servers
  - Smart text chunking for long posts
- **Rich Reply Posts**:
  - Replies with Telegraph URL card (no URL in text body)
  - Includes page title and thumbnail image in card
  - Supports optional hashtag in reply
  - Customizable reply text: "تفضل معلم هي السرد"
- **Duplicate Prevention**: SQLite-based tracking prevents reprocessing
- **Performance Optimized**: Efficient notification filtering and early processing checks
- **Configurable Rate Limiting**: Respects API limits with adjustable processing caps
- **Dry-run Mode**: Test mode that logs actions without posting

## Requirements

- PHP 7.4 or higher
- PHP extensions: `sqlite3`, `curl`, `json`, `mbstring`, `dom`
- Bluesky account with App Password
- Telegraph access token (optional - bot can create anonymous account)
- Cron access (recommended for automated polling)

### Installing Required PHP Extensions

If you're missing required extensions:

**Arch Linux / CachyOS:**
```bash
sudo pacman -S php-sqlite php-curl php-mbstring php-xml
```

**Ubuntu/Debian:**
```bash
sudo apt-get install php-sqlite3 php-curl php-mbstring php-xml
```

**Verify extensions are installed:**
```bash
php -r "echo extension_loaded('sqlite3') ? 'sqlite3: OK' : 'sqlite3: MISSING'; echo PHP_EOL;"
php -r "echo extension_loaded('curl') ? 'curl: OK' : 'curl: MISSING'; echo PHP_EOL;"
php -r "echo extension_loaded('mbstring') ? 'mbstring: OK' : 'mbstring: MISSING'; echo PHP_EOL;"
```

## Installation

1. **Get Telegraph access token (optional):**
   - Visit https://api.telegra.ph/createAccount
   - Save the `access_token` from the response
   - If not provided, bot will automatically create an anonymous account
   - Note: New anonymous accounts may be rate-limited for the first hour

2. **Create Bluesky App Password:**
   - Go to Bluesky Settings > App Passwords
   - Create a new app password and save it securely

3. **Configure credentials:**
   ```bash
   cd "thread2telegraph bot"
   cp env-example .env
   nano .env
   ```
   
   Edit the `.env` file with your credentials (see Configuration section below)

4. **Test the bot:**
   ```bash
   php bot.php
   ```

5. **Set up cron job for automated polling:**
   ```bash
   crontab -e
   ```
   
   Add this line to run every minute:
   ```
   * * * * * /usr/bin/php "/run/media/hadi/SSD2/Coding/BSKY BOTS/thread2telegraph bot/bot.php" >> "/run/media/hadi/SSD2/Coding/BSKY BOTS/thread2telegraph bot/cron.log" 2>&1
   ```
   
   Adjust the path to match your installation directory.

## File Structure

```
thread2telegraph bot/
├── bot.php                    # Main bot script
├── config.php                 # Configuration loader
├── composer.json              # PHP extension requirements
├── .env                       # Your credentials (create from env-example)
├── env-example                # Template for credentials
├── .gitignore                 # Git ignore file
├── README.md                  # This file
├── processed.sqlite           # Auto-created database (tracking processed mentions)
├── bot.log                    # Auto-created log file
└── telegraph_token.txt        # Auto-created if using anonymous Telegraph account
```

## How It Works

1. **Authentication:**
   - Bot authenticates with Bluesky using `com.atproto.server.createSession`
   - Stores access JWT and DID (Decentralized Identifier)
   - Creates or retrieves Telegraph access token

2. **Notification Processing:**
   - Fetches recent notifications via `app.bsky.notification.listNotifications`
   - Filters for mention notifications only (optimized processing)
   - Early filtering: skips already-processed mentions before further checks
   - Validates mentions target the bot's DID

3. **Trigger Detection:**
   - Checks if post text contains trigger words (Arabic word-boundary matching)
   - Skips if already processed (checked against SQLite database)
   - Fetches full record if facets are missing

4. **Thread Collection:**
   - Calls `app.bsky.feed.getPostThread` with the mention post URI
   - Traverses from mention post up to root post
   - Collects all posts in order (root → mention)
   - **Excludes the mention post itself** from Telegraph content

5. **Content Extraction:**
   - **Text Processing:**
     - Preserves original formatting
     - Converts facets to Telegraph HTML format:
       - Links: `<a href="...">` tags
       - Mentions: Links to Bluesky profiles (`https://bsky.app/profile/{did}`)
       - Hashtags: Links to hashtag pages (`https://bsky.app/hashtag/{tag}`)
     - Smart text chunking for long posts (300-5000 character limits)
   - **Images:**
     - Extracts from `app.bsky.embed.images` embeds
     - Downloads images from Bluesky blob URLs
     - Uploads to Telegraph via `https://telegra.ph/upload` endpoint
     - Embeds uploaded Telegraph image URLs in content
     - Falls back to direct URLs if upload fails
   - **External Cards:** Converts `app.bsky.embed.external` to clickable links
   - **Author Attribution:** Each post includes author handle as `@username: ` prefix

6. **Telegraph Page Creation:**
   - Builds Telegraph content array (nodes: paragraphs, images, links)
   - Uses first post text as page title (truncated to 90 chars, with unique suffix)
   - Extracts thread root author information:
     - Author name: displayName or handle
     - Author URL: Link to root post on Bluesky
   - Calls Telegraph `/createPage` API with:
     - Title
     - Content nodes
     - Author name and URL (from thread root author)
     - Access token

7. **Reply Post Creation:**
   - Fetches Telegraph page metadata (title, description)
   - Extracts first image from page content as thumbnail
   - Uploads thumbnail to Bluesky blob storage
   - Creates reply post with:
     - Text: "تفضل معلم هي السرد" + optional hashtag
     - Hashtag facet (if `REPLY_HASHTAG` is configured)
     - Rich URL card embed with:
       - Telegraph page title
       - Page description
       - Thumbnail image (uploaded to Bluesky)
   - Note: URL is **not** included in post text, only in the card
   - Sets proper reply parent/root structure

8. **Tracking:**
   - Records processed mention URI in SQLite database
   - Prevents duplicate processing across bot restarts

## Configuration

Edit `.env` file to customize behavior:

### Required Settings

- `BLUESKY_IDENTIFIER`: Your Bluesky handle (e.g., `botname.bsky.social`)
- `BLUESKY_PASSWORD`: Your Bluesky App Password (NOT your main password)

### Optional Settings

- `TELEGRAPH_ACCESS_TOKEN`: Your Telegraph API access token (optional - bot creates anonymous account if not provided)
- `TELEGRAPH_AUTHOR_NAME`: Fallback author name (default: "Thread Compiler") - Note: Bot uses thread root author instead
- `TELEGRAPH_AUTHOR_URL`: Fallback author URL (default: empty) - Note: Bot uses thread root post link instead
- `TRIGGER_WORDS`: Comma-separated list of trigger words (default: `رتبها,سرد,ترتيب,رتب`)
- `REPLY_HASHTAG`: Optional hashtag to include in reply posts (without # symbol, e.g., `myhashtag`)
- `MAX_MENTIONS_PER_RUN`: Maximum mentions to process per execution (default: 3)
- `DB_PATH`: Path to SQLite database (default: `processed.sqlite`)
- `LOG_PATH`: Path to log file (default: `bot.log`)
- `DRY_RUN`: Set to `1` to log actions without actually posting (default: 0)
- `BLUESKY_API_URL`: Bluesky API endpoint (default: `https://bsky.social/xrpc`)

### Example `.env` file:

```bash
BLUESKY_IDENTIFIER=mybot.bsky.social
BLUESKY_PASSWORD=abcd-efgh-ijkl-mnop
TELEGRAPH_ACCESS_TOKEN=0123456789abcdef0123456789abcdef01234567
TELEGRAPH_AUTHOR_NAME=Thread Compiler Bot
TELEGRAPH_AUTHOR_URL=https://bsky.app/profile/mybot.bsky.social
TRIGGER_WORDS=رتبها,سرد,ترتيب,رتب
REPLY_HASHTAG=threadarchive
MAX_MENTIONS_PER_RUN=3
DRY_RUN=0
```

## Usage

### Manual Execution

Run the bot once:
```bash
php bot.php
```

### Automated (Cron)

Add to crontab to run every minute:
```
* * * * * /usr/bin/php "/path/to/thread2telegraph bot/bot.php" >> "/path/to/cron.log" 2>&1
```

Recommended frequency: Every 1-2 minutes to balance responsiveness with API rate limits.

### Dry Run Mode

Set `DRY_RUN=1` in `.env` to test without posting:
- Bot will log all actions it would take
- No Telegraph pages will be created
- No Bluesky replies will be posted
- Mentions will still be marked as processed (to prevent duplicate test processing)

## How to Use the Bot

1. Start a Bluesky thread (or find an existing one)
2. Reply to the thread and mention the bot with a trigger word: `@yourbot.bsky.social رتبها`
3. The bot will:
   - Detect the mention with trigger word
   - Collect the entire thread from root to your mention (excluding your mention post)
   - Extract all text, images, and links
   - Upload images to Telegraph
   - Create a formatted Telegraph article with thread root author attribution
   - Reply with a rich URL card showing:
     - Page title from Telegraph
     - Thumbnail image from first image in thread
     - Descriptive text: "تفضل معلم هي السرد"
     - Optional hashtag (if configured)

### Trigger Words

By default, the bot responds to these Arabic words:
- `رتبها` - "organize it"
- `سرد` - "narrate"
- `ترتيب` - "arrange"
- `رتب` - "organize"

You can customize these via `TRIGGER_WORDS` in `.env`. Words are matched with word boundaries (not substring matching).

### Reply Format

The bot's reply posts include:
- **Text**: "تفضل معلم هي السرد" followed by optional hashtag
- **URL Card**: Rich embed showing:
  - Telegraph page title
  - Page description
  - Thumbnail image (first image from thread)
  - Clickable link to Telegraph page
- **No URL in text**: The Telegraph URL is only in the card, not written as text

## Logging

All activity is logged to `bot.log` in the same directory. Check this file for:
- Authentication status
- Mention detection and filtering
- Processing status
- Image upload results
- Errors and warnings
- Telegraph page URLs created
- Reply posting status

Example log output:
```
[2025-10-29 23:14:10] === Thread→Telegraph Bot Starting ===
[2025-10-29 23:14:11] Authenticated as radar.syrian.zone (did:plc:bjj4ucintgxxhoav6tpumlrk)
[2025-10-29 23:14:12] Found 50 notifications
[2025-10-29 23:14:12] Found 1 potential mentions
[2025-10-29 23:14:12] Checking mention: URI=at://did:plc:.../app.bsky.feed.post/3m4el6vmzds2v, Text=@radar.syrian.zone رتبها ولك الأجر..., Bot DID=did:plc:bjj4ucintgxxhoav6tpumlrk
[2025-10-29 23:14:12] Processing mention: at://did:plc:.../app.bsky.feed.post/3m4el6vmzds2v
[2025-10-29 23:14:14] Image uploaded successfully: https://telegra.ph/file/abc123.jpg
[2025-10-29 23:14:14] Replied with Telegraph URL: https://telegra.ph/Thread-Title-01-20
[2025-10-29 23:14:14] Processed mentions: 1
[2025-10-29 23:14:14] === Thread→Telegraph Bot Finished ===
```

## Troubleshooting

**Authentication fails:**
- Verify your handle is correct (include `.bsky.social`)
- Use an App Password, not your main password
- Create App Password at: Bluesky Settings > App Passwords
- Check `bot.log` for specific error messages

**Bot doesn't respond to mentions:**
- Check `bot.log` for mention detection logs
- Verify trigger word is spelled correctly in the post
- Ensure bot account is not muted/blocked
- Check if mention has already been processed (see database)
- Verify mention is formatted correctly: `@botname.bsky.social triggerword`

**Image upload fails:**
- Check `bot.log` for "WARNING: Failed to upload image" messages
- Images will fall back to direct URLs if Telegraph upload fails
- Verify Bluesky image blobs are accessible
- Telegraph upload endpoint may have temporary issues
- New Telegraph accounts may be rate-limited for first hour

**Telegraph page creation fails:**
- Verify `TELEGRAPH_ACCESS_TOKEN` is valid (if provided)
- Bot can create anonymous account automatically if token not provided
- Check Telegraph API status
- Review `bot.log` for specific error messages
- Anonymous accounts may be rate-limited: see "WARNING: Telegraph token is X minutes old" in logs

**URL card doesn't show title/image:**
- Bot fetches Telegraph page metadata after creation
- Check `bot.log` for page info fetch errors
- Thumbnail extraction requires page content to be accessible
- Image upload to Bluesky may fail silently (card shows without image)

**Rate limiting issues:**
- Reduce `MAX_MENTIONS_PER_RUN` to process fewer mentions per execution
- Increase cron interval (e.g., every 2 minutes instead of 1)
- Check `bot.log` for rate limit errors from Bluesky or Telegraph API
- Wait for rate limit cooldown period

**Database errors:**
- Ensure directory is writable
- Check `processed.sqlite` exists and has proper permissions
- Delete database file to reset processing history (bot will recreate it)
- Verify SQLite extension is installed

**Thread collection issues:**
- Very deep threads (10+ levels) may not be fully collected (depth limit: 10)
- Thread structure must be intact (deleted posts break traversal)
- Check `bot.log` for thread fetch errors
- Mention post is excluded from Telegraph content (by design)

**Performance issues:**
- Bot now has optimized notification filtering
- Check `bot.log` for processing times
- Reduce `MAX_MENTIONS_PER_RUN` if processing is slow
- Consider running less frequently via cron

## Rate Limiting

The bot respects API rate limits by:
- Processing maximum `MAX_MENTIONS_PER_RUN` mentions per execution (default: 3)
- Skipping already-processed mentions early in the filtering process
- Optimized notification processing to reduce unnecessary API calls
- Running via cron (recommended: every 1-2 minutes)

This allows safe operation without hitting Bluesky or Telegraph API limits:
- **Bluesky**: No specific documented limits, but conservative approach recommended
- **Telegraph**: New anonymous accounts may be limited for first hour

## Security

- Never commit `.env` file to version control (already in `.gitignore`)
- Use App Passwords instead of main account password
- Keep Telegraph access token secure (if provided)
- Review `bot.log` periodically for security-relevant information
- Consider using separate Bluesky account for the bot
- Database stores only post URIs, no sensitive data

## Technical Details

### Image Handling

1. **Bluesky to Telegraph:**
   - Downloads images from Bluesky blob URLs
   - Uploads via `https://telegra.ph/upload` (multipart/form-data)
   - Converts to Telegraph image URLs
   - Embeds in Telegraph page content

2. **Telegraph to Bluesky:**
   - Extracts first image from Telegraph page content
   - Downloads image from Telegraph
   - Uploads to Bluesky blob storage via `com.atproto.repo.uploadBlob`
   - Uses as thumbnail in URL card embed

### Text Processing

- Preserves Unicode characters (Arabic, etc.)
- Smart chunking for Telegraph limits:
  - Paragraph text: max 2000 characters
  - Merged text nodes: max 300 characters  
  - Final node validation: max 5000 characters
- Word-boundary aware chunking at natural break points

### Author Attribution

- Telegraph pages use thread root author information:
  - Name: `displayName` or `handle` from root post author
  - URL: Link to root post on Bluesky (`https://bsky.app/profile/{handle}/post/{rkey}`)
- Falls back to config values if root author info unavailable

## API References

- **Bluesky HTTP API:** https://docs.bsky.app/docs/category/http-reference
- **Telegraph API:** https://telegra.ph/api

### Key Bluesky Endpoints Used

- `com.atproto.server.createSession` - Authentication
- `app.bsky.notification.listNotifications` - Poll mentions
- `app.bsky.feed.getPostThread` - Fetch thread structure (depth: 10)
- `com.atproto.repo.getRecord` - Get post record details
- `com.atproto.repo.createRecord` - Post replies
- `com.atproto.repo.uploadBlob` - Upload image thumbnails

### Telegraph Endpoints Used

- `https://telegra.ph/upload` - Upload images (multipart/form-data)
- `https://api.telegra.ph/createPage` - Create articles
- `https://api.telegra.ph/getPage` - Fetch page metadata and content
- `https://api.telegra.ph/createAccount` - Create anonymous account (if needed)

## License

Free to use and modify.
