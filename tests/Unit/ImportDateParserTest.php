<?php

namespace Tests\Unit;

use App\Support\Helpers\ImportDateParser;
use PHPUnit\Framework\TestCase;

class ImportDateParserTest extends TestCase
{
    public function test_format_dd_mm_yyyy(): void
    {
        $this->assertSame('2026-05-28', ImportDateParser::parse('28-05-2026'));
    }

    public function test_format_yyyy_mm_dd(): void
    {
        $this->assertSame('2026-05-28', ImportDateParser::parse('2026-05-28'));
    }

    public function test_nama_bulan_indonesia(): void
    {
        $this->assertSame('2026-05-28', ImportDateParser::parse('28 Mei 2026'));
    }

    public function test_nama_bulan_indonesia_case_insensitive(): void
    {
        $this->assertSame('2026-05-28', ImportDateParser::parse('28 mei 2026'));
    }

    public function test_semua_nama_bulan_indonesia(): void
    {
        $bulan = [
            'Januari' => '01', 'Februari' => '02', 'Maret' => '03', 'April' => '04',
            'Mei' => '05', 'Juni' => '06', 'Juli' => '07', 'Agustus' => '08',
            'September' => '09', 'Oktober' => '10', 'November' => '11', 'Desember' => '12',
        ];

        foreach ($bulan as $nama => $num) {
            $this->assertSame("2026-{$num}-01", ImportDateParser::parse("1 {$nama} 2026"), "Gagal parse bulan {$nama}");
        }
    }

    public function test_format_dd_mon_yy_singkatan_inggris(): void
    {
        $this->assertSame('2026-07-01', ImportDateParser::parse('01-Jul-26'));
    }

    public function test_semua_bulan_singkatan_inggris(): void
    {
        $bulan = [
            'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
            'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
            'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12',
        ];

        foreach ($bulan as $nama => $num) {
            $this->assertSame("2026-{$num}-01", ImportDateParser::parse("01-{$nama}-26"), "Gagal parse bulan {$nama}");
        }
    }

    public function test_singkatan_bulan_inggris_salah_mengembalikan_null(): void
    {
        $this->assertNull(ImportDateParser::parse('01-Xyz-26'));
    }

    public function test_serial_excel_numerik(): void
    {
        // 46170 = 28 Mei 2026 (serial Excel, epoch 1900).
        $this->assertSame('2026-05-28', ImportDateParser::parse(46170));
    }

    public function test_nama_bulan_salah_eja_mengembalikan_null(): void
    {
        $this->assertNull(ImportDateParser::parse('28 Mmei 2026'));
    }

    public function test_string_acak_mengembalikan_null_bukan_passthrough(): void
    {
        $this->assertNull(ImportDateParser::parse('tanggal ngasal'));
    }

    public function test_kosong_dan_dash_mengembalikan_null(): void
    {
        $this->assertNull(ImportDateParser::parse(''));
        $this->assertNull(ImportDateParser::parse('-'));
        $this->assertNull(ImportDateParser::parse(null));
    }
}
