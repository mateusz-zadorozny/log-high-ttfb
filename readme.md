# Log High TTFB

Log High TTFB records slow front-end requests directly from the browser and stores the measurements in a custom WordPress table for later analysis. The plugin tracks warning requests over 800&nbsp;ms and slow requests starting at 1800&nbsp;ms, exposes the data in the WordPress admin, and can send a daily email digest of problem URLs.

## Features
- Measures TTFB in the browser via `PerformanceObserver` with timing fallbacks and posts results through a hardened REST endpoint.
- Persists slow requests (URL, severity, TTFB, device category, browser, role, Cloudflare country, referrer, cookies/query param keys) inside a dedicated database table created on activation.
- Adds a "TTFB Monitor" admin menu with:
  - **Logs** list table (searchable/filterable) for individual request records.
  - **Insights** dashboard summarising the previous 7 days, including totals, top 50 slow hits, and similarity groupings by URL, query params, and cookies.
  - **Settings** page to enable/disable the email digest and configure recipients.
- Schedules a daily cron event (`log_high_ttfb_daily_summary`) that sends a text email at 08:00 site time with totals, top offenders, and similarity hints for the prior day.
- Includes nonce-based REST protection plus authentication fallbacks for logged-in sessions to avoid false negatives during admin usage.

## Installation
1. Copy the plugin folder into `wp-content/plugins/log-high-ttfb`.
2. Run `composer install` if you want development dependencies (PHPUnit/polyfills).
3. Activate **Log High TTFB** from the WordPress Plugins screen. Activation will:
   - Create the `{$wpdb->prefix}log_high_ttfb` table via `dbDelta()`.
   - Schedule the `log_high_ttfb_daily_summary` cron hook for the next 08:00 run.

## Configuration & Usage
- Visit **TTFB Monitor → Settings** to enable the daily digest and enter one or more comma-separated recipient emails. Saving the setting will schedule/clear the cron event accordingly.
- Use **TTFB Monitor → Logs** to review captured requests. Filter by severity using the dropdown or search by URL.
- Use **TTFB Monitor → Insights** for a last-7-days overview and similarity groupings that mirror the daily email.
- The front-end script is automatically enqueued on non-admin views. It sends exactly one payload per page view once the warning threshold is exceeded.

## REST Endpoint
- Namespace: `log-high-ttfb/v1`
- Route: `POST /wp-json/log-high-ttfb/v1/log`
- Required headers:
  - `Content-Type: application/json`
  - `X-Log-High-Ttfb-Nonce`: plugin nonce generated server-side.
  - `X-WP-Nonce`: standard `wp_rest` nonce for authenticated users (automatically provided by the plugin script).
- Sample payload:

```json
{
  "ttfb": 2010,
  "url": "https://example.com/page/",
  "timestamp": "2025-09-17T16:12:20.546Z",
  "queryParamKeys": ["utm_source", "utm_medium"],
  "cookieNames": ["woocommerce_cart_hash", "wordpress_logged_in_"],
  "deviceType": "desktop",
  "browser": "Chrome",
  "referrer": "https://example.com/prev/"
}
```

The REST controller will classify the entry (`warning` for >800&nbsp;ms, `bad` for ≥1800&nbsp;ms) and enrich it with role, country (Cloudflare `CF-IPCountry`), and timestamps before saving.

## Cron & Email Summary
- Hook: `log_high_ttfb_daily_summary`
- Scheduled automatically on activation (next 08:00 site time). You can inspect with:
  - `wp cron event list | grep log_high_ttfb_daily_summary`
- Manual trigger for testing: `wp cron event run log_high_ttfb_daily_summary`
- Email includes:
  - Counts of warnings and slow requests from the prior day.
  - Top 50 slowest requests (descending by TTFB).
  - Similarity buckets by URL, cookie name, and query parameter keys (top 5 each).

## Database Schema
Created table `wp_log_high_ttfb` (prefix varies) stores:

| Column        | Type                  | Notes                                 |
|---------------|-----------------------|----------------------------------------|
| `id`          | BIGINT UNSIGNED PK    | Auto increment                         |
| `recorded_at` | DATETIME              | Stored in UTC                          |
| `ttfb_ms`     | MEDIUMINT UNSIGNED    | Rounded milliseconds                   |
| `category`    | VARCHAR(20)           | `warning` or `bad`                     |
| `url`         | TEXT                  | Fully-qualified URL                    |
| `query_params`| TEXT (JSON)           | Unique query param names               |
| `cookies`     | TEXT (JSON)           | Unique cookie names                    |
| `user_role`   | VARCHAR(100)          | `guest` for unauthenticated visitors   |
| `country`     | VARCHAR(10)           | ISO code (when provided by Cloudflare) |
| `device_type` | VARCHAR(20)           | `desktop`, `mobile`, or `tablet`       |
| `browser`     | VARCHAR(100)          | Parsed browser label                   |
| `referrer`    | TEXT                  | Referrer URL (if present)              |

## Development Notes
- PHP lint: `php -l log-high-ttfb.php includes/*.php`
- JS is plain ES5 to maximise compatibility; rebuild is not required after edits—just clear caches.
- PHPUnit config (`phpunit.xml.dist`) and Yoast polyfills are included for future automated tests, though no suites ship by default.
- When testing in browsers, perform a hard refresh to ensure the latest nonce-bearing script is loaded.

## Troubleshooting
- **REST 403 Invalid security token**: confirm both nonces are present. Logged-in users receive a fallback that checks `is_user_logged_in()`, but cached pages may need a refresh to pick up new scripts.
- **Cron missing**: toggle the email setting off/on to reschedule, or run `wp cron event schedule log_high_ttfb_daily_summary now daily` manually.
- **Activation failure**: ensure the WordPress REST API base classes are available (WP 5.0+ recommended).

## License
Distributed under the same terms as WordPress (GPLv2 or later).
