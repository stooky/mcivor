<?php
/**
 * Quote Request API
 * Handles quote requests from the inventory checker page
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Load config
$configPath = __DIR__ . '/../config.yaml';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Config not found']);
    exit;
}

$configContent = file_get_contents($configPath);
// Simple YAML parsing for the values we need
preg_match('/recipient_email:\s*"?([^"\n]+)"?/', $configContent, $recipientMatch);
preg_match('/resend_api_key:\s*"?([^"\n]+)"?/', $configContent, $apiKeyMatch);
preg_match('/from_email:\s*"?([^"\n]+)"?/', $configContent, $fromMatch);

$recipientEmail = trim($recipientMatch[1] ?? 'ccansam22@gmail.com');
$resendApiKey = trim($apiKeyMatch[1] ?? '');
$fromEmail = trim($fromMatch[1] ?? 'C-Can Sam <hello@ccansam.com>');

// Load inventory
$inventoryPath = __DIR__ . '/../data/inventory.json';
if (!file_exists($inventoryPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Inventory not found']);
    exit;
}

$inventory = json_decode(file_get_contents($inventoryPath), true);

// Get form data
$itemId = trim($_POST['item_id'] ?? '');
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');

// Validate required fields
if (empty($itemId) || empty($name) || empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// Find the inventory item
$item = null;
foreach ($inventory['inventory'] as $inv) {
    if ($inv['id'] === $itemId) {
        $item = $inv;
        break;
    }
}

if (!$item) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Item not found']);
    exit;
}

// Format price
$price = '$' . number_format($item['price'], 0);

// Build email content
$subject = "[Quote Request] {$item['unit']} - {$item['condition']} - {$item['location']}";

$htmlBody = "
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #d45c44; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
        .section { margin-bottom: 20px; }
        .label { font-weight: bold; color: #666; font-size: 12px; text-transform: uppercase; }
        .value { font-size: 16px; margin-top: 4px; }
        .price { font-size: 24px; font-weight: bold; color: #d45c44; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 8px; border-bottom: 1px solid #eee; }
        td:first-child { font-weight: bold; width: 120px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2 style='margin:0;'>New Quote Request</h2>
        </div>
        <div class='content'>
            <div class='section'>
                <h3>Customer Information</h3>
                <table>
                    <tr><td>Name:</td><td>{$name}</td></tr>
                    <tr><td>Email:</td><td><a href='mailto:{$email}'>{$email}</a></td></tr>
                    <tr><td>Phone:</td><td>" . ($phone ?: 'Not provided') . "</td></tr>
                </table>
            </div>

            <div class='section'>
                <h3>Container Details</h3>
                <table>
                    <tr><td>Item ID:</td><td>{$item['id']}</td></tr>
                    <tr><td>Unit:</td><td><strong>{$item['unit']}</strong></td></tr>
                    <tr><td>Condition:</td><td>{$item['condition']}</td></tr>
                    <tr><td>Location:</td><td>{$item['location']}</td></tr>
                    <tr><td>Quantity:</td><td>{$item['qty']} available</td></tr>
                    <tr><td>Depot:</td><td>" . ($item['depot'] ?: 'N/A') . "</td></tr>
                    <tr><td>Remarks:</td><td>" . ($item['remarks'] ?: 'None') . "</td></tr>
                </table>
            </div>

            <div class='section' style='background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #d45c44;'>
                <div class='label'>Supplier Price</div>
                <div class='price'>{$price}</div>
                <p style='margin: 8px 0 0 0; font-size: 13px; color: #666;'>
                    Remember to add markup + delivery to Saskatchewan for customer quote.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
";

$textBody = "
NEW QUOTE REQUEST
=================

CUSTOMER INFORMATION
--------------------
Name: {$name}
Email: {$email}
Phone: " . ($phone ?: 'Not provided') . "

CONTAINER DETAILS
-----------------
Item ID: {$item['id']}
Unit: {$item['unit']}
Condition: {$item['condition']}
Location: {$item['location']}
Quantity: {$item['qty']} available
Depot: " . ($item['depot'] ?: 'N/A') . "
Remarks: " . ($item['remarks'] ?: 'None') . "

SUPPLIER PRICE: {$price}
(Add markup + delivery for customer quote)
";

// Send email via Resend API
if (!empty($resendApiKey)) {
    $response = sendViaResend($resendApiKey, $fromEmail, $recipientEmail, $subject, $htmlBody, $textBody, $email);
} else {
    // Fallback to PHP mail
    $response = sendViaMail($recipientEmail, $subject, $htmlBody, $email);
}

if ($response['success']) {
    // Log the quote request
    logQuoteRequest($itemId, $name, $email, $phone, $item);

    echo json_encode(['success' => true, 'message' => 'Quote request sent']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $response['message']]);
}

/**
 * Send email via Resend API
 */
function sendViaResend($apiKey, $from, $to, $subject, $html, $text, $replyTo) {
    $data = [
        'from' => $from,
        'to' => [$to],
        'reply_to' => $replyTo,
        'subject' => $subject,
        'html' => $html,
        'text' => $text
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true];
    }

    $error = json_decode($response, true);
    return ['success' => false, 'message' => $error['message'] ?? 'Failed to send email'];
}

/**
 * Send email via PHP mail() - fallback
 */
function sendViaMail($to, $subject, $html, $replyTo) {
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: C-Can Sam <noreply@ccansam.com>',
        'Reply-To: ' . $replyTo
    ];

    $success = mail($to, $subject, $html, implode("\r\n", $headers));

    return [
        'success' => $success,
        'message' => $success ? 'Sent' : 'Failed to send email'
    ];
}

/**
 * Log quote request
 */
function logQuoteRequest($itemId, $name, $email, $phone, $item) {
    $logDir = __DIR__ . '/../data';
    $logFile = $logDir . '/quote-requests.json';

    $log = [];
    if (file_exists($logFile)) {
        $log = json_decode(file_get_contents($logFile), true) ?: [];
    }

    $log[] = [
        'timestamp' => date('c'),
        'item_id' => $itemId,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'unit' => $item['unit'],
        'condition' => $item['condition'],
        'location' => $item['location'],
        'price' => $item['price']
    ];

    // Keep last 500 entries
    if (count($log) > 500) {
        $log = array_slice($log, -500);
    }

    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
}
