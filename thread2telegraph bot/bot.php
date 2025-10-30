<?php

require_once __DIR__ . '/config.php';

class ThreadToTelegraphBot
{
    private $db;
    private $accessJwt;
    private $did;

    public function __construct()
    {
        $this->initDatabase();
    }

    private function initDatabase()
    {
        try {
            $this->db = new SQLite3(DB_PATH);
            $this->db->exec('CREATE TABLE IF NOT EXISTS processed_mentions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_uri TEXT UNIQUE NOT NULL,
                processed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )');
        } catch (Exception $e) {
            $this->log('ERROR: Failed to initialize database: ' . $e->getMessage());
            exit(1);
        }
    }

    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        file_put_contents(LOG_PATH, $logMessage, FILE_APPEND);
        echo $logMessage;
    }

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
        if (!isset($data['accessJwt'], $data['did'])) {
            $this->log('ERROR: Invalid authentication response');
            return false;
        }
        $this->accessJwt = $data['accessJwt'];
        $this->did = $data['did'];
        $this->log('Authenticated as ' . BLUESKY_IDENTIFIER . ' (' . $this->did . ')');
        return true;
    }

    private function httpGetJson($url, $headers = [])
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || $response === false) {
            return [null, $httpCode, $response];
        }
        return [json_decode($response, true), 200, $response];
    }

    private function httpPostJson($url, $body, $headers = [])
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $headers = array_merge(['Content-Type: application/json'], $headers);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || $response === false) {
            return [null, $httpCode, $response];
        }
        return [json_decode($response, true), 200, $response];
    }

    private function listNotifications($limit = 50)
    {
        $url = BLUESKY_API_URL . '/app.bsky.notification.listNotifications?limit=' . (int)$limit;
        return $this->httpGetJson($url, ['Authorization: Bearer ' . $this->accessJwt]);
    }

    private function hasProcessed($postUri)
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM processed_mentions WHERE post_uri = :uri');
        $stmt->bindValue(':uri', $postUri, SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res->fetchArray(SQLITE3_ASSOC);
        return (int)$row['c'] > 0;
    }

    private function markProcessed($postUri)
    {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO processed_mentions (post_uri) VALUES (:uri)');
        $stmt->bindValue(':uri', $postUri, SQLITE3_TEXT);
        $stmt->execute();
    }

    private function textContainsTrigger($text)
    {
        if (!$text) {
            return false;
        }
        foreach (TRIGGER_WORDS as $word) {
            $pattern = '/(?<!\p{L})' . preg_quote($word, '/') . '(?!\p{L})/u';
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    private function getPostRecord($uri)
    {
        if (preg_match('/^at:\/\/([^\/]+)\/([^\/]+)\/(.+)$/', $uri, $matches)) {
            $did = $matches[1];
            $collection = $matches[2];
            $rkey = $matches[3];
            $url = BLUESKY_API_URL . '/com.atproto.repo.getRecord?repo=' . rawurlencode($did) . '&collection=' . rawurlencode($collection) . '&rkey=' . rawurlencode($rkey);
            return $this->httpGetJson($url, ['Authorization: Bearer ' . $this->accessJwt]);
        }
        return [null, 400, 'Invalid URI format'];
    }

    private function recordMentionsOurDid($record)
    {
        if (!isset($record['facets']) || !is_array($record['facets'])) {
            return false;
        }
        
        foreach ($record['facets'] as $facet) {
            if (!isset($facet['features'])) {
                continue;
            }
            foreach ($facet['features'] as $feature) {
                if (isset($feature['$type']) && $feature['$type'] === 'app.bsky.richtext.facet#mention') {
                    $mentionedDid = $feature['did'] ?? null;
                    if ($mentionedDid && $mentionedDid === $this->did) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }

    private function getPostThread($uri)
    {
        $url = BLUESKY_API_URL . '/app.bsky.feed.getPostThread?uri=' . rawurlencode($uri) . '&depth=10';
        return $this->httpGetJson($url, ['Authorization: Bearer ' . $this->accessJwt]);
    }

    private function collectPathRootToNode($thread)
    {
        if (!isset($thread['thread'])) {
            return [];
        }
        $node = $thread['thread'];
        $path = [];
        while ($node && isset($node['$type']) && $node['$type'] === 'app.bsky.feed.defs#threadViewPost') {
            $path[] = $node['post'];
            if (!isset($node['parent']) || !$node['parent']) {
                break;
            }
            $node = $node['parent'];
        }
        return array_reverse($path);
    }

    private function chunkText($text, $maxLength, $breakChars = [' ', '،', '.', '-', ':', ';', '!', '?'])
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return [$text];
        }
        $chunks = [];
        $remaining = $text;
        $minBreakPos = max(1, (int)($maxLength * 0.9));
        while (mb_strlen($remaining, 'UTF-8') > $maxLength) {
            $chunk = mb_substr($remaining, 0, $maxLength, 'UTF-8');
            $lastBreak = 0;
            foreach ($breakChars as $char) {
                $pos = mb_strrpos($chunk, $char, 0, 'UTF-8');
                if ($pos !== false && $pos > $lastBreak && $pos >= $minBreakPos) {
                    $lastBreak = $pos + mb_strlen($char, 'UTF-8');
                }
            }
            if ($lastBreak >= $minBreakPos) {
                $chunk = mb_substr($remaining, 0, $lastBreak, 'UTF-8');
                $remaining = mb_substr($remaining, $lastBreak, null, 'UTF-8');
            } else {
                $remaining = mb_substr($remaining, $maxLength, null, 'UTF-8');
            }
            $chunks[] = $chunk;
        }
        if (mb_strlen($remaining, 'UTF-8') > 0) {
            $chunks[] = $remaining;
        }
        return $chunks;
    }

    private function buildTelegraphContentFromPosts($posts, $excludeUri = null)
    {
        $content = [];
        $isFirstIncludedPost = true;
        foreach ($posts as $post) {
            if ($excludeUri && isset($post['uri']) && $post['uri'] === $excludeUri) {
                continue;
            }
            $author = $post['author']['handle'] ?? $post['author']['did'] ?? '';
            $text = (string)($post['record']['text'] ?? '');
            $facets = $post['record']['facets'] ?? null;
            
            // Add a separator between replies (but not before the first one)
            if (!$isFirstIncludedPost) {
                $content[] = [ 'tag' => 'hr' ];
            }

            $paragraphChildren = [];
            // Author will be rendered on a separate line below the reply
            
            if (!is_array($facets) || empty($facets)) {
                if ($text !== '') {
                    $cleaned = str_replace(["\r\n", "\r", "\n"], ' ', trim($text));
                    if ($cleaned !== '') {
                        $chunks = $this->chunkText($cleaned, 2000, [' ', '،', '.', '-', ':', ';']);
                        foreach ($chunks as $chunk) {
                            $paragraphChildren[] = $chunk;
                        }
                    }
                }
            } else {
                $bytes = $text;
                $last = 0;
                foreach ($facets as $facet) {
                    if (!isset($facet['index'])) continue;
                    $start = (int)$facet['index']['byteStart'];
                    $end = (int)$facet['index']['byteEnd'];
                    
                    if ($start > $last) {
                        $preText = substr($bytes, $last, $start - $last);
                        $preText = str_replace(["\r\n", "\r", "\n"], ' ', trim($preText));
                        if ($preText !== '') {
                            $paragraphChildren[] = $preText;
                        }
                    }
                    
                    $segmentText = substr($bytes, $start, $end - $start);
                    $segmentText = str_replace(["\r\n", "\r", "\n"], ' ', trim($segmentText));
                    $isLink = false;
                    if (isset($facet['features']) && is_array($facet['features'])) {
                        foreach ($facet['features'] as $feature) {
                            if ($feature['$type'] === 'app.bsky.richtext.facet#link' && isset($feature['uri'])) {
                                $paragraphChildren[] = [
                                    'tag' => 'a',
                                    'attrs' => ['href' => $feature['uri']],
                                    'children' => [$segmentText]
                                ];
                                $isLink = true;
                                break;
                            } elseif ($feature['$type'] === 'app.bsky.richtext.facet#tag' && isset($feature['tag'])) {
                                $tagText = '#' . $segmentText;
                                $paragraphChildren[] = [
                                    'tag' => 'a',
                                    'attrs' => ['href' => 'https://bsky.app/hashtag/' . rawurlencode($feature['tag'])],
                                    'children' => [$tagText]
                                ];
                                $isLink = true;
                                break;
                            } elseif ($feature['$type'] === 'app.bsky.richtext.facet#mention' && isset($feature['did'])) {
                                $paragraphChildren[] = [
                                    'tag' => 'a',
                                    'attrs' => ['href' => 'https://bsky.app/profile/' . rawurlencode($feature['did'])],
                                    'children' => [$segmentText]
                                ];
                                $isLink = true;
                                break;
                            }
                        }
                    }
                    if (!$isLink) {
                        $paragraphChildren[] = $segmentText;
                    }
                    $last = $end;
                }
                
                if ($last < strlen($bytes)) {
                    $remaining = substr($bytes, $last);
                    $remaining = str_replace(["\r\n", "\r", "\n"], ' ', trim($remaining));
                    if ($remaining !== '') {
                        $paragraphChildren[] = $remaining;
                    }
                }
            }
            
            if (empty($paragraphChildren)) {
                $paragraphChildren[] = '';
            }
            
            $mergedChildren = [];
            $currentText = '';
            
            foreach ($paragraphChildren as $child) {
                if (is_string($child)) {
                    $currentText .= $child;
                } else {
                    if ($currentText !== '') {
                        $chunks = $this->chunkText($currentText, 300);
                        foreach ($chunks as $chunk) {
                            $mergedChildren[] = $chunk;
                        }
                        $currentText = '';
                    }
                    $mergedChildren[] = $child;
                }
            }
            
            if ($currentText !== '') {
                $chunks = $this->chunkText($currentText, 300);
                foreach ($chunks as $chunk) {
                    $mergedChildren[] = $chunk;
                }
            }
            
            $paragraphChildren = $mergedChildren;
            
            // Main reply text paragraph
            $content[] = [
                'tag' => 'p',
                'children' => $paragraphChildren
            ];

            // Author line directly below the reply text
            if ($author && $text !== '') {
                $content[] = [
                    'tag' => 'p',
                    'attrs' => ['dir' => 'ltr'],
                    'children' => [
                        [ 'tag' => 'em', 'children' => ['— '] ],
                        [ 'tag' => 'strong', 'children' => ['@' . $author] ]
                    ]
                ];
            }

            if (isset($post['embed']['$type']) && $post['embed']['$type'] === 'app.bsky.embed.images#view') {
                $images = $post['embed']['images'] ?? [];
                foreach ($images as $img) {
                    $fullUrl = $img['fullsize'] ?? ($img['thumb'] ?? null);
                    if ($fullUrl) {
                        $telegraphImagePath = $this->uploadImageToTelegraph($fullUrl);
                        if ($telegraphImagePath) {
                            $content[] = [
                                'tag' => 'figure',
                                'children' => [
                                    ['tag' => 'img', 'attrs' => ['src' => $telegraphImagePath]],
                                ]
                            ];
                        } else {
                            $this->log('WARNING: Failed to upload image, falling back to direct URL');
                            $content[] = [
                                'tag' => 'figure',
                                'children' => [
                                    ['tag' => 'img', 'attrs' => ['src' => $fullUrl]],
                                ]
                            ];
                        }
                    }
                }
            }

            if (isset($post['embed']['$type']) && $post['embed']['$type'] === 'app.bsky.embed.external#view') {
                $ext = $post['embed']['external'] ?? null;
                if ($ext && isset($ext['uri'])) {
                    $title = $ext['title'] ?? $ext['uri'];
                    $content[] = [
                        'tag' => 'p',
                        'children' => [
                            [
                                'tag' => 'a',
                                'attrs' => ['href' => $ext['uri']],
                                'children' => [$title]
                            ]
                        ]
                    ];
                }
            }

            // Mark that we've added the first included post
            if ($isFirstIncludedPost) {
                $isFirstIncludedPost = false;
            }
        }
        return $content;
    }

    private function mergeAdjacentTextNodes($children)
    {
        $merged = [];
        $currentText = '';
        
        foreach ($children as $child) {
            if (is_string($child)) {
                if (mb_strlen($currentText, 'UTF-8') > 100) {
                    $merged[] = $currentText;
                    $currentText = $child;
                } else {
                    $newText = $currentText . $child;
                    if (mb_strlen($newText, 'UTF-8') > 100) {
                        if ($currentText !== '') {
                            $merged[] = $currentText;
                        }
                        $currentText = $child;
                    } else {
                        $currentText = $newText;
                    }
                }
            } else {
                if ($currentText !== '') {
                    $merged[] = $currentText;
                    $currentText = '';
                }
                $merged[] = $child;
            }
        }
        
        if ($currentText !== '') {
            $merged[] = $currentText;
        }
        
        return $merged;
    }

    private function cleanTelegraphNode($node)
    {
        if (is_string($node)) {
            if ($node === '') {
                return null;
            }
            if (!mb_check_encoding($node, 'UTF-8')) {
                $node = mb_convert_encoding($node, 'UTF-8', mb_detect_encoding($node));
            }
            if (mb_strlen($node, 'UTF-8') > 5000) {
                $chunks = $this->chunkText($node, 5000, [' ']);
                return count($chunks) === 1 ? $chunks[0] : $chunks;
            }
            return $node;
        }
        
        if (!is_array($node)) {
            return null;
        }
        
        if (isset($node['tag'])) {
            $tag = $node['tag'];
            $cleaned = ['tag' => $tag];
            
            if (isset($node['attrs']) && is_array($node['attrs']) && !empty($node['attrs'])) {
                $cleaned['attrs'] = $node['attrs'];
            }
            
            $voidTags = ['br', 'hr', 'img'];
            if (!in_array($tag, $voidTags)) {
                if (isset($node['children']) && is_array($node['children'])) {
                    $cleanedChildren = [];
                    foreach ($node['children'] as $child) {
                        if (is_array($child) && isset($child['tag']) && $child['tag'] === 'br') {
                            continue;
                        }
                        $cleanedChild = $this->cleanTelegraphNode($child);
                        if ($cleanedChild !== null) {
                            if (is_array($cleanedChild) && !isset($cleanedChild['tag'])) {
                                foreach ($cleanedChild as $chunk) {
                                    if ($chunk !== '') {
                                        $cleanedChildren[] = $chunk;
                                    }
                                }
                            } else {
                                $cleanedChildren[] = $cleanedChild;
                            }
                        }
                    }
                    
                    $cleanedChildren = $this->mergeAdjacentTextNodes($cleanedChildren);
                    $cleanedChildren = array_filter($cleanedChildren, function($child) {
                        return !(is_string($child) && $child === '');
                    });
                    $cleanedChildren = array_values($cleanedChildren);
                    
                    if (!empty($cleanedChildren)) {
                        $cleaned['children'] = $cleanedChildren;
                    } elseif ($tag === 'p') {
                        $cleaned['children'] = [' '];
                    }
                } elseif ($tag === 'p') {
                    $cleaned['children'] = [' '];
                }
            }
            return $cleaned;
        }
        
        return null;
    }


    private function uploadImageToTelegraph($imageUrl)
    {
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; Thread2TelegraphBot/1.0)');
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$imageData) {
            $this->log('WARNING: Failed to download image from ' . $imageUrl);
            return null;
        }
        
        if (strpos($contentType, 'image') === false) {
            $this->log('WARNING: URL is not an image: ' . $contentType);
            return null;
        }
        
        $tempFile = tmpfile();
        if (!$tempFile) {
            $this->log('WARNING: Failed to create temporary file for image');
            return null;
        }
        
        fwrite($tempFile, $imageData);
        rewind($tempFile);
        $tempPath = stream_get_meta_data($tempFile)['uri'];
        
        $ch = curl_init('https://telegra.ph/upload');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => new CURLFile($tempPath, $contentType, basename($imageUrl))
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            $errorMsg = 'WARNING: Failed to upload image to Telegraph. HTTP ' . $httpCode;
            if ($curlError) {
                $errorMsg .= ' CURL Error: ' . $curlError;
            }
            if ($response) {
                $errorMsg .= ' Response: ' . substr($response, 0, 200);
            }
            $this->log($errorMsg);
            fclose($tempFile);
            return null;
        }
        
        fclose($tempFile);
        
        $data = json_decode($response, true);
        if (!$data) {
            $this->log('WARNING: Invalid Telegraph upload response (not JSON): ' . substr($response, 0, 200));
            return null;
        }
        
        $imageSrc = null;
        if (isset($data[0]['src'])) {
            $imageSrc = $data[0]['src'];
        } elseif (isset($data['src'])) {
            $imageSrc = $data['src'];
        }
        
        if (!$imageSrc) {
            $this->log('WARNING: Invalid Telegraph upload response format: ' . json_encode($data));
            return null;
        }
        
        if (strpos($imageSrc, 'http') !== 0) {
            $imageSrc = 'https://telegra.ph' . $imageSrc;
        }
        
        $this->log('Image uploaded successfully: ' . $imageSrc);
        return $imageSrc;
    }

    private function getOrCreateTelegraphToken()
    {
        if (!empty(TELEGRAPH_ACCESS_TOKEN)) {
            return TELEGRAPH_ACCESS_TOKEN;
        }
        $tokenFile = __DIR__ . '/telegraph_token.txt';
        $tokenCreatedFile = __DIR__ . '/telegraph_token_created.txt';
        if (file_exists($tokenFile)) {
            $token = trim(file_get_contents($tokenFile));
            if (!empty($token)) {
                $tokenCreated = 0;
                if (file_exists($tokenCreatedFile)) {
                    $tokenCreated = (int)trim(file_get_contents($tokenCreatedFile));
                }
                $timeSinceCreation = time() - $tokenCreated;
                $minAge = 3600;
                
                if ($timeSinceCreation < $minAge && $tokenCreated > 0) {
                    $this->log("WARNING: Telegraph token is " . round($timeSinceCreation / 60) . " minutes old. Telegraph may reject pages from newly created accounts (< 1 hour). Set TELEGRAPH_ACCESS_TOKEN in .env for production.");
                }
                return $token;
            }
        }
        
        $this->log('No Telegraph access token provided, creating anonymous account...');
        $ch = curl_init(TELEGRAPH_API_URL . '/createAccount');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'short_name' => TELEGRAPH_AUTHOR_NAME ?: 'Thread Compiler',
            'author_name' => TELEGRAPH_AUTHOR_NAME ?: 'Thread Compiler',
            'author_url' => TELEGRAPH_AUTHOR_URL ?: ''
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['ok']) && $data['ok'] && isset($data['result']['access_token'])) {
                $token = $data['result']['access_token'];
                file_put_contents($tokenFile, $token);
                file_put_contents($tokenCreatedFile, time());
                $this->log('Created anonymous Telegraph account and saved token to ' . $tokenFile);
                $this->log('WARNING: Telegraph may reject pages from newly created accounts for up to 1 hour. Set TELEGRAPH_ACCESS_TOKEN in .env for production use.');
                return $token;
            }
        }
        
        $this->log('WARNING: Failed to create anonymous account. Telegraph posts may fail.');
        return null;
    }

    private function telegraphCreatePage($title, $content, $authorName = null, $authorUrl = null)
    {
        $accessToken = $this->getOrCreateTelegraphToken();
        if (!$accessToken) {
            return [null, 400, 'No access token available'];
        }
        $titleCleaned = trim($title);
        if (!mb_check_encoding($titleCleaned, 'UTF-8')) {
            $titleCleaned = mb_convert_encoding($titleCleaned, 'UTF-8', mb_detect_encoding($titleCleaned));
        }
        $titleCleaned = mb_substr($titleCleaned, 0, 256, 'UTF-8');
        
        $payload = [
            'title' => $titleCleaned,
            'access_token' => $accessToken,
            'return_content' => false
        ];
        
        if (!empty($authorName)) {
            $payload['author_name'] = substr($authorName, 0, 128);
        } elseif (!empty(TELEGRAPH_AUTHOR_NAME)) {
            $payload['author_name'] = substr(TELEGRAPH_AUTHOR_NAME, 0, 128);
        } else {
            $payload['author_name'] = 'Anonymous';
        }
        
        if (!empty($authorUrl)) {
            $payload['author_url'] = substr($authorUrl, 0, 512);
        } elseif (!empty(TELEGRAPH_AUTHOR_URL)) {
            $payload['author_url'] = substr(TELEGRAPH_AUTHOR_URL, 0, 512);
        }
        $cleanedContent = [];
        foreach ($content as $node) {
            $cleaned = $this->cleanTelegraphNode($node);
            if ($cleaned !== null) {
                $cleanedContent[] = $cleaned;
            }
        }
        
        if (empty($cleanedContent)) {
            $this->log('ERROR: No valid content nodes after cleaning');
            return [null, 400, 'No valid content'];
        }
        
        $contentJson = json_encode($cleanedContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($contentJson === false || json_last_error() !== JSON_ERROR_NONE) {
            $this->log('ERROR: Failed to encode content as JSON: ' . json_last_error_msg());
            return [null, 400, 'JSON encoding failed'];
        }
        
        if (strlen($contentJson) > 65536) {
            $this->log('ERROR: Content JSON exceeds 64KB limit: ' . strlen($contentJson) . ' bytes');
            return [null, 400, 'Content too large'];
        }
        
        $payload['content'] = $contentJson;
        
        $testDecode = json_decode($payload['content'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('ERROR: Content JSON is invalid: ' . json_last_error_msg());
            return [null, 400, 'Invalid JSON content'];
        }

        $ch = curl_init(TELEGRAPH_API_URL . '/createPage');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            $this->log('ERROR: Telegraph API request failed with HTTP ' . $httpCode);
            return [null, $httpCode, $response];
        }
        $data = json_decode($response, true);
        if (!isset($data['ok']) || !$data['ok']) {
            $error = $data['error'] ?? 'Unknown error';
            $this->log('ERROR: Telegraph API error: ' . $error);
            return [null, $httpCode, $response];
        }
        return [$data['result']['url'] ?? null, 200, $response];
    }

    private function uploadImageBlobToBluesky($imageUrl)
    {
        $ch = curl_init($imageUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; Thread2TelegraphBot/1.0)');
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$imageData) {
            $this->log('WARNING: Failed to download thumbnail from ' . $imageUrl);
            return null;
        }
        
        if (strpos($contentType, 'image') === false) {
            $this->log('WARNING: URL is not an image: ' . $contentType);
            return null;
        }
        
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
            return null;
        }
        
        $data = json_decode($response, true);
        if (!isset($data['blob'])) {
            $this->log('WARNING: Invalid blob upload response');
            return null;
        }
        
        return $data['blob'];
    }

    private function getTelegraphPageInfo($url)
    {
        if (preg_match('/telegra\.ph\/([^\/\s]+)/', $url, $matches)) {
            $path = $matches[1];
            $infoUrl = 'https://api.telegra.ph/getPage?path=' . rawurlencode($path) . '&return_content=false';
            list($data, $code, $raw) = $this->httpGetJson($infoUrl);
            if ($code === 200 && isset($data['ok']) && $data['ok'] && isset($data['result'])) {
                return $data['result'];
            }
        }
        return null;
    }

    private function replyWithUrl($rootUri, $parentUri, $url, $rootCid = null, $parentCid = null)
    {
        // If CIDs not provided, fetch them
        if (!$rootCid) {
            list($recordData, $code, $raw) = $this->getPostRecord($rootUri);
            if ($code === 200 && isset($recordData['cid'])) {
                $rootCid = $recordData['cid'];
            }
        }
        if (!$parentCid) {
            list($recordData, $code, $raw) = $this->getPostRecord($parentUri);
            if ($code === 200 && isset($recordData['cid'])) {
                $parentCid = $recordData['cid'];
            }
        }
        
        if (!$rootCid || !$parentCid) {
            $this->log('ERROR: Failed to get CIDs for root or parent post');
            return false;
        }
        
        // Fetch Telegraph page info for title and thumbnail
        $pageInfo = $this->getTelegraphPageInfo($url);
        $pageTitle = $pageInfo['title'] ?? 'Thread Archive';
        $pageDescription = $pageInfo['description'] ?? 'View the compiled thread on Telegraph';
        $thumbnail = null;
        
        // Fetch page content to get first image for thumbnail
        if (preg_match('/telegra\.ph\/([^\/\s]+)/', $url, $matches)) {
            $path = $matches[1];
            $contentUrl = 'https://api.telegra.ph/getPage?path=' . rawurlencode($path) . '&return_content=true';
            list($contentData, $contentCode, $contentRaw) = $this->httpGetJson($contentUrl);
            if ($contentCode === 200 && isset($contentData['ok']) && $contentData['ok'] && isset($contentData['result']['content'])) {
                $pageContent = $contentData['result']['content'];
                foreach ($pageContent as $node) {
                    if (isset($node['tag']) && $node['tag'] === 'img' && isset($node['attrs']['src'])) {
                    $imgSrc = $node['attrs']['src'];
                    if (strpos($imgSrc, 'http') !== 0) {
                        $imgSrc = 'https://telegra.ph' . $imgSrc;
                    }
                    $thumbnail = $imgSrc;
                    break;
                }
                if (isset($node['tag']) && $node['tag'] === 'figure' && isset($node['children'])) {
                    foreach ($node['children'] as $child) {
                        if (isset($child['tag']) && $child['tag'] === 'img' && isset($child['attrs']['src'])) {
                            $imgSrc = $child['attrs']['src'];
                            if (strpos($imgSrc, 'http') !== 0) {
                                $imgSrc = 'https://telegra.ph' . $imgSrc;
                            }
                            $thumbnail = $imgSrc;
                            break 2;
                        }
                    }
                }
                }
            }
        }
        
        $postText = 'تفضل معلم هي السرد';
        $hashtag = '';
        if (!empty(REPLY_HASHTAG)) {
            $hashtag = ' #' . REPLY_HASHTAG;
            $postText .= $hashtag;
        }
        
        $facets = [];
        if (!empty(REPLY_HASHTAG)) {
            $hashtagText = '#' . REPLY_HASHTAG;
            $hashtagPos = mb_strpos($postText, $hashtagText, 0, 'UTF-8');
            if ($hashtagPos !== false) {
                $byteStart = strlen(mb_substr($postText, 0, $hashtagPos, 'UTF-8'));
                $byteEnd = $byteStart + strlen($hashtagText);
                $facets[] = [
                    'index' => [
                        'byteStart' => $byteStart,
                        'byteEnd' => $byteEnd
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#tag',
                            'tag' => REPLY_HASHTAG
                        ]
                    ]
                ];
            }
        }
        
        $embed = [
            '$type' => 'app.bsky.embed.external',
            'external' => [
                'uri' => $url,
                'title' => $pageTitle,
                'description' => $pageDescription
            ]
        ];
        
        // Upload thumbnail to Bluesky if available
        if ($thumbnail) {
            $thumbBlob = $this->uploadImageBlobToBluesky($thumbnail);
            if ($thumbBlob) {
                $embed['external']['thumb'] = $thumbBlob;
            }
        }
        
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $postText,
            'facets' => empty($facets) ? null : $facets,
            'embed' => $embed,
            'createdAt' => date('c'),
            'reply' => [
                'root' => ['uri' => $rootUri, 'cid' => $rootCid],
                'parent' => ['uri' => $parentUri, 'cid' => $parentCid]
            ]
        ];
        if (DRY_RUN) {
            $this->log('[DRY_RUN] Would reply with: ' . $url);
            return true;
        }
        list($data, $code, $raw) = $this->httpPostJson(BLUESKY_API_URL . '/com.atproto.repo.createRecord', [
            'repo' => $this->did,
            'collection' => 'app.bsky.feed.post',
            'record' => $record
        ], ['Authorization: Bearer ' . $this->accessJwt]);
        if ($code !== 200) {
            $this->log('ERROR: Failed to reply. HTTP ' . $code . ' ' . $raw);
            return false;
        }
        return true;
    }
    

    private function processMention($notif)
    {
        $postUri = $notif['uri'];
        if ($this->hasProcessed($postUri)) {
            $this->log('Skipping already processed mention: ' . $postUri);
            return false;
        }

        $record = $notif['record'] ?? [];
        $text = $record['text'] ?? '';
        
        if (!isset($record['facets']) || !is_array($record['facets'])) {
            $this->log('Fetching full record for mention: ' . $postUri);
            list($recordData, $code, $raw) = $this->getPostRecord($postUri);
            if ($code === 200 && isset($recordData['value'])) {
                $record = $recordData['value'];
                $text = $record['text'] ?? '';
                $this->log('Successfully fetched full record');
            } else {
                $this->log('WARNING: Failed to fetch full record. HTTP ' . $code . '. Record data may be incomplete.');
            }
        }
        
        $this->log('Checking mention: URI=' . $postUri . ', Text=' . mb_substr($text, 0, 50) . '..., Bot DID=' . $this->did);
        
        if (!$this->recordMentionsOurDid($record)) {
            $this->log('Mention check failed: Post does not mention our DID');
            return false;
        }
        
        if (!$this->textContainsTrigger($text)) {
            $this->log('Trigger check failed: No trigger word found in text');
            return false;
        }

        $this->log('Processing mention: ' . $postUri);
        list($thread, $code, $raw) = $this->getPostThread($postUri);
        if ($code !== 200 || !$thread) {
            $this->log('ERROR: getPostThread failed HTTP ' . $code);
            return false;
        }
        $posts = $this->collectPathRootToNode($thread);
        if (empty($posts)) {
            $this->log('WARNING: No posts collected for thread');
            return false;
        }

        $titleSource = $posts[0]['record']['text'] ?? 'Bluesky Thread';
        $title = mb_substr(trim(preg_replace('/\s+/', ' ', $titleSource)), 0, 90);
        $uniqueSuffix = ' • ' . substr(md5($postUri . time()), 0, 8);
        $title = $title . $uniqueSuffix;
        $content = $this->buildTelegraphContentFromPosts($posts, $postUri);
        if (empty($content)) {
            $content = [['tag' => 'p', 'children' => ['(empty)']]];
        }

        $rootAuthor = $posts[0]['author'] ?? null;
        $rootPost = $posts[0] ?? null;
        $authorName = null;
        $authorUrl = null;
        if ($rootAuthor) {
            $authorName = $rootAuthor['displayName'] ?? $rootAuthor['handle'] ?? null;
        }
        if ($rootPost && isset($rootPost['uri'])) {
            $uri = $rootPost['uri'];
            if (preg_match('/at:\/\/[^\/]+\/app\.bsky\.feed\.post\/(.+)/', $uri, $matches)) {
                $rkey = $matches[1];
                $handle = $rootAuthor['handle'] ?? null;
                if ($handle) {
                    $authorUrl = 'https://bsky.app/profile/' . rawurlencode($handle) . '/post/' . rawurlencode($rkey);
                }
            }
        }

        if (DRY_RUN) {
            $this->log('[DRY_RUN] Would create Telegraph page with title: ' . $title);
            $url = 'https://telegra.ph/dry-run';
        } else {
            list($url, $tcode, $traw) = $this->telegraphCreatePage($title, $content, $authorName, $authorUrl);
            if (!$url) {
                $this->log('ERROR: Telegraph createPage failed HTTP ' . $tcode . ' ' . $traw);
                return false;
            }
        }

        $rootUri = $posts[0]['uri'];
        $rootCid = $posts[0]['cid'] ?? null;
        
        $parentCid = null;
        foreach ($posts as $post) {
            if (isset($post['uri']) && $post['uri'] === $postUri) {
                $parentCid = $post['cid'] ?? null;
                break;
            }
        }
        
        $ok = $this->replyWithUrl($rootUri, $postUri, $url, $rootCid, $parentCid);
        if ($ok) {
            $this->markProcessed($postUri);
            $this->log('Replied with Telegraph URL: ' . $url);
            return true;
        }
        return false;
    }

    private function processNotifications()
    {
        list($data, $code, $raw) = $this->listNotifications(50);
        if ($code !== 200 || !$data) {
            $this->log('ERROR: listNotifications failed HTTP ' . $code);
            return;
        }
        $items = $data['notifications'] ?? [];
        $this->log('Found ' . count($items) . ' notifications');
        
        $potentialMentions = [];
        foreach ($items as $notif) {
            $postUri = $notif['uri'] ?? null;
            if (!$postUri) {
                continue;
            }
            
            // Skip already processed mentions early
            if ($this->hasProcessed($postUri)) {
                continue;
            }
            
            $reason = $notif['reason'] ?? '';
            $record = $notif['record'] ?? [];
            
            // Quick check: if reason is 'mention', it's likely a mention
            // Otherwise, check facets if available
            $hasMention = false;
            
            if ($reason === 'mention') {
                // For mention notifications, check if it mentions our DID
                if (!empty($record['facets'])) {
                    foreach ($record['facets'] as $facet) {
                        if (!isset($facet['features'])) continue;
                        foreach ($facet['features'] as $feature) {
                            if (isset($feature['$type']) && 
                                $feature['$type'] === 'app.bsky.richtext.facet#mention' &&
                                isset($feature['did']) && 
                                $feature['did'] === $this->did) {
                                $hasMention = true;
                                break 2;
                            }
                        }
                    }
                } else {
                    // No facets in notification, will fetch in processMention
                    $hasMention = true;
                }
            }
            
            if ($hasMention) {
                $potentialMentions[] = $notif;
            }
        }
        
        $this->log('Found ' . count($potentialMentions) . ' potential mentions');
        
        $processed = 0;
        foreach ($potentialMentions as $notif) {
            if ($processed >= MAX_MENTIONS_PER_RUN) {
                $this->log('Reached max mentions per run limit (' . MAX_MENTIONS_PER_RUN . ')');
                break;
            }
            $ok = $this->processMention($notif);
            if ($ok) {
                $processed++;
            }
        }
        if ($processed === 0) {
            $this->log('No qualifying mentions to process');
        } else {
            $this->log('Processed mentions: ' . $processed);
        }
    }

    public function run()
    {
        $this->log('=== Thread→Telegraph Bot Starting ===');
        if (!$this->authenticate()) {
            $this->log('Stopped due to authentication failure');
            exit(1);
        }
        $this->processNotifications();
        $this->log('=== Thread→Telegraph Bot Finished ===');
    }
}

try {
    $bot = new ThreadToTelegraphBot();
    $bot->run();
} catch (Exception $e) {
    error_log('FATAL ERROR: ' . $e->getMessage());
    exit(1);
}


