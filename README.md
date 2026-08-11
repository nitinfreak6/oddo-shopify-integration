# Odoo–Shopify Connector

Laravel dashboard that syncs products, inventory, customers, orders, and dispatch between an ERP (Odoo) and e-commerce (Shopify). Scheduled jobs use the same fetch + post logic as the dashboard UI.

---

## Requirements

- PHP 8.2+
- Composer
- MySQL / MariaDB
- Node.js (optional, for front-end asset builds)

---

## Local development

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Open **http://127.0.0.1:8000** and sign in.

Configure ERP (Odoo) and e-commerce (Shopify) credentials under **Dashboard → Settings**.

---

## Production deployment (cPanel / Bluehost)

Example install path:

```
/home/USERNAME/public_html/oddoshopify/
```

Example public URL:

```
https://your-domain.example/oddoshopify/
```

### Environment

Set in `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example/oddoshopify
```

Run after any `.env` change:

```bash
php artisan config:clear
php artisan route:clear
php artisan config:cache
```

Ensure permissions:

```bash
chmod -R 775 storage bootstrap/cache
```

### Serve without `/public` in the URL

Laravel’s web root stays the `public/` folder. Add two files at the **project root** (same level as `app/`, `routes/`, `artisan`).

**`index.php`** (project root):

```php
<?php

require __DIR__.'/public/index.php';
```

**`.htaccess`** (project root):

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /oddoshopify/

    # Serve static files from public/ (CSS, JS, images)
    RewriteCond public/$1 -f [OR]
    RewriteCond public/$1 -d
    RewriteRule ^(.+)$ public/$1 [L]

    # Everything else → root index.php
    RewriteRule ^ index.php [L]
</IfModule>
```

Replace `/oddoshopify/` with your subdirectory name if different.

**`public/index.php`** — add this block immediately **before** `->handleRequest(Request::capture())`:

```php
// Subdirectory deployment (shared hosting)
$base = '/oddoshopify';
if (!empty($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], $base)) {
    $uri = substr($_SERVER['REQUEST_URI'], strlen($base));
    $_SERVER['REQUEST_URI'] = ($uri === '' || $uri === false) ? '/' : $uri;
}
```

Keep `public/.htaccess` as the standard Laravel file (no `RewriteBase`).

> **Do not** move Laravel files out of `public/` into the project root — that exposes `.env` and other sensitive directories.

---

## Cron / scheduled sync

Scheduled tasks are defined in `routes/console.php`.

| Task | Frequency |
|------|-----------|
| Full sync (`sync:all`) | Every 5 minutes |
| Amazon products | Hourly |
| Amazon inventory | Every 15 minutes |
| Amazon orders | Every 5 minutes |
| Pending alerts | Hourly |
| Log prune | Weekly |

### Full sync order

When `sync:all` runs, entities sync in this order:

1. Products  
2. Inventory  
3. Customers  
4. Orders  
5. Dispatch  

Each step respects **Global Settings** toggles (`*_sync_enabled`) and direction (`*_sync_mode`: `erp_to_ecom`, `ecom_to_erp`, `bidirectional`). Disabled entities are skipped.

### Recommended cPanel cron (Laravel scheduler)

Run every minute; Laravel decides what to execute:

| Field | Value |
|-------|--------|
| Minute | `*` |
| Hour | `*` |
| Day | `*` |
| Month | `*` |
| Weekday | `*` |

**Command** (adjust paths):

```bash
cd /home/USERNAME/public_html/oddoshopify && /usr/local/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1
```

Find the PHP binary with `which php` in cPanel Terminal. On many Bluehost servers it is `/usr/local/bin/php` or `/opt/cpanel/ea-php82/root/usr/bin/php`, not `/usr/bin/php`.

### Alternative: run sync directly every 5 minutes

```bash
cd /home/USERNAME/public_html/oddoshopify && /usr/local/bin/php artisan sync:all >> storage/logs/cron.log 2>&1
```

Use `*/5` for the minute field. This runs the main Odoo ↔ Shopify pipeline only; Amazon and maintenance tasks still need `schedule:run` if you rely on them.

### Verify cron is working

```bash
cd ~/public_html/oddoshopify
php artisan sync:all --dry-run
php artisan sync:all
php artisan schedule:list
```

Check logs:

- `storage/logs/laravel.log` — sync activity and errors  
- `storage/logs/cron.log` — cron command output (if configured)

Enable at least one entity in **Global Settings** or `sync:all` will skip all steps.

---

## Artisan sync commands

| Command | Description |
|---------|-------------|
| `php artisan sync:all` | Full pipeline (products → inventory → customers → orders → dispatch) |
| `php artisan sync:all --only=products` | Run a single step |
| `php artisan sync:all --dry-run` | Show planned steps without running |
| `php artisan sync:products` | Products only |
| `php artisan sync:inventory` | Inventory only |
| `php artisan sync:customers` | Customers only |
| `php artisan sync:orders` | Orders only |
| `php artisan sync:dispatch` | Dispatch only |
| `php artisan sync:amazon-products` | Amazon products |
| `php artisan sync:amazon-inventory` | Amazon inventory |
| `php artisan sync:amazon-orders` | Amazon orders |

Manual sync can also be triggered from **Settings** in the dashboard (requires `trigger-sync` permission).

---

## Dashboard features

- **Products, Inventory, Customers, Orders** — list, fetch, push, bulk delete  
- **Dispatch** — fulfillment sync (requires products mapped to Shopify variants)  
- **Sync Logs** — request/response history per entity  
- **Field mapping & config** — per-entity field transforms  
- **Alerts** — configurable notifications  
- **Global Settings** — sync enable/disable, direction, credentials  

Delete removes records from Shopify/Odoo and the local database (with confirmation). Shopify orders are cancelled, not hard-deleted.

---

## Troubleshooting

### 404 on subdirectory URL (`/oddoshopify/login`)

Laravel is running but routes do not match. Ensure the subdirectory fix is in `public/index.php` and `APP_URL` has no `/public` suffix.

### HTTP 500 after `.htaccess` changes

- Remove `Require all denied` and `index.php/$1` rules (often unsupported on shared hosting).  
- Use the root `index.php` + simple `.htaccess` setup above.  
- Check `storage/logs/laravel.log` and cPanel **Error Log**.

### Cron runs manually but not from server

- Use the full PHP path from `which php`.  
- Log to `storage/logs/cron.log` instead of `/dev/null`.  
- Cron must `cd` to the project root (where `artisan` lives), not `public/`.  
- On shared hosting, prefer `artisan sync:all` every 5 minutes over `schedule:run` if background jobs fail.

### Dispatch sync errors

Example:

```
Could not resolve fulfillment_order_id for Odoo product_id …
```

The Odoo product must be synced to Shopify first (`SyncMapping` with a Shopify variant ID) and appear on the order.

### Odoo URL in settings

Use the Odoo base URL only — no `/web`, `/public`, or Laravel connector URL.

---

## Project structure (key paths)

```
app/
  Console/Commands/     # sync:* artisan commands
  Http/Controllers/     # dashboard + API
  Services/Sync/        # ScheduledSyncRunner, UniversalSyncService
  Services/Odoo/        # Odoo RPC client
  Services/Shopify/     # Shopify GraphQL/REST
routes/
  web.php               # dashboard routes
  console.php           # scheduler definitions
resources/views/dashboard/
storage/logs/           # application logs
public/                 # web root (index.php, assets)
```

---

## License

Proprietary — internal use.
