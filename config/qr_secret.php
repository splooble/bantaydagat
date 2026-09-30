<?php
/**
 * QR Encryption Secret Key
 * ─────────────────────────────────────────────────────────────────────────
 * KEEP THIS FILE PRIVATE. Never commit to version control.
 * Never expose in public directories.
 *
 * To regenerate the key run in PHP CLI:
 *   php -r "echo bin2hex(random_bytes(32));"
 * Then paste the result below.
 *
 * Changing this key invalidates ALL existing QR cards — regenerate them.
 */

define('QR_SECRET_KEY', hex2bin('cafa7a7764225f689b73b7109f31c414c6631f12afb78d9448b66f4dfbeeb285'));
// ^ Replace this with your own generated key before deploying to production

/**
 * QR token validity window in seconds.
 * Tokens older than this are rejected even if the signature is valid.
 * Set to 0 to disable expiry (tokens valid forever).
 * Recommended: 0 for permit cards (they're printed/downloaded).
 */
define('QR_TOKEN_TTL', 0);