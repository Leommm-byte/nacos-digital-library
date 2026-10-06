<?php

namespace App\Support\Uploads;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Illuminate\Support\Facades\Log;

/**
 * Reads the text of one scanned page with Claude's vision, for search. Used
 * by ImproveScanPage only when an API key is configured.
 *
 * Returns null when the page can't be read this way (a declined or invalid
 * request), so the text read on the uploader's device is kept. Rate limits,
 * timeouts, connection and server errors are thrown, so the queue retries.
 */
class PageReader
{
    private const PROMPT = 'This is a photo or scan of one page from a study book or past question paper. '
        .'Transcribe all of the text on the page exactly as written, in reading order, keeping line breaks '
        .'between paragraphs, headings, list items and questions. Write equations and code as plain text. '
        .'Skip anything you cannot read; do not guess or add words. Reply with the text only, without '
        .'commentary. If the page has no text, reply with nothing.';

    public static function enabled(): bool
    {
        return (string) config('services.anthropic.key') !== '';
    }

    public function read(string $jpeg): ?string
    {
        $client = new Client(apiKey: (string) config('services.anthropic.key'));

        try {
            $message = $client->beta->messages->create(
                maxTokens: 16000,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
                        ['type' => 'text', 'text' => self::PROMPT],
                    ],
                ]],
                model: (string) config('services.anthropic.model'),
                // Transcription doesn't need deep reasoning.
                outputConfig: ['effort' => 'low'],
                // If the model declines a page, the API retries it on a
                // suitable fallback model within the same call.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (BadRequestException|AuthenticationException|PermissionDeniedException $e) {
            // Retrying won't help: a bad key, no access, or a page the API
            // won't accept. Keep the device text.
            Log::warning('Scanned page could not be read by AI.', ['error' => $e->getMessage()]);

            return null;
        }

        if ($message->stopReason === 'refusal') {
            return null;
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        return trim($text);
    }
}
