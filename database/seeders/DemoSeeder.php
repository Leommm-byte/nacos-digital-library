<?php

namespace Database\Seeders;

use App\Enums\BookStatus;
use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\ReviewAction;
use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Department;
use App\Models\Election;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Notifications\UploadReviewed;
use Illuminate\Database\Seeder;

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
            ['Ada Admin', 'F/HD/21/0000001', Role::Admin],
            ['Gbenga Governor', 'F/HD/22/0000002', Role::Governor],
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

        $election = new Election(['title' => 'NACOS Executive Council Election']);
        $election->status = ElectionStatus::Draft;
        $election->save();

        foreach (['President', 'Vice President', 'General Secretary'] as $order => $title) {
            $position = $election->positions()->create(['title' => $title, 'sort_order' => $order]);

            foreach ($students->random(2) as $i => $candidate) {
                $position->candidates()->create([
                    'user_id' => $candidate->id,
                    'name' => $candidate->fullname,
                    'matric_number' => $candidate->matric_number,
                    'manifesto' => fake()->sentence(12),
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
