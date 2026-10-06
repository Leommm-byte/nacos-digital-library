<?php

namespace Tests\Feature\Announcements;

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $governor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->governor = User::factory()->role(Role::Governor)->create();
    }

    #[Test]
    public function students_read_announcements_but_cannot_manage_them(): void
    {
        Announcement::factory()->create(['title' => 'Exams Timetable']);
        $student = User::factory()->create();

        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertSee('Exams Timetable')->assertDontSee('Manage');
        $this->get(route('announcements.manage'))->assertForbidden();
        $this->post(route('announcements.store'), ['title' => 'x', 'body' => 'y'])->assertForbidden();
    }

    #[Test]
    public function an_announcement_shows_until_the_end_of_its_last_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Africa/Lagos'));

        $this->actingAs($this->governor)
            ->post(route('announcements.store'), [
                'title' => 'Hackathon',
                'body' => 'Register by Friday.',
                'starts_on' => '2026-11-02',
                'ends_on' => '2026-11-06',
                'is_published' => '1',
            ])
            ->assertRedirect(route('announcements.manage'));

        $announcement = Announcement::firstOrFail();
        $this->assertSame($this->governor->id, $announcement->created_by);
        $this->assertTrue($announcement->ends_at?->equalTo(Carbon::parse('2026-11-06 23:59:59', 'Africa/Lagos')));

        // The legacy app hid it at the start of the last day.
        $this->travelTo(Carbon::parse('2026-11-06 22:00', 'Africa/Lagos'));
        $this->get('/')->assertSee('Hackathon');

        $this->travelTo(Carbon::parse('2026-11-07 00:01', 'Africa/Lagos'));
        $this->get(route('announcements.index'))->assertDontSee('Hackathon');
    }

    #[Test]
    public function a_new_announcement_appears_on_home_pages_straight_away(): void
    {
        $this->actingAs($this->governor)->get('/')->assertSee('No announcements right now');

        $this->post(route('announcements.store'), ['title' => 'Fresh News', 'body' => 'Hello.', 'is_published' => '1']);

        $this->get('/')->assertSee('Fresh News');
    }

    #[Test]
    public function drafts_are_hidden_and_can_be_edited_and_deleted(): void
    {
        $this->actingAs($this->governor)->post(route('announcements.store'), ['title' => 'Draft News', 'body' => 'Soon.']);
        $announcement = Announcement::firstOrFail();
        $this->assertFalse($announcement->is_published);

        $this->get(route('announcements.manage'))->assertSee('Draft News')->assertSee('Draft');
        $this->get(route('announcements.index'))->assertDontSee('Draft News');

        $this->put(route('announcements.update', $announcement), ['title' => 'Now Live', 'body' => 'Here.', 'is_published' => '1'])
            ->assertRedirect(route('announcements.manage'));
        $this->get(route('announcements.index'))->assertSee('Now Live');

        $this->delete(route('announcements.destroy', $announcement))->assertRedirect(route('announcements.manage'));
        $this->assertModelMissing($announcement);
        $this->assertDatabaseHas('audit_logs', ['action' => 'announcement_deleted']);
    }

    #[Test]
    public function the_last_day_cannot_be_before_the_first(): void
    {
        $this->actingAs($this->governor)
            ->post(route('announcements.store'), ['title' => 'T', 'body' => 'B', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-01'])
            ->assertSessionHasErrors(['ends_on' => "The last day can't be before the first day."]);
    }

    #[Test]
    public function the_form_opens_for_new_and_existing_announcements(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Existing']);

        $this->actingAs($this->governor)->get(route('announcements.create'))->assertOk()->assertSee('Post announcement');
        $this->get(route('announcements.edit', $announcement))->assertOk()->assertSee('Existing');
    }
}
