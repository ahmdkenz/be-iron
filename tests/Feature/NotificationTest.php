<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * Lonceng Notifikasi Finance: API list/unread-count/mark-read/mark-all-read
 * + otorisasi private channel `App.Models.User.{id}`.
 *
 * Menghindari RefreshDatabase (migrate:fresh rusak di project ini — lihat
 * memory project_test_db_migrate_fresh_broken): tabel `notifications` dibuat
 * ad-hoc mengikuti struktur migration 2026_07_29_000001_create_notifications_table,
 * lalu di-drop lagi di tearDown (pola sama dengan BankStatementImportServiceTest).
 * User login disimulasikan lewat Mockery partial mock (pola sama dengan
 * OpeningBalanceApRoleAccessTest) karena tidak ada User factory yang valid
 * untuk model tb_users di project ini.
 */
class NotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('notifications', function ($table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('notifications');
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Sengaja BUKAN Mockery partial mock (pola di test lain seperti
     * OpeningBalanceApRoleAccessTest): morphMany notifications()/unreadNotifications()
     * butuh get_class($user) === App\Models\User yang sesungguhnya untuk mencocokkan
     * kolom notifiable_type — Mockery::mock() menghasilkan nama kelas proxy yang beda.
     * Instance User polos (tanpa disimpan ke tb_users) sudah cukup karena endpoint
     * notifikasi tidak memanggil hasAnyRole()/relasi karyawan sama sekali.
     */
    private function makeUser(int $id): User
    {
        $user = new User();
        $user->forceFill(['id' => $id, 'username' => 'tester-' . $id]);

        return $user;
    }

    private function insertNotification(int $notifiableId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert(array_merge([
            'id'              => $id,
            'type'            => 'App\\Domain\\Notification\\Notifications\\IronFinanceNotification',
            'notifiable_type' => User::class,
            'notifiable_id'   => $notifiableId,
            'data'            => json_encode([
                'category' => 'opening_balance_ar',
                'severity' => 'info',
                'title'    => 'Opening Balance AR menunggu persetujuan',
                'body'     => 'Contoh notifikasi test.',
            ]),
            'read_at'         => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));

        return $id;
    }

    // ── GET /notifications & /notifications/unread-count ─────────────────────

    public function test_list_hanya_berisi_notifikasi_milik_user_login(): void
    {
        $ownId   = $this->insertNotification(1);
        $otherId = $this->insertNotification(2);

        $response = $this->actingAs($this->makeUser(1))->getJson('/api/v1/notifications');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    public function test_unread_count_hanya_menghitung_milik_user_login(): void
    {
        $this->insertNotification(1);
        $this->insertNotification(1);
        $this->insertNotification(2);

        $response = $this->actingAs($this->makeUser(1))->getJson('/api/v1/notifications/unread-count');

        $response->assertOk()->assertJsonPath('data.count', 2);
    }

    // ── PATCH /notifications/{id}/read ────────────────────────────────────────

    public function test_mark_read_menandai_notifikasi_sendiri(): void
    {
        $id = $this->insertNotification(1);

        $response = $this->actingAs($this->makeUser(1))->patchJson("/api/v1/notifications/{$id}/read");

        $response->assertOk();
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_mark_read_notifikasi_user_lain_ditolak_404(): void
    {
        $otherId = $this->insertNotification(2);

        $response = $this->actingAs($this->makeUser(1))->patchJson("/api/v1/notifications/{$otherId}/read");

        $response->assertNotFound();
        $this->assertNull(DB::table('notifications')->where('id', $otherId)->value('read_at'));
    }

    public function test_mark_read_id_yang_tidak_ada_dianggap_404(): void
    {
        $response = $this->actingAs($this->makeUser(1))
            ->patchJson('/api/v1/notifications/' . Str::uuid() . '/read');

        $response->assertNotFound();
    }

    // ── PATCH /notifications/read-all ─────────────────────────────────────────

    public function test_mark_all_read_hanya_menandai_milik_user_login(): void
    {
        $ownA  = $this->insertNotification(1);
        $ownB  = $this->insertNotification(1);
        $other = $this->insertNotification(2);

        $response = $this->actingAs($this->makeUser(1))->patchJson('/api/v1/notifications/read-all');

        $response->assertOk();
        $this->assertNotNull(DB::table('notifications')->where('id', $ownA)->value('read_at'));
        $this->assertNotNull(DB::table('notifications')->where('id', $ownB)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $other)->value('read_at'));
    }

    // ── routes/channels.php: App.Models.User.{id} ─────────────────────────────

    public function test_private_user_channel_hanya_mengizinkan_user_yang_sama(): void
    {
        $broadcaster = Broadcast::connection();

        $reflection = new ReflectionClass($broadcaster);
        $property   = $reflection->getProperty('channels');
        $property->setAccessible(true);
        $channels = $property->getValue($broadcaster);

        $this->assertArrayHasKey('App.Models.User.{id}', $channels, 'Channel App.Models.User.{id} belum terdaftar di routes/channels.php');

        $callback = $channels['App.Models.User.{id}'];
        $user     = $this->makeUser(5);

        $this->assertTrue((bool) $callback($user, 5));
        $this->assertFalse((bool) $callback($user, 6));
    }
}
