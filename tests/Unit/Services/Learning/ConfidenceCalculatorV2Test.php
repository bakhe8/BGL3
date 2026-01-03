<?php

namespace Tests\Unit\Services\Learning;

use PHPUnit\Framework\TestCase;
use App\Services\Learning\ConfidenceCalculatorV2;
use App\DTO\SignalDTO;

/**
 * Unit Tests for ConfidenceCalculatorV2
 * 
 * Tests the unified confidence formula (Charter Part 2, Section 4)
 */
class ConfidenceCalculatorV2Test extends TestCase
{
    private ConfidenceCalculatorV2 $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ConfidenceCalculatorV2();
    }

    /**
     * Test base score calculation for alias_exact signal
     */
    public function testAliasExactSignal(): void
    {
        $signals = [
            new SignalDTO(
                supplier_id: 1,
                signal_type: 'alias_exact',
                raw_strength: 1.0,
                metadata: []
            )
        ];

        $confidence = $this->calculator->calculate($signals, 0, 0);

        // alias_exact base score = 100
        $this->assertEquals(100, $confidence);
    }

    /**
     * Test confirmation boost (+10 for 1 confirmation)
     */
    public function testConfirmationBoost(): void
    {
        $signals = [
            new SignalDTO(
                supplier_id: 1,
                signal_type: 'fuzzy_official_medium',
                raw_strength: 0.75,
                metadata: []
            )
        ];

        // fuzzy_official_medium base = 70, strength 0.75 = 52.5, rounded = 53
        $baseConfidence = $this->calculator->calculate($signals, 0, 0);
        
        // With 1 confirmation (+10)
        $withConfirmation = $this->calculator->calculate($signals, 1, 0);

        $this->assertEquals($baseConfidence + 10, $withConfirmation);
    }

    /**
     * Test rejection penalty (-10 per rejection)
     */
    public function testRejectionPenalty(): void
    {
        $signals = [
            new SignalDTO(
                supplier_id: 1,
                signal_type: 'alias_exact',
                raw_strength: 1.0,
                metadata: []
            )
        ];

        $baseConfidence = $this->calculator->calculate($signals, 0, 0);
        
        // With 1 rejection (-10)
        $withRejection = $this->calculator->calculate($signals, 0, 1);

        $this->assertEquals($baseConfidence - 10, $withRejection);
    }

    /**
     * Test confidence clamping (0-100 range)
     */
    public function testConfidenceClamping(): void
    {
        $weakSignals = [
            new SignalDTO(
                supplier_id: 1,
                signal_type: 'fuzzy_official_weak',
                raw_strength: 0.1,
                metadata: []
            )
        ];

        // Very weak signal with rejections
        $confidence = $this->calculator->calculate($weakSignals, 0, 10);

        // Should be clamped to 0
        $this->assertGreaterThanOrEqual(0, $confidence);
        $this->assertLessThanOrEqual(100, $confidence);
    }

    /**
     * Test level assignment (B/C/D)
     */
    public function testLevelAssignment(): void
    {
        // Level B: >= 85
        $this->assertEquals('B', $this->calculator->assignLevel(85));
        $this->assertEquals('B', $this->calculator->assignLevel(100));

        // Level C: 65-84
        $this->assertEquals('C', $this->calculator->assignLevel(65));
        $this->assertEquals('C', $this->calculator->assignLevel(84));

        // Level D: < 65
        $this->assertEquals('D', $this->calculator->assignLevel(64));
        $this->assertEquals('D', $this->calculator->assignLevel(40));
        $this->assertEquals('D', $this->calculator->assignLevel(0));
    }

    /**
     * Test display threshold (40)
     */
    public function testDisplayThreshold(): void
    {
        $this->assertTrue($this->calculator->meetsDisplayThreshold(40));
        $this->assertTrue($this->calculator->meetsDisplayThreshold(50));
        $this->assertFalse($this->calculator->meetsDisplayThreshold(39));
        $this->assertFalse($this->calculator->meetsDisplayThreshold(0));
    }

    /**
     * Test multiple signals aggregation
     */
    public function testMultipleSignalsAggregation(): void
    {
        $signals = [
            new SignalDTO(
                supplier_id: 1,
                signal_type: 'alias_exact',
                raw_strength: 1.0,
                metadata: []
            ),
            new SignalDTO(
                supplier_id: 1,
                signal_type: 'learning_confirmation',
                raw_strength: 0.5,
                metadata: []
            )
        ];

        $confidence = $this->calculator->calculate($signals, 0, 0);

        // Should use highest base score (alias_exact = 100)
        $this->assertEquals(100, $confidence);
    }
}
