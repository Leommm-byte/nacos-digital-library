<?php

namespace App\Http\Controllers\Vote;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationRequest;
use App\Support\Operations\Alerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /vote: the departments NACOS runs elections for, and a form for another
 * department to ask for a space. A NACOS admin approves or declines each
 * request (Admin → Departments); the alert email says one came in.
 */
class DirectoryController extends Controller
{
    public function index(): View
    {
        return view('vote.directory', [
            'departments' => Organization::query()->where('status', UserStatus::Active)->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('vote.apply');
    }

    public function store(Request $request): RedirectResponse
    {
        // A field people never see: bots fill it in.
        if ($request->filled('website')) {
            return redirect()->route('vote.apply.sent');
        }

        $data = $request->validate([
            'department_name' => ['required', 'string', 'max:150'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_role' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'department_name.required' => 'Enter the department or association.',
            'contact_name.required' => 'Enter your name.',
            'contact_email.required' => 'Enter an email we can reach you at.',
        ]);

        $application = new OrganizationRequest($data);
        $application->contact_email = strtolower((string) $data['contact_email']);
        $application->ip_address = $request->ip();
        $application->save();

        Alerts::notice(
            'A department asked for an election space',
            "{$application->department_name} asked for an election space ({$application->contact_name}, {$application->contact_email}).\n\n"
            .'Approve or decline it under Admin → Departments: '.route('admin.departments.index'),
            30,
        );

        return redirect()->route('vote.apply.sent');
    }

    public function sent(): View
    {
        return view('vote.apply-sent');
    }
}
