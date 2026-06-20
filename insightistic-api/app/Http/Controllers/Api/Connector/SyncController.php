<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Jobs\RebuildSiteSnapshots;
use App\Models\Site;
use App\Models\SyncLog;
use App\Models\WcCustomer;
use App\Models\WcOrder;
use App\Models\WcOrderItem;
use App\Models\WcProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    /** Receive environment / health info and refresh site metadata. */
    public function siteHealth(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'wp_version'     => ['nullable', 'string', 'max:32'],
            'wc_version'     => ['nullable', 'string', 'max:32'],
            'plugin_version' => ['nullable', 'string', 'max:32'],
            'timezone'       => ['nullable', 'string', 'max:64'],
            'currency'       => ['nullable', 'string', 'max:8'],
        ]);

        $site->fill(array_filter($data, fn ($v) => $v !== null))->save();
        $this->log($site, 'site_health', 'success', 0);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Upsert a chunk of orders (+ their line items) keyed on
     * (site_id, external_order_id) so re-syncs never duplicate.
     */
    public function ordersBulk(Request $request)
    {
        $site = $this->site($request);

        $payload = $request->validate([
            'orders'                        => ['required', 'array', 'max:200'],
            'orders.*.external_order_id'    => ['required'],
            'orders.*.order_number'         => ['nullable', 'string'],
            'orders.*.customer_id'          => ['nullable'],
            'orders.*.status'               => ['nullable', 'string'],
            'orders.*.currency'             => ['nullable', 'string', 'max:8'],
            'orders.*.total'                => ['nullable', 'numeric'],
            'orders.*.subtotal'             => ['nullable', 'numeric'],
            'orders.*.tax_total'            => ['nullable', 'numeric'],
            'orders.*.shipping_total'       => ['nullable', 'numeric'],
            'orders.*.discount_total'       => ['nullable', 'numeric'],
            'orders.*.refund_total'         => ['nullable', 'numeric'],
            'orders.*.payment_method'       => ['nullable', 'string'],
            'orders.*.created_at_store'     => ['nullable', 'string'],
            'orders.*.completed_at_store'   => ['nullable', 'string'],
            'orders.*.items'                => ['nullable', 'array'],
        ]);

        $now = now();
        $orderRows = [];
        foreach ($payload['orders'] as $o) {
            $orderRows[] = [
                'site_id'            => $site->id,
                'external_order_id'  => (int) $o['external_order_id'],
                'order_number'       => $o['order_number'] ?? null,
                'customer_id'        => isset($o['customer_id']) ? (int) $o['customer_id'] : null,
                'status'             => $o['status'] ?? null,
                'currency'           => $o['currency'] ?? null,
                'total'              => $o['total'] ?? 0,
                'subtotal'           => $o['subtotal'] ?? 0,
                'tax_total'          => $o['tax_total'] ?? 0,
                'shipping_total'     => $o['shipping_total'] ?? 0,
                'discount_total'     => $o['discount_total'] ?? 0,
                'refund_total'       => $o['refund_total'] ?? 0,
                'payment_method'     => $o['payment_method'] ?? null,
                'created_at_store'   => $this->parseDate($o['created_at_store'] ?? null),
                'completed_at_store' => $this->parseDate($o['completed_at_store'] ?? null),
                'synced_at'          => $now,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        DB::transaction(function () use ($site, $payload, $orderRows, $now) {
            // Bulk upsert orders on the unique (site_id, external_order_id) key.
            WcOrder::upsert(
                $orderRows,
                ['site_id', 'external_order_id'],
                ['order_number', 'customer_id', 'status', 'currency', 'total', 'subtotal',
                 'tax_total', 'shipping_total', 'discount_total', 'refund_total',
                 'payment_method', 'created_at_store', 'completed_at_store', 'synced_at', 'updated_at']
            );

            // Refresh line items: clear + reinsert per order (items have no stable id).
            foreach ($payload['orders'] as $o) {
                $extId = (int) $o['external_order_id'];
                WcOrderItem::where('site_id', $site->id)
                    ->where('external_order_id', $extId)
                    ->delete();

                $items = $o['items'] ?? [];
                if (! $items) {
                    continue;
                }
                $itemRows = [];
                foreach ($items as $it) {
                    $itemRows[] = [
                        'site_id'             => $site->id,
                        'external_order_id'   => $extId,
                        'external_product_id' => isset($it['external_product_id']) ? (int) $it['external_product_id'] : null,
                        'product_name'        => $it['product_name'] ?? null,
                        'sku'                 => $it['sku'] ?? null,
                        'quantity'            => $it['quantity'] ?? 0,
                        'subtotal'            => $it['subtotal'] ?? 0,
                        'total'               => $it['total'] ?? 0,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ];
                }
                WcOrderItem::insert($itemRows);
            }
        });

        $count = count($orderRows);
        $this->log($site, 'orders', 'success', $count);

        return response()->json(['status' => 'ok', 'received' => $count]);
    }

    public function productsBulk(Request $request)
    {
        $site = $this->site($request);

        $payload = $request->validate([
            'products'                       => ['required', 'array', 'max:300'],
            'products.*.external_product_id' => ['required'],
            'products.*.name'                => ['nullable', 'string'],
            'products.*.sku'                 => ['nullable', 'string'],
            'products.*.price'               => ['nullable', 'numeric'],
            'products.*.regular_price'       => ['nullable', 'numeric'],
            'products.*.sale_price'          => ['nullable', 'numeric'],
            'products.*.stock_quantity'      => ['nullable', 'integer'],
            'products.*.stock_status'        => ['nullable', 'string'],
            'products.*.total_sales'         => ['nullable', 'integer'],
            'products.*.status'              => ['nullable', 'string'],
        ]);

        $now = now();
        $rows = array_map(fn ($p) => [
            'site_id'             => $site->id,
            'external_product_id' => (int) $p['external_product_id'],
            'name'                => $p['name'] ?? null,
            'sku'                 => $p['sku'] ?? null,
            'price'               => $p['price'] ?? 0,
            'regular_price'       => $p['regular_price'] ?? 0,
            'sale_price'          => $p['sale_price'] ?? null,
            'stock_quantity'      => $p['stock_quantity'] ?? null,
            'stock_status'        => $p['stock_status'] ?? null,
            'total_sales'         => $p['total_sales'] ?? 0,
            'status'              => $p['status'] ?? null,
            'synced_at'           => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ], $payload['products']);

        WcProduct::upsert(
            $rows,
            ['site_id', 'external_product_id'],
            ['name', 'sku', 'price', 'regular_price', 'sale_price', 'stock_quantity',
             'stock_status', 'total_sales', 'status', 'synced_at', 'updated_at']
        );

        $this->log($site, 'products', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    public function customersBulk(Request $request)
    {
        $site = $this->site($request);

        $payload = $request->validate([
            'customers'                        => ['required', 'array', 'max:300'],
            'customers.*.external_customer_id' => ['required'],
            'customers.*.email_hash'           => ['nullable', 'string', 'max:64'],
            'customers.*.first_name'           => ['nullable', 'string'],
            'customers.*.last_name'            => ['nullable', 'string'],
            'customers.*.city'                 => ['nullable', 'string'],
            'customers.*.country'              => ['nullable', 'string', 'max:4'],
            'customers.*.total_spent'          => ['nullable', 'numeric'],
            'customers.*.order_count'          => ['nullable', 'integer'],
            'customers.*.first_order_at'       => ['nullable', 'string'],
            'customers.*.last_order_at'        => ['nullable', 'string'],
        ]);

        $now = now();
        $rows = array_map(fn ($c) => [
            'site_id'              => $site->id,
            'external_customer_id' => (int) $c['external_customer_id'],
            'email_hash'           => $c['email_hash'] ?? null,
            'first_name'           => $c['first_name'] ?? null,
            'last_name'            => $c['last_name'] ?? null,
            'city'                 => $c['city'] ?? null,
            'country'              => $c['country'] ?? null,
            'total_spent'          => $c['total_spent'] ?? 0,
            'order_count'          => $c['order_count'] ?? 0,
            'first_order_at'       => $this->parseDate($c['first_order_at'] ?? null),
            'last_order_at'        => $this->parseDate($c['last_order_at'] ?? null),
            'synced_at'            => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ], $payload['customers']);

        WcCustomer::upsert(
            $rows,
            ['site_id', 'external_customer_id'],
            ['email_hash', 'first_name', 'last_name', 'city', 'country',
             'total_spent', 'order_count', 'first_order_at', 'last_order_at', 'synced_at', 'updated_at']
        );

        $this->log($site, 'customers', 'success', count($rows));

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    /** Plugin calls this after the last chunk; marks the sync run complete. */
    public function syncComplete(Request $request)
    {
        $site = $this->site($request);
        $site->update(['last_sync_at' => now(), 'connection_status' => 'connected']);
        $this->log($site, 'sync_complete', 'success', 0, 'Full sync finished');

        // Rebuild analytics snapshots from the freshly synced orders. Runs after
        // the HTTP response so it needs no queue worker for first-run UX; switch
        // to ::dispatch() once Horizon is running in production.
        RebuildSiteSnapshots::dispatchAfterResponse($site->id);

        return response()->json(['status' => 'ok', 'last_sync_at' => $site->last_sync_at]);
    }

    // --- helpers -----------------------------------------------------------

    private function site(Request $request): Site
    {
        return $request->attributes->get('connector_site');
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function log(Site $site, string $job, string $status, int $records, ?string $message = null): void
    {
        SyncLog::create([
            'site_id'     => $site->id,
            'job'         => $job,
            'status'      => $status,
            'records'     => $records,
            'message'     => $message,
            'started_at'  => now(),
            'finished_at' => now(),
        ]);
    }
}
