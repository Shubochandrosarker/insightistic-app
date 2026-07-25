<?php

namespace Insightistic\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Realtime event bridge. WooCommerce CRUD hooks collect changed ids into
 * small deduped pending queues (one non-autoloaded option per entity), and a
 * single debounced Action Scheduler job (`insightistic_core_flush_events`,
 * ~60s out) flushes them through the same /api/connector/v1/*\/bulk endpoints
 * the chunked sync uses — reusing Sync's public map_order()/map_product().
 * Content deletions queued by ContentSync ride the same flush.
 *
 * Queues are only fed while connected AND the matching sync_* toggle is on.
 * A flush sends at most one batch per entity and re-schedules itself while a
 * backlog remains, so a burst of updates (bulk edit, importer) never floods
 * the API; a failed flush keeps its ids queued and retries on the next run.
 */
class Events
{
    private static ?self $instance = null;

    const PENDING_ORDERS_OPTION   = 'insightistic_core_pending_orders';
    const PENDING_PRODUCTS_OPTION = 'insightistic_core_pending_products';
    const PENDING_CONTENT_OPTION  = 'insightistic_core_pending_content_deletes';

    const FLUSH_HOOK     = 'insightistic_core_flush_events';
    const FLUSH_DELAY    = 60;
    const ORDERS_BATCH   = 50;
    const PRODUCTS_BATCH = 50;
    const CONTENT_BATCH  = 20;

    /** sync_batch_id for the current flush run (see flush_events()). */
    private string $flush_batch_id = '';

