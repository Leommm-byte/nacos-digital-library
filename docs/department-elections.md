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
| Who can join | Only departments a NACOS admin adds (Admin → Departments). |
| Students in two spaces | A separate account in each (a NACOS account doesn't work in a department's space, and the reverse). |
| Payment | Collected offline; the app has no billing. A NACOS admin can pause a space (for example while payment is outstanding). |

## Running it (NACOS admins)

1. **Add the department:** Admin → Departments → *Add a department*.
   - Fill in its name, an optional short name (shown in the logo tile when
     there's no logo) and its address.
   - The address becomes the part after `/vote/`; it's made from the name
     if left empty.
   - Add the logo and the contact details here. Contact details are only
     shown to you.
2. **Add its first admin:** on the department's page, enter their name and
   email.
   - A slip with a temporary password appears once.
   - Send it to them privately. They sign in at the department's space with
     their email and choose their own password.
3. **Share the address** (`/vote/{slug}`) with the department.
4. **Later, from the same page:**
   - add more admins;
   - give an admin a new password;
   - remove an admin;
   - pause or resume the whole space.

## Running it (a department's admins)

All from *Manage* at the top of the space.

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
   - Department elections are always for students on the department's roll.
     There are no courses (arms).
4. **Voters:** see who has registered.
   - *New password* gives a forgotten password back as a printed slip.
     There's no email reset in a department's space.
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
    - finds the department and makes `voter` the default guard;
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
- **Tests:** `tests/Feature/Departments/DepartmentElectionsTest.php`.
- **Demo data:** `DemoDepartmentSeeder` (local and screenshots) seeds Food
  Science and Technology. Both accounts use the password `Password1!`:
  - `admin@fst.test`: the department's admin;
  - `F/ND/24/3101001`: a voter who hasn't voted yet.

## Not yet (later phases)

- A form for departments to ask to join (for now, a NACOS admin adds them).
- Password reset by email, and invitations, in a department's space (for
  now, admins print slips).
- Each department's own colours.
- Exporting results as PDF or CSV.
