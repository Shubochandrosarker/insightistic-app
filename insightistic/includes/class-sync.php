<?php

namespace Insightistic\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Drives the chunked sync to the SaaS, using the connector credentials issued
 * by License::activate(). Wire format matches the standalone "Insightistic
 * Connector" plugin's /api/connector/v1/* endpoints exactly — same server,
 * same HMAC scheme, same payload shape.
 *
 * Linear chain (each step schedules the next, so we never run a huge job in one
 * request and never need cross-job coordination):
 *   orders(page 1..N) -> products(page 1..N) -> customers(page 1..N)
 *     -> content(page 1..N, opt-in, see ContentSync) -> sync-complete
 *
 * Since 1.1.0 this full pass is the WEEKLY reconciliation — the daily cadence
 * runs DeltaSync (modified-since cursors) instead, so a full historical crawl
 * never happens more than once a week. Every bulk payload carries the
 * sync_batch_id generated in start_full_sync(). A failed page POST is retried
 * with backoff (RETRY_DELAYS, up to MAX_RETRIES) and then skipped, so a dead
 * endpoint can never wedge the chain.
 *
 * Chunk sizes follow the spec: 50 orders, 100 products, 100 customers per request.
 */
class Sync
{
    private static ?self $instance = null;

    const ORDERS_PER    = 50;
    const PRODUCTS_PER  = 100;
    const CUSTOMERS_PER = 100;

    /** Backoff for a failed page POST: 1 min, 5 min, 15 min. */
    const RETRY_DELAYS = [60, 300, 900];

    /** Retries per page after the initial attempt; then log + advance. */
    const MAX_RETRIES = 3;

