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
expect(store.includes('8x5:v4'), 'Store preview signature must publish uniform readability v4.');
expect(store.includes('domino-uniform-fullfield-v4'), 'Store must load the v4 full-field Domino stylesheet.');
expect(store.includes('цельной древесной игровой поверхностью'), 'Walnut copy must describe a full wood playing surface.');
expect(!store.includes('зелёной игровой вставкой'), 'Walnut preview must not retain the old green-insert concept.');
expect(!store.includes('<b>DOMINO</b>'), 'Preview field must not render a DOMINO label.');

expect(css.includes('aspect-ratio:8 / 5!important'), 'Domino preview card must remain 8:5.');
expect(css.includes('aspect-ratio:47 / 24'), 'Domino tiles must keep authentic live proportions.');
expect(css.includes('border-radius:3px'), 'Domino tile corners must stay restrained rather than capsule-like.');
expect(css.includes('inset:1px'), 'Table surface must fill the preview with only a minimal outer rim.');
expect(css.includes('grid-template-columns:repeat(3,minmax(0,1fr))'), 'Static Domino tiles must be three exactly equal grid columns.');
expect(css.includes('.mgw-domino-stock{position:relative;display:block;width:31.5%;height:auto;aspect-ratio:47 / 24'), 'Face-down stock tiles must use the same 47:24 geometry and scale as face-up tiles.');
expect(css.includes('min-width:2px'), 'Pips must keep a readable minimum size.');
expect(css.includes('theme-walnut .mgw-domino-preview-table'), 'Walnut table must have a dedicated full-surface material.');
expect(css.includes('mgw-domino-head-back>i:first-child'), 'Header backs must visibly split through the centre.');
expect(css.includes('mgw-domino-head-back>i::after{display:none!important;content:none!important}'), 'Header backs must not contain decorative circles/insets.');

expect(manifest.includes('domino_preview=expanded-readable-v3'), 'Accepted Store owner graph must remain unchanged while v4 is published by the Domino module itself.');
expect(manifest.includes('domino-expanded-readable-v3'), 'Accepted Domino module import-map key must remain stable.');
const launchMatch = launch.match(/\/app\/v110\.php\?v=(\d+)/);
expect(launchMatch && Number(launchMatch[1]) >= 1158, 'Telegram entry must publish the v4 preview cache bump.');

console.log('MVP-19.9 Domino preview readability v4 contract passed.');
