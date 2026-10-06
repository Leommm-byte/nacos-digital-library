<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The signed-in user's notifications (for now: decisions on their uploads).
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('notifications.index', [
            'notifications' => $user->notifications()->paginate(20),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marks one notification read and goes to what it's about.
     */
    public function open(Request $request, string $id): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $notification = $user->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $book = $notification->data['book'] ?? null;

        return is_string($book)
            ? redirect()->route('uploads.show', $book)
            : redirect()->route('notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->route('notifications.index')->with('status', 'All caught up.');
    }
}
