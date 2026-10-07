<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Department;
use App\Support\Audit;
use App\Support\BookRemover;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Every book whatever its status, with permanent deletion (files, cover,
 * text, bookmarks and reading history; see BookRemover).
 */
class BookController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(BookStatus::class)],
            'department' => ['nullable', 'integer'],
        ]);

        $books = Book::query()
            ->with(['uploader:id,fullname,matric_number', 'department:id,name'])
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%')->orWhere('author', 'like', '%'.$search.'%'));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['department'] ?? null, fn (Builder $query, $department) => $query->where('department_id', $department))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.books.index', [
            'books' => $books,
            'search' => $search,
            'filters' => $filters,
            'departments' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function destroy(Book $book): RedirectResponse
    {
        BookRemover::remove($book);
        Audit::record('book_deleted', $book, ['title' => $book->title, 'by_admin' => true]);

        return back()->with('status', "Deleted \"{$book->title}\" for good.");
    }
}
