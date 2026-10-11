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

    /** HND1 SWD Full-time */
    private const CLASS_A = ['programme' => 'full_time', 'level' => 'HND1', 'arm' => 'swd'];

    /** HND1 NCC Full-time: same level and programme, other course */
    private const CLASS_B = ['programme' => 'full_time', 'level' => 'HND1', 'arm' => 'ncc'];

    private User $governor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->governor = User::factory()->role(Role::Governor)->create(self::CLASS_A);
        $this->admin = User::factory()->role(Role::Admin)->create();
    }

    /**
     * @param  array<string, string>  $class
     * @param  array<string, mixed>  $attributes
     */
    private function forClass(array $class, array $attributes = []): Announcement
    {
        return Announcement::factory()->create([...$class, ...$attributes]);
    }

    #[Test]
    public function students_read_announcements_but_cannot_manage_them(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Exams Timetable']);
        $student = User::factory()->create(self::CLASS_A);

        $this->actingAs($student)->get(route('announcements.index'))->assertOk()->assertSee('Exams Timetable')->assertDontSee('Manage');
        $this->get(route('announcements.show', $announcement))->assertOk()->assertDontSee('Edit');
        $this->get(route('announcements.manage'))->assertForbidden();
        $this->get(route('announcements.edit', $announcement))->assertForbidden();
        $this->post(route('announcements.store'), ['title' => 'x', 'body' => 'y'])->assertForbidden();
    }

    #[Test]
    public function a_class_announcement_is_seen_only_by_that_exact_class_and_admins(): void
    {
        $notice = $this->forClass(self::CLASS_A, ['title' => 'Class Meeting']);
        Announcement::factory()->create(['title' => 'For Everyone']);

        $classmate = User::factory()->create(self::CLASS_A);
        $otherCourse = User::factory()->create(self::CLASS_B);
        $otherProgramme = User::factory()->create([...self::CLASS_A, 'programme' => 'part_time']);
        $graduate = User::factory()->create([...self::CLASS_A, 'graduated_at' => now()]);

        foreach ([$classmate, $this->admin, $this->governor] as $user) {
            $this->actingAs($user)->get(route('announcements.index'))->assertSee('Class Meeting')->assertSee('For Everyone');
            $this->get(route('announcements.show', $notice))->assertOk()->assertSee('For HND1 SWD Full-time');
            $this->get('/')->assertSee('Class Meeting');
        }

        foreach ([$otherCourse, $otherProgramme, $graduate] as $user) {
            $this->actingAs($user)->get(route('announcements.index'))->assertDontSee('Class Meeting')->assertSee('For Everyone');
            $this->get(route('announcements.show', $notice))->assertForbidden();
            $this->get('/')->assertDontSee('Class Meeting');
        }
    }

    #[Test]
    public function governors_post_only_to_their_own_class(): void
    {
        // Asking for everyone (or another class) still posts to their class.
        $this->actingAs($this->governor)->get(route('announcements.create'))->assertOk()
            ->assertSee('HND1 SWD Full-time and admins')->assertDontSee('name="audience"', false);
        $this->post(route('announcements.store'), ['title' => 'Project Defence', 'body' => 'Bring your topic.', 'audience' => 'full_time|HND1|ncc', 'is_published' => '1']);

        $announcement = Announcement::where('title', 'Project Defence')->firstOrFail();
        $this->assertSame('HND1 SWD Full-time', $announcement->audience());
        $this->assertSame($this->governor->id, $announcement->created_by);

        // Their manage page lists their class's only, whoever posted them.
        $this->forClass(self::CLASS_A, ['title' => 'From The Old Governor']);
        $other = $this->forClass(self::CLASS_B, ['title' => 'Another Class']);
        $everyone = Announcement::factory()->create(['title' => 'School Wide']);

        $this->get(route('announcements.manage'))->assertSee('Project Defence')->assertSee('From The Old Governor')
            ->assertDontSee('Another Class')->assertDontSee('School Wide');

        foreach ([$other, $everyone] as $notTheirs) {
            $this->get(route('announcements.edit', $notTheirs))->assertForbidden();
            $this->put(route('announcements.update', $notTheirs), ['title' => 'Hijacked', 'body' => 'x'])->assertForbidden();
            $this->delete(route('announcements.destroy', $notTheirs))->assertForbidden();
        }
        $this->assertSame(0, Announcement::where('title', 'Hijacked')->count());

        // A graduated governor has no class, so nothing to post to.
        $this->governor->forceFill(['graduated_at' => now()])->save();
        $this->get(route('announcements.manage'))->assertForbidden();
    }

    #[Test]
    public function admins_post_to_everyone_or_any_class(): void
    {
        $this->actingAs($this->admin)->get(route('announcements.create'))->assertOk()
            ->assertSee('name="audience"', false)->assertSee('HND1 NCC Full-time');

        $this->post(route('announcements.store'), ['title' => 'Everyone News', 'body' => 'Hi.', 'audience' => '', 'is_published' => '1']);
        $this->post(route('announcements.store'), ['title' => 'NCC News', 'body' => 'Hi.', 'audience' => 'full_time|HND1|ncc', 'is_published' => '1']);
        $this->post(route('announcements.store'), ['title' => 'Bad', 'body' => 'Hi.', 'audience' => 'codfel|HND1|swd'])->assertSessionHasErrors('audience');

        $this->assertTrue(Announcement::where('title', 'Everyone News')->firstOrFail()->isForEveryone());
        $this->assertSame('HND1 NCC Full-time', Announcement::where('title', 'NCC News')->firstOrFail()->audience());

        // And manage any class's.
        $theirs = $this->forClass(self::CLASS_A, ['title' => 'Governor Post']);
        $this->get(route('announcements.manage'))->assertSee('Governor Post')->assertSee('NCC News');
        $this->put(route('announcements.update', $theirs), ['title' => 'Edited By Admin', 'body' => 'x', 'audience' => 'full_time|HND1|swd', 'is_published' => '1'])
            ->assertRedirect(route('announcements.show', $theirs));
        $this->assertSame('Edited By Admin', $theirs->fresh()?->title);
    }

    #[Test]
    public function the_message_is_formatted_safely_and_long_ones_have_a_preview(): void
    {
        $body = "Intro with **bold** and *italics*.\n\n## Agenda\n\n- First\n- Second\n\n1. One\n2. Two\n\n"
            ."[Our site](https://example.com) and [bad](javascript:alert(1))\n\n<script>alert('x')</script><b onclick=\"x\">raw</b>\n\n![a picture](https://evil.example/x.png)\n\n"
            .str_repeat('More words here. ', 40);
        $announcement = Announcement::factory()->create(['title' => 'Formatted', 'body' => $body]);
        $student = User::factory()->create(self::CLASS_A);

        $page = $this->actingAs($student)->get(route('announcements.show', $announcement))->assertOk();
        $page->assertSee('<strong>bold</strong>', false)
            ->assertSee('<em>italics</em>', false)
            ->assertSee('<h2>Agenda</h2>', false)
            ->assertSee('<li>First</li>', false)
            ->assertSee('<ol>', false)
            ->assertSee('<a rel="nofollow noopener noreferrer" href="https://example.com">Our site</a>', false)
            ->assertDontSee('javascript:', false)
            ->assertDontSee('<script>alert', false)
            ->assertDontSee('onclick', false)
            ->assertDontSee('evil.example', false)
            ->assertSee('a picture');

        // The list shows plain text, cut short, with "Read more".
        $this->get(route('announcements.index'))
            ->assertSee('Intro with bold and italics. Agenda First Second')
            ->assertDontSee('**bold**')
            ->assertSee('Read more');
        $this->assertLessThanOrEqual(223, mb_strlen($announcement->excerpt()));
        $this->assertTrue($announcement->isLong());

        $short = Announcement::factory()->create(['title' => 'Short', 'body' => 'One line only.']);
        $this->assertFalse($short->isLong());
    }

    #[Test]
    public function an_announcement_shows_until_the_end_of_its_last_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Africa/Lagos'));

        $this->actingAs($this->admin)
            ->post(route('announcements.store'), [
                'title' => 'Hackathon',
                'body' => 'Register by Friday.',
                'starts_on' => '2026-11-02',
                'ends_on' => '2026-11-06',
                'is_published' => '1',
            ]);

        $announcement = Announcement::firstOrFail();
        $this->assertSame($this->admin->id, $announcement->created_by);
        $this->assertTrue($announcement->ends_at?->equalTo(Carbon::parse('2026-11-06 23:59:59', 'Africa/Lagos')));

        // The legacy app hid it at the start of the last day.
        $this->travelTo(Carbon::parse('2026-11-06 22:00', 'Africa/Lagos'));
        $this->get('/')->assertSee('Hackathon');

        $this->travelTo(Carbon::parse('2026-11-07 00:01', 'Africa/Lagos'));
        $this->get(route('announcements.index'))->assertDontSee('Hackathon');

        // Ended: students can't open it; its managers still can.
        $student = User::factory()->create(self::CLASS_A);
        $this->actingAs($student)->get(route('announcements.show', $announcement))->assertForbidden();
        $this->actingAs($this->admin)->get(route('announcements.show', $announcement))->assertOk()->assertSee('has ended');
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
        // Its author previews it; classmates can't open it yet.
        $this->get(route('announcements.show', $announcement))->assertOk()->assertSee('This is a draft');
        $this->actingAs(User::factory()->create(self::CLASS_A))->get(route('announcements.show', $announcement))->assertForbidden();

        $this->actingAs($this->governor)->put(route('announcements.update', $announcement), ['title' => 'Now Live', 'body' => 'Here.', 'is_published' => '1'])
            ->assertRedirect(route('announcements.show', $announcement));
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
        $announcement = $this->forClass(self::CLASS_A, ['title' => 'Existing']);

        $this->actingAs($this->governor)->get(route('announcements.create'))->assertOk()->assertSee('Post announcement')->assertSee('data-markdown-editor', false);
        $this->get(route('announcements.edit', $announcement))->assertOk()->assertSee('Existing');
    }
}
