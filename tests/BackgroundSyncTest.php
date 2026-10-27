<?php

/**
 * The background service that replaces the practice's cron entry: one run
 * sends the queue and polls every facility, one failing facility does not stop
 * the rest, and the work is never attributed to whoever is signed in. All data
 * here is synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Config\SessionContext;
use LogiCoy\ERx\Queue\BackgroundSync;
use LogiCoy\ERx\Queue\QueueWorker;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class BackgroundSyncTest extends TestCase
{
    private const POLLED = ['fetched' => 1, 'processed' => 1, 'replayed' => 0, 'skipped' => 0, 'failed' => 0];

    protected function tearDown(): void
    {
        SessionContext::useSession(null);
    }

    public function testSendsTheQueueOnceAndPollsEveryFacility(): void
    {
        $worker = $this->createMock(QueueWorker::class);
        $worker->expects($this->once())->method('drainOutbound')
            ->willReturn(['processed' => 2, 'failed' => 0, 'released' => 0]);
        $polled = [];
        $worker->method('pollInbound')->willReturnCallback(function (int $facilityId) use (&$polled): array {
            $polled[] = $facilityId;
            return self::POLLED;
        });

        $result = BackgroundSync::sync($worker, [3, 7], $this->createMock(SystemLogger::class));

        $this->assertSame([3, 7], $polled);
        $this->assertSame(2, $result['outbound']['processed']);
        $this->assertSame(self::POLLED, $result['inbound'][7]);
    }

    public function testOneFacilityFailingDoesNotStopTheOthers(): void
    {
        $worker = $this->createMock(QueueWorker::class);
        $worker->method('drainOutbound')->willReturn(['processed' => 0, 'failed' => 0, 'released' => 0]);
        $worker->method('pollInbound')->willReturnCallback(static function (int $facilityId): array {
            if ($facilityId === 3) {
                throw new RuntimeException('credential revoked');
            }
            return self::POLLED;
        });
        $logger = $this->createMock(SystemLogger::class);
        $logger->expects($this->once())->method('error')
            ->with('LogiCoy eRx inbound poll failed', $this->callback(
                static fn(array $context): bool => $context['facility'] === 3
            ));

        $result = BackgroundSync::sync($worker, [3, 7], $logger);

        $this->assertSame('failed', $result['inbound'][3]);
        $this->assertSame(self::POLLED, $result['inbound'][7]);
    }

    public function testNoFacilitiesStillSendsTheQueue(): void
    {
        $worker = $this->createMock(QueueWorker::class);
        $worker->expects($this->once())->method('drainOutbound')
            ->willReturn(['processed' => 0, 'failed' => 0, 'released' => 0]);
        $worker->expects($this->never())->method('pollInbound');

        $result = BackgroundSync::sync($worker, [], $this->createMock(SystemLogger::class));

        $this->assertSame([], $result['inbound']);
    }

    public function testRunsWithoutTheSignedInUser(): void
    {
        // OpenEMR 7.0 runs background services inside a signed-in request.
        $session = new Session(new MockArraySessionStorage());
        $session->set('authUser', 'synthetic-clinician');
        $session->set('authUserID', 41);
        $session->set('site_id', 'default');
        SessionContext::useSession($session);

        $seen = SessionContext::runAsWorker(static function (): array {
            SessionContext::set('scratch', 'kept inside the run');
            return [
                'user' => SessionContext::getString('authUser'),
                'userId' => SessionContext::getInt('authUserID'),
                'site' => SessionContext::getString('site_id'),
                'scratch' => SessionContext::getString('scratch'),
            ];
        });

        $this->assertSame(
            ['user' => '', 'userId' => 0, 'site' => 'default', 'scratch' => 'kept inside the run'],
            $seen
        );
        // The user's own session is untouched afterwards.
        $this->assertSame('synthetic-clinician', SessionContext::getString('authUser'));
        $this->assertFalse($session->has('scratch'));
    }

    public function testTheWorkerIdentityIsDroppedEvenWhenTheRunThrows(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('authUser', 'synthetic-clinician');
        SessionContext::useSession($session);

        try {
            SessionContext::runAsWorker(static function (): void {
                throw new RuntimeException('backend down');
            });
            $this->fail('expected the exception to propagate');
        } catch (RuntimeException) {
        }

        $this->assertSame('synthetic-clinician', SessionContext::getString('authUser'));
    }

    public function testTheServiceNameFitsOpenEmrsColumn(): void
    {
        // background_services.name is VARCHAR(31); a longer name is cut and
        // the row would never be found again by name.
        $this->assertLessThanOrEqual(31, strlen(BackgroundSync::SERVICE_NAME));
        $this->assertStringStartsWith('/interface/modules/custom_modules/', BackgroundSync::INCLUDE_PATH);
        $this->assertFileExists(dirname(__DIR__) . '/scripts/background_service.php');
    }
}
