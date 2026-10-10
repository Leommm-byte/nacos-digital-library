<?php

namespace App\Support\Assistant;

use App\Enums\Level;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The free assistant: answers from keywords, with no AI and no cost. It
 * finds books, shows the student's uploads, saved books, reading,
 * notifications, activity and elections, explains how things work, and
 * otherwise offers a menu. Also answers when the AI is off, over its daily
 * limit or unavailable.
 */
class Helper
{
    public const MENU = [
        'Find books on data structures',
        'How are my uploads doing?',
        'Show my saved books',
        'What should I continue reading?',
        'Any new notifications?',
        'Can I vote in an election?',
    ];

    /**
     * Words dropped from a book search ("find me books on networking").
     */
    private const FILLER = [
        'a', 'about', 'an', 'any', 'book', 'books', 'can', 'do', 'find', 'for', 'get', 'give', 'have', 'i',
        'is', 'look', 'looking', 'material', 'materials', 'me', 'need', 'of', 'on', 'please', 'pdf', 'pdfs',
        'search', 'show', 'some', 'textbook', 'textbooks', 'the', 'there', 'to', 'want', 'what', 'you',
    ];

    private Lookups $lookups;

    public function __construct(private User $user)
    {
        $this->lookups = new Lookups($user);
    }

    public function answer(string $message): Reply
    {
        $text = Str::of($message)->lower()->squish()->toString();
        $has = fn (string ...$words) => (bool) preg_match('/\b(?:'.implode('|', $words).')/u', $text);

        return match (true) {
            $text === '' || (bool) preg_match('/^(hi|hello|hey|help|menu|start|good (morning|afternoon|evening))\b/u', $text)
                || $has('what can you', 'how do you work') => $this->menu(),
            $has('how do i', 'how can i', 'how to', 'how does') && $has('upload', 'share', 'submit') => $this->howToUpload(),
            $has('password') => $this->howTo('password'),
            $has('two-step', 'two step', '2fa', 'authenticator') => $this->howTo('two-step'),
            $has('email') && $has('change', 'confirm', 'verify', 'update') => $this->howTo('email'),
            $has('election', 'vote', 'voting', 'ballot', 'candidate') => $this->elections(),
            $has('upload', 'status', 'approv', 'pending', 'reject', 'my submission') => $this->uploads(),
            $has('saved', 'bookmark') => $this->saved(),
            $has('notification', 'inbox', 'alert') => $this->notifications(),
            $has('continue', 'reading', 'progress', 'where did i', 'stopped') => $this->reading(),
            $has('activity', 'recent activity', 'what did i', 'login', 'logged in') => $this->activity(),
            default => $this->search($message),
        };
    }

    public function menu(?string $intro = null): Reply
    {
        return new Reply(
            ($intro ?? 'Hi '.$this->user->firstName().'! I can help you with the library and your account.')
            ."\n\nTry asking me to:\n- find books by title, author or topic\n- check how your uploads are doing\n- list your saved books or what you were reading\n- show your notifications and recent activity\n- tell you about elections you can vote in",
            suggestions: self::MENU,
        );
    }

    private function search(string $message): Reply
    {
        $words = preg_split('/[^\p{L}\p{N}+#]+/u', mb_strtolower($message)) ?: [];

        // "ND2", "hnd 1": a level filter rather than a search word.
        $level = null;
        if (preg_match('/\b(h?nd)\s?([123])\b/u', mb_strtolower($message), $match)) {
            $level = Level::tryFrom(mb_strtoupper($match[1]).$match[2]);
            $words = array_diff($words, [$match[1], $match[2], $match[1].$match[2]]);
        }

        $search = trim(implode(' ', array_filter($words, fn (string $word) => $word !== '' && ! in_array($word, self::FILLER, true))));

        if ($search === '' && $level !== null) {
            $books = $this->lookups->books('', $level->value);

            return $books === []
                ? new Reply('There are no '.$level->label().' books in the library yet.', [['label' => 'Browse the library', 'url' => route('library.index')]])
                : new Reply('The newest '.$level->label().' books:', $this->links($books, ['label' => 'All '.$level->label().' books', 'url' => route('library.index', ['level' => $level->value])]));
        }

        if ($search === '') {
            return new Reply(
                'Which book are you looking for? Tell me a title, author or topic, like "Find database systems".',
                suggestions: ['Find books on networking', 'Find past questions'],
            );
        }

        $books = $this->lookups->books($search, $level?->value);

        if ($books === []) {
            return $this->menu('I couldn\'t find any books matching "'.Str::limit($search, 60).'". Try another word, or browse the library.');
        }

        return new Reply(
            count($books) === 1 ? 'I found one book for "'.$search.'":' : 'Here\'s what I found for "'.$search.'":',
            $this->links($books, ['label' => 'Search the whole library', 'url' => route('library.index', ['q' => $search])]),
        );
    }

