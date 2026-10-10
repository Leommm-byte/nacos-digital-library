<?php

namespace Tests\Feature\Classes;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Models\Election;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
use App\Support\Elections\RollImport;
use App\Support\Spreadsheets\SpreadsheetRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class ArmsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_course_is_read_from_the_matric_number(): void
    {
        $this->assertSame('swd', Arms::fromMatric('F/HD/24/3211001'));
        $this->assertSame('ncc', Arms::fromMatric('p/hnd/24/3212001'));
        $this->assertNull(Arms::fromMatric('F/HD/24/3215001'));
        // ND isn't split (yet).
        $this->assertNull(Arms::fromMatric('F/ND/24/3211001'));
        $this->assertNull(Arms::fromMatric('not a matric'));

        $this->assertSame('SWD', Arms::short('swd'));
        $this->assertSame('Networking and Cloud Computing', Arms::name('ncc'));
    }

    #[Test]
    public function a_new_course_or_a_split_nd_needs_only_the_config(): void
    {
        config([
            'classes.arms.ai' => ['stage' => 'HND', 'digit' => '3', 'short' => 'AI', 'name' => 'Artificial Intelligence'],
            'classes.arms.nd-a' => ['stage' => 'ND', 'digit' => '5', 'short' => 'A', 'name' => 'ND A'],
        ]);

        $this->assertSame('ai', Arms::fromMatric('F/HD/24/3213001'));
        $this->assertSame('nd-a', Arms::fromMatric('F/ND/24/1115001'));
        $this->assertNotNull(SchoolClass::fromKey('full_time|HND2|ai'));
        $this->assertNull(SchoolClass::fromKey('full_time|ND1|'));
        $this->assertNotNull(SchoolClass::fromKey('full_time|ND1|nd-a'));
    }

    #[Test]
    public function the_school_runs_these_classes(): void
    {
        $labels = array_map(fn (SchoolClass $class) => $class->label(), SchoolClass::all());

        // Full-time 2 + 2×2, part-time 3 + 3×2, CODFEL ND only: 2.
        $this->assertCount(17, $labels);
        $this->assertSame(['ND1 Full-time', 'ND2 Full-time', 'HND1 SWD Full-time', 'HND1 NCC Full-time', 'HND2 SWD Full-time', 'HND2 NCC Full-time'], array_slice($labels, 0, 6));
        $this->assertContains('HND3 NCC Part-time', $labels);
        $this->assertNotContains('ND3 Full-time', $labels);
        $this->assertSame(['ND1 CODFEL', 'ND2 CODFEL'], array_slice($labels, -2));

        $this->assertNull(SchoolClass::fromKey('codfel|HND1|swd'), 'CODFEL has no HND');
        $this->assertNull(SchoolClass::fromKey('full_time|HND1|'), 'HND needs its course');
        $this->assertNull(SchoolClass::fromKey('full_time|ND1|swd'), 'ND has no courses');
        $this->assertNull(SchoolClass::fromKey('full_time|ND3|'), 'full-time ND is two years');
        $this->assertSame('part_time|HND3|ncc', SchoolClass::fromKey('part_time|HND3|ncc')?->key());
        $this->assertSame(2, SchoolClass::years(Programme::Codfel, 'ND'));
        $this->assertNull(SchoolClass::years(Programme::Codfel, 'HND'));
    }

    #[Test]
    public function students_get_their_course_from_their_matric_number(): void
    {
        $swd = User::factory()->create(['matric_number' => 'F/HD/24/3211001', 'level' => Level::HND1, 'programme' => Programme::FullTime]);
        $nd = User::factory()->create(['matric_number' => 'F/ND/24/3212001', 'level' => Level::ND1, 'programme' => Programme::FullTime]);

        $this->assertSame('swd', $swd->arm);
        $this->assertSame('HND1 SWD Full-time', $swd->classLabel());
        $this->assertNull($nd->arm);
        $this->assertSame('ND1 Full-time', $nd->classLabel());

        $this->actingAs($swd)->get(route('profile.edit'))->assertOk()->assertSee('Software and Web Development (SWD)');
        $this->get(route('home'))->assertOk()->assertSee('HND1 SWD');
    }

    #[Test]
    public function swd_and_ncc_have_their_own_rolls(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        RollEntry::create(['matric_number' => 'F/HD/24/3212001', 'fullname' => 'NCC Student', 'programme' => 'full_time', 'level' => 'HND1', 'arm' => 'ncc']);
        RollEntry::create(['matric_number' => 'F/HD/24/3211009', 'fullname' => 'Old SWD', 'programme' => 'full_time', 'level' => 'HND1', 'arm' => 'swd']);

        // Replacing SWD leaves NCC alone.
        $this->actingAs($admin)->post(route('roll.store'), [
            'class' => 'full_time|HND1|swd',
            'roll' => UploadedFile::fake()->createWithContent('swd.csv', "Programme,Full-time\nLevel,HND1\nCourse,SWD\n\nMatric number,Full name\nF/HD/24/3211001,New SWD\n"),
        ])->assertSessionHasNoErrors()->assertSessionHas('import', fn (array $import) => $import['class'] === 'HND1 SWD Full-time' && $import['removed'] === 1);

        $this->assertSame(['F/HD/24/3211001', 'F/HD/24/3212001'], RollEntry::orderBy('matric_number')->pluck('matric_number')->all());
        $this->assertSame('swd', RollEntry::where('matric_number', 'F/HD/24/3211001')->value('arm'));

        // A file for NCC can't go in as SWD.
        $this->post(route('roll.store'), [
            'class' => 'full_time|HND1|swd',
            'roll' => UploadedFile::fake()->createWithContent('ncc.csv', "Course,NCC\n\nMatric number,Full name\nF/HD/24/3212005,Someone\n"),
        ])->assertSessionHasErrors(['roll' => 'This file is for HND1 NCC Full-time, but you chose HND1 SWD Full-time. Check the class or the file.']);

        // An NCC number in an SWD list needs confirming.
        $this->post(route('roll.store'), [
            'class' => 'full_time|HND1|swd',
            'mode' => 'add',
            'roll' => UploadedFile::fake()->createWithContent('swd.csv', "F/HD/24/3212005\n"),
        ])->assertSessionHasErrors('roll')->assertSessionHas('mismatches', ['F/HD/24/3212005']);

        // HND without its course isn't a class.
        $this->post(route('roll.students.store'), ['matric_number' => 'F/HD/24/3211002', 'fullname' => 'Ada', 'class' => 'full_time|HND1|'])
            ->assertSessionHasErrorsIn('student', 'class');

        $this->get(route('roll.index'))->assertOk()
            ->assertSee('HND1 SWD')->assertSee('HND1 NCC')
            ->assertSee('/17', false)
            ->assertSee('value="full_time|HND1|ncc"', false);
    }

    #[Test]
    public function the_template_has_the_course(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $response = $this->actingAs($admin)->get(route('roll.template', ['class' => 'part_time|HND2|ncc']));

        $response->assertOk()->assertDownload('nominal-roll-hnd2-ncc-part-time.xlsx');
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $parsed = RollImport::parse(SpreadsheetRows::xlsx($file->getFile()->getPathname()));

        $this->assertSame(Programme::PartTime, $parsed['programme']);
        $this->assertSame(Level::HND2, $parsed['level']);
        $this->assertSame('ncc', $parsed['arm']);
    }

    #[Test]
    public function elections_can_be_limited_to_a_course(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->get(route('elections.manage.create'))->assertSee('name="arms[]" value="ncc"', false);
        $this->post(route('elections.manage.store'), ['title' => 'NCC rep', 'roll_only' => '1', 'levels' => ['HND1'], 'arms' => ['ncc']])->assertSessionHasNoErrors();
        $this->post(route('elections.manage.store'), ['title' => 'Bad', 'arms' => ['nope']])->assertSessionHasErrors('arms.0');

        $draft = Election::where('title', 'NCC rep')->firstOrFail();
        $this->assertSame(['ncc'], $draft->arms);
        $this->assertSame('HND1 NCC students, on the nominal roll', $draft->eligibilitySummary());

        $election = Election::factory()->open()->withBallot(['Rep' => 1])->create(['roll_only' => true, 'arms' => ['ncc']]);
        $ncc = User::factory()->create(['matric_number' => 'F/HD/24/3212001', 'level' => Level::HND1]);
        $swd = User::factory()->create(['matric_number' => 'F/HD/24/3211001', 'level' => Level::HND1]);
        $nd = User::factory()->create(['matric_number' => 'F/ND/24/3212002', 'level' => Level::ND1]);
        foreach ([$ncc, $swd, $nd] as $user) {
            RollEntry::create(['matric_number' => $user->matric_number, 'level' => $user->level, 'programme' => $user->programme, 'arm' => $user->arm]);
        }

        $this->assertTrue($election->isEligible($ncc));
        $this->assertFalse($election->isEligible($swd));
        $this->assertFalse($election->isEligible($nd));
        $this->assertSame([$ncc->id], $election->electorate()->pluck('users.id')->all());
    }
}
