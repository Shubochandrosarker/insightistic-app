<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appsumo_licenses', function (Blueprint $table): void {
            $table->boolean('keeps_tier_three_features')->default(false);
        });
        DB::table('appsumo_licenses')->where('tier', 3)->where('is_test', false)
            ->whereIn('license_status', ['active', 'inactive'])
            ->update(['keeps_tier_three_features' => true]);

        $this->updateLimits([5, 10, 15, 30, 50], [10, 50, null, null, null], [5, 25, 50, 100, null], [1, 3, 5, 10, 20], 5, 50, false);
    }

    public function down(): void
    {
        $this->updateLimits([1, 3, 10, 25, 50], [5, 25, 100, 250, 500], [1, 3, 10, 25, 50], [1, 3, 3, 3, 3], 3, 10, true);
        Schema::table('appsumo_licenses', function (Blueprint $table): void {
            $table->dropColumn('keeps_tier_three_features');
        });
    }

    private function updateLimits(
        array $websites,
        array $reports,
        array $schedules,
        array $knowledgeBases,
        int $tierOneMembers,
        int $credits,
        bool $tierThreePremium,
    ): void
    {
        foreach ($reports as $index => $reportLimit) {
            $tier = $index + 1;
            $query = DB::table('plans')->where('slug', "appsumo_tier_{$tier}");
            $plan = $query->first(['user_limit', 'limits_json', 'features_json']);
            if ($plan === null) {
                continue;
            }

            $limits = json_decode($plan->limits_json ?? '{}', true, flags: JSON_THROW_ON_ERROR) ?? [];
            $limits['connected_websites'] = $websites[$index];
            $limits['client_ready_reports_monthly'] = $reportLimit ?? 0;
            $limits['scheduled_report_limit'] = $schedules[$index] ?? 0;
            if ($schedules[$index] === null) {
                $limits['unlimited_report_schedules'] = 1;
            } else {
                unset($limits['unlimited_report_schedules']);
            }
            $limits['knowledge_bases'] = $knowledgeBases[$index];
            $limits['team_member_limit'] = $tier === 1 ? $tierOneMembers : 0;
            $limits['team_member_add_credits'] = $credits;
            if ($reportLimit === null) {
                $limits['unlimited_client_reports'] = 1;
            } else {
                unset($limits['unlimited_client_reports']);
            }

            $seatLimit = (int) $plan->user_limit;
            if ($tier === 1) {
                $seatLimit = $tierOneMembers + 1;
                $limits['team_seats'] = $seatLimit;
            }

            $attributes = [
                'site_limit' => $websites[$index],
                'report_limit' => $reportLimit ?? 0,
                'user_limit' => $seatLimit,
                'limits_json' => json_encode($limits, JSON_THROW_ON_ERROR),
            ];
            if ($tier >= 3) {
                $features = json_decode($plan->features_json ?? '[]', true, flags: JSON_THROW_ON_ERROR) ?? [];
                $premium = $tier === 3 ? ['white_label_reports', 'priority_support'] : ['executive_insights'];
                $addPremium = $tier === 3 ? $tierThreePremium : ! $tierThreePremium;
                $features = $addPremium
                    ? array_unique(array_merge($features, $premium))
                    : array_diff($features, $premium);
                $attributes['features_json'] = json_encode(array_values($features), JSON_THROW_ON_ERROR);
            }
            if ($tier === 3) {
                $attributes['white_label_enabled'] = $tierThreePremium;
            }

            $query->update($attributes);
        }
    }
};
