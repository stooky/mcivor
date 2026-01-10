<?php
/**
 * Tag Reviews API Endpoint
 *
 * Runs the OpenAI tagging script and returns the result.
 * Called from the admin panel "Tag Reviews" button.
 */

header('Content-Type: application/json');

// Load config for auth
$configPath = dirname(__DIR__) . '/config.yaml';
$localConfigPath = dirname(__DIR__) . '/config.local.yaml';

function parseSimpleYaml($path) {
    $content = file_get_contents($path);
    $config = [];

    if (preg_match('/secret_path:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
        $config['admin']['secret_path'] = trim($matches[1]);
    }

    return $config;
}

$config = parseSimpleYaml($configPath);
if (file_exists($localConfigPath)) {
    $localConfig = parseSimpleYaml($localConfigPath);
    $config = array_replace_recursive($config, $localConfig);
}

// Check admin auth
$secret = $config['admin']['secret_path'] ?? '';
$providedKey = $_GET['key'] ?? '';

if (!$secret || $providedKey !== $secret) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Run the tag-reviews script
$rootDir = dirname(__DIR__);
$output = [];
$returnCode = 0;

// Set PATH for npm/node (required for PHP exec)
$env = "export PATH=/usr/bin:/usr/local/bin:\$PATH && ";

// Try npm run tag-reviews first, fall back to node directly
$commands = [
    "{$env}cd \"$rootDir\" && /usr/bin/npm run tag-reviews 2>&1",
    "{$env}cd \"$rootDir\" && /usr/bin/node scripts/tag-reviews.js 2>&1"
];

$success = false;
$finalOutput = '';

foreach ($commands as $cmd) {
    exec($cmd, $output, $returnCode);
    $finalOutput = implode("\n", $output);

    if ($returnCode === 0) {
        $success = true;
        break;
    }
    $output = []; // Reset for next attempt
}

if ($success) {
    // Rebuild the site so tagged reviews appear on pages
    $buildOutput = [];
    $buildCode = 0;
    exec("{$env}cd \"$rootDir\" && /usr/bin/npm run build 2>&1", $buildOutput, $buildCode);
    $buildResult = implode("\n", $buildOutput);

    if ($buildCode === 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Reviews tagged and site rebuilt successfully',
            'output' => $finalOutput . "\n\n--- Build Output ---\n" . $buildResult
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Reviews tagged but site rebuild failed',
            'output' => $finalOutput . "\n\n--- Build Error ---\n" . $buildResult,
            'hint' => 'Run "npm run build" manually on the server'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to tag reviews',
        'output' => $finalOutput,
        'hint' => 'Make sure OPENAI_API_KEY is set in .env.local and Node.js is installed'
    ]);
}
