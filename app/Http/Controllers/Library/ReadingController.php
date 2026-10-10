<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ReadingProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The books a student has opened, most recent first, with how far they got:
 * where the home page's "books read" leads.
 */
class ReadingController extends Controller
{
    public const PER_PAGE = 20;

    /** Tabs: query value => label. */
    public const SHOWS = ['all' => 'All', 'reading' => 'Still reading', 'finished' => 'Finished'];

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $show = array_key_exists((string) $request->query('show'), self::SHOWS) ? (string) $request->query('show') : 'all';

        // Books taken out of the library since are left out, as on the home page.
        $base = fn () => ReadingProgress::query()
            ->where('user_id', $user->id)
            ->whereHas('book', fn (Builder $q) => $q->approved());

        $counts = $base()->toBase()
            ->selectRaw('count(*) as total, sum(case when completed_at is null then 0 else 1 end) as finished')
            ->first();
        $total = (int) ($counts->total ?? 0);
        $finished = (int) ($counts->finished ?? 0);

        $reading = $base()
            ->when($show === 'reading', fn (Builder $q) => $q->whereNull('completed_at'))
            ->when($show === 'finished', fn (Builder $q) => $q->whereNotNull('completed_at'))
            ->with('book')
            ->latest('last_read_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('library.reading', [
            'reading' => $reading,
            'show' => $show,
            'counts' => ['all' => $total, 'reading' => $total - $finished, 'finished' => $finished],
        ]);
    }
}
