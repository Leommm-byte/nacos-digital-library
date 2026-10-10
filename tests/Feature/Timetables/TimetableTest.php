<?php

namespace Tests\Feature\Timetables;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\Classes\SchoolClass;
use App\Support\Spreadsheets\SpreadsheetRows;
use App\Support\Timetables\TimeOfDay;
use App\Support\Timetables\TimetableImport;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class TimetableTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $nd1;

    private SchoolClass $ncc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nd1 = new SchoolClass(Programme::FullTime, Level::ND1);
        $this->ncc = new SchoolClass(Programme::FullTime, Level::HND1, 'ncc');
    }

    private function student(SchoolClass $class, Role $role = Role::Student): User
    {
        return User::factory()->role($role)->create([
            'level' => $class->level,
            'programme' => $class->programme,
            'matric_number' => UserFactory::matricFor($class->level, $class->programme, $class->arm),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slot(SchoolClass $class, array $attributes = []): TimetableSlot
    {
        $slot = new TimetableSlot([
            'day' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'course_code' => 'COM 111',
            'course_title' => 'Introduction to Computing',
            'venue' => 'Lab 1',
            ...$attributes,
        ]);
        $slot->assignClass($class);
        $slot->save();

        return $slot;
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function lecture(SchoolClass $class, array $overrides = []): array
    {
        return ['class' => $class->key(), 'day' => '2', 'starts_at' => '09:00', 'ends_at' => '11:00', 'course_code' => 'csc  201', 'course_title' => 'Programming', 'lecturer' => '', 'venue' => 'Lab 2', ...$overrides];
    }

    #[Test]
    public function students_see_only_their_own_class(): void
    {
        $this->slot($this->nd1, ['course_code' => 'COM 111']);
        $this->slot($this->ncc, ['course_code' => 'NCC 311']);
        $student = $this->student($this->nd1);

        $this->get(route('timetable.show'))->assertRedirect(route('login'));

        $this->actingAs($student)->get(route('timetable.show'))->assertOk()
            ->assertSee('ND1 Full-time')
            ->assertSee('COM 111')->assertSee('Lab 1')
            ->assertDontSee('NCC 311')
            ->assertDontSee('Edit timetable');

        // Asking for another class shows their own.
        $this->get(route('timetable.show', ['class' => $this->ncc->key()]))->assertOk()->assertDontSee('NCC 311')->assertSee('COM 111');
        $this->get(route('timetable.edit'))->assertForbidden();
    }

    #[Test]
    public function an_empty_timetable_says_so(): void
    {
        $this->actingAs($this->student($this->nd1))->get(route('timetable.show'))->assertOk()
            ->assertSee('No timetable yet')
            ->assertSee('Your class governor adds it');
    }

    #[Test]
    public function today_shows_what_is_on_now_and_next(): void
    {
        // Monday 9:30 in Lagos.
        $this->travelTo(Carbon::parse('2026-11-02 09:30', 'Africa/Lagos'));
        $this->slot($this->nd1, ['course_code' => 'COM 111', 'starts_at' => '08:00', 'ends_at' => '10:00']);
        $this->slot($this->nd1, ['course_code' => 'MTH 111', 'starts_at' => '10:00', 'ends_at' => '12:00']);
        $this->slot($this->nd1, ['course_code' => 'GNS 101', 'day' => 3]);

        $this->actingAs($this->student($this->nd1))->get(route('timetable.show'))->assertOk()
            ->assertSee('On now')
            ->assertSeeInOrder(['COM 111', 'Until 10:00 am', 'Next', 'MTH 111', 'Today, 10:00 am'])
            ->assertSee('Wednesday');

        // After the last lecture, the next is on Wednesday.
        $this->travelTo(Carbon::parse('2026-11-02 13:00', 'Africa/Lagos'));
        $this->get(route('timetable.show'))->assertSee('Done for today')->assertSeeInOrder(['Next', 'GNS 101', 'Wednesday, 8:00 am']);

        // And on the home page.
        $this->get(route('home'))->assertOk()->assertSee('Today')->assertSee('MTH 111');
    }

    #[Test]
    public function a_governor_keeps_their_own_class_timetable_only(): void
    {
        $governor = $this->student($this->nd1, Role::Governor);
        $this->actingAs($governor);

        $this->get(route('timetable.show'))->assertSee('Edit timetable');
        $this->get(route('timetable.edit'))->assertOk()->assertSee('ND1 Full-time')->assertSee('Add a lecture');

        $this->post(route('timetable.slots.store'), $this->lecture($this->nd1))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('timetable.edit', ['class' => $this->nd1->key()]).'#day-2');

        $slot = TimetableSlot::forClass($this->nd1)->firstOrFail();
        $this->assertSame('CSC 201', $slot->course_code);
        $this->assertNull($slot->lecturer);
        $this->assertSame($governor->id, $slot->updated_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'timetable_slot_added', 'user_id' => $governor->id]);

        $this->put(route('timetable.slots.update', $slot), [...$this->lecture($this->nd1), 'ends_at' => '12:00', 'venue' => 'LT 1'])->assertSessionHasNoErrors();
        $this->assertSame('LT 1', TimetableSlot::findOrFail($slot->id)->venue);

        // Ending before it starts is refused.
        $this->put(route('timetable.slots.update', $slot), [...$this->lecture($this->nd1), 'ends_at' => '08:00'])
            ->assertSessionHasErrorsIn('slot-'.$slot->id, 'ends_at');

        // Another class's timetable is off limits.
        $other = $this->slot($this->ncc);
        $this->post(route('timetable.slots.store'), $this->lecture($this->ncc))->assertForbidden();
        $this->put(route('timetable.slots.update', $other), $this->lecture($this->ncc))->assertForbidden();
        $this->delete(route('timetable.slots.destroy', $other))->assertForbidden();

        $this->delete(route('timetable.slots.destroy', $slot))->assertRedirect();
        $this->assertModelMissing($slot);
    }

    #[Test]
    public function students_and_course_reps_cannot_edit(): void
    {
        foreach ([Role::Student, Role::CourseRep] as $role) {
            $this->actingAs($this->student($this->nd1, $role));
            $this->get(route('timetable.edit'))->assertForbidden();
            $this->post(route('timetable.slots.store'), $this->lecture($this->nd1))->assertForbidden();
        }

        $this->assertSame(0, TimetableSlot::count());
    }

    #[Test]
    public function admins_see_and_keep_every_class(): void
    {
        $this->slot($this->ncc, ['course_code' => 'NCC 311']);
        $admin = $this->student($this->nd1, Role::Admin);
        $this->actingAs($admin);

        $this->get(route('timetable.show', ['class' => $this->ncc->key()]))->assertOk()
            ->assertSee('HND1 NCC Full-time')->assertSee('NCC 311')
            ->assertSee('value="'.$this->ncc->key().'"', false);

        $this->get(route('timetable.edit', ['class' => $this->ncc->key()]))->assertOk()->assertSee('Class timetables');
        $this->post(route('timetable.slots.store'), $this->lecture($this->ncc))->assertSessionHasNoErrors();
        $this->assertSame(2, TimetableSlot::forClass($this->ncc)->count());

        // A class the school doesn't run.
        $this->post(route('timetable.slots.store'), $this->lecture($this->nd1, ['class' => 'codfel|HND1|swd']))->assertNotFound();
    }

    #[Test]
    public function archived_lectures_are_not_shown_or_editable(): void
    {
        $slot = $this->slot($this->nd1, ['course_code' => 'OLD 101']);
        $slot->forceFill(['archived_at' => now()])->save();
        $this->actingAs($this->student($this->nd1, Role::Governor));

        $this->get(route('timetable.show'))->assertDontSee('OLD 101');
        $this->delete(route('timetable.slots.destroy', $slot))->assertNotFound();
    }

    #[Test]
    public function the_week_is_uploaded_from_a_spreadsheet(): void
    {
        $this->slot($this->nd1, ['course_code' => 'OLD 101']);
        $governor = $this->student($this->nd1, Role::Governor);
        $csv = implode("\n", [
            'Class,ND1 Full-time',
            '',
            'Day,Start,End,Course code,Course title,Lecturer,Venue',
            'Monday,8:00,10:00,com 111,Introduction to Computing,Dr. Bello,Lab 1',
            ',10:00,12:00,MTH 111,Algebra,,LT 2',
            'Tue,2pm,4:00 pm,GNS 101,Use of English,,Hall B',
            'Wednesday,9:00,8:00,BAD 101,Ends before it starts,,',
            'Sunday,9:00,10:00,SUN 101,No lectures on Sunday,,',
        ]);

        $this->actingAs($governor)->post(route('timetable.import'), [
            'class' => $this->nd1->key(),
            'timetable' => UploadedFile::fake()->createWithContent('week.csv', $csv),
        ])->assertSessionHasNoErrors()->assertSessionHas('import', fn (array $import) => $import['count'] === 3 && $import['skippedCount'] === 2);

        $slots = TimetableSlot::forClass($this->nd1)->get();
        $this->assertSame(['COM 111', 'MTH 111', 'GNS 101'], $slots->pluck('course_code')->all());
        $this->assertSame([1, 1, 2], $slots->pluck('day')->all());
        $this->assertSame('14:00', $slots[2]->startInput());
        $this->assertDatabaseHas('audit_logs', ['action' => 'timetable_imported']);

        // A file for another class is refused.
        $this->post(route('timetable.import'), [
            'class' => $this->nd1->key(),
            'timetable' => UploadedFile::fake()->createWithContent('ncc.csv', "Class,HND1 NCC Full-time\nDay,Time,Course\nMonday,8-10am,NCC 311\n"),
        ])->assertSessionHasErrorsIn('import', 'timetable');
        $this->assertSame(3, TimetableSlot::forClass($this->nd1)->count());
    }

    #[Test]
    public function the_template_comes_with_the_class(): void
    {
        $this->actingAs($this->student($this->ncc, Role::Governor));
        $response = $this->get(route('timetable.template'));

        $response->assertOk()->assertDownload('timetable-hnd1-ncc-full-time.xlsx');
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $parsed = TimetableImport::parse(SpreadsheetRows::xlsx($file->getFile()->getPathname()));

        $this->assertSame($this->ncc->key(), $parsed['class']?->key());
        $this->assertTrue($parsed['headings']);
        $this->assertSame([], $parsed['slots']);
    }

    #[Test]
    public function times_are_read_as_timetables_write_them(): void
    {
        $this->assertSame('08:00', TimeOfDay::parse('8'));
        $this->assertSame('08:30', TimeOfDay::parse('08.30'));
        $this->assertSame('14:00', TimeOfDay::parse('2:00'));
        $this->assertSame('14:00', TimeOfDay::parse('2pm'));
        $this->assertSame('12:00', TimeOfDay::parse('12:00 PM'));
        $this->assertSame('09:00', TimeOfDay::parse('0.375'));
        $this->assertNull(TimeOfDay::parse('25:00'));
        $this->assertNull(TimeOfDay::parse('noon'));
        $this->assertSame(['08:00', '10:00'], TimeOfDay::range('8-10am'));
        $this->assertSame(['11:00', '13:00'], TimeOfDay::range('11 - 1pm'));
        $this->assertSame(['14:00', '16:00'], TimeOfDay::range('2:00 – 4:00'));
        $this->assertSame('2:30 pm', TimeOfDay::format('14:30:00'));
        $this->assertSame(4, TimetableSlot::dayFrom('thurs'));
        $this->assertNull(TimetableSlot::dayFrom('Sunday'));
    }
}
