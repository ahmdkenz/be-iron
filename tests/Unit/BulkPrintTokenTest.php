<?php

namespace Tests\Unit;

use App\Models\BulkPrintToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * tb_bulk_print_tokens dibuat ad-hoc via Schema::create/dropIfExists (bukan
 * RefreshDatabase) karena migrate:fresh rusak di project ini — lihat
 * project_test_db_migrate_fresh_broken di memory, pola sama seperti
 * InvoiceBulkB2CInvestorTest.
 */
class BulkPrintTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tb_bulk_print_tokens', function (Blueprint $table) {
            $table->uuid('token')->primary();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tb_bulk_print_tokens');
        parent::tearDown();
    }

    public function test_create_mengisi_token_uuid_otomatis_dan_payload_bulat_balik_sebagai_array(): void
    {
        $payload = ['i' => [1, 2, 3], 'v' => 10, 'k' => 20];

        $row = BulkPrintToken::create(['payload' => $payload]);

        $this->assertNotEmpty($row->token);
        $this->assertTrue(Str::isUuid($row->token));

        $fetched = BulkPrintToken::find($row->token);
        $this->assertSame($payload, $fetched->payload);
    }

    public function test_cleanup_command_hapus_token_lebih_dari_30_hari_tapi_bukan_yang_masih_baru(): void
    {
        $old = BulkPrintToken::create(['payload' => ['i' => [1]]]);
        DB::table('tb_bulk_print_tokens')->where('token', $old->token)
            ->update(['created_at' => now()->subDays(31)]);

        $fresh = BulkPrintToken::create(['payload' => ['i' => [2]]]);

        $this->artisan('bulk-print-token:cleanup')->assertSuccessful();

        $this->assertDatabaseMissing('tb_bulk_print_tokens', ['token' => $old->token]);
        $this->assertDatabaseHas('tb_bulk_print_tokens', ['token' => $fresh->token]);
    }
}
