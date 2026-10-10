<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The load test (tests/load) found students signed out right after asking
 * the assistant. These requests carry only the cookies a browser would,
 * with sessions in the database as on the server.
 */
class AssistantSessionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database', 'services.anthropic.key' => '']);
    }

    #[Test]
    public function asking_the_assistant_keeps_the_student_signed_in(): void
    {
        $user = User::factory()->create(['matric_number' => 'F/ND/25/0000001']);

        $this->send('POST', route('login'), ['matric_number' => $user->matric_number, 'password' => 'Password1!'])
            ->assertRedirect(route('home'));
        $this->send('GET', route('home'))->assertOk()->assertSee('data-user=', false);
        $this->send('GET', route('bookmarks.index'))->assertOk();

        $this->send('POST', route('assistant.store'), ['message' => 'Find books on networking'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->send('GET', route('home'))->assertOk()->assertSee('data-user=', false);
        $this->send('GET', route('library.index'))->assertOk();
    }

    /**
     * One request from a fresh app state, like a new PHP process: only the
     * cookies carry the session.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function send(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $response = $this->withCookies($this->jar)->call($method, $uri, $data, $this->prepareCookiesForRequest(), [], $this->transformHeadersToServerVars($headers));

        foreach ($response->headers->getCookies() as $cookie) {
            $this->jar[$cookie->getName()] = (string) $response->getCookie($cookie->getName())?->getValue();
        }

        return $response;
    }
}
