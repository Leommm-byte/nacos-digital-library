<?php

namespace Tests\Feature\Timetables;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Models\Exam;
use App\Models\User;
use App\Support\Spreadsheets\SpreadsheetRows;
use App\Support\Timetables\ExamImport;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02 08:00', 'Africa/Lagos'));
        $this->student = User::factory()->create([
            'level' => Level::HND1,
            'programme' => Programme::FullTime,
            'matric_number' => UserFactory::matricFor(Level::HND1, Programme::FullTime, 'swd'),
        ]);
        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function exam(string $code, string $date, array $attributes = []): Exam
    {
        return Exam::create(['date' => $date, 'starts_at' => '09:00', 'ends_at' => '12:00', 'course_code' => $code, 'venue' => 'Hall A', ...$attributes]);
    }

    #[Test]
    public function students_see_their_own_papers_first_and_can_see_everyones(): void
    {
        $this->exam('SWD 311', '2026-11-03', ['levels' => ['HND1'], 'arms' => ['swd'], 'course_title' => 'Web Apps', 'note' => 'Bring your ID']);
        $this->exam('NCC 311', '2026-11-03', ['levels' => ['HND1'], 'arms' => ['ncc']]);
        $this->exam('GNS 301', '2026-11-05');
        $this->exam('COM 111', '2026-11-04', ['levels' => ['ND1']]);
        $this->exam('OLD 101', '2026-10-30');

        $this->get(route('exams.index'))->assertRedirect(route('login'));

        $this->actingAs($this->student)->get(route('exams.index'))->assertOk()
            ->assertSeeInOrder(['HND1 SWD', 'SWD 311', 'Web Apps', 'Hall A', 'Bring your ID', 'Everyone', 'GNS 301'])
            ->assertSee('in 1 day')
            ->assertDontSee('NCC 311')->assertDontSee('COM 111')->assertDontSee('OLD 101')
            ->assertSee('Show past papers')
            ->assertDontSee('Manage');

        $this->get(route('exams.index', ['show' => 'all']))->assertOk()
            ->assertSeeInOrder(['NCC 311', 'SWD 311', 'COM 111', 'GNS 301'])
            ->assertDontSee('OLD 101');

        $this->get(route('exams.index', ['show' => 'all', 'past' => 1]))->assertSee('OLD 101');
    }

    #[Test]
    public function an_empty_timetable_says_so(): void
    {
        $this->actingAs($this->student)->get(route('exams.index'))->assertOk()->assertSee('No exams on the timetable');
    }

    #[Test]
    public function only_admins_manage_the_exam_timetable(): void
    {
        $governor = User::factory()->role(Role::Governor)->create();
        $exam = $this->exam('GNS 301', '2026-11-05');

        $this->actingAs($governor);
        $this->get(route('exams.manage'))->assertForbidden();
        $this->post(route('exams.store'), ['date' => '2026-11-06', 'starts_at' => '09:00', 'course_code' => 'X 1'])->assertForbidden();
        $this->delete(route('exams.destroy', $exam))->assertForbidden();
        $this->get(route('exams.template'))->assertForbidden();

        $this->actingAs($this->admin);
        $this->get(route('exams.manage'))->assertOk()->assertSee('GNS 301')->assertSee('Add a paper');

        $this->post(route('exams.store'), [
            'date' => '2026-11-06', 'starts_at' => '09:00', 'ends_at' => '11:00', 'course_code' => 'ncc 312',
            'levels' => ['HND1'], 'arms' => ['ncc'],
        ])->assertSessionHasNoErrors();
        $added = Exam::where('course_code', 'NCC 312')->firstOrFail();
        $this->assertSame(['HND1'], $added->levels);
        $this->assertSame('HND1 NCC', $added->audience());
        $this->assertDatabaseHas('audit_logs', ['action' => 'exam_added', 'user_id' => $this->admin->id]);

        $this->put(route('exams.update', $added), ['date' => '2026-11-06', 'starts_at' => '10:00', 'ends_at' => '09:00', 'course_code' => 'NCC 312'])
            ->assertSessionHasErrorsIn('exam-'.$added->id, 'ends_at');
        $this->put(route('exams.update', $added), ['date' => '2026-11-07', 'starts_at' => '10:00', 'course_code' => 'NCC 312'])->assertSessionHasNoErrors();
        $added = Exam::findOrFail($added->id);
        $this->assertNull($added->levels);
        $this->assertSame('Everyone', $added->audience());

        $this->delete(route('exams.destroy', $exam))->assertRedirect(route('exams.manage'));
        $this->assertModelMissing($exam);
    }

    #[Test]
    public function the_exam_timetable_is_uploaded_from_a_spreadsheet(): void
    {
        $this->exam('OLD 101', '2026-11-03');
        $csv = implode("\n", [
            'Date,Start,End,Course code,Course title,Venue,For,Note',
            '3/11/2026,9:00 am,12:00 pm,csc 201,Programming,Hall A,ND2,',
            ',2:00,4:00,NCC 311,Networks,Lab 3,HND1 NCC,Bring your ID',
            'Thursday 5th November 2026,10:00,,GNS 301,English,Hall C,,',
            '46335,9:00,11:00,SWD 311,Web Apps,Lab 2,"Full-time HND1 SWD",',
            'not a date,9:00,10:00,BAD 101,,,,',
            '6/11/2026,9:00,10:00,BAD 102,,,Year 9,',
        ]);

        $this->actingAs($this->admin)->post(route('exams.import'), ['exams' => UploadedFile::fake()->createWithContent('exams.csv', $csv)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import', fn (array $import) => $import['count'] === 4 && $import['skippedCount'] === 2);

        $exams = Exam::current()->get();
        $this->assertSame(['CSC 201', 'NCC 311', 'GNS 301', 'SWD 311'], $exams->pluck('course_code')->all());
        $this->assertSame(['2026-11-03', '2026-11-03', '2026-11-05', '2026-11-09'], $exams->map(fn (Exam $exam) => $exam->date->toDateString())->all());
        $this->assertSame('14:00', $exams[1]->startInput());
        $this->assertSame(['ncc'], $exams[1]->arms);
        $this->assertNull($exams[2]->levels);
        $this->assertNull($exams[2]->ends_at);
        $this->assertSame(['full_time'], $exams[3]->programmes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exams_imported']);
    }

    #[Test]
    public function the_template_reads_back_empty(): void
    {
        $response = $this->actingAs($this->admin)->get(route('exams.template'));

        $response->assertOk()->assertDownload('exam-timetable.xlsx');
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $parsed = ExamImport::parse(SpreadsheetRows::xlsx($file->getFile()->getPathname()));

        $this->assertTrue($parsed['headings']);
        $this->assertSame([], $parsed['exams']);
    }

    #[Test]
    public function the_next_exam_is_on_the_home_page(): void
    {
        $this->exam('SWD 311', '2026-11-04', ['levels' => ['HND1'], 'arms' => ['swd'], 'course_title' => 'Web Apps']);
        $this->exam('NCC 311', '2026-11-03', ['levels' => ['HND1'], 'arms' => ['ncc']]);

        $this->actingAs($this->student)->get(route('home'))->assertOk()
            ->assertSee('Next exam: SWD 311')
            ->assertSee('Wednesday 4 November')
            ->assertDontSee('NCC 311');
    }

    #[Test]
    public function dates_are_read_day_first(): void
    {
        $this->assertSame('2026-11-03', ExamImport::date('03/11/2026')?->toDateString());
        $this->assertSame('2026-11-03', ExamImport::date('2026-11-03')?->toDateString());
        $this->assertSame('2026-11-03', ExamImport::date('Tue, 3rd Nov 2026')?->toDateString());
        $this->assertSame('2026-11-03', ExamImport::date('November 3, 2026')?->toDateString());
        $this->assertSame('2026-11-03', ExamImport::date('46329')?->toDateString());
        $this->assertNull(ExamImport::date('soon'));
        $this->assertSame(['levels' => ['ND1', 'ND2'], 'programmes' => ['part_time'], 'arms' => null], ExamImport::audience('Part time ND1, ND2'));
        $this->assertSame(['levels' => null, 'programmes' => null, 'arms' => null], ExamImport::audience('All'));
        $this->assertNull(ExamImport::audience('Year 9'));
    }
}
