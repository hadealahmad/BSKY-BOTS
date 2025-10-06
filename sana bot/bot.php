<?php

/**
 * Sana Bot - RSS to Bluesky Poster
 * 
 * Fetches RSS feed from sana.sy and posts new items to Bluesky
 * Designed to run every minute via cron
 */

require_once __DIR__ . '/config.php';

class SanaBot
{
    private $db;
    private $accessJwt;
    private $did;
    
    public function __construct()
    {
        $this->initDatabase();
    }
    
    /**
     * Initialize SQLite database
     */
    private function initDatabase()
    {
        try {
            $this->db = new SQLite3(DB_PATH);
            $this->db->exec('CREATE TABLE IF NOT EXISTS sent_links (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                link TEXT UNIQUE NOT NULL,
                posted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )');
        } catch (Exception $e) {
            $this->log('ERROR: Failed to initialize database: ' . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Log message to file
     */
    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        file_put_contents(LOG_PATH, $logMessage, FILE_APPEND);
        echo $logMessage;
    }
    
    /**
     * Authenticate with Bluesky API
     */
    private function authenticate()
    {
        $ch = curl_init(BLUESKY_API_URL . '/com.atproto.server.createSession');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'identifier' => BLUESKY_IDENTIFIER,
            'password' => BLUESKY_PASSWORD
        ]));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->log('ERROR: Authentication failed with HTTP code ' . $httpCode);
            $this->log('Response: ' . $response);
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['accessJwt']) || !isset($data['did'])) {
            $this->log('ERROR: Invalid authentication response');
            return false;
        }
        
        $this->accessJwt = $data['accessJwt'];
        $this->did = $data['did'];
        
        $this->log('Successfully authenticated as ' . BLUESKY_IDENTIFIER);
        return true;
    }
    
    /**
     * Fetch Open Graph metadata from URL
     */
    private function fetchOpenGraphData($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; BlueskyBot/1.0)');
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$html) {
            $this->log('WARNING: Failed to fetch page for metadata');
            return null;
        }
        
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        
        $ogData = [];
        $metaTags = $xpath->query('//meta');
        
        foreach ($metaTags as $meta) {
            $property = $meta->getAttribute('property');
            $name = $meta->getAttribute('name');
            $content = $meta->getAttribute('content');
            
            // Check Open Graph tags
            if (strpos($property, 'og:') === 0) {
                $key = str_replace('og:', '', $property);
                $ogData[$key] = $content;
            }
            // Fallback to Twitter cards
            elseif (strpos($name, 'twitter:') === 0) {
                $key = str_replace('twitter:', '', $name);
                if (!isset($ogData[$key])) {
                    $ogData[$key] = $content;
                }
            }
        }
        
        // Fallback to title tag if no og:title
        if (!isset($ogData['title'])) {
            $titleTags = $xpath->query('//title');
            if ($titleTags->length > 0) {
                $ogData['title'] = $titleTags->item(0)->textContent;
            }
        }
        
        return $ogData;
    }
    
    /**
     * Upload image blob to Bluesky
     */
    private function uploadImageBlob($imageUrl)
    {
        // Download image
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; BlueskyBot/1.0)');
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$imageData) {
            $this->log('WARNING: Failed to download thumbnail from ' . $imageUrl);
            return false;
        }
        
        // Validate it's an image
        if (strpos($contentType, 'image') === false) {
            $this->log('WARNING: URL is not an image: ' . $contentType);
            return false;
        }
        
        // Upload blob to Bluesky
        $ch = curl_init(BLUESKY_API_URL . '/com.atproto.repo.uploadBlob');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: ' . $contentType,
            'Authorization: Bearer ' . $this->accessJwt
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->log('WARNING: Thumbnail upload failed with HTTP code ' . $httpCode);
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['blob'])) {
            $this->log('WARNING: Invalid blob upload response');
            return false;
        }
        
        return $data['blob'];
    }
    
    /**
     * Post to Bluesky
     */
    private function postToBluesky($text, $facets = [], $embed = null)
    {
        $record = [
            'text' => $text,
            '$type' => 'app.bsky.feed.post',
            'createdAt' => date('c')
        ];
        
        if (!empty($facets)) {
            $record['facets'] = $facets;
        }
        
        if ($embed !== null) {
            $record['embed'] = $embed;
        }
        
        $ch = curl_init(BLUESKY_API_URL . '/com.atproto.repo.createRecord');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessJwt
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'repo' => $this->did,
            'collection' => 'app.bsky.feed.post',
            'record' => $record
        ]));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->log('ERROR: Post failed with HTTP code ' . $httpCode);
            $this->log('Response: ' . $response);
            return false;
        }
        
        return true;
    }
    
    /**
     * Extract hashtags from text and create facets
     */
    private function extractHashtags($text)
    {
        $facets = [];
        
        // Match hashtags (including Arabic characters)
        $pattern = '/#[\p{L}\p{N}_]+/u';
        
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $hashtag = $match[0];
                $byteOffset = strlen(substr($text, 0, $match[1]));
                $byteLength = strlen($hashtag);
                
                $facets[] = [
                    'index' => [
                        'byteStart' => $byteOffset,
                        'byteEnd' => $byteOffset + $byteLength
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#tag',
                            'tag' => mb_substr($hashtag, 1) // Remove # prefix
                        ]
                    ]
                ];
            }
        }
        
        return $facets;
    }
    
    /**
     * Check if link has already been posted
     */
    private function isLinkPosted($link)
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as count FROM sent_links WHERE link = :link');
        $stmt->bindValue(':link', $link, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row['count'] > 0;
    }
    
    /**
     * Mark link as posted
     */
    private function markLinkAsPosted($link)
    {
        $stmt = $this->db->prepare('INSERT INTO sent_links (link) VALUES (:link)');
        $stmt->bindValue(':link', $link, SQLITE3_TEXT);
        return $stmt->execute();
    }
    
    /**
     * Parse RSS feed manually
     */
    private function parseRssFeed($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $rssContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$rssContent) {
            return false;
        }
        
        // Parse XML
        $xml = simplexml_load_string($rssContent);
        if ($xml === false) {
            return false;
        }
        
        $items = [];
        
        // Check if it's RSS 2.0
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = [
                    'title' => (string) $item->title,
                    'link' => (string) $item->link,
                    'description' => (string) $item->description,
                    'pubDate' => (string) $item->pubDate
                ];
            }
        }
        // Check if it's Atom
        elseif (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $items[] = [
                    'title' => (string) $entry->title,
                    'link' => (string) $entry->link['href'],
                    'description' => (string) $entry->summary,
                    'pubDate' => (string) $entry->published
                ];
            }
        }
        
        return $items;
    }
    
    /**
     * Fetch and process RSS feed
     */
    private function processFeed()
    {
        $this->log('Fetching RSS feed from ' . RSS_FEED_URL);
        
        $items = $this->parseRssFeed(RSS_FEED_URL);
        
        if ($items === false) {
            $this->log('ERROR: Failed to fetch or parse RSS feed');
            return;
        }
        
        $this->log('Found ' . count($items) . ' items in feed');
        
        $newPosts = 0;
        
        foreach ($items as $item) {
            $link = $item['link'];
            $title = $item['title'];
            
            if ($this->isLinkPosted($link)) {
                continue;
            }
            
            // Fetch Open Graph metadata for link preview
            $this->log('Fetching metadata for: ' . $link);
            $ogData = $this->fetchOpenGraphData($link);
            
            if (!$ogData || !isset($ogData['title'])) {
                $this->log('WARNING: Could not fetch metadata, using RSS title');
                $ogData = [
                    'title' => $title,
                    'description' => ''
                ];
            }
            
            // Create external embed for link preview
            $embed = [
                '$type' => 'app.bsky.embed.external',
                'external' => [
                    'uri' => $link,
                    'title' => $ogData['title'] ?? $title,
                    'description' => $ogData['description'] ?? ''
                ]
            ];
            
            // Upload thumbnail if available
            if (!empty($ogData['image'])) {
                $this->log('Uploading thumbnail: ' . $ogData['image']);
                $blob = $this->uploadImageBlob($ogData['image']);
                if ($blob) {
                    $embed['external']['thumb'] = $blob;
                    $this->log('Thumbnail uploaded successfully');
                }
            }
            
            // Post with hashtag and external embed
            $postText = '#سانا_زون';
            $facets = $this->extractHashtags($postText);
            
            if ($this->postToBluesky($postText, $facets, $embed)) {
                $this->markLinkAsPosted($link);
                $this->log('Posted link preview card: ' . $title);
                $newPosts++;
                
                // Only post one item per run to avoid rate limits
                break;
            } else {
                $this->log('ERROR: Failed to post: ' . $title);
            }
        }
        
        if ($newPosts === 0) {
            $this->log('No new items to post');
        }
    }
    
    /**
     * Run the bot
     */
    public function run()
    {
        $this->log('=== Sana Bot Starting ===');
        
        if (!$this->authenticate()) {
            $this->log('Bot stopped due to authentication failure');
            exit(1);
        }
        
        $this->processFeed();
        
        $this->log('=== Sana Bot Finished ===');
    }
}

// Run the bot
try {
    $bot = new SanaBot();
    $bot->run();
} catch (Exception $e) {
    error_log('FATAL ERROR: ' . $e->getMessage());
    exit(1);
}

