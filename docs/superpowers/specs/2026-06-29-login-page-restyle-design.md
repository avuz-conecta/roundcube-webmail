# Login Page Restyle — Avuz Skin

**Date:** 2026-06-29
**Branch:** avuz-customization
**Status:** Approved design

## Goal

Restyle the webmail login page to match the ConectaMail mockup: light-grey
page background, centered white rounded card, ConectaMail logo, light pill
input fields, black "Entrar" button.

Pure visual change. No new functionality.

## Constraints / Decisions

- **No remember-me checkbox.** Roundcube has no native one; not adding it.
- **No password eye-toggle.** Not native; not adding it.
- **Black submit button** (mockup), overriding the current lime accent — login only.
- Items the mockup omits (device-login, forgot-password, footer icons) do not
  exist in roundcube's login template, so nothing to remove.
- In-app UI (header/taskbar cyan + lime) stays unchanged. Only the login view changes.

## Why no template override

The avuz skin is a CSS-only child of elastic. The inherited
`skins/elastic/templates/login.html` already renders exactly: logo, username,
password, submit button, footer (product info / support link). That matches the
target. So the restyle is achievable with CSS + one logo asset + one config key.
No template/JS/PHP changes.

## Changes

### 1. Logo asset

- Source: `ConectaMail.png` at repo root (2160×2160, large white padding).
- Trim padding to content with ImageMagick (same approach as the recent favicon
  rebuild): produce `skins/avuz/images/login-logo.png`.
- Wire it for the login template only by adding a `skin_logo` key in
  `config/config.inc.php`:

  ```php
  'login' => '/skins/avuz/images/login-logo.png',
  ```

  Resolution order in `rcmail_output_html.php::get_template_logo` checks the
  `login` template key before the catch-all, so the in-app header logo is
  unaffected.

### 2. CSS — `skins/avuz/styles/styles.min.css`

Appended/updated rules:

- `body` background `#f2f6fb` → `#f1f1f1`.
- `#login-form`: white card, ~25px radius, soft shadow, centered, generous padding.
- `#logo`: centered, max-width ~220px, margin-bottom.
- Login inputs (`#rcmloginuser`, `#rcmloginpwd`): light-grey fill (`#ededed`),
  rounded/pill, no hard border, focus ring.
- `#rcmloginsubmit`: black background, white bold text, full-width pill, dark-grey
  hover. Overrides the existing lime `#rcmloginsubmit` rule.
- `#login-footer`: muted, minimal.

### 3. Untouched

- In-app header/taskbar colors.
- No template, JS, or PHP files.

## Acceptance Criteria

- Login page: `#f1f1f1` background, centered white rounded card.
- ConectaMail logo centered at top of card, not tiny/over-padded.
- Username + password fields render as light pill fields.
- "Entrar" button is a black full-width pill.
- No remember-me, no eye-toggle, no extra footer links/icons.
- After login, in-app UI looks the same as before (cyan/lime intact).

## Out of Scope

- Remember-me / persistent login.
- Password visibility toggle.
- Any change to authenticated views.
