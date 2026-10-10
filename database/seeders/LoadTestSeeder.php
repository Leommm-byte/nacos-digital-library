<?php

namespace Database\Seeders;

use App\Enums\BookSource;
use App\Enums\BookStatus;
use App\Enums\ElectionStatus;
use App\Enums\Level;
use App\Enums\Programme;
use App\Enums\TextStatus;
use App\Models\Department;
use App\Models\Election;
use App\Support\Elections\LiveResults;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Data for the load test (tests/load, .github/workflows/load-test.yml): a
 * full library and a large class, far more than launch day.
 *
 * - 3,000 approved books with searchable page text, all sharing one PDF;
 * - 1,500 students, F/ND/25/0000001 to F/ND/25/0001500, password
 *   "Password1!", all on the nominal roll;
 * - an open election for them, three positions with three candidates.
 *
 * Inserted in bulk; run on an empty or demo database only:
 *   php artisan db:seed --class=LoadTestSeeder
 */
class LoadTestSeeder extends Seeder
{
    public const BOOKS = 3000;

    public const STUDENTS = 1500;

    private const WORDS = [
        'algorithm', 'array', 'binary', 'compiler', 'database', 'network', 'protocol', 'recursion',
        'stack', 'queue', 'pointer', 'memory', 'process', 'thread', 'kernel', 'socket', 'router',
        'normalisation', 'query', 'index', 'transaction', 'object', 'class', 'inheritance', 'interface',
        'function', 'variable', 'loop', 'sorting', 'searching', 'graph', 'tree', 'hashing', 'encryption',
        'security', 'firewall', 'packet', 'circuit', 'logic', 'gate', 'register', 'assembly', 'cache',
        'operating', 'system', 'software', 'engineering', 'testing', 'design', 'analysis', 'model',
        'the', 'of', 'and', 'in', 'is', 'a', 'to', 'for', 'with', 'each', 'when', 'which', 'this',
    ];

    public function run(): void
    {
        $departments = Department::query()->where('is_active', true)->pluck('id')->all();
        $now = now();

        // One real PDF that every book points to: the reader streams it in
        // byte ranges exactly as it would a real upload.
        $pdf = DemoPdf::make('Load test book', 30);
        Storage::disk('private')->put('books/load-test.pdf', $pdf);
        $sha = hash('sha256', $pdf);

        $levels = array_map(fn (Level $level) => $level->value, Level::cases());

        for ($start = 0; $start < self::BOOKS; $start += 500) {
            $books = [];
            for ($i = $start; $i < min(self::BOOKS, $start + 500); $i++) {
                $topic = $this->words(2);
                $books[] = [
                    'public_id' => (string) Str::ulid(),
                    'title' => Str::title($topic).' '.($i % 7 === 0 ? 'Past Questions' : 'Notes').' '.($i + 1),
                    'author' => fake()->name(),
                    'description' => ucfirst($this->words(25)).'.',
                    'department_id' => $departments[$i % count($departments)],
                    'level' => $levels[$i % count($levels)],
                    'status' => BookStatus::Approved->value,
                    'page_count' => 30,
                    'views_count' => random_int(0, 500),
                    'approved_at' => $now->copy()->subMinutes($i),
                    'created_at' => $now->copy()->subMinutes($i),
                    'updated_at' => $now,
                ];
            }
            DB::table('books')->insert($books);
        }

        $ids = DB::table('books')->orderByDesc('id')->limit(self::BOOKS)->pluck('id')->all();

        foreach (array_chunk($ids, 250) as $chunk) {
            DB::table('book_files')->insert(array_map(fn ($id) => [
                'book_id' => $id,
                'disk' => 'private',
                'path' => 'books/load-test.pdf',
                'original_name' => 'load-test.pdf',
                'mime' => 'application/pdf',
                'size_bytes' => strlen($pdf),
                'sha256' => $sha,
                'page_count' => 30,
                'text_status' => TextStatus::Done->value,
                'source' => BookSource::Pdf->value,
                'is_current' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));

            // About 8,000 characters of page text per book, for search.
            DB::table('book_texts')->insert(array_map(fn ($id) => [
                'book_id' => $id,
                'text' => implode("\n\n", array_map(fn () => ucfirst($this->words(60)).'.', range(1, 18))),
            ], $chunk));
        }

        $password = Hash::make('Password1!');

        for ($start = 1; $start <= self::STUDENTS; $start += 500) {
            $users = [];
            $roll = [];
            for ($n = $start; $n < min(self::STUDENTS + 1, $start + 500); $n++) {
                $matric = sprintf('F/ND/25/%07d', $n);
                $name = fake()->firstName().' '.fake()->lastName();
                $users[] = [
                    'fullname' => $name,
                    'matric_number' => $matric,
                    'department_id' => $departments[$n % count($departments)],
                    'level' => Level::ND1->value,
                    'programme' => Programme::FullTime->value,
                    'role' => 'student',
                    'status' => 'active',
                    'password' => $password,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $roll[] = [
                    'matric_number' => $matric,
                    'fullname' => $name,
                    'level' => Level::ND1->value,
                    'programme' => Programme::FullTime->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('users')->insert($users);
            DB::table('nominal_roll')->insertOrIgnore($roll);
        }

        $election = Election::query()->forceCreate([
            'title' => 'Load Test Executive Election',
            'description' => 'Election used by the load test.',
            'status' => ElectionStatus::Open,
            'roll_only' => true,
            'starts_at' => $now->copy()->subMinute(),
            'ends_at' => $now->copy()->addHours(6),
        ]);

        foreach (['President', 'General Secretary', 'Treasurer'] as $order => $title) {
            $position = $election->positions()->create(['title' => $title, 'sort_order' => $order]);
            foreach (range(0, 2) as $i) {
                $position->candidates()->create(['name' => fake()->name(), 'manifesto' => fake()->sentence(10), 'sort_order' => $i]);
            }
        }

        LiveResults::publish($election);

        $this->command?->info('Load test data: '.self::BOOKS.' books, '.self::STUDENTS.' students, election #'.$election->id.'.');
    }

    private function words(int $count): string
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = self::WORDS[array_rand(self::WORDS)];
        }

        return implode(' ', $words);
    }
}
