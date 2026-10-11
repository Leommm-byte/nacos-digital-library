<?php

namespace Tests\Feature\Admin;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Http\Controllers\Timetables\ExamController;
use App\Models\Book;
use App\Models\Election;
use App\Models\Exam;
use App\Models\RollEntry;
use App\Models\SessionChange;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\Classes\SchoolClass;
use App\Support\Sessions\NewSession;
use App\Support\Sessions\ProgrammeSessions;
use App\Support\Uploads\UploadLimits;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NewSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->student(Programme::FullTime, Level::HND1, 'swd', Role::Admin);
        // Each programme keeps its own calendar.
        ProgrammeSessions::setSession(Programme::FullTime, '2026/2027');
        ProgrammeSessions::setSession(Programme::PartTime, '2025/2026');
        ProgrammeSessions::setExamsPublished(Programme::FullTime, true);
        ProgrammeSessions::setExamsPublished(Programme::PartTime, true);
    }

    private function student(Programme $programme, Level $level, ?string $arm = null, Role $role = Role::Student): User
    {
        return User::factory()->role($role)->create([
            'level' => $level,
            'programme' => $programme,
            'matric_number' => UserFactory::matricFor($level, $programme, $arm),
        ]);
    }

    private function start(string $session = '2027/2028', Programme $programme = Programme::FullTime): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.session.start'), ['programme' => $programme->value, 'to_session' => $session, 'confirm' => $session])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.session', ['programme' => $programme->value]));
    }

    #[Test]
    public function students_move_up_by_their_programmes_length(): void
    {
        $cases = [
            [Programme::FullTime, Level::ND1, Level::ND2],
            [Programme::FullTime, Level::ND2, null],
            [Programme::FullTime, Level::HND1, Level::HND2],
            [Programme::FullTime, Level::HND2, null],
            [Programme::PartTime, Level::ND2, Level::ND3],
            [Programme::PartTime, Level::ND3, null],
            [Programme::PartTime, Level::HND2, Level::HND3],
            [Programme::PartTime, Level::HND3, null],
            [Programme::Codfel, Level::ND1, Level::ND2],
            [Programme::Codfel, Level::ND2, null],
            // Not a class the school runs: they leave.
            [Programme::FullTime, Level::ND3, null],
            [Programme::Codfel, Level::HND1, null],
        ];

        foreach ($cases as [$programme, $from, $to]) {
            $this->assertSame($to, NewSession::nextLevel($programme, $from), "{$programme->value} {$from->value}");
        }

        $this->assertSame('2027/2028', NewSession::nextSession('2026/2027'));
    }

    #[Test]
    public function only_admins_start_a_new_session(): void
    {
        $governor = $this->student(Programme::FullTime, Level::ND1, null, Role::Governor);

        $this->actingAs($governor)->get(route('admin.session'))->assertForbidden();
        $this->post(route('admin.session.start'), ['programme' => 'full_time', 'to_session' => '2027/2028', 'confirm' => '2027/2028'])->assertForbidden();
        $this->assertSame(0, SessionChange::count());
    }

    #[Test]
    public function the_preview_shows_every_class_move(): void
    {
        $this->student(Programme::FullTime, Level::ND1);
        $this->student(Programme::FullTime, Level::ND2);
        $this->student(Programme::FullTime, Level::HND1, 'ncc');
        $this->student(Programme::PartTime, Level::ND3);
        RollEntry::create(['matric_number' => 'F/ND/24/0000001', 'programme' => 'full_time', 'level' => 'ND1']);
        RollEntry::create(['matric_number' => 'P/ND/24/0000002', 'programme' => 'part_time', 'level' => 'ND3']);

        $this->actingAs($this->admin)->get(route('admin.session'))->assertOk()
            ->assertSee('Current session: 2026/2027')
            ->assertSee('Start Full-time 2027/2028')
            ->assertDontSee('ND3 Part-time')
            ->assertSeeInOrder(['ND1 Full-time', 'ND2 Full-time', 'ND2 Full-time', 'Graduate', 'HND1 SWD Full-time', 'HND2 SWD Full-time', 'HND1 NCC Full-time', 'HND2 NCC Full-time']);

        $preview = NewSession::preview(Programme::FullTime);
        $this->assertSame(3, $preview['promoted']);
        $this->assertSame(1, $preview['graduated']);
        $this->assertSame(1, $preview['roll']);

        // Part-time is a session behind, on its own tab.
        $this->get(route('admin.session', ['programme' => 'part_time']))->assertOk()
            ->assertSee('Current session: 2025/2026')
            ->assertSee('Start Part-time 2026/2027')
            ->assertSee('ND3 Part-time');
    }

    #[Test]
    public function starting_a_session_promotes_graduates_and_starts_afresh(): void
    {
        $nd1 = $this->student(Programme::FullTime, Level::ND1);
        $nd2 = $this->student(Programme::FullTime, Level::ND2);
        $governor = $this->student(Programme::FullTime, Level::HND1, 'ncc', Role::Governor);
        $partTimeRep = $this->student(Programme::PartTime, Level::ND2, null, Role::CourseRep);
        $finalRep = $this->student(Programme::FullTime, Level::HND2, 'swd', Role::CourseRep);
        $old = $this->student(Programme::FullTime, Level::ND2);
        $old->forceFill(['graduated_at' => now()->subYear()])->save();

        RollEntry::create(['matric_number' => $nd1->matric_number, 'fullname' => 'ND1 Student', 'programme' => 'full_time', 'level' => 'ND1']);
        RollEntry::create(['matric_number' => $partTimeRep->matric_number, 'fullname' => 'Part Timer', 'programme' => 'part_time', 'level' => 'ND2']);
        $slot = new TimetableSlot(['day' => 1, 'starts_at' => '08:00', 'ends_at' => '10:00', 'course_code' => 'COM 111']);
        $slot->assignClass(new SchoolClass(Programme::FullTime, Level::ND1));
        $slot->save();
        $partTimeSlot = new TimetableSlot(['day' => 1, 'starts_at' => '17:00', 'ends_at' => '19:00', 'course_code' => 'COM 211']);
        $partTimeSlot->assignClass(new SchoolClass(Programme::PartTime, Level::ND2));
        $partTimeSlot->save();
        $exam = Exam::create(['programme' => Programme::FullTime, 'date' => now()->addWeek()->toDateString(), 'starts_at' => '09:00', 'course_code' => 'COM 111']);
        $partTimeExam = Exam::create(['programme' => Programme::PartTime, 'date' => now()->addWeek()->toDateString(), 'starts_at' => '17:00', 'course_code' => 'COM 211']);

        $this->start();

        $this->assertSame(Level::ND2, User::findOrFail($nd1->id)->level);
        $this->assertTrue(User::findOrFail($nd2->id)->isGraduated());
        $this->assertSame(Level::HND2, User::findOrFail($governor->id)->level);
        $this->assertSame(Role::Governor, User::findOrFail($governor->id)->role);
        // Part-time is on its own calendar: untouched.
        $this->assertSame(Level::ND2, User::findOrFail($partTimeRep->id)->level);
        // A graduating course rep no longer has a class to represent.
        $this->assertTrue(User::findOrFail($finalRep->id)->isGraduated());
        $this->assertSame(Role::Student, User::findOrFail($finalRep->id)->role);
        // The admin moved up too, and is still an admin.
        $this->assertSame(Level::HND2, User::findOrFail($this->admin->id)->level);
        $this->assertSame(Role::Admin, User::findOrFail($this->admin->id)->role);
        // Already graduated: untouched.
        $this->assertSame(Level::ND2, User::findOrFail($old->id)->level);

        $this->assertSame(['Part Timer'], RollEntry::pluck('fullname')->all());
        $this->assertNotNull(TimetableSlot::findOrFail($slot->id)->archived_at);
        $this->assertNull(TimetableSlot::findOrFail($partTimeSlot->id)->archived_at);
        $this->assertNotNull(Exam::findOrFail($exam->id)->archived_at);
        $this->assertNull(Exam::findOrFail($partTimeExam->id)->archived_at);
        $this->assertSame('2027/2028', ProgrammeSessions::session(Programme::FullTime));
        $this->assertSame('2025/2026', ProgrammeSessions::session(Programme::PartTime));
        $this->assertFalse(ExamController::published(Programme::FullTime));
        $this->assertTrue(ExamController::published(Programme::PartTime));
        $this->assertDatabaseHas('audit_logs', ['action' => 'session_started', 'user_id' => $this->admin->id]);

        $this->get(route('admin.session'))->assertSee('Full-time 2027/2028 has started')->assertSee('Undo');

        // Part-time's turn, later.
        $this->start('2026/2027', Programme::PartTime);
        $this->assertSame(Level::ND3, User::findOrFail($partTimeRep->id)->level);
        $this->assertSame(Role::CourseRep, User::findOrFail($partTimeRep->id)->role);
        $this->assertSame(0, RollEntry::count());
        $this->assertNotNull(TimetableSlot::findOrFail($partTimeSlot->id)->archived_at);
        $this->assertSame('2026/2027', ProgrammeSessions::session(Programme::PartTime));
        // Full-time students don't move twice.
        $this->assertSame(Level::ND2, User::findOrFail($nd1->id)->level);
    }

    #[Test]
    public function it_needs_the_session_typed_and_no_voting_open(): void
    {
        $nd1 = $this->student(Programme::FullTime, Level::ND1);
        $this->actingAs($this->admin);

        $this->post(route('admin.session.start'), ['programme' => 'full_time', 'to_session' => '2027/2028', 'confirm' => '2027'])->assertSessionHasErrors('confirm');
        $this->post(route('admin.session.start'), ['programme' => 'full_time', 'to_session' => 'next year', 'confirm' => 'next year'])->assertSessionHasErrors('to_session');
        $this->post(route('admin.session.start'), ['programme' => 'evening', 'to_session' => '2027/2028', 'confirm' => '2027/2028'])->assertSessionHasErrors('programme');

        Election::factory()->open()->withBallot()->create(['title' => 'Class Rep Election']);
        $this->get(route('admin.session'))->assertSee('Voting is open in Class Rep Election');
        $this->post(route('admin.session.start'), ['programme' => 'full_time', 'to_session' => '2027/2028', 'confirm' => '2027/2028'])->assertSessionHasErrors('to_session');

        // An election for part-time only doesn't hold up full-time.
        Election::query()->update(['programmes' => json_encode(['part_time'])]);
        $this->assertNull(NewSession::blocker(Programme::FullTime));
        $this->assertNotNull(NewSession::blocker(Programme::PartTime));

        $this->assertSame(Level::ND1, User::findOrFail($nd1->id)->level);
        $this->assertSame(0, SessionChange::count());
    }

    #[Test]
    public function it_can_be_undone_until_a_new_roll_is_uploaded(): void
    {
        $nd1 = $this->student(Programme::FullTime, Level::ND1);
        $nd2 = $this->student(Programme::FullTime, Level::ND2, null, Role::CourseRep);
        $moved = $this->student(Programme::FullTime, Level::ND1);
        RollEntry::create(['matric_number' => $nd1->matric_number, 'fullname' => 'ND1 Student', 'programme' => 'full_time', 'level' => 'ND1']);
        $slot = new TimetableSlot(['day' => 1, 'starts_at' => '08:00', 'ends_at' => '10:00', 'course_code' => 'COM 111']);
        $slot->assignClass(new SchoolClass(Programme::FullTime, Level::ND1));
        $slot->save();

        $this->start();
        $change = SessionChange::firstOrFail();

        // An admin corrects someone's class in between: undo keeps it.
        User::findOrFail($moved->id)->forceFill(['level' => Level::HND1])->save();

        $this->post(route('admin.session.undo', $change))->assertRedirect(route('admin.session', ['programme' => 'full_time']));

        $this->assertSame(Level::ND1, User::findOrFail($nd1->id)->level);
        $back = User::findOrFail($nd2->id);
        $this->assertFalse($back->isGraduated());
        $this->assertSame(Role::CourseRep, $back->role);
        $this->assertSame(Level::HND1, User::findOrFail($moved->id)->level);
        $this->assertSame(['ND1 Student'], RollEntry::pluck('fullname')->all());
        $this->assertNull(TimetableSlot::findOrFail($slot->id)->archived_at);
        $this->assertSame('2026/2027', ProgrammeSessions::session(Programme::FullTime));
        $this->assertTrue(ExamController::published(Programme::FullTime));
        $this->assertNotNull(SessionChange::findOrFail($change->id)->undone_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session_undone']);

        // Again, then a roll is uploaded: no more undo.
        $this->start();
        $second = SessionChange::latest('id')->firstOrFail();
        RollEntry::create(['matric_number' => 'F/ND/26/0000009', 'programme' => 'full_time', 'level' => 'ND1']);
        // (The start's success message still shows; the undo card doesn't.)
        $this->get(route('admin.session'))->assertDontSee('Possible until the first new Full-time roll is uploaded');
        $this->post(route('admin.session.undo', $second))->assertSessionHasErrors('undo');
        $this->assertSame(Level::ND2, User::findOrFail($nd1->id)->level);
    }

    #[Test]
    public function graduates_read_but_cannot_vote_or_upload(): void
    {
        $graduate = $this->student(Programme::FullTime, Level::ND2);
        $this->start();
        $graduate = User::findOrFail($graduate->id);
        $book = Book::factory()->approved()->withPdf()->create();

        $election = Election::factory()->open()->withBallot(['President' => 1])->create(['roll_only' => false]);
        $this->assertSame('You\'ve graduated, so you can\'t vote in student elections.', $election->ineligibilityReason($graduate));
        $this->assertSame(0, $election->electorate()->whereKey($graduate->id)->count());
        $this->assertNotNull(UploadLimits::blockReason($graduate));
        $this->assertNull(UploadLimits::blockReason(User::findOrFail($this->admin->id)));

        $this->actingAs($graduate);
        $this->get(route('books.read', $book))->assertOk();
        $this->get(route('uploads.create'))->assertSee('You\'ve graduated, so you can no longer upload');
        $this->get(route('timetable.show'))->assertSee('You\'ve graduated');
        $this->get(route('home'))->assertSee('Graduate');

        // Their class is fixed.
        $this->put(route('profile.update'), [
            'fullname' => $graduate->fullname,
            'department_id' => $graduate->department_id,
            'level' => 'ND1',
            'programme' => 'full_time',
        ])->assertSessionHasErrors('level');

        // An admin saving their class makes them a current student again.
        $this->actingAs($this->admin)->get(route('admin.users.index', ['status' => 'graduated']))->assertSee($graduate->fullname);
        $this->put(route('admin.users.class', $graduate), ['level' => 'HND1', 'programme' => 'full_time', 'department_id' => $graduate->department_id])
            ->assertSessionHasNoErrors();
        $this->assertFalse(User::findOrFail($graduate->id)->isGraduated());
    }
}
