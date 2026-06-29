# Login Page Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the webmail login page to the ConectaMail mockup — grey page, white rounded card, ConectaMail logo, light pill inputs, black "Entrar" button — via CSS, one logo asset, and one config key.

**Architecture:** Avuz is a CSS-only child of the elastic skin. The inherited `skins/elastic/templates/login.html` already renders only logo/username/password/submit/footer, matching the target markup. No template, JS, or PHP changes. Restyle = trimmed logo asset + `skin_logo` `login` key + appended/overridden CSS in `skins/avuz/styles/styles.min.css`.

**Tech Stack:** CSS, PHP config (`config.inc.php`), ImageMagick (`magick`).

## Global Constraints

- Page background: `#f1f1f1` (exact).
- Submit button: black pill, white bold text — login page only.
- Do NOT add remember-me or password eye-toggle.
- Do NOT change in-app header/taskbar colors (cyan `#2bb5e3` / lime `#d2e314` stay).
- No template, JS, or PHP-logic files touched. Only: `config/config.inc.php`, `skins/avuz/styles/styles.min.css`, new image under `skins/avuz/images/`.
- Logo CSS target id: `#logo`. Form id: `#login-form`. Inputs: `#rcmloginuser`, `#rcmloginpwd`. Submit: `#rcmloginsubmit`. Footer: `#login-footer`.

---

### Task 1: ConectaMail login logo asset + config wiring

**Files:**
- Create: `skins/avuz/images/login-logo.png`
- Modify: `config/config.inc.php` (the `$config['skin_logo']` array, around line 87-95)

**Interfaces:**
- Produces: web path `/skins/avuz/images/login-logo.png`, referenced by `skin_logo['login']`.

- [ ] **Step 1: Trim padding from the source logo**

Source `ConectaMail.png` is 2160×2160 with large white padding. Trim to content, keep a small white margin so it doesn't sit edge-to-edge on the card:

```bash
cd /Users/patrickrezende/work/avuz/roundcube-webmail
magick ConectaMail.png -trim +repage -bordercolor white -border 60 skins/avuz/images/login-logo.png
```

- [ ] **Step 2: Verify the asset dimensions are sane**

Run:
```bash
magick identify skins/avuz/images/login-logo.png
```
Expected: a roughly landscape/square PNG far smaller than 2160×2160 (content-cropped, e.g. width on the order of ~1000-1600px). If it is still ~2160×2160, the trim failed — investigate before continuing.

- [ ] **Step 3: Add the `login` key to `skin_logo`**

In `config/config.inc.php`, inside the `$config['skin_logo'] = [ ... ]` array, add a `login` entry (login template only; header logo keys untouched):

```php
$config['skin_logo'] = [
    ''             => '/skins/avuz/images/icon.png',
    'login'        => '/skins/avuz/images/login-logo.png',
    '[favicon]'    => '/skins/avuz/images/favicon.ico',
    '[small]'      => '/skins/avuz/images/icon.png',
    '[dark]'       => '/skins/avuz/images/icon.png',
    '[small-dark]' => '/skins/avuz/images/icon.png',
    '[print]'      => '/skins/avuz/images/logo.png',
    '[link]'       => '',
];
```

- [ ] **Step 4: Verify PHP config still parses**

Run:
```bash
php -l config/config.inc.php
```
Expected: `No syntax errors detected in config/config.inc.php`

- [ ] **Step 5: Commit**

```bash
git add skins/avuz/images/login-logo.png config/config.inc.php
git commit -m "feat(login): add trimmed ConectaMail logo, wire skin_logo login key"
```

---

### Task 2: Login CSS restyle

**Files:**
- Modify: `skins/avuz/styles/styles.min.css`

**Interfaces:**
- Consumes: web path `/skins/avuz/images/login-logo.png` (loaded via the logo `<img>`, not CSS — no direct reference needed here).
- The existing `#rcmloginsubmit` lime rule (lines ~80-93) is replaced by a black rule in this task.

