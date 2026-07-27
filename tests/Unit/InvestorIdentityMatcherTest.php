<?php

namespace Tests\Unit;

use App\Models\Investor;
use App\Support\Helpers\InvestorIdentityMatcher;
use PHPUnit\Framework\TestCase;

class InvestorIdentityMatcherTest extends TestCase
{
    private function makeInvestor(?string $nama, ?string $ktp): Investor
    {
        $investor = new Investor();
        $investor->forceFill(['nama_investor' => $nama, 'ktp' => $ktp]);

        return $investor;
    }

    public function test_ktp_sama_beda_format_dash_dianggap_match(): void
    {
        $a = $this->makeInvestor('ABDUL KHOLIK', '32011-51212-96000-2');
        $b = $this->makeInvestor('ABDUL KHOLIK', '320115121296000-2');

        $this->assertTrue(InvestorIdentityMatcher::matches($a, $b));
    }

    public function test_ktp_kosong_keduanya_fallback_ke_nama_ternormalisasi(): void
    {
        $a = $this->makeInvestor('ARIS MUNANDAR S.Kom', null);
        $b = $this->makeInvestor('ARIS MUNANDAR, S.Kom', null);

        $this->assertTrue(InvestorIdentityMatcher::matches($a, $b));
    }

    public function test_ktp_beda_meski_nama_sama_dianggap_tidak_match(): void
    {
        $a = $this->makeInvestor('BUDI SANTOSO', '3201111111111111');
        $b = $this->makeInvestor('BUDI SANTOSO', '3201122222222222');

        $this->assertFalse(InvestorIdentityMatcher::matches($a, $b));
    }

    public function test_nama_beda_jauh_dan_ktp_kosong_tidak_match(): void
    {
        $a = $this->makeInvestor('ARIS MUNANDAR', null);
        $b = $this->makeInvestor('BUDI SANTOSO', null);

        $this->assertFalse(InvestorIdentityMatcher::matches($a, $b));
    }

    public function test_salah_satu_ktp_kosong_fallback_nama_tetap_match(): void
    {
        $a = $this->makeInvestor('SITI AMINAH', '3201133333333333');
        $b = $this->makeInvestor('SITI AMINAH', null);

        $this->assertTrue(InvestorIdentityMatcher::matches($a, $b));
    }

    public function test_investor_yang_sama_dengan_dirinya_sendiri_match(): void
    {
        $a = $this->makeInvestor('ARIS MUNANDAR S.Kom', '32021-00804-82000-8');

        $this->assertTrue(InvestorIdentityMatcher::matches($a, $a));
    }
}
