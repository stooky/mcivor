<?php
/**
 * C-Can Sam Admin Panel
 *
 * View contact form submissions and Google reviews
 * Access via: /api/admin.php?key=YOUR_SECRET_PATH
 */

// Load configuration
$configPath = dirname(__DIR__) . '/config.yaml';
$localConfigPath = dirname(__DIR__) . '/config.local.yaml';
$reviewsPath = dirname(__DIR__) . '/data/reviews.json';
$config = null;

if (file_exists($configPath)) {
    if (function_exists('yaml_parse_file')) {
        $config = yaml_parse_file($configPath);
    } else {
        // Simple parser
        $content = file_get_contents($configPath);
        $config = ['admin' => ['secret_path' => 'ccan-admin-2024', 'per_page' => 50]];
        if (preg_match('/secret_path:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
            $config['admin']['secret_path'] = trim($matches[1]);
        }
        if (preg_match('/per_page:\s*(\d+)/', $content, $matches)) {
            $config['admin']['per_page'] = (int)$matches[1];
        }
        if (preg_match('/submissions_file:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
            $config['logging']['submissions_file'] = trim($matches[1]);
        }
    }
}

// Merge local overrides if they exist
if (file_exists($localConfigPath)) {
    if (function_exists('yaml_parse_file')) {
        $localConfig = yaml_parse_file($localConfigPath);
    } else {
        $content = file_get_contents($localConfigPath);
        $localConfig = [];
        if (preg_match('/secret_path:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
            $localConfig['admin']['secret_path'] = trim($matches[1]);
        }
    }
    if ($localConfig) {
        $config = array_replace_recursive($config ?? [], $localConfig);
    }
}

$secretPath = $config['admin']['secret_path'] ?? 'ccan-admin-2024';
$perPage = $config['admin']['per_page'] ?? 100;
$logFile = dirname(__DIR__) . '/' . ($config['logging']['submissions_file'] ?? 'data/submissions.json');

// Check authentication
$providedKey = $_GET['key'] ?? '';
if ($providedKey !== $secretPath) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>';
    exit();
}

// Load all submissions
$allSubmissions = [];
if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    $allSubmissions = json_decode($content, true) ?? [];
}

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = $_POST['delete_id'];
    $allSubmissions = array_filter($allSubmissions, fn($s) => $s['id'] !== $deleteId);
    $allSubmissions = array_values($allSubmissions);
    file_put_contents($logFile, json_encode($allSubmissions, JSON_PRETTY_PRINT));
    header('Location: ?key=' . urlencode($secretPath) . '&deleted=1');
    exit();
}

// Helper: Get display name for submission
function getDisplayName($sub) {
    if (!empty($sub['name'])) {
        return $sub['name'];
    }
    return trim(($sub['firstName'] ?? '') . ' ' . ($sub['lastName'] ?? ''));
}

// Handle export action (exports filtered data)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="submissions-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'ID', 'Form Type', 'Date', 'Time', 'Name', 'Email', 'Phone',
        'Container Size', 'Condition', 'Intention', 'Delivery',
        'Location Type', 'Street Address', 'City', 'Postal Code',
        'Land Location', 'Additional Directions',
        'Subject', 'Message', 'IP'
    ]);

    foreach ($allSubmissions as $sub) {
        fputcsv($output, [
            $sub['id'],
            $sub['formType'] ?? 'message',
            $sub['date'],
            $sub['time'],
            getDisplayName($sub),
            $sub['email'],
            $sub['phone'] ?? '',
            $sub['containerSize'] ?? '',
            $sub['condition'] ?? '',
            $sub['intention'] ?? '',
            $sub['delivery'] ?? '',
            $sub['locationType'] ?? '',
            $sub['streetAddress'] ?? '',
            $sub['city'] ?? '',
            $sub['postalCode'] ?? '',
            $sub['landLocation'] ?? '',
            $sub['additionalDirections'] ?? '',
            $sub['subject'] ?? '',
            $sub['message'] ?? '',
            $sub['ip']
        ]);
    }

    fclose($output);
    exit();
}

// Prepare submissions data as JSON for client-side filtering
$submissionsJson = json_encode($allSubmissions);
$totalSubmissions = count($allSubmissions);
$quoteCount = count(array_filter($allSubmissions, fn($s) => ($s['formType'] ?? 'message') === 'quote'));
$todayCount = count(array_filter($allSubmissions, fn($s) => ($s['date'] ?? '') === date('Y-m-d')));
$last7DaysCount = count(array_filter($allSubmissions, fn($s) => strtotime($s['date'] ?? '1970-01-01') >= strtotime('-7 days')));

// Load reviews
$reviewsData = ['reviews' => [], 'lastSync' => null, 'totalCount' => 0, 'averageRating' => 0];
if (file_exists($reviewsPath)) {
    $reviewsContent = file_get_contents($reviewsPath);
    $reviewsData = json_decode($reviewsContent, true) ?? $reviewsData;
}
$reviewsJson = json_encode($reviewsData['reviews'] ?? []);
$reviewsLastSync = $reviewsData['lastSync'] ?? 'Never';
$reviewsTotalCount = $reviewsData['totalCount'] ?? count($reviewsData['reviews'] ?? []);
$reviewsAvgRating = $reviewsData['averageRating'] ?? 0;

// Get current tab
$currentTab = $_GET['tab'] ?? 'submissions';

// Load products config for Rich Snippets tab
$productsConfig = [];
if ($config && isset($config['products']['containers'])) {
    $productsConfig = $config['products']['containers'];
}

