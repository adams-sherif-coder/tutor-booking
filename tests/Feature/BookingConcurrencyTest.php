<?php

namespace Tests\Feature;

use App\Models\Availability;
use App\Models\Booking;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real concurrent locking tests require MySQL; run with phpunit.mysql.xml.');
        }
        if (! str_ends_with(DB::getDatabaseName(), '_test')) {
            $this->fail('Concurrency tests may only reset a database ending in _test.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    private function tutor(): User
    {
        $tutor = User::factory()->tutor()->hasAttached(Subject::factory())->create();
        Availability::factory()->create(['tutor_id' => $tutor->id]);

        return $tutor;
    }

    private function data(User $tutor): array
    {
        return ['tutor_id' => $tutor->id, 'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00'];
    }

    public function test_two_students_cannot_reserve_same_tutor_time_concurrently(): void
    {
        $tutor = $this->tutor();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $statuses = $this->race($tutor->id, [[$first, 'POST', '/api/bookings', $this->data($tutor)], [$second, 'POST', '/api/bookings', $this->data($tutor)]]);
        $this->assertSame([201, 422], $statuses);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_same_student_cannot_reserve_different_tutors_concurrently(): void
    {
        $student = User::factory()->create();
        $first = $this->tutor();
        $second = $this->tutor();
        $statuses = $this->race($student->id, [[$student, 'POST', '/api/bookings', $this->data($first)], [$student, 'POST', '/api/bookings', $this->data($second)]]);
        $this->assertSame([201, 422], $statuses);
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_reschedule_and_new_booking_cannot_claim_same_time(): void
    {
        $tutor = $this->tutor();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $booking = Booking::factory()->create(['student_id' => $first->id, 'tutor_id' => $tutor->id, 'starts_at' => now()->addDays(3)->setTime(12, 0), 'ends_at' => now()->addDays(3)->setTime(13, 0)]);
        $statuses = $this->race($tutor->id, [[$first, 'PATCH', '/api/bookings/'.$booking->id.'/reschedule', collect($this->data($tutor))->except('tutor_id')->all()], [$second, 'POST', '/api/bookings', $this->data($tutor)]]);
        $this->assertTrue(in_array($statuses, [[200, 422], [201, 422]], true));
        $this->assertSame(1, Booking::active()->whereTime('starts_at', '10:00:00')->count());
    }

    /** Launch independent HTTP kernels while holding their common schedule lock. */
    private function race(int $lockId, array $requests): array
    {
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'booking-race-'.bin2hex(random_bytes(8));
        $connection = config('database.connections.mysql');
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array'];
        $processes = [];
        foreach ($requests as $id => [$user, $method, $uri, $data]) {
            $token = $user->createToken('race')->plainTextToken;
            $processes[] = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', base_path('tests/Support/booking-request.php'), $method, $uri, $token, json_encode($data), $barrier, (string) $id], base_path(), $environment, timeout: 30);
        }
        DB::beginTransaction();
        try {
            User::whereKey($lockId)->lockForUpdate()->firstOrFail();
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 15;
            while (! file_exists($barrier.'.0.ready') || ! file_exists($barrier.'.1.ready')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Concurrent request workers did not reach the barrier.');
                }
                usleep(10000);
            }
            file_put_contents($barrier.'.go', 'go');
            usleep(300000);
            DB::commit();
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertContains($result['status'], [200, 201, 422], json_encode($result));
                $statuses[] = $result['status'];
            }
            sort($statuses);

            return $statuses;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($barrier.'.*') as $file) {
                unlink($file);
            }
        }
    }
}
