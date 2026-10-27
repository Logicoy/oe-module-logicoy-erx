<?php

/**
 * Settings that must read as on when never configured.
 *
 * @package   OpenEMR Module: LogiCoy eRx
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace LogiCoy\ERx\Tests;

use LogiCoy\ERx\Config\GlobalConfig;
use PHPUnit\Framework\TestCase;

class GlobalConfigTest extends TestCase
{
    public function testTakingOverTheRxButtonsIsOnUnlessSwitchedOff(): void
    {
        $this->assertTrue((new GlobalConfig([]))->shouldReplaceRxButtons(), 'absent reads as on');
        $this->assertTrue((new GlobalConfig([GlobalConfig::REPLACE_RX_BUTTONS => '']))->shouldReplaceRxButtons());
        $this->assertTrue((new GlobalConfig([GlobalConfig::REPLACE_RX_BUTTONS => '1']))->shouldReplaceRxButtons());
        $this->assertFalse((new GlobalConfig([GlobalConfig::REPLACE_RX_BUTTONS => '0']))->shouldReplaceRxButtons());
    }

    public function testFillIndicatorDefaultsToAllFillStatuses(): void
    {
        $this->assertSame('1', (new GlobalConfig([]))->getFillIndicator(), 'absent reads as the default');
        $this->assertSame('1', (new GlobalConfig([GlobalConfig::FILL_INDICATOR => '']))->getFillIndicator());
        $this->assertSame('7', (new GlobalConfig([GlobalConfig::FILL_INDICATOR => '7']))->getFillIndicator());
    }

    public function testFillIndicatorCanBeSwitchedOff(): void
    {
        $off = new GlobalConfig([GlobalConfig::FILL_INDICATOR => GlobalConfig::FILL_INDICATOR_OFF]);
        $this->assertSame('', $off->getFillIndicator(), 'nothing is queued after a send');
    }

    public function testOnlyTheApisOwnFillIndicatorCodesAreEverSent(): void
    {
        foreach (['9', 'ALL', '1; DROP', '01'] as $stored) {
            $config = new GlobalConfig([GlobalConfig::FILL_INDICATOR => $stored]);
            $this->assertSame('1', $config->getFillIndicator(), $stored . ' falls back to the default');
        }
        // PHP stores the numeric-string keys as ints; the codes sent are strings.
        $this->assertSame(
            ['1', '2', '3', '4', '5', '6', '7', '8'],
            array_map('strval', array_keys(GlobalConfig::FILL_INDICATORS))
        );
    }

    public function testWritingToTheChartIsOnUnlessSwitchedOff(): void
    {
        $this->assertTrue((new GlobalConfig([]))->shouldWriteToPrescriptions());
        $off = new GlobalConfig([GlobalConfig::WRITE_TO_PRESCRIPTIONS => '0']);
        $this->assertFalse($off->shouldWriteToPrescriptions());
    }
}
