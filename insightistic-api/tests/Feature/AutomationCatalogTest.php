<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteUserAccess;
use App\Models\User;
use App\Services\Actions\ActionType;
use App\Services\Anomaly\AnomalyDetector;
use App\Services\Automation\AutomationCatalog;

/**
 * Guards Automation Studio's rule-builder catalog against drifting from
 * the code it describes: every dispatched_by entry must really dispatch
 * its trigger, every active action must really be executable by
 * ActionExecutor, and doc-19 items that are NOT implemented must be
 * flagged coming_soon so the UI grays them out instead of faking them.
 */
function catalogSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Catalog Shop',
        'domain' => 'catalog-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

/** Runtime grep: the source file of a class, for asserting dispatched_by matches reality. */
function catalogSourceOf(string $class): string
{
    $path = (new ReflectionClass($class))->getFileName();
    expect($path)->not->toBeNull();

    return file_get_contents($path);
}

function catalogTriggerOfType(array $catalog, string $type): array
{
    $matches = array_values(array_filter($catalog['triggers'], fn ($t) => $t['type'] === $type));
    expect($matches)->toHaveCount(1);

    return $matches[0];
}

it('exposes exactly the trigger types the dispatcher implements', function () {
    $catalog = AutomationCatalog::catalog();

    $active = array_values(array_filter($catalog['triggers'], fn ($t) => ($t['status'] ?? 'active') === 'active'));
    expect(array_column($active, 'type'))->toEqualCanonicalizing(['schedule', 'alert_created', 'anomaly_detected']);

    // Nothing beyond the dispatcher's real allowlist may claim to be active.
    foreach ($catalog['triggers'] as $trigger) {
        if (($trigger['status'] ?? 'active') === 'active') {
            expect($trigger['dispatched_by'])->not->toBeEmpty();
        }
    }
});

it('lists dispatched_by services that really dispatch each trigger', function () {
    $catalog = AutomationCatalog::catalog();

    foreach ($catalog['triggers'] as $trigger) {
        if (($trigger['status'] ?? 'active') !== 'active') {
            expect($trigger['dispatched_by'])->toBeEmpty();
            continue;
        }

        foreach ($trigger['dispatched_by'] as $service) {
            expect(class_exists($service))->toBeTrue();
            // The service's own source really calls dispatch('<type>', ...).
            expect(catalogSourceOf($service))->toContain("dispatch('{$trigger['type']}'");
        }
    }

    // The five alert sources are exactly the ones wired today.
    $alertTrigger = catalogTriggerOfType($catalog, 'alert_created');
    expect($alertTrigger['dispatched_by'])->toEqualCanonicalizing([
        'App\Services\AlertDetector',
        'App\Services\Crawler\ChangeDetection',
        'App\Services\Uptime\IncidentManager',
        'App\Services\Uptime\SslInspector',
        'App\Services\Rank\RankChecker',
    ]);
});

it('offers only alert_type options its sources can actually raise', function () {
    $catalog = AutomationCatalog::catalog();
    $alertTrigger = catalogTriggerOfType($catalog, 'alert_created');

    $fields = array_column($alertTrigger['config_fields'], null, 'key');
    expect($fields)->toHaveKey('alert_type');
    $options = $fields['alert_type']['options'];

    // Every cataloged alert_type must appear as a literal in at least one
    // dispatching service's source (raise() call or ALERT_* constant).
    $sources = implode("\n", array_map(catalogSourceOf(...), $alertTrigger['dispatched_by']));
    foreach ($options as $option) {
        expect($sources)->toContain("'{$option}'");
    }

    // And the doc-19 initial triggers map onto real alert subtypes.
    foreach (['traffic_drop', 'critical_site_change', 'uptime_incident_confirmed', 'performance_regression', 'rank_dropped', 'low_stock', 'sync_stale'] as $expected) {
        expect($options)->toContain($expected);
    }
});

it('derives anomaly metric_id options from AnomalyDetector::supportedMetrics()', function () {
    $catalog = AutomationCatalog::catalog();
    $anomalyTrigger = catalogTriggerOfType($catalog, 'anomaly_detected');

    $fields = array_column($anomalyTrigger['config_fields'], null, 'key');
    expect($fields)->toHaveKey('metric_id');
    expect($fields['metric_id']['options'])->toBe(AnomalyDetector::supportedMetrics());
});

it('only offers actions the ActionExecutor can run, and never misses one', function () {
    $catalog = AutomationCatalog::catalog();

    $active = array_column(
        array_filter($catalog['actions'], fn ($a) => ($a['status'] ?? 'active') === 'active'),
        'type',
    );

    foreach ($active as $type) {
        expect(in_array($type, ActionType::ALL, true))->toBeTrue();
    }
    foreach (ActionType::ALL as $type) {
        expect(in_array($type, $active, true))->toBeTrue();
    }

    foreach ($catalog['actions'] as $action) {
        if (($action['status'] ?? 'active') === 'coming_soon') {
            expect(in_array($action['type'], ActionType::ALL, true))->toBeFalse();
        }
    }
});

it('flags doc-19 triggers and actions that are not implemented as coming_soon', function () {
    $catalog = AutomationCatalog::catalog();

    $aiDrop = catalogTriggerOfType($catalog, 'ai_visibility_drop');
    expect($aiDrop['status'])->toBe('coming_soon')
        ->and($aiDrop['dispatched_by'])->toBeEmpty();

    $soonActions = array_column(
        array_filter($catalog['actions'], fn ($a) => ($a['status'] ?? 'active') === 'coming_soon'),
        'type',
    );
    expect($soonActions)->toEqualCanonicalizing(['in_app_notification', 'generate_report']);
});

it('serves the catalog on the API to any site-access role', function () {
    [$user, $org] = makeOrgUser('catalog-owner@example.com');
    $site = catalogSite($org);

    // Unauthenticated first — actingAs would otherwise persist for the
    // rest of this test.
    $this->getJson("/api/sites/{$site->id}/automation-rules/catalog")->assertUnauthorized();

    $this->actingAs($user)
        ->getJson("/api/sites/{$site->id}/automation-rules/catalog")
        ->assertOk()
        ->assertJsonStructure(['triggers', 'actions'])
        ->assertJsonPath('triggers.0.type', 'schedule'); // route must not be swallowed by {automationRule}

    $viewer = User::create([
        'name' => 'Catalog Viewer',
        'email' => 'catalog-viewer@example.com',
        'password' => 'secret-password',
        'status' => 'active',
    ]);
    $org->users()->attach($viewer->id, ['role' => 'client_viewer']);
    SiteUserAccess::create(['site_id' => $site->id, 'user_id' => $viewer->id, 'role' => 'viewer']);

    $this->actingAs($viewer)
        ->getJson("/api/sites/{$site->id}/automation-rules/catalog")
        ->assertOk()
        ->assertJsonStructure(['triggers', 'actions']);
});

it('never serves the catalog for another organization\'s site', function () {
    [$userA, $orgA] = makeOrgUser('catalog-iso-a@example.com');
    [$userB, $orgB] = makeOrgUser('catalog-iso-b@example.com');
    $siteA = catalogSite($orgA);

    $this->actingAs($userB)
        ->getJson("/api/sites/{$siteA->id}/automation-rules/catalog")
        ->assertNotFound();
});
