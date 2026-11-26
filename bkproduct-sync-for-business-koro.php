<?php
/**
 * Plugin Name: BKProduct Sync for Business Koro
 * Plugin URI:  https://facebook.com/vipergadget
 * Description: Independent integration plugin to import products and Color/Size variants from Business Koro API into WooCommerce, manage products, send orders, and check live order status.
 * Version:     1.5.0
 * Author:      Md.Shimul Hasan Boni
 * Author URI:  https://facebook.com/BDshemul71
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bkproduct-sync-for-business-koro
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BKPSBK_VERSION', '1.2.0' );
define( 'BKPSBK_PATH', plugin_dir_path( __FILE__ ) );
define( 'BKPSBK_URL', plugin_dir_url( __FILE__ ) );
define( 'BKPSBK_OPTION_KEY', 'bk_wc_settings' );
define( 'BKPSBK_SENT_ORDERS_OPTION', 'bk_wc_sent_orders' );

require_once BKPSBK_PATH . 'includes/class-bkpsbk-api.php';

class BKPSBK_Business_Koro_Sync {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );

        // AJAX handlers
        add_action( 'wp_ajax_bk_wc_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_bk_wc_check_status', array( $this, 'ajax_check_status' ) );
        add_action( 'wp_ajax_bk_wc_import_products', array( $this, 'ajax_import_products' ) );
        add_action( 'wp_ajax_bk_wc_remove_product', array( $this, 'ajax_remove_product' ) );
        add_action( 'wp_ajax_bk_wc_get_order_details', array( $this, 'ajax_get_order_details' ) );
        add_action( 'wp_ajax_bk_wc_send_order', array( $this, 'ajax_send_order' ) );
        add_action( 'wp_ajax_bk_wc_check_order_status', array( $this, 'ajax_check_order_status' ) );
    }

    public function woocommerce_missing_notice() {
        if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'activate_plugins' ) ) {
            echo '<div class="notice notice-error"><p><strong>BKProduct Sync for Business Koro</strong> requires <strong>WooCommerce</strong> to be installed and active.</p></div>';
        }
    }

    public function get_settings() {
        $defaults = array(
            'api_key'                => '',
            'domain'                 => '',
            'api_status'             => 'Api key Incorrect',
            'last_imported_date'     => '',
            'delivery_paid_customer' => '1',
            'delivery_mode'          => '',
            'price_markup_type'      => 'suggested',
            'price_markup_value'     => '0',
        );
        $saved = get_option( BKPSBK_OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return wp_parse_args( $saved, $defaults );
    }

    public function register_admin_menu() {
        add_menu_page(
            'BKProduct Sync for Business Koro',
            'BK Sync',
            'manage_woocommerce',
            'bk-wc-dashboard',
            array( $this, 'render_admin_page' ),
            'dashicons-store',
            56
        );

        add_submenu_page( 'bk-wc-dashboard', 'Dashboard', 'Dashboard', 'manage_woocommerce', 'bk-wc-dashboard', array( $this, 'render_admin_page' ) );
        add_submenu_page( 'bk-wc-dashboard', 'Import Product', 'Import Product', 'manage_woocommerce', 'bk-wc-import', array( $this, 'render_admin_page' ) );
        add_submenu_page( 'bk-wc-dashboard', 'Manage Products', 'Manage Products', 'manage_woocommerce', 'bk-wc-products', array( $this, 'render_admin_page' ) );
        add_submenu_page( 'bk-wc-dashboard', 'Manage Orders', 'Manage Orders', 'manage_woocommerce', 'bk-wc-orders', array( $this, 'render_admin_page' ) );
        add_submenu_page( 'bk-wc-dashboard', 'Settings', 'Settings', 'manage_woocommerce', 'bk-wc-settings', array( $this, 'render_admin_page' ) );
        add_submenu_page( 'bk-wc-dashboard', 'Help', 'Help', 'manage_woocommerce', 'bk-wc-help', array( $this, 'render_admin_page' ) );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'bk-wc-' ) === false && 'toplevel_page_bk-wc-dashboard' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'vgsbk-admin-css',
            BKPSBK_URL . 'assets/css/admin-style.css',
            array(),
            BKPSBK_VERSION
        );

        wp_enqueue_script(
            'vgsbk-admin-js',
            BKPSBK_URL . 'assets/js/admin-script.js',
            array( 'jquery' ),
            BKPSBK_VERSION,
            true
        );

        $page = ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bk_wc_admin_tab' ) && isset( $_GET['page'] ) )
            ? sanitize_key( wp_unslash( $_GET['page'] ) )
            : 'bk-wc-dashboard';

        wp_localize_script(
            'vgsbk-admin-js',
            'bkWcData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'bk_wc_nonce' ),
                'activeTab' => $page,
                'strings'   => array(
                    'confirmDel' => 'Are you sure you want to remove this imported product?',
                ),
            )
        );
    }

    private function get_imported_products_query_args() {
        return array(
            'post_type'      => 'product',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Scoped to imported Business Koro product IDs.
            'meta_query'     => array(
                array(
                    'key'     => '_bk_imported_product',
                    'value'   => '1',
                    'compare' => '=',
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
    }

    private function get_imported_products_stats() {
        $query          = new WP_Query( $this->get_imported_products_query_args() );
        $total_imported = (int) $query->found_posts;
        $stock_in       = 0;
        $stock_out      = 0;
        $products_list  = array();

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post ) {
                $product_id = $post->ID;
                $wc_product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
                $bk_id      = get_post_meta( $product_id, '_bk_product_id', true );
                $bk_stock   = get_post_meta( $product_id, '_bk_in_stock', true );

                $is_in_stock = ( '1' === $bk_stock || 'yes' === $bk_stock );
                if ( $wc_product && method_exists( $wc_product, 'is_in_stock' ) ) {
                    $is_in_stock = $wc_product->is_in_stock();
                }

                if ( $is_in_stock ) {
                    $stock_in++;
                } else {
                    $stock_out++;
                }

                $thumb_url = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
                if ( ! $thumb_url && function_exists( 'wc_placeholder_img_src' ) ) {
                    $thumb_url = wc_placeholder_img_src( 'thumbnail' );
                }

                $price_display = $wc_product && method_exists( $wc_product, 'get_price_html' )
                    ? $wc_product->get_price_html()
                    : ( get_post_meta( $product_id, '_regular_price', true ) ?: '0' );

                $products_list[] = array(
                    'id'         => $product_id,
                    'bk_id'      => $bk_id ? $bk_id : '-',
                    'title'      => get_the_title( $product_id ),
                    'price_html' => $price_display,
                    'in_stock'   => $is_in_stock,
                    'thumb_url'  => $thumb_url,
                    'edit_url'   => get_edit_post_link( $product_id, '' ),
                );
            }
        }
        wp_reset_postdata();

        return array(
            'total_imported' => $total_imported,
            'stock_in'       => $stock_in,
            'stock_out'      => $stock_out,
            'products'       => $products_list,
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        $page = ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bk_wc_admin_tab' ) && isset( $_GET['page'] ) )
            ? sanitize_key( wp_unslash( $_GET['page'] ) )
            : 'bk-wc-dashboard';

        $settings = $this->get_settings();
        $stats    = $this->get_imported_products_stats();

        $tabs = array(
            'bk-wc-dashboard' => array( 'label' => 'Dashboard', 'icon' => 'dashicons-chart-bar' ),
            'bk-wc-import'    => array( 'label' => 'Import Product', 'icon' => 'dashicons-download' ),
            'bk-wc-products'  => array( 'label' => 'Manage Products', 'icon' => 'dashicons-products' ),
            'bk-wc-orders'    => array( 'label' => 'Manage Orders', 'icon' => 'dashicons-cart' ),
            'bk-wc-settings'  => array( 'label' => 'Settings', 'icon' => 'dashicons-admin-generic' ),
            'bk-wc-help'      => array( 'label' => 'Help', 'icon' => 'dashicons-sos' ),
        );

        $is_active_api = ( 'Active' === $settings['api_status'] );
        ?>
        <div class="wrap bk-wc-wrap">
            <div class="bk-wc-header">
                <div class="bk-wc-brand">
                    <div class="bk-wc-logo-badge">
                        <span class="dashicons dashicons-store"></span>
                    </div>
                    <div>
                        <h1 class="bk-wc-title">BKProduct Sync for Business Koro</h1>
                        <p class="bk-wc-subtitle">Independent WooCommerce Product Sync, Variants &amp; Order Fulfillment Bridge</p>
                    </div>
                </div>
                <div class="bk-wc-header-status">
                    <span class="bk-status-pill <?php echo $is_active_api ? 'is-active' : 'is-error'; ?>" id="bk-header-api-badge">
                        <span class="bk-status-dot"></span>
                        <span id="bk-header-api-text"><?php echo esc_html( $settings['api_status'] ); ?></span>
                    </span>
                </div>
            </div>

            <nav class="bk-wc-nav" aria-label="Plugin sections">
                <?php foreach ( $tabs as $slug => $tab ) :
                    $url          = wp_nonce_url( admin_url( 'admin.php?page=' . $slug ), 'bk_wc_admin_tab' );
                    $active_class = ( $page === $slug ) ? 'is-active' : '';
                ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="bk-wc-nav-item <?php echo esc_attr( $active_class ); ?>">
                        <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                        <span><?php echo esc_html( $tab['label'] ); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div id="bk-wc-toast" class="bk-wc-toast" role="status" aria-live="polite" style="display:none;"></div>

            <div class="bk-wc-content">
                <?php
                switch ( $page ) {
                    case 'bk-wc-import':
                        $this->render_import_view( $settings );
                        break;
                    case 'bk-wc-products':
                        $this->render_manage_products_view( $stats );
                        break;
                    case 'bk-wc-orders':
                        $this->render_manage_orders_view();
                        break;
                    case 'bk-wc-settings':
                        $this->render_settings_view( $settings );
                        break;
                    case 'bk-wc-help':
                        $this->render_help_view();
                        break;
                    case 'bk-wc-dashboard':
                    default:
                        $this->render_dashboard_view( $settings, $stats );
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_dashboard_view( $settings, $stats ) {
        $is_active = ( 'Active' === $settings['api_status'] );
        $last_date = ! empty( $settings['last_imported_date'] ) ? $settings['last_imported_date'] : 'Never Imported';
        ?>
        <div class="bk-animate-fade-up">
            <div class="bk-kpi-grid">
                <div class="bk-card bk-kpi-card">
                    <div class="bk-kpi-icon <?php echo $is_active ? 'icon-success' : 'icon-danger'; ?>">
                        <span class="dashicons <?php echo $is_active ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    </div>
                    <div class="bk-kpi-meta">
                        <span class="bk-kpi-label">API Status</span>
                        <strong class="bk-kpi-value <?php echo $is_active ? 'text-success' : 'text-danger'; ?>" id="bk-dash-api-status">
                            <?php echo esc_html( $settings['api_status'] ); ?>
                        </strong>
                    </div>
                </div>

                <div class="bk-card bk-kpi-card">
                    <div class="bk-kpi-icon icon-primary">
                        <span class="dashicons dashicons-archive"></span>
                    </div>
                    <div class="bk-kpi-meta">
                        <span class="bk-kpi-label">Total Imported Product Count</span>
                        <strong class="bk-kpi-value" id="bk-dash-total-imported"><?php echo esc_html( number_format_i18n( $stats['total_imported'] ) ); ?></strong>
                    </div>
                </div>

                <div class="bk-card bk-kpi-card">
                    <div class="bk-kpi-icon icon-teal">
                        <span class="dashicons dashicons-database-add"></span>
                    </div>
                    <div class="bk-kpi-meta">
                        <span class="bk-kpi-label">Total Stock In Count</span>
                        <strong class="bk-kpi-value text-teal" id="bk-dash-stock-in"><?php echo esc_html( number_format_i18n( $stats['stock_in'] ) ); ?></strong>
                    </div>
                </div>

                <div class="bk-card bk-kpi-card">
                    <div class="bk-kpi-icon icon-amber">
                        <span class="dashicons dashicons-database-remove"></span>
                    </div>
                    <div class="bk-kpi-meta">
                        <span class="bk-kpi-label">Total Stock Out Count</span>
                        <strong class="bk-kpi-value text-amber" id="bk-dash-stock-out"><?php echo esc_html( number_format_i18n( $stats['stock_out'] ) ); ?></strong>
                    </div>
                </div>

                <div class="bk-card bk-kpi-card bk-kpi-wide">
                    <div class="bk-kpi-icon icon-purple">
                        <span class="dashicons dashicons-calendar-alt"></span>
                    </div>
                    <div class="bk-kpi-meta">
                        <span class="bk-kpi-label">Last Imported Date</span>
                        <strong class="bk-kpi-value bk-kpi-date" id="bk-dash-last-date"><?php echo esc_html( $last_date ); ?></strong>
                    </div>
                </div>
            </div>

            <div class="bk-card bk-mt-24">
                <div class="bk-card-header">
                    <div>
                        <h2>Total Imported Products List</h2>
                        <p class="bk-muted">Products synced from Business Koro into WooCommerce</p>
                    </div>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bk-wc-import' ), 'bk_wc_admin_tab' ) ); ?>" class="bk-btn bk-btn-primary">
                        <span class="dashicons dashicons-download"></span> Import More Products
                    </a>
                </div>

                <?php if ( empty( $stats['products'] ) ) : ?>
                    <div class="bk-empty-state">
                        <span class="dashicons dashicons-products"></span>
                        <h3>No Imported Products Yet</h3>
                        <p>Go to <strong>Import Product</strong> to sync Business Koro products directly into WooCommerce.</p>
                    </div>
                <?php else : ?>
                    <div class="bk-table-responsive">
                        <table class="bk-table">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Product Name</th>
                                    <th>BK Product ID</th>
                                    <th>WooCommerce Price</th>
                                    <th>Stock Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $stats['products'] as $item ) : ?>
                                    <tr>
                                        <td><img src="<?php echo esc_url( $item['thumb_url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" class="bk-thumb" /></td>
                                        <td><strong><?php echo esc_html( $item['title'] ); ?></strong></td>
                                        <td><code><?php echo esc_html( $item['bk_id'] ); ?></code></td>
                                        <td><?php echo wp_kses_post( $item['price_html'] ); ?></td>
                                        <td>
                                            <?php if ( $item['in_stock'] ) : ?>
                                                <span class="bk-badge bk-badge-success">Stock In</span>
                                            <?php else : ?>
                                                <span class="bk-badge bk-badge-danger">Stock Out</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_import_view( $settings ) {
        $has_key = ! empty( trim( $settings['api_key'] ) );
        ?>
        <div class="bk-animate-fade-up">
            <div class="bk-card bk-import-hero">
                <div class="bk-import-copy">
                    <h2>Import Products to WooCommerce</h2>
                    <p>
                        Fetch all available products and <strong>Color / Size variants</strong> from Business Koro and sync them into <strong>WooCommerce Products</strong> (name, description, price, stock status, attributes, and product image).
                    </p>
                    <?php if ( ! $has_key ) : ?>
                        <div class="bk-inline-alert bk-alert-warning">
                            <span class="dashicons dashicons-info"></span>
                            <span>API Key is not configured yet. Please set it in <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bk-wc-settings' ), 'bk_wc_admin_tab' ) ); ?>">Settings</a> first.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bk-import-action">
                    <button type="button" id="bk-start-import-btn" class="bk-btn bk-btn-primary bk-btn-lg">
                        <span class="dashicons dashicons-download"></span>
                        <span>Import Products</span>
                    </button>
                </div>
            </div>

            <div id="bk-import-progress-card" class="bk-card bk-mt-24" style="display:none;">
                <div class="bk-progress-header">
                    <div class="bk-spinner-row">
                        <span class="bk-spinner"></span>
                        <strong id="bk-import-status-text">Connecting to Business Koro API...</strong>
                    </div>
                    <span id="bk-import-percent" class="bk-percent-label">0%</span>
                </div>

                <div class="bk-progress-track">
                    <div id="bk-import-progress-bar" class="bk-progress-fill" style="width: 0%;"></div>
                </div>

                <div id="bk-import-summary" class="bk-import-summary" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    private function render_manage_products_view( $stats ) {
        ?>
        <div class="bk-animate-fade-up">
            <div class="bk-card">
                <div class="bk-card-header">
                    <div>
                        <h2>Manage Imported Products</h2>
                        <p class="bk-muted">View all Business Koro products in WooCommerce or remove imported products cleanly.</p>
                    </div>
                    <span class="bk-badge bk-badge-info" id="bk-manage-count-badge">
                        <?php echo esc_html( $stats['total_imported'] ); ?> Products
                    </span>
                </div>

                <?php if ( empty( $stats['products'] ) ) : ?>
                    <div class="bk-empty-state">
                        <span class="dashicons dashicons-products"></span>
                        <h3>No Products to Manage</h3>
                        <p>Imported WooCommerce products will appear here.</p>
                    </div>
                <?php else : ?>
                    <div class="bk-table-responsive">
                        <table class="bk-table" id="bk-manage-products-table">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Product Name</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th class="bk-text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $stats['products'] as $item ) : ?>
                                    <tr id="bk-product-row-<?php echo esc_attr( $item['id'] ); ?>" class="bk-row-anim">
                                        <td><img src="<?php echo esc_url( $item['thumb_url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" class="bk-thumb" /></td>
                                        <td>
                                            <strong><?php echo esc_html( $item['title'] ); ?></strong>
                                            <div class="bk-subtext">WC ID: #<?php echo esc_html( $item['id'] ); ?> &bull; BK ID: <code><?php echo esc_html( $item['bk_id'] ); ?></code></div>
                                        </td>
                                        <td><?php echo wp_kses_post( $item['price_html'] ); ?></td>
                                        <td>
                                            <?php if ( $item['in_stock'] ) : ?>
                                                <span class="bk-badge bk-badge-success">In Stock</span>
                                            <?php else : ?>
                                                <span class="bk-badge bk-badge-danger">Out of Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="bk-text-right">
                                            <?php if ( $item['edit_url'] ) : ?>
                                                <a href="<?php echo esc_url( $item['edit_url'] ); ?>" class="bk-btn bk-btn-ghost bk-btn-sm" target="_blank" rel="noopener">Edit in WC</a>
                                            <?php endif; ?>
                                            <button type="button" class="bk-btn bk-btn-danger bk-btn-sm bk-remove-product-btn" data-product-id="<?php echo esc_attr( $item['id'] ); ?>">
                                                <span class="dashicons dashicons-trash"></span> Remove
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_manage_orders_view() {
        $sent_orders = get_option( BKPSBK_SENT_ORDERS_OPTION, array() );
        if ( ! is_array( $sent_orders ) ) {
            $sent_orders = array();
        }

        $orders = array();
        if ( function_exists( 'wc_get_orders' ) ) {
            $orders = wc_get_orders(
                array(
                    'limit'  => 50,
                    'status' => array_keys( wc_get_order_statuses() ),
                    'return' => 'objects',
                )
            );
        }
        ?>
        <div class="bk-animate-fade-up">
            <div class="bk-card">
                <div class="bk-card-header">
                    <div>
                        <h2>Manage WooCommerce Orders</h2>
                        <p class="bk-muted">Click any order row to inspect customer details, send to Business Koro, or check live order status.</p>
                    </div>
                </div>

                <?php if ( empty( $orders ) ) : ?>
                    <div class="bk-empty-state">
                        <span class="dashicons dashicons-cart"></span>
                        <h3>No WooCommerce Orders Found</h3>
                        <p>When customers place orders on your WooCommerce store, they will appear here automatically.</p>
                    </div>
                <?php else : ?>
                    <div class="bk-table-responsive">
                        <table class="bk-table bk-orders-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Location</th>
                                    <th>Total</th>
                                    <th>WC Status</th>
                                    <th>Business Koro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $orders as $order ) :
                                    if ( ! is_a( $order, 'WC_Order' ) ) {
                                        continue;
                                    }
                                    $oid       = $order->get_id();
                                    $full_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                                    $phone     = $order->get_billing_phone();
                                    $city      = $order->get_billing_city();
                                    $state     = $order->get_billing_state();
                                    $location  = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
                                    $sent_info = isset( $sent_orders[ $oid ] ) ? $sent_orders[ $oid ] : null;
                                ?>
                                    <tr class="bk-order-row" data-order-id="<?php echo esc_attr( $oid ); ?>" title="Click to view order details">
                                        <td><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></td>
                                        <td><?php echo esc_html( $full_name ? $full_name : 'Customer' ); ?></td>
                                        <td><?php echo esc_html( $phone ? $phone : '-' ); ?></td>
                                        <td><?php echo esc_html( $location ? $location : '-' ); ?></td>
                                        <td><strong><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></strong></td>
                                        <td><span class="bk-badge bk-badge-neutral"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span></td>
                                        <td id="bk-order-badge-<?php echo esc_attr( $oid ); ?>">
                                            <?php if ( $sent_info ) :
                                                $remote_st = ! empty( $sent_info['remote_status'] ) ? ' &bull; ' . $sent_info['remote_status'] : '';
                                            ?>
                                                <span class="bk-badge bk-badge-success">Sent (#<?php echo esc_html( $sent_info['bk_order_id'] . $remote_st ); ?>)</span>
                                            <?php else : ?>
                                                <span class="bk-badge bk-badge-warning">Pending Send</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="bk-order-modal" class="bk-modal-backdrop" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="bk-modal-title">
            <div class="bk-modal-card">
                <div class="bk-modal-header">
                    <h3 id="bk-modal-title">Order Details</h3>
                    <button type="button" id="bk-modal-close" class="bk-modal-close" aria-label="Close modal">&times;</button>
                </div>
                <div id="bk-modal-body" class="bk-modal-body">
                    <div class="bk-spinner-row">
                        <span class="bk-spinner"></span>
                        <span>Loading order details...</span>
                    </div>
                </div>
                <div class="bk-modal-footer">
                    <div id="bk-modal-send-feedback" class="bk-modal-feedback"></div>
                    <div class="bk-modal-actions">
                        <button type="button" id="bk-check-order-status-btn" class="bk-btn bk-btn-ghost" data-order-id="" style="display:none;">
                            <span class="dashicons dashicons-update"></span>
                            <span>Check Status</span>
                        </button>
                        <button type="button" id="bk-send-order-btn" class="bk-btn bk-btn-primary bk-btn-lg" data-order-id="">
                            <span class="dashicons dashicons-upload"></span>
                            <span>Send Order to Business koro</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_settings_view( $settings ) {
        $is_active = ( 'Active' === $settings['api_status'] );
        ?>
        <div class="bk-animate-fade-up">
            <div class="bk-card bk-settings-card">
                <div class="bk-card-header">
                    <div>
                        <h2>API &amp; Fulfillment Settings</h2>
                        <p class="bk-muted">Your API key is stored safely in your WordPress database and used strictly on the server side.</p>
                    </div>
                    <span class="bk-status-pill <?php echo $is_active ? 'is-active' : 'is-error'; ?>" id="bk-settings-status-pill">
                        <span class="bk-status-dot"></span>
                        <span id="bk-settings-status-text"><?php echo esc_html( $settings['api_status'] ); ?></span>
                    </span>
                </div>

                <form id="bk-settings-form" class="bk-form">
                    <div class="bk-form-group">
                        <label for="bk-api-key">Business Koro API Key <span class="bk-required">*</span></label>
                        <div class="bk-input-group">
                            <input
                                type="password"
                                id="bk-api-key"
                                name="api_key"
                                value="<?php echo esc_attr( $settings['api_key'] ); ?>"
                                placeholder="Enter your Business Koro API Key"
                                autocomplete="off"
                                class="bk-input"
                            />
                            <button type="button" id="bk-toggle-key-visibility" class="bk-btn bk-btn-ghost" title="Show/Hide API Key">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                        <p class="bk-help-text">Once saved, your API key remains persisted until you update it. Valid keys show <strong>Active</strong>; invalid keys show <strong>Api key Incorrect</strong>.</p>
                    </div>

                    <div class="bk-form-group">
                        <label for="bk-domain">Domain Header (<code>x-domain</code> — Optional)</label>
                        <input
                            type="text"
                            id="bk-domain"
                            name="domain"
                            value="<?php echo esc_attr( $settings['domain'] ); ?>"
                            placeholder="e.g. example.com (leave empty if not required)"
                            class="bk-input"
                        />
                        <p class="bk-help-text">Leave empty to avoid domain header restrictions unless required by your Business Koro key.</p>
                    </div>

                    <div class="bk-grid-2">
                        <div class="bk-form-group">
                            <label for="bk-delivery-paid-customer">Who Pays Delivery Charge?</label>
                            <select id="bk-delivery-paid-customer" name="delivery_paid_customer" class="bk-input">
                                <option value="1" <?php selected( $settings['delivery_paid_customer'], '1' ); ?>>Customer Pays Delivery (true)</option>
                                <option value="0" <?php selected( $settings['delivery_paid_customer'], '0' ); ?>>Reseller / Shop Pays Delivery (false)</option>
                            </select>
                        </div>

                        <div class="bk-form-group">
                            <label for="bk-delivery-mode">Delivery Collection Mode</label>
                            <select id="bk-delivery-mode" name="delivery_mode" class="bk-input">
                                <option value="" <?php selected( $settings['delivery_mode'], '' ); ?>>Standard (Not Upfront)</option>
                                <option value="CUSTOMER_PAID_RESELLER_UPFRONT" <?php selected( $settings['delivery_mode'], 'CUSTOMER_PAID_RESELLER_UPFRONT' ); ?>>CUSTOMER_PAID_RESELLER_UPFRONT</option>
                            </select>
                        </div>
                    </div>

                    <div class="bk-grid-2">
                        <div class="bk-form-group">
                            <label for="bk-price-markup-type">WooCommerce Price Rule</label>
                            <select id="bk-price-markup-type" name="price_markup_type" class="bk-input">
                                <option value="suggested" <?php selected( $settings['price_markup_type'], 'suggested' ); ?>>Use Business Koro Suggested Price</option>
                                <option value="markup" <?php selected( $settings['price_markup_type'], 'markup' ); ?>>Suggested Price + Fixed Markup (BDT)</option>
                            </select>
                        </div>

                        <div class="bk-form-group">
                            <label for="bk-price-markup-value">Fixed Markup Amount (BDT)</label>
                            <input
                                type="number"
                                step="0.01"
                                id="bk-price-markup-value"
                                name="price_markup_value"
                                value="<?php echo esc_attr( $settings['price_markup_value'] ); ?>"
                                class="bk-input"
                            />
                        </div>
                    </div>

                    <div class="bk-form-actions">
                        <button type="submit" id="bk-save-settings-btn" class="bk-btn bk-btn-primary bk-btn-lg">
                            <span class="dashicons dashicons-saved"></span>
                            <span>Save Settings</span>
                        </button>

                        <button type="button" id="bk-test-api-btn" class="bk-btn bk-btn-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <span>Verify API Status</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    private function render_help_view() {
        $creator_name = 'Md.Shimul Hasan Boni';
        $fb_id_url    = 'https://facebook.com/BDshemul71';
        $fb_page_url  = 'https://facebook.com/vipergadget';
        $tiktok_url   = 'https://tiktok.com/@vipergadget';
        $email        = 'vipergadget20@gmail.com';
        ?>
        <div class="bk-animate-fade-up">
            <div class="bk-card bk-help-card">
                <div class="bk-card-header">
                    <div>
                        <h2>Help &amp; Developer Contact Information</h2>
                        <p class="bk-muted">Independent plugin developer contact details (read-only &amp; locked).</p>
                    </div>
                    <span class="bk-badge bk-badge-neutral"><span class="dashicons dashicons-lock"></span> Locked Contact Info</span>
                </div>

                <div class="bk-contact-grid">
                    <div class="bk-contact-item">
                        <span class="bk-contact-label">Creator Name</span>
                        <input type="text" class="bk-input bk-locked-input" value="<?php echo esc_attr( $creator_name ); ?>" readonly aria-readonly="true" />
                    </div>

                    <div class="bk-contact-item">
                        <span class="bk-contact-label">Creator Facebook Id</span>
                        <div class="bk-contact-link-row">
                            <input type="text" class="bk-input bk-locked-input" value="<?php echo esc_attr( $fb_id_url ); ?>" readonly aria-readonly="true" />
                            <a href="<?php echo esc_url( $fb_id_url ); ?>" target="_blank" rel="noopener noreferrer" class="bk-btn bk-btn-ghost">Open</a>
                        </div>
                    </div>

                    <div class="bk-contact-item">
                        <span class="bk-contact-label">Facebook Page</span>
                        <div class="bk-contact-link-row">
                            <input type="text" class="bk-input bk-locked-input" value="<?php echo esc_attr( $fb_page_url ); ?>" readonly aria-readonly="true" />
                            <a href="<?php echo esc_url( $fb_page_url ); ?>" target="_blank" rel="noopener noreferrer" class="bk-btn bk-btn-ghost">Open</a>
                        </div>
                    </div>

                    <div class="bk-contact-item">
                        <span class="bk-contact-label">TikTok</span>
                        <div class="bk-contact-link-row">
                            <input type="text" class="bk-input bk-locked-input" value="<?php echo esc_attr( $tiktok_url ); ?>" readonly aria-readonly="true" />
                            <a href="<?php echo esc_url( $tiktok_url ); ?>" target="_blank" rel="noopener noreferrer" class="bk-btn bk-btn-ghost">Open</a>
                        </div>
                    </div>

                    <div class="bk-contact-item bk-contact-full">
                        <span class="bk-contact-label">Email</span>
                        <div class="bk-contact-link-row">
                            <input type="text" class="bk-input bk-locked-input" value="<?php echo esc_attr( $email ); ?>" readonly aria-readonly="true" />
                            <a href="mailto:<?php echo esc_attr( $email ); ?>" class="bk-btn bk-btn-primary">Send Email</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function sync_product_variants( $wc_post_id, $variants ) {
        if ( ! is_array( $variants ) || empty( $variants ) ) {
            update_post_meta( $wc_post_id, '_bk_variants', wp_json_encode( array() ) );
            return;
        }

        $clean_variants = array();
        $colors         = array();
        $sizes          = array();

        foreach ( $variants as $v ) {
            if ( ! is_array( $v ) ) {
                continue;
            }
            $color = isset( $v['color'] ) && null !== $v['color'] && '' !== trim( (string) $v['color'] )
                ? sanitize_text_field( (string) $v['color'] )
                : '';
            $size  = isset( $v['size'] ) && null !== $v['size'] && '' !== trim( (string) $v['size'] )
                ? sanitize_text_field( (string) $v['size'] )
                : '';
            $in_st = ! empty( $v['inStock'] );

            if ( '' !== $color && ! in_array( $color, $colors, true ) ) {
                $colors[] = $color;
            }
            if ( '' !== $size && ! in_array( $size, $sizes, true ) ) {
                $sizes[] = $size;
            }

            $clean_variants[] = array(
                'color'   => $color ? $color : null,
                'size'    => $size ? $size : null,
                'inStock' => $in_st,
            );
        }

        update_post_meta( $wc_post_id, '_bk_variants', wp_json_encode( $clean_variants ) );

        $attributes = array();
        if ( ! empty( $colors ) && class_exists( 'WC_Product_Attribute' ) ) {
            $attr_color = new WC_Product_Attribute();
            $attr_color->set_name( 'Color' );
            $attr_color->set_options( $colors );
            $attr_color->set_visible( true );
            $attr_color->set_variation( false );
            $attributes[] = $attr_color;
        }
        if ( ! empty( $sizes ) && class_exists( 'WC_Product_Attribute' ) ) {
            $attr_size = new WC_Product_Attribute();
            $attr_size->set_name( 'Size' );
            $attr_size->set_options( $sizes );
            $attr_size->set_visible( true );
            $attr_size->set_variation( false );
            $attributes[] = $attr_size;
        }

        if ( ! empty( $attributes ) && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $wc_post_id );
            if ( $product ) {
                $product->set_attributes( $attributes );
                $product->save();
            }
        }
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'bk_wc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $current       = $this->get_settings();
        $api_key       = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $domain        = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
        $delivery_paid = isset( $_POST['delivery_paid_customer'] ) && '0' === $_POST['delivery_paid_customer'] ? '0' : '1';
        $delivery_mode = isset( $_POST['delivery_mode'] ) && 'CUSTOMER_PAID_RESELLER_UPFRONT' === $_POST['delivery_mode']
            ? 'CUSTOMER_PAID_RESELLER_UPFRONT'
            : '';
        $markup_type   = isset( $_POST['price_markup_type'] ) && 'markup' === $_POST['price_markup_type'] ? 'markup' : 'suggested';
        $markup_val    = isset( $_POST['price_markup_value'] ) ? floatval( wp_unslash( $_POST['price_markup_value'] ) ) : 0;

        $check  = BKPSBK_API::verify_credentials( $api_key, $domain );
        $status = $check['valid'] ? 'Active' : 'Api key Incorrect';

        $updated = array(
            'api_key'                => $api_key,
            'domain'                 => $domain,
            'api_status'             => $status,
            'last_imported_date'     => $current['last_imported_date'],
            'delivery_paid_customer' => $delivery_paid,
            'delivery_mode'          => $delivery_mode,
            'price_markup_type'      => $markup_type,
            'price_markup_value'     => (string) $markup_val,
        );

        update_option( BKPSBK_OPTION_KEY, $updated );

        wp_send_json_success(
            array(
                'message'    => $check['valid']
                    ? 'Settings saved. API key is Active!'
                    : 'Settings saved, but API status is: Api key Incorrect.',
                'api_status' => $status,
                'is_active'  => $check['valid'],
            )
        );
    }

    public function ajax_check_status() {
        check_ajax_referer( 'bk_wc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $settings = $this->get_settings();
        $check    = BKPSBK_API::verify_credentials( $settings['api_key'], $settings['domain'] );
        $status   = $check['valid'] ? 'Active' : 'Api key Incorrect';

        $settings['api_status'] = $status;
        update_option( BKPSBK_OPTION_KEY, $settings );

        wp_send_json_success(
            array(
                'api_status' => $status,
                'is_active'  => $check['valid'],
                'message'    => $check['message'],
            )
        );
    }

    public function ajax_import_products() {
        check_ajax_referer( 'bk_wc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( array( 'message' => 'WooCommerce is not active.' ) );
        }

        $settings = $this->get_settings();
        $res      = BKPSBK_API::get_products( $settings['api_key'], $settings['domain'] );

        if ( is_wp_error( $res ) ) {
            $settings['api_status'] = 'Api key Incorrect';
            update_option( BKPSBK_OPTION_KEY, $settings );
            wp_send_json_error( array( 'message' => $res->get_error_message(), 'api_status' => 'Api key Incorrect' ) );
        }

        $settings['api_status'] = 'Active';

        $items = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
        if ( empty( $items ) ) {
            $settings['last_imported_date'] = current_time( 'mysql' );
            update_option( BKPSBK_OPTION_KEY, $settings );
            wp_send_json_success(
                array(
                    'message'            => 'Connected to Business Koro, but 0 products were returned.',
                    'imported'           => 0,
                    'updated'            => 0,
                    'failed'             => 0,
                    'last_imported_date' => $settings['last_imported_date'],
                )
            );
        }

        $imported_count = 0;
        $updated_count  = 0;
        $failed_count   = 0;

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['name'] ) ) {
                $failed_count++;
                continue;
            }

            $bk_prod_id  = sanitize_text_field( (string) $item['id'] );
            $name        = sanitize_text_field( (string) $item['name'] );
            $description = isset( $item['description'] ) ? wp_kses_post( (string) $item['description'] ) : '';
            $in_stock    = ! empty( $item['inStock'] );

            $base_price = isset( $item['suggestedPrice'] ) && is_numeric( $item['suggestedPrice'] )
                ? floatval( $item['suggestedPrice'] )
                : 0;

            if ( 'markup' === $settings['price_markup_type'] ) {
                $base_price = max( 0, $base_price + floatval( $settings['price_markup_value'] ) );
            }

            $existing_wc_id = $this->find_wc_product_by_bk_id( $bk_prod_id );

            $post_data = array(
                'post_title'   => $name,
                'post_content' => $description,
                'post_status'  => 'publish',
                'post_type'    => 'product',
            );

            if ( $existing_wc_id ) {
                $post_data['ID'] = $existing_wc_id;
                $wc_post_id = wp_update_post( $post_data, true );
                if ( is_wp_error( $wc_post_id ) || ! $wc_post_id ) {
                    $failed_count++;
                    continue;
                }
                $updated_count++;
            } else {
                $wc_post_id = wp_insert_post( $post_data, true );
                if ( is_wp_error( $wc_post_id ) || ! $wc_post_id ) {
                    $failed_count++;
                    continue;
                }
                $imported_count++;
            }

            if ( function_exists( 'wp_set_object_terms' ) ) {
                wp_set_object_terms( $wc_post_id, 'simple', 'product_type' );
            }

            update_post_meta( $wc_post_id, '_regular_price', (string) $base_price );
            update_post_meta( $wc_post_id, '_price', (string) $base_price );
            update_post_meta( $wc_post_id, '_stock_status', $in_stock ? 'instock' : 'outofstock' );
            update_post_meta( $wc_post_id, '_manage_stock', 'no' );

            update_post_meta( $wc_post_id, '_bk_imported_product', '1' );
            update_post_meta( $wc_post_id, '_bk_product_id', $bk_prod_id );
            update_post_meta( $wc_post_id, '_bk_in_stock', $in_stock ? '1' : '0' );

            if ( isset( $item['costPrice'] ) ) {
                update_post_meta( $wc_post_id, '_bk_cost_price', sanitize_text_field( (string) $item['costPrice'] ) );
            }
            if ( isset( $item['minimumSellingPrice'] ) && is_array( $item['minimumSellingPrice'] ) ) {
                update_post_meta( $wc_post_id, '_bk_min_selling_price', wp_json_encode( $item['minimumSellingPrice'] ) );
            }

            $variants = isset( $item['variants'] ) && is_array( $item['variants'] ) ? $item['variants'] : array();
            $this->sync_product_variants( $wc_post_id, $variants );

            if ( ! empty( $item['images'] ) && is_array( $item['images'] ) ) {
                $first_img       = esc_url_raw( $item['images'][0] );
                $current_img_url = get_post_meta( $wc_post_id, '_bk_source_image_url', true );
                if ( $first_img && ( ! has_post_thumbnail( $wc_post_id ) || $current_img_url !== $first_img ) ) {
                    $attach_id = $this->attach_image_from_url( $first_img, $wc_post_id, $name );
                    if ( $attach_id && ! is_wp_error( $attach_id ) ) {
                        set_post_thumbnail( $wc_post_id, $attach_id );
                        update_post_meta( $wc_post_id, '_bk_source_image_url', $first_img );
                    }
                }
            }
        }

        $settings['last_imported_date'] = current_time( 'mysql' );
        update_option( BKPSBK_OPTION_KEY, $settings );

        wp_send_json_success(
            array(
                'message'            => sprintf(
                    'Import completed! %d new imported, %d updated, %d failed.',
                    $imported_count,
                    $updated_count,
                    $failed_count
                ),
                'imported'           => $imported_count,
                'updated'            => $updated_count,
                'failed'             => $failed_count,
                'last_imported_date' => $settings['last_imported_date'],
            )
        );
    }

    private function find_wc_product_by_bk_id( $bk_prod_id ) {
        $posts = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Scoped to imported Business Koro product lookup.
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'   => '_bk_imported_product',
                        'value' => '1',
                    ),
                    array(
                        'key'   => '_bk_product_id',
                        'value' => $bk_prod_id,
                    ),
                ),
            )
        );
        return ! empty( $posts ) ? (int) $posts[0] : 0;
    }

    private function attach_image_from_url( $url, $post_id, $desc ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 20 );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        $file_ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
        if ( ! $file_ext || ! in_array( strtolower( $file_ext ), array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ) ) {
            $file_ext = 'jpg';
        }

        $file_array = array(
            'name'     => sanitize_file_name( 'bk-' . $post_id . '-' . time() . '.' . $file_ext ),
            'tmp_name' => $tmp,
        );

        $id = media_handle_sideload( $file_array, $post_id, $desc );
        if ( is_wp_error( $id ) && file_exists( $tmp ) ) {
            wp_delete_file( $tmp );
        }
        return $id;
    }

    public function ajax_remove_product() {
        check_ajax_referer( 'bk_wc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid product ID.' ) );
        }

        $is_bk = get_post_meta( $product_id, '_bk_imported_product', true );
        if ( '1' !== $is_bk ) {
            wp_send_json_error( array( 'message' => 'This product is not marked as a Business Koro imported product.' ) );
        }

        $deleted = wp_delete_post( $product_id, true );
        if ( ! $deleted ) {
            wp_send_json_error( array( 'message' => 'Failed to delete product.' ) );
        }

        $stats = $this->get_imported_products_stats();

        wp_send_json_success(
            array(
                'message'        => 'Product removed from WooCommerce.',
                'product_id'     => $product_id,
                'total_imported' => $stats['total_imported'],
            )
        );
    }

    private function extract_order_variant_selection( $item, $prod_id ) {
        $selected_color = '';
        $selected_size  = '';

        foreach ( $item->get_meta_data() as $meta ) {
            $mdata = $meta->get_data();
            $k     = strtolower( (string) $mdata['key'] );
            $v     = is_scalar( $mdata['value'] ) ? trim( (string) $mdata['value'] ) : '';
            if ( '' === $v ) {
                continue;
            }
            if ( false !== strpos( $k, 'color' ) || 'pa_color' === $k || 'color' === $k ) {
                $selected_color = $v;
            }
            if ( false !== strpos( $k, 'size' ) || 'pa_size' === $k || 'size' === $k ) {
                $selected_size = $v;
            }
        }

        $raw_variants = $prod_id ? get_post_meta( $prod_id, '_bk_variants', true ) : '';
        $variants     = $raw_variants ? json_decode( $raw_variants, true ) : array();

        if ( is_array( $variants ) && ! empty( $variants ) ) {
            foreach ( $variants as $var ) {
                if ( empty( $var['inStock'] ) ) {
                    continue;
                }
                if ( '' === $selected_color && ! empty( $var['color'] ) ) {
                    $selected_color = sanitize_text_field( (string) $var['color'] );
                }
                if ( '' === $selected_size && ! empty( $var['size'] ) ) {
                    $selected_size = sanitize_text_field( (string) $var['size'] );
                }
                break;
            }
        }

        return array(
            'color' => $selected_color,
            'size'  => $selected_size,
        );
    }

    public function ajax_get_order_details() {
        check_ajax_referer( 'bk_wc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            wp_send_json_error( array( 'message' => 'Order not found.' ) );
        }

        $items_data = array();
        foreach ( $order->get_items() as $item ) {
            $prod_id = $item->get_product_id();
            $bk_pid  = $prod_id ? get_post_meta( $prod_id, '_bk_product_id', true ) : '';
            $var_sel = $this->extract_order_variant_selection( $item, $prod_id );

            $items_data[] = array(
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'total'        => $item->get_total(),
                'bk_productId' => $bk_pid,
                'color'        => $var_sel['color'],
                'size'         => $var_sel['size'],
            );
        }

        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( ! $customer_name ) {
            $customer_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        }

        $address_1 = $order->get_billing_address_1();
        $address_2 = $order->get_billing_address_2();
        $full_addr = trim( $address_1 . ( $address_2 ? ', ' . $address_2 : '' ) );

        $sent_orders = get_option( BKPSBK_SENT_ORDERS_OPTION, array() );
        $sent_info   = isset( $sent_orders[ $order_id ] ) ? $sent_orders[ $order_id ] : null;

        wp_send_json_success(
            array(
                'order_id'       => $order_id,
                'order_number'   => $order->get_order_number(),
                'customer_name'  => $customer_name,
                'customer_phone' => $order->get_billing_phone(),
                'address'        => $full_addr,
                'district'       => $order->get_billing_city(),
                'area'           => $order->get_billing_address_2() ? $order->get_billing_address_2() : $order->get_billing_city(),
                'division'       => $order->get_billing_state(),
                'total_amount'   => floatval( $order->get_total() ),
                'currency'       => $order->get_currency(),
                'note'           => $order->get_customer_note(),
                'items'          => $items_data,
                'sent_info'      => $sent_info,
            )
        );
    }

    public function ajax_send_order() {
        check_ajax_referer( 'bk_wc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid WooCommerce order.' ) );
        }

        $settings = $this->get_settings();
        if ( empty( trim( $settings['api_key'] ) ) ) {
            wp_send_json_error( array( 'message' => 'Please configure your Business Koro API Key in Settings first.' ) );
        }

        $bk_product_id = '';
        $line_total    = 0;
        $var_sel       = array( 'color' => '', 'size' => '' );

        foreach ( $order->get_items() as $item ) {
            $prod_id = $item->get_product_id();
            $bk_pid  = $prod_id ? get_post_meta( $prod_id, '_bk_product_id', true ) : '';
            if ( ! $bk_product_id && $bk_pid ) {
                $bk_product_id = sanitize_text_field( (string) $bk_pid );
                $var_sel       = $this->extract_order_variant_selection( $item, $prod_id );
            }
            $line_total += floatval( $item->get_total() );
        }

        if ( empty( $bk_product_id ) ) {
            wp_send_json_error( array( 'message' => 'This order item does not have a linked Business Koro Product ID (_bk_product_id).' ) );
        }

        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( ! $customer_name ) {
            $customer_name = 'Customer #' . $order->get_order_number();
        }

        $address_1 = $order->get_billing_address_1();
        $address_2 = $order->get_billing_address_2();
        $full_addr = trim( $address_1 . ( $address_2 ? ', ' . $address_2 : '' ) );

        $district = $order->get_billing_city();
        $division = $order->get_billing_state();
        $area     = $address_2 ? $address_2 : $district;

        $selling_price = $line_total > 0 ? round( $line_total ) : round( floatval( $order->get_total() ) );
        $delivery_paid = ( '0' !== $settings['delivery_paid_customer'] );

        $payload = array(
            'productId'                    => $bk_product_id,
            'customerName'                 => $customer_name,
            'customerPhone'                => $order->get_billing_phone(),
            'customerAddress'              => $full_addr ? $full_addr : 'N/A',
            'customerDivision'             => $division ? $division : 'Dhaka',
            'customerDistrict'             => $district ? $district : 'Dhaka',
            'customerArea'                 => $area ? $area : 'Center',
            'sellingPrice'                 => (int) $selling_price,
            'deliveryChargePaidByCustomer' => $delivery_paid,
            'customerNote'                 => $order->get_customer_note(),
        );

        if ( '' !== $var_sel['color'] ) {
            $payload['color'] = $var_sel['color'];
        }
        if ( '' !== $var_sel['size'] ) {
            $payload['size'] = $var_sel['size'];
        }
        if ( 'CUSTOMER_PAID_RESELLER_UPFRONT' === $settings['delivery_mode'] ) {
            $payload['deliveryChargeCollectionMode'] = 'CUSTOMER_PAID_RESELLER_UPFRONT';
        }

        $api_res = BKPSBK_API::create_order( $settings['api_key'], $settings['domain'], $payload );

        if ( is_wp_error( $api_res ) ) {
            wp_send_json_error( array( 'message' => $api_res->get_error_message() ) );
        }

        $bk_order_id = 'BK-' . $order_id;
        if ( isset( $api_res['data']['id'] ) ) {
            $bk_order_id = sanitize_text_field( (string) $api_res['data']['id'] );
        } elseif ( isset( $api_res['id'] ) ) {
            $bk_order_id = sanitize_text_field( (string) $api_res['id'] );
        } elseif ( isset( $api_res['data']['orderId'] ) ) {
            $bk_order_id = sanitize_text_field( (string) $api_res['data']['orderId'] );
        }

        $remote_status = '';
        if ( isset( $api_res['data']['status'] ) ) {
            $remote_status = sanitize_text_field( (string) $api_res['data']['status'] );
        } elseif ( isset( $api_res['status'] ) ) {
            $remote_status = sanitize_text_field( (string) $api_res['status'] );
        }

        $sent_orders = get_option( BKPSBK_SENT_ORDERS_OPTION, array() );
        if ( ! is_array( $sent_orders ) ) {
            $sent_orders = array();
        }
        $sent_orders[ $order_id ] = array(
            'bk_order_id'   => $bk_order_id,
            'remote_status' => $remote_status,
            'sent_at'       => current_time( 'mysql' ),
        );
        update_option( BKPSBK_SENT_ORDERS_OPTION, $sent_orders );

        $order->add_order_note( 'Order sent to Business Koro. BK Order ID: ' . $bk_order_id );

        wp_send_json_success(
            array(
                'message'       => 'Order successfully sent to Business koro!',
                'order_id'      => $order_id,
                'bk_order_id'   => $bk_order_id,
                'remote_status' => $remote_status,
            )
        );
    }

    public function ajax_check_order_status() {
        check_ajax_referer( 'bk_wc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Invalid order ID.' ) );
        }

        $sent_orders = get_option( BKPSBK_SENT_ORDERS_OPTION, array() );
        if ( ! is_array( $sent_orders ) || empty( $sent_orders[ $order_id ]['bk_order_id'] ) ) {
            wp_send_json_error( array( 'message' => 'This order has not been sent to Business Koro yet.' ) );
        }

        $bk_order_id = sanitize_text_field( (string) $sent_orders[ $order_id ]['bk_order_id'] );
        $settings    = $this->get_settings();
        $res         = BKPSBK_API::get_order_status( $settings['api_key'], $settings['domain'], $bk_order_id );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }

        $remote_status = 'Updated';
        if ( isset( $res['data']['status'] ) ) {
            $remote_status = sanitize_text_field( (string) $res['data']['status'] );
        } elseif ( isset( $res['status'] ) ) {
            $remote_status = sanitize_text_field( (string) $res['status'] );
        }

        $sent_orders[ $order_id ]['remote_status'] = $remote_status;
        $sent_orders[ $order_id ]['checked_at']    = current_time( 'mysql' );
        update_option( BKPSBK_SENT_ORDERS_OPTION, $sent_orders );

        wp_send_json_success(
            array(
                'order_id'      => $order_id,
                'bk_order_id'   => $bk_order_id,
                'remote_status' => $remote_status,
                'message'       => 'Business Koro Order #' . $bk_order_id . ' Status: ' . $remote_status,
            )
        );
    }
}

add_action(
    'plugins_loaded',
    function () {
        BKPSBK_Business_Koro_Sync::instance();
    }
);
