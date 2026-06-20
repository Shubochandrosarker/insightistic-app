<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Site;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\WcCustomer;
use App\Models\WcOrder;
use App\Models\WcOrderItem;
use App\Models\WcProduct;
use App\Services\MetricSnapshotService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'demo@insightistic.com')->exists()) {
            $this->command?->warn('Demo account already exists — skipping.');
            return;
        }

        $plan = Plan::where('slug', 'agency')->first();

        $user = User::create([
            'name'     => 'Demo Owner',
            'email'    => 'demo@insightistic.com',
            'password' => Hash::make('demo12345'),
            'status'   => 'active',
        ]);

        $org = Organization::create([
            'name'          => 'Demo Agency',
            'slug'          => 'demo-agency',
            'owner_user_id' => $user->id,
            'plan_id'       => $plan?->id,
            'status'        => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);
        UsageCounter::create(['organization_id' => $org->id, 'period' => now()->format('Y-m')]);

        $site = Site::create([
            'organization_id'   => $org->id,
            'name'              => 'Demo Store',
            'domain'            => 'demo-store.example',
            'platform'          => 'woocommerce',
            'connection_status' => 'connected',
            'timezone'          => 'UTC',
            'currency'          => 'USD',
            'last_sync_at'      => now(),
            'wc_version'        => '9.4',
            'plugin_version'    => '0.1.0',
        ]);

        // Products
        $productNames = ['Tactical Belt', 'Range Bag', 'Hearing Protection', 'Shooting Glasses',
            'Cleaning Kit', 'Holster', 'Ammo Crate', 'Targets Pack'];
        $products = [];
        foreach ($productNames as $i => $name) {
            $price = [29, 89, 25, 19, 35, 49, 120, 15][$i];
            $products[] = WcProduct::create([
                'site_id'             => $site->id,
                'external_product_id' => 100 + $i,
                'name'                => $name,
                'sku'                 => 'SKU-' . (100 + $i),
                'price'               => $price,
                'regular_price'       => $price,
                'stock_quantity'      => rand(0, 50),
                'stock_status'        => rand(0, 5) === 0 ? 'outofstock' : 'instock',
                'total_sales'         => rand(0, 200),
                'status'              => 'publish',
                'synced_at'           => now(),
            ]);
        }

        // Customers
        $customers = [];
        for ($c = 1; $c <= 15; $c++) {
            $customers[] = WcCustomer::create([
                'site_id'              => $site->id,
                'external_customer_id' => 1000 + $c,
                'email_hash'           => hash('sha256', "customer{$c}@example.com"),
                'first_name'           => 'Customer',
                'last_name'            => (string) $c,
                'city'                 => ['Mesa', 'Phoenix', 'Tucson', 'Austin', 'Dallas'][$c % 5],
                'country'              => 'US',
                'total_spent'          => 0,
                'order_count'          => 0,
            ]);
        }

        // 30 days of orders
        $statuses = ['completed', 'completed', 'completed', 'processing', 'on-hold', 'failed', 'cancelled'];
        $orderId = 5000;
        $spent = [];
        $counts = [];

        for ($d = 29; $d >= 0; $d--) {
            $day = Carbon::now('UTC')->subDays($d);
            $orderCount = rand(0, 6);

            for ($o = 0; $o < $orderCount; $o++) {
                $orderId++;
                $status = $statuses[array_rand($statuses)];
                $cust = $customers[array_rand($customers)];
                $createdAt = $day->copy()->setTime(rand(8, 21), rand(0, 59));

                $lineCount = rand(1, 3);
                $items = [];
                $subtotal = 0;
                for ($li = 0; $li < $lineCount; $li++) {
                    $p = $products[array_rand($products)];
                    $qty = rand(1, 3);
                    $lineTotal = round($p->price * $qty, 2);
                    $subtotal += $lineTotal;
                    $items[] = [
                        'product'  => $p,
                        'qty'      => $qty,
                        'total'    => $lineTotal,
                    ];
                }

                $refund = (rand(1, 20) === 1) ? round($subtotal, 2) : 0;
                $total = round($subtotal, 2);

                $order = WcOrder::create([
                    'site_id'           => $site->id,
                    'external_order_id' => $orderId,
                    'order_number'      => (string) $orderId,
                    'customer_id'       => $cust->external_customer_id,
                    'status'            => $status,
                    'currency'          => 'USD',
                    'total'             => $total,
                    'subtotal'          => $subtotal,
                    'tax_total'         => 0,
                    'shipping_total'    => 0,
                    'discount_total'    => 0,
                    'refund_total'      => $refund,
                    'payment_method'    => 'Card',
                    'created_at_store'  => $createdAt,
                    'completed_at_store'=> in_array($status, ['completed'], true) ? $createdAt : null,
                    'synced_at'         => now(),
                ]);

                foreach ($items as $it) {
                    WcOrderItem::create([
                        'site_id'             => $site->id,
                        'external_order_id'   => $orderId,
                        'external_product_id' => $it['product']->external_product_id,
                        'product_name'        => $it['product']->name,
                        'sku'                 => $it['product']->sku,
                        'quantity'            => $it['qty'],
                        'subtotal'            => $it['total'],
                        'total'               => $it['total'],
                    ]);
                }

                if (in_array($status, ['completed', 'processing', 'on-hold'], true)) {
                    $spent[$cust->id] = ($spent[$cust->id] ?? 0) + $total;
                    $counts[$cust->id] = ($counts[$cust->id] ?? 0) + 1;
                }
            }
        }

        // Roll customer totals
        foreach ($customers as $cust) {
            $cust->update([
                'total_spent' => $spent[$cust->id] ?? 0,
                'order_count' => $counts[$cust->id] ?? 0,
            ]);
        }

        // Build analytics snapshots from the seeded orders
        app(MetricSnapshotService::class)->rebuild($site);

        $this->command?->info('Demo account ready → demo@insightistic.com / demo12345');
    }
}
