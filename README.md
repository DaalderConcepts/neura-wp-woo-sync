# Neura WooCommerce Sync

WordPress plugin that syncs WooCommerce data (products, orders, COGS) with [Neuramerce](https://neuramerce.com) for accurate ROAS tracking and margin analysis.

## Features

- Real-time product & order sync to Neuramerce
- COGS (Cost of Goods Sold) fields on products & variations (EUR / USD)
- UTM + fbclid capture on orders
- REST API endpoints (`/wp-json/nwws/v1/`)
- **Auto-updates via GitHub releases** — updates appear directly in WordPress admin

## Installation

1. Download the latest ZIP from [Releases](../../releases/latest)
2. Upload via **Plugins → Add New → Upload Plugin**
3. Activate and configure under **WooCommerce → Neura Sync**

## Auto-updates

The plugin checks GitHub for new releases every 6 hours. When a new version is tagged, WordPress will show the update notice in **Dashboard → Updates** — no manual ZIP upload needed.

To release a new version:

```bash
# 1. Bump NWWS_VERSION in neura-wp-woo-sync.php
# 2. Commit & push
git tag v1.2.0
git push origin v1.2.0
# 3. Create a GitHub Release from the tag
```

## Configuration

| Setting | Description |
|---------|-------------|
| API URL | `https://app.neuramerce.com/api` |
| API Key | Found in Neuramerce workspace settings |

## REST API

All endpoints require `X-API-Key` header.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/wp-json/nwws/v1/products` | GET | Products with COGS |
| `/wp-json/nwws/v1/orders`   | GET | Orders with margins |
| `/wp-json/nwws/v1/stats`    | GET | Sync statistics |