    /**
     * Every Action Scheduler hook this plugin owns — the single source of
     * truth for the unschedule lists (plugin deactivation in insightistic.php
     * and license deactivation in Admin::handle_deactivate()).
     */
    const ACTION_HOOKS = [
        'insightistic_core_run_sync',
        'insightistic_core_run_delta_sync',
        'insightistic_core_sync_orders',
        'insightistic_core_sync_products',
        'insightistic_core_sync_customers',
        'insightistic_core_sync_content',
        'insightistic_core_delta_orders',
        'insightistic_core_delta_products',
        'insightistic_core_delta_content',
        'insightistic_core_flush_events',
        'insightistic_core_validate_license',
    ];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void
    {
        add_action('insightistic_core_sync_orders', [$this, 'sync_orders'], 10, 4);
        add_action('insightistic_core_sync_products', [$this, 'sync_products'], 10, 2);
        add_action('insightistic_core_sync_customers', [$this, 'sync_customers'], 10, 2);
        add_action('insightistic_core_run_sync', [$this, 'start_full_sync'], 10, 0);
        add_action('insightistic_core_validate_license', [License::class, 'validate'], 10, 0);
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

    /**
     * Re-enqueue a failed page with backoff. Shared by Sync, DeltaSync and
     * ContentSync so all three chains retry identically. Args must be in the
     * exact positional order of the hook's callback signature (Action
     * Scheduler passes values positionally). Falls back to an immediate
     * inline retry when Action Scheduler is unavailable — attempts are
     * capped by MAX_RETRIES either way, so this cannot recurse forever.
     */
    public static function schedule_retry(string $hook, array $args, int $attempt): void
    {
        $delay = self::RETRY_DELAYS[min($attempt, count(self::RETRY_DELAYS) - 1)];
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, $hook, $args, 'insightistic');
        } else {
            do_action($hook, ...array_values($args));
        }
    }

    /**
     * Entry point — kicks the chain. Safe to call from the admin "Sync now"
     * button. An optional Y-m-d date range narrows the ORDERS query to orders
     * created between the two dates (manual resync); products, customers and
     * content are unaffected by the range.
     */
    public function start_full_sync(string $date_from = '', string $date_to = ''): void
    {
        if (! Settings::is_connected()) {
            Settings::log('Sync skipped: license not connected.', 'warning');
            return;
        }

        $ranged = ($date_from !== '' || $date_to !== '');

        // One uuid per run, echoed as sync_batch_id in every bulk payload so
        // server-side upserts are idempotent. A date-ranged resync is partial,
        // so it must NOT fast-forward the delta cursors when it completes —
        // only a complete pass records its start time (see finish()).
        Settings::update([
            'current_batch_id'     => wp_generate_uuid4(),
            'current_sync_started' => $ranged ? '' : current_time('mysql'),
        ]);

        if ($ranged) {
            Settings::log(sprintf(
                'Manual resync started (orders created %s to %s).',
                $date_from !== '' ? $date_from : 'beginning',
                $date_to !== '' ? $date_to : 'now'
            ), 'info');
        }

        // Refresh site health first (cheap, confirms link is healthy).
        if (Settings::get('sync_site_health')) {
            Client::request('POST', '/api/connector/v1/site-health', $this->site_health_payload());
        }

        if (Settings::get('sync_orders') && $this->woo_active()) {
            $this->enqueue('insightistic_core_sync_orders', ['page' => 1, 'attempt' => 0, 'from' => $date_from, 'to' => $date_to]);
        } elseif (Settings::get('sync_products') && $this->woo_active()) {
            $this->enqueue('insightistic_core_sync_products', ['page' => 1, 'attempt' => 0]);
        } elseif (Settings::get('sync_customers') && $this->woo_active()) {
            $this->enqueue('insightistic_core_sync_customers', ['page' => 1, 'attempt' => 0]);
        } elseif (Settings::get('sync_content')) {
            $this->enqueue('insightistic_core_sync_content', ['page' => 1, 'attempt' => 0]);
        } else {
            $this->finish();
        }
    }

    public function sync_orders(int $page = 1, int $attempt = 0, string $from = '', string $to = ''): void
    {
        if (! $this->woo_active()) {
            $this->advance_after_orders();
            return;
        }

        $args = [
            'limit'   => self::ORDERS_PER,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'ASC',
            'return'  => 'objects',
        ];

        // Optional manual-resync range (orders only).
        if ($from !== '' && $to !== '') {
            $args['date_created'] = $from . '...' . $to;
        } elseif ($from !== '') {
            $args['date_created'] = '>=' . $from;
        } elseif ($to !== '') {
            $args['date_created'] = '<=' . $to;
        }

        $orders = wc_get_orders($args);

        if (empty($orders)) {
            $this->advance_after_orders();
            return;
        }

        $payload = [
            'orders'        => array_map([$this, 'map_order'], $orders),
            'sync_batch_id' => (string) Settings::get('current_batch_id'),
        ];
        $res = Client::request('POST', '/api/connector/v1/orders/bulk', $payload);

        if (! $res['ok'] && $attempt < self::MAX_RETRIES) {
            Settings::log(sprintf('Orders page %d failed (attempt %d/%d): %s — retrying with backoff.', $page, $attempt + 1, self::MAX_RETRIES + 1, $res['error']), 'warning');
            self::schedule_retry('insightistic_core_sync_orders', ['page' => $page, 'attempt' => $attempt + 1, 'from' => $from, 'to' => $to], $attempt);
            return; // Same page retries later — do not advance.
        }

        if (! $res['ok']) {
            Settings::log(sprintf('Orders page %d failed after %d attempts: %s — skipping page so the chain can continue.', $page, $attempt + 1, $res['error']), 'error');
        } else {
            Settings::log(sprintf('Orders page %d: ok (%d sent)', $page, count($orders)), 'info');
        }

        if (count($orders) < self::ORDERS_PER) {
            $this->advance_after_orders();
        } else {
            $this->enqueue('insightistic_core_sync_orders', ['page' => $page + 1, 'attempt' => 0, 'from' => $from, 'to' => $to]);
        }
    }

    public function sync_products(int $page = 1, int $attempt = 0): void
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

        $payload = [
            'products'      => array_map([$this, 'map_product'], $products),
            'sync_batch_id' => (string) Settings::get('current_batch_id'),
        ];
        $res = Client::request('POST', '/api/connector/v1/products/bulk', $payload);

        if (! $res['ok'] && $attempt < self::MAX_RETRIES) {
            Settings::log(sprintf('Products page %d failed (attempt %d/%d): %s — retrying with backoff.', $page, $attempt + 1, self::MAX_RETRIES + 1, $res['error']), 'warning');
            self::schedule_retry('insightistic_core_sync_products', ['page' => $page, 'attempt' => $attempt + 1], $attempt);
            return;
        }

        if (! $res['ok']) {
            Settings::log(sprintf('Products page %d failed after %d attempts: %s — skipping page so the chain can continue.', $page, $attempt + 1, $res['error']), 'error');
        } else {
            Settings::log(sprintf('Products page %d: ok (%d sent)', $page, count($products)), 'info');
        }

        if (count($products) < self::PRODUCTS_PER) {
            $this->advance_after_products();
        } else {
            $this->enqueue('insightistic_core_sync_products', ['page' => $page + 1, 'attempt' => 0]);
        }
    }

    public function sync_customers(int $page = 1, int $attempt = 0): void
    {
        $users = get_users([
            'role'    => 'customer',
            'number'  => self::CUSTOMERS_PER,
            'paged'   => $page,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        if (empty($users)) {
            $this->advance_after_customers();
            return;
        }

        $payload = [
            'customers'     => array_map([$this, 'map_customer'], $users),
            'sync_batch_id' => (string) Settings::get('current_batch_id'),
        ];
        $res = Client::request('POST', '/api/connector/v1/customers/bulk', $payload);

        if (! $res['ok'] && $attempt < self::MAX_RETRIES) {
            Settings::log(sprintf('Customers page %d failed (attempt %d/%d): %s — retrying with backoff.', $page, $attempt + 1, self::MAX_RETRIES + 1, $res['error']), 'warning');
            self::schedule_retry('insightistic_core_sync_customers', ['page' => $page, 'attempt' => $attempt + 1], $attempt);
            return;
        }

        if (! $res['ok']) {
            Settings::log(sprintf('Customers page %d failed after %d attempts: %s — skipping page so the chain can continue.', $page, $attempt + 1, $res['error']), 'error');
        } else {
            Settings::log(sprintf('Customers page %d: ok (%d sent)', $page, count($users)), 'info');
        }

        if (count($users) < self::CUSTOMERS_PER) {
            $this->advance_after_customers();
        } else {
            $this->enqueue('insightistic_core_sync_customers', ['page' => $page + 1, 'attempt' => 0]);
        }
    }

    private function advance_after_orders(): void
    {
        if (Settings::get('sync_products') && $this->woo_active()) {
            $this->enqueue('insightistic_core_sync_products', ['page' => 1, 'attempt' => 0]);
        } else {
            $this->advance_after_products();
        }
    }

    private function advance_after_products(): void
    {
        if (Settings::get('sync_customers')) {
            $this->enqueue('insightistic_core_sync_customers', ['page' => 1, 'attempt' => 0]);
        } else {
            $this->advance_after_customers();
        }
    }

    private function advance_after_customers(): void
    {
        // Opt-in AI Knowledge Base content rides the weekly full pass too;
        // ContentSync calls back into finish() when its pages are done.
        if (Settings::get('sync_content')) {
            $this->enqueue('insightistic_core_sync_content', ['page' => 1, 'attempt' => 0]);
        } else {
            $this->finish();
        }
    }

    /** Public because ContentSync ends the full-pass chain by calling it. */
    public function finish(): void
    {
        $res = Client::request('POST', '/api/connector/v1/sync-complete', []);
        if ($res['ok']) {
            $updates = ['last_sync' => current_time('mysql'), 'current_batch_id' => ''];

            // A complete (non date-ranged) pass fast-forwards the delta
            // cursors to its own start time: everything modified before then
            // was just re-sent, and anything modified during the pass is
            // still newer than the start time, so the next delta catches it.
            $started = (string) Settings::get('current_sync_started');
            if ($started !== '') {
                $updates['cursor_orders']        = $started;
                $updates['cursor_products']      = $started;
                $updates['cursor_content']       = $started;
                $updates['current_sync_started'] = '';
            }

            Settings::update($updates);
            Settings::log('Full sync complete.', 'info');
        } else {
            Settings::log('sync-complete failed: ' . $res['error'], 'error');
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
            'plugin_version' => INSIGHTISTIC_CORE_VERSION,
            'timezone'       => wp_timezone_string(),
            'currency'       => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
        ];
    }
}
