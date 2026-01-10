<?php
/**
 * Backlink Research API
 *
 * Searches for backlinks to a competitor domain using multiple methods.
 * Returns a list of potential linking pages.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Load configuration
$configPath = dirname(__DIR__) . '/config.yaml';
$config = null;

if (file_exists($configPath)) {
    $content = file_get_contents($configPath);
    $config = ['admin' => ['secret_path' => 'ccan-admin-2024']];
    if (preg_match('/secret_path:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
        $config['admin']['secret_path'] = trim($matches[1]);
    }
}

$secretPath = $config['admin']['secret_path'] ?? 'ccan-admin-2024';

// Check authentication
$providedKey = $_GET['key'] ?? '';
if ($providedKey !== $secretPath) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get the domain to research
$domain = $_GET['domain'] ?? '';
$domain = preg_replace('/^https?:\/\//', '', $domain);
$domain = preg_replace('/^www\./', '', $domain);
$domain = rtrim($domain, '/');

if (empty($domain)) {
    echo json_encode(['error' => 'No domain provided']);
    exit();
}

// Validate domain format
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*\.[a-zA-Z]{2,}$/', $domain)) {
    echo json_encode(['error' => 'Invalid domain format']);
    exit();
}

$results = [];
$errors = [];

// Method 1: Common Crawl Index API (free, large dataset)
function searchCommonCrawl($domain) {
    $results = [];

    // Get the latest Common Crawl index
    $indexListUrl = 'https://index.commoncrawl.org/collinfo.json';
    $indexList = @file_get_contents($indexListUrl);

    if (!$indexList) {
        return ['error' => 'Could not fetch Common Crawl index list'];
    }

    $indexes = json_decode($indexList, true);
    if (empty($indexes)) {
        return ['error' => 'No Common Crawl indexes available'];
    }

    // Use the most recent index
    $latestIndex = $indexes[0]['cdx-api'];

    // Search for pages linking to this domain
    // Note: Common Crawl indexes pages, not links directly
    // We search for pages that mention the domain
    $searchUrl = $latestIndex . '?url=*.' . urlencode($domain) . '&output=json&limit=100';

    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'BacklinkResearchBot/1.0'
        ]
    ]);

    $response = @file_get_contents($searchUrl, false, $context);

    if ($response) {
        $lines = explode("\n", trim($response));
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data && isset($data['url'])) {
                $results[] = [
                    'url' => $data['url'],
                    'source' => 'CommonCrawl',
                    'timestamp' => $data['timestamp'] ?? null,
                    'status' => $data['status'] ?? null
                ];
            }
        }
    }

    return $results;
}

// Method 2: Search for mentions using Bing (via scraping - may not always work)
function searchBingMentions($domain) {
    $results = [];

    // Search query: pages that mention the domain but aren't on the domain
    $query = '"' . $domain . '" -site:' . $domain;
    $url = 'https://www.bing.com/search?q=' . urlencode($query) . '&count=50';

    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'header' => "Accept: text/html\r\n"
        ]
    ]);

    $html = @file_get_contents($url, false, $context);

    if ($html) {
        // Extract URLs from search results
        preg_match_all('/<a[^>]+href="(https?:\/\/[^"]+)"[^>]*>/i', $html, $matches);

        if (!empty($matches[1])) {
            $seen = [];
            foreach ($matches[1] as $foundUrl) {
                // Skip Bing's own URLs and the target domain
                if (strpos($foundUrl, 'bing.com') !== false) continue;
                if (strpos($foundUrl, 'microsoft.com') !== false) continue;
                if (strpos($foundUrl, $domain) !== false) continue;
                if (isset($seen[$foundUrl])) continue;

                $seen[$foundUrl] = true;
                $results[] = [
                    'url' => $foundUrl,
                    'source' => 'Bing Search',
                    'type' => 'mention'
                ];
            }
        }
    }

    return $results;
}

// Method 3: Generate search queries for manual research
function generateSearchQueries($domain) {
    return [
        [
            'name' => 'Google: Exact mentions',
            'query' => '"' . $domain . '" -site:' . $domain,
            'url' => 'https://www.google.com/search?q=' . urlencode('"' . $domain . '" -site:' . $domain)
        ],
        [
            'name' => 'Google: Link operator',
            'query' => 'link:' . $domain,
            'url' => 'https://www.google.com/search?q=' . urlencode('link:' . $domain)
        ],
        [
            'name' => 'Bing: Mentions',
            'query' => '"' . $domain . '" -site:' . $domain,
            'url' => 'https://www.bing.com/search?q=' . urlencode('"' . $domain . '" -site:' . $domain)
        ],
        [
            'name' => 'Ahrefs Free Checker',
            'query' => $domain,
            'url' => 'https://ahrefs.com/backlink-checker?input=' . urlencode($domain)
        ],
        [
            'name' => 'Moz Link Explorer',
            'query' => $domain,
            'url' => 'https://moz.com/link-explorer?site=' . urlencode('https://' . $domain)
        ],
        [
            'name' => 'OpenLinkProfiler',
            'query' => $domain,
            'url' => 'https://openlinkprofiler.org/r/' . urlencode($domain)
        ]
    ];
}

// Method 4: Check OpenLinkProfiler (free backlink checker)
function checkOpenLinkProfiler($domain) {
    $results = [];

    $url = 'https://openlinkprofiler.org/r/' . urlencode($domain);

    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'header' => "Accept: text/html\r\n"
        ]
    ]);

    $html = @file_get_contents($url, false, $context);

    if ($html) {
        // Try to extract backlink data from the page
        // This is fragile and may break if the site changes
        preg_match_all('/class="link-url"[^>]*>([^<]+)</i', $html, $matches);

        if (!empty($matches[1])) {
            foreach (array_slice($matches[1], 0, 50) as $foundUrl) {
                $results[] = [
                    'url' => trim($foundUrl),
                    'source' => 'OpenLinkProfiler',
                    'type' => 'backlink'
                ];
            }
        }
    }

    return $results;
}

// Execute searches
$allResults = [];
$searchQueries = generateSearchQueries($domain);

// Try Common Crawl (most reliable free source)
try {
    $ccResults = searchCommonCrawl($domain);
    if (is_array($ccResults) && !isset($ccResults['error'])) {
        $allResults = array_merge($allResults, $ccResults);
    } else {
        $errors[] = $ccResults['error'] ?? 'Common Crawl search failed';
    }
} catch (Exception $e) {
    $errors[] = 'Common Crawl: ' . $e->getMessage();
}

// Try Bing search for mentions
try {
    $bingResults = searchBingMentions($domain);
    if (!empty($bingResults)) {
        $allResults = array_merge($allResults, $bingResults);
    }
} catch (Exception $e) {
    $errors[] = 'Bing: ' . $e->getMessage();
}

// Deduplicate results by URL
$seen = [];
$uniqueResults = [];
foreach ($allResults as $result) {
    $urlKey = strtolower($result['url']);
    if (!isset($seen[$urlKey])) {
        $seen[$urlKey] = true;
        $uniqueResults[] = $result;
    }
}

// Sort by source
usort($uniqueResults, function($a, $b) {
    return strcmp($a['source'], $b['source']);
});

// Return results
echo json_encode([
    'success' => true,
    'domain' => $domain,
    'results' => $uniqueResults,
    'count' => count($uniqueResults),
    'searchQueries' => $searchQueries,
    'errors' => $errors,
    'note' => 'For comprehensive backlink data, use the search queries to check professional tools like Ahrefs, Moz, or SEMrush.'
]);
