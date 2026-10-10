<?php

namespace App\Support\Assistant;

use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers a student's message and keeps the chat. The AI answers while
 * it's available and the student is under the daily limit; otherwise, or
 * if the AI call fails, the free helper does.
 */
class Assistant
{
    public function __construct(private User $user) {}

    public static function dailyLimit(): int
    {
        return max(0, (int) config('assistant.daily_limit'));
    }

    public static function aiEnabled(): bool
    {
        return Tutor::available() && self::dailyLimit() > 0;
    }

    /**
     * AI answers left today, or null when AI is off.
     */
    public function remaining(): ?int
    {
        if (! self::aiEnabled()) {
            return null;
        }

        $used = AssistantMessage::query()
            ->where('user_id', $this->user->id)
            ->where('role', 'user')
            ->where('ai', true)
            // "Today" in Lagos, compared in the database's time zone.
            ->where('created_at', '>=', now()->timezone((string) config('app.display_timezone'))->startOfDay()->timezone((string) config('app.timezone')))
            ->count();

        return max(0, self::dailyLimit() - $used);
    }

    /**
     * @return array{question: AssistantMessage, answer: AssistantMessage, reply: Reply}
     */
    public function ask(string $message): array
    {
        $remaining = $this->remaining();
        $reply = null;

        if ($remaining !== null && $remaining > 0) {
            try {
                $history = AssistantMessage::query()
                    ->where('user_id', $this->user->id)
                    ->latest('id')
                    ->limit((int) config('assistant.context'))
                    ->get()
                    ->reverse()
                    ->values();

                $reply = app(Tutor::class, ['user' => $this->user])->answer($message, $history);
            } catch (Throwable $e) {
                Log::warning('Assistant AI answer failed; the helper answered instead.', ['error' => $e->getMessage()]);
            }
        }

        if ($reply === null) {
            $reply = (new Helper($this->user))->answer($message);

            if ($remaining === 0) {
                $reply = new Reply(
                    'You\'ve used today\'s '.self::dailyLimit().' smart answers, so here\'s a quick one. They come back tomorrow.'."\n\n".$reply->text,
                    $reply->links,
                    $reply->suggestions,
                );
            }
        }

        $question = AssistantMessage::create([
            'user_id' => $this->user->id,
            'role' => 'user',
            'body' => $message,
            'ai' => $reply->ai,
        ]);

        $answer = AssistantMessage::create([
            'user_id' => $this->user->id,
            'role' => 'assistant',
            'body' => $reply->text,
            'links' => $reply->links === [] ? null : $reply->links,
            'ai' => $reply->ai,
        ]);

        $this->prune();

        return ['question' => $question, 'answer' => $answer, 'reply' => $reply];
    }

    /**
     * The chat, oldest first.
     *
     * @return list<AssistantMessage>
     */
    public function history(): array
    {
        return AssistantMessage::query()
            ->where('user_id', $this->user->id)
            ->latest('id')
            ->limit((int) config('assistant.history'))
            ->get()
            ->reverse()
            ->values()
            ->all();
    }

    public function clear(): void
    {
        AssistantMessage::query()->where('user_id', $this->user->id)->delete();
    }

    /**
     * A message as the page draws it.
     *
     * @param  list<string>  $suggestions
     * @return array<string, mixed>
     */
    public static function present(AssistantMessage $message, array $suggestions = []): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'blocks' => Format::blocks($message->body),
            'links' => $message->links ?? [],
            'suggestions' => $suggestions,
            'ai' => $message->ai,
        ];
    }

    /**
     * Keeps the latest messages only.
     */
    private function prune(): void
    {
        $oldest = AssistantMessage::query()
            ->where('user_id', $this->user->id)
            ->orderByDesc('id')
            ->skip((int) config('assistant.history'))
            ->value('id');

        if ($oldest !== null) {
            AssistantMessage::query()->where('user_id', $this->user->id)->where('id', '<=', $oldest)->delete();
        }
    }
}
