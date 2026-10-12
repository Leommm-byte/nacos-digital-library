<?php

namespace Tests\Feature\Operations;

use App\Enums\Role;
use App\Models\User;
use App\Support\Operations\Alerts;
use App\Support\Operations\Backups;
use App\Support\Operations\DatabaseDump;
use App\Support\Operations\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // A storage folder of its own, so backups and alerts start empty.
        $this->storage = sys_get_temp_dir().'/nacos-ops-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->storage.'/framework');
        $this->app->useStoragePath($this->storage);

        $this->app->instance(DatabaseDump::class, new class extends DatabaseDump
        {
            public function write(string $path): void
            {
                file_put_contents($path, (string) gzencode('-- dump'));
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    /**
     * @return list<Email>
     */
    private function sentMail(): array
    {
        /** @var TransportInterface&ArrayTransport $transport */
        $transport = Mail::mailer('array')->getSymfonyTransport();

        return $transport->messages()->map(fn ($sent) => $sent->getOriginalMessage())->filter(fn ($m) => $m instanceof Email)->values()->all();
    }

    private function live(): void
    {
        $this->app['env'] = 'production';
        config(['operations.alert_email' => 'admin@example.com', 'mail.default' => 'array']);
    }

    #[Test]
    public function the_database_is_dumped_and_copied_off_site(): void
    {
        Storage::fake('backups');
        config(['operations.backups.disk' => 'backups', 'operations.backups.keep_local' => 2]);

        foreach (range(1, 3) as $i) {
            $this->travel(1)->days();
            $this->artisan('backup:run --database')->assertSuccessful();
        }

        // Two kept on the server, all three off-site (all recent).
        $this->assertCount(2, File::glob(Backups::directory().'/db-*.sql.gz'));
        $this->assertCount(3, Storage::disk('backups')->files('db'));

        $status = Backups::status();
        $this->assertTrue($status['database_offsite']);
        $this->get('/up/backups')->assertOk()->assertSee('OK');

        // Two days later, the backup is too old.
        $this->travel(2)->days();
        $this->get('/up/backups')->assertStatus(503)->assertSee('No database backup');
    }

    #[Test]
    public function without_off_site_storage_the_dump_stays_local_and_the_check_says_so(): void
    {
        $this->artisan('backup:run')->assertSuccessful()->expectsOutputToContain('no off-site storage');

        $this->assertCount(1, File::glob(Backups::directory().'/db-*.sql.gz'));
        $this->get('/up/backups')->assertStatus(503)->assertSee('not copied off-site');
    }

    #[Test]
    public function off_site_dumps_keep_the_last_of_each_day_week_and_month(): void
    {
        config(['operations.backups.keep_daily' => 7, 'operations.backups.keep_weekly' => 4, 'operations.backups.keep_monthly' => 6]);
        $now = Carbon::parse('2026-10-12 03:00', 'UTC');

        $paths = [];
        // Twice a day for 200 days.
        for ($day = 0; $day < 200; $day++) {
            foreach (['013000', '133000'] as $time) {
                $paths[] = 'db/db-'.$now->copy()->subDays($day)->format('Ymd').'-'.$time.'.sql.gz';
            }
        }
        $paths[] = 'db/notes.txt';

        $expired = Backups::expired($paths, $now);
        $kept = array_values(array_diff($paths, $expired));

        // The newest is kept; the morning one of the same day goes.
        $this->assertContains('db/db-20261012-133000.sql.gz', $kept);
        $this->assertContains('db/db-20261012-013000.sql.gz', $expired);
        // About 7 days, plus older weeks and months, and never other files.
        $this->assertNotContains('db/notes.txt', $expired);
        $this->assertLessThanOrEqual(7 + 4 + 6 + 1, count($kept));
        $this->assertGreaterThanOrEqual(12, count($kept));
        // Nothing older than six months.
        $this->assertContains('db/db-'.$now->copy()->subDays(199)->format('Ymd').'-133000.sql.gz', $expired);
    }

    #[Test]
    public function uploaded_files_are_copied_off_site_a_little_at_a_time(): void
    {
        Storage::fake('backups');
        config(['operations.backups.disk' => 'backups', 'operations.backups.files_per_run_mb' => 1]);

        $private = $this->storage.'/app/private/library';
        File::ensureDirectoryExists($private.'/books');
        File::put($private.'/books/a.pdf', str_repeat('a', 700 * 1024));
        File::put($private.'/books/b.pdf', str_repeat('b', 700 * 1024));
        File::put($private.'/cover.jpg', 'jpg');

        // The first run fits about 1 MB, the rest waits.
        $first = app(Backups::class)->files();
        $this->assertSame(1, $first['waiting']);
        $second = app(Backups::class)->files();
        $this->assertSame(1, $second['copied']);
        $this->assertSame(0, $second['waiting']);

        Storage::disk('backups')->assertExists(['files/library/books/a.pdf', 'files/library/books/b.pdf', 'files/library/cover.jpg']);

        // Nothing new: nothing copied. A changed file is copied again.
        $this->assertSame(0, app(Backups::class)->files()['copied']);
        File::put($private.'/cover.jpg', 'a new cover');
        $this->assertSame(1, app(Backups::class)->files()['copied']);
        $this->assertSame('a new cover', Storage::disk('backups')->get('files/library/cover.jpg'));
    }

    #[Test]
    public function a_failed_backup_is_recorded_and_emailed(): void
    {
        $this->live();
        $this->app->instance(DatabaseDump::class, new class extends DatabaseDump
        {
            public function write(string $path): void
            {
                throw new RuntimeException('mysqldump: Access denied');
            }
        });

        $this->artisan('backup:run --database')->assertFailed();

        $this->assertSame('mysqldump: Access denied', Backups::status()['failure']);
        $subjects = array_map(fn (Email $mail) => $mail->getSubject(), $this->sentMail());
        $this->assertContains('[NACOS YabaTech] Backup failed', $subjects);
    }

    #[Test]
    public function the_scheduler_check_notices_when_the_cron_stops(): void
    {
        $this->get('/up/scheduler')->assertStatus(503)->assertSee('has not run yet');

        Heartbeat::beat();
        $this->get('/up/scheduler')->assertOk();
        // No session started for the monitor.
        $this->assertSame([], $this->get('/up/scheduler')->headers->getCookies());

        $this->travel(6)->minutes();
        $this->get('/up/scheduler')->assertStatus(503);
    }

    #[Test]
    public function errors_on_the_live_site_are_emailed_once_an_hour_with_a_count(): void
    {
        $error = new RuntimeException('Database went away');

        // Not outside production, or without an address.
        Alerts::error($error);
        $this->assertSame([], $this->sentMail());

        $this->live();
        Alerts::error($error);
        Alerts::error($error);
        Alerts::error($error);
        $this->assertCount(1, $this->sentMail());
        $mail = $this->sentMail()[0];
        $this->assertSame('[NACOS YabaTech] RuntimeException: Database went away', $mail->getSubject());
        $this->assertSame('admin@example.com', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('File: tests/Feature/Operations/OperationsTest.php', (string) $mail->getTextBody());

        // An hour later: sent again, saying how often it happened meanwhile.
        $this->travel(61)->minutes();
        Alerts::error($error);
        $this->assertCount(2, $this->sentMail());
        $this->assertStringContainsString('It also happened 2 more times', (string) $this->sentMail()[1]->getTextBody());
    }

    #[Test]
    public function no_more_than_ten_error_emails_an_hour_and_links_are_hidden(): void
    {
        $this->live();

        // Different errors (different lines), on a page with a token in its address.
        $this->get('/invitation/'.str_repeat('x', 48));
        for ($i = 0; $i < 15; $i++) {
            Alerts::error(new class("Error {$i}", $i) extends RuntimeException
            {
                public function __construct(string $message, int $line)
                {
                    parent::__construct($message);
                    $this->line = $line;
                }
            });
        }

        $this->assertCount(10, $this->sentMail());
        $this->assertStringNotContainsString(str_repeat('x', 48), (string) $this->sentMail()[0]->getTextBody());
    }

    #[Test]
    public function a_warning_is_emailed_when_the_disk_fills_up(): void
    {
        $this->live();
        config(['operations.disk_warn_gb' => 0.000001]);
        File::ensureDirectoryExists($this->storage.'/app');
        File::put($this->storage.'/app/big.bin', str_repeat('x', 10_000));

        $this->artisan('ops:disk')->assertSuccessful();

        $this->assertSame('[NACOS YabaTech] Disk space is running low', $this->sentMail()[0]->getSubject());
    }

    #[Test]
    public function admins_see_the_site_health_on_their_dashboard(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Site health')->assertSee('Not running')->assertSee('Overdue')->assertSee('Not deployed by the Deploy workflow');

        Heartbeat::beat();
        Storage::fake('backups');
        config(['operations.backups.disk' => 'backups']);
        $this->artisan('backup:run --database');

        $this->get(route('admin.dashboard'))->assertSee('Running')->assertSee('Up to date')->assertDontSee('Overdue');
    }

    #[Test]
    public function the_first_admin_is_made_from_the_command_line(): void
    {
        $user = User::factory()->create(['matric_number' => 'F/ND/24/1234567']);

        $this->artisan('users:make-admin', ['matric' => 'f/nd/24/1234567'])->assertSuccessful();
        $this->assertSame(Role::Admin, $user->fresh()?->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_role_changed']);

        $this->artisan('users:make-admin', ['matric' => 'F/ND/24/0000000'])->assertFailed();
    }

    #[Test]
    public function mysql_is_dumped_with_mysqldump(): void
    {
        $binary = trim((string) shell_exec('command -v mysqldump'));
        if (DB::connection()->getDriverName() !== 'mysql' || $binary === '') {
            $this->markTestSkipped('Needs MySQL and mysqldump (CI has both).');
        }

        $path = $this->storage.'/dump.sql.gz';
        (new DatabaseDump)->write($path);

        $sql = (string) gzdecode((string) file_get_contents($path));
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        // The password never lingers in a file.
        $this->assertSame([], File::glob($this->storage.'/.mysqldump-*'));
    }
}
