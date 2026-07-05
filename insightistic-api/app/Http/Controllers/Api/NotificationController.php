<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * Dashboard notifications + contextual conversion prompts. The rows are
 * written by the automations (trial sweep, plugin activation, billing hooks);
 * this controller only reads and resolves them.
 */
class NotificationController extends Controller
{
    public function index()
    {
        return response()->json([
            'notifications' => Notification::query()
                ->orderByDesc('created_at')
                ->limit(30)
                ->get(['id', 'type', 'title', 'message', 'action_url', 'status', 'created_at', 'read_at']),
        ]);
    }

    public function update(Request $request, Notification $notification)
    {
        $data = $request->validate(['status' => ['required', 'in:read,dismissed']]);

        $notification->update([
            'status'  => $data['status'],
            'read_at' => $notification->read_at ?? now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
