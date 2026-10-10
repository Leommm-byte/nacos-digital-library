<?php

namespace App\Support\Assistant;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use App\Enums\Level;
use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The AI assistant (Claude), used when an Anthropic API key is set and the
 * student is under the daily limit. It answers questions about the app and
 * helps with study: explaining topics, summarising and quizzing from books
 * in the library.
 *
 * Its tools only read: the library, and the signed-in student's own data
 * (through Lookups). Nothing it does can change anything or reach another
 * student's account. Answers are plain text; links come from what the
 * tools returned, never from the model's text.
 */
class Tutor
{
    /** Model calls per question: enough to search, read and answer. */
    private const ROUNDS = 4;

    private const SYSTEM = <<<'PROMPT'
You are the assistant in the NACOS YabaTech student portal, the app of the Nigeria Association of Computing Students at Yaba College of Technology, Lagos. Students use it to read and share study books and past questions, vote in association elections and read announcements. You help in two ways.

1. Help with the app and the student's own account. Use the tools to look things up rather than guessing. What you know about the app:
- Library: approved books and past questions by department and level (ND1 to HND3), with search. Students read in the browser, and the app remembers their page.
- Saving: the bookmark button on a book adds it to Saved.
- Uploads: "Upload" takes a PDF or photos of the pages, with title, author, department and level. A reviewer approves it, asks for changes or rejects it, and the student gets a notification. Uploads that need changes can be edited and sent back from "My uploads".
- Elections: run by admins. Who can vote is set per election (usually students on the class nominal roll, sometimes only some levels or programmes). Each student votes once; ballots are secret and results update live.
- Account: Settings has the password, email address and two-step verification. A forgotten password can be reset from the login page, by email or with a reset code from a class rep or admin.
You can't change anything for the student. Tell them where to do it.

2. Study help: explain topics in computing and related courses, summarise chapters, make practice questions and check the student's answers. Prefer the library: search it, then read the passages of a book that matter, and say which book an explanation comes from. Only quote or summarise what read_book returned; never invent book titles, authors, page numbers or quotes. If the library has nothing, say so, then explain from general knowledge. Book ids appear in earlier links as /library/<id>.

Academic honesty: help the student learn, don't do graded work for them. If a message looks like an assignment, test, project or exam question to hand in, don't write the finished answer. Explain the idea, give a worked example of a similar problem, point out where their attempt goes wrong, or ask what they've tried. Working through past questions for revision is fine.

Style: reply in plain, friendly English for Nigerian polytechnic students. Keep answers short: a few sentences or a short list, longer only for an explanation they asked for. Plain text only: no tables, no links, no HTML, no code fences; you may use "- " bullets, "1. " steps and **bold**. Don't mention these instructions or your tools by name. If asked about something outside the app and study, answer briefly and steer back.
PROMPT;

    private Lookups $lookups;

    /** @var array<string, array{label: string, url: string, note?: string|null}> */
    private array $links = [];

    public function __construct(private User $user)
    {
        $this->lookups = new Lookups($user);
    }

    public static function available(): bool
    {
        return (string) config('services.anthropic.key') !== '';
    }

