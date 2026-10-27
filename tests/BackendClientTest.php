<?php

/**
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Client\BackendClient;
use LogiCoy\ERx\Client\BackendException;
use PHPUnit\Framework\TestCase;

class BackendClientTest extends TestCase
{
    public function testUserAgentNamesTheModuleAndItsVersion(): void
    {
        // The backend's WAF 403s any request without a User-Agent.
        $v_major = $v_minor = $v_patch = '';
        include __DIR__ . '/../version.php';

        $this->assertSame(
            'LogiCoy-eRx-OpenEMR/' . $v_major . '.' . $v_minor . '.' . $v_patch,
            BackendClient::userAgent()
        );
    }

    public function testFindValueWalksNestedResponseEnvelopes(): void
    {
        $response = [
            'statusCode' => '200',
            'data' => [
                'patientList' => [
                    ['erxPatientPKUuid' => 'erx-pat-0001'],
                ],
            ],
        ];

        $this->assertSame('erx-pat-0001', BackendClient::findValue($response, 'erxPatientPKUuid'));
    }

    public function testFindValueReturnsNullWhenAbsent(): void
    {
        $this->assertNull(BackendClient::findValue(['a' => ['b' => [1, 2]]], 'missing'));
        $this->assertNull(BackendClient::findValue('not an array', 'missing'));
        $this->assertNull(BackendClient::findValue([], 'missing'));
    }

    public function testFindValueIgnoresEmptyStrings(): void
    {
        $response = ['erxPatientPKUuid' => '', 'data' => ['erxPatientPKUuid' => 'erx-pat-0002']];

        $this->assertSame('erx-pat-0002', BackendClient::findValue($response, 'erxPatientPKUuid'));
    }

    public function testServerErrorsAndThrottlingAreRetryable(): void
    {
        $this->assertTrue((new BackendException('boom', 500))->isRetryable());
        $this->assertTrue((new BackendException('boom', 503))->isRetryable());
        $this->assertTrue((new BackendException('boom', 429))->isRetryable());
        $this->assertTrue((new BackendException('boom', 408))->isRetryable());
        // A network level failure carries no status and is worth another go.
        $this->assertTrue((new BackendException('boom', 0))->isRetryable());
    }

    public function testTheBackendsErrorCodeIsReadFromItsStatusEnvelope(): void
    {
        // Every eRx endpoint answers with transactionStatus; only the framework
        // level errors put anything at the top level.
        $this->assertSame('MED_ERC_5067', BackendClient::errorCode([
            'transactionStatus' => ['statusCode' => '500', 'errorCode' => 'MED_ERC_5067'],
        ]));
        $this->assertSame('Bad Request', BackendClient::errorCode(['error' => 'Bad Request']));
        $this->assertSame('', BackendClient::errorCode([]));
    }

    public function testFailuresCarryWhatTheBackendSaidWentWrong(): void
    {
        $detail = BackendClient::describeError([
            'transactionStatus' => [
                'statusCode' => '500',
                'status' => 'KO',
                'errorCode' => 'MED_ERC_5067',
                'statusMessage' => 'Failed to search medications.',
                'serverError' => true,
            ],
        ]);

        $this->assertSame(': MED_ERC_5067 — Failed to search medications.', $detail);
    }

    public function testAFrameworkLevelErrorIsDescribedToo(): void
    {
        $detail = BackendClient::describeError([
            'status' => 400,
            'error' => 'Bad Request',
            'message' => 'Clinician is not associated with the practice service location',
        ]);

        $this->assertStringContainsString('Bad Request', $detail);
        $this->assertStringContainsString('Clinician is not associated', $detail);
    }

    public function testAnUndescribedFailureAddsNothingRatherThanEchoingTheBody(): void
    {
        // A response can echo the request back, and that carries prescription
        // content — so nothing outside the status envelope is ever quoted.
        $this->assertSame('', BackendClient::describeError([
            'eprescriptionMedicationList' => [['drugDescription' => 'AMOXICILLIN 500 MG']],
        ]));
    }

    public function testClientErrorsAreNotRetryable(): void
    {
        // Retrying a malformed or unauthorized request just burns attempts.
        $this->assertFalse((new BackendException('boom', 400))->isRetryable());
        $this->assertFalse((new BackendException('boom', 401))->isRetryable());
        $this->assertFalse((new BackendException('boom', 404))->isRetryable());
        $this->assertFalse((new BackendException('boom', 422))->isRetryable());
    }

    public function testAnOrdinaryAnswerIsNotAFailure(): void
    {
        $this->assertNull(BackendClient::reportedFailure([]));
        $this->assertNull(BackendClient::reportedFailure([
            'isValidationError' => false,
            'transactionStatus' => ['status' => 'SUCCESS', 'serverError' => false],
        ]));
    }

    public function testAValidationFailureInsideA200IsReported(): void
    {
        $this->assertSame('Response code not accepted; Notes too long', BackendClient::reportedFailure([
            'isValidationError' => true,
            'validationErrorInfo' => [
                ['variableName' => 'code', 'errorMessage' => 'Response code not accepted'],
                ['variableName' => 'notes', 'errorMessage' => 'Notes too long'],
            ],
        ]));
    }

    public function testAServerErrorFlagFallsBackToTheStatusEnvelope(): void
    {
        $this->assertSame('Not allowed (RX_CHANGE_ERC_6754)', BackendClient::reportedFailure([
            'transactionStatus' => [
                'serverError' => true,
                'errorCode' => 'RX_CHANGE_ERC_6754',
                'statusMessage' => 'Not allowed',
            ],
        ]));
        $this->assertSame(
            'The LogiCoy backend reported a failure',
            BackendClient::reportedFailure(['isValidationError' => true])
        );
    }
    public function testTheScreenGetsTheBackendsOwnWordsWithTheCodeLast(): void
    {
        // What the demo backend answered a draft of a scheduled drug with. The
        // prescriber needs the sentence; the code is for support.
        $explained = BackendClient::explain([
            'transactionStatus' => [
                'statusCode' => '403',
                'errorCode' => 'EPCS_ERC_9503',
                'statusMessage' => 'The medication contains a controlled substance (DEA Schedule II-V).'
                    . ' Your client app does not have EPCS permission to prescribe controlled substances.'
                    . ' Please contact LogiCoy eRx support.',
            ],
        ]);

        $this->assertStringStartsWith('The medication contains a controlled substance', $explained);
        $this->assertStringEndsWith('Please contact LogiCoy eRx support. (EPCS_ERC_9503)', $explained);
    }

    public function testALongBackendMessageIsNotCutMidSentence(): void
    {
        // IDME_ERC_14007 used to reach the screen as "...please upgrade the pro".
        $message = str_repeat('The user is not authorized to complete ID.me MFA verification. ', 5);

        $explained = BackendClient::explain(['transactionStatus' => ['statusMessage' => $message]]);

        $this->assertGreaterThan(200, strlen($explained));
        $this->assertSame(trim($message), $explained);
    }

    public function testFieldReasonsOnARefusalReachTheScreen(): void
    {
        // A 400 that says only "invalid or missing fields" tells the
        // prescriber nothing; the fields it lists do.
        $explained = BackendClient::explain([
            'transactionStatus' => [
                'errorCode' => 'GEN_ERC_1001',
                'statusMessage' => 'Request contains invalid or missing fields.',
            ],
            'validationErrorInfo' => [
                // The backend names its own request model; the prescriber
                // sees the screen's label for the box.
                [
                    'variableName' => 'eprescriptionMedicationList[0].noOfUnits',
                    'errorMessage' => 'must be greater than 0',
                ],
                ['variableName' => 'sig', 'errorMessage' => 'sig is required'],
            ],
        ]);

        $this->assertSame(
            'Request contains invalid or missing fields.'
            . ' — Quantity: must be greater than 0; Directions: sig is required (GEN_ERC_1001)',
            $explained
        );
    }

    public function testBackendFieldPathsAreLabelledAsTheScreenDoes(): void
    {
        $this->assertSame('Quantity', BackendClient::fieldLabel('eprescriptionMedicationList[0].noOfUnits'));
        $this->assertSame('Days supply', BackendClient::fieldLabel('daysSupply'));
        $this->assertSame('somethingElse', BackendClient::fieldLabel('model.somethingElse'));
        $this->assertSame('', BackendClient::fieldLabel(''));
    }

    public function testFrameworkValidationErrorsAreReadToo(): void
    {
        $this->assertSame(
            ['Days supply: must not be null', 'Malformed JSON'],
            BackendClient::reasons([
                'errors' => [['field' => 'daysSupply', 'defaultMessage' => 'must not be null']],
                'details' => ['Malformed JSON'],
            ])
        );
    }

    public function testACodeOnlyAnswerStillNamesTheCode(): void
    {
        $this->assertSame('LogiCoy error GEN_ERC_1001', BackendClient::explain([
            'transactionStatus' => ['errorCode' => 'GEN_ERC_1001'],
        ]));
        // The framework's "Bad Request" is not a code worth quoting.
        $this->assertSame('Clinician is not associated', BackendClient::explain([
            'error' => 'Bad Request',
            'message' => 'Clinician is not associated',
        ]));
        $this->assertSame('', BackendClient::explain([
            'eprescriptionMedicationList' => [['drugDescription' => 'AMOXICILLIN 500 MG']],
        ]));
    }

    public function testTheUserMessageFallsBackToTheLogMessage(): void
    {
        $e = new BackendException(
            'LogiCoy backend rejected /v1/x (HTTP 400): GEN_ERC_1001 — Bad',
            400,
            [],
            null,
            'Bad (GEN_ERC_1001)'
        );
        $this->assertSame('Bad (GEN_ERC_1001)', $e->getUserMessage());

        // Failures raised on this side are written for the screen already.
        $onThisSide = new BackendException('Enter the code ID.me sent you.');
        $this->assertSame('Enter the code ID.me sent you.', $onThisSide->getUserMessage());
        $blank = new BackendException('log message', 0, [], null, '  ');
        $this->assertSame('log message', $blank->getUserMessage());
    }
}