    private function uploads(): Reply
    {
        $uploads = $this->lookups->uploads();

        if ($uploads === []) {
            return new Reply(
                'You haven\'t shared any books yet. Uploads are checked by a reviewer before they appear in the library.',
                [['label' => 'Share a book', 'url' => route('uploads.create')]],
            );
        }

        $comments = collect($uploads)
            ->filter(fn (array $upload) => $upload['comment'] !== null)
            ->map(fn (array $upload) => '- **'.$upload['label'].'**: "'.Str::limit((string) $upload['comment'], 140).'"')
            ->implode("\n");

        return new Reply(
            'Here are your latest uploads.'.($comments !== '' ? "\n\nReviewer notes:\n".$comments : ''),
            $this->links($uploads, ['label' => 'All my uploads', 'url' => route('uploads.index')]),
        );
    }

    private function saved(): Reply
    {
        $saved = $this->lookups->saved();

        return $saved === []
            ? new Reply('You haven\'t saved any books yet. Tap the bookmark on any book to keep it here.', [['label' => 'Browse the library', 'url' => route('library.index')]])
            : new Reply('Your most recently saved books:', $this->links($saved, ['label' => 'All saved books', 'url' => route('bookmarks.index')]));
    }

    private function reading(): Reply
    {
        $reading = $this->lookups->reading();

        return $reading === []
            ? new Reply('You have nothing half-read right now. Want a book for your level?', suggestions: ['Find books for '.$this->user->level->label()])
            : new Reply('Pick up where you stopped:', $this->links($reading));
    }

    private function notifications(): Reply
    {
        $notifications = $this->lookups->notifications();
        $unread = count(array_filter($notifications, fn (array $n) => $n['unread']));

        return $notifications === []
            ? new Reply('No notifications yet. You\'ll get one when a reviewer decides on your upload.')
            : new Reply(
                $unread > 0 ? 'You have '.$unread.' new '.Str::plural('notification', $unread).':' : 'You\'re all caught up. Your latest notifications:',
                $this->links($notifications, ['label' => 'All notifications', 'url' => route('notifications.index')]),
            );
    }

    private function activity(): Reply
    {
        $activity = $this->lookups->activity();

        if ($activity === []) {
            return new Reply('There\'s no recent activity on your account yet.');
        }

        return new Reply(
            "Your recent activity:\n".collect($activity)->map(fn (array $entry) => '- '.$entry['text'].' ('.$entry['when'].')')->implode("\n")
            ."\n\nIf you don't recognise something here, change your password and turn on two-step verification.",
            [['label' => 'Account settings', 'url' => route('settings')]],
        );
    }

    private function elections(): Reply
    {
        $elections = $this->lookups->elections();

        if ($elections === []) {
            return new Reply('There\'s no election open right now. Past results stay on the elections page.', [['label' => 'Elections', 'url' => route('elections.index')]]);
        }

        $reasons = collect($elections)
            ->filter(fn (array $election) => $election['reason'] !== null)
            ->map(fn (array $election) => '- **'.$election['label'].'**: '.$election['reason'])
            ->implode("\n");

        return new Reply(
            'Elections open now:'.($reasons !== '' ? "\n\n".$reasons : ''),
            $this->links($elections, ['label' => 'All elections', 'url' => route('elections.index')]),
        );
    }

    private function howToUpload(): Reply
    {
        return new Reply(
            "To share a book or past questions:\n1. Open **Upload** and choose a PDF, or take photos of the pages.\n2. Add the title, author, department and level.\n3. Send it. A reviewer checks it, and you get a notification when it's in the library or needs changes.",
            [['label' => 'Share a book', 'url' => route('uploads.create')], ['label' => 'My uploads', 'url' => route('uploads.index')]],
        );
    }

    private function howTo(string $topic): Reply
    {
        return match ($topic) {
            'password' => new Reply(
                'You can change your password in Settings. Forgot it? Use "Forgot password" on the login page, or ask a class rep or admin for a reset code.',
                [['label' => 'Change password', 'url' => route('password.change')]],
            ),
            'two-step' => new Reply(
                'Two-step verification asks for a code from an authenticator app when you log in, so a stolen password isn\'t enough. Turn it on in Settings and keep your recovery codes somewhere safe.',
                [['label' => 'Account settings', 'url' => route('settings')]],
            ),
            default => new Reply(
                'You can change or confirm your email address in Settings. We send a link to the new address to confirm it.',
                [['label' => 'Account settings', 'url' => route('settings')]],
            ),
        };
    }

    /**
     * @param  list<array{label: string, url: string, note?: string|null}>  $rows
     * @param  array{label: string, url: string}|null  $more
     * @return list<array{label: string, url: string, note?: string|null}>
     */
    private function links(array $rows, ?array $more = null): array
    {
        $links = array_map(fn (array $row) => ['label' => $row['label'], 'url' => $row['url'], 'note' => $row['note'] ?? null], $rows);

        return $more === null ? $links : [...$links, $more];
    }
}
