<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Base for every /api/admin/* controller. Provides consistent, safe pagination,
 * search and sorting. Admin routes deliberately omit the `tenant` middleware so
 * the org global scope is inert and queries see every tenant.
 */
abstract class AdminController extends Controller
{
    protected function perPage(Request $request): int
    {
        return min(100, max(5, (int) $request->query('per_page', 20)));
    }

    /** Case-insensitive LIKE operator for the active driver. */
    protected function likeOp(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /** Apply a whitelisted sort column + direction (defaults to desc). */
    protected function applySort(Builder $query, Request $request, array $allowed, string $default): void
    {
        $sort = in_array($request->query('sort'), $allowed, true) ? $request->query('sort') : $default;
        $dir  = strtolower((string) $request->query('dir')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);
    }
}
