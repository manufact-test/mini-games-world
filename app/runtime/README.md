# Mini Games World clean runtime

This directory is an isolated staging runtime for MVP-14R3.

Open `index.php` directly in a staging environment. It does not replace or import the legacy production application.

Current package scope:

- canonical standard/invite launch parsing;
- one application store;
- one explicit router;
- controlled error boundary;
- architecture contract guard.

Not connected yet:

- authentication;
- server bootstrap;
- storage adapter;
- match, invite and notification product contours.

Those are added only through clean modules in subsequent packages.
