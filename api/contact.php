<?php
/**
 * C-Can Sam Contact Form Handler
 *
 * Handles form submissions:
 * - Validates input
 * - Logs to JSON file
 * - Sends email notification
 * - Returns JSON response
 */

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Load configuration
$configPath = dirname(__DIR__) . '/config.yaml';
$localConfigPath = dirname(__DIR__) . '/config.local.yaml';

if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit();
}

$config = yaml_parse_file($configPath);
if ($config === false) {
    // Fallback: parse YAML manually if yaml extension not available
    $config = parseSimpleYaml($configPath);
}

// Merge local overrides if they exist
if (file_exists($localConfigPath)) {
    $localConfig = yaml_parse_file($localConfigPath);
    if ($localConfig === false) {
        $localConfig = parseSimpleYaml($localConfigPath);
    }
    if ($localConfig) {
        $config = array_replace_recursive($config, $localConfig);
    }
}

/**
 * Simple YAML parser for our config format
 */
function parseSimpleYaml($path) {
    $content = file_get_contents($path);
    $config = [
        'contact_form' => [
            'recipient_email' => 'ccansam22@gmail.com',
            'subject_prefix' => '[C-Can Sam Contact]'
        ],
        'logging' => [
            'submissions_file' => 'data/submissions.json'
        ],
        'security' => [
            'honeypot_field' => 'website_url',
            'rate_limit' => 10
        ]
    ];

    // Extract recipient email
    if (preg_match('/recipient_email:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
        $config['contact_form']['recipient_email'] = trim($matches[1]);
    }

    // Extract subject prefix
    if (preg_match('/subject_prefix:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
        $config['contact_form']['subject_prefix'] = trim($matches[1]);
    }

    // Extract submissions file
    if (preg_match('/submissions_file:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
        $config['logging']['submissions_file'] = trim($matches[1]);
    }

    // Extract honeypot field
    if (preg_match('/honeypot_field:\s*["\']?([^"\'\n]+)["\']?/', $content, $matches)) {
        $config['security']['honeypot_field'] = trim($matches[1]);
    }

    return $config;
}

// Get form data
$data = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $data = $_POST;
}

// Honeypot check (spam prevention)
$honeypotField = $config['security']['honeypot_field'] ?? 'website_url';
if (!empty($data[$honeypotField])) {
    // Bot detected - pretend success but do nothing
    echo json_encode(['success' => true, 'message' => 'Thank you for your message!']);
    exit();
}

// Validate required fields based on form type
$formType = $data['formType'] ?? 'message';
$errors = [];

if ($formType === 'quote') {
    // Quote form requires: name, email, phone
    $required = ['name', 'email', 'phone'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst($field) . ' is required';
        }
    }
} else {
    // Message form requires: firstName, lastName, email, message
    $required = ['firstName', 'lastName', 'email', 'message'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst($field) . ' is required';
        }
    }
}

// Validate email format
if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors), 'errors' => $errors]);
    exit();
}

