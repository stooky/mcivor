/**
 * Local Development Contact Form Handler
 *
 * Simple Node.js server for testing contact form locally.
 * Only logs submissions - no email sending.
 *
 * Usage: node api/contact-local.js
 * Then form posts to: http://localhost:3001/contact
 */

import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PORT = 3001;
const LOG_FILE = path.join(__dirname, '..', 'data', 'submissions.json');

// Ensure data directory exists
const dataDir = path.dirname(LOG_FILE);
if (!fs.existsSync(dataDir)) {
  fs.mkdirSync(dataDir, { recursive: true });
}

const server = http.createServer((req, res) => {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Content-Type', 'application/json');

  // Handle preflight
  if (req.method === 'OPTIONS') {
    res.writeHead(200);
    res.end();
    return;
  }

  // Only handle POST to /contact
  if (req.method !== 'POST' || !req.url.startsWith('/contact')) {
    res.writeHead(404);
    res.end(JSON.stringify({ success: false, message: 'Not found' }));
    return;
  }

  let body = '';
  req.on('data', chunk => body += chunk);
  req.on('end', () => {
    try {
      const data = JSON.parse(body);

      // Validate required fields
      const required = ['firstName', 'lastName', 'email', 'message'];
      const missing = required.filter(f => !data[f]);
      if (missing.length > 0) {
        res.writeHead(400);
        res.end(JSON.stringify({
          success: false,
          message: `Missing required fields: ${missing.join(', ')}`
        }));
        return;
      }

      // Create submission record
      const submission = {
        id: 'sub_' + Date.now().toString(36),
        timestamp: new Date().toISOString(),
        date: new Date().toISOString().split('T')[0],
        time: new Date().toTimeString().split(' ')[0],
        ip: req.socket.remoteAddress || 'localhost',
        firstName: data.firstName,
        lastName: data.lastName,
        email: data.email,
        phone: data.phone || '',
        subject: data.subject || 'General Inquiry',
        message: data.message,
        userAgent: req.headers['user-agent'] || 'unknown',
        referer: req.headers['referer'] || 'direct'
      };

      // Load existing submissions
      let submissions = [];
      if (fs.existsSync(LOG_FILE)) {
        try {
          submissions = JSON.parse(fs.readFileSync(LOG_FILE, 'utf8'));
        } catch (e) {
          submissions = [];
        }
      }

      // Add new submission at the beginning
      submissions.unshift(submission);

      // Save to file
      fs.writeFileSync(LOG_FILE, JSON.stringify(submissions, null, 2));

      // Log to console
      console.log('\n📬 New contact form submission:');
      console.log('─'.repeat(40));
      console.log(`Name:    ${submission.firstName} ${submission.lastName}`);
      console.log(`Email:   ${submission.email}`);
      console.log(`Phone:   ${submission.phone || 'Not provided'}`);
      console.log(`Subject: ${submission.subject}`);
      console.log(`Message: ${submission.message.substring(0, 100)}${submission.message.length > 100 ? '...' : ''}`);
      console.log(`ID:      ${submission.id}`);
      console.log('─'.repeat(40));
      console.log(`Logged to: ${LOG_FILE}`);
      console.log(`Total submissions: ${submissions.length}\n`);

      res.writeHead(200);
      res.end(JSON.stringify({
        success: true,
        message: 'Thank you for your message! (Local dev - logged to file)',
        id: submission.id
      }));

    } catch (e) {
      console.error('Error processing submission:', e);
      res.writeHead(400);
      res.end(JSON.stringify({ success: false, message: 'Invalid request data' }));
    }
  });
});

server.listen(PORT, () => {
  console.log(`\n🚀 Local contact form server running at http://localhost:${PORT}`);
  console.log(`   POST to http://localhost:${PORT}/contact`);
  console.log(`   Submissions logged to: ${LOG_FILE}\n`);
});
