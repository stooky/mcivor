#!/usr/bin/env node
/**
 * Deploy script for C-Can Sam
 *
 * Usage:
 *   npm run deploy:staging    - Deploy to ccan.crkid.com
 *   npm run deploy:prod       - Deploy to ccansam.com
 *   npm run deploy            - Deploy to staging (default)
 */

import { execSync } from 'child_process';

const ENVIRONMENTS = {
  staging: {
    name: 'Staging',
    host: 'ccan.crkid.com',
    user: 'root',
    path: '/var/www/ccan',
    url: 'https://ccan.crkid.com',
  },
  production: {
    name: 'Production',
    host: 'ccansam.com',
    user: 'root',
    path: '/var/www/ccan',
    url: 'https://ccansam.com',
  },
};

// Parse command line argument
const arg = process.argv[2] || 'staging';
const env = ENVIRONMENTS[arg];

if (!env) {
  console.error(`Unknown environment: ${arg}`);
  console.error('Usage: node scripts/deploy.js [staging|production]');
  process.exit(1);
}

console.log(`\n🚀 Deploying to ${env.name} (${env.host})...\n`);

const sshKey = '~/.ssh/id_rsa';
const sshCmd = `ssh -i ${sshKey} ${env.user}@${env.host}`;
const remoteCmd = `cd ${env.path} && git pull origin storage-containers && npm run build`;

try {
  // Run the deploy command
  execSync(`${sshCmd} "${remoteCmd}"`, {
    stdio: 'inherit',
    shell: true
  });

  console.log(`\n✅ Deployed successfully!`);
  console.log(`   ${env.url}\n`);
} catch (error) {
  console.error(`\n❌ Deployment failed`);
  process.exit(1);
}
