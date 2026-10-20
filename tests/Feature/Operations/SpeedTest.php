<?php

namespace Tests\Feature\Operations;

use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What makes the live site measurable (Server-Timing, the slow request log,
 * ops:latency) and quicker to move around (prefetching).
 */
class SpeedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_response_says_where_its_time_went(): void
    {
        $timing = (string) $this->get('/login')->assertOk()->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/^boot;dur=[\d.]+, db;dur=[\d.]+;desc="\d+ queries", app;dur=[\d.]+$/', $timing);
    }

    #[Test]
    public function slow_requests_are_logged(): void
    {
        $log = Log::spy();

        config(['operations.slow_request_ms' => 100_000]);
        $this->get('/login')->assertOk();
        $log->shouldNotHaveReceived('warning');

        config(['operations.slow_request_ms' => 1]);
        DB::statement('select 1');
        $this->get('/login')->assertOk();
        $log->shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Slow request'
            && $context['path'] === '/login' && $context['status'] === 200 && isset($context['ms'], $context['db_ms'], $context['queries']));
    }

    #[Test]
    public function the_latency_check_runs(): void
    {
        $this->artisan('ops:latency', ['--rounds' => 2])
            ->expectsOutputToContain('One database query')
            ->assertSuccessful();
    }

    #[Test]
    public function pages_prefetch_links_but_not_ones_that_change_something(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);

        $response->assertSee('<script type="speculationrules" nonce="'.$nonce[1].'">', false);
        preg_match('#<script type="speculationrules"[^>]*>(.*?)</script>#s', (string) $response->getContent(), $rules);
        $rules = json_decode($rules[1], true, flags: JSON_THROW_ON_ERROR);
        $excluded = $rules['prefetch'][0]['where']['and'][1]['not']['href_matches'];

        foreach (['/logout', '/notifications/*', '/library/*/read', '/library/*/file'] as $path) {
            $this->assertContains($path, $excluded);
        }
    }

    #[Test]
    public function a_book_page_fetches_the_reader_ahead(): void
    {
        Storage::fake('private');
        $book = Book::factory()->for(Department::factory())->approved()->withPdf(2)->create();

        $this->actingAs(User::factory()->create())
            ->get(route('library.show', $book))
            ->assertOk()
            ->assertSee('<link rel="prefetch"', false);

        $this->get(route('books.read', $book))
            ->assertOk()
            ->assertSee('rel="modulepreload"', false);
    }
}
