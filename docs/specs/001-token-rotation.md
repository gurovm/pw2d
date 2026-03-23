# Spec 001: Extension Token Rotation & Hardening

**Priority:** CRITICAL — blocks all other security fixes
**Audit refs:** Security #1 (hardcoded token), Security #9 (query string leak), Reviewer #7 (orphaned auth)

---

## Problem

The Chrome Extension shared secret is hardcoded in `chrome_extension/popup.js:6`:
```
const EXTENSION_TOKEN = '626f897ea3ed...';
```
Anyone with repo access (or a leaked commit) has the production token. Additionally, `VerifyExtensionToken` middleware accepts the token via `?token=` query string (`line 21`), which leaks the secret into server access logs, browser history, and proxy caches.

## Changes Required

### 1. Rotate the token in production `.env`
- Generate a new token: `openssl rand -hex 32`
- Update `CHROME_EXTENSION_KEY` in the production `.env` file on the server.
- The existing `config('services.extension.token')` already reads from `.env` — no config change needed.

### 2. Remove the query string fallback in `VerifyExtensionToken`

**File:** `app/Http/Middleware/VerifyExtensionToken.php`

```php
// BEFORE (line 20-21)
$provided = $request->header('X-Extension-Token')
    ?? $request->query('token');

// AFTER
$provided = $request->header('X-Extension-Token') ?? '';
```

The Chrome Extension already sends the header (`X-Extension-Token`). The query string path is unused legacy code.

### 3. Move the token in `popup.js` to runtime config

**File:** `chrome_extension/popup.js`

Replace the hardcoded constant with a value read from `chrome.storage.local`. On first install, the user sets their token via the popup UI.

```js
// BEFORE (line 6)
const EXTENSION_TOKEN = '626f897e...';

// AFTER
let EXTENSION_TOKEN = '';
chrome.storage.local.get('extensionToken', (result) => {
    EXTENSION_TOKEN = result.extensionToken || '';
});
```

Add a small "API Token" input field in `popup.html` (collapsed under a settings gear icon) that saves to `chrome.storage.local`.

### 4. Remove the old hardcoded token from git history (optional but recommended)

Run `git filter-repo` or `BFG Repo Cleaner` to scrub the old token from history, or accept the risk since we're rotating anyway.

## Files Modified

| File | Action |
|------|--------|
| `app/Http/Middleware/VerifyExtensionToken.php` | Remove query string fallback |
| `chrome_extension/popup.js` | Replace hardcoded token with `chrome.storage.local` |
| `chrome_extension/popup.html` | Add collapsed token config input |
| Production `.env` | Rotate `CHROME_EXTENSION_KEY` value |

## Testing

- **Unit:** Test `VerifyExtensionToken` rejects requests with token only in query string.
- **Unit:** Test `VerifyExtensionToken` accepts valid `X-Extension-Token` header.
- **Unit:** Test `VerifyExtensionToken` rejects empty/missing token.
- **Manual:** Load extension, set token in popup settings, verify scrape flow works.

## Rollout

1. Deploy the middleware change + extension update.
2. Rotate the token in production `.env`.
3. Set the new token in the Chrome Extension popup.
4. Verify import flow works end-to-end.
