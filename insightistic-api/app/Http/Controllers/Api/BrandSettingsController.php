<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandSettings;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandSettingsController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function show()
    {
        return response()->json(['brand_settings' => $this->settings()]);
    }

    public function update(Request $request)
    {
        if (! $this->whiteLabelAllowed()) {
            return $this->upgradeRequired();
        }

        $data = $request->validate([
            'primary_color'      => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'accent_color'       => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'email_from_name'    => ['nullable', 'string', 'max:120'],
            'email_from_address' => ['nullable', 'email', 'max:190'],
            'report_footer_text' => ['nullable', 'string', 'max:255'],
            'custom_domain'      => ['nullable', 'string', 'max:190'],
        ]);

        $brand = $this->settings();
        $brand->fill(array_filter($data, fn ($v) => $v !== null))->save();

        return response()->json(['brand_settings' => $brand]);
    }

    public function uploadLogo(Request $request)
    {
        if (! $this->whiteLabelAllowed()) {
            return $this->upgradeRequired();
        }

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $org  = $this->tenancy->organization();
        $disk = config('insightistic.reports.disk', 'public');
        $path = $request->file('logo')->store("brands/{$org->id}", $disk);

        $brand = $this->settings();
        // Store the absolute URL so both the API and the PDF report can render it.
        $brand->update(['logo_url' => Storage::disk($disk)->url($path)]);

        return response()->json(['brand_settings' => $brand]);
    }

    private function settings(): BrandSettings
    {
        $org = $this->tenancy->organization();
        return BrandSettings::firstOrCreate(['organization_id' => $org->id]);
    }

    private function whiteLabelAllowed(): bool
    {
        return (bool) ($this->tenancy->organization()->plan?->white_label_enabled);
    }

    private function upgradeRequired()
    {
        return response()->json([
            'message' => 'White-label is available on Agency plans and above.',
            'code'    => 'white_label_required',
        ], 402);
    }
}
