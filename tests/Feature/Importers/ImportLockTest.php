<?php

namespace Tests\Feature\Importers;

use App\Importers\FawzanImporter;
use App\Importers\ImportLock;
use App\Importers\ImportLockLost;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ImportLockTest extends TestCase
{
    use RefreshDatabase;

    private function bootId(): string
    {
        return trim((string) @file_get_contents('/proc/sys/kernel/random/boot_id'));
    }

    private function deadOwner(): string
    {
        return gethostname().'|'.$this->bootId().'|999999999|dead';
    }

    public function test_takeover_is_a_compare_and_swap(): void
    {
        Cache::put('import-lock:fawzan', $this->deadOwner(), 600);

        $first = ImportLock::takeOver('fawzan', $this->deadOwner(), 'owner-a');
        $second = ImportLock::takeOver('fawzan', $this->deadOwner(), 'owner-b');

        $this->assertTrue($first);
        $this->assertFalse($second, 'a second process that also saw the dead owner must not win');
        $this->assertSame('owner-a', Cache::get('import-lock:fawzan'));
    }

    public function test_dead_owner_on_this_machine_is_taken_over(): void
    {
        $this->assertNotSame('', $this->bootId(), 'this test needs /proc (Linux)');
        Cache::put('import-lock:fawzan', $this->deadOwner(), 600);

        $this->assertSame('ran', ImportLock::run('fawzan', fn () => 'ran'));
        $this->assertNull(Cache::get('import-lock:fawzan'));
    }

    public function test_owner_from_another_machine_or_boot_is_waited_out(): void
    {
        Cache::put('import-lock:fawzan', 'other-host|'.$this->bootId().'|999999999|x', 600);
        $this->assertFalse(ImportLock::run('fawzan', fn () => 'ran'));

        Cache::put('import-lock:fawzan', gethostname().'|another-boot|999999999|x', 600);
        $this->assertFalse(ImportLock::run('fawzan', fn () => 'ran'));
    }

    public function test_lost_lock_stops_the_import(): void
    {
        Source::query()->create(['key' => 'fawzan', 'kind' => 'fatwa', 'tier' => 3, 'name_ar' => 'الفوزان', 'name_en' => 'Fawzan', 'base_url' => 'https://shamela.ws/book/8612']);

        $this->expectException(ImportLockLost::class);

        try {
            ImportLock::run('fawzan', function () {
                Cache::put('import-lock:fawzan', 'someone-else', 600);
                $this->travel(61)->seconds();
                ImportLock::heartbeat('fawzan');

                return 'kept going';
            });
        } finally {
            $this->assertSame('someone-else', Cache::get('import-lock:fawzan'), 'the other owner keeps the lock');
        }
    }

    public function test_heartbeat_extends_a_lock_we_hold(): void
    {
        $result = ImportLock::run('fawzan', function () {
            $this->travel(61)->seconds();
            ImportLock::heartbeat('fawzan');

            return Cache::get('import-lock:fawzan');
        });

        $this->assertSame(ImportLock::token(), $result);
    }

    public function test_heartbeat_outside_a_lock_is_a_no_op(): void
    {
        ImportLock::heartbeat('fawzan');

        $this->assertNull(Cache::get('import-lock:fawzan'));
        $this->assertInstanceOf(FawzanImporter::class, app(FawzanImporter::class));
    }
}
