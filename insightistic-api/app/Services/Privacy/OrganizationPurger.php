<?php

namespace App\Services\Privacy;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Carries out an organization deletion once its grace window has elapsed.
 *
 * Most tables clear themselves through `cascadeOnDelete` foreign keys, so
 * the job here is the parts a cascade cannot reach:
 *
 *  - Files on disk — knowledge uploads, generated reports and export
 *    archives. A cascade deletes the row that points at a file and leaves
 *    the file, which is exactly the outcome a deletion request is meant to
 *    prevent.
 *  - Users who belonged to this organization and no other. Leaving them
 *    behind as orphan accounts keeps personal data after a deletion.
 */
class OrganizationPurger
{
    /**
     * @return array{sites:int,files:int,users:int}
     */
    public function purge(Organization $org): array
    {
        $siteIds = DB::table('sites')->where('organization_id', $org->id)->pluck('id')->all();
        $memberIds = DB::table('organization_users')->where('organization_id', $org->id)->pluck('user_id')->all();

        $files = $this->deleteFiles($org, $siteIds);

        // Delete the organization; foreign keys cascade the rest.
        $org->delete();

        $users = $this->deleteOrphanedUsers($memberIds);

        return ['sites' => count($siteIds), 'files' => $files, 'users' => $users];
    }

    /** Remove every stored file this organization owns. Returns the count. */
    private function deleteFiles(Organization $org, array $siteIds): int
    {
        $deleted = 0;

        // Knowledge uploads.
        $knowledgeDisk = config('insightistic.knowledge.disk');
        foreach (DB::table('knowledge_sources')->where('organization_id', $org->id)->pluck('storage_path') as $path) {
            if ($path && Storage::disk($knowledgeDisk)->exists($path)) {
                Storage::disk($knowledgeDisk)->delete($path);
                $deleted++;
            }
        }

        // Generated reports. `html_url`/`pdf_url` hold relative disk paths
        // despite the column names — the public URL is built by an accessor.
        $reportsDisk = config('insightistic.reports.disk');
        $reports = DB::table('client_reports')
            ->where('organization_id', $org->id)
            ->get(['html_url', 'pdf_url']);

        foreach ($reports as $report) {
            foreach ([$report->html_url, $report->pdf_url] as $path) {
                if ($path && Storage::disk($reportsDisk)->exists($path)) {
                    Storage::disk($reportsDisk)->delete($path);
                    $deleted++;
                }
            }
        }

        // Export archives — including any still-valid download.
        foreach (DB::table('organization_exports')->where('organization_id', $org->id)->get() as $export) {
            if ($export->storage_path && $export->disk && Storage::disk($export->disk)->exists($export->storage_path)) {
                Storage::disk($export->disk)->delete($export->storage_path);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete accounts that belonged only to this organization.
     *
     * A user who is also a member elsewhere keeps their account — deleting
     * one tenant must not remove someone's access to another.
     */
    private function deleteOrphanedUsers(array $memberIds): int
    {
        if ($memberIds === []) {
            return 0;
        }

        $stillMembers = DB::table('organization_users')
            ->whereIn('user_id', $memberIds)
            ->pluck('user_id')
            ->unique()
            ->all();

        $orphans = array_values(array_diff($memberIds, $stillMembers));

        if ($orphans === []) {
            return 0;
        }

        // Super admins are platform staff, not tenant members — never remove
        // one as a side effect of a customer deleting their organization.
        return DB::table('users')
            ->whereIn('id', $orphans)
            ->where('is_super_admin', false)
            ->delete();
    }
}
