# Custom GCC & Jordan Multi-Currency for WooCommerce

A lightweight, production-ready, object-oriented WooCommerce plugin that dynamically switches the store currency based on visitor geolocation for GCC nations (UAE, Saudi Arabia, Qatar, Kuwait, Bahrain, Oman) and Jordan, defaulting all other traffic to USD.

## Features

- Automatic geolocation-based currency resolution (Cloudflare header → WooCommerce IP geolocation → filterable default).
- Unobtrusive top-bar dropdown via shortcode `[gcc_currency_switcher]` or the `do_action( 'gcc_currency_switcher' )` hook — no floating widgets, no third-party bloat.
- Live FX rate fetching with transient caching, admin-configurable markup/buffer percentage, and static fallback rates.
- Psychological "charm" pricing/rounding after conversion, respecting each currency's decimal-place rules.
- Fixed manual price overrides per product per currency (takes precedence over calculated FX conversion).
- Order currency locking so historical orders are never affected by later FX rate changes.
- Full-page cache (Varnish/WP Rocket/Cloudflare cache) compatibility via a nonce-protected AJAX endpoint and a small vanilla-JS client script.

## Currency Matrix

| Country Code | Country Name | Target Currency | Decimal Places | Default FX Fallback (vs USD) |
|:---|:---|:---:|:---:|:---:|
| `AE` | United Arab Emirates | AED | 2 | 3.67 |
| `SA` | Saudi Arabia | SAR | 2 | 3.75 |
| `QA` | Qatar | QAR | 2 | 3.64 |
| `KW` | Kuwait | KWD | 3 | 0.31 |
| `BH` | Bahrain | BHD | 3 | 0.38 |
| `OM` | Oman | OMR | 3 | 0.38 |
| `JO` | Jordan | JOD | 3 | 0.71 |
| *All Others* | Rest of World | USD | 2 | 1.00 |

## Installation

1. Copy the `gcc-currency-switcher` directory into your WordPress installation's `wp-content/plugins/` directory.
2. Activate **Custom GCC & Jordan Multi-Currency for WooCommerce** from the Plugins screen. WooCommerce must already be installed and active — if it is not, the plugin displays an admin notice and stays inactive rather than fataling.
3. Add the currency dropdown to your theme's header/top bar using either:
   - The shortcode: `[gcc_currency_switcher]`
   - The action hook in a template file: `<?php do_action( 'gcc_currency_switcher' ); ?>`

## Configuration

Go to **Settings → Currency Switcher** in wp-admin to configure:

- **FX Markup / Buffer (%)** — an extra percentage applied on top of the fetched live rate to protect against FX fluctuation (default `2%`).
- **Enabled Countries** — toggle which GCC/Jordan countries should be geolocation-mapped to their local currency.

### FX rate caching

Live FX rates (base USD) are fetched from a public exchange-rate API and cached in the `gcc_fx_rates` transient. The cache duration defaults to 6 hours and can be adjusted with the `gcc_cs_fx_cache_duration_hours` filter. If the remote fetch fails or returns invalid data, the plugin automatically falls back to the static default rates from the Currency Matrix above.

### Manual per-product price overrides

On the WooCommerce **Product** edit screen, a **GCC & Jordan Currency Price Overrides** metabox lets you set a fixed regular/sale price per currency (AED, SAR, QAR, KWD, BHD, OMR, JOD). When set, the fixed value is used instead of the calculated FX conversion for that product and currency.

## Known Limitations

- **Variable products:** fixed price overrides are currently supported for simple products only. Variable product variations continue to use the automatically calculated FX conversion; per-variation overrides are not yet implemented (see `includes/class-admin-metabox.php`).
- FX rates are fetched from a single public API endpoint; consider self-hosting/mirroring rates for high-traffic production stores.

## Developer notes / hooks

All custom hooks are prefixed with `gcc_cs_` (filters) or `gcc_` (actions/shortcode) to avoid collisions:

- `gcc_cs_country_currency_map` — filter the country → currency map.
- `gcc_cs_currency_decimals_map` — filter the currency → decimal places map.
- `gcc_cs_default_fx_rates` — filter the static fallback FX rates.
- `gcc_cs_default_country` — filter the default country when geolocation cannot resolve one.
- `gcc_cs_detected_country` — filter the final detected country code.
- `gcc_cs_mapped_currency` — filter the currency mapped from a country code.
- `gcc_cs_cookie_duration_days` — filter how long the `gcc_currency` cookie persists (default 30 days).
- `gcc_cs_fx_cache_duration_hours` — filter the FX rate transient cache duration (default 6 hours).
- `gcc_cs_fx_markup_percent` — filter the markup/buffer percentage applied to fetched rates.
- `gcc_cs_fx_rate` — filter the final resolved rate for a currency.
- `gcc_cs_charm_rounding_rule` — filter the charm-rounding decimal ending (default `0.99`).
- `gcc_currency_switcher` (action) — render the currency dropdown from a theme template.

## Requirements

- PHP 7.4+
- WordPress 6.0+
- WooCommerce 7.0+ (HPOS / custom order tables compatible)

## License

GPL-2.0-or-later