- [ ] **Step 1: Change page background to `#f1f1f1`**

In `skins/avuz/styles/styles.min.css`, change the `body` rule:

```css
body {
  background-color: #f1f1f1;
}
```

- [ ] **Step 2: Replace the lime submit-button rules with black**

Remove the existing `#rcmloginsubmit` and `#rcmloginsubmit:hover` blocks (the lime `#d2e314` ones, ~lines 80-93) and the `/* Login submit button — lime accent */` comment. They are replaced in Step 3's block. (Leave the generic `.button.mainaction` / `input[type="submit"]` rules alone — those serve the in-app UI.)

- [ ] **Step 3: Append the login-view restyle block**

Append to the end of `skins/avuz/styles/styles.min.css`:

```css
/* === Login page — ConectaMail mockup === */
#login-form {
  background: #fff;
  border-radius: 28px;
  box-shadow: 0 8px 40px rgba(0, 0, 0, 0.06);
  padding: 48px 40px;
  max-width: 440px;
  margin: 0 auto;
}

#logo {
  display: block;
  margin: 0 auto 32px;
  max-width: 220px;
  height: auto;
}

#rcmloginuser,
#rcmloginpwd {
  background-color: #ededed;
  border: none;
  border-radius: 22px;
  padding: 14px 20px;
  height: auto;
  box-shadow: none;
}

#rcmloginuser:focus,
#rcmloginpwd:focus {
  background-color: #e8e8e8;
  border: none;
  box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.08);
}

#rcmloginsubmit {
  background-color: #000 !important;
  border-color: #000 !important;
  color: #fff !important;
  font-weight: 700;
  border-radius: 50px;
  width: 100%;
  padding: 14px 0;
}

#rcmloginsubmit:hover {
  background-color: #222 !important;
  border-color: #222 !important;
  color: #fff !important;
}

#login-footer {
  color: #9a9a9a;
  text-align: center;
  margin-top: 24px;
}
```

- [ ] **Step 4: Verify the stylesheet has no obvious syntax breakage**

Run (brace balance sanity check — open/close counts should match):
```bash
awk -F'{' '{o+=NF-1} END{print "open:",o}' skins/avuz/styles/styles.min.css
awk -F'}' '{c+=NF-1} END{print "close:",c}' skins/avuz/styles/styles.min.css
```
Expected: `open` and `close` counts are equal. Also confirm no leftover `#d2e314` remains:
```bash
grep -n "d2e314" skins/avuz/styles/styles.min.css
```
Expected: no output (lime submit rule fully removed).

- [ ] **Step 5: Commit**

```bash
git add skins/avuz/styles/styles.min.css
git commit -m "feat(login): restyle login card, inputs, and black submit button"
```

---

### Task 3: Visual verification of the rendered login page

**Files:** none (verification only).

**Interfaces:**
- Consumes: Tasks 1 and 2 (logo + CSS in place).

- [ ] **Step 1: Build and serve the app locally**

Per CLAUDE.md build flow:
```bash
./scripts/build-push.sh latest local
```
Then open the running login page in a browser (the local container's mapped URL).

- [ ] **Step 2: Confirm acceptance criteria visually**

Check against the spec:
- Page background is `#f1f1f1`.
- Login form is a centered white rounded card with soft shadow.
- ConectaMail logo is centered at the top of the card and is appropriately sized (not tiny, not over-padded).
- Username and password fields render as light-grey pill fields.
- "Entrar" button is a full-width black pill with white bold text.
- No remember-me checkbox, no eye-toggle, no extra footer links/icons.

- [ ] **Step 3: Confirm in-app UI is unchanged**

Log in. Confirm the header/taskbar still use the cyan/lime branding (no black button, no grey background leaking into the authenticated UI).

- [ ] **Step 4: Capture evidence**

Take a screenshot of the login page. If anything fails criteria, file the gap and loop back to Task 2 before declaring done.
