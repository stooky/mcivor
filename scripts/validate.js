#!/usr/bin/env node

/**
 * Validate CLI - Check site configuration for issues
 *
 * Usage:
 *   npm run validate
 *
 * This script:
 * 1. Checks if a vertical is configured
 * 2. Validates required business information
 * 3. Checks prerequisites for enabled features
 * 4. Reports any issues that need to be fixed
 */

const fs = require('fs');
const path = require('path');

console.log(`
╔══════════════════════════════════════════════════════════════╗
║                  NOCMS CONFIGURATION VALIDATOR               ║
╚══════════════════════════════════════════════════════════════╝
`);

// Check if vertical is initialized
const markerPath = path.join(process.cwd(), '.vertical');
if (!fs.existsSync(markerPath)) {
  console.log('❌ No vertical initialized yet.');
  console.log('   Run: npm run launch <vertical>\n');
  process.exit(1);
}

const marker = JSON.parse(fs.readFileSync(markerPath, 'utf-8'));
console.log(`Vertical: ${marker.name} (${marker.vertical})`);
console.log(`Category: ${marker.category}`);
console.log(`Initialized: ${marker.initializedAt}\n`);

// Check vertical config exists
const configPath = path.join(process.cwd(), 'src/config/vertical.ts');
if (!fs.existsSync(configPath)) {
  console.log('❌ Vertical config not found: src/config/vertical.ts');
  console.log('   Run: npm run launch ' + marker.vertical + ' --force\n');
  process.exit(1);
}

console.log('✓ Vertical configuration found\n');

// Check for required files
console.log('Checking required files...\n');

const requiredFiles = [
  { path: 'src/config/site.ts', description: 'Site configuration' },
  { path: 'public/favicon.svg', description: 'Favicon' },
];

const optionalFiles = [
  { path: 'public/images/logo.png', description: 'Logo image' },
  { path: 'public/og-image.png', description: 'Social share image (1200x630)' },
];

let hasErrors = false;

requiredFiles.forEach(file => {
  const exists = fs.existsSync(path.join(process.cwd(), file.path));
  if (exists) {
    console.log(`   ✓ ${file.path}`);
  } else {
    console.log(`   ❌ ${file.path} - ${file.description} (REQUIRED)`);
    hasErrors = true;
  }
});

console.log('');

optionalFiles.forEach(file => {
  const exists = fs.existsSync(path.join(process.cwd(), file.path));
  if (exists) {
    console.log(`   ✓ ${file.path}`);
  } else {
    console.log(`   ⚠ ${file.path} - ${file.description} (optional)`);
  }
});

console.log('');

// Check for placeholder content
console.log('Checking for placeholder content...\n');

const filesToCheck = [
  'src/config/site.ts',
  'src/config/vertical.ts',
];

let placeholderWarnings = [];

filesToCheck.forEach(filePath => {
  const fullPath = path.join(process.cwd(), filePath);
  if (fs.existsSync(fullPath)) {
    const content = fs.readFileSync(fullPath, 'utf-8');

    // Check for common placeholders
    const placeholders = [
      { pattern: /example\.com/gi, description: 'example.com domain' },
      { pattern: /\(555\)/g, description: '(555) phone number' },
      { pattern: /123 Main/gi, description: '123 Main Street address' },
      { pattern: /ABC Heating/gi, description: 'ABC Heating placeholder name' },
      { pattern: /ABC Plumbing/gi, description: 'ABC Plumbing placeholder name' },
      { pattern: /Springfield/g, description: 'Springfield placeholder city' },
    ];

    placeholders.forEach(({ pattern, description }) => {
      if (pattern.test(content)) {
        placeholderWarnings.push({
          file: filePath,
          issue: `Contains placeholder: ${description}`,
        });
      }
    });
  }
});

if (placeholderWarnings.length > 0) {
  console.log('   ⚠ Placeholder content detected:\n');
  placeholderWarnings.forEach(warning => {
    console.log(`      ${warning.file}: ${warning.issue}`);
  });
  console.log('\n   Update these with real business information before launch.\n');
} else {
  console.log('   ✓ No obvious placeholder content detected\n');
}

// Summary
console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

if (hasErrors) {
  console.log('\n❌ Validation FAILED - fix required issues above\n');
  process.exit(1);
} else if (placeholderWarnings.length > 0) {
  console.log('\n⚠ Validation PASSED with warnings\n');
  console.log('The site will build, but update placeholder content before launch.\n');
  process.exit(0);
} else {
  console.log('\n✓ Validation PASSED\n');
  console.log('Ready to build: npm run build\n');
  process.exit(0);
}
