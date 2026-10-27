<?php

/**
 * The chart a pharmacy's patient becomes. All data here is synthetic.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Prescription\RequestPatientLinker;
use PHPUnit\Framework\TestCase;

class RequestPatientLinkerTest extends TestCase
{
    public function testThePharmacysPatientBecomesAChartInOpenEmrsOwnColumns(): void
    {
        $data = RequestPatientLinker::toPatientData([
            'prefix' => 'Ms.',
            'firstName' => ' Test ',
            'middleName' => 'Q',
            'lastName' => 'Patient',
            'dateOfBirth' => '1980-01-01T00:00:00',
            'genderType' => 'F',
            'phone' => '(555) 000-0000',
            'email' => 'test.patient@example.test',
            'address' => [
                'addressLine1' => '1 Test St',
                'addressLine2' => 'Apt 2',
                'city' => 'Testville',
                'stateProvince' => 'CA',
                'postalCode' => '90000',
                'country' => 'US',
            ],
            'weightInLb' => 150,
        ]);

        $this->assertSame([
            'fname' => 'Test',
            'lname' => 'Patient',
            'mname' => 'Q',
            'DOB' => '1980-01-01',
            'sex' => 'Female',
            'phone_home' => '5550000000',
            'email' => 'test.patient@example.test',
            'street' => '1 Test St Apt 2',
            'city' => 'Testville',
            'state' => 'CA',
            'postal_code' => '90000',
            'country_code' => 'USA',
        ], $data);
    }

    public function testOnlyWhatThePharmacySentIsFilledIn(): void
    {
        $data = RequestPatientLinker::toPatientData([
            'firstName' => 'Test',
            'lastName' => 'Patient',
            'dateOfBirth' => '1980-01-01',
            'genderType' => 'M',
            'address' => null,
        ]);

        $this->assertSame(
            ['fname' => 'Test', 'lname' => 'Patient', 'mname' => '', 'DOB' => '1980-01-01', 'sex' => 'Male'],
            $data
        );
    }

    public function testUnknownGenderIsNotGuessed(): void
    {
        $this->assertSame('Unknown', RequestPatientLinker::toPatientData(['genderType' => 'O'])['sex']);
        $this->assertSame('Unknown', RequestPatientLinker::toPatientData([])['sex']);
    }

    public function testTheScreenSeesOnlyWhatTellsTwoChartsApart(): void
    {
        $this->assertSame(
            ['pid' => 7, 'pubpid' => 'P7', 'name' => 'Test Patient', 'dob' => '1980-01-01', 'sex' => 'Female'],
            RequestPatientLinker::summary([
                'pid' => '7', 'pubpid' => 'P7', 'fname' => 'Test', 'mname' => 'Q', 'lname' => 'Patient',
                'DOB' => '1980-01-01', 'sex' => 'Female', 'ss' => 'never',
            ])
        );
    }
}
