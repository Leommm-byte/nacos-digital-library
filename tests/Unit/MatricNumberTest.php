<?php

namespace Tests\Unit;

use App\Support\MatricNumber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MatricNumberTest extends TestCase
{
    #[Test]
    public function the_entry_year_is_read_from_both_formats(): void
    {
        $this->assertSame(2024, MatricNumber::entryYear('F/ND/24/1234567'));
        $this->assertSame(2019, MatricNumber::entryYear(' p/hd/19/0000001 '));
        $this->assertSame(2021, MatricNumber::entryYear('HND/2021/CS/1234'));
        $this->assertNull(MatricNumber::entryYear('nonsense'));
        $this->assertNull(MatricNumber::entryYear(null));
    }
}
