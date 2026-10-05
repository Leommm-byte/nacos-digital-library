<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\User;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Home: a landing page for guests, and for students a starting point
 * (greeting, books new for their class, their saved books). The full
 * dashboard with reading progress and announcements arrives in PR 10.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return view('home.guest');
        }

        $user->loadMissing('department:id,name');

        $forYou = Book::approved()
            ->whereIn('department_id', Catalog::activeDepartmentIds())
            ->where('department_id', $user->department_id)
            ->where('level', $user->level)
            ->latest('approved_at')
            ->limit(6)
            ->get();

        $saved = $user->bookmarks()->approved()->orderByPivot('created_at', 'desc')->limit(6)->get();

        return view('home.student', [
            'user' => $user,
            'greeting' => $this->greeting(),
            'forYou' => $forYou,
            'saved' => $saved,
            'savedCount' => $user->bookmarks()->approved()->count(),
            'savedIds' => $saved->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all(),
        ]);
    }

    private function greeting(): string
    {
        $hour = (int) now()->timezone((string) config('app.display_timezone'))->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
