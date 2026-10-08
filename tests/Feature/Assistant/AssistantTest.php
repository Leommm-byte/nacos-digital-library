<?php

namespace Tests\Feature\Assistant;

use App\Enums\BookStatus;
use App\Enums\Level;
use App\Models\AssistantMessage;
use App\Models\Book;
use App\Models\User;
use App\Support\Assistant\Reply;
use App\Support\Assistant\Tutor;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['fullname' => 'Ada Obi']);
        config(['services.anthropic.key' => '']);
    }

    #[Test]
    public function guests_are_sent_to_log_in(): void
    {
        $this->get(route('assistant.index'))->assertRedirect(route('login'));
        $this->post(route('assistant.store'), ['message' => 'hi'])->assertRedirect(route('login'));
    }

    #[Test]
    public function signed_in_pages_have_the_assistant_button(): void
    {
        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('data-assistant', false)
            ->assertSee(route('assistant.index'));

        $this->get(route('assistant.index'))
            ->assertOk()
            ->assertSee('Quick answers about the library and your account')
            ->assertSee('Find books on data structures');
    }

    #[Test]
    public function the_helper_lists_approved_books_for_a_level(): void
    {
        // Keyword search needs committed rows on MySQL; see Library\SearchTest.
        $book = Book::factory()->approved()->create(['title' => 'Operating Systems', 'level' => Level::ND2]);
        Book::factory()->status(BookStatus::Pending)->create(['title' => 'Pending Draft', 'level' => Level::ND2]);
        Book::factory()->approved()->create(['title' => 'Compiler Design', 'level' => Level::HND1]);

        $response = $this->actingAs($this->user)
            ->postJson(route('assistant.store'), ['message' => 'Find books for ND2'])
            ->assertOk();

        $answer = $response->json('messages.1');
        $this->assertSame('assistant', $answer['role']);
        $this->assertFalse($answer['ai']);
        $labels = array_column($answer['links'], 'label');
        $this->assertSame(['Operating Systems', 'All ND2 books'], $labels);
        $this->assertSame(route('library.show', $book), $answer['links'][0]['url']);
        $this->assertNull($response->json('remaining'));
    }

    #[Test]
    public function the_helper_shows_only_the_students_own_uploads(): void
    {
        Book::factory()->uploadedBy($this->user)->status(BookStatus::ChangesRequested)->create(['title' => 'My Networking Notes']);
        Book::factory()->uploadedBy(User::factory()->create())->create(['title' => 'Someone Else Upload']);

        $answer = $this->actingAs($this->user)
            ->postJson(route('assistant.store'), ['message' => 'How are my uploads doing?'])
            ->json('messages.1');

        $labels = array_column($answer['links'], 'label');
        $this->assertContains('My Networking Notes', $labels);
        $this->assertNotContains('Someone Else Upload', $labels);
        $this->assertSame('Changes requested', $answer['links'][0]['note']);
    }

    #[Test]
    public function the_helper_answers_how_to_questions_and_offers_a_menu(): void
    {
        $this->actingAs($this->user);

        $upload = $this->postJson(route('assistant.store'), ['message' => 'How do I upload a book?'])->json('messages.1');
        $this->assertSame(route('uploads.create'), $upload['links'][0]['url']);

        $menu = $this->postJson(route('assistant.store'), ['message' => 'hello'])->json('messages.1');
        $this->assertNotEmpty($menu['suggestions']);
        $this->assertSame('Hi Ada! I can help you with the library and your account.', $menu['blocks'][0]['items'][0][0]['text']);
    }

    #[Test]
    public function messages_are_stored_as_text_and_never_rendered_as_html(): void
    {
        $this->actingAs($this->user)
            ->post(route('assistant.store'), ['message' => '<script>alert(1)</script> **bold**'])
            ->assertRedirect(route('assistant.index').'#latest');

        $this->assertDatabaseHas('assistant_messages', ['user_id' => $this->user->id, 'role' => 'user', 'body' => '<script>alert(1)</script> **bold**']);

        $this->get(route('assistant.index'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('<strong>bold</strong>', false);
    }

    #[Test]
    public function questions_are_validated(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('assistant.store'), ['message' => str_repeat('a', 1001)])
            ->assertUnprocessable();
        $this->postJson(route('assistant.store'), ['message' => ''])->assertUnprocessable();
    }

    #[Test]
    public function the_ai_answers_until_the_daily_limit_then_the_helper_does(): void
    {
        config(['services.anthropic.key' => 'test-key', 'assistant.daily_limit' => 2]);
        $this->fakeTutor(fn () => new Reply('An AI answer', ai: true));

        $this->actingAs($this->user);
        $first = $this->postJson(route('assistant.store'), ['message' => 'Explain recursion'])->assertOk();
        $this->assertTrue($first->json('messages.1.ai'));
        $this->assertSame(1, $first->json('remaining'));

        $this->postJson(route('assistant.store'), ['message' => 'And stacks?'])->assertJsonPath('remaining', 0);

        $third = $this->postJson(route('assistant.store'), ['message' => 'hello'])->assertOk();
        $this->assertFalse($third->json('messages.1.ai'));
        $this->assertStringStartsWith('You\'ve used today\'s 2 smart answers', $third->json('messages.1.blocks.0.items.0.0.text'));
        $this->assertSame(2, AssistantMessage::where('role', 'user')->where('ai', true)->count());
    }

    #[Test]
    public function the_helper_steps_in_when_the_ai_fails(): void
    {
        config(['services.anthropic.key' => 'test-key', 'assistant.daily_limit' => 5]);
        $this->fakeTutor(fn () => throw new RuntimeException('API down'));

        $response = $this->actingAs($this->user)
            ->postJson(route('assistant.store'), ['message' => 'Show my saved books'])
            ->assertOk();

        $this->assertFalse($response->json('messages.1.ai'));
        // A failed AI answer doesn't use up the student's limit.
        $this->assertSame(5, $response->json('remaining'));
    }

    #[Test]
    public function a_limit_of_zero_turns_the_ai_off(): void
    {
        config(['services.anthropic.key' => 'test-key', 'assistant.daily_limit' => 0]);
        $this->fakeTutor(fn () => throw new RuntimeException('Should not be called'));

        $this->actingAs($this->user)
            ->getJson(route('assistant.index'))
            ->assertJsonPath('ai', false)
            ->assertJsonPath('remaining', null);
    }

    #[Test]
    public function the_ai_gets_earlier_messages_starting_with_the_student(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $seen = null;
        $this->fakeTutor(function (string $question, Collection $history) use (&$seen) {
            $seen = $history->pluck('body')->all();

            return new Reply('Sure', ai: true);
        });

        $this->actingAs($this->user);
        $this->postJson(route('assistant.store'), ['message' => 'First']);
        $this->postJson(route('assistant.store'), ['message' => 'Second']);

        $this->assertSame(['First', 'Sure'], $seen);
    }

    #[Test]
    public function the_ai_tools_only_read_the_students_own_data(): void
    {
        Book::factory()->uploadedBy($this->user)->create(['title' => 'My Upload']);
        Book::factory()->uploadedBy(User::factory()->create())->create(['title' => 'Their Upload']);

        $tutor = new Tutor($this->user);
        $uploads = json_decode($tutor->run('my_uploads', []), true);

        $this->assertSame(['My Upload'], array_column($uploads, 'label'));
        $this->assertSame(['result' => 'Nothing found.'], json_decode($tutor->run('my_saved_books', ['limit' => 3]), true));
        $this->assertSame(['error' => 'Unknown tool.'], json_decode($tutor->run('delete_account', []), true));
    }

    #[Test]
    public function the_chat_keeps_the_latest_messages_and_can_be_cleared(): void
    {
        config(['assistant.history' => 4]);
        $this->actingAs($this->user);

        foreach (['one', 'two', 'three'] as $message) {
            $this->postJson(route('assistant.store'), ['message' => $message]);
        }

        $this->assertSame(4, AssistantMessage::where('user_id', $this->user->id)->count());
        $this->getJson(route('assistant.index'))->assertJsonCount(4, 'messages')->assertJsonPath('messages.0.blocks.0.items.0.0.text', 'two');

        $other = User::factory()->create();
        AssistantMessage::create(['user_id' => $other->id, 'role' => 'user', 'body' => 'Mine']);

        $this->deleteJson(route('assistant.destroy'))->assertOk();
        $this->assertSame(0, AssistantMessage::where('user_id', $this->user->id)->count());
        $this->assertSame(1, AssistantMessage::where('user_id', $other->id)->count());
    }

    /**
     * Replaces the AI with a closure taking (question, history).
     *
     * @param  Closure(string, Collection<int, AssistantMessage>): Reply  $answer
     */
    private function fakeTutor(Closure $answer): void
    {
        $this->app->bind(Tutor::class, fn ($app, array $params) => new class($params['user'], $answer) extends Tutor
        {
            /**
             * @param  Closure(string, Collection<int, AssistantMessage>): Reply  $fake
             */
            public function __construct(User $user, private Closure $fake)
            {
                parent::__construct($user);
            }

            public function answer(string $question, Collection $history): Reply
            {
                return ($this->fake)($question, $history);
            }
        });
    }
}
