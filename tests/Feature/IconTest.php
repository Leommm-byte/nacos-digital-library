<?php

namespace Tests\Feature;

use App\Support\Icons;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IconTest extends TestCase
{
    #[Test]
    public function icons_render_as_inline_svg_hidden_from_screen_readers(): void
    {
        $html = trim(Blade::render('<x-icon name="house" class="size-6" />'));

        $this->assertStringStartsWith('<svg', $html);
        $this->assertStringContainsString('class="icon size-6"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('<path', $html);
        $this->assertStringNotContainsString('@license', $html);
    }

    #[Test]
    public function labelled_icons_are_announced(): void
    {
        $html = trim(Blade::render('<x-icon name="search" label="Search" />'));

        $this->assertStringContainsString('role="img" aria-label="Search"', $html);
        $this->assertStringNotContainsString('aria-hidden', $html);
    }

    #[Test]
    public function unknown_icons_fail_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Icons::body('../../.env');
    }
}
