<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Drives the chunked sync to the SaaS.
 *
 * Linear chain (each step schedules the next, so we never run a huge job in one
 * request and never need cross-job coordination):
 *   orders(page 1..N) -> products(page 1..N) -> customers(page 1..N) -> sync-complete
 *
 * Chunk sizes follow the spec: 50 orders, 100 products, 100 customers per request.
 */
class Insightistic_Sync
{
    private static ?self $instance = null;

    const ORDERS_PER    = 50;
    const PRODUCTS_PER  = 100;
    const CUSTOMERS_PER = 100;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void
    {
        add_action('insightistic_sync_orders', [$this, 'sync_orders'], 10, 1);
        add_action('insightistic_sync_products', [$this, 'sync_products'], 10, 1);
        add_action('insightistic_sync_customers', [$this, 'sync_customers'], 10, 1);
        add_action('insightistic_run_sync', [$this, 'start_full_sync'], 10, 0);
    }

    private function woo_active(): bool
    {
        return class_exists('WooCommerce');
    }

    private function enqueue(string $hook, array $args = []): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args, 'insightistic');
        } else {
            // Fallback: run inline (small stores / no Action Scheduler).
            do_action($hook, ...array_values($args));
        }
    }

    /** Entry point — kicks the chain. Safe to call from the admin "Sync now" button. */
    public function start_full_sync(): void
    {
        if (! Insightistic_Settings::is_connected()) {
            Insightistic_Settings::log('Sync skipped: site not connected.', 'warning');
            return;
        }

        // Refresh site health first (cheap, confirms link is healthy).
        if (Insightistic_Settings::get('sync_site_health')) {
            Insightistic_Client::post('/api/connector/v1/site-health', $this->site_health_payload());
        }

        if (Insightistic_Settings::get('sync_orders') && $this->woo_active()) {
            $this->enqueue('insightistic_sync_orders', ['page' => 1]);
        } elseif (Insightistic_Settings::get('sync_products') && $this->woo_active()) {
            $this->enqueue('insightistic_sync_products', ['page' => 1]);
        } elseif (Insightistic_Settings::get('sync_customers') && $this->woo_active()) {
            $this->enqueue('insightistic_sync_customers', ['page' => 1]);
        } else {
            $this->finish();
        }
    }

    public function sync_orders(int $page = 1): void
    {
        if (! $this->woo_active()) {
            $this->advance_after_orders();
            return;
        }

        $orders = wc_get_orders([
            'limit'   => self::ORDERS_PER,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'objects',
        ]);

        if (empty($orders)) {
            $this->advance_after_orders();
            return;
        }

        $payload = ['orders' => array_map([$this, 'map_order'], $orders)];
        $res = Insightistic_Client::post('/api/connector/v1/orders/bulk', $payload);
        Insightistic_Settings::log(
            sprintf('Orders page %d: %s (%d sent)', $page, $res['ok'] ? 'ok' : 'failed: ' . $res['error'], count($orders)),
            $res['ok'] ? 'info' : 'error'
        );

        if (count($orders) < self::ORDERS_PER) {
            $this->advance_after_orders();
        } else {
            $this->enqueue('insightistic_sync_orders', ['page' => $page + 1]);
        }
    }

    public function sync_products(int $page = 1): void
    {
        if (! $this->woo_active()) {
            $this->advance_after_products();
            return;
        }

        $products = wc_get_products([
            'limit'   => self::PRODUCTS_PER,
            'page'    => $page,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'return'  => 'objects',
        ]);

        if (empty($products)) {
            $this->advance_after_products();
            return;
        }

        $payload = ['products' => array_map([$this, 'map_product'], $products)];
        $res = Insightistic_Client::post('/api/connector/v1/products/bulk', $payload);
        Insightistic_Settings::log(
            sprintf('Products page %d: %s (%d sent)', $page, $res['ok'] ? 'ok' : 'failed: ' . $res['error'], count($products)),
            $res['ok'] ? 'info' : 'error'
        );

        if (count($products) < self::PRODUCTS_PER) {
            $this->advance_after_products();
        } else {
            $this->enqueue('insightistic_sync_products', ['page' => $page + 1]);
        }
    }

    public function sync_customers(int $page = 1): void
    {
        $users = get_users([
            'role'    => 'customer',
            'number'  => self::CUSTOMERS_PER,
            'paged'   => $page,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        if (empty($users)) {
            $this->finish();
            return;
        }

        $payload = ['customers' => array_map([$this, 'map_customer'], $users)];
        $res = Insightistic_Client::post('/api/connector/v1/customers/bulk', $payload);
        Insightistic_Settings::log(
            sprintf('Customers page %d: %s (%d sent)', $page, $res['ok'] ? 'ok' : 'failed: ' . $res['error'], count($users)),
            $res['ok'] ? 'info' : 'error'
        );

        if (count($users) < self::CUSTOMERS_PER) {
            $this->finish();
        } else {
            $this->enqueue('insightistic_sync_customers', ['page' => $page + 1]);
        }
    }

    private function advance_after_orders(): void
    {
        if (Insightistic_Settings::get('sync_products') && $this->woo_active()) {
            $this->enqueue('insightistic_sync_products', ['page' => 1]);
        } else {
            $this->advance_after_products();
        }
    }

    private function advance_after_products(): void
    {
        if (Insightistic_Settings::get('sync_customers')) {
            $this->enqueue('insightistic_sync_customers', ['page' => 1]);
        } else {
            $this->finish();
        }
    }

    private function finish(): void
    {
        $res = Insightistic_Client::post('/api/connector/v1/sync-complete', []);
        if ($res['ok']) {
            Insightistic_Settings::update(['last_sync' => current_time('mysql')]);
            Insightistic_Settings::log('Full sync complete.', 'info');
        } else {
            Insightistic_Settings::log('sync-complete failed: ' . $res['error'], 'error');
        }
    }

    // --- mappers (only business data — never card/payment secrets) ----------

    public function map_order($order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'external_product_id' => $item->get_product_id(),
                'product_name'        => $item->get_name(),
                'sku'                 => $product ? $product->get_sku() : null,
                'quantity'            => (int) $item->get_quantity(),
                'subtotal'            => (float) $item->get_subtotal(),
                'total'               => (float) $item->get_total(),
            ];
        }

        return [
            'external_order_id'  => $order->get_id(),
            'order_number'       => $order->get_order_number(),
            'customer_id'        => $order->get_customer_id(),
            'status'             => $order->get_status(),
            'currency'           => $order->get_currency(),
            'total'              => (float) $order->get_total(),
            'subtotal'           => (float) $order->get_subtotal(),
            'tax_total'          => (float) $order->get_total_tax(),
            'shipping_total'     => (float) $order->get_shipping_total(),
            'discount_total'     => (float) $order->get_total_discount(),
            'refund_total'       => (float) $order->get_total_refunded(),
            'payment_method'     => $order->get_payment_method_title(),
            'created_at_store'   => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'completed_at_store' => $order->get_date_completed() ? $order->get_date_completed()->date('c') : null,
            'items'              => $items,
        ];
    }

    public function map_product($p): array
    {
        $sale = $p->get_sale_price();
        return [
            'external_product_id' => $p->get_id(),
            'name'                => $p->get_name(),
            'sku'                 => $p->get_sku(),
            'price'               => $p->get_price() !== '' ? (float) $p->get_price() : 0,
            'regular_price'       => $p->get_regular_price() !== '' ? (float) $p->get_regular_price() : 0,
            'sale_price'          => ($sale !== '' && $sale !== null) ? (float) $sale : null,
            'stock_quantity'      => $p->get_stock_quantity(),
            'stock_status'        => $p->get_stock_status(),
            'total_sales'         => (int) $p->get_total_sales(),
            'status'              => $p->get_status(),
        ];
    }

    public function map_customer($user): array
    {
        $total_spent = function_exists('wc_get_customer_total_spent') ? (float) wc_get_customer_total_spent($user->ID) : 0;
        $order_count = function_exists('wc_get_customer_order_count') ? (int) wc_get_customer_order_count($user->ID) : 0;

        return [
            'external_customer_id' => $user->ID,
            // Privacy: hash the email; raw email never leaves the store.
            'email_hash'           => hash('sha256', strtolower(trim($user->user_email))),
            'first_name'           => get_user_meta($user->ID, 'first_name', true) ?: null,
            'last_name'            => get_user_meta($user->ID, 'last_name', true) ?: null,
            'city'                 => get_user_meta($user->ID, 'billing_city', true) ?: null,
            'country'              => get_user_meta($user->ID, 'billing_country', true) ?: null,
            'total_spent'          => $total_spent,
            'order_count'          => $order_count,
        ];
    }

    private function site_health_payload(): array
    {
        global $wp_version;
        return [
            'wp_version'     => $wp_version,
            'wc_version'     => defined('WC_VERSION') ? WC_VERSION : null,
            'plugin_version' => INSIGHTISTIC_VERSION,
            'timezone'       => wp_timezone_string(),
            'currency'       => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
        ];
    }
}