// Sanitize input based on form type
$submission = [
    'id' => uniqid('sub_'),
    'timestamp' => date('c'),
    'date' => date('Y-m-d'),
    'time' => date('H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'formType' => $formType,
    'email' => filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL),
    'phone' => htmlspecialchars(trim($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'referer' => $_SERVER['HTTP_REFERER'] ?? 'direct'
];

if ($formType === 'quote') {
    // Quote form fields
    $submission['name'] = htmlspecialchars(trim($data['name']), ENT_QUOTES, 'UTF-8');
    $submission['containerSize'] = htmlspecialchars(trim($data['containerSize'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['condition'] = htmlspecialchars(trim($data['condition'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['intention'] = htmlspecialchars(trim($data['intention'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['delivery'] = htmlspecialchars(trim($data['delivery'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['locationType'] = htmlspecialchars(trim($data['locationType'] ?? ''), ENT_QUOTES, 'UTF-8');
    // Urban address fields
    $submission['streetAddress'] = htmlspecialchars(trim($data['streetAddress'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['city'] = htmlspecialchars(trim($data['city'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['postalCode'] = htmlspecialchars(trim($data['postalCode'] ?? ''), ENT_QUOTES, 'UTF-8');
    // Rural address fields
    $submission['landLocation'] = htmlspecialchars(trim($data['landLocation'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['additionalDirections'] = htmlspecialchars(trim($data['additionalDirections'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['message'] = htmlspecialchars(trim($data['message'] ?? ''), ENT_QUOTES, 'UTF-8');
    $submission['subject'] = 'Quote Request';
} else {
    // Message form fields
    $submission['firstName'] = htmlspecialchars(trim($data['firstName']), ENT_QUOTES, 'UTF-8');
    $submission['lastName'] = htmlspecialchars(trim($data['lastName']), ENT_QUOTES, 'UTF-8');
    $submission['subject'] = htmlspecialchars(trim($data['subject'] ?? 'General Inquiry'), ENT_QUOTES, 'UTF-8');
    $submission['message'] = htmlspecialchars(trim($data['message']), ENT_QUOTES, 'UTF-8');
}

// Log submission to file
$logFile = dirname(__DIR__) . '/' . ($config['logging']['submissions_file'] ?? 'data/submissions.json');
$logDir = dirname($logFile);

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$submissions = [];
if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    $submissions = json_decode($content, true) ?? [];
}

// Add new submission at the beginning
array_unshift($submissions, $submission);

// Save log
if (file_put_contents($logFile, json_encode($submissions, JSON_PRETTY_PRINT)) === false) {
    error_log('Failed to write submission log: ' . $logFile);
}

// Send email notification
$recipientEmail = $config['contact_form']['recipient_email'] ?? 'ccansam22@gmail.com';
$subjectPrefix = $config['contact_form']['subject_prefix'] ?? '[Contact Form]';
$resendApiKey = $config['email']['resend_api_key'] ?? '';
$fromEmail = $config['email']['from_email'] ?? 'noreply@ccansam.com';

if ($formType === 'quote') {
    $emailSubject = $subjectPrefix . ' Quote Request from ' . $submission['name'];

    $emailBody = "New Quote Request:\n\n";
    $emailBody .= "Name: {$submission['name']}\n";
    $emailBody .= "Email: {$submission['email']}\n";
    $emailBody .= "Phone: {$submission['phone']}\n";
    $emailBody .= "Date: {$submission['date']} at {$submission['time']}\n";
    $emailBody .= "\n--- Container Details ---\n\n";
    $emailBody .= "Container Size: {$submission['containerSize']}\n";
    $emailBody .= "Condition: {$submission['condition']}\n";
    $emailBody .= "Intention: {$submission['intention']}\n";
    $emailBody .= "Delivery: {$submission['delivery']}\n";

    if (!empty($submission['locationType'])) {
        $emailBody .= "\n--- Delivery Location ---\n\n";
        $emailBody .= "Location Type: {$submission['locationType']}\n";
        if ($submission['locationType'] === 'Urban') {
            $emailBody .= "Street Address: {$submission['streetAddress']}\n";
            $emailBody .= "City: {$submission['city']}\n";
            $emailBody .= "Postal Code: {$submission['postalCode']}\n";
        } else {
            $emailBody .= "Land Location: {$submission['landLocation']}\n";
            if (!empty($submission['additionalDirections'])) {
                $emailBody .= "Additional Directions: {$submission['additionalDirections']}\n";
            }
        }
    }

    if (!empty($submission['message'])) {
        $emailBody .= "\n--- Additional Message ---\n\n";
        $emailBody .= $submission['message'];
    }

    $emailBody .= "\n\n--- End of Request ---\n";
} else {
    $emailSubject = $subjectPrefix . ' ' . $submission['subject'] . ' from ' . $submission['firstName'];

    $emailBody = "New contact form submission:\n\n";
    $emailBody .= "Name: {$submission['firstName']} {$submission['lastName']}\n";
    $emailBody .= "Email: {$submission['email']}\n";
    $emailBody .= "Phone: " . ($submission['phone'] ?: 'Not provided') . "\n";
    $emailBody .= "Subject: {$submission['subject']}\n";
    $emailBody .= "Date: {$submission['date']} at {$submission['time']}\n";
    $emailBody .= "\n--- Message ---\n\n";
    $emailBody .= $submission['message'];
    $emailBody .= "\n\n--- End of Message ---\n";
}

$emailBody .= "\nIP: {$submission['ip']}\n";
$emailBody .= "Submission ID: {$submission['id']}\n";

$emailSent = false;

// Try Resend API first (recommended)
if (!empty($resendApiKey)) {
    $emailSent = sendViaResend($resendApiKey, $fromEmail, $recipientEmail, $submission['email'], $emailSubject, $emailBody);
} else {
    // Fallback to PHP mail()
    $emailHeaders = [
        'From: ' . $fromEmail,
        'Reply-To: ' . $submission['email'],
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: text/plain; charset=UTF-8'
    ];
    $emailSent = @mail($recipientEmail, $emailSubject, $emailBody, implode("\r\n", $emailHeaders));
}

if (!$emailSent) {
    error_log('Failed to send contact form email to: ' . $recipientEmail);
}

/**
 * Send email via Resend API
 * https://resend.com - Free tier: 3,000 emails/month
 */
function sendViaResend($apiKey, $from, $to, $replyTo, $subject, $body) {
    $data = [
        'from' => $from,
        'to' => [$to],
        'reply_to' => $replyTo,
        'subject' => $subject,
        'text' => $body
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log('Resend cURL error: ' . $error);
        return false;
    }

    if ($httpCode !== 200) {
        error_log('Resend API error (HTTP ' . $httpCode . '): ' . $response);
        return false;
    }

    return true;
}

// Return success response
echo json_encode([
    'success' => true,
    'message' => 'Thank you for your message! We\'ll get back to you soon.',
    'id' => $submission['id']
]);
