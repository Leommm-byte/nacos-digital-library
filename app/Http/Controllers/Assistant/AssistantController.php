<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Support\Assistant\Assistant;
use App\Support\Assistant\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The assistant chat. The floating panel (resources/js/assistant.js) talks
 * to it with JSON; without JavaScript the same routes serve a full page
 * with an ordinary form. Every message is a CSRF-checked POST, and answers
 * are sent as text blocks and links, never HTML.
 */
class AssistantController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $assistant = new Assistant($this->user($request));
        $history = $assistant->history();

        if ($request->wantsJson()) {
            return response()->json([
                'messages' => array_map(fn (AssistantMessage $message) => Assistant::present($message), $history),
                'ai' => Assistant::aiEnabled(),
                'remaining' => $assistant->remaining(),
                'suggestions' => Helper::MENU,
            ]);
        }

        return view('assistant.index', [
            'messages' => $history,
            'ai' => Assistant::aiEnabled(),
            'remaining' => $assistant->remaining(),
            'suggestions' => Helper::MENU,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ], [
            'message.required' => 'Type a question first.',
            'message.max' => 'Keep questions under 1,000 characters.',
        ]);

        $assistant = new Assistant($this->user($request));
        $result = $assistant->ask(trim((string) $data['message']));

        if (! $request->wantsJson()) {
            return redirect()->to(route('assistant.index').'#latest');
        }

        return response()->json([
            'messages' => [
                Assistant::present($result['question']),
                Assistant::present($result['answer'], $result['reply']->suggestions),
            ],
            'remaining' => $assistant->remaining(),
        ]);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        (new Assistant($this->user($request)))->clear();

        return $request->wantsJson()
            ? response()->json(['cleared' => true])
            : redirect()->route('assistant.index')->with('status', 'Chat cleared.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
