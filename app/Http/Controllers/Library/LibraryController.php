<?php

namespace App\Http\Controllers\Library;

use App\Enums\BookStatus;
use App\Enums\Level;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class LibraryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => mb_substr(trim($request->string('q')->toString()), 0, 100),
            'level' => $request->string('level')->toString(),
            'department' => $request->integer('department'),
            'sort' => $request->string('sort')->toString(),
        ];

        $books = Catalog::query($filters)->paginate(Catalog::PER_PAGE)->withQueryString();

        return view('library.index', [
            'books' => $books,
            'bookmarked' => $this->bookmarkedIds($request, $books->getCollection()->pluck('id')->all()),
            'filters' => $filters,
            'levels' => collect(Level::cases())->mapWithKeys(fn (Level $level) => [$level->value => $level->label()])->all(),
            'departments' => Department::whereIn('id', Catalog::activeDepartmentIds())->orderBy('name')->pluck('name', 'id')->all(),
            'sorts' => Catalog::SORTS,
            'filtering' => $filters['q'] !== '' || $filters['level'] !== '' || $filters['department'] > 0,
        ]);
    }

    public function show(Request $request, Book $book): View
    {
        // Not found, rather than forbidden, so unapproved books stay hidden.
        abort_unless(Gate::allows('view', $book), 404);

        $book->load(['department:id,name', 'uploader:id,fullname']);

        $related = $book->status === BookStatus::Approved
            ? Book::approved()
                ->where('department_id', $book->department_id)
                ->where('level', $book->level)
                ->whereKeyNot($book->id)
                ->latest('approved_at')
                ->limit(4)
                ->get()
            : collect();

        return view('library.show', [
            'book' => $book,
            'related' => $related,
            'bookmarked' => $this->bookmarkedIds($request, [$book->id, ...$related->pluck('id')->all()]),
        ]);
    }

    /**
     * Which of these books the user has saved, in one query.
     *
     * @param  array<mixed>  $bookIds
     * @return array<int, bool>
     */
    private function bookmarkedIds(Request $request, array $bookIds): array
    {
        $user = $request->user();

        if (! $user instanceof User || $bookIds === []) {
            return [];
        }

        return $user->bookmarks()
            ->whereIn('books.id', $bookIds)
            ->pluck('books.id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
