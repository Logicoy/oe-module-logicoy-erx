<?php

/**
 * Idempotency tests for the prescription write path.
 *
 * OpenEMR's cron replays on shared hosting, so a redelivered message must never
 * produce a second script. All data here is synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Prescription\PrescriptionGatewayInterface;
use LogiCoy\ERx\Prescription\PrescriptionWriter;
use OpenEMR\Common\Logging\EventAuditLogger;
use PHPUnit\Framework\TestCase;

class ReplayTest extends TestCase
{
    private const MESSAGE_ID = 'LOGICOY-MSG-TEST-0001';
    private const PATIENT_ID = 4242;

    /** @return array<string, mixed> */
    private function syntheticPrescription(): array
    {
        return [
            'drugName' => 'TEST-DRUG 100 MG tablet',
            'ndcNumber' => '00000000000',
            'sig' => 'Synthetic directions for test use only',
            'quantity' => '30',
            'refills' => 1,
            'effectiveDate' => '2026-01-01',
        ];
    }

    private function makeGateway(int $newId, int &$insertCalls): PrescriptionGatewayInterface
    {
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->method('insert')->willReturnCallback(
            function () use ($newId, &$insertCalls): int {
                $insertCalls++;
                return $newId;
            }
        );

        return $gateway;
    }

    public function testFirstDeliveryWritesTheScript(): void
    {
        $insertCalls = 0;
        $map = new InMemoryMessageMap();
        $writer = new PrescriptionWriter(
            $this->makeGateway(9001, $insertCalls),
            $map,
            $this->createMock(EventAuditLogger::class)
        );

        $result = $writer->reflect(self::MESSAGE_ID, self::PATIENT_ID, $this->syntheticPrescription());

        $this->assertTrue($result['created']);
        $this->assertSame(9001, $result['prescription_id']);
        $this->assertSame(1, $insertCalls);
    }

    public function testReplayedDeliveryDoesNotWriteASecondScript(): void
    {
        $insertCalls = 0;
        $map = new InMemoryMessageMap();
        $writer = new PrescriptionWriter(
            $this->makeGateway(9001, $insertCalls),
            $map,
            $this->createMock(EventAuditLogger::class)
        );

        $first = $writer->reflect(self::MESSAGE_ID, self::PATIENT_ID, $this->syntheticPrescription());
        $second = $writer->reflect(self::MESSAGE_ID, self::PATIENT_ID, $this->syntheticPrescription());
        $third = $writer->reflect(self::MESSAGE_ID, self::PATIENT_ID, $this->syntheticPrescription());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created'], 'a replay must not create a script');
        $this->assertFalse($third['created'], 'a second replay must not create a script');

        $this->assertSame($first['prescription_id'], $second['prescription_id']);
        $this->assertSame($first['prescription_id'], $third['prescription_id']);

        $this->assertSame(1, $insertCalls, 'the prescription gateway must be called exactly once');
        $this->assertSame(1, $map->mapCallCount, 'the message must be mapped exactly once');
    }

    public function testRowCarriesWhatTheChartShowsAndNothingTheSchemaRefuses(): void
    {
        $written = null;
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->method('insert')->willReturnCallback(
            static function (array $data) use (&$written): int {
                $written = $data;
                return 9002;
            }
        );
        $writer = new PrescriptionWriter(
            $gateway,
            new InMemoryMessageMap(),
            $this->createMock(EventAuditLogger::class)
        );

        $writer->reflect(self::MESSAGE_ID, self::PATIENT_ID, [
            'providerUserId' => 7,
            'encounter' => 12,
            // An 11-digit NDC: it must never land in drug_id, an INT column.
            'ndcNumber' => '65862-0015-05',
        ] + $this->syntheticPrescription());

        $this->assertIsArray($written);
        $this->assertSame(self::PATIENT_ID, $written['patient_id']);
        $this->assertSame('TEST-DRUG 100 MG tablet', $written['drug']);
        $this->assertSame('30', $written['quantity']);
        $this->assertSame(1, $written['refills']);
        $this->assertSame('Synthetic directions for test use only', $written['note']);
        $this->assertSame('Synthetic directions for test use only', $written['drug_dosage_instructions']);
        $this->assertSame('2026-01-01', $written['start_date']);
        $this->assertSame(7, $written['provider_id']);
        $this->assertSame(12, $written['encounter']);
        $this->assertSame(1, $written['active']);
        $this->assertSame(1, $written['erx_source']);
        $this->assertSame(self::MESSAGE_ID, $written['prescriptionguid']);
        $this->assertSame('NDC 65862001505', $written['drug_info_erx']);
        $this->assertArrayNotHasKey('drug_id', $written);

        // Strict-mode MySQL refuses an insert that omits a NOT NULL column
        // with no default; these three are such columns on 7.x and 8.x.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $written['txDate']);
        $this->assertSame('', $written['usage_category_title']);
        $this->assertSame('', $written['request_intent_title']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $written['date_added']);
    }

    public function testSparsePayloadStillWritesWithTodayAsStartDate(): void
    {
        $written = null;
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->method('insert')->willReturnCallback(
            static function (array $data) use (&$written): int {
                $written = $data;
                return 9003;
            }
        );
        $writer = new PrescriptionWriter(
            $gateway,
            new InMemoryMessageMap(),
            $this->createMock(EventAuditLogger::class)
        );

        $writer->reflect(self::MESSAGE_ID, self::PATIENT_ID, [
            'drugName' => '  TEST-DRUG  ',
            'effectiveDate' => 'not a date',
            'refills' => '',
        ]);

        $this->assertSame('TEST-DRUG', $written['drug']);
        $this->assertSame(date('Y-m-d'), $written['start_date']);
        $this->assertArrayNotHasKey('refills', $written);
        $this->assertArrayNotHasKey('note', $written);
        $this->assertArrayNotHasKey('quantity', $written);
        $this->assertArrayNotHasKey('provider_id', $written);
        $this->assertArrayNotHasKey('encounter', $written);
        $this->assertArrayNotHasKey('drug_info_erx', $written);
    }

    public function testUpdatingAKnownMessageChangesTheRowInPlace(): void
    {
        $updates = [];
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->method('insert')->willReturn(9001);
        $gateway->expects($this->once())->method('update')->willReturnCallback(
            static function (int $id, array $data) use (&$updates): void {
                $updates[] = [$id, $data];
            }
        );
        $writer = new PrescriptionWriter(
            $gateway,
            new InMemoryMessageMap(),
            $this->createMock(EventAuditLogger::class)
        );

        $writer->reflect(self::MESSAGE_ID, self::PATIENT_ID, $this->syntheticPrescription());
        $result = $writer->update(self::MESSAGE_ID, self::PATIENT_ID, [
            'sig' => 'Changed synthetic directions', 'quantity' => '60', 'refills' => 3, 'providerUserId' => 7,
        ] + $this->syntheticPrescription());

        $this->assertSame(['prescription_id' => 9001, 'created' => false], $result);
        [$id, $data] = $updates[0];
        $this->assertSame(9001, $id);
        $this->assertSame('Changed synthetic directions', $data['note']);
        $this->assertSame('60', $data['quantity']);
        $this->assertSame(3, $data['refills']);
        $this->assertSame(7, $data['updated_by']);
        $this->assertArrayHasKey('date_modified', $data);
        foreach (['patient_id', 'date_added', 'prescriptionguid', 'active', 'txDate'] as $kept) {
            $this->assertArrayNotHasKey($kept, $data, $kept . ' is not a thing an update may change');
        }
    }

    public function testSubstitutionChoiceIsWrittenWithCoreCodes(): void
    {
        $written = [];
        $updates = [];
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->method('insert')->willReturnCallback(
            static function (array $data) use (&$written): int {
                $written[] = $data;
                return 9010 + count($written);
            }
        );
        $gateway->method('update')->willReturnCallback(
            static function (int $id, array $data) use (&$updates): void {
                $updates[] = $data;
            }
        );
        $writer = new PrescriptionWriter(
            $gateway,
            new InMemoryMessageMap(),
            $this->createMock(EventAuditLogger::class)
        );

        // 1 = substitution allowed, 2 = do not substitute; absent leaves the column alone.
        $rx = $this->syntheticPrescription();
        $writer->reflect('LOGICOY-MSG-DAW', self::PATIENT_ID, ['substitutionAllowed' => false] + $rx);
        $writer->reflect('LOGICOY-MSG-SUB', self::PATIENT_ID, ['substitutionAllowed' => true] + $rx);
        $writer->reflect('LOGICOY-MSG-NONE', self::PATIENT_ID, $rx);
        $writer->update('LOGICOY-MSG-DAW', self::PATIENT_ID, ['substitutionAllowed' => true] + $rx);

        $this->assertSame(2, $written[0]['substitute']);
        $this->assertSame(1, $written[1]['substitute']);
        $this->assertArrayNotHasKey('substitute', $written[2]);
        $this->assertSame(1, $updates[0]['substitute']);
    }

    public function testUpdatingAnUnknownMessageWritesTheScript(): void
    {
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->expects($this->once())->method('insert')->willReturn(9005);
        $gateway->expects($this->never())->method('update');
        $writer = new PrescriptionWriter(
            $gateway,
            new InMemoryMessageMap(),
            $this->createMock(EventAuditLogger::class)
        );

        $result = $writer->update('LOGICOY-MSG-NEW', self::PATIENT_ID, $this->syntheticPrescription());

        $this->assertSame(['prescription_id' => 9005, 'created' => true], $result);
    }

    public function testDeactivatingTakesAKnownRowOffTheChartAndIgnoresAnUnknownOne(): void
    {
        $gateway = $this->createMock(PrescriptionGatewayInterface::class);
        $gateway->method('insert')->willReturn(9001);
        $gateway->expects($this->once())->method('deactivate')->with(9001);
        $writer = new PrescriptionWriter(
            $gateway,
            new InMemoryMessageMap(),
            $this->createMock(EventAuditLogger::class)
        );

        $this->assertFalse($writer->deactivate('LOGICOY-MSG-UNKNOWN', self::PATIENT_ID));

        $writer->reflect(self::MESSAGE_ID, self::PATIENT_ID, $this->syntheticPrescription());
        $this->assertTrue($writer->deactivate(self::MESSAGE_ID, self::PATIENT_ID));
    }

    public function testDistinctMessagesEachWriteTheirOwnScript(): void
    {
        $insertCalls = 0;
        $map = new InMemoryMessageMap();
        $writer = new PrescriptionWriter(
            $this->makeGateway(9001, $insertCalls),
            $map,
            $this->createMock(EventAuditLogger::class)
        );

        $writer->reflect('LOGICOY-MSG-A', self::PATIENT_ID, $this->syntheticPrescription());
        $writer->reflect('LOGICOY-MSG-B', self::PATIENT_ID, $this->syntheticPrescription());

        $this->assertSame(2, $insertCalls);
    }
}
