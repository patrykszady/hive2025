/**
 * Stamp public/sw.js with a unique deploy version.
 *
 * Run automatically via `npm run build`.
 * The service worker uses versioned cache names so each deploy creates
 * fresh caches, and the activate handler purges old ones.
 */

import { readFileSync, writeFileSync } from 'fs';

const SW_PATH = 'public/sw.js';
const version = Date.now().toString(36);

let content = readFileSync(SW_PATH, 'utf8');

// Replace both the placeholder (first build) and any previously-stamped version
content = content.replace(
    /const DEPLOY_VERSION = '[^']*';/,
    `const DEPLOY_VERSION = '${version}';`
);

writeFileSync(SW_PATH, content);
console.log(`[stamp-sw] Service worker cache version: ${version}`);
