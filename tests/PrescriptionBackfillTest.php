<?php

/**
 * The chart backfill for scripts sent before the write-back existed. It must
 * write each qualifying script once and nothing on a repeat. Synthetic data.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Prescription\PrescriptionBackfill;
use LogiCoy\ERx\Prescription\PrescriptionGatewayInterface;
use LogiCoy\ERx\Prescription\PrescriptionWriter;
use OpenEMR\Common\Logging\EventAuditLogger;
use PHPUnit\Framework\TestCase;

class PrescriptionBackfillTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $written = [];
    private PrescriptionWriter $writer;
    private InMemoryMessageMap $map;

    protected function setUp(): void
    {
        $GLOBALS['temp_skip_translations'] = true;
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->method('insert')->willReturnCallback(function (array $data): int {
            $this->written[] = $data;
            return 9000 + count($this->written);
        });
        $this->map = new InMemoryMessageMap();
        $this->writer = new PrescriptionWriter($gateway, $this->map, $this->createMock(EventAuditLogger::class));
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        return [
            [
                'erx_medication_uuid' => 'med-test-0001', 'pid' => 4242, 'provider_user_id' => 5,
                'drug_name' => 'TEST-DRUG 10 MG tablet', 'status' => 'sent',
                'transmission_status' => 'SUCCESS', 'sent_at' => '2026-09-01 10:00:00',
            ],
            [
                'erx_medication_uuid' => 'med-test-0002', 'pid' => 4242, 'provider_user_id' => null,
                'drug_name' => null, 'status' => 'sent',
                'transmission_status' => null, 'sent_at' => '2026-09-02 10:00:00',
            ],
            [
                'erx_medication_uuid' => 'med-test-0003', 'pid' => 4242, 'provider_user_id' => 5,
                'drug_name' => 'TEST-DRUG', 'status' => 'sent',
                'transmission_status' => 'FAILED', 'sent_at' => '2026-09-03 10:00:00',
            ],
            [
                'erx_medication_uuid' => 'med-test-0004', 'pid' => 4242, 'provider_user_id' => 5,
                'drug_name' => 'TEST-DRUG', 'status' => 'cancelled',
                'transmission_status' => 'SUCCESS', 'sent_at' => '2026-09-04 10:00:00',
            ],
            [
                'erx_medication_uuid' => 'med-test-0005', 'pid' => 0, 'provider_user_id' => 5,
                'drug_name' => 'TEST-DRUG', 'status' => 'sent',
                'transmission_status' => 'SUCCESS', 'sent_at' => '2026-09-05 10:00:00',
            ],
        ];
    }

    public function testQualifyingScriptsAreWrittenWithWhatWasRecorded(): void
    {
        $reported = [];
        $counts = (new PrescriptionBackfill($this->writer))->run(
            $this->rows(),
            false,
            static function (string $uuid, int $pid, string $result, string $detail) use (&$reported): void {
                $reported[$uuid] = $result;
            }
        );

        $this->assertSame(['considered' => 5, 'written' => 2, 'exists' => 0, 'skipped' => 3, 'failed' => 0], $counts);
        $this->assertSame([
            'med-test-0001' => 'written',
            'med-test-0002' => 'written',
            'med-test-0003' => 'skipped',
            'med-test-0004' => 'skipped',
            'med-test-0005' => 'skipped',
        ], $reported);

        $this->assertSame('TEST-DRUG 10 MG tablet', $this->written[0]['drug']);
        $this->assertSame(4242, $this->written[0]['patient_id']);
        $this->assertSame(5, $this->written[0]['provider_id']);
        $this->assertSame('2026-09-01 10:00:00', $this->written[0]['date_added'], 'when it was sent, not now');
        $this->assertSame('2026-09-01', $this->written[0]['start_date']);
        $this->assertSame('med-test-0001', $this->written[0]['prescriptionguid']);
        $this->assertArrayNotHasKey('note', $this->written[0], 'the sig was never recorded locally');

        $this->assertSame('Prescription sent through LogiCoy eRx', $this->written[1]['drug']);
        $this->assertArrayNotHasKey('provider_id', $this->written[1]);
    }

    public function testRepeatedRunsAndTheSendPathAddNothing(): void
    {
        $backfill = new PrescriptionBackfill($this->writer);
        // The send path already wrote one of them under the same key.
        $this->writer->reflect('med-test-0001', 4242, ['drugName' => 'TEST-DRUG 10 MG tablet']);

        $first = $backfill->run($this->rows());
        $second = $backfill->run($this->rows());
        $third = $backfill->run($this->rows());

        $this->assertSame(1, $first['written']);
        $this->assertSame(1, $first['exists']);
        $this->assertSame(0, $second['written'], 'a repeated run must not write a second script');
        $this->assertSame(2, $second['exists']);
        $this->assertSame(0, $third['written']);
        $this->assertCount(2, $this->written, 'one from the send path, one from the backfill');
    }

    public function testDryRunWritesNothingButSaysWhatItWould(): void
    {
        $this->writer->reflect('med-test-0001', 4242, ['drugName' => 'TEST-DRUG 10 MG tablet']);
        $reported = [];

        $counts = (new PrescriptionBackfill($this->writer))->run(
            $this->rows(),
            true,
            static function (string $uuid, int $pid, string $result, string $detail) use (&$reported): void {
                $reported[$uuid] = $result . ': ' . $detail;
            }
        );

        $this->assertSame(['considered' => 5, 'written' => 1, 'exists' => 1, 'skipped' => 3, 'failed' => 0], $counts);
        $this->assertSame('exists: prescription 9001', $reported['med-test-0001']);
        $this->assertSame('written: would write', $reported['med-test-0002']);
        $this->assertCount(1, $this->written, 'only the send path wrote');
        $this->assertSame(1, $this->map->mapCallCount);
    }

    public function testAFailedWriteIsCountedAndTheRestContinue(): void
    {
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->method('insert')->willReturnCallback(static function (array $data): int {
            if ($data['prescriptionguid'] === 'med-test-0001') {
                throw new \RuntimeException('db gone');
            }
            return 9100;
        });
        $writer = new PrescriptionWriter(
            $gateway,
            new InMemoryMessageMap(),
            $this->createMock(EventAuditLogger::class)
        );

        $counts = (new PrescriptionBackfill($writer))->run($this->rows());

        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['written']);
    }
}
