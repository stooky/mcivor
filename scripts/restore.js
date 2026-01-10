#!/usr/bin/env node

/**
 * Restore CLI - Restore a stashed site
 *
 * Usage:
 *   npm run restore <domain>
 *   npm run restore --list
 *   node scripts/restore.js fireandfrostmechanical.ca
 *
 * This script:
 * 1. Reads from stash/<domain>/
 * 2. Copies all stashed files back to their original locations
 * 3. Overwrites current site files
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Reverse mapping: stash location -> original location
const RESTORE_ITEMS = {
  'config/site.ts': 'src/config/site.ts',
  'content/blog': 'src/content/blog',
  'pages/index.astro': 'src/pages/index.astro',
  'pages/about.astro': 'src/pages/about.astro',
  'pages/services.astro': 'src/pages/services.astro',
  'pages/contact.astro': 'src/pages/contact.astro',
  'assets/favicon.svg': 'public/favicon.svg',
  'assets/og-image.png': 'public/og-image.png',
  'assets/images': 'public/images',
  'config/vertical.ts': 'src/config/vertical.ts',
  'config/.vertical': '.vertical',
};

// Parse command line arguments
const args = process.argv.slice(2);
const domain = args[0];
const options = parseOptions(args);

// Show help
if (domain === '--help' || domain === '-h') {
  showHelp();
  process.exit(0);
}

// List available stashes
if (domain === '--list' || domain === '-l' || options.list) {
  listStashes();
  process.exit(0);
}

// Show help if no domain specified
if (!domain) {
  console.log('\n❌ No domain specified.\n');
  listStashes();
  console.log('\nUse: npm run restore <domain>');
  process.exit(1);
}

// Run restore
restore(domain, options);

// ============================================================================
// Main restore function
// ============================================================================

function restore(domain, options) {
  const stashDir = path.join(process.cwd(), 'stash', domain);

  console.log(`
╔══════════════════════════════════════════════════════════════╗
║                     RESTORE SITE                             ║
╠══════════════════════════════════════════════════════════════╣
║  Domain: ${domain.padEnd(50)}║
║  Source: stash/${domain.padEnd(43)}║
╚══════════════════════════════════════════════════════════════╝
`);

  // Check if stash exists
  if (!fs.existsSync(stashDir)) {
    console.log(`❌ No stash found for "${domain}"\n`);
    listStashes();
    process.exit(1);
  }

  // Read stash metadata
  const metadataPath = path.join(stashDir, 'stash.json');
  let metadata = null;
  if (fs.existsSync(metadataPath)) {
    metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf-8'));
    console.log(`📋 Stash info:`);
    console.log(`   Stashed at: ${metadata.stashedAt}`);
    console.log(`   Files: ${metadata.files?.length || 'unknown'}\n`);
  }

  // Confirm restoration
  if (!options.force) {
    console.log('⚠️  This will overwrite current site files!');
    console.log('   Use --force to skip this warning.\n');
    // In a real implementation, we'd prompt for confirmation
    // For now, we require --force
    console.log('   Run: npm run restore ' + domain + ' --force\n');
    process.exit(0);
  }

  // Restore files
  console.log('📋 Restoring files...\n');
  let restoredCount = 0;
  let skippedCount = 0;

  Object.entries(RESTORE_ITEMS).forEach(([source, dest]) => {
    const sourcePath = path.join(stashDir, source);
    const destPath = path.join(process.cwd(), dest);

    if (fs.existsSync(sourcePath)) {
      // Clear destination if it exists (for directories)
      if (fs.existsSync(destPath)) {
        const stats = fs.statSync(destPath);
        if (stats.isDirectory()) {
          // For blog content, clear existing posts
          if (source === 'content/blog') {
            clearDirectory(destPath);
          }
        }
      }

      copyRecursive(sourcePath, destPath);
      console.log(`   ✓ ${dest}`);
      restoredCount++;
    } else {
      console.log(`   ○ ${source} (not in stash)`);
      skippedCount++;
    }
  });

  // Summary
  console.log(`
╔══════════════════════════════════════════════════════════════╗
║                    RESTORE COMPLETE                          ║
╠══════════════════════════════════════════════════════════════╣
║  Files restored: ${String(restoredCount).padEnd(41)}║
║  Files skipped: ${String(skippedCount).padEnd(42)}║
║  Site: ${domain.padEnd(52)}║
╚══════════════════════════════════════════════════════════════╝

Next steps:
  1. Run: npm run dev
  2. Verify the site looks correct
  3. Make any needed adjustments

`);
}

// ============================================================================
// Helper functions
// ============================================================================

function listStashes() {
  const stashDir = path.join(process.cwd(), 'stash');

  if (!fs.existsSync(stashDir)) {
    console.log('📁 No stashes found. Stash directory does not exist.\n');
    return;
  }

  const stashes = fs.readdirSync(stashDir).filter(name => {
    const stashPath = path.join(stashDir, name);
    return fs.statSync(stashPath).isDirectory();
  });

  if (stashes.length === 0) {
    console.log('📁 No stashes found.\n');
    return;
  }

  console.log('📁 Available stashes:\n');

  stashes.forEach(domain => {
    const metadataPath = path.join(stashDir, domain, 'stash.json');
    let info = '';

    if (fs.existsSync(metadataPath)) {
      const metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf-8'));
      const date = new Date(metadata.stashedAt).toLocaleDateString();
      info = ` (stashed: ${date})`;
    }

    console.log(`   • ${domain}${info}`);
  });

  console.log('');
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

function clearDirectory(dir) {
  if (!fs.existsSync(dir)) return;

  const files = fs.readdirSync(dir);
  files.forEach(file => {
    const filePath = path.join(dir, file);
    const stats = fs.statSync(filePath);

    if (stats.isDirectory()) {
      clearDirectory(filePath);
      fs.rmdirSync(filePath);
    } else {
      fs.unlinkSync(filePath);
    }
  });
}

function parseOptions(args) {
  const options = {
    force: false,
    list: false,
  };

  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--force' || arg === '-f') {
      options.force = true;
    } else if (arg === '--list' || arg === '-l') {
      options.list = true;
    }
  }

  return options;
}

function showHelp() {
  console.log(`
NOCMS Site Restore
━━━━━━━━━━━━━━━━━━

Restore a previously stashed site configuration.

USAGE:
  npm run restore <domain> [options]
  npm run restore --list

OPTIONS:
  --force, -f    Skip confirmation and overwrite
  --list, -l     List available stashes
  --help, -h     Show this help message

EXAMPLES:
  npm run restore --list
  npm run restore fireandfrostmechanical.ca --force
  npm run restore mysite.com -f

WHAT GETS RESTORED:
  • Site configuration (src/config/site.ts)
  • Blog posts (src/content/blog/)
  • Page content (src/pages/*.astro)
  • Favicon and social images
  • Site images

`);
}
