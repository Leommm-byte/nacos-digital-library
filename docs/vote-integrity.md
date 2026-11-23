# Stopping votes in someone else's name

After the 2026 primaries, students reported that someone had registered
with their matric number and voted as them. Nothing was hacked: a matric
number isn't secret, so a classmate who registers first can take it,
unless the class list has the student's email (sign-up then sends the link
to that address).

## What admins do

1. **Find the suspicious accounts:** Admin → Vote holds → *Shared
   devices*.
   - *Same device*: one browser on one phone or computer, recognised by an
     anonymous id in a cookie. Strong evidence, but only from when it was
     added (November 2026).
   - *Same network and browser*: the same internet address and the same
     browser details. It covers the primaries, but it's weaker: campus
     Wi-Fi gives many students one address and many phones report the same
     browser, so big groups are often just classmates. Look at small groups
     that signed up minutes apart.
   - A person's page under Users also lists *Seen on the same device*:
     start from an account someone reported and find the others.
   - *No browser details* or *Not a browser* means the sign-ups didn't come
     from a phone's browser but from a script or tool: a strong sign of
     bulk registration.
   - *Locked to class-list email*: signed up with a matric number whose
     class-list row has an email. The account has no usable password; only
     the link sent to that email sets one, so whoever signed up can't use
     it. *Put all on hold* leaves these out, so their owners don't have to
     queue on election day.
   - *Only signed in here*: an account that didn't sign up on that device
     but signed in from it, often the account of whoever ran it.
   - *Export CSV* downloads the report (one row per account, with the
     address and browser details as sent) to show the committee.
2. **Put them on hold**, before the election: *Put all on hold* for a whole
   group, tick some on Shared devices,
   or *Put on hold for voting* on a person's page, with a reason. A held
   account can still sign in, but can't vote.
3. **On election day**, a student whose account is held comes with their
   student ID. Find them under Vote holds, check it's them and *Release*:
   they can vote straight away, in the election that's open too.
4. **Live results** show the eligible voters and, under them, how many
   accounts are still on hold: the most extra ballots there can be.

Every hold and release is in the audit log, with who did it and why.

## Class-email verification (the vote gate)

Holds are reactive: an admin has to spot a suspicious account first. The
gate is proactive. When an election has **require_roll_email** on (the
default for NACOS elections), a ballot is only accepted from an account
that has proved control of the **class-list email on the nominal roll** —
the one address an impersonator can't change. It's the same email the roll
already stores and that invitations go to.

- **Proof** is opening a one-time link emailed to that address. Invitation
  links always go to the roll email, never the account's (an impersonator
  may have changed the account's), so opening one proves it's the real
  student. Acceptance sets `users.roll_email_verified_at`, puts the
  account's email back to the roll's, signs out every other session, and
  **auto-releases a hold** (controlling the class email answers what the
  hold was waiting for, so the hold queue drains itself).
- **Verify / reclaim** (`/verify-account`): a student enters their matric
  number and we email the link to their roll email. This replaces admins
  issuing reset codes one by one, and is how a student takes back an
  account someone registered or voted with.
- **In advance**: *Invitations → Queue verification links* emails the
  students who need it — on the roll with a class email, not verified, and
  never sent a link — sent under the hourly cap. Students who signed up
  through their class email already have a link, and students who already
  confirmed are marked verified, so both are left out; it's really for the
  accounts whose email was only just added to the roll. Expired links are
  re-sent separately from the Expired tab.
- A student with no class email on the roll can't verify remotely; fix
  their email on the roll (a re-upload fills emails without wiping the
  ones already there) and they can.

## What it doesn't do

- **Take back a ballot.** Ballots aren't linked to voters (that's what
  keeps them secret), so an impersonator's ballot can't be found or
  removed, and letting the real student vote again would count both.
  Holds stop it happening; if it already happened, the committee decides
  whether a position must be rerun.
- **Prove anything.** Device and network matches are leads for a person to
  check. Clearing cookies or using a private window gets a new device id.

## How it's built

- `users.vote_held_at`, `vote_hold_reason`, `vote_held_by`
  (`2026_11_18_000100_vote_holds_and_devices`).
  `App\Support\Elections\VoteHolds` places and releases holds and
  recounts open elections.
- `Election::ineligibilityReason` refuses held accounts; `electorate()`
  leaves them out and `heldVoters()` counts them. The live results file
  has `held`. Departments' spaces have no holds.
- `App\Http\Middleware\DeviceId` (web routes) keeps a random id in the
  `nacos_device` cookie for two years. `Audit::record` writes it as
  `audit_logs.device_id`, and `network_key` (a hash of address and browser
  details). The migration filled in `network_key` for sign-ups, sign-ins
  and votes already in the log.
- `App\Support\Elections\SharedDevices` groups accounts by either key;
  `Admin\VoteHoldController` has the pages.
- `users.roll_email_verified_at`, `elections.require_roll_email`
  (`2026_11_22_000100_add_roll_email_verification`).
  `Election::needsRollEmailVerification` and `ineligibilityReason` gate the
  vote; `User::hasVerifiedRollEmail`. `RollEntry::emailFor` is the roll
  email; `User::routeNotificationForMail` sends invitation links there.
  `Auth\VerifyAccountController` is the verify/reclaim flow;
  `InvitationController@store` records the proof, resets the email, and
  releases the hold. `AccountController@queueVerifications` is the advance
  send (only never-invited, unverified, on-roll-with-email accounts).
  `RollEmailVerification::backfill` (a migration) marks accounts that
  already confirmed a matching class email as verified. `RollImport::saveClass`
  never wipes an email with a blank cell and reports the reconcile counts.
- Tests: `tests/Feature/Elections/VoteHoldTest.php`,
  `tests/Feature/Elections/RollEmailVerificationTest.php`.
