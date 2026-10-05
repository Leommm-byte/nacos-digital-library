<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pages_send_a_strict_content_security_policy(): void
    {
        $csp = $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    #[Test]
    public function the_inline_theme_script_carries_the_policy_nonce(): void
    {
        $response = $this->get('/');

        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $match);

        $this->assertNotEmpty($match[1] ?? null);
        $response->assertSee('<script nonce="'.$match[1].'">', false);
    }

    #[Test]
    public function each_request_gets_a_fresh_nonce(): void
    {
        $first = $this->get('/')->headers->get('Content-Security-Policy');
        $second = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function https_requests_upgrade_insecure_subresources(): void
    {
        $csp = $this->get('https://localhost/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('upgrade-insecure-requests', (string) $csp);
    }

    #[Test]
    public function the_layout_has_landmarks_and_a_skip_link(): void
    {
        $this->get('/')
            ->assertSee('href="#main"', false)
            ->assertSee('<main id="main"', false)
            ->assertSee('<nav aria-label="Main"', false)
            ->assertSee('data-theme-toggle', false);
    }

    #[Test]
    public function guests_do_not_see_the_account_menu(): void
    {
        $this->get('/')->assertDontSee('Account menu');
    }

    #[Test]
    public function signed_in_users_see_their_account_menu(): void
    {
        $user = User::factory()->create(['fullname' => 'Ada Obi']);

        $this->actingAs($user)
            ->get('/')
            ->assertSee('Account menu')
            ->assertSee('Ada Obi')
            ->assertSee($user->matric_number);
    }

    #[Test]
    public function navigation_skips_screens_that_do_not_exist_yet(): void
    {
        $this->get('/');

        $routes = array_column(Navigation::primary(null), 'route');

        $this->assertSame(['home'], $routes);
    }

    #[Test]
    public function navigation_shows_signed_in_items_only_to_users_and_marks_the_active_one(): void
    {
        Route::get('/library', fn () => 'library')->name('library.index');
        Route::get('/saved', fn () => 'saved')->name('bookmarks.index');
        Route::getRoutes()->refreshNameLookups();

        $this->get('/');

        $this->assertSame(['home', 'library.index'], array_column(Navigation::primary(null), 'route'));

        $items = Navigation::primary(User::factory()->role(Role::Student)->make());

        $this->assertSame(['home', 'library.index', 'bookmarks.index'], array_column($items, 'route'));
        $this->assertSame([true, false, false], array_column($items, 'active'));
    }

    #[Test]
    public function every_navigation_icon_exists(): void
    {
        $user = User::factory()->make();

        foreach (['library.index', 'bookmarks.index', 'elections.index', 'profile.edit'] as $name) {
            Route::get('/'.$name, fn () => '')->name($name);
        }
        Route::getRoutes()->refreshNameLookups();

        foreach (Navigation::primary($user) as $item) {
            $this->assertFileExists(resource_path("icons/{$item['icon']}.svg"));
        }
    }

    #[Test]
    public function the_style_guide_renders_outside_production(): void
    {
        $this->get('/styleguide')
            ->assertOk()
            ->assertSee('Style guide')
            ->assertSee('class="btn btn-primary"', false);
    }

    #[Test]
    public function error_pages_use_the_app_design(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Go to the home page')
            ->assertSee('noindex', false)
            ->assertHeader('Content-Security-Policy');
    }
}
