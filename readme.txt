=== BKProduct Sync for Business Koro ===
Contributors: mdshimolhasanboni
Donate link: https://facebook.com/vipergadget
Tags: woocommerce, business koro, dropshipping, product import, order fulfillment
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.5
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync products and Color/Size variants from Business Koro to WooCommerce, send orders, and track live order status.

== Description ==

**BKProduct Sync for Business Koro** is an Independent plugin to sync products and Color/Size variants from Business Koro API into WooCommerce, send orders, and track live order status.

Key Features:
* **Dashboard:** View live API status, Total Imported Products, Total Stock In, Total Stock Out, Last Imported Date, and the Total Imported Products.
* **Import Product:** Fetch products from Business Koro and sync them directly into WooCommerce products (including name, description, price, stock status, Color/Size attributes).
* **Manage Products:** Browse imported WooCommerce products, open products in WooCommerce editor, or remove imported items cleanly.
* **Manage Orders:** Inspect WooCommerce orders in a modal popup showing customer Order Details, then send orders to Business Koro with one click.
* **Settings:** Securely save and update your Business Koro API key and optional domain header on the server side with instant **Active** or **Api key Incorrect** status verification.
* **Help:** View developer and support contact information.

== External services ==

This plugin relies on the external **Business Koro Storefront API** (`https://api.businesskoro.com/api/v1/storefront`) to provide product catalog synchronization, order submission, and order status tracking for store administrators.

* **What the service is and what it is used for:** Business Koro is a dropshipping and order fulfillment platform. This plugin connects to its Storefront REST API so the store administrator can verify API credentials, fetch available products and variants into WooCommerce, submit customer orders for fulfillment, and retrieve live order statuses.
* **What data is sent and when:**
  * **API Verification & Product Import (`GET /products`):** When the administrator saves/verifies settings or clicks **Import Products**, the plugin sends the configured `x-api-key` (and optional `x-domain` header) from the WordPress server to fetch product details. No storefront visitor data is sent during product imports.
  * **Order Dispatch (`POST /orders`):** Only when a store administrator explicitly clicks **Send Order to Business koro** inside **Manage Orders**, the plugin sends the selected order's Business Koro `productId`, selected `color`/`size`, `sellingPrice`, delivery charge preference, and the customer's shipping details (`customerName`, `customerPhone`, `customerAddress`, `customerDivision`, `customerDistrict`, `customerArea`, and `customerNote`) along with `x-api-key` to create the fulfillment order.
  * **Order Status Check (`GET /orders/{orderId}`):** Only when a store administrator clicks **Check Status** on a dispatched order, the plugin sends the Business Koro order ID and `x-api-key` to retrieve the latest fulfillment status.
* **Service Provider, Terms of Service & Privacy Policy:**
  * Service Provider: Business Koro (`https://businesskoro.com`)
  * Terms of Service: https://businesskoro.com/terms
  * Privacy Policy: https://businesskoro.com/privacy

== Installation ==

1. Upload the `bkproduct-sync-for-business-koro` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP file through **Plugins > Add New > Upload Plugin**.
2. Ensure **WooCommerce** is installed and activated.
3. Activate **BKProduct Sync for Business Koro** from the **Plugins** menu in WordPress.
4. Go to **BK Sync > Settings**, enter your Business Koro API key, and click **Save Settings**.
5. Navigate to **BK Sync > Import Product** to import products into WooCommerce.

== Frequently Asked Questions ==

= Is this an official Business Koro plugin? =

No. **BKProduct Sync for Business Koro** is an independent integration plugin developed by Md.Shimul Hasan Boni (ViperGadget) to connect WooCommerce stores with the Business Koro Storefront API.

= Where is the API key stored and used? =

Your API key is stored in WordPress options and used strictly on the server side via WordPress HTTP API requests (`wp_remote_get` and `wp_remote_post`). It is never exposed in front-end or browser JavaScript.

= Are imported products created as standard WooCommerce products? =

Yes. Imported items are created and updated as standard WooCommerce `product` entries so they work with your storefront, cart, and checkout flow.

== Changelog ==

= 1.2.0 =
* Renamed plugin to **BKProduct Sync for Business Koro** to clearly indicate independent integration status.
* Added full **External services** disclosure with Business Koro data transmission details, Terms of Service, and Privacy Policy links in `readme.txt`.
* Updated contributor handle to `mdshimolhasanboni`.

= 1.1.0 =
* Added Color/Size variant synchronization and order payload variant mapping.
* Added live order status checking (`GET /storefront/orders/{orderId}`) in the order modal.

= 1.0.0 =
* Initial release.
