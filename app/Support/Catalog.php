<?php

namespace App\Support;

use App\Enums\Level;
use App\Models\Book;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The library catalog query: approved books in active departments, with
 * search, filters and sorting. Search uses MySQL full-text indexes on the
 * title, author and description and on the text read from the pages (no
 * `LIKE '%…%'` table scans); other
 * databases (SQLite in local tests) fall back to simple matching.
 */
class Catalog
{
    public const PER_PAGE = 24;

    public const SORTS = [
        'newest' => 'Newest',
        'popular' => 'Most read',
        'title' => 'Title A–Z',
    ];

    /**
     * InnoDB's default stopwords (3+ letters). They aren't indexed, so
     * requiring one would match nothing.
     */
    private const STOPWORDS = [
        'about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this',
        'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];

    /**
     * @param  array{q?: string|null, level?: string|null, department?: int|string|null, sort?: string|null}  $filters
     * @return Builder<Book>
     */
    public static function query(array $filters): Builder
    {
        $query = Book::query()
            ->approved()
            ->whereIn('department_id', self::activeDepartmentIds())
            ->with('department:id,name');

        $level = Level::tryFrom((string) ($filters['level'] ?? ''));
        if ($level) {
            $query->where('level', $level);
        }

        $department = (int) ($filters['department'] ?? 0);
        if ($department > 0) {
            $query->where('department_id', $department);
        }

        $search = trim((string) ($filters['q'] ?? ''));
        $relevance = $search !== '' ? self::search($query, $search) : null;

        $sort = (string) ($filters['sort'] ?? '');
        if (! isset(self::SORTS[$sort])) {
            $sort = $relevance !== null ? 'relevance' : 'newest';
        }

        return match ($sort) {
            // A match in the title, author or description outranks one in the
            // book's text.
            'relevance' => $query
                ->orderByRaw('(MATCH (books.title, books.author, books.description) AGAINST (? IN BOOLEAN MODE) * 3 + COALESCE(MATCH (book_texts.text) AGAINST (? IN BOOLEAN MODE), 0)) DESC', [$relevance, $relevance])
                ->orderByDesc('books.id'),
            'popular' => $query->orderByDesc('views_count')->orderByDesc('id'),
            'title' => $query->orderBy('title')->orderBy('id'),
            default => $query->orderByDesc('approved_at')->orderByDesc('id'),
        };
    }

    /**
     * Department ids whose books are listed. Cleared when an admin changes
     * departments.
     *
     * @return list<int>
     */
    public static function activeDepartmentIds(): array
    {
        /** @var list<int> $ids */
        $ids = Cache::remember('catalog:active-departments', 300, fn () => Department::where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all());

        return $ids;
    }

    /**
     * Adds the search condition. Returns the full-text expression to rank
     * by, or null when the simple fallback was used.
     *
     * @param  Builder<Book>  $query
     */
    private static function search(Builder $query, string $search): ?string
    {
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($search)) ?: [])
            ->filter(fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true))
            ->unique()
            ->take(8)
            ->values();

        $mysql = $query->getModel()->getConnection()->getDriverName() === 'mysql';

        // The page text lives in its own table, joined only when searching.
        $query->leftJoin('book_texts', 'book_texts.book_id', '=', 'books.id')->select('books.*');

        if ($mysql && $words->isNotEmpty()) {
            // Every word must appear, as a word or the start of one:
            // "data struct" finds "Data Structures".
            $boolean = $words->map(fn (string $word) => '+'.$word.'*')->implode(' ');
            $query->where(fn (Builder $q) => $q
                ->whereFullText(['books.title', 'books.author', 'books.description'], $boolean, ['mode' => 'boolean'])
                ->orWhereFullText(['book_texts.text'], $boolean, ['mode' => 'boolean']));

            return $boolean;
        }

        $escape = fn (string $value) => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        if (! $mysql) {
            // No full-text index (SQLite in local tests): every word must
            // appear somewhere in the title, author, description or text.
            $terms = $words->isNotEmpty() ? $words : collect([mb_strtolower($search)]);

            foreach ($terms as $term) {
                $like = '%'.$escape($term).'%';
                $query->where(fn (Builder $q) => $q
                    ->where('title', 'like', $like)
                    ->orWhere('author', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('book_texts.text', 'like', $like));
            }

            return null;
        }

        // Only short or common words (e.g. "C++"): match the start of the
        // title or author, which MySQL can still do from an index.
        $prefix = $escape($search).'%';
        $query->where(fn (Builder $q) => $q->where('title', 'like', $prefix)->orWhere('author', 'like', $prefix));

        return null;
    }
}
