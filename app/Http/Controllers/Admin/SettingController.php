<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\Assistant\Tutor;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Site settings (stored in the database and applied over config/) and
 * which departments are open.
 */
class SettingController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings', [
            'settings' => [
                'site_name' => Settings::get('site_name', (string) config('app.name')),
                'academic_session' => Settings::get('academic_session'),
                'pdf_max_mb' => Settings::get('pdf_max_mb', (string) intdiv((int) config('uploads.pdf_max_kb'), 1024)),
                'uploads_per_day' => Settings::get('uploads_per_day', (string) config('uploads.per_day')),
                'uploads_pending' => Settings::get('uploads_pending', (string) config('uploads.pending')),
                'assistant_daily_limit' => Settings::get('assistant_daily_limit', (string) config('assistant.daily_limit')),
            ],
            'aiKey' => Tutor::available(),
            'departments' => Department::query()->withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:60'],
            'academic_session' => ['nullable', 'regex:/^20[0-9]{2}\/20[0-9]{2}$/'],
            'pdf_max_mb' => ['required', 'integer', 'min:1', 'max:50'],
            'uploads_per_day' => ['required', 'integer', 'min:1', 'max:200'],
            'uploads_pending' => ['required', 'integer', 'min:1', 'max:500'],
            'assistant_daily_limit' => ['nullable', 'integer', 'min:0', 'max:200'],
        ], [
            'academic_session.regex' => 'Write the session like 2026/2027.',
            'pdf_max_mb.max' => 'Keep PDFs to 50 MB or less; shared hosting limits uploads.',
        ]);

        $values = [
            'site_name' => trim((string) $data['site_name']),
            'academic_session' => isset($data['academic_session']) ? (string) $data['academic_session'] : null,
            'pdf_max_mb' => (int) $data['pdf_max_mb'],
            'uploads_per_day' => (int) $data['uploads_per_day'],
            'uploads_pending' => (int) $data['uploads_pending'],
        ];

        if (isset($data['assistant_daily_limit'])) {
            $values['assistant_daily_limit'] = (int) $data['assistant_daily_limit'];
        }

        Settings::put($values);
        Audit::record('settings_updated', null, $values);

        return back()->with('status', 'Settings saved.');
    }

    /**
     * Opens or closes a department for signup, uploads and the library.
     */
    public function department(Request $request, Department $department): RedirectResponse
    {
        $open = $request->boolean('open');

        if (! $open && Department::query()->where('is_active', true)->whereKeyNot($department->id)->doesntExist()) {
            return back()->withErrors(['department' => 'At least one department has to stay open.']);
        }

        $department->update(['is_active' => $open]);
        // The library caches which departments are open.
        Cache::forget('catalog:active-departments');

        Audit::record($open ? 'department_opened' : 'department_closed', $department, ['name' => $department->name]);

        return back()->with('status', "{$department->name} is ".($open ? 'open' : 'closed').'.');
    }
}