    /** Re-entrancy guard for the inline no-Action-Scheduler fallback. */
    private bool $flushing = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void
    {
        add_action(self::FLUSH_HOOK, [$this, 'flush_events'], 10, 0);

        // The Woo CRUD hooks only exist when WooCommerce is active; this
        // runs on plugins_loaded, so the class check is reliable.
        if (! class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_new_order', [$this, 'queue_order'], 10, 1);
        add_action('woocommerce_update_order', [$this, 'queue_order'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'queue_order'], 10, 1);
        add_action('woocommerce_order_refunded', [$this, 'queue_order'], 10, 1);

        add_action('woocommerce_new_product', [$this, 'queue_product'], 10, 1);
        add_action('woocommerce_update_product', [$this, 'queue_product'], 10, 1);
        add_action('woocommerce_product_set_stock', [$this, 'queue_product_from_object'], 10, 1);
    }

    // --- collectors (cheap: one deduped option write, no HTTP) --------------

    public function queue_order($order_id): void
    {
        if (! Settings::is_connected() || ! Settings::get('sync_orders')) {
            return;
        }
        $this->add_pending(self::PENDING_ORDERS_OPTION, (int) $order_id, time());
        $this->schedule_flush();
    }

    public function queue_product($product_id): void
    {
        if (! Settings::is_connected() || ! Settings::get('sync_products')) {
            return;
        }
        $this->add_pending(self::PENDING_PRODUCTS_OPTION, (int) $product_id, time());
        $this->schedule_flush();
    }

    /** woocommerce_product_set_stock passes the product object, not an id. */
    public function queue_product_from_object($product): void
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $this->queue_product($product->get_id());
        }
    }

    /**
     * Called by ContentSync when an eligible post is trashed/deleted. The
     * item is captured at hook time (the post is gone by flush time) and
     * deduped by post id, so trashed_post + before_delete_post firing for
     * the same post produce a single deletion event.
     */
    public function queue_content_delete(int $post_id, array $item): void
    {
        $this->add_pending(self::PENDING_CONTENT_OPTION, $post_id, $item);
        $this->schedule_flush();
    }

    // --- flush ---------------------------------------------------------------

    public function flush_events(): void
    {
        if ($this->flushing || ! Settings::is_connected()) {
            return;
        }
        $this->flushing = true;

        // Every bulk payload carries a sync_batch_id. Events fire between
        // chained syncs, so reuse a running sync's id, else mint one per flush.
        $this->flush_batch_id = (string) Settings::get('current_batch_id') ?: wp_generate_uuid4();

        $backlog = $this->flush_orders();
        $backlog = $this->flush_products() || $backlog;
        $backlog = $this->flush_content_deletes() || $backlog;

        if ($backlog) {
            // No-op in the inline fallback ($flushing still true) — attempts
            // resume with the next incoming event instead of recursing.
            $this->schedule_flush();
        }

        $this->flushing = false;
    }

    /** @return bool true while a backlog remains for this entity. */
    private function flush_orders(): bool
    {
        $pending = $this->pending(self::PENDING_ORDERS_OPTION);
        if (empty($pending)) {
            return false;
        }
        if (! Settings::get('sync_orders') || ! class_exists('WooCommerce')) {
            update_option(self::PENDING_ORDERS_OPTION, [], false); // Toggle went off — drop the backlog.
            return false;
        }

        $ids    = array_slice(array_keys($pending), 0, self::ORDERS_BATCH);
        $orders = [];
        foreach ($ids as $id) {
            $order = wc_get_order((int) $id);
            if ($order && is_a($order, 'WC_Order')) { // Skips deleted orders and refund objects.
                $orders[] = Sync::instance()->map_order($order);
            }
        }

        if (! empty($orders)) {
            $res = Client::request('POST', '/api/connector/v1/orders/bulk', [
                'orders'        => $orders,
                'sync_batch_id' => $this->flush_batch_id,
            ]);
            if (! $res['ok']) {
                Settings::log('Realtime order flush failed (will retry): ' . $res['error'], 'error');
                return true; // Keep ids queued for the next flush.
            }
            Settings::log(sprintf('Realtime: %d order update(s) sent.', count($orders)), 'info');
        }

        $remaining = array_diff_key($this->pending(self::PENDING_ORDERS_OPTION), array_flip($ids));
        update_option(self::PENDING_ORDERS_OPTION, $remaining, false);

        return ! empty($remaining);
    }

    /** @return bool true while a backlog remains for this entity. */
    private function flush_products(): bool
    {
        $pending = $this->pending(self::PENDING_PRODUCTS_OPTION);
        if (empty($pending)) {
            return false;
        }
        if (! Settings::get('sync_products') || ! class_exists('WooCommerce')) {
            update_option(self::PENDING_PRODUCTS_OPTION, [], false);
            return false;
        }

        $ids      = array_slice(array_keys($pending), 0, self::PRODUCTS_BATCH);
        $products = [];
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if ($product && is_a($product, 'WC_Product')) {
                $products[] = Sync::instance()->map_product($product);
            }
        }

        if (! empty($products)) {
            $res = Client::request('POST', '/api/connector/v1/products/bulk', [
                'products'      => $products,
                'sync_batch_id' => $this->flush_batch_id,
            ]);
            if (! $res['ok']) {
                Settings::log('Realtime product flush failed (will retry): ' . $res['error'], 'error');
                return true;
            }
            Settings::log(sprintf('Realtime: %d product update(s) sent.', count($products)), 'info');
        }

        $remaining = array_diff_key($this->pending(self::PENDING_PRODUCTS_OPTION), array_flip($ids));
        update_option(self::PENDING_PRODUCTS_OPTION, $remaining, false);

        return ! empty($remaining);
    }

    /** @return bool true while a backlog remains for this entity. */
    private function flush_content_deletes(): bool
    {
        $pending = $this->pending(self::PENDING_CONTENT_OPTION);
        if (empty($pending)) {
            return false;
        }
        if (! Settings::get('sync_content')) {
            update_option(self::PENDING_CONTENT_OPTION, [], false);
            return false;
        }

        $keys  = array_slice(array_keys($pending), 0, self::CONTENT_BATCH);
        $items = array_values(array_intersect_key($pending, array_flip($keys)));

        $res = ContentSync::instance()->post_items($items, $this->flush_batch_id, sprintf('Realtime: %d content deletion(s)', count($items)));
        if (! $res['ok']) {
            Settings::log('Realtime content-delete flush failed (will retry): ' . $res['error'], 'error');
            return true;
        }

        $remaining = array_diff_key($this->pending(self::PENDING_CONTENT_OPTION), array_flip($keys));
        update_option(self::PENDING_CONTENT_OPTION, $remaining, false);

        return ! empty($remaining);
    }

    // --- plumbing ------------------------------------------------------------

    private function pending(string $option): array
    {
        $value = get_option($option, []);
        return is_array($value) ? $value : [];
    }

    private function add_pending(string $option, int $key, $value): void
    {
        $pending       = $this->pending($option);
        $pending[$key] = $value;
        update_option($option, $pending, false);
    }

    /**
     * Debounce: one flush action ~60s out no matter how many events arrive.
     * Falls back to an immediate inline flush when Action Scheduler is
     * unavailable (guarded against re-entry by $flushing).
     */
    private function schedule_flush(): void
    {
        if (function_exists('as_schedule_single_action')) {
            if (! as_next_scheduled_action(self::FLUSH_HOOK)) {
                as_schedule_single_action(time() + self::FLUSH_DELAY, self::FLUSH_HOOK, [], 'insightistic');
            }
        } elseif (! $this->flushing) {
            $this->flush_events();
        }
    }
}
