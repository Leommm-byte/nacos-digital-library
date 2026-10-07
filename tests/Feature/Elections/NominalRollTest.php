<?php

namespace Tests\Feature\Elections;

use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\Role;
use App\Models\Election;
use App\Models\RollEntry;
use App\Models\User;
use App\Support\Elections\RollImport;
use App\Support\Spreadsheets\SpreadsheetRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class NominalRollTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    private function csv(string $content, string $name = 'roll.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * A small Excel file, as Excel saves it (shared strings).
     *
     * @param  list<list<string>>  $rows
     */
    private function xlsx(array $rows): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $strings = [];
        $sheet = '';

        foreach ($rows as $r => $cells) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($cells as $c => $value) {
                $strings[] = '<si><t>'.htmlspecialchars($value, ENT_XML1).'</t></si>';
                $sheet .= '<c r="'.chr(65 + $c).($r + 1).'" t="s"><v>'.(count($strings) - 1).'</v></c>';
            }
            $sheet .= '</row>';
        }

        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', "<workbook {$ns}><sheets><sheet name=\"Roll\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>");
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', $strings).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', "<worksheet {$ns}><sheetData>{$sheet}</sheetData></worksheet>");
        $zip->close();

        return UploadedFile::fake()->createWithContent('hnd1-full-time.xlsx', (string) file_get_contents($path));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function upload(UploadedFile $file, array $overrides = []): array
    {
        return ['programme' => 'full_time', 'level' => 'HND1', 'roll' => $file, ...$overrides];
    }

    #[Test]
    public function only_admins_see_and_upload_the_roll(): void
    {
        foreach ([Role::Student, Role::CourseRep, Role::Governor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get(route('roll.index'))->assertForbidden();
            $this->post(route('roll.store'), $this->upload($this->csv("F/HD/24/1234567\n")))->assertForbidden();
            $this->get(route('roll.template'))->assertForbidden();
        }

        $this->assertSame(0, RollEntry::count());
        $this->actingAs($this->admin)->get(route('roll.index'))->assertOk()->assertSee('The roll is empty')->assertSee('Not uploaded');
    }

    #[Test]
    public function an_excel_class_list_is_uploaded_for_its_class(): void
    {
        $file = $this->xlsx([
            ['Programme', 'Full-time'],
            ['Level', 'HND1'],
            [],
            ['Matric number', 'Full name', 'Email (optional)'],
            ['f/hd/24/2222222', 'OBI  Ada', 'ADA@EXAMPLE.COM'],
            ['not a matric', 'Someone'],
            ['F/HD/24/2222222', 'Duplicate'],
            ['F/HD/23/3333333', 'BELLO Tunde'],
        ]);

        $this->actingAs($this->admin)->post(route('roll.store'), $this->upload($file))
            ->assertRedirect(route('roll.index'))
            ->assertSessionHas('import.total', 2);

        $ada = RollEntry::where('matric_number', 'F/HD/24/2222222')->firstOrFail();
        $this->assertSame('OBI Ada', $ada->fullname);
        $this->assertSame('ada@example.com', $ada->email);
        $this->assertSame(Level::HND1, $ada->level);
        $this->assertSame(Programme::FullTime, $ada->programme);
        $this->assertSame(1, session('import')['skippedCount']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'roll_imported', 'user_id' => $this->admin->id]);

        $this->get(route('roll.index', ['q' => 'tunde']))->assertSee('F/HD/23/3333333')->assertDontSee('F/HD/24/2222222')->assertSee('2 students');
    }

    #[Test]
    public function the_class_in_the_file_must_match_the_one_chosen(): void
    {
        $file = $this->csv("Programme,Part-time\nLevel,ND1\n\nMatric number,Full name\nP/ND/24/0000001,Ada\n");

        $this->actingAs($this->admin)
            ->post(route('roll.store'), $this->upload($file))
            ->assertSessionHasErrors(['roll' => 'This file is for ND1 Part-time, but you chose HND1 Full-time. Check the class or the file.']);

        $this->assertSame(0, RollEntry::count());
    }

    #[Test]
    public function matric_numbers_from_another_kind_of_class_need_confirming(): void
    {
        // ND part-time numbers in an HND1 full-time list.
        $content = "Matric number,Full name\nF/HD/24/0000001,Right\nP/ND/24/0000002,Wrong\n";

        $this->actingAs($this->admin)
            ->post(route('roll.store'), $this->upload($this->csv($content)))
            ->assertSessionHasErrors('roll')
            ->assertSessionHas('mismatches', ['P/ND/24/0000002']);
        $this->assertSame(0, RollEntry::count());

        $this->post(route('roll.store'), $this->upload($this->csv($content), ['confirm' => '1']))->assertSessionHasNoErrors();
        $this->assertSame(2, RollEntry::count());
    }

    #[Test]
    public function uploading_a_class_replaces_that_class_only(): void
    {
        RollEntry::create(['matric_number' => 'F/HD/19/0000001', 'programme' => 'full_time', 'level' => 'HND1']);
        RollEntry::create(['matric_number' => 'F/HD/24/0000002', 'programme' => 'full_time', 'level' => 'HND1']);
        RollEntry::create(['matric_number' => 'F/ND/24/0000003', 'programme' => 'full_time', 'level' => 'ND1']);
        // Listed in HND2 by mistake last time.
        RollEntry::create(['matric_number' => 'F/HD/24/0000004', 'programme' => 'full_time', 'level' => 'HND2']);

        $this->actingAs($this->admin)
            ->post(route('roll.store'), $this->upload($this->csv("F/HD/24/0000002\nF/HD/24/0000004\nF/HD/25/0000005\n")))
            ->assertSessionHas('import', fn (array $import) => $import['total'] === 3 && $import['added'] === 1 && $import['removed'] === 1 && $import['moved'] === 1);

        $this->assertDatabaseMissing('nominal_roll', ['matric_number' => 'F/HD/19/0000001']);
        $this->assertSame(Level::HND1, RollEntry::where('matric_number', 'F/HD/24/0000004')->firstOrFail()->level);
        // Other classes are untouched.
        $this->assertDatabaseHas('nominal_roll', ['matric_number' => 'F/ND/24/0000003', 'level' => 'ND1']);
    }

    #[Test]
    public function bad_files_are_refused(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('roll.store'), $this->upload($this->csv("name,phone\nAda,0803\n")))->assertSessionHasErrors('roll');
        $this->post(route('roll.store'), $this->upload(UploadedFile::fake()->createWithContent('roll.xlsx', 'not a zip')))->assertSessionHasErrors('roll');
        $this->post(route('roll.store'), $this->upload(UploadedFile::fake()->createWithContent('roll.pdf', '%PDF')))->assertSessionHasErrors('roll');
        $this->post(route('roll.store'), ['roll' => $this->csv("F/HD/24/0000001\n")])->assertSessionHasErrors(['programme', 'level']);

        $this->assertSame(0, RollEntry::count());
    }

    #[Test]
    public function the_template_comes_with_the_class_filled_in(): void
    {
        $response = $this->actingAs($this->admin)->get(route('roll.template', ['programme' => 'codfel', 'level' => 'ND2']));

        $response->assertOk()->assertDownload('nominal-roll-nd2-codfel.xlsx');
        $file = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $file);
        $parsed = RollImport::parse(SpreadsheetRows::xlsx($file->getFile()->getPathname()));

        $this->assertSame(Programme::Codfel, $parsed['programme']);
        $this->assertSame(Level::ND2, $parsed['level']);
        $this->assertSame([], $parsed['rows']);
    }

    #[Test]
    public function only_students_on_the_roll_can_vote_and_its_class_counts(): void
    {
        $election = Election::factory()->open()->withBallot(['President' => 1])->create(['roll_only' => true, 'levels' => ['ND2'], 'programmes' => ['full_time']]);
        $position = $election->positions()->firstOrFail();
        $choices = ['choices' => [$position->id => $position->candidates()->firstOrFail()->id]];

        // Says ND2 at signup, but the roll says ND1.
        $claimsNd2 = User::factory()->create(['level' => Level::ND2, 'programme' => Programme::FullTime, 'matric_number' => 'F/ND/24/0000010']);
        RollEntry::create(['matric_number' => 'F/ND/24/0000010', 'level' => 'ND1', 'programme' => 'full_time']);
        // Graduated: not on the roll.
        $graduate = User::factory()->create(['level' => Level::ND2, 'programme' => Programme::FullTime, 'matric_number' => 'F/ND/20/0000011']);
        // Part-time on the roll.
        $partTime = User::factory()->create(['level' => Level::ND2, 'programme' => Programme::FullTime, 'matric_number' => 'P/ND/24/0000013']);
        RollEntry::create(['matric_number' => 'P/ND/24/0000013', 'level' => 'ND2', 'programme' => 'part_time']);
        $current = User::factory()->create(['level' => Level::ND2, 'programme' => Programme::FullTime, 'matric_number' => 'F/ND/24/0000012']);
        RollEntry::create(['matric_number' => 'F/ND/24/0000012', 'level' => 'ND2', 'programme' => 'full_time']);

        $this->assertSame('Full-time ND2 students, on the nominal roll', $election->eligibilitySummary());
        $this->assertSame(1, $election->electorate()->count());

        $this->actingAs($graduate)->get(route('elections.show', $election))->assertSee('isn&#039;t on the current nominal roll', false);
        $this->post(route('elections.vote', $election), $choices)->assertSessionHasErrors('ballot');
        $this->actingAs($claimsNd2)->post(route('elections.vote', $election), $choices)->assertSessionHasErrors('ballot');
        $this->actingAs($partTime)->post(route('elections.vote', $election), $choices)->assertSessionHasErrors('ballot');
        $this->actingAs($current)->post(route('elections.vote', $election), $choices)->assertSessionHasNoErrors();

        $this->assertSame(1, $election->ballotCount());
    }

    #[Test]
    public function an_election_limited_to_the_roll_cannot_launch_while_it_is_empty(): void
    {
        $election = Election::factory()->withBallot(['President' => 1])->create(['roll_only' => true]);

        $this->actingAs($this->admin)
            ->post(route('elections.manage.launch', $election), ['hours' => 6])
            ->assertSessionHasErrors('launch');

        RollEntry::create(['matric_number' => 'F/ND/24/0000001']);
        $this->post(route('elections.manage.launch', $election), ['hours' => 6])->assertSessionHasNoErrors();
    }

    #[Test]
    public function new_elections_are_limited_to_the_roll_by_default(): void
    {
        $this->actingAs($this->admin)->get(route('elections.manage.create'))
            ->assertSee('Only students on the nominal roll')
            ->assertSee('name="roll_only" value="1" class="mt-1 size-4 shrink-0 accent-[var(--primary)]" checked', false);

        $this->post(route('elections.manage.store'), ['title' => 'Class rep', 'roll_only' => '1', 'programmes' => ['part_time'], 'levels' => ['ND1']]);
        $election = Election::where('title', 'Class rep')->firstOrFail();
        $this->assertTrue($election->roll_only);
        $this->assertSame(['part_time'], $election->programmes);
        $this->assertSame('Part-time ND1 students, on the nominal roll', $election->eligibilitySummary());
    }
}
