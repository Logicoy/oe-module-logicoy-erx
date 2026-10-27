<?php

/**
 * The sent-prescription lifecycle rules. These are pure; the table is not
 * touched.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Prescription\PrescriptionTracker as Tracker;
use PHPUnit\Framework\TestCase;

class PrescriptionTrackerTest extends TestCase
{
    public function testASentPrescriptionCanBeCancelled(): void
    {
        $this->assertTrue(Tracker::isCancellable(['status' => 'sent', 'transmission_status' => 'SUCCESS']));
        $this->assertTrue(Tracker::isCancellable(['status' => 'sent', 'transmission_status' => null]));
    }

    public function testAFailedCancelCanBeTriedAgain(): void
    {
        $this->assertTrue(Tracker::isCancellable(['status' => 'cancel_failed', 'transmission_status' => 'SUCCESS']));
    }

    public function testACancelInFlightOrAnsweredCannotBeRepeated(): void
    {
        foreach (['cancel_queued', 'cancel_requested', 'cancelled', 'cancel_denied'] as $status) {
            $this->assertFalse(Tracker::isCancellable(['status' => $status]), $status);
        }
    }

    public function testAPrescriptionThatNeverReachedThePharmacyCannotBeCancelled(): void
    {
        foreach (['FAILED', 'SYSTEM_ERROR', 'MAX_RETRY_REACHED'] as $transmission) {
            $this->assertFalse(Tracker::isCancellable(['status' => 'sent', 'transmission_status' => $transmission]));
        }
    }

    public function testFillReportsCanBeRequestedForASentScriptWithoutOne(): void
    {
        $this->assertTrue(Tracker::canRequestFillIndicator(['status' => 'sent', 'transmission_status' => 'SUCCESS']));
        $this->assertTrue(
            Tracker::canRequestFillIndicator(['status' => 'sent', 'fill_indicator_status' => 'failed']),
            'a refused request can be made again'
        );
        $this->assertTrue(
            Tracker::canRequestFillIndicator(['status' => 'cancel_failed', 'transmission_status' => 'SUCCESS'])
        );
    }

    public function testFillReportsAreNotOfferedTwiceOrForAScriptThePharmacyNeverGot(): void
    {
        foreach (['queued', 'set'] as $state) {
            $this->assertFalse(
                Tracker::canRequestFillIndicator(['status' => 'sent', 'fill_indicator_status' => $state]),
                $state
            );
        }
        foreach (['FAILED', 'SYSTEM_ERROR', 'MAX_RETRY_REACHED'] as $transmission) {
            $this->assertFalse(
                Tracker::canRequestFillIndicator(['status' => 'sent', 'transmission_status' => $transmission]),
                $transmission
            );
        }
        $this->assertFalse(Tracker::canRequestFillIndicator(['status' => 'cancelled']));
    }

    public function testCancelOutcomes(): void
    {
        $this->assertSame(Tracker::STATUS_CANCELLED, Tracker::cancelOutcome('CANCELLED', 'CANCELLED'));
        $this->assertSame(Tracker::STATUS_CANCELLED, Tracker::cancelOutcome('Approved', ''));
        $this->assertSame(Tracker::STATUS_CANCEL_DENIED, Tracker::cancelOutcome('DENIED', ''));
        // "NOT_CANCELLED" contains CANCELLED; a denial must win.
        $this->assertSame(Tracker::STATUS_CANCEL_DENIED, Tracker::cancelOutcome('NOT_CANCELLED', ''));
        // Unknown words leave it waiting rather than guessing.
        $this->assertSame(Tracker::STATUS_CANCEL_REQUESTED, Tracker::cancelOutcome('PENDING', ''));
        $this->assertSame(Tracker::STATUS_CANCEL_REQUESTED, Tracker::cancelOutcome('', ''));
    }

    public function testCancelAnswerLabelsForTheInbox(): void
    {
        $this->assertSame('approved', Tracker::cancelAnswer(Tracker::STATUS_CANCELLED));
        $this->assertSame('denied', Tracker::cancelAnswer(Tracker::STATUS_CANCEL_DENIED));
        // The answer arrived but its outcome lookup has not succeeded yet.
        $this->assertSame('pending', Tracker::cancelAnswer(Tracker::STATUS_CANCEL_REQUESTED));
        // No row here: the Rx was sent from somewhere else.
        $this->assertSame('unknown', Tracker::cancelAnswer(null));
    }
}
