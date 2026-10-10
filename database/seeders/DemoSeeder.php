<?php

namespace Database\Seeders;

use App\Enums\BookStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\ReviewAction;
use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Department;
use App\Models\Election;
use App\Models\ReadingProgress;
use App\Models\RollEntry;
use App\Models\User;
use App\Notifications\UploadReviewed;
use App\Support\Classes\Arms;
use App\Support\Classes\SchoolClass;
use App\Support\Elections\BallotBox;
use App\Support\Elections\ElectionLifecycle;
use App\Support\Elections\LiveResults;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Sample data for local development and testing only. Never runs in
 * production (see DatabaseSeeder).
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password1!';

    public function run(): void
    {
        if (User::query()->exists()) {
            $this->command->info('Demo data already present, skipping.');

            return;
        }

        $cs = Department::where('slug', 'computer-science')->firstOrFail();

        $accounts = [
            ['Ada Admin', 'F/HD/24/3211001', Role::Admin],
            ['Gbenga Governor', 'F/HD/24/3212002', Role::Governor],
            ['Chika CourseRep', 'F/ND/23/0000003', Role::CourseRep],
            ['Tobi Student', 'F/ND/24/0000004', Role::Student],
        ];

        $users = [];
        foreach ($accounts as [$name, $matric, $role]) {
            $users[$role->value] = User::factory()
                ->for($cs)
                ->role($role)
                ->create([
                    'fullname' => $name,
                    'matric_number' => $matric,
                    'email' => strtolower(explode(' ', $name)[0]).'@example.test',
                    'level' => $role === Role::Student ? Level::ND1 : Level::HND1,
                    'programme' => Programme::FullTime,
                    'password' => self::PASSWORD,
                ]);
        }

        $students = User::factory()->count(20)->for($cs)->create();
        $uploaders = $students->push($users['course_rep']);

        // Each approved book gets a short generated PDF, so the reader works.
        Book::factory()->count(36)->for($cs)->approved()
            ->withPdf()
            ->sequence(fn ($sequence) => ['uploader_id' => $uploaders[$sequence->index % $uploaders->count()]->id])
            ->create();

        Book::factory()->count(5)->for($cs)->status(BookStatus::Pending)
            ->withPdf(4)
            ->sequence(fn ($sequence) => ['uploader_id' => $uploaders[$sequence->index % $uploaders->count()]->id])
            ->create();

        // Two of Tobi's uploads already reviewed, with notes and notifications,
        // so the uploader side of moderation can be tried straight away.
        foreach ([
            [ReviewAction::ChangesRequested, 'Data Communication Lecture Notes', 'Pages 4 and 5 are blurry. Please retake them in better light and add the course code (COM 213) to the title.'],
            [ReviewAction::Rejected, 'Random Screenshots', 'These are screenshots of a chat, not study material.'],
        ] as [$action, $title, $comment]) {
            $book = Book::factory()->for($cs)->withPdf(3)->create(['title' => $title, 'level' => Level::ND1]);
            $book->uploader_id = $users['student']->id;
            $book->status = $action->resultingStatus();
            $book->reviewed_by = $users['governor']->id;
            $book->save();

            $review = $book->reviews()->make(['action' => $action, 'comment' => $comment]);
            $review->reviewer_id = $users['governor']->id;
            $review->save();

            $users['student']->notify(new UploadReviewed($book, $action, $comment));
        }

        // Tobi is part-way through two books and has finished one, so the
        // dashboard's "Continue reading" and stats have something to show.
        foreach (Book::approved()->where('level', Level::ND1)->take(3)->get() as $i => $book) {
            if ($i === 0) {
                // A long title, as real uploads have, so the screenshots
                // show how cards cope with one on a phone.
                $book->forceFill(['title' => 'Introduction to Data Structures and Algorithms: Lecture Notes and Past Questions (2024/2025 Session)'])->save();
            }
            $pages = $book->page_count ?? 10;
            ReadingProgress::upsert([[
                'user_id' => $users['student']->id,
                'book_id' => $book->id,
                'current_page' => $i === 2 ? $pages : max(1, intdiv($pages, 3 - $i)),
                'progress_percent' => $i === 2 ? 100 : (int) round(max(1, intdiv($pages, 3 - $i)) / $pages * 100),
                'completed_at' => $i === 2 ? now()->subDay() : null,
                'last_read_at' => now()->subHours($i + 1),
            ]], ['user_id', 'book_id'], ['current_page', 'progress_percent', 'completed_at', 'last_read_at']);
        }

        Announcement::factory()->create([
            'title' => 'First semester exams timetable is out',
            'body' => "The timetable for first semester exams is on the departmental notice board and in the library under ND1–HND2.\n\nCheck your course codes and venues early.",
        ]);
        Announcement::factory()->create([
            'title' => 'NACOS week: hackathon registration',
            'body' => 'Teams of up to four can register with their course reps until Friday. Prizes for the top three teams.',
        ]);
        Announcement::factory()->create([
            'title' => 'Library uploads are open',
            'body' => 'Share your notes and past questions from the Upload page. Reviewers check every upload before it appears.',
        ]);

        $this->seedElections($users, $students);

        // Students on the roll without an account yet, three per class, so
        // "Create accounts" has someone to create.
        $waiting = [];
        $classes = [
            new SchoolClass(Programme::PartTime, Level::HND1, 'swd'),
            new SchoolClass(Programme::PartTime, Level::HND1, 'ncc'),
            new SchoolClass(Programme::PartTime, Level::ND2),
            new SchoolClass(Programme::Codfel, Level::ND1),
        ];
        foreach ($classes as $c => $class) {
            for ($i = 1; $i <= 3; $i++) {
                // HND numbers carry the course digit: 3211… SWD, 3212… NCC.
                $digits = $class->arm !== null ? sprintf('321%s%03d', Arms::all()[$class->arm]['digit'], $c * 10 + $i) : sprintf('%07d', 9000000 + $c * 10 + $i);
                $waiting[] = [
                    'matric_number' => sprintf('%s/%s/24/%s', $class->programme->matricLetter(), $class->level->stage() === 'HND' ? 'HD' : 'ND', $digits),
                    'fullname' => strtoupper(fake()->lastName()).' '.fake()->firstName(),
                    'email' => null,
                    'programme' => $class->programme->value,
                    'level' => $class->level->value,
                    'arm' => $class->arm,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        RollEntry::query()->insert($waiting);

        Settings::put(['academic_session' => '2026/2027']);
    }

    /**
     * An open election part-way through voting (Tobi hasn't voted yet), a
     * closed one with final results, and a draft being set up.
     *
     * @param  array<string, User>  $users
     * @param  Collection<int, User>  $students
     */
    private function seedElections(array $users, Collection $students): void
    {
        // Every demo account is a current student on the nominal roll.
        RollEntry::query()->insert(User::query()->get()->map(fn (User $user) => [
            'matric_number' => $user->matric_number,
            'fullname' => $user->fullname,
            'email' => null,
            'level' => $user->level->value,
            'programme' => $user->programme->value,
            'arm' => $user->arm,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());

        // Closed yesterday, with final results.
        $past = Election::create([
            'title' => 'NACOS Week Planning Committee',
            'description' => 'Three students to plan NACOS week with the executives.',
        ]);
        $past->created_by = $users['admin']->id;
        $past->save();
        $this->seedBallot($past, ['Committee Chair' => 3, 'Secretary' => 2], $students);
        ElectionLifecycle::launch($past, 6);
        $this->seedVotes($past, $students->random(15));
        ElectionLifecycle::close($past);
        $past->forceFill(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'closed_at' => now()->subDay()])->save();
        LiveResults::publish($past);

        // Open now, closing tomorrow.
        $open = Election::create([
            'title' => 'NACOS Executive Council Election 2026',
            'description' => 'Choose the executives who will lead NACOS YabaTech for the 2026/2027 session.',
        ]);
        $open->created_by = $users['admin']->id;
        $open->save();
        $this->seedBallot($open, ['President' => 3, 'Vice President' => 2, 'General Secretary' => 2, 'Financial Secretary' => 2], $students);
        ElectionLifecycle::launch($open, 24);
        $this->seedVotes($open, $students->random(12));
        LiveResults::publish($open);

        // Still being set up.
        $draft = Election::create([
            'title' => 'HND Class Representatives',
            'description' => 'Class representatives for HND1 and HND2.',
            'levels' => [Level::HND1->value, Level::HND2->value],
            'entry_year_from' => 2022,
            'entry_year_to' => 2025,
        ]);
        $draft->created_by = $users['admin']->id;
        $draft->save();
        $this->seedBallot($draft, ['HND1 Representative' => 2, 'HND2 Representative' => 0], $students);
    }

    /**
     * @param  array<string, int>  $positions  title => number of candidates
     * @param  Collection<int, User>  $students
     */
    private function seedBallot(Election $election, array $positions, Collection $students): void
    {
        $manifestos = [
            'A library that works on every phone, past questions for every course, and monthly tech talks with alumni.',
            'Fair dues, a transparent budget published every semester, and a laptop repair day each term.',
            'More hackathons and study groups, and a NACOS week that every level can take part in.',
            'Quick answers on WhatsApp, clear minutes after every meeting, and timetables shared on time.',
            'Bring back the coding bootcamp for ND1 and get internships listed in one place.',
            'Every student heard: monthly town halls and a suggestion box that gets a reply.',
        ];
        $order = 0;

        foreach ($positions as $title => $count) {
            $position = $election->positions()->create(['title' => $title, 'sort_order' => $order++]);

            foreach ($students->random($count)->values() as $i => $candidate) {
                $row = $position->candidates()->create([
                    'user_id' => $candidate->id,
                    'name' => $candidate->fullname,
                    'matric_number' => $candidate->matric_number,
                    'manifesto' => $manifestos[($order + $i) % count($manifestos)],
                    'sort_order' => $i,
                ]);

                // Every other candidate has a photo, so both looks can be seen.
                if ($i % 2 === 0) {
                    $path = "candidate-photos/{$election->id}/{$row->id}-demo.jpg";
                    Storage::disk('private')->put($path, $this->portrait($row->id));
                    $row->update(['photo_path' => $path]);
                }
            }
        }
    }

    /**
     * A plain illustrated portrait (head and shoulders) standing in for a
     * candidate's photo in demo data.
     */
    private function portrait(int $seed): string
    {
        $palettes = [
            [[214, 234, 220], [36, 120, 72], [92, 64, 51]],
            [[219, 228, 245], [52, 84, 160], [141, 98, 72]],
            [[244, 232, 205], [168, 112, 24], [74, 52, 40]],
            [[232, 222, 244], [104, 72, 160], [198, 140, 100]],
        ];
        [$background, $clothes, $skin] = $palettes[$seed % count($palettes)];

        $image = imagecreatetruecolor(480, 480);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$background));
        imagefilledellipse($image, 240, 520, 400, 340, (int) imagecolorallocate($image, ...$clothes));
        imagefilledellipse($image, 240, 210, 190, 220, (int) imagecolorallocate($image, ...$skin));

        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * @param  Collection<int, User>  $voters
     */
    private function seedVotes(Election $election, Collection $voters): void
    {
        $positions = $election->positions()->with('candidates')->get();

        foreach ($voters as $voter) {
            $choices = [];

            foreach ($positions as $position) {
                // Some voters skip a position.
                if ($position->candidates->isNotEmpty() && fake()->boolean(90)) {
                    $choices[$position->id] = $position->candidates->random()->id;
                }
            }

            if ($choices !== []) {
                BallotBox::cast($election, $voter, $choices);
            }
        }
    }
}
