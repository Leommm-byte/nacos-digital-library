<?php

namespace Tests\Feature\Elections;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Support\Elections\LiveResults;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CloseElectionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_scheduler_closes_elections_when_their_time_is_up(): void
    {
        $due = Election::factory()->open(1)->withBallot()->create();
        $running = Election::factory()->open(5)->withBallot()->create();

        $this->travel(2)->hours();
        $this->artisan('elections:close')->expectsOutput('Closed 1 election.')->assertSuccessful();

        $this->assertSame(ElectionStatus::Closed, Election::findOrFail($due->id)->status);
        $this->assertSame(ElectionStatus::Open, Election::findOrFail($running->id)->status);
        $this->assertTrue(LiveResults::read(Election::findOrFail($due->id))['final'] ?? false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'election_closed', 'subject_id' => $due->id, 'user_id' => null]);
    }

    #[Test]
    public function closing_runs_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'elections:close'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()?->expression);
    }
}
