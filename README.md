# Ahan Proforma

A standalone, lightweight WordPress plugin that turns rows of the **Ahan Price Tables (APT)** plugin's price tables into customer proforma invoices — with no dependency on WooCommerce, Digitsparts, or any other heavy e-commerce plugin.

Built for **ahanshahr.com** (a steel/iron merchant site), replacing a previous stack of WooCommerce + several add-ons with one integrated, purpose-built plugin.


---

## What it does

1. Every price table rendered by the Ahan Price Tables plugin gets an extra **"Buy"** column.
2. For each row: if the row has a real price, an **"Add to invoice"** button appears; if the row says "call us" (or the price is invalid/zero), a **"Call us"** button appears instead.
3. Adding a row shows immediate feedback and opens a slide-out **proforma cart** drawer, where the customer can review items, change quantities, and remove rows.
4. The customer logs in or signs up with just their **mobile number + a one-time SMS code** (no password, no classic WordPress login form).
5. From the cart, the customer **issues a proforma invoice** — a standalone, printable HTML document with a Jalali-dated invoice number, VAT, and a permanent record tied to their account.
6. Customers can view all their past proforma invoices from their **account page**.
7. Admins get a restricted panel to view, filter, edit, and change the status of every proforma invoice, plus a settings page for colors, the SMS gateway, VAT rate, and invoice footer text.

---

## Architecture

### No WooCommerce, no Digitsparts

Every table, cart, invoice, and account feature is implemented from scratch, specifically for this site. Nothing in this plugin touches WooCommerce data structures, hooks, or tables.

### Soft dependency on Ahan Price Tables (APT)

This plugin never calls any `APT_*` class directly. The **only** connection point is the `apt_table_rendered_html` filter, which this plugin hooks into to inject the "Buy" column into each table's final HTML. If the APT plugin is inactive, this plugin still loads without errors — only the "Add to invoice" button on price tables won't appear.

### Unit detection

Each row's measurement unit is read from a `data-col="unit"` cell in the table HTML (added on the APT side). The Persian text is mapped to one of three values — the only ones allowed anywhere in this system, defined in `AHP_Helpers::allowed_units()`:

| Value | Meaning | Matched APT text |
|---|---|---|
| `kg` | Sold by weight | `کیلوگرم` |
| `shakhe` | Sold by bundle/rod | `شاخه` |
| `adad` | Sold by count/piece | *(default — used whenever the table has no unit column at all, e.g. fittings/connectors tables)* |

If a unit column is present but its text doesn't match a known value, the row also falls back to `adad` rather than guessing — a labeling change on the APT side can never silently produce a wrong `kg`/`shakhe`. See `AHP_Table_Integration::detect_unit()`.

### Dedicated database tables

