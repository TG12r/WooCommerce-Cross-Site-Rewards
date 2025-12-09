# WooCommerce Cross-Site Rewards

![WooCommerce](https://img.shields.io/badge/WooCommerce-Success-purple?style=flat-square) ![WordPress](https://img.shields.io/badge/WordPress-Plugin-blue?style=flat-square) ![License](https://img.shields.io/badge/License-GPLv3-green?style=flat-square)

**Cross-site rewards plugin for WooCommerce. It uses the REST API to generate remote coupons and QR codes between independent WordPress installations.**

This plugin enables a "Shop & Earn" workflow where purchasing specific products on **Site A (Sender)** automatically grants rewards (free products/coupons) on **Site B (Receiver)**. Ideally suited for ecosystem sales, partner networks, or separating digital course platforms from merchandising stores.

---

## Features

*   **Universal Plugin**: Single codebase installable on both sites. Role (Sender/Receiver) is toggleable via settings.
*   **Secure Communication**: Uses a Shared Secret Key for authenticated REST API requests.
*   **Dynamic Mapping**: Connect specific products on Site A to specific rewards on Site B.
*   **Remote Catalog Fetching**: "Sender" site can fetch and display a dropdown of "Receiver" products for easy mapping.
*   **Instant Gratification**: Generates a QR Code and "Claim Now" button immediately after purchase.
*   **Frictionless Redemption**: QR codes link directly to the Cart with the product added and the 100% discount coupon applied.

## Requirements

*   WordPress 5.0+
*   WooCommerce 4.0+
*   PHP 7.4+
*   Admin access to both WordPress installations.

## Installation

1.  Download the repository or the `wc-cross-site-rewards.php` file.
2.  Upload it to the `/wp-content/plugins/` directory on **BOTH** websites.
3.  Activate the plugin through the WordPress 'Plugins' menu.

## Configuration

### 1. Site B (The Receiver / Reward Provider)
Go to **Settings > WC Rewards**:
*   **Mode**: Select `RECEPTOR` (Receiver).
*   **Secret Key**: Define a strong, unique alphanumeric string (e.g., `s3cr3t_k3y_x99`).
*   Save Settings.

### 2. Site A (The Sender / Storefront)
Go to **Settings > WC Rewards**:
*   **Mode**: Select `EMISOR` (Sender).
*   **Secret Key**: Enter the **exact same** key you defined in Site B.
*   **Remote URL**: Enter the URL of Site B (e.g., `https://rewards-site.com`).
*   Save Settings.

## Usage

### Linking a Product
1.  On **Site A**, edit a Product.
2.  In the **Product Data > General** tab, locate the field: **"Remote Reward Product"**.
3.  Click **"🔄 Actualizar Lista Remota"** (Refresh Remote List). The plugin will fetch available products from Site B.
4.  Select the product you wish to gift.
5.  Update/Save the product.

### The Customer Experience
1.  Customer buys the product on **Site A**.
2.  On the "Order Received" page (and in the email), they see a **Reward Box**.
3.  They scan the **QR Code** or click **"Reclamar Ahora"** (Claim Now).
4.  They are redirected to **Site B** > Cart > Coupon Applied automatically.

## Technical Details

The plugin registers a custom REST API namespace `wc-xsr/v1`.
*   **GET** `/products`: Returns a lightweight list of ID/Names for the admin dropdown.
*   **POST** `/generate`: Accepts a `reward_product_id`, validates the secret, and generates a unique single-use WooCommerce coupon.

## License

GPLv3
