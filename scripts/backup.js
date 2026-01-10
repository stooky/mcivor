#!/usr/bin/env node
/**
 * C-Can Sam Backup Script
 *
 * Backs up submissions.json and reviews.json by emailing a zip to the admin.
 *
 * Usage:
 *   npm run backup
 *   node scripts/backup.js
 */

import fs from 'fs';
import path from 'path';
import https from 'https';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Paths
const ROOT = path.join(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'data');
const CONFIG_PATH = path.join(ROOT, 'config.yaml');
const LOCAL_CONFIG_PATH = path.join(ROOT, 'config.local.yaml');

// Files to backup
const BACKUP_FILES = [
  'submissions.json',
  'reviews.json'
];

// Simple YAML parser for our config
function parseConfig(content) {
  const config = {};
  const lines = content.split('\n');
  let currentSection = null;

  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;

    // Section header
    if (!line.startsWith(' ') && !line.startsWith('\t') && trimmed.endsWith(':') && !trimmed.includes('"')) {
      currentSection = trimmed.slice(0, -1);
      config[currentSection] = {};
      continue;
    }

    // Key-value pair
    const match = trimmed.match(/^(\w+):\s*["']?([^"'\n]+)["']?$/);
    if (match) {
      const [, key, value] = match;
      if (currentSection) {
        config[currentSection][key] = value.trim();
      } else {
        config[key] = value.trim();
      }
    }
  }

  return config;
}

// Deep merge objects (local overrides base)
function deepMerge(base, override) {
  const result = { ...base };
  for (const key of Object.keys(override)) {
    if (override[key] && typeof override[key] === 'object' && !Array.isArray(override[key])) {
      result[key] = deepMerge(base[key] || {}, override[key]);
    } else {
      result[key] = override[key];
    }
  }
  return result;
}

// Load config with local overrides
function loadConfig() {
  const content = fs.readFileSync(CONFIG_PATH, 'utf-8');
  let config = parseConfig(content);

  // Merge local overrides if they exist
  if (fs.existsSync(LOCAL_CONFIG_PATH)) {
    const localContent = fs.readFileSync(LOCAL_CONFIG_PATH, 'utf-8');
    const localConfig = parseConfig(localContent);
    config = deepMerge(config, localConfig);
    console.log('(merged config.local.yaml overrides)');
  }

  return config;
}

// Create zip of backup files
function createBackupZip() {
  const timestamp = new Date().toISOString().split('T')[0];
  const zipName = `ccansam-backup-${timestamp}.zip`;
  const zipPath = path.join(DATA_DIR, zipName);

  // Check which files exist
  const existingFiles = BACKUP_FILES.filter(f =>
    fs.existsSync(path.join(DATA_DIR, f))
  );

  if (existingFiles.length === 0) {
    console.error('No backup files found in data/');
    process.exit(1);
  }

  // Create zip using system zip command or PowerShell
  const isWindows = process.platform === 'win32';

  if (isWindows) {
    // Use PowerShell Compress-Archive
    const files = existingFiles.map(f => `"${path.join(DATA_DIR, f)}"`).join(',');
    execSync(`powershell -Command "Compress-Archive -Path ${files} -DestinationPath '${zipPath}' -Force"`, {
      cwd: ROOT
    });
  } else {
    // Use zip command
    const files = existingFiles.join(' ');
    execSync(`cd "${DATA_DIR}" && zip -j "${zipPath}" ${files}`, {
      cwd: ROOT
    });
  }

  console.log(`Created: ${zipName}`);
  return { zipPath, zipName };
}

// Send email via Resend
function sendBackupEmail(config, zipPath, zipName) {
  return new Promise((resolve, reject) => {
    const apiKey = config.email?.resend_api_key;
    const recipient = config.contact_form?.recipient_email || config.contact?.email;
    const fromEmail = config.email?.from_email || 'C-Can Sam <onboarding@resend.dev>';

    if (!apiKey) {
      reject(new Error('No Resend API key found in config.yaml'));
      return;
    }

    // Read zip file as base64
    const zipContent = fs.readFileSync(zipPath).toString('base64');

    // Get file stats for summary
    const stats = BACKUP_FILES.map(f => {
      const filePath = path.join(DATA_DIR, f);
      if (fs.existsSync(filePath)) {
        const content = JSON.parse(fs.readFileSync(filePath, 'utf-8'));
        if (f === 'submissions.json') {
          return `- ${f}: ${Array.isArray(content) ? content.length : 0} submissions`;
        } else if (f === 'reviews.json') {
          return `- ${f}: ${content.reviews?.length || 0} reviews`;
        }
      }
      return `- ${f}: not found`;
    }).join('\n');

    const today = new Date().toLocaleDateString('en-CA');

    const payload = JSON.stringify({
      from: fromEmail,
      to: [recipient],
      subject: `[C-Can Sam Backup] ${today}`,
      html: `
        <h2>C-Can Sam Data Backup</h2>
        <p>Attached is your backup from <strong>${today}</strong>.</p>
        <h3>Contents:</h3>
        <pre>${stats}</pre>
        <h3>To Restore:</h3>
        <ol>
          <li>Unzip the attachment</li>
          <li>Upload files to <code>/var/www/ccan/data/</code> on your server</li>
          <li>Or commit to the repo under <code>data/</code></li>
        </ol>
        <p style="color: #666; font-size: 12px;">
          This backup was generated automatically. Store it somewhere safe!
        </p>
      `,
      attachments: [
        {
          filename: zipName,
          content: zipContent
        }
      ]
    });

    const options = {
      hostname: 'api.resend.com',
      port: 443,
      path: '/emails',
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiKey}`,
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(payload)
      }
    };

    const req = https.request(options, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        if (res.statusCode >= 200 && res.statusCode < 300) {
          resolve(JSON.parse(data));
        } else {
          reject(new Error(`Resend API error: ${res.statusCode} - ${data}`));
        }
      });
    });

    req.on('error', reject);
    req.write(payload);
    req.end();
  });
}

// Cleanup zip file
function cleanup(zipPath) {
  if (fs.existsSync(zipPath)) {
    fs.unlinkSync(zipPath);
    console.log('Cleaned up temporary zip file');
  }
}

// Main
async function main() {
  console.log('C-Can Sam Backup\n');

  try {
    // Load config
    console.log('Loading config...');
    const config = loadConfig();

    // Create zip
    console.log('Creating backup zip...');
    const { zipPath, zipName } = createBackupZip();

    // Send email
    console.log('Sending backup email...');
    const result = await sendBackupEmail(config, zipPath, zipName);
    console.log(`Email sent! ID: ${result.id}`);

    // Cleanup
    cleanup(zipPath);

    console.log('\n✅ Backup complete!');

  } catch (error) {
    console.error('\n❌ Backup failed:', error.message);
    process.exit(1);
  }
}

main();
