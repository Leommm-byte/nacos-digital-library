<?php

namespace Tests\Unit;

use App\Enums\Programme;
use App\Support\MatricNumber;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

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

    #[Test]
    public function the_programme_and_nd_or_hnd_are_read_from_the_number(): void
    {
        $this->assertSame(Programme::FullTime, MatricNumber::programme('F/ND/24/1234567'));
        $this->assertSame(Programme::PartTime, MatricNumber::programme('p/hd/23/1234567'));
        $this->assertSame(Programme::Codfel, MatricNumber::programme('D/ND/22/1234567'));
        $this->assertNull(MatricNumber::programme('ND/2019/CS/1234'));

        $this->assertFalse(MatricNumber::isHnd('F/ND/24/1234567'));
        $this->assertTrue(MatricNumber::isHnd('F/HD/24/1234567'));
        $this->assertTrue(MatricNumber::isHnd('F/HND/24/1234567'));
        $this->assertTrue(MatricNumber::isHnd('HND/2021/CS/1234'));
        $this->assertNull(MatricNumber::isHnd('nonsense'));

        // CODFEL is D, not C.
        $this->assertTrue(MatricNumber::isValid('D/ND/24/1234567'));
        $this->assertFalse(MatricNumber::isValid('C/ND/24/1234567'));
    }
}
