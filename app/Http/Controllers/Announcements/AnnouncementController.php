<?php

namespace App\Http\Controllers\Announcements;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Support\Audit;
use App\Support\Classes\SchoolClass;
use App\Support\Dashboard\Announcements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Announcements: everyone signed in reads the live ones for everyone and
 * for their own class, each on its own page. A class's governor posts to
 * that class (seen by the class and admins); admins post to everyone or
 * any class. Dates are whole days in Lagos time, and an announcement shows
 * until the end of its last day (the legacy app hid it at midnight at the
 * start of that day).
 */
class AnnouncementController extends Controller
{
    /** Long enough for a full notice or event write-up. */
    public const BODY_MAX = 20000;

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('announcements.index', [
            'announcements' => Announcement::live()
                ->visibleTo($user)
                ->orderByRaw('coalesce(starts_at, created_at) desc')
                ->orderByDesc('id')
                ->paginate(10),
        ]);
    }

    public function show(Announcement $announcement): View
    {
        Gate::authorize('view', $announcement);
        $announcement->load('author:id,fullname,display_name');

        return view('announcements.show', ['announcement' => $announcement]);
    }

    public function manage(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $everything = Gate::allows('view-all-announcements');
        $class = $user->schoolClass();

        return view('announcements.manage', [
            'announcements' => Announcement::with('author:id,fullname,display_name')
                ->when(! $everything, fn ($query) => $query->forClass($class ?? abort(403)))
                ->latest('id')
                ->paginate(20),
            'class' => $everything ? null : $class,
        ]);
    }

    public function create(Request $request): View
    {
        // Every attribute set, so the form can read them (models are strict).
        $announcement = new Announcement([
            'title' => '',
            'body' => '',
            'is_published' => true,
            'starts_at' => null,
            'ends_at' => null,
        ]);

        return view('announcements.form', $this->formData($request, $announcement));
    }

    public function store(Request $request): RedirectResponse
    {
        $announcement = new Announcement($this->validated($request));
        /** @var User $user */
        $user = $request->user();
        $announcement->created_by = $user->id;
        $announcement->save();

        Announcements::forget();
        Audit::record('announcement_created', $announcement, ['title' => $announcement->title, 'for' => $announcement->audience()]);

        return redirect()->route('announcements.show', $announcement)->with('status', $announcement->is_published ? 'Announcement posted.' : 'Draft saved. Only you and admins can see it.');
    }

    public function edit(Request $request, Announcement $announcement): View
    {
        Gate::authorize('update', $announcement);

        return view('announcements.form', $this->formData($request, $announcement));
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        Gate::authorize('update', $announcement);
        $announcement->update($this->validated($request, $announcement));

        Announcements::forget();
        Audit::record('announcement_updated', $announcement, ['title' => $announcement->title, 'for' => $announcement->audience()]);

        return redirect()->route('announcements.show', $announcement)->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        Gate::authorize('delete', $announcement);
        $announcement->delete();

        Announcements::forget();
        Audit::record('announcement_deleted', $announcement, ['title' => $announcement->title, 'for' => $announcement->audience()]);

        return redirect()->route('announcements.manage')->with('status', 'Announcement deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, Announcement $announcement): array
    {
        /** @var User $user */
        $user = $request->user();
        $everyone = Gate::allows('announce-to-everyone');

        return [
            'announcement' => $announcement,
            // Admins choose; a governor's go to their own class.
            'audiences' => $everyone
                ? ['' => 'Everyone'] + collect(SchoolClass::all())->mapWithKeys(fn (SchoolClass $c) => [$c->key() => $c->label()])->all()
                : null,
            'class' => $everyone ? null : $user->schoolClass(),
            'bodyMax' => self::BODY_MAX,
        ];
    }

    /**
     * @return array{title: string, body: string, programme: string|null, level: string|null, arm: string|null, is_published: bool, starts_at: Carbon|null, ends_at: Carbon|null}
     */
    private function validated(Request $request, ?Announcement $existing = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:'.self::BODY_MAX],
            'audience' => ['nullable', 'string', Rule::in(array_map(fn (SchoolClass $c) => $c->key(), SchoolClass::all()))],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'body.max' => 'The message can be at most '.number_format(self::BODY_MAX).' characters.',
            'ends_on.after_or_equal' => 'The last day can\'t be before the first day.',
        ]);

        $zone = (string) config('app.display_timezone');

        if (Gate::allows('announce-to-everyone')) {
            $class = SchoolClass::fromKey(isset($data['audience']) ? (string) $data['audience'] : null);
        } else {
            // A governor's always go to their own class; when editing, the
            // announcement is already theirs (the policy checked).
            /** @var User $user */
            $user = $request->user();
            $class = $existing?->schoolClass() ?? $user->schoolClass();
            abort_if($class === null, 403);
        }

        return [
            'title' => (string) $data['title'],
            'body' => (string) $data['body'],
            'programme' => $class?->programme->value,
            'level' => $class?->level->value,
            'arm' => $class?->arm,
            'is_published' => $request->boolean('is_published'),
            'starts_at' => isset($data['starts_on']) ? Carbon::parse((string) $data['starts_on'], $zone)->startOfDay()->utc() : null,
            'ends_at' => isset($data['ends_on']) ? Carbon::parse((string) $data['ends_on'], $zone)->endOfDay()->utc() : null,
        ];
    }
}
