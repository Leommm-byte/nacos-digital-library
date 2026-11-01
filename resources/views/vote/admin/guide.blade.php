@php($space = preg_replace('#^https?://#', '', route('vote.home')))

<x-layouts.vote-admin title="Guide">
    <x-page-header title="Running your election" subtitle="Everything an election admin does, in order. Share the address {{ $space }} with your students." />

    <ol class="guide-steps space-y-6">
        <li>
            <x-section title="1. Upload the nominal roll" icon="clipboard-list" tone="green">
                <p>Only students on the roll can register and vote. Under <a href="{{ route('vote.admin.roll') }}" class="link">Nominal roll</a>, download the template, fill in one class (programme and level at the top, then each student's matric number, full name and email), and upload it. Upload each class the same way.</p>
                <p class="mt-2 text-muted">Uploading a class again replaces that class only. Add a late student with "Add one student". The roll locks while voting is open, so finish it first.</p>
            </x-section>
        </li>
        <li>
            <x-section title="2. Get students to register" icon="user-plus" tone="blue">
                <p>Share <strong>{{ $space }}</strong>. Students tap "Register to vote" and enter their matric number, name, email and a password.</p>
                <p class="mt-2 text-muted">Ask them to register well before voting opens: by default, accounts made after voting opens can't vote in that election. <a href="{{ route('vote.admin.voters') }}" class="link">Voters</a> shows how many of the roll have registered.</p>
            </x-section>
        </li>
        <li>
            <x-section title="3. Set up the election" icon="vote" tone="violet">
                <p>Under <a href="{{ route('vote.admin.elections') }}" class="link">Elections</a>, create the election and choose who can vote (programmes and levels; leave them empty for everyone on the roll). Add each position and its candidates, with photos and a short manifesto if you like.</p>
                <p class="mt-2 text-muted">Nothing is public until you open voting. After that, positions and candidates can't change.</p>
            </x-section>
        </li>
        <li>
            <x-section title="4. Open voting" icon="rocket" tone="green">
                <p>On the election's page, choose how long voting lasts and press "Open voting". Students log in and vote; each student votes once, and nobody can see who they chose.</p>
                <p class="mt-2 text-muted">The live results update on their own. Voting closes by itself when the time is up, or press "Stop voting now" to end it early (it can't be undone).</p>
            </x-section>
        </li>
        <li>
            <x-section title="5. Results" icon="trophy" tone="yellow">
                <p>When voting closes, the final results with the winners appear for everyone. On the election's page, download them as a spreadsheet (CSV) or print them (or save them as a PDF) for your records.</p>
            </x-section>
        </li>
        <li>
            <x-section title="Helping students" icon="key-round" tone="blue">
                <ul class="list-disc space-y-1 pl-5">
                    <li><strong>Forgot password:</strong> students with an email reset it themselves ("Forgot password?" on the login page). Otherwise, find them under Voters and press "New password", then give them the slip.</li>
                    <li><strong>"Not on the nominal roll":</strong> check their matric number under Nominal roll and add them (before voting opens).</li>
                    <li><strong>"Account created after voting opened":</strong> they registered too late for this election.</li>
                    <li><strong>Someone misusing an account:</strong> suspend it under Voters.</li>
                </ul>
                <p class="mt-3 text-muted">Stuck? Contact NACOS YabaTech: we run this space with you.</p>
            </x-section>
        </li>
    </ol>
</x-layouts.vote-admin>
