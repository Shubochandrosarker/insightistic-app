<?php

namespace App\Jobs;

use App\Models\OrganizationExport;
use App\Services\Privacy\OrganizationExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Assemble an organization's data export off the request cycle — a large
 * store's archive takes far longer than any sane HTTP timeout.
 */
class BuildOrganizationExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $exportId)
    {
    }

    public function handle(OrganizationExporter $exporter): void
    {
        $export = OrganizationExport::withoutGlobalScope('organization')->find($this->exportId);

        if (! $export || $export->status === 'ready') {
            return;
        }

        try {
            $exporter->build($export);
        } catch (\Throwable $e) {
            report($e);

            // Record the failure on the row so the UI can say what happened
            // rather than leaving the export stuck on "processing" forever.
            $export->update([
                'status'        => 'failed',
                'error_message' => 'The export could not be generated. Please try again.',
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        OrganizationExport::withoutGlobalScope('organization')
            ->where('id', $this->exportId)
            ->where('status', '!=', 'ready')
            ->update(['status' => 'failed']);
    }
}
