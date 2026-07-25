<?php

namespace Insightistic\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in AI Knowledge Base content sharing (Settings `sync_content`,
 * default OFF — privacy-first). Posts batches of up to CONTENT_PER published
 * posts/pages (+ products when WooCommerce is active) to
 * POST /api/connector/v1/content/bulk as
 *   { items: [{external_id, type, title, url, content, modified_at}], sync_batch_id }.
 *
 * Only publicly visible content is ever sent: non-published and
 * password-protected posts are excluded by the query AND re-checked in
 * map_posts(), and any post carrying truthy `_insightistic_exclude` meta is
 * skipped. content is the rendered post body, tags stripped, truncated to
 * MAX_CHARS characters.
 *
 * Runs two ways:
 *  - weekly full pass: chunked chain hook `insightistic_core_sync_content`,
 *    appended to Sync's chain after customers; ends by calling Sync::finish().
 *  - daily delta: DeltaSync::delta_content() reuses query_page()/map_posts()/
 *    post_items() with a modified-after cursor.
 *
 * Deletions (trash or hard delete of an eligible post type) send a single
 * tombstone item {external_id, type, title:'(deleted)', content:'-',
 * deleted:true} through the Events async queue.
 */
class ContentSync
{
    private static ?self $instance = null;

    const CONTENT_PER = 20;
    const MAX_CHARS   = 100000;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void
    {
        add_action('insightistic_core_sync_content', [$this, 'sync_content'], 10, 2);

        // Deletion tombstones — captured at hook time, flushed by Events.
        add_action('trashed_post', [$this, 'on_post_removed'], 10, 1);
        add_action('before_delete_post', [$this, 'on_post_removed'], 10, 1);
    }

    public function enabled(): bool
    {
        return Settings::is_connected() && (bool) Settings::get('sync_content');
    }

    /** Eligible post types: posts + pages, plus products when Woo is active. */
    public function post_types(): array
    {
        $types = ['post', 'page'];
        if (class_exists('WooCommerce')) {
            $types[] = 'product';
        }
        return $types;
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

    /** Full-pass chunk (weekly reconciliation chain, after customers). */
    public function sync_content(int $page = 1, int $attempt = 0): void
    {
        if (! $this->enabled()) {
            Sync::instance()->finish();
            return;
        }

        $posts = $this->query_page($page, '');

        if (empty($posts)) {
            Sync::instance()->finish();
            return;
        }

        $items = $this->map_posts($posts);
        if (! empty($items)) {
            $res = $this->post_items($items, (string) Settings::get('current_batch_id'), sprintf('Content page %d', $page));

            if (! $res['ok'] && $attempt < Sync::MAX_RETRIES) {
                Settings::log(sprintf('Content page %d failed (attempt %d/%d): %s — retrying with backoff.', $page, $attempt + 1, Sync::MAX_RETRIES + 1, $res['error']), 'warning');
                Sync::schedule_retry('insightistic_core_sync_content', ['page' => $page, 'attempt' => $attempt + 1], $attempt);
                return; // Same page retries later — do not advance.
            }

            if (! $res['ok']) {
                Settings::log(sprintf('Content page %d failed after %d attempts: %s — skipping page so the chain can continue.', $page, $attempt + 1, $res['error']), 'error');
            }
        }

        // Short-page termination uses the RAW post count (a fully-excluded
        // page must still advance to the next page, not end the chain).
        if (count($posts) < self::CONTENT_PER) {
            Sync::instance()->finish();
        } else {
            $this->enqueue('insightistic_core_sync_content', ['page' => $page + 1, 'attempt' => 0]);
        }
    }

    /**
     * One page of eligible published posts. With $modified_after set (delta),
     * only posts whose post_modified is strictly after it, ordered by
     * modified ASC so DeltaSync can checkpoint the cursor per page.
     *
     * @return \WP_Post[]
     */
    public function query_page(int $page, string $modified_after = ''): array
    {
        $args = [
            'post_type'      => $this->post_types(),
            'post_status'    => 'publish',
            'has_password'   => false,
            'posts_per_page' => self::CONTENT_PER,
            'paged'          => $page,
            'orderby'        => $modified_after !== '' ? 'modified' : 'ID',
            'order'          => 'ASC',
        ];

        if ($modified_after !== '') {
            $args['date_query'] = [
                [
                    'column' => 'post_modified',
                    'after'  => $modified_after,
                ],
            ];
        }

        return get_posts($args);
    }

    /**
     * Filters exclusions (meta `_insightistic_exclude`, password, non-publish)
     * and maps the rest to the content/bulk wire format.
     */
    public function map_posts(array $posts): array
    {
        $items = [];
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            if ($post->post_status !== 'publish' || $post->post_password !== '') {
                continue;
            }
            if (get_post_meta($post->ID, '_insightistic_exclude', true)) {
                continue;
            }
            $items[] = $this->map_post($post);
        }
        return $items;
    }

    private function map_post(\WP_Post $post): array
    {
        $content = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
        $content = mb_substr($content, 0, self::MAX_CHARS); // WP polyfills mb_substr.

        return [
            'external_id' => $post->ID,
            'type'        => $post->post_type,
            'title'       => get_the_title($post),
            'url'         => get_permalink($post),
            'content'     => $content,
            'modified_at' => get_post_modified_time('c', true, $post),
        ];
    }

    /**
     * POST one batch to /api/connector/v1/content/bulk. The server replies
     * with accepted / skipped_over_limit (plan quota) — both are logged
     * honestly. Failures are returned to the caller, which owns retries.
     *
     * @return array{ok:bool,status:int,data:mixed,error:string|null}
     */
    public function post_items(array $items, string $batch_id, string $context): array
    {
        $res = Client::request('POST', '/api/connector/v1/content/bulk', [
            'items'         => array_values($items),
            'sync_batch_id' => $batch_id,
        ]);

        if ($res['ok']) {
            $accepted = isset($res['data']['accepted']) ? (int) $res['data']['accepted'] : count($items);
            $skipped  = isset($res['data']['skipped_over_limit']) ? (int) $res['data']['skipped_over_limit'] : 0;
            Settings::log(
                sprintf('%s: ok (%d accepted, %d skipped over plan limit)', $context, $accepted, $skipped),
                $skipped > 0 ? 'warning' : 'info'
            );
        }

        return $res;
    }

    /**
     * trashed_post / before_delete_post: queue a deletion tombstone for a
     * previously eligible post type, but only while connected AND the
     * knowledge-base toggle is on. Deduped by post id in the Events queue,
     * so trash followed by hard delete still sends one event.
     */
    public function on_post_removed($post_id): void
    {
        if (! $this->enabled()) {
            return;
        }

        $post_id = (int) $post_id;
        $post    = get_post($post_id);
        if (! $post || ! in_array($post->post_type, $this->post_types(), true)) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        Events::instance()->queue_content_delete($post_id, [
            'external_id' => $post_id,
            'type'        => $post->post_type,
            'title'       => '(deleted)',
            'content'     => '-',
            'deleted'     => true,
        ]);
    }
}