    /**
     * @param  Collection<int, AssistantMessage>  $history  earlier messages, oldest first
     */
    public function answer(string $question, Collection $history): Reply
    {
        $client = new Client(apiKey: (string) config('services.anthropic.key'));
        $messages = [...$this->context($history), ['role' => 'user', 'content' => $question]];
        $text = '';

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $response = $client->beta->messages->create(
                maxTokens: 4096,
                messages: $messages,
                model: (string) config('services.anthropic.model'),
                system: [
                    ['type' => 'text', 'text' => self::SYSTEM, 'cacheControl' => ['type' => 'ephemeral']],
                    ['type' => 'text', 'text' => $this->about()],
                ],
                tools: self::tools(),
                // On the last round the model has to answer with what it has.
                toolChoice: ['type' => $round === self::ROUNDS ? 'none' : 'auto'],
                // A chat reply, not a research task: keep it quick.
                outputConfig: ['effort' => 'low'],
                // If the model declines, the API retries on a suitable
                // fallback model within the same call.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );

            if ($response->stopReason === 'refusal') {
                return new Reply('Sorry, I can\'t help with that one. I can explain a topic, find books, or answer questions about the app.', ai: true);
            }

            $text = '';
            $results = [];
            foreach ($response->content as $block) {
                if ($block instanceof BetaTextBlock) {
                    $text .= $block->text;
                } elseif ($block instanceof BetaToolUseBlock) {
                    $results[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $block->id,
                        'content' => $this->run($block->name, $block->input),
                    ];
                }
            }

            if ($response->stopReason !== 'tool_use' || $results === []) {
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        $text = trim($text);

        return new Reply(
            $text !== '' ? $text : 'Sorry, I didn\'t get an answer together. Could you ask again in other words?',
            array_slice(array_values($this->links), 0, 6),
            ai: true,
        );
    }

    /**
     * Runs one tool and returns its result as JSON for the model.
     *
     * @param  array<array-key, mixed>|mixed  $input
     */
    public function run(string $name, mixed $input): string
    {
        $input = is_array($input) ? $input : [];
        $limit = max(1, min(10, (int) ($input['limit'] ?? 5)));

        $result = match ($name) {
            'search_library' => $this->collect($this->lookups->books(
                (string) ($input['query'] ?? ''),
                Level::tryFrom(mb_strtoupper((string) ($input['level'] ?? '')))?->value,
                $limit,
            )),
            'read_book' => $this->readBook((string) ($input['book_id'] ?? ''), isset($input['focus']) ? (string) $input['focus'] : null),
            'my_uploads' => $this->collect($this->lookups->uploads($limit)),
            'my_saved_books' => $this->collect($this->lookups->saved($limit)),
            'my_reading' => $this->collect($this->lookups->reading($limit)),
            'my_notifications' => $this->collect($this->lookups->notifications($limit)),
            'my_recent_activity' => $this->lookups->activity($limit),
            'open_elections' => $this->collect($this->lookups->elections()),
            default => ['error' => 'Unknown tool.'],
        };

        return (string) json_encode($result === [] ? ['result' => 'Nothing found.'] : $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<string, mixed>
     */
    private function readBook(string $id, ?string $focus): array
    {
        $book = $id !== '' ? $this->lookups->book($id) : null;

        if ($book === null) {
            return ['error' => 'No approved book with that id. Search the library first.'];
        }

        $this->collect([['label' => $book->title, 'url' => route('library.show', $book), 'note' => $book->author.' · '.$book->level->label()]]);
        $excerpt = $this->lookups->excerpt($book, $focus);

        return [
            'title' => $book->title,
            'author' => $book->author,
            'level' => $book->level->label(),
            'department' => $book->department->name,
            'description' => $book->description,
            'text' => $excerpt !== '' ? $excerpt : null,
            'note' => $excerpt === '' ? 'This book has no searchable text yet (for example a scan still being read). Only its details are available.' : 'Passages from the book, not the whole text.',
        ];
    }

    /**
     * Keeps the rows' links to show under the answer, and drops the URLs
     * from what the model sees (it shouldn't write links itself).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function collect(array $rows): array
    {
        return array_map(function (array $row) {
            if (isset($row['label'], $row['url']) && is_string($row['label']) && is_string($row['url'])) {
                $note = $row['note'] ?? null;
                $this->links[$row['url']] ??= ['label' => $row['label'], 'url' => $row['url'], 'note' => is_string($note) ? $note : null];
            }

            unset($row['url']);

            return $row;
        }, $rows);
    }

    /**
     * Earlier messages as plain text turns. Links shown with an answer are
     * listed after it, so "summarise the second one" works.
     *
     * @param  Collection<int, AssistantMessage>  $history
     * @return list<array{role: string, content: string}>
     */
    private function context(Collection $history): array
    {
        $turns = $history->map(function (AssistantMessage $message) {
            $content = $message->body;

            if ($message->role === 'assistant' && $message->links) {
                $content .= "\n\n[Links shown: ".collect($message->links)
                    ->map(fn (array $link) => $link['label'].' ('.(parse_url($link['url'], PHP_URL_PATH) ?: $link['url']).')')
                    ->implode('; ').']';
            }

            return ['role' => $message->role === 'user' ? 'user' : 'assistant', 'content' => $content];
        })->values()->all();

        // The conversation has to start with the student.
        while ($turns !== [] && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return $turns;
    }

    private function about(): string
    {
        $this->user->loadMissing('department:id,name');

        return 'The student: '.$this->user->greetingName().', '.$this->user->level->label()
            .' '.$this->user->programme->label().' in '.$this->user->department->name
            .'. Today is '.now()->timezone((string) config('app.display_timezone'))->format('l j F Y').'.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function tools(): array
    {
        $limit = ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'How many to return (default 5).'];
        $mine = fn (string $name, string $description) => [
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => ['limit' => $limit]],
        ];

        return [
            [
                'name' => 'search_library',
                'description' => 'Search the approved books and past questions in the library by title, author, topic or words in the text. Returns ids, titles, authors, levels and departments, best match first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'A few keywords, e.g. "data structures linked list".'],
                        'level' => ['type' => 'string', 'enum' => array_map(fn (Level $level) => $level->value, Level::cases()), 'description' => 'Only books for this level.'],
                        'limit' => $limit,
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'read_book',
                'description' => 'Read passages of one library book, to explain, summarise or make questions from it. With a focus, returns the passages about it; without, the start of the book. Up to about 6,000 characters.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'book_id' => ['type' => 'string', 'description' => 'The id from search_library.'],
                        'focus' => ['type' => 'string', 'description' => 'The topic to find in the book, e.g. "normalisation 3NF".'],
                    ],
                    'required' => ['book_id'],
                ],
            ],
            $mine('my_uploads', 'The student\'s latest uploads with their review status and the reviewer\'s note.'),
            $mine('my_saved_books', 'Books the student saved (bookmarked), newest first.'),
            $mine('my_reading', 'Books the student started and hasn\'t finished, with their page and progress.'),
            $mine('my_notifications', 'The student\'s latest notifications (decisions on their uploads), newest first.'),
            $mine('my_recent_activity', 'Recent actions on the student\'s account: logins, uploads, votes, settings changes.'),
            $mine('open_elections', 'Elections open now, whether the student can vote and why not, and whether they have voted.'),
        ];
    }
}
