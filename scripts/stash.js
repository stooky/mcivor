#!/usr/bin/env node

/**
 * Stash CLI - Save current site to stash folder
 *
 * Usage:
 *   npm run stash <domain>
 *   node scripts/stash.js fireandfrostmechanical.ca
 *
 * This script:
 * 1. Creates a folder under stash/<domain>/
 * 2. Copies all site-specific files to the stash
 * 3. Clears the active site files (optional)
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Files and directories to stash
const STASH_ITEMS = {
  // Config files
  'src/config/site.ts': 'config/site.ts',

  // Content
  'src/content/blog': 'content/blog',

  // Pages (full page files)
  'src/pages/index.astro': 'pages/index.astro',
  'src/pages/about.astro': 'pages/about.astro',
  'src/pages/services.astro': 'pages/services.astro',
  'src/pages/contact.astro': 'pages/contact.astro',

  // Assets
  'public/favicon.svg': 'assets/favicon.svg',
  'public/og-image.png': 'assets/og-image.png',
  'public/images': 'assets/images',

  // Vertical config (if exists)
  'src/config/vertical.ts': 'config/vertical.ts',
  '.vertical': 'config/.vertical',
};

// Parse command line arguments
const args = process.argv.slice(2);
const domain = args[0];
const options = parseOptions(args.slice(1));

// Show help if no domain specified
if (!domain || domain === '--help' || domain === '-h') {
  showHelp();
  process.exit(0);
}

// Validate domain format
if (!isValidDomain(domain)) {
  console.error(`\n❌ Invalid domain format: "${domain}"\n`);
  console.error('Domain should be like: example.com or subdomain.example.com');
  process.exit(1);
}

// Run stash
stash(domain, options);

// ============================================================================
// Main stash function
// ============================================================================

function stash(domain, options) {
  const stashDir = path.join(process.cwd(), 'stash', domain);

  console.log(`
╔══════════════════════════════════════════════════════════════╗
║                      STASH SITE                              ║
╠══════════════════════════════════════════════════════════════╣
║  Domain: ${domain.padEnd(50)}║
║  Target: stash/${domain.padEnd(43)}║
╚══════════════════════════════════════════════════════════════╝
`);

  // Check if stash already exists
  if (fs.existsSync(stashDir) && !options.force) {
    console.log(`⚠️  Stash already exists for "${domain}"`);
    console.log('   Use --force to overwrite.\n');
    process.exit(1);
  }

  // Create stash directory structure
  console.log('📁 Creating stash directory...');
  createStashDirectories(stashDir);

  // Copy files to stash
  console.log('📋 Copying files to stash...\n');
  let stashedCount = 0;
  let skippedCount = 0;

  Object.entries(STASH_ITEMS).forEach(([source, dest]) => {
    const sourcePath = path.join(process.cwd(), source);
    const destPath = path.join(stashDir, dest);

    if (fs.existsSync(sourcePath)) {
      copyRecursive(sourcePath, destPath);
      console.log(`   ✓ ${source}`);
      stashedCount++;
    } else {
      console.log(`   ○ ${source} (not found, skipping)`);
      skippedCount++;
    }
  });

  // Create stash metadata
  console.log('\n📝 Creating stash metadata...');
  createStashMetadata(stashDir, domain);

  // Summary
  console.log(`
╔══════════════════════════════════════════════════════════════╗
║                      STASH COMPLETE                          ║
╠══════════════════════════════════════════════════════════════╣
║  Files stashed: ${String(stashedCount).padEnd(42)}║
║  Files skipped: ${String(skippedCount).padEnd(42)}║
║  Location: stash/${domain.padEnd(41)}║
╚══════════════════════════════════════════════════════════════╝

To restore this site later, run:
  npm run restore ${domain}

To start a new site, you can now:
  1. Clear current files manually, or
  2. Run: npm run restore <other-domain>
`);
}

// ============================================================================
// Helper functions
// ============================================================================

function createStashDirectories(stashDir) {
  const dirs = ['config', 'content/blog', 'pages', 'assets/images'];

  dirs.forEach(dir => {
    const fullPath = path.join(stashDir, dir);
    fs.mkdirSync(fullPath, { recursive: true });
  });

  console.log('   ✓ Created directory structure\n');
}

function copyRecursive(source, dest) {
  const stats = fs.statSync(source);

  if (stats.isDirectory()) {
    fs.mkdirSync(dest, { recursive: true });
    const files = fs.readdirSync(source);
    files.forEach(file => {
      copyRecursive(path.join(source, file), path.join(dest, file));
    });
  } else {
    // Ensure parent directory exists
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.copyFileSync(source, dest);
  }
}

function createStashMetadata(stashDir, domain) {
  const metadata = {
    domain,
    stashedAt: new Date().toISOString(),
    version: '1.0.0',
    files: Object.keys(STASH_ITEMS).filter(source =>
      fs.existsSync(path.join(process.cwd(), source))
    ),
  };

  const metadataPath = path.join(stashDir, 'stash.json');
  fs.writeFileSync(metadataPath, JSON.stringify(metadata, null, 2));
  console.log('   ✓ Created stash.json metadata\n');
}

function isValidDomain(domain) {
  // Basic domain validation
  const domainRegex = /^[a-zA-Z0-9][a-zA-Z0-9-]*(\.[a-zA-Z0-9][a-zA-Z0-9-]*)+$/;
  return domainRegex.test(domain);
}

function parseOptions(args) {
  const options = {
    force: false,
  };

  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--force' || arg === '-f') {
      options.force = true;
    }
  }

  return options;
}

function showHelp() {
  console.log(`
NOCMS Site Stash
━━━━━━━━━━━━━━━━

Save the current site configuration to a stash folder.

USAGE:
  npm run stash <domain> [options]

OPTIONS:
  --force, -f    Overwrite existing stash
  --help, -h     Show this help message

EXAMPLES:
  npm run stash fireandfrostmechanical.ca
  npm run stash mysite.com --force

WHAT GETS STASHED:
  • src/config/site.ts        → Site configuration
  • src/content/blog/         → Blog posts
  • src/pages/*.astro         → Page content
  • public/favicon.svg        → Favicon
  • public/og-image.png       → Social image
  • public/images/            → Site images

The stash is saved to: stash/<domain>/
`);
}
