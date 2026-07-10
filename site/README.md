# Public website

This directory contains only the public marketing website.

- `landing/` — current landing page and its legacy layered PHP versions.
- `blog/` — blog index, articles and shared blog assets.
- `legal/` — privacy, cookies and terms pages with shared styles.

The public URLs remain unchanged through the root `.htaccess` dispatcher.
The Telegram Mini App (`/app`) and bot (`/bot`) are separate projects and must not depend on this directory.
