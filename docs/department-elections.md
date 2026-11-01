# Department election spaces

NACOS runs elections for other departments on the same site. Each
department NACOS approves gets its own space at `/vote/{slug}`, for example
`nacosyabatech.com/vote/food-science`.

A department's space has:
- its own name and logo at the top, and "Powered by NACOS YabaTech" at the
  bottom;
- nothing else from the NACOS app: no library, timetable or NACOS
  accounts;
- its own admins, nominal roll, voters and elections.

The first department is Food Science and Technology.

## Decisions (with the owner)

| Question | Decision |
|---|---|
| Address | Under the NACOS domain: `/vote/{slug}`. No new hosting or DNS. |
| Who can join | Only departments a NACOS admin approves. Departments ask at `/vote/apply`; a NACOS admin approves or declines under Admin → Departments. |
| Students in two spaces | A separate account in each (a NACOS account doesn't work in a department's space, and the reverse). |
| Payment | Collected offline; the app has no billing. A NACOS admin can pause a space (for example while payment is outstanding). |

## Running it (NACOS admins)

1. **Add the department:** either approve its request (Admin → Departments
   → *Requests* → *Approve*, which opens the form filled in), or *Add a
   department* yourself.
   - The public list of spaces is at `/vote`, with *Ask for your
     department* (`/vote/apply`). Each request emails ALERT_EMAIL.
   - Fill in its name, an optional short name (shown in the logo tile when
     there's no logo) and its address.
   - The address becomes the part after `/vote/`; it's made from the name
     if left empty.
   - Add the logo, a colour (buttons and links on its pages) and the
     contact details here. Contact details are only shown to you.
2. **Add its first admin:** on the department's page, enter their name and
   email.
   - A slip with a temporary password appears once.
   - Send it to them privately. They sign in at the department's space with
     their email and choose their own password.
3. **Share the address** (`/vote/{slug}`) with the department.
4. **Do the work for them:** *Manage roll and elections* on the
   department's page opens its Manage area as you (signed in to NACOS):
   upload its roll, set up its election, help its voters. It works exactly
   as for its own admins, and you never become a voter there.
5. **Later, from the same page:**
   - add more admins;
   - give an admin a new password;
   - remove an admin;
   - pause or resume the whole space.

## Running it (a department's admins)

All from *Manage* at the top of the space. *Guide* there walks through it
step by step.

1. **Nominal roll.** Upload each class's list (Excel or CSV), choosing its
   programme and level.
   - It uses the same template as the NACOS roll: the class at the top,
     then matric number, full name and email.
   - Uploading a class again replaces that class only.
   - Students can be added one at a time too.
   - The roll is locked while voting is open.
2. **Students register** at the space with a matric number on the roll, their
   name and a password.
   - One account per matric number.
   - Ask them to register before voting opens: by default accounts made
     during voting can't vote in that election.
3. **Elections:** the same screens as NACOS's own.
   - Details and who can vote: programmes, levels, entry years.
   - Positions and candidates (with photos).
   - *Open voting* for a set time, and *Stop voting now*.
   - Results are public and update live.
   - Once voting opens, the results download as a spreadsheet (CSV) or
     print (or save as PDF) from the election's page. NACOS's own elections
     have the same buttons.
   - Department elections are always for students on the department's roll.
     There are no courses (arms).
4. **Voters:** see who has registered.
   - Students who gave an email reset a forgotten password themselves
     (*Forgot password?*: a link that works once, for 60 minutes).
   - For everyone else, *New password* gives a printed slip.
   - *Suspend* stops an account from voting.

## How it's built

- **Tables.** One migration adds them all
  (`2026_11_01_000100_create_department_elections`):
  - `organizations`: the departments.
  - `voters`: accounts in a department's space.
  - `organization_roll`: each department's roll.
  - `organization_election_voters`: who voted (like `election_voters`, with
    no link to `election_votes`).
  - `elections.organization_id`: null for NACOS's own elections.
- **Kept apart from NACOS.**
  - `Election` has a global scope (`nacos`) that keeps department elections
    out of every NACOS page, query and route binding.
  - A department's elections are reached through
    `$organization->elections()`.
  - `Election::everywhere()` covers both, and is used only by the scheduler
    and live results.
- **Accounts.**
  - Voters sign in through the `voter` guard (`App\Models\Voter`), never
    `web`.
  - The `vote` middleware (`ResolveOrganization`):
    - finds the department and hands it to controllers by type;
    - never makes `voter` the default guard: the `voter` guard is asked for
      by name, so `sessions.user_id` only ever holds NACOS users' ids;
    - signs out an account from another department;
    - shows the paused page;
    - fills `{organization}` into every `route()`.
  - `Audit::record` always uses the `web` guard. NACOS's audit log
    doesn't record department election activity.
- **Shared code.** Both kinds of election use the same:
  - eligibility (`Election::ineligibilityReason`, `electorate`);
  - ballot box (`BallotBox::cast` takes a `User` or a `Voter`);
  - live results;
  - launch and close (`ElectionLifecycle`);
  - roll file reader (`RollImport`).
- **Shared screens.**
  - The setup screens (`resources/views/elections/manage`) and their
    controllers are shared through `App\Support\Elections\ElectionDesk`.
    It says which routes, layout, roll and elections to use.
  - `Vote\Admin\ElectionController` and `BallotController` subclass the
    NACOS controllers.
- **Rate limits.** A whole campus can share one IP on election day, so nothing is limited per address alone:
  - signing in counts only failed attempts, per account and address (5 a minute), plus a loose 100 per address;
  - ballots and roll uploads are limited per account (`department-ballot`, `department-admin` in `AppServiceProvider`);
  - registration allows 120 per address every 10 minutes (a class can register together on campus Wi-Fi; only matric numbers on the roll can register, each once).
- **Tests:** `tests/Feature/Departments/DepartmentElectionsTest.php`.
- **Demo data:** `DemoDepartmentSeeder` (local and screenshots) seeds Food
  Science and Technology. Both accounts use the password `Password1!`:
  - `admin@fst.test`: the department's admin;
  - `F/ND/24/3101001`: a voter who hasn't voted yet.

- **NACOS admins in a space.** `vote.admin` (`EnsureDepartmentAdmin`)
  lets in the department's admins and NACOS admins (the
  `manage-departments` gate, on the `web` guard).
- **Colours.** `App\Support\Departments\Colours`: a fixed palette, each a
  `.brand-{key}` class on the space's `<body>` that sets the primary
  tokens (the CSP rules out inline styles). The space's styles are in
  `resources/css/vote.css`, loaded only on its pages and the department
  form, not with `app.css`.
- **Password reset.** `voter_password_resets`, keyed by account (the same
  email can be in several spaces and in NACOS); only a hash of the token is
  kept. The email (`VoterPasswordReset`) goes through the queue.

## Email delivery

Password reset links and the request alert go through the site's normal
mail (DreamHost SMTP, with its hourly limit). If many departments use
email, switch the mailer to a transactional service such as Brevo (free
up to about 300 emails a day): only `MAIL_*` settings in `.env` change,
then `php artisan optimize`.

## Not yet

- Invitations by email for a department's roll (students register
  themselves instead).
