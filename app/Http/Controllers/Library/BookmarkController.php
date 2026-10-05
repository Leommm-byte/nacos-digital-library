<?php

namespace App\Http\Controllers\Library;

use App\Enums\BookStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Saved books. Saving and removing work as plain form posts; with
 * JavaScript they happen without leaving the page (resources/js/bookmarks.js).
 */
class BookmarkController extends Controller
{
    public const PER_PAGE = 24;

    public function index(Request $request): View
    {
        $books = $this->user($request)->bookmarks()
            ->approved()
            ->with('department:id,name')
            ->orderByPivot('created_at', 'desc')
            ->paginate(self::PER_PAGE);

        return view('library.saved', ['books' => $books]);
    }

    public function store(Request $request, Book $book): JsonResponse|RedirectResponse
    {
        // Only books in the public catalog can be saved.
        abort_unless($book->status === BookStatus::Approved, 404);

        $this->user($request)->bookmarks()->syncWithoutDetaching([$book->id]);

        return $this->respond($request, true, 'Saved to your books.');
    }

    public function destroy(Request $request, Book $book): JsonResponse|RedirectResponse
    {
        $this->user($request)->bookmarks()->detach($book->id);

        return $this->respond($request, false, 'Removed from your saved books.');
    }

    private function respond(Request $request, bool $saved, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['saved' => $saved, 'message' => $message]);
        }

        return back()->with('status', $message);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
