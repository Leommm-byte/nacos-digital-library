<?php

namespace App\Http\Controllers\Announcements;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Support\Audit;
use App\Support\Dashboard\Announcements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Announcements: everyone signed in reads the live ones; governors and
 * admins write them. Dates are whole days in Lagos time, and an
 * announcement shows until the end of its last day (the legacy app hid it
 * at midnight at the start of that day).
 */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('announcements.index', [
            'announcements' => Announcement::live()
                ->orderByRaw('coalesce(starts_at, created_at) desc')
                ->orderByDesc('id')
                ->paginate(10),
        ]);
    }

    public function manage(): View
    {
        return view('announcements.manage', [
            'announcements' => Announcement::with('author:id,fullname')->latest('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        // Every attribute set, so the form can read them (models are strict).
        return view('announcements.form', ['announcement' => new Announcement([
            'title' => '',
            'body' => '',
            'is_published' => true,
            'starts_at' => null,
            'ends_at' => null,
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $announcement = new Announcement($this->validated($request));
        /** @var User $user */
        $user = $request->user();
        $announcement->created_by = $user->id;
        $announcement->save();

        Announcements::forget();
        Audit::record('announcement_created', $announcement);

        return redirect()->route('announcements.manage')->with('status', 'Announcement posted.');
    }

    public function edit(Announcement $announcement): View
    {
        return view('announcements.form', ['announcement' => $announcement]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));

        Announcements::forget();
        Audit::record('announcement_updated', $announcement);

        return redirect()->route('announcements.manage')->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        Announcements::forget();
        Audit::record('announcement_deleted', $announcement, ['title' => $announcement->title]);

        return redirect()->route('announcements.manage')->with('status', 'Announcement deleted.');
    }

    /**
     * @return array{title: string, body: string, is_published: bool, starts_at: Carbon|null, ends_at: Carbon|null}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'ends_on.after_or_equal' => 'The last day can\'t be before the first day.',
        ]);

        $zone = (string) config('app.display_timezone');

        return [
            'title' => (string) $data['title'],
            'body' => (string) $data['body'],
            'is_published' => $request->boolean('is_published'),
            'starts_at' => isset($data['starts_on']) ? Carbon::parse((string) $data['starts_on'], $zone)->startOfDay()->utc() : null,
            'ends_at' => isset($data['ends_on']) ? Carbon::parse((string) $data['ends_on'], $zone)->endOfDay()->utc() : null,
        ];
    }
}
