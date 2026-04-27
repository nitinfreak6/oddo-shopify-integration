## Odoo ↔ Shopify (and Amazon) Connector

Laravel middleware to sync data between **Odoo** and **Shopify**, plus optional **Amazon SP-API** flows.

### What it syncs

- **Products (Odoo → Shopify)**: creates/updates products and variants; stores ID mappings.
- **Inventory (Odoo → Shopify)**: reads `stock.quant` changes in Odoo and updates Shopify inventory levels.
- **Customers (Odoo → Shopify)**
- **Orders**
  - **Odoo → Shopify**: fulfillment/cancellation updates based on Odoo order changes.
  - **Shopify → Odoo**: imports orders (manual mode) + webhook ingestion.
- **Amazon (optional)**: order sync and inventory/listing operations (depending on configuration).

### Requirements

- **PHP >= 8.2** (see `composer.json`)
- Composer
- Node.js + npm (for frontend assets)
- Database (default config uses **SQLite** for local dev)

### Setup

1) Install dependencies and create `.env`

```bash
composer install
copy .env.example .env
php artisan key:generate
```

2) Configure environment variables

- **Odoo**
  - `ODOO_URL`, `ODOO_DB`, `ODOO_USERNAME`, `ODOO_API_KEY`
  - `ODOO_LOCATION_SHOPIFY_LOCATION_MAP` (JSON map of Odoo location IDs → Shopify location IDs)
- **Shopify**
  - `SHOPIFY_SHOP`, `SHOPIFY_ACCESS_TOKEN`, `SHOPIFY_API_VERSION`
  - `SHOPIFY_WEBHOOK_SECRET`, `SHOPIFY_WEBHOOK_CALLBACK_URL`
  - `SHOPIFY_INVENTORY_WRITEBACK` (defaults `false`; Odoo is source of truth)
- **Queue**
  - `QUEUE_CONNECTION=database` by default (jobs stored in `jobs` table)
- **Amazon (optional)**
  - `AMAZON_*` variables in `.env.example`

3) Migrate database (queue tables, mapping tables, logs, etc.)

```bash
php artisan migrate
```

4) Build frontend assets (optional for API-only usage)

```bash
npm install
npm run build
```

### Running locally

You typically need **at least two processes**:

- **App server**

```bash
php artisan serve
```

- **Queue worker** (important: inventory/product/order pushes are queued on `sync`)

```bash
php artisan queue:work --queue=sync
```

If you use the provided composer script:

```bash
composer run dev
```

### Artisan sync commands

- `php artisan sync:products [--full] [--limit=NN] [--dry-run]`
- `php artisan sync:inventory [--location=ID] [--full] [--dry-run]`
- `php artisan sync:customers [--full] [--dry-run]`
- `php artisan sync:orders [full] [--dry-run] [--import-shopify] [--limit=NN]`
- Amazon:
  - `php artisan sync:amazon-products`
  - `php artisan sync:amazon-orders`
  - `php artisan sync:amazon-inventory`

### Shopify webhooks

Endpoints are defined under `routes/api.php`:

- `POST /api/webhooks/shopify/orders/create`
- `POST /api/webhooks/shopify/orders/updated`
- `POST /api/webhooks/shopify/inventory_levels/update`

Incoming webhook HMAC is verified by `App\Http\Middleware\VerifyShopifyWebhook`, and payloads are recorded to `webhook_logs` then processed via queued jobs.

### Scheduling

This repo schedules recurring syncs in `routes/console.php`. For production you’ll need:

```bash
php artisan schedule:work
```

### Troubleshooting

- **Inventory sync prints “completed” but nothing updates**:
  - Ensure a worker is running: `php artisan queue:work --queue=sync`
  - Confirm `ODOO_LOCATION_SHOPIFY_LOCATION_MAP` is set; unmapped locations are skipped.
  - Run product sync first so variant mappings exist: `php artisan sync:products --full`
