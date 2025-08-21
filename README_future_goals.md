# Plugin Manager — Future Goals & Polished Enhancements

_Last updated: 2025-08-21 04:17:08Z_

This document outlines optional, low-risk enhancements that can be layered onto the existing **plugin_manager** without changing core behavior. Each item is scoped to be **surgical**, behind a config flag when relevant, and reversible.

---

## 1) Per‑Plugin “Last Checked” Timestamps

**Goal:** Each row shows its own “x min ago” based on when _that plugin’s_ remote was checked.

**Design:** Reuse the existing cache file (`pm_cache.json`). The code already stores entries by `sha1(json_encode($sources))`:
```php
$cache[$key] = array('ver' => $ver, 'ts' => $this->last_ts, 'via' => $this->last_via, 'reason' => $this->last_reason);
```
**Implementation notes:**
- In `latest_version_cached()` keep writing `ts` (already done).
- In `render_page()` (or wherever rows are built), locate the cache entry for the current plugin and set:
  ```php
  $checked_ts = isset($cache[$key]['ts']) ? intval($cache[$key]['ts']) : 0;
  ```
- Render the remote cell with the remote version left and `pm_time_ago($checked_ts)` right (current UI already supports this).

**Migration/compat:** Zero. Old cache entries missing `ts` default to `0` (no time shown).

---

## 2) Graceful Error Reporting in UI

**Goal:** When an update fails, indicate it inline in the **Status** cell with a brief reason; keep toasts too.

**Design:** Add a small badge in the status cell on failure with a tooltip:
```html
<span class="pm-badge pm-badge-err" title="HTTP 429 from GitHub API">update failed</span>
```
**Notes:**
- Preserve existing `flash_add()` messages.
- Keep badges short and optional (only when there is a concrete reason).

**CSS (tiny):**
```css
.pm-badge-err { background:#fbe9e9; color:#a33; border-radius:8px; padding:1px 6px; font-size:80%; }
```

---

## 3) Composer-on-Install/Update (Opt‑in)

**Goal:** When a plugin contains `composer.json`, optionally run `composer install` after extraction.

**Safety:** Disabled by default. Enable via config:
```php
$config['pm_enable_composer'] = false;             // default
$config['pm_composer_bin'] = 'composer';
$config['pm_composer_allow_scripts'] = false;      // use --no-scripts unless true
$config['pm_composer_timeout_sec'] = 120;
```

**Call:** In `perform_update()` after unpack, before `merge_config_dist()`:
- Detect `composer.json`.
- Shell out with `proc_open` / `escapeshellarg`:
  - `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress`
  - Add `--no-scripts` unless `pm_composer_allow_scripts` is true.
- Capture stdout/stderr to debug log; surface a concise toast on failure.
- If Composer is missing or `exec` is disabled, just skip and toast a notice.

**Portability:** Graceful fallback if host disables PHP process functions.

---

## 4) Exact Timestamp Tooltips (UI QoL)

**Goal:** Hovering over “x min ago” shows the exact ISO timestamp.

**Status:** Already done for the remote cell (`title="last check at ISO8601"`). Consider adding the same in future places (e.g., bulk summaries).

---

## 5) Cache Hygiene

**Goal:** Prevent cache bloat and keep timestamps fresh.

**Design:**
- Keep current TTL for **version** freshness.
- Add a soft TTL for **timestamp display** (e.g., hide “x min ago” if older than 30 days).
- On “Refresh versions,” force-check all remote entries and update their `ts`.

---

## 6) Webhook/Email Details (Optional)

**Goal:** When updates happen (or fail), include minimal structured data (dir, from, to, reason) so an external system can alert.

**Status:** Basic hooks exist. Consider adding:
- Per-plugin `checked_ts` and `via` fields in the payload.
- A “digest” mode that batches updates within 60s to one webhook call.

---

## 7) Security Posture (Docs Only)

**Goal:** Document that the manager does not execute arbitrary code by default.
- Composer steps are **opt‑in** and **no‑scripts** by default.
- Checksum validation is supported (release assets) and can be required via `pm_require_checksum`.
- Backups & restore keep file-level rollback simple; configs are preserved.

---

## 8) Micro‑UX details

**Ideas:**
- Add a compact legend explaining badges (Pinned, Ignored, etc.).
- In **Update All** dry-run output, show `from→to` versions for the first N items.
- Keep the “Restore” link visible even when up-to-date (already implemented).

---

## Non‑Goals

- No auto‑SQL parsing. If database setup is needed later, prefer a small plugin‑side `pm-install.php` hook using Roundcube’s DB layer with idempotent checks.

---

## Summary

These are small, compartmentalized improvements that won’t surprise admins. The most value for least risk comes from **per‑plugin timestamps** and **inline error badges**, both of which are pure UI/data plumbing on top of the cache that already exists.
