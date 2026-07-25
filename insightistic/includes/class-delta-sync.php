<?php

namespace Insightistic\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Daily incremental (delta) sync — the cheap counterpart to Sync's weekly
 * full reconciliation. Each entity pages through records modified since its
 * stored cursor (Settings cursor_orders / cursor_products / cursor_content).
 *
 * Checkpoint semantics: the `since` value is fixed for the whole chain run
 * (passed through the action args so page N always means page N of the same
 * result set), while the stored cursor is advanced to the newest
 * date_modified of a page ONLY after that page's POST succeeded. If a page
 * fails and exhausts its retries, the cursor stays at the last confirmed
 * checkpoint and the next daily run re-covers the gap.
 *
 * Chain mirrors Sync: orders(page 1..N) -> products(page 1..N) -> content -> done.
 * Customers are deliberately excluded — they only sync on the weekly full pass.
 *
 * An empty cursor means no complete full pass has finished yet; that entity
 * is skipped rather than falling back to a full crawl — the daily job must
 * NEVER run a full historical sync.
 */
class DeltaSync
{
    private static ?self $instance = null;

    const ORDERS_PER   = 50;
    const PRODUCTS_PER = 50;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void
    {
        add_action('insightistic_core_run_delta_sync', [$this, 'start_delta_sync'], 10, 0);
        add_action('insightistic_core_delta_orders', [$this, 'delta_orders'], 10, 3);
        add_action('insightistic_core_delta_products', [$this, 'delta_products'], 10, 3);
        add_action('insightistic_core_delta_content', [$this, 'delta_content'], 10, 3);
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

    /** Entry point for the daily recurring action. */
    public function start_delta_sync(): void
    {
        if (! Settings::is_connected()) {
            Settings::log('Delta sync skipped: license not connected.', 'warning');
            return;
        }

        // One uuid per run, echoed as sync_batch_id in every bulk payload.
        Settings::update(['current_batch_id' => wp_generate_uuid4()]);
        Settings::log('Delta sync started.', 'info');

        $this->advance_to_orders();
    }

    private function advance_to_orders(): void
    {
        $cursor = (string) Settings::get('cursor_orders');
        if (Settings::get('sync_orders') && $this->woo_active() && $cursor !== '') {
            $this->enqueue('insightistic_core_delta_orders', ['page' => 1, 'since' => $cursor, 'attempt' => 0]);
        } else {
            $this->advance_to_products();
        }
    }

    private function advance_to_products(): void
    {
        $cursor = (string) Settings::get('cursor_products');
        if (Settings::get('sync_products') && $this->woo_active() && $cursor !== '') {
            $this->enqueue('insightistic_core_delta_products', ['page' => 1, 'since' => $cursor, 'attempt' => 0]);
        } else {
            $this->advance_to_content();
        }
    }

    private function advance_to_content(): void
    {
        $cursor = (string) Settings::get('cursor_content');
        if (Settings::get('sync_content') && $cursor !== '') {
            $this->enqueue('insightistic_core_delta_content', ['page' => 1, 'since' => $cursor, 'attempt' => 0]);
        } else {
            $this->finish();
        }
    }

    private function finish(): void
    {
        Settings::update(['last_sync' => current_time('mysql'), 'current_batch_id' => '']);
        Settings::log('Delta sync complete.', 'info');
    }

    public function delta_orders(int $page = 1, string $since = '', int $attempt = 0): void
    {
        if (! $this->woo_active() || $since === '') {
            $this->advance_to_products();
            return;
        }

        $orders = wc_get_orders([
            'date_modified' => '>' . $since,
            'orderby'       => 'modified',
            'order'         => 'ASC',
            'limit'         => self::ORDERS_PER,
            'page'          => $page,
            'return'        => 'objects',
        ]);

        if (empty($orders)) {
            $this->advance_to_products();
            return;
        }

        $res = Client::request('POST', '/api/connector/v1/orders/bulk', [
            'orders'        => array_map([Sync::instance(), 'map_order'], $orders),
            'sync_batch_id' => (string) Settings::get('current_batch_id'),
        ]);

        if (! $res['ok'] && $attempt < Sync::MAX_RETRIES) {
            Settings::log(sprintf('Delta orders page %d failed (attempt %d/%d): %s — retrying with backoff.', $page, $attempt + 1, Sync::MAX_RETRIES + 1, $res['error']), 'warning');
            Sync::schedule_retry('insightistic_core_delta_orders', ['page' => $page, 'since' => $since, 'attempt' => $attempt + 1], $attempt);
            return; // Cursor NOT advanced — checkpoint holds.
        }

        if (! $res['ok']) {
            Settings::log(sprintf('Delta orders page %d failed after %d attempts: %s — skipping page (cursor not advanced).', $page, $attempt + 1, $res['error']), 'error');
        } else {
            Settings::log(sprintf('Delta orders page %d: ok (%d sent)', $page, count($orders)), 'info');

            // Checkpoint: newest date_modified confirmed received by the server.
            $max = '';
            foreach ($orders as $order) {
                $mod = $order->get_date_modified();
                $val = $mod ? $mod->date('Y-m-d H:i:s') : '';
                if ($val > $max) {
                    $max = $val;
                }
            }
            if ($max !== '') {
                Settings::update(['cursor_orders' => $max]);
            }
        }

        if (count($orders) < self::ORDERS_PER) {
            $this->advance_to_products();
        } else {
            $this->enqueue('insightistic_core_delta_orders', ['page' => $page + 1, 'since' => $since, 'attempt' => 0]);
        }
    }

    public function delta_products(int $page = 1, string $since = '', int $attempt = 0): void
    {
        if (! $this->woo_active() || $since === '') {
            $this->advance_to_content();
            return;
        }

        $products = wc_get_products([
            'date_modified' => '>' . $since,
            'orderby'       => 'modified',
            'order'         => 'ASC',
            'limit'         => self::PRODUCTS_PER,
            'page'          => $page,
            'return'        => 'objects',
        ]);

        if (empty($products)) {
            $this->advance_to_content();
            return;
        }

        $res = Client::request('POST', '/api/connector/v1/products/bulk', [
            'products'      => array_map([Sync::instance(), 'map_product'], $products),
            'sync_batch_id' => (string) Settings::get('current_batch_id'),
        ]);

        if (! $res['ok'] && $attempt < Sync::MAX_RETRIES) {
            Settings::log(sprintf('Delta products page %d failed (attempt %d/%d): %s — retrying with backoff.', $page, $attempt + 1, Sync::MAX_RETRIES + 1, $res['error']), 'warning');
            Sync::schedule_retry('insightistic_core_delta_products', ['page' => $page, 'since' => $since, 'attempt' => $attempt + 1], $attempt);
            return; // Cursor NOT advanced — checkpoint holds.
        }

        if (! $res['ok']) {
            Settings::log(sprintf('Delta products page %d failed after %d attempts: %s — skipping page (cursor not advanced).', $page, $attempt + 1, $res['error']), 'error');
        } else {
            Settings::log(sprintf('Delta products page %d: ok (%d sent)', $page, count($products)), 'info');

            $max = '';
            foreach ($products as $product) {
                $mod = $product->get_date_modified();
                $val = $mod ? $mod->date('Y-m-d H:i:s') : '';
                if ($val > $max) {
                    $max = $val;
                }
            }
            if ($max !== '') {
                Settings::update(['cursor_products' => $max]);
            }
        }

        if (count($products) < self::PRODUCTS_PER) {
            $this->advance_to_content();
        } else {
            $this->enqueue('insightistic_core_delta_products', ['page' => $page + 1, 'since' => $since, 'attempt' => 0]);
        }
    }

    /**
     * Daily content delta: only posts modified after cursor_content, in
     * ContentSync-sized batches. Query/mapping/POST live in ContentSync so
     * the wire format has a single owner.
     */
    public function delta_content(int $page = 1, string $since = '', int $attempt = 0): void
    {
        if (! Settings::get('sync_content') || ! Settings::is_connected() || $since === '') {
            $this->finish();
            return;
        }

        $content = ContentSync::instance();
        $posts   = $content->query_page($page, $since);

        if (empty($posts)) {
            $this->finish();
            return;
        }

        $ok    = true;
        $items = $content->map_posts($posts);
        if (! empty($items)) {
            $res = $content->post_items($items, (string) Settings::get('current_batch_id'), sprintf('Delta content page %d', $page));

            if (! $res['ok'] && $attempt < Sync::MAX_RETRIES) {
                Settings::log(sprintf('Delta content page %d failed (attempt %d/%d): %s — retrying with backoff.', $page, $attempt + 1, Sync::MAX_RETRIES + 1, $res['error']), 'warning');
                Sync::schedule_retry('insightistic_core_delta_content', ['page' => $page, 'since' => $since, 'attempt' => $attempt + 1], $attempt);
                return; // Cursor NOT advanced — checkpoint holds.
            }

            if (! $res['ok']) {
                Settings::log(sprintf('Delta content page %d failed after %d attempts: %s — skipping page (cursor not advanced).', $page, $attempt + 1, $res['error']), 'error');
                $ok = false;
            }
        }

        if ($ok) {
            $max = '';
            foreach ($posts as $post) {
                if ($post->post_modified > $max) {
                    $max = $post->post_modified;
                }
            }
            if ($max !== '') {
                Settings::update(['cursor_content' => $max]);
            }
        }

        if (count($posts) < ContentSync::CONTENT_PER) {
            $this->finish();
        } else {
            $this->enqueue('insightistic_core_delta_content', ['page' => $page + 1, 'since' => $since, 'attempt' => 0]);
        }
    }
}
