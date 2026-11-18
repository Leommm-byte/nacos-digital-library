<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ElectionStatus;
use App\Enums\UserStatus;
use App\Enums\VoterRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Models\Voter;
use App\Support\Audit;
use App\Support\Departments\Activity;
use App\Support\Departments\Colours;
use App\Support\TemporaryPassword;
use App\Support\Uploads\Images;
use App\Support\Uploads\UnreadableImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin → Departments: the departments NACOS runs elections for
 * (docs/department-elections.md). Only NACOS admins approve a department,
 * set it up and give it its first admins; they can pause its space.
 */
class DepartmentController extends Controller
{
    public function index(): View
    {
        return view('admin.departments.index', [
            'departments' => Organization::query()
                ->withCount(['voters', 'roll', 'elections'])
                ->orderBy('name')
                ->get(),
            'requests' => OrganizationRequest::query()->where('status', OrganizationRequest::PENDING)->latest('id')->get(),
        ]);
    }

    /**
     * The form, filled in from a department's request when approving one.
     */
    public function create(Request $request): View
    {
        $application = $request->filled('request')
            ? OrganizationRequest::query()->where('status', OrganizationRequest::PENDING)->find($request->integer('request'))
            : null;

        return view('admin.departments.form', [
            'department' => new Organization($application ? [
                'name' => $application->department_name,
                'contact_name' => $application->contact_name,
                'contact_email' => $application->contact_email,
                'contact_phone' => $application->contact_phone,
                'notes' => trim(($application->contact_role ? "Contact's role: {$application->contact_role}\n" : '').($application->message ?? '')) ?: null,
            ] : []),
            'application' => $application,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $department = new Organization($this->validated($request));
        $department->save();
        $this->saveLogo($request, $department);

        // Approving a request: it's answered by this department.
        if ($request->filled('request')) {
            OrganizationRequest::query()->whereKey($request->integer('request'))->where('status', OrganizationRequest::PENDING)
                ->update(['status' => OrganizationRequest::APPROVED, 'organization_id' => $department->id]);
        }

        Audit::record('vote_space_created', $department, ['name' => $department->name]);

        return redirect()->route('admin.departments.show', $department)
            ->with('status', "{$department->name} is set up. Add its first admin below.");
    }

    public function decline(OrganizationRequest $application): RedirectResponse
    {
        abort_unless($application->status === OrganizationRequest::PENDING, 404);
        $application->forceFill(['status' => OrganizationRequest::DECLINED])->save();

        Audit::record('vote_space_request_declined', $application, ['name' => $application->department_name]);

        return back()->with('status', "Request from {$application->department_name} declined.");
    }

    public function show(Organization $department): View
    {
        return view('admin.departments.show', [
            'department' => $department,
            'admins' => $department->admins()->orderBy('fullname')->get(),
            'counts' => [
                'roll' => $department->roll()->count(),
                'voters' => $department->voters()->where('role', VoterRole::Voter)->count(),
                'elections' => $department->elections()->count(),
                'open' => $department->elections()->where('status', ElectionStatus::Open)->count(),
            ],
            'slip' => session('slip'),
        ]);
    }

    public function edit(Organization $department): View
    {
        return view('admin.departments.form', ['department' => $department, 'application' => null]);
    }

    public function update(Request $request, Organization $department): RedirectResponse
    {
        $department->update($this->validated($request, $department));
        $this->saveLogo($request, $department);

        if ($request->boolean('remove_logo') && $department->logo_path !== null) {
            Storage::disk('private')->delete($department->logo_path);
            $department->forceFill(['logo_path' => null])->save();
        }

        Audit::record('vote_space_updated', $department, ['name' => $department->name]);

        return redirect()->route('admin.departments.show', $department)->with('status', 'Department saved.');
    }

    /**
     * Pauses the department's space (nobody can sign in or vote; open
     * elections still close on time) or resumes it.
     */
    public function status(Request $request, Organization $department): RedirectResponse
    {
        $pause = $request->input('status') === UserStatus::Suspended->value;
        $department->forceFill(['status' => $pause ? UserStatus::Suspended : UserStatus::Active])->save();

        Audit::record($pause ? 'vote_space_paused' : 'vote_space_resumed', $department, ['name' => $department->name]);
        Activity::record($department, $pause ? 'space_paused' : 'space_resumed');

        return back()->with('status', $pause ? "{$department->name}'s space is paused." : "{$department->name}'s space is open again.");
    }

    /**
     * A new admin for the department, signed in with a printed slip: an
     * email (or matric number) and a temporary password, changed at once.
     */
    public function storeAdmin(Request $request, Organization $department): RedirectResponse
    {
        $data = $request->validateWithBag('admin', [
            'fullname' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $email = strtolower((string) $data['email']);
        $existing = $department->voters()->where('email', $email)->first();

        if ($existing?->isAdmin()) {
            throw ValidationException::withMessages(['email' => "{$existing->fullname} is already an admin here."])->errorBag('admin');
        }

        $password = TemporaryPassword::make();

        // Someone already registered to vote with this email becomes an admin
        // too; otherwise a new account.
        $admin = $existing ?? new Voter(['fullname' => (string) $data['fullname'], 'email' => $email]);
        $admin->organization_id = $department->id;
        $admin->role = VoterRole::Admin;
        $admin->password = $password;
        $admin->must_change_password = true;
        $admin->save();

        Audit::record('vote_space_admin_added', $department, ['name' => $admin->fullname, 'email' => $email]);
        Activity::record($department, 'admin_added', $admin, ['name' => $admin->fullname, 'email' => $email], null);

        return redirect()->route('admin.departments.show', $department)->with('slip', [
            'name' => $admin->fullname,
            'login' => $email,
            'password' => $password,
        ]);
    }

    public function adminPassword(Organization $department, int $admin): RedirectResponse
    {
        $admin = $department->admins()->findOrFail($admin);
        $password = TemporaryPassword::make();
        $admin->forceFill(['password' => $password, 'must_change_password' => true, 'remember_token' => null])->save();

        Audit::record('vote_space_admin_password', $department, ['name' => $admin->fullname]);
        Activity::record($department, 'admin_password', $admin, ['name' => $admin->fullname]);

        return redirect()->route('admin.departments.show', $department)->with('slip', [
            'name' => $admin->fullname,
            'login' => $admin->email ?? (string) $admin->matric_number,
            'password' => $password,
        ]);
    }

    /**
     * Takes away admin rights. An admin who also registered to vote keeps
     * that account as a voter; an admin-only account is deleted.
     */
    public function destroyAdmin(Organization $department, int $admin): RedirectResponse
    {
        $admin = $department->admins()->findOrFail($admin);

        DB::transaction(function () use ($admin) {
            if ($admin->matric_number !== null) {
                $admin->forceFill(['role' => VoterRole::Voter, 'remember_token' => null])->save();
            } else {
                $admin->delete();
            }
        });

        Audit::record('vote_space_admin_removed', $department, ['name' => $admin->fullname]);
        Activity::record($department, 'admin_removed', null, ['name' => $admin->fullname]);

        return back()->with('status', "{$admin->fullname} is no longer an admin of {$department->name}.");
    }

    /**
     * @return array{name: string, short_name: string|null, slug: string, contact_name: string|null, contact_email: string|null, contact_phone: string|null, notes: string|null, colour: string|null}
     */
    private function validated(Request $request, ?Organization $department = null): array
    {
        $request->merge(['slug' => Str::slug($request->string('slug')->toString() ?: $request->string('name')->toString())]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'short_name' => ['nullable', 'string', 'max:30'],
            'slug' => ['required', 'string', 'max:60', 'alpha_dash', Rule::notIn(Organization::RESERVED_SLUGS), Rule::unique('organizations', 'slug')->ignore($department?->id)],
            'colour' => ['nullable', Rule::in(array_keys(Colours::ALL))],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp'],
        ], [
            'slug.unique' => 'Another department already uses this address.',
            'slug.not_in' => 'This address is taken by a page of its own. Choose another.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
            'logo.mimes' => 'The logo must be a JPG, PNG or WebP image.',
            'logo.mimetypes' => 'The logo must be a JPG, PNG or WebP image.',
        ]);

        $optional = fn (string $key) => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return [
            'name' => trim((string) $data['name']),
            'short_name' => $optional('short_name'),
            'slug' => (string) $data['slug'],
            'contact_name' => $optional('contact_name'),
            'contact_email' => $optional('contact_email'),
            'contact_phone' => $optional('contact_phone'),
            'notes' => $optional('notes'),
            'colour' => $optional('colour'),
        ];
    }

    private function saveLogo(Request $request, Organization $department): void
    {
        $file = $request->file('logo');
        if (! $file instanceof UploadedFile) {
            return;
        }

        try {
            $jpeg = Images::toJpeg((string) file_get_contents((string) $file->getRealPath()), 256, 88)['data'];
        } catch (UnreadableImage) {
            throw ValidationException::withMessages(['logo' => 'This image couldn\'t be read. Try saving it as a JPG or PNG.']);
        }

        $disk = Storage::disk('private');
        $old = $department->logo_path;
        // A new name each time, so browsers don't keep showing the old one.
        $path = "department-logos/{$department->id}-".now()->format('YmdHis').'.jpg';
        $disk->put($path, $jpeg);
        $department->forceFill(['logo_path' => $path])->save();

        if ($old !== null && $old !== $path) {
            $disk->delete($old);
        }
    }
}
