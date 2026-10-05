<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    #[Test]
    public function it_matches_the_rfc_6238_test_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $time => $code) {
            $this->assertSame($code, Totp::at($secret, Totp::stepFor($time), 8), "T = {$time}");
        }
    }

    #[Test]
    public function codes_are_accepted_one_step_either_side_for_clock_drift(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $step = Totp::stepFor($now);
        $code = Totp::at($secret, $step);

        $this->assertSame($step, Totp::verify($secret, $code, $now));
        $this->assertSame($step, Totp::verify($secret, $code, $now + Totp::PERIOD));
        $this->assertSame($step, Totp::verify($secret, $code, $now - Totp::PERIOD));
        $this->assertNull(Totp::verify($secret, $code, $now + 3 * Totp::PERIOD));
    }

    #[Test]
    public function a_used_step_is_refused(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $code = Totp::at($secret, Totp::stepFor($now));

        $this->assertNull(Totp::verify($secret, $code, $now, afterStep: Totp::stepFor($now)));
    }

    #[Test]
    public function spaces_are_ignored_and_junk_is_refused(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $code = Totp::at($secret, Totp::stepFor($now));

        $this->assertNotNull(Totp::verify($secret, substr($code, 0, 3).' '.substr($code, 3), $now));
        $this->assertNull(Totp::verify($secret, 'abcdef', $now));
        $this->assertNull(Totp::verify($secret, '12345', $now));
    }

    #[Test]
    public function secrets_are_160_bit_base32(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
    }

    #[Test]
    public function the_uri_is_what_authenticator_apps_expect(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'F/ND/24/1234567', 'NACOS YabaTech');

        $this->assertSame(
            'otpauth://totp/NACOS%20YabaTech:F%2FND%2F24%2F1234567?secret=JBSWY3DPEHPK3PXP&issuer=NACOS%20YabaTech&algorithm=SHA1&digits=6&period=30',
            $uri,
        );
    }
}