All storage uses its own `wp_ahp_*` tables (never touching APT's `apt_*` tables or WooCommerce tables):

| Table | Purpose |
|---|---|
| `wp_ahp_invoices` | One row per issued proforma invoice (header: number, buyer, totals, status, access token) |
| `wp_ahp_invoice_items` | Line items for each proforma invoice — a full **snapshot** of description, unit, quantity, and price at the moment it was added, independent of later changes to the source price table |
| `wp_ahp_otp_requests` | One-time SMS codes for login/sign-up, with rate limiting and expiry |

### Custom roles and capabilities

Two dedicated roles, unrelated to WooCommerce's Customer role or WordPress's built-in Editor/Author roles:

- **`ahan_customer`** — a site customer. No dashboard/wp-admin access. Only has the `ahp_view_own_invoices` capability, used to gate the account page.
- **`ahan_invoice_manager`** — a restricted admin who can manage proforma invoices. Has the base `read` capability (to see the dashboard) plus `ahp_manage_invoices` (used to gate the plugin's own admin menu). No other WordPress capability — no access to Posts, Plugins, Themes, Settings, or Users. There is no separate "view only" tier; anyone with this role always has full manage access.

The site's main Administrator (`manage_options`) always has full access to everything, regardless of these dedicated capabilities.

### Invoice numbering

Format: `{Jalali year}/A/{4-digit, zero-padded sequence number}` — e.g. `1405/A/0001`. The sequence number is a single, atomic, database-level counter (`wp_options`, incremented with an atomic `UPDATE`) that never resets and is never reused, even under concurrent load. The middle letter (`A`) can be changed via the `ahp_invoice_number_prefix` filter.

### Authentication

Customers log in/sign up with just a **mobile number** and a **5-digit SMS code** — no password field exists anywhere in the customer-facing flow. `user_login` is the normalized mobile number itself (e.g. `09121234567`). New users are created with a long random password that is never shown or sent to anyone; every future login is via a fresh OTP code.

#### OTP rate limiting

| Limit | Default | Where it is counted |
|---|---|---|
| Per mobile number | 5 requests / 10 minutes | `wp_ahp_otp_requests` table (`AHP_DB::count_recent_otp_requests()`) |
| Per IP address | 10 requests / 10 minutes | Transient `ahp_otp_ip_<md5(ip)>` (adjustable with the `ahp_otp_ip_limit` filter: `[ max, window_seconds ]`; `max = 0` disables it) |
| Wrong code attempts | 5 per issued code | `attempts` column of the OTP row |

- A request whose SMS could not be sent is **deleted from the table immediately**, so a gateway failure never consumes the user's quota; the real gateway error is written to `error_log`.
- Every `429`/`502` JSON error from `ahp_request_code` carries a `reason` field (`number_limit`, `ip_limit`, `sms_failed`) to make debugging from the browser's Network tab trivial.

### LiteSpeed Cache compatibility

The site runs LiteSpeed Cache, which has caused several nonce/caching issues during development. Key patterns adopted to work around this:

- Pages containing a short-lived nonce (the login form) explicitly disable caching (`DONOTCACHEPAGE`, `LSCACHE_NO_CACHE`, and the `litespeed_control_set_nocache` hook).
- AJAX handlers detect a stale/invalid nonce specifically by checking for a raw `"-1"` or `"0"` text response (WordPress's `wp_die(-1)` signature from a failed `check_ajax_referer()`) — **never** by checking for a JSON `success:false`, since that would incorrectly retry genuine business-logic errors.
- A dedicated `ahp_cart_refresh_nonce` / `ahp_refresh_nonce` AJAX action lets the client fetch a fresh nonce without a full page reload, used both proactively (right after login) and reactively (as a second line of defense inside the generic `ajax()` helper).

---

## File structure

```
ahan-proforma/
├── ahan-proforma.php              # Main plugin file: constants, hooks, file loading
├── uninstall.php                  # Full data removal (opt-in via a settings checkbox), runs only on "Delete"
├── includes/
│   ├── class-ahp-db.php            # All database schema + queries (wp_ahp_* tables)
│   ├── class-ahp-roles.php         # Custom roles/capabilities + dashboard access restriction
│   ├── class-ahp-helpers.php       # Jalali date conversion, number formatting, invoice-number builder
│   ├── class-ahp-table-integration.php  # Injects the "Buy" column into APT price tables
│   ├── class-ahp-cart.php          # Proforma cart: storage, AJAX, guest→user cart merge on login
│   ├── class-ahp-sms-gateway.php   # Farapayamak SMS REST API wrapper (OTP + free-text sending)
│   ├── class-ahp-auth.php          # Customer login/sign-up via mobile number + OTP
│   ├── class-ahp-user-fields.php   # Mobile-number column/field in the WordPress user list & profile
│   ├── class-ahp-account.php       # Customer account page: invoice list, edit name/mobile
│   ├── class-ahp-invoice.php       # Issuing a proforma invoice from the cart + standalone HTML output page
│   └── class-ahp-admin.php         # Restricted admin panel: invoice list/status + settings
├── templates/                     # All HTML output (PHP templates, no logic beyond simple loops)
├── assets/
│   ├── css/
│   └── js/                        # Vanilla JS only — no jQuery, no build step
└── languages/                     # .pot / .po / .mo translation files
```

---

## Internationalization (i18n)

**Source strings are English.** Every user-facing string in the plugin's PHP source is wrapped in `__()` / `esc_html__()` / `esc_attr__()` and written in English, which serves as the canonical/reference text. Code comments throughout the codebase are also in English.

**One deliberate exception:** `templates/invoice-output.php` — the actual printable proforma-invoice document the customer receives — keeps its visible strings in **Persian** (with `dir="rtl"` and Persian numerals), since this is the real, final artifact the customer reads. Only that file's code comments are in English; its `__()`-wrapped UI strings are Persian source strings, ready to be picked up by the `.po` file like any other string.

### Translation files (`languages/`)

| File | Purpose |
|---|---|
| `ahan-proforma.pot` | The template — every translatable string extracted from the source, with empty translations. Regenerate this whenever strings change. |
| `ahan-proforma-fa_IR.po` / `.mo` | Persian translation — this is what actually displays on the live site (`fa_IR` locale). |
| `ahan-proforma-en_US.po` / `.mo` | English reference translation (identity — msgstr equals msgid), kept for completeness/tooling that expects a matching locale file. |

### Regenerating the `.pot` file after changing strings

```bash
xgettext \
  --from-code=UTF-8 --language=PHP \
  --keyword=__ --keyword=_e \
  --keyword=esc_html__ --keyword=esc_html_e \
  --keyword=esc_attr__ --keyword=esc_attr_e \
  --keyword=_x:1,2c --keyword=_ex:1,2c \
  --keyword=_n:1,2 --keyword=_nx:1,2,4c \
  --keyword=esc_attr_x:1,2c --keyword=esc_html_x:1,2c \
  --package-name="Ahan Proforma" --package-version="1.0.12" \
  --copyright-holder="Ahan Shahr" \
  -o languages/ahan-proforma.pot \
  $(find . -name "*.php")
```

Then merge new/changed strings into the existing `.po` files with `msgmerge`, translate anything new, and recompile with `msgfmt`:

```bash
msgmerge --update languages/ahan-proforma-fa_IR.po languages/ahan-proforma.pot
msgfmt languages/ahan-proforma-fa_IR.po -o languages/ahan-proforma-fa_IR.mo
```

### JavaScript strings

JavaScript files (`assets/js/*.js`) contain **no hardcoded UI text**. All labels are passed from PHP via `wp_localize_script()` into a global object's `.i18n` property (e.g. `AHP_CART.i18n.addToProforma`), so every string a user sees — whether rendered by PHP or by JS — flows through the same `.po` translation file. There is no separate `lang === 'fa'` ternary switch anywhere in the JS.

---

## Key hooks and filters (for future customization)

| Hook | Purpose |
|---|---|
| `apt_table_rendered_html` | The only integration point with the Ahan Price Tables plugin |
| `ahp_invoice_number_prefix` | Change the middle letter in the invoice number format (default `A`) |
| `ahp_store_phone_number` | Override the phone number used by the "Call us" button |
| `ahp_cart_page_url` / `ahp_account_page_url` / `ahp_account_edit_url` | Override the URLs used for the cart/account/edit-info pages |
| `ahp_login_redirect_url` | Where a customer is sent after a standalone-page login (not used by the popup flow) |
| `ahp_customer_admin_redirect_url` | Where a customer is redirected if they try to access wp-admin |
| `ahp_otp_ip_limit` | Per-IP OTP request limit, `[ max_requests, window_seconds ]` (default `[ 10, 600 ]`, `max = 0` disables it) |
| `ahp_sms_settings` | Override SMS gateway settings programmatically |
| `ahp_sms_otp_request_body` | Filter the outgoing request body sent to Farapayamak's `SendOTP` endpoint |
| `ahp_sms_text_request_body` | Filter the outgoing request body sent to Farapayamak's `SendSMS` (free-text) endpoint |
| `ahp_invoice_output_head` | Fires in `<head>` of the standalone invoice output page (used to inject custom colors) |

---

## Requirements

- WordPress 6.0+
- PHP 8.1+
- The **Ahan Price Tables** plugin (optional — for the "Add to invoice" button to appear on price tables)
- A Farapayamak (`payamak-panel.com`) SMS account, configured under **Ahan Shahr Proforma ▸ Settings**

---

## Known environmental notes

- The site uses **LiteSpeed Cache**; see the "LiteSpeed Cache compatibility" section above for the specific patterns this plugin relies on to avoid stale-nonce bugs.
- `dbDelta()` can silently fail to add new columns to an already-existing table via `ALTER TABLE`. `AHP_DB::ensure_access_token_column()` works around this by checking `information_schema.COLUMNS` directly and running an explicit, idempotent `ALTER TABLE` if the column is missing.
- **Never use `wp_date()` / `date_i18n()` to build values that are compared against stored dates.** Persian-calendar plugins (WP-Parsidate, WP-Jalali, ...) make them return Jalali dates (`1405-08-27`), while rows are stored with `current_time('mysql')` in Gregorian (`2026-11-18`). Comparing those strings in SQL makes every row look "recent". This caused a real production incident in v1.0.11 and earlier: the OTP rate-limit counter counted *all* historical requests of a number, so any number with two or more past requests was locked out permanently (HTTP 429, "too many requests"). Since v1.0.12 all SQL cutoffs are built by `AHP_DB::site_time_ago()`, which uses `DateTime` + `wp_timezone()` and is never filtered by calendar plugins. Jalali conversion for *display* stays in `AHP_Helpers`.
- **Redis / persistent object cache is compatible.** The only transient-based state is the per-IP OTP counter; everything else (OTP codes, per-number counter, carts of logged-in users, invoices) lives in database tables/options. Enabling or flushing Redis is safe.
- Farapayamak's SMS API returns **HTTP 200 even on business-logic failures** (wrong credentials, blocked IP, etc.) — the actual result must always be read from the `RetStatus` field in the JSON body, never from the HTTP status code alone.

---

## Releasing a new version

Auto-updates are delivered through the bundled Plugin Update Checker from **GitHub Releases** of `moeinoa/ahan-proforma` (release assets are enabled).

1. Bump the version in **both** places in `ahan-proforma.php`: the `Version:` header and `define( 'AHP_VERSION', ... )` (keep `AHP_DB_VERSION` unchanged unless the schema changed).
2. Commit and push to `main` (`git pull --rebase origin main` first if the push is rejected).
3. On GitHub: **Releases → Draft a new release**, create tag `vX.Y.Z` (must match the header version), and **attach the plugin zip** whose single top-level folder is `ahan-proforma/`. Do not rely on GitHub's automatic "Source code" zip — its folder name (`ahan-proforma-X.Y.Z`) makes WordPress install the plugin in the wrong directory.
4. In WordPress: **Dashboard → Updates → Check again**, then update.

---

## Changelog

### 1.0.16
- Honor the selected quantity on add-to-proforma buttons.
- Add a validated batch endpoint for adding all weight-calculator rows in one request.

### 1.0.15
- The `expired` invoice status is now labelled **"Referred to sales team"** everywhere it is shown (admin list/filter, customer account, printed invoice watermark). The stored status key stays `expired`, so existing invoices need no migration.

### 1.0.14
- **UI:** in the admin invoices list, status select / Apply / Delete are now three separate columns, and delete is a trash icon (no text button). Controls sit in their own cells and submit hidden forms via the `form=""` attribute.

### 1.0.13
- **New:** admin can permanently delete a whole proforma invoice (with all its rows) from the invoices list, with a confirmation prompt, nonce + capability checks. Invoice numbers are never reused, so a deleted number leaves a gap in the sequence.

### 1.0.12
- **Fix:** login lockout ("too many requests") on sites running a Persian-calendar plugin. The OTP rate-limit window and the daily cleanup cutoff are now computed with `AHP_DB::site_time_ago()` instead of `wp_date()` (see *Known environmental notes*).
- OTP rate limit changed from 2 requests / 2 minutes to 5 requests / 10 minutes per number (matches the documented behaviour).
- A failed SMS send no longer consumes the rate-limit quota; the gateway error is logged to `error_log`.
- `ahp_request_code` error responses include a `reason` field.

### 1.0.11
- Updater test release (version number only).