// Handle Rich Snippets form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_products') {
    // Build updated products array from POST data
    $updatedProducts = [];
    foreach ($_POST['products'] as $index => $product) {
        $updatedProducts[] = [
            'size' => $product['size'],
            'slug' => $product['slug'],
            'name' => $product['name'],
            'description' => $product['description'],
            'new' => !empty($product['new_min']) ? [
                'min' => (int)$product['new_min'],
                'max' => (int)$product['new_max']
            ] : null,
            'used' => !empty($product['used_min']) ? [
                'min' => (int)$product['used_min'],
                'max' => (int)$product['used_max']
            ] : null,
        ];
    }

    // Read config file and update products section
    $configContent = file_get_contents($configPath);

    // Build YAML for products
    $productsYaml = "products:\n  currency: \"CAD\"\n  containers:\n";
    foreach ($updatedProducts as $product) {
        $productsYaml .= "    - size: \"{$product['size']}\"\n";
        $productsYaml .= "      slug: \"{$product['slug']}\"\n";
        $productsYaml .= "      name: \"{$product['name']}\"\n";
        $productsYaml .= "      description: \"{$product['description']}\"\n";
        if ($product['new']) {
            $productsYaml .= "      new: { min: {$product['new']['min']}, max: {$product['new']['max']} }\n";
        } else {
            $productsYaml .= "      new: null\n";
        }
        if ($product['used']) {
            $productsYaml .= "      used: { min: {$product['used']['min']}, max: {$product['used']['max']} }\n";
        } else {
            $productsYaml .= "      used: null\n";
        }
    }

    // Replace products section in config
    $pattern = '/products:\s*\n\s+currency:.*?(?=\n# |\n[a-z]+:|\z)/s';
    $configContent = preg_replace($pattern, $productsYaml, $configContent);

    file_put_contents($configPath, $configContent);

    // Reload config
    if (function_exists('yaml_parse_file')) {
        $config = yaml_parse_file($configPath);
    }
    $productsConfig = $config['products']['containers'] ?? [];

    $productsSaved = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - C-Can Sam</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
            line-height: 1.5;
            padding: 2rem;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { font-size: 1.875rem; font-weight: 700; margin-bottom: 0.5rem; }
        .subtitle { color: #6b7280; margin-bottom: 2rem; }
        .stats {
            display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;
        }
        .stat {
            background: white; padding: 1rem 1.5rem; border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: #d97706; }
        .stat-label { font-size: 0.875rem; color: #6b7280; }

        /* Filter bar */
        .filter-bar {
            background: white; padding: 1rem 1.5rem; border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem;
            display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;
        }
        .filter-group { display: flex; align-items: center; gap: 0.5rem; }
        .filter-group label { font-size: 0.875rem; font-weight: 500; color: #374151; }
        .filter-group input[type="date"], .filter-group input[type="text"], .filter-group select {
            padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem;
            font-size: 0.875rem; background: white;
        }
        .filter-group input[type="text"] { width: 200px; }
        .filter-group input:focus, .filter-group select:focus {
            outline: none; border-color: #d97706; box-shadow: 0 0 0 2px rgba(217, 119, 6, 0.2);
        }
        .quick-filters { display: flex; gap: 0.5rem; }
        .quick-filter {
            padding: 0.375rem 0.75rem; border-radius: 0.375rem; font-size: 0.75rem;
            background: #f3f4f6; color: #374151; border: none; cursor: pointer;
            transition: all 0.15s;
        }
        .quick-filter:hover { background: #e5e7eb; }
        .quick-filter.active { background: #d97706; color: white; }
        .results-count { font-size: 0.875rem; color: #6b7280; margin-left: auto; }

        .actions { margin-bottom: 1.5rem; display: flex; gap: 0.5rem; }
        .btn {
            display: inline-block; padding: 0.5rem 1rem; border-radius: 0.375rem;
            text-decoration: none; font-weight: 500; font-size: 0.875rem;
            border: none; cursor: pointer;
        }
        .btn-primary { background: #d97706; color: white; }
        .btn-primary:hover { background: #b45309; }
        .btn-secondary { background: #e5e7eb; color: #374151; }
        .btn-secondary:hover { background: #d1d5db; }
        .btn-danger { background: #dc2626; color: white; font-size: 0.75rem; padding: 0.25rem 0.5rem; }
        .btn-danger:hover { background: #b91c1c; }
        .alert {
            padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;
            background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7;
        }
        .submissions { display: flex; flex-direction: column; gap: 0.5rem; }

        /* Expandable row styles */
        .submission-row {
            background: white; border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .submission-row.hidden { display: none; }
        .submission-summary {
            display: flex; align-items: center; padding: 0.75rem 1rem;
            cursor: pointer; gap: 1rem; transition: background 0.15s;
        }
        .submission-summary:hover { background: #f9fafb; }
        .expand-icon {
            font-size: 0.75rem; color: #9ca3af; transition: transform 0.2s;
            flex-shrink: 0; width: 1rem;
        }
        .submission-row.expanded .expand-icon { transform: rotate(90deg); }
        .badge {
            display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px;
            font-size: 0.625rem; font-weight: 600; text-transform: uppercase;
            flex-shrink: 0;
        }
        .badge-quote { background: #fef3c7; color: #92400e; }
        .badge-message { background: #dbeafe; color: #1e40af; }
        .summary-name { font-weight: 600; min-width: 120px; flex-shrink: 0; }
        .summary-email { color: #d97706; min-width: 180px; flex-shrink: 0; }
        .summary-phone { color: #6b7280; min-width: 120px; flex-shrink: 0; }
        .summary-preview {
            color: #6b7280; font-size: 0.875rem; flex: 1;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .summary-date { color: #9ca3af; font-size: 0.75rem; flex-shrink: 0; text-align: right; min-width: 80px; }

        /* Expanded details */
        .submission-details {
            display: none; padding: 1rem 1.5rem; background: #f9fafb;
            border-top: 1px solid #e5e7eb;
        }
        .submission-row.expanded .submission-details { display: block; }
        .details-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem; margin-bottom: 1rem;
        }
        .detail-item { }
        .detail-label { font-size: 0.625rem; text-transform: uppercase; color: #9ca3af; font-weight: 600; margin-bottom: 0.125rem; }
        .detail-value { font-size: 0.875rem; color: #1f2937; }
        .detail-value:empty::after { content: '—'; color: #d1d5db; }
        .message-section { margin-top: 1rem; }
        .message-label { font-size: 0.625rem; text-transform: uppercase; color: #9ca3af; font-weight: 600; margin-bottom: 0.25rem; }
        .message-content {
            background: white; padding: 0.75rem; border-radius: 0.375rem;
            white-space: pre-wrap; font-size: 0.875rem; border: 1px solid #e5e7eb;
        }
        .details-footer {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #e5e7eb;
            font-size: 0.75rem; color: #9ca3af;
        }

        .empty {
            text-align: center; padding: 3rem; background: white;
            border-radius: 0.5rem; color: #6b7280;
        }
        @media (max-width: 768px) {
            body { padding: 1rem; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-group { flex-wrap: wrap; }
            .results-count { margin-left: 0; margin-top: 0.5rem; }
            .summary-phone, .summary-preview { display: none; }
            .details-grid { grid-template-columns: 1fr 1fr; }
        }

        /* Tab Navigation */
        .tabs {
            display: flex; gap: 0; margin-bottom: 1.5rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .tab {
            padding: 0.75rem 1.5rem; font-weight: 500; font-size: 0.9375rem;
            color: #6b7280; text-decoration: none; border-bottom: 2px solid transparent;
            margin-bottom: -2px; transition: all 0.15s;
        }
        .tab:hover { color: #d97706; }
        .tab.active { color: #d97706; border-bottom-color: #d97706; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Reviews styles */
        .reviews-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;
        }
        .reviews-stats {
            display: flex; gap: 1.5rem; align-items: center;
        }
        .reviews-stat {
            display: flex; align-items: center; gap: 0.5rem;
        }
        .reviews-stat-value { font-size: 1.25rem; font-weight: 700; color: #d97706; }
        .reviews-stat-label { font-size: 0.875rem; color: #6b7280; }
        .stars { color: #fbbf24; font-size: 1.125rem; }

        .reviews-filters {
            background: white; padding: 1rem 1.5rem; border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem;
            display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;
        }
        .reviews-filter-group {
            display: flex; align-items: center; gap: 0.5rem;
        }
        .reviews-filter-group label {
            font-size: 0.875rem; font-weight: 500; color: #374151;
        }
        .reviews-filters input[type="date"],
        .reviews-filters input[type="text"],
        .reviews-filters select {
            padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem;
            font-size: 0.875rem; background: white;
        }
        .reviews-filters input[type="text"] { width: 200px; }
        .reviews-filters input:focus,
        .reviews-filters select:focus {
            outline: none; border-color: #d97706; box-shadow: 0 0 0 2px rgba(217, 119, 6, 0.2);
        }
        .reviews-results-count {
            font-size: 0.875rem; color: #6b7280; margin-left: auto;
        }

        .reviews-list {
            display: flex; flex-direction: column; gap: 0.75rem;
        }
        .review-card {
            background: white; border-radius: 0.5rem; padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .review-card.hidden { display: none; }
        .review-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        .review-author {
            display: flex; align-items: center; gap: 0.75rem;
        }
        .review-avatar {
            width: 40px; height: 40px; border-radius: 50%; background: #d97706;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 600; font-size: 1rem;
        }
        .review-author-info { }
        .review-author-name { font-weight: 600; color: #1f2937; }
        .review-author-meta { font-size: 0.75rem; color: #9ca3af; display: flex; gap: 0.5rem; align-items: center; }
        .review-rating { display: flex; gap: 0.125rem; }
        .review-star { color: #fbbf24; font-size: 0.875rem; }
        .review-star.empty { color: #e5e7eb; }
        .review-date { font-size: 0.75rem; color: #9ca3af; text-align: right; }
        .review-text { color: #374151; line-height: 1.6; margin-bottom: 0.75rem; }
        .review-response {
            background: #f9fafb; border-left: 3px solid #d97706; padding: 0.75rem 1rem;
            margin-top: 0.75rem; border-radius: 0 0.375rem 0.375rem 0;
        }
        .review-response-header {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.75rem; font-weight: 600; color: #d97706; margin-bottom: 0.5rem;
        }
        .review-response-avatar {
            width: 24px; height: 24px; border-radius: 50%; object-fit: cover;
            border: 1px solid #e5e7eb;
        }
        .review-response-text { font-size: 0.875rem; color: #6b7280; }
        .review-badges { display: flex; gap: 0.5rem; margin-top: 0.5rem; }
        .review-badge {
            font-size: 0.625rem; padding: 0.125rem 0.375rem; border-radius: 0.25rem;
            background: #e5e7eb; color: #6b7280; text-transform: uppercase; font-weight: 600;
        }
        .review-badge.new { background: #dcfce7; color: #166534; }
        .review-badge.photo { background: #dbeafe; color: #1e40af; }
        .review-badge.guide { background: #fef3c7; color: #92400e; }

        .reviews-pagination {
            display: flex; justify-content: center; align-items: center; gap: 0.5rem;
            margin-top: 1.5rem;
        }
        .page-btn {
            padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem;
            background: white; cursor: pointer; font-size: 0.875rem;
        }
        .page-btn:hover { background: #f3f4f6; }
        .page-btn.active { background: #d97706; color: white; border-color: #d97706; }
        .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .page-info { font-size: 0.875rem; color: #6b7280; margin: 0 0.5rem; }

        /* Modal styles */
        .modal {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5); z-index: 1000;
            display: flex; align-items: center; justify-content: center;
        }
        .modal-content {
            background: white; border-radius: 0.5rem; width: 90%; max-width: 500px;
            max-height: 80vh; overflow: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 1rem 1.5rem; border-bottom: 1px solid #e5e7eb;
        }
        .modal-header h3 { margin: 0; font-size: 1.125rem; }
        .modal-close {
            background: none; border: none; font-size: 1.5rem; cursor: pointer;
            color: #9ca3af; line-height: 1;
        }
        .modal-close:hover { color: #6b7280; }
        .modal-body { padding: 1.5rem; }
        .spinner {
            width: 40px; height: 40px; margin: 1rem auto;
            border: 3px solid #e5e7eb; border-top-color: #d97706;
            border-radius: 50%; animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .tag-result { white-space: pre-wrap; font-family: monospace; font-size: 0.75rem; background: #f3f4f6; padding: 1rem; border-radius: 0.375rem; max-height: 300px; overflow: auto; }
        .tag-success { color: #065f46; }
        .tag-error { color: #dc2626; }
    </style>
</head>
<body>
    <div class="container">
        <h1>C-Can Sam Admin</h1>
        <p class="subtitle">Manage submissions and reviews</p>

        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert">Submission deleted successfully.</div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tabs">
            <a href="?key=<?= urlencode($secretPath) ?>&tab=submissions" class="tab <?= $currentTab === 'submissions' ? 'active' : '' ?>">
                Submissions (<?= $totalSubmissions ?>)
            </a>
            <a href="?key=<?= urlencode($secretPath) ?>&tab=reviews" class="tab <?= $currentTab === 'reviews' ? 'active' : '' ?>">
                Reviews (<?= $reviewsTotalCount ?>)
            </a>
            <a href="?key=<?= urlencode($secretPath) ?>&tab=backlinks" class="tab <?= $currentTab === 'backlinks' ? 'active' : '' ?>">
                Backlink Research
            </a>
            <a href="?key=<?= urlencode($secretPath) ?>&tab=rich-snippets" class="tab <?= $currentTab === 'rich-snippets' ? 'active' : '' ?>">
                Rich Snippets
            </a>
        </div>

        <!-- Submissions Tab -->
        <div class="tab-content <?= $currentTab === 'submissions' ? 'active' : '' ?>" id="submissions-tab">

        <div class="stats">
            <div class="stat">
                <div class="stat-value" id="stat-total"><?= $totalSubmissions ?></div>
                <div class="stat-label">Total Submissions</div>
            </div>
            <div class="stat">
                <div class="stat-value" id="stat-quotes"><?= $quoteCount ?></div>
                <div class="stat-label">Quote Requests</div>
            </div>
            <div class="stat">
                <div class="stat-value" id="stat-today"><?= $todayCount ?></div>
                <div class="stat-label">Today</div>
            </div>
            <div class="stat">
                <div class="stat-value" id="stat-week"><?= $last7DaysCount ?></div>
                <div class="stat-label">Last 7 Days</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>From:</label>
                <input type="date" id="date-from" />
            </div>
            <div class="filter-group">
                <label>To:</label>
                <input type="date" id="date-to" />
            </div>
            <div class="filter-group">
                <label>Type:</label>
                <select id="type-filter">
                    <option value="all">All</option>
                    <option value="quote">Quotes</option>
                    <option value="message">Messages</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Search:</label>
                <input type="text" id="search-input" placeholder="Name, email, phone..." />
            </div>
            <div class="quick-filters">
                <button class="quick-filter active" data-days="30">Last 30 Days</button>
                <button class="quick-filter" data-days="7">Last 7 Days</button>
                <button class="quick-filter" data-days="0">Today</button>
                <button class="quick-filter" data-days="-1">All Time</button>
            </div>
            <span class="results-count"><span id="filtered-count">0</span> results</span>
        </div>

        <div class="actions">
            <a href="?key=<?= urlencode($secretPath) ?>&export=csv" class="btn btn-secondary">Export CSV</a>
            <a href="?key=<?= urlencode($secretPath) ?>" class="btn btn-secondary">Refresh</a>
        </div>

        <div class="submissions" id="submissions-container">
            <!-- Submissions will be rendered by JavaScript -->
        </div>

        <div class="empty" id="empty-state" style="display: none;">
            <p>No submissions match your filters.</p>
        </div>

        </div><!-- End Submissions Tab -->

        <!-- Reviews Tab -->
        <div class="tab-content <?= $currentTab === 'reviews' ? 'active' : '' ?>" id="reviews-tab">
            <div class="reviews-header">
                <div class="reviews-stats">
                    <div class="reviews-stat">
                        <span class="reviews-stat-value"><?= $reviewsTotalCount ?></span>
                        <span class="reviews-stat-label">Reviews</span>
                    </div>
                    <div class="reviews-stat">
                        <span class="stars">★★★★★</span>
                        <span class="reviews-stat-value"><?= number_format($reviewsAvgRating, 2) ?></span>
                    </div>
                    <div class="reviews-stat">
                        <span class="reviews-stat-label">Last sync: <?= htmlspecialchars($reviewsLastSync) ?></span>
                    </div>
                </div>
                <div class="reviews-actions">
                    <button type="button" class="btn btn-primary" id="tag-reviews-btn" onclick="tagReviews()">
                        Tag Reviews with AI
                    </button>
                </div>
            </div>

            <!-- Tag Reviews Modal -->
            <div id="tag-modal" class="modal" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Tag Reviews with AI</h3>
                        <button class="modal-close" onclick="closeTagModal()">&times;</button>
                    </div>
                    <div class="modal-body" id="tag-modal-body">
                        <p>Analyzing reviews with OpenAI to determine which pages they should appear on...</p>
                        <div class="spinner"></div>
                    </div>
                </div>
            </div>

            <!-- Reviews Filter Bar -->
            <div class="reviews-filters">
                <div class="reviews-filter-group">
                    <label>From:</label>
                    <input type="date" id="reviews-date-from" />
                </div>
                <div class="reviews-filter-group">
                    <label>To:</label>
                    <input type="date" id="reviews-date-to" />
                </div>
                <div class="reviews-filter-group">
                    <label>Sort:</label>
                    <select id="reviews-sort">
                        <option value="desc">Newest First</option>
                        <option value="asc">Oldest First</option>
                    </select>
                </div>
                <div class="reviews-filter-group">
                    <label>Search:</label>
                    <input type="text" id="reviews-search" placeholder="Name, review text..." />
                </div>
                <span class="reviews-results-count"><span id="reviews-count">0</span> reviews</span>
            </div>

            <div class="reviews-list" id="reviews-container">
                <!-- Reviews will be rendered by JavaScript -->
            </div>

            <div class="reviews-pagination" id="reviews-pagination">
                <!-- Pagination will be rendered by JavaScript -->
            </div>
        </div><!-- End Reviews Tab -->

        <!-- Backlinks Tab -->
        <div class="tab-content <?= $currentTab === 'backlinks' ? 'active' : '' ?>" id="backlinks-tab">
            <div class="backlinks-header">
                <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem;">Competitor Backlink Research</h2>
                <p style="color: #6b7280; font-size: 0.875rem;">Enter a competitor's domain to find their backlinks. Export results to CSV for outreach.</p>
            </div>

            <!-- Search Form -->
            <div class="backlinks-search" style="background: white; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin: 1.5rem 0;">
                <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 250px;">
                        <label style="display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 0.5rem;">Competitor Domain</label>
                        <input type="text" id="competitor-domain" placeholder="example.com" style="width: 100%; padding: 0.625rem 0.875rem; border: 1px solid #d1d5db; border-radius: 0.375rem; font-size: 1rem;">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="searchBacklinks()" id="search-btn">
                        Search Backlinks
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="exportBacklinksCSV()" id="export-btn" style="display: none;">
                        Export CSV
                    </button>
                </div>
                <p style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.75rem;">
                    Enter domain without http:// or www. Example: targetcontainers.ca
                </p>
            </div>

            <!-- Loading State -->
            <div id="backlinks-loading" style="display: none; text-align: center; padding: 3rem;">
                <div class="spinner"></div>
                <p style="color: #6b7280; margin-top: 1rem;">Searching for backlinks... This may take 30-60 seconds.</p>
            </div>

            <!-- Results -->
            <div id="backlinks-results" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="font-size: 1rem; font-weight: 600;">
                        Results for <span id="results-domain" style="color: #d97706;"></span>
                        (<span id="results-count">0</span> found)
                    </h3>
                </div>

                <!-- Quick Links to Pro Tools -->
                <div id="search-queries" style="background: #fffbeb; border: 1px solid #fcd34d; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1.5rem;">
                    <p style="font-size: 0.875rem; font-weight: 500; color: #92400e; margin-bottom: 0.75rem;">
                        🔍 For more comprehensive results, try these tools:
                    </p>
                    <div id="query-links" style="display: flex; flex-wrap: wrap; gap: 0.5rem;"></div>
                </div>

                <!-- Results Table -->
                <div style="background: white; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
                    <table style="width: 100%; border-collapse: collapse;" id="backlinks-table">
                        <thead>
                            <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                                <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">URL</th>
                                <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; width: 120px;">Source</th>
                                <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; width: 100px;">Type</th>
                            </tr>
                        </thead>
                        <tbody id="backlinks-tbody"></tbody>
                    </table>
                </div>

                <!-- Manual Entry -->
                <div style="margin-top: 1.5rem; background: white; padding: 1rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <p style="font-size: 0.875rem; font-weight: 500; margin-bottom: 0.75rem;">Add backlink manually:</p>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" id="manual-url" placeholder="https://example.com/page-linking-to-competitor" style="flex: 1; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem; font-size: 0.875rem;">
                        <button type="button" class="btn btn-secondary" onclick="addManualBacklink()">Add</button>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div id="backlinks-empty" style="text-align: center; padding: 3rem; background: white; border-radius: 0.5rem; margin-top: 1.5rem;">
                <p style="color: #6b7280;">Enter a competitor domain above to search for their backlinks.</p>
                <p style="color: #9ca3af; font-size: 0.875rem; margin-top: 0.5rem;">
                    Find who links to your competitors, then reach out to get links for yourself.
                </p>
            </div>
        </div><!-- End Backlinks Tab -->

        <!-- Rich Snippets Tab -->
        <div class="tab-content <?= $currentTab === 'rich-snippets' ? 'active' : '' ?>" id="rich-snippets-tab">
            <?php if (isset($productsSaved) && $productsSaved): ?>
                <div class="alert">Product pricing saved successfully! <strong>Rebuild and deploy to apply changes.</strong></div>
            <?php endif; ?>

            <div class="rich-snippets-header" style="margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem;">Rich Snippets Configuration</h2>
                <p style="color: #6b7280; font-size: 0.875rem;">Manage product pricing and structured data that appears in Google search results.</p>
            </div>

            <!-- Review Stats Card -->
            <div style="background: white; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                    <span style="color: #fbbf24;">★</span> Aggregate Rating
                </h3>
                <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                    <div>
                        <div style="font-size: 2rem; font-weight: 700; color: #d97706;"><?= number_format($reviewsAvgRating, 1) ?></div>
                        <div style="font-size: 0.75rem; color: #6b7280;">Average Rating</div>
                    </div>
                    <div>
                        <div style="font-size: 2rem; font-weight: 700; color: #d97706;"><?= $reviewsTotalCount ?></div>
                        <div style="font-size: 0.75rem; color: #6b7280;">Total Reviews</div>
                    </div>
                    <div>
                        <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.25rem;">Last Sync</div>
                        <div style="font-size: 0.875rem; font-weight: 500;"><?= htmlspecialchars($reviewsLastSync) ?></div>
                    </div>
                </div>
                <p style="font-size: 0.75rem; color: #9ca3af; margin-top: 1rem;">
                    This rating appears on all product pages in search results. Update reviews in the Reviews tab.
                </p>
            </div>

            <!-- Products Form -->
            <form method="POST" action="?key=<?= urlencode($secretPath) ?>&tab=rich-snippets">
                <input type="hidden" name="action" value="save_products">

                <div style="background: white; padding: 1.5rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h3 style="font-size: 1rem; font-weight: 600;">Container Products</h3>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>

                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 800px;">
                            <thead>
                                <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                                    <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Size</th>
                                    <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Name</th>
                                    <th style="padding: 0.75rem 1rem; text-align: center; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #ecfdf5;">New Price Range</th>
                                    <th style="padding: 0.75rem 1rem; text-align: center; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; background: #fef3c7;">Used Price Range</th>
                                    <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase;">Page</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productsConfig as $index => $product): ?>
                                    <tr style="border-bottom: 1px solid #e5e7eb;">
                                        <td style="padding: 0.75rem 1rem;">
                                            <input type="hidden" name="products[<?= $index ?>][size]" value="<?= htmlspecialchars($product['size'] ?? '') ?>">
                                            <input type="hidden" name="products[<?= $index ?>][slug]" value="<?= htmlspecialchars($product['slug'] ?? '') ?>">
                                            <input type="hidden" name="products[<?= $index ?>][description]" value="<?= htmlspecialchars($product['description'] ?? '') ?>">
                                            <span style="font-weight: 600;"><?= htmlspecialchars($product['size'] ?? '') ?></span>
                                        </td>
                                        <td style="padding: 0.75rem 1rem;">
                                            <input type="text" name="products[<?= $index ?>][name]" value="<?= htmlspecialchars($product['name'] ?? '') ?>" style="width: 100%; padding: 0.375rem 0.5rem; border: 1px solid #d1d5db; border-radius: 0.25rem; font-size: 0.875rem;">
                                        </td>
                                        <td style="padding: 0.75rem 0.5rem; background: #ecfdf5;">
                                            <div style="display: flex; gap: 0.25rem; align-items: center; justify-content: center;">
                                                <span style="font-size: 0.75rem; color: #6b7280;">$</span>
                                                <input type="number" name="products[<?= $index ?>][new_min]" value="<?= htmlspecialchars($product['new']['min'] ?? '') ?>" placeholder="Min" style="width: 70px; padding: 0.375rem 0.5rem; border: 1px solid #d1d5db; border-radius: 0.25rem; font-size: 0.875rem; text-align: right;">
                                                <span style="font-size: 0.75rem; color: #6b7280;">-</span>
                                                <input type="number" name="products[<?= $index ?>][new_max]" value="<?= htmlspecialchars($product['new']['max'] ?? '') ?>" placeholder="Max" style="width: 70px; padding: 0.375rem 0.5rem; border: 1px solid #d1d5db; border-radius: 0.25rem; font-size: 0.875rem; text-align: right;">
                                            </div>
                                        </td>
                                        <td style="padding: 0.75rem 0.5rem; background: #fef3c7;">
                                            <div style="display: flex; gap: 0.25rem; align-items: center; justify-content: center;">
                                                <span style="font-size: 0.75rem; color: #6b7280;">$</span>
                                                <input type="number" name="products[<?= $index ?>][used_min]" value="<?= htmlspecialchars($product['used']['min'] ?? '') ?>" placeholder="Min" style="width: 70px; padding: 0.375rem 0.5rem; border: 1px solid #d1d5db; border-radius: 0.25rem; font-size: 0.875rem; text-align: right;">
                                                <span style="font-size: 0.75rem; color: #6b7280;">-</span>
                                                <input type="number" name="products[<?= $index ?>][used_max]" value="<?= htmlspecialchars($product['used']['max'] ?? '') ?>" placeholder="Max" style="width: 70px; padding: 0.375rem 0.5rem; border: 1px solid #d1d5db; border-radius: 0.25rem; font-size: 0.875rem; text-align: right;">
                                            </div>
                                        </td>
                                        <td style="padding: 0.75rem 1rem;">
                                            <a href="/containers/<?= htmlspecialchars($product['slug'] ?? '') ?>" target="_blank" style="color: #d97706; font-size: 0.75rem;">
                                                /containers/<?= htmlspecialchars($product['slug'] ?? '') ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p style="font-size: 0.75rem; color: #9ca3af; margin-top: 1rem;">
                        Leave price fields empty if that condition is not available. Prices shown are the starting prices that appear in Google search results.
                    </p>
                </div>

                <div style="margin-top: 1rem; display: flex; gap: 0.5rem; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>

            <!-- Testing Links -->
            <div style="background: #fffbeb; border: 1px solid #fcd34d; border-radius: 0.5rem; padding: 1rem; margin-top: 1.5rem;">
                <p style="font-size: 0.875rem; font-weight: 500; color: #92400e; margin-bottom: 0.75rem;">
                    🔍 Test Your Rich Snippets
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    <a href="https://search.google.com/test/rich-results?url=https://ccansam.com" target="_blank" rel="noopener" class="btn btn-secondary" style="font-size: 0.75rem; padding: 0.375rem 0.75rem;">Test Homepage</a>
                    <?php foreach ($productsConfig as $product): ?>
                        <a href="https://search.google.com/test/rich-results?url=https://ccansam.com/containers/<?= urlencode($product['slug'] ?? '') ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="font-size: 0.75rem; padding: 0.375rem 0.75rem;">Test <?= htmlspecialchars($product['size'] ?? '') ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div><!-- End Rich Snippets Tab -->

    </div>

    <script>
        // All submissions data from PHP
        const allSubmissions = <?= $submissionsJson ?>;
        const secretPath = '<?= urlencode($secretPath) ?>';

        // DOM elements
        const dateFromInput = document.getElementById('date-from');
        const dateToInput = document.getElementById('date-to');
        const typeFilter = document.getElementById('type-filter');
        const searchInput = document.getElementById('search-input');
        const quickFilters = document.querySelectorAll('.quick-filter');
        const container = document.getElementById('submissions-container');
        const emptyState = document.getElementById('empty-state');
        const filteredCountEl = document.getElementById('filtered-count');

        // Set default dates (last 30 days)
        function setDateRange(days) {
            const today = new Date();
            const toDate = today.toISOString().split('T')[0];
            dateToInput.value = toDate;

            if (days === -1) {
                // All time
                dateFromInput.value = '';
            } else if (days === 0) {
                // Today
                dateFromInput.value = toDate;
            } else {
                const fromDate = new Date(today);
                fromDate.setDate(fromDate.getDate() - days);
                dateFromInput.value = fromDate.toISOString().split('T')[0];
            }
            filterSubmissions();
        }

        // Initialize with last 30 days
        setDateRange(30);

        // Helper: get display name
        function getDisplayName(sub) {
            if (sub.name) return sub.name;
            return ((sub.firstName || '') + ' ' + (sub.lastName || '')).trim();
        }

        // Filter submissions based on current filters
        function filterSubmissions() {
            const fromDate = dateFromInput.value;
            const toDate = dateToInput.value;
            const type = typeFilter.value;
            const search = searchInput.value.toLowerCase().trim();

            const filtered = allSubmissions.filter(sub => {
                // Date filter
                if (fromDate && sub.date < fromDate) return false;
                if (toDate && sub.date > toDate) return false;

                // Type filter
                const formType = sub.formType || 'message';
                if (type !== 'all' && formType !== type) return false;

                // Search filter
                if (search) {
                    const searchFields = [
                        getDisplayName(sub),
                        sub.email || '',
                        sub.phone || '',
                        sub.message || '',
                        sub.containerSize || '',
                        sub.city || '',
                        sub.subject || ''
                    ].join(' ').toLowerCase();
                    if (!searchFields.includes(search)) return false;
                }

                return true;
            });

            renderSubmissions(filtered);
            filteredCountEl.textContent = filtered.length;
        }

        // Render submissions to DOM
        function renderSubmissions(submissions) {
            if (submissions.length === 0) {
                container.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';

            container.innerHTML = submissions.map((sub, index) => {
                const formType = sub.formType || 'message';
                const isQuote = formType === 'quote';
                const displayName = getDisplayName(sub);
                const preview = isQuote
                    ? [sub.containerSize, sub.condition, sub.intention].filter(Boolean).join(' · ')
                    : (sub.subject || 'General Inquiry');

                let detailsHtml = `
                    <div class="detail-item">
                        <div class="detail-label">Name</div>
                        <div class="detail-value">${escapeHtml(displayName)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Email</div>
                        <div class="detail-value"><a href="mailto:${escapeHtml(sub.email)}">${escapeHtml(sub.email)}</a></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value">${escapeHtml(sub.phone || '')}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Date & Time</div>
                        <div class="detail-value">${escapeHtml(sub.date + ' ' + sub.time)}</div>
                    </div>
                `;

                if (isQuote) {
                    detailsHtml += `
                        <div class="detail-item">
                            <div class="detail-label">Container Size</div>
                            <div class="detail-value">${escapeHtml(sub.containerSize || '')}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Condition</div>
                            <div class="detail-value">${escapeHtml(sub.condition || '')}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Intention</div>
                            <div class="detail-value">${escapeHtml(sub.intention || '')}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Delivery</div>
                            <div class="detail-value">${escapeHtml(sub.delivery || '')}</div>
                        </div>
                    `;
                    if (sub.locationType) {
                        detailsHtml += `
                            <div class="detail-item">
                                <div class="detail-label">Location Type</div>
                                <div class="detail-value">${escapeHtml(sub.locationType)}</div>
                            </div>
                        `;
                        if (sub.locationType === 'Urban') {
                            detailsHtml += `
                                <div class="detail-item">
                                    <div class="detail-label">Street Address</div>
                                    <div class="detail-value">${escapeHtml(sub.streetAddress || '')}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">City</div>
                                    <div class="detail-value">${escapeHtml(sub.city || '')}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Postal Code</div>
                                    <div class="detail-value">${escapeHtml(sub.postalCode || '')}</div>
                                </div>
                            `;
                        } else {
                            detailsHtml += `
                                <div class="detail-item">
                                    <div class="detail-label">Land Location</div>
                                    <div class="detail-value">${escapeHtml(sub.landLocation || '')}</div>
                                </div>
                            `;
                            if (sub.additionalDirections) {
                                detailsHtml += `
                                    <div class="detail-item">
                                        <div class="detail-label">Additional Directions</div>
                                        <div class="detail-value">${escapeHtml(sub.additionalDirections)}</div>
                                    </div>
                                `;
                            }
                        }
                    }
                } else {
                    detailsHtml += `
                        <div class="detail-item">
                            <div class="detail-label">Subject</div>
                            <div class="detail-value">${escapeHtml(sub.subject || 'General Inquiry')}</div>
                        </div>
                    `;
                }

                let messageHtml = '';
                if (sub.message) {
                    messageHtml = `
                        <div class="message-section">
                            <div class="message-label">Message</div>
                            <div class="message-content">${escapeHtml(sub.message)}</div>
                        </div>
                    `;
                }

                return `
                    <div class="submission-row" id="row-${index}">
                        <div class="submission-summary" onclick="toggleRow(${index})">
                            <span class="expand-icon">▶</span>
                            <span class="badge ${isQuote ? 'badge-quote' : 'badge-message'}">
                                ${isQuote ? 'Quote' : 'Message'}
                            </span>
                            <span class="summary-name">${escapeHtml(displayName)}</span>
                            <span class="summary-email">${escapeHtml(sub.email)}</span>
                            <span class="summary-phone">${escapeHtml(sub.phone || '')}</span>
                            <span class="summary-preview">${escapeHtml(preview)}</span>
                            <span class="summary-date">${escapeHtml(sub.date)}</span>
                        </div>
                        <div class="submission-details">
                            <div class="details-grid">
                                ${detailsHtml}
                            </div>
                            ${messageHtml}
                            <div class="details-footer">
                                <span>ID: ${escapeHtml(sub.id)} · IP: ${escapeHtml(sub.ip)}</span>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this submission?');">
                                    <input type="hidden" name="delete_id" value="${escapeHtml(sub.id)}">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Toggle row expansion
        function toggleRow(index) {
            const row = document.getElementById('row-' + index);
            row.classList.toggle('expanded');
        }

        // Escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Event listeners
        dateFromInput.addEventListener('change', () => {
            clearQuickFilterActive();
            filterSubmissions();
        });
        dateToInput.addEventListener('change', () => {
            clearQuickFilterActive();
            filterSubmissions();
        });
        typeFilter.addEventListener('change', filterSubmissions);
        searchInput.addEventListener('input', filterSubmissions);

        quickFilters.forEach(btn => {
            btn.addEventListener('click', () => {
                quickFilters.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                setDateRange(parseInt(btn.dataset.days));
            });
        });

        function clearQuickFilterActive() {
            quickFilters.forEach(b => b.classList.remove('active'));
        }

        // Initial render
        filterSubmissions();

        // ============================================
        // Reviews Tab Functionality
        // ============================================
        const allReviews = <?= $reviewsJson ?>;
        const reviewsContainer = document.getElementById('reviews-container');
        const reviewsPagination = document.getElementById('reviews-pagination');
        const reviewsSearchInput = document.getElementById('reviews-search');
        const reviewsDateFromInput = document.getElementById('reviews-date-from');
        const reviewsDateToInput = document.getElementById('reviews-date-to');
        const reviewsSortSelect = document.getElementById('reviews-sort');
        const reviewsCountEl = document.getElementById('reviews-count');

        const REVIEWS_PER_PAGE = 10;
        let currentReviewsPage = 1;
        let filteredReviews = [...allReviews];

        // Set default date range (all time - leave empty for no restriction)
        function initReviewsDateRange() {
            const today = new Date().toISOString().split('T')[0];
            reviewsDateToInput.value = today;
            // Leave from date empty to show all
        }

        function getInitials(name) {
            if (!name) return '?';
            const parts = name.trim().split(' ');
            if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
            return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        }

        function renderStars(rating) {
            let stars = '';
            for (let i = 1; i <= 5; i++) {
                stars += `<span class="review-star ${i <= rating ? '' : 'empty'}">★</span>`;
            }
            return stars;
        }

        function getRelativeTime(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const now = new Date();
            const diffMs = now - date;
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

            if (diffDays < 1) return 'today';
            if (diffDays === 1) return 'yesterday';
            if (diffDays < 7) return `${diffDays} days ago`;
            if (diffDays < 14) return 'a week ago';
            if (diffDays < 30) return `${Math.floor(diffDays / 7)} weeks ago`;
            if (diffDays < 60) return 'a month ago';
            if (diffDays < 365) return `${Math.floor(diffDays / 30)} months ago`;
            if (diffDays < 730) return 'a year ago';
            return `${Math.floor(diffDays / 365)} years ago`;
        }

        function filterReviews() {
            const search = reviewsSearchInput.value.toLowerCase().trim();
            const fromDate = reviewsDateFromInput.value;
            const toDate = reviewsDateToInput.value;
            const sortOrder = reviewsSortSelect.value;

            // Filter
            filteredReviews = allReviews.filter(review => {
                // Date filter
                if (fromDate && review.date < fromDate) return false;
                if (toDate && review.date > toDate) return false;

                // Search filter
                if (search) {
                    const searchText = [
                        review.author || '',
                        review.text || '',
                        review.ownerResponse?.text || ''
                    ].join(' ').toLowerCase();
                    if (!searchText.includes(search)) return false;
                }

                return true;
            });

            // Sort
            filteredReviews.sort((a, b) => {
                const dateA = new Date(a.date);
                const dateB = new Date(b.date);
                return sortOrder === 'desc' ? dateB - dateA : dateA - dateB;
            });

            currentReviewsPage = 1;
            renderReviews();
            renderReviewsPagination();
            reviewsCountEl.textContent = filteredReviews.length;
        }

        function renderReviews() {
            const start = (currentReviewsPage - 1) * REVIEWS_PER_PAGE;
            const end = start + REVIEWS_PER_PAGE;
            const pageReviews = filteredReviews.slice(start, end);

            if (pageReviews.length === 0) {
                reviewsContainer.innerHTML = '<div class="empty"><p>No reviews match your search.</p></div>';
                return;
            }

            reviewsContainer.innerHTML = pageReviews.map(review => {
                let badges = '';
                if (review.isNew) badges += '<span class="review-badge new">New</span>';
                if (review.hasPhoto) badges += '<span class="review-badge photo">Photo</span>';
                if (review.isLocalGuide) badges += '<span class="review-badge guide">Local Guide</span>';
                if (review.isEdited) badges += '<span class="review-badge">Edited</span>';

                let responseHtml = '';
                if (review.ownerResponse) {
                    responseHtml = `
                        <div class="review-response">
                            <div class="review-response-header">
                                <img src="/favicon-32.png" alt="C-Can Sam" class="review-response-avatar" />
                                <span>C-Can Sam (Owner)</span>
                            </div>
                            <div class="review-response-text">${escapeHtml(review.ownerResponse.text)}</div>
                        </div>
                    `;
                }

                return `
                    <div class="review-card">
                        <div class="review-header">
                            <div class="review-author">
                                <div class="review-avatar">${getInitials(review.author)}</div>
                                <div class="review-author-info">
                                    <div class="review-author-name">${escapeHtml(review.author)}</div>
                                    <div class="review-author-meta">
                                        <div class="review-rating">${renderStars(review.rating)}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="review-date">
                                ${getRelativeTime(review.date)}
                            </div>
                        </div>
                        ${review.text ? `<div class="review-text">${escapeHtml(review.text)}</div>` : '<div class="review-text" style="color: #9ca3af; font-style: italic;">(No written review)</div>'}
                        ${badges ? `<div class="review-badges">${badges}</div>` : ''}
                        ${responseHtml}
                    </div>
                `;
            }).join('');
        }

        function renderReviewsPagination() {
            const totalPages = Math.ceil(filteredReviews.length / REVIEWS_PER_PAGE);

            if (totalPages <= 1) {
                reviewsPagination.innerHTML = '';
                return;
            }

            let paginationHtml = `
                <button class="page-btn" onclick="goToReviewsPage(${currentReviewsPage - 1})" ${currentReviewsPage === 1 ? 'disabled' : ''}>← Prev</button>
            `;

            const startPage = Math.max(1, currentReviewsPage - 2);
            const endPage = Math.min(totalPages, startPage + 4);

            for (let i = startPage; i <= endPage; i++) {
                paginationHtml += `
                    <button class="page-btn ${i === currentReviewsPage ? 'active' : ''}" onclick="goToReviewsPage(${i})">${i}</button>
                `;
            }

            paginationHtml += `
                <span class="page-info">${filteredReviews.length} reviews</span>
                <button class="page-btn" onclick="goToReviewsPage(${currentReviewsPage + 1})" ${currentReviewsPage === totalPages ? 'disabled' : ''}>Next →</button>
            `;

            reviewsPagination.innerHTML = paginationHtml;
        }

        function goToReviewsPage(page) {
            const totalPages = Math.ceil(filteredReviews.length / REVIEWS_PER_PAGE);
            if (page < 1 || page > totalPages) return;
            currentReviewsPage = page;
            renderReviews();
            renderReviewsPagination();
            reviewsContainer.scrollIntoView({ behavior: 'smooth' });
        }

        // Reviews event listeners
        if (reviewsSearchInput) {
            reviewsSearchInput.addEventListener('input', filterReviews);
        }
        if (reviewsDateFromInput) {
            reviewsDateFromInput.addEventListener('change', filterReviews);
        }
        if (reviewsDateToInput) {
            reviewsDateToInput.addEventListener('change', filterReviews);
        }
        if (reviewsSortSelect) {
            reviewsSortSelect.addEventListener('change', filterReviews);
        }

        // Initial reviews render (only if on reviews tab)
        if (document.getElementById('reviews-tab').classList.contains('active')) {
            initReviewsDateRange();
            filterReviews();
        }

        // Render reviews when tab is clicked (for tabs without page reload)
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                setTimeout(() => {
                    if (document.getElementById('reviews-tab').classList.contains('active')) {
                        initReviewsDateRange();
                        filterReviews();
                    }
                }, 0);
            });
        });

        // ============================================
        // Tag Reviews with AI
        // ============================================
        const tagModal = document.getElementById('tag-modal');
        const tagModalBody = document.getElementById('tag-modal-body');

        function tagReviews() {
            tagModal.style.display = 'flex';
            tagModalBody.innerHTML = `
                <p>Analyzing reviews with OpenAI to determine which pages they should appear on...</p>
                <div class="spinner"></div>
                <p style="font-size: 0.75rem; color: #9ca3af; text-align: center;">This may take 30-60 seconds.</p>
            `;

            fetch(`/api/tag-reviews.php?key=${secretPath}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        tagModalBody.innerHTML = `
                            <p class="tag-success"><strong>Reviews tagged successfully!</strong></p>
                            <p style="margin-top: 0.5rem;">The config.yaml file has been updated with review-to-page mappings.</p>
                            <p style="margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280;">Rebuild the site to see reviews on pages.</p>
                            <details style="margin-top: 1rem;">
                                <summary style="cursor: pointer; font-size: 0.875rem;">Show output</summary>
                                <div class="tag-result">${escapeHtml(data.output || '')}</div>
                            </details>
                        `;
                    } else {
                        tagModalBody.innerHTML = `
                            <p class="tag-error"><strong>Failed to tag reviews</strong></p>
                            <p style="margin-top: 0.5rem;">${escapeHtml(data.message || 'Unknown error')}</p>
                            ${data.hint ? `<p style="margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280;">${escapeHtml(data.hint)}</p>` : ''}
                            <details style="margin-top: 1rem;">
                                <summary style="cursor: pointer; font-size: 0.875rem;">Show output</summary>
                                <div class="tag-result">${escapeHtml(data.output || '')}</div>
                            </details>
                        `;
                    }
                })
                .catch(error => {
                    tagModalBody.innerHTML = `
                        <p class="tag-error"><strong>Error</strong></p>
                        <p style="margin-top: 0.5rem;">${escapeHtml(error.message)}</p>
                        <p style="margin-top: 1rem; font-size: 0.875rem; color: #6b7280;">
                            You can also run this manually:<br>
                            <code style="background: #f3f4f6; padding: 0.25rem 0.5rem; border-radius: 0.25rem;">npm run tag-reviews</code>
                        </p>
                    `;
                });
        }

        function closeTagModal() {
            tagModal.style.display = 'none';
        }

        // Close modal on escape key or click outside
        tagModal.addEventListener('click', (e) => {
            if (e.target === tagModal) closeTagModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && tagModal.style.display !== 'none') closeTagModal();
        });

        // ============================================
        // Backlink Research Tab Functionality
        // ============================================
        let currentBacklinks = [];
        let currentDomain = '';

        const backlinksDomainInput = document.getElementById('competitor-domain');
        const backlinksLoading = document.getElementById('backlinks-loading');
        const backlinksResults = document.getElementById('backlinks-results');
        const backlinksEmpty = document.getElementById('backlinks-empty');
        const backlinksTbody = document.getElementById('backlinks-tbody');
        const queryLinks = document.getElementById('query-links');
        const resultsDomain = document.getElementById('results-domain');
        const resultsCount = document.getElementById('results-count');
        const exportBtn = document.getElementById('export-btn');
        const searchBtn = document.getElementById('search-btn');

        // Search for backlinks
        function searchBacklinks() {
            let domain = backlinksDomainInput.value.trim();

            // Clean up domain
            domain = domain.replace(/^https?:\/\//, '');
            domain = domain.replace(/^www\./, '');
            domain = domain.replace(/\/.*$/, '');

            if (!domain) {
                alert('Please enter a competitor domain');
                return;
            }

            currentDomain = domain;
            backlinksEmpty.style.display = 'none';
            backlinksResults.style.display = 'none';
            backlinksLoading.style.display = 'block';
            searchBtn.disabled = true;
            searchBtn.textContent = 'Searching...';

            fetch(`/api/backlink-search.php?key=${secretPath}&domain=${encodeURIComponent(domain)}`)
                .then(response => response.json())
                .then(data => {
                    backlinksLoading.style.display = 'none';
                    searchBtn.disabled = false;
                    searchBtn.textContent = 'Search Backlinks';

                    if (data.error) {
                        alert('Error: ' + data.error);
                        backlinksEmpty.style.display = 'block';
                        return;
                    }

                    currentBacklinks = data.results || [];
                    resultsDomain.textContent = data.domain;
                    resultsCount.textContent = currentBacklinks.length;

                    // Render search query links
                    if (data.searchQueries && data.searchQueries.length > 0) {
                        queryLinks.innerHTML = data.searchQueries.map(q =>
                            `<a href="${escapeHtml(q.url)}" target="_blank" rel="noopener" class="btn btn-secondary" style="font-size: 0.75rem; padding: 0.375rem 0.75rem;">${escapeHtml(q.name)}</a>`
                        ).join('');
                    }

                    // Render results table
                    renderBacklinksTable();

                    backlinksResults.style.display = 'block';
                    exportBtn.style.display = currentBacklinks.length > 0 ? 'inline-block' : 'none';
                })
                .catch(error => {
                    backlinksLoading.style.display = 'none';
                    searchBtn.disabled = false;
                    searchBtn.textContent = 'Search Backlinks';
                    alert('Error searching for backlinks: ' + error.message);
                    backlinksEmpty.style.display = 'block';
                });
        }

        function renderBacklinksTable() {
            if (currentBacklinks.length === 0) {
                backlinksTbody.innerHTML = `
                    <tr>
                        <td colspan="3" style="padding: 2rem; text-align: center; color: #6b7280;">
                            No backlinks found automatically. Use the tools above to find more, then add them manually below.
                        </td>
                    </tr>
                `;
                return;
            }

            backlinksTbody.innerHTML = currentBacklinks.map((bl, index) => `
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="padding: 0.75rem 1rem;">
                        <a href="${escapeHtml(bl.url)}" target="_blank" rel="noopener" style="color: #d97706; font-size: 0.875rem; word-break: break-all;">
                            ${escapeHtml(bl.url.length > 80 ? bl.url.substring(0, 80) + '...' : bl.url)}
                        </a>
                    </td>
                    <td style="padding: 0.75rem 1rem; font-size: 0.75rem; color: #6b7280;">
                        ${escapeHtml(bl.source || 'Manual')}
                    </td>
                    <td style="padding: 0.75rem 1rem;">
                        <span style="font-size: 0.625rem; padding: 0.125rem 0.5rem; border-radius: 9999px; background: ${bl.type === 'backlink' ? '#dcfce7' : '#e0e7ff'}; color: ${bl.type === 'backlink' ? '#166534' : '#3730a3'};">
                            ${escapeHtml(bl.type || 'link')}
                        </span>
                    </td>
                </tr>
            `).join('');
        }

        function addManualBacklink() {
            const urlInput = document.getElementById('manual-url');
            const url = urlInput.value.trim();

            if (!url) {
                alert('Please enter a URL');
                return;
            }

            if (!url.startsWith('http://') && !url.startsWith('https://')) {
                alert('Please enter a valid URL starting with http:// or https://');
                return;
            }

            // Add to current backlinks
            currentBacklinks.push({
                url: url,
                source: 'Manual',
                type: 'backlink'
            });

            urlInput.value = '';
            resultsCount.textContent = currentBacklinks.length;
            renderBacklinksTable();
            exportBtn.style.display = 'inline-block';
        }

        function exportBacklinksCSV() {
            if (currentBacklinks.length === 0) {
                alert('No backlinks to export');
                return;
            }

            // Create CSV content
            let csv = 'URL,Source,Type,Competitor Domain\n';
            currentBacklinks.forEach(bl => {
                csv += `"${bl.url.replace(/"/g, '""')}","${bl.source || 'Unknown'}","${bl.type || 'link'}","${currentDomain}"\n`;
            });

            // Download CSV
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `backlinks-${currentDomain}-${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Handle Enter key in domain input
        if (backlinksDomainInput) {
            backlinksDomainInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    searchBacklinks();
                }
            });
        }
    </script>
</body>
</html>
