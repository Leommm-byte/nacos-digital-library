<?php

namespace Tests\Unit;

use App\Support\Assistant\Format;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AssistantFormatTest extends TestCase
{
    #[Test]
    public function it_splits_paragraphs_lists_and_bold_text(): void
    {
        $blocks = Format::blocks("## Stacks\nA stack is **last in, first out**.\nLike plates.\n\n- push adds\n- pop removes\n\n1. First\n2) Second");

        $this->assertSame([
            ['type' => 'p', 'items' => [[
                ['text' => 'Stacks', 'bold' => true],
                ['text' => "\n", 'bold' => false],
                ['text' => 'A stack is ', 'bold' => false],
                ['text' => 'last in, first out', 'bold' => true],
                ['text' => '.', 'bold' => false],
                ['text' => "\n", 'bold' => false],
                ['text' => 'Like plates.', 'bold' => false],
            ]]],
            ['type' => 'ul', 'items' => [
                [['text' => 'push adds', 'bold' => false]],
                [['text' => 'pop removes', 'bold' => false]],
            ]],
            ['type' => 'ol', 'items' => [
                [['text' => 'First', 'bold' => false]],
                [['text' => 'Second', 'bold' => false]],
            ]],
        ], $blocks);
    }

    #[Test]
    public function an_unpaired_marker_stays_as_written(): void
    {
        $this->assertSame(
            [['type' => 'p', 'items' => [[['text' => '2 ** 3 is 8', 'bold' => false]]]]],
            Format::blocks('2 ** 3 is 8'),
        );
    }
}
