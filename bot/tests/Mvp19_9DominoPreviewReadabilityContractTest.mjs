import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const store = readFileSync(resolve(root, 'app/assets/js/screens/store-screen-domino-store-v1.js'), 'utf8');
const css = readFileSync(resolve(root, 'app/assets/css/games/domino/store-cosmetics-v1.css'), 'utf8');
const manifest = readFileSync(resolve(root, 'app/runtime/client/version-manifest.php'), 'utf8');
const launch = readFileSync(resolve(root, 'bot/helpers/WebAppLaunchUrl.php'), 'utf8');

function expect(condition, message) {
  if (!condition) throw new Error(message);
}

expect(store.includes('headBackMarkup'), 'Domino header must use the dedicated split-back primitive.');
expect(store.includes('[[6,3],[3,5],[5,2]]'), 'Static table/tile previews must use three larger horizontal tiles.');
expect(store.includes('8x5:v3'), 'Store preview signature must publish readability v3.');
expect(store.includes('цельной древесной игровой поверхностью'), 'Walnut copy must describe a full wood playing surface.');
expect(!store.includes('зелёной игровой вставкой'), 'Walnut preview must not retain the old green-insert concept.');
expect(!store.includes('<b>DOMINO</b>'), 'Preview field must not render a DOMINO label.');

expect(css.includes('aspect-ratio:8 / 5!important'), 'Domino preview card must remain 8:5.');
expect(css.includes('aspect-ratio:47 / 24'), 'Domino tiles must keep authentic live proportions.');
expect(css.includes('border-radius:3px'), 'Domino tile corners must stay restrained rather than capsule-like.');
expect(css.includes('inset:3px'), 'Table surface must expand close to the preview edges.');
expect(css.includes('min-width:1.9px'), 'Pips must have a readable minimum size.');
expect(css.includes('theme-walnut .mgw-domino-preview-table'), 'Walnut table must have a dedicated full-surface material.');
expect(css.includes('mgw-domino-head-back>i:first-child'), 'Header backs must visibly split through the centre.');

expect(manifest.includes('domino_preview=expanded-readable-v3'), 'Active Store graph must publish expanded Domino preview v3.');
expect(manifest.includes('domino-expanded-readable-v3'), 'Domino module cache target must publish v3.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1157, 'Telegram entry must publish the post-review cache bump.');

console.log('MVP-19.9 Domino preview readability contract passed.');
