<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use App\DTO\SuggestionDTO;

/**
 * Unit Tests for SuggestionDTO
 * 
 * Tests validation and Charter compliance enforcement
 */
class SuggestionDTOTest extends TestCase
{
    /**
     * Test valid DTO creation
     */
    public function testValidDTOCreation(): void
    {
        $dto = new SuggestionDTO(
            supplier_id: 1,
            official_name: 'شركة النورس للتجارة',
            english_name: 'Al-Nawras Trading',
            confidence: 92,
            level: 'B',
            reason_ar: 'تطابق دقيق',
            confirmation_count: 5,
            rejection_count: 0,
            usage_count: 10
        );

        $this->assertInstanceOf(SuggestionDTO::class, $dto);
        $this->assertEquals(1, $dto->supplier_id);
        $this->assertEquals(92, $dto->confidence);
        $this->assertEquals('B', $dto->level);
    }

    /**
     * Test confidence validation (0-100)
     */
    public function testConfidenceValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Confidence must be 0-100');

        new SuggestionDTO(
            supplier_id: 1,
            official_name: 'Test',
            confidence: 150, // Invalid
            level: 'B',
            reason_ar: 'Test'
        );
    }

    /**
     * Test level validation (B/C/D only)
     */
    public function testLevelValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Level must be B, C, or D');

        new SuggestionDTO(
            supplier_id: 1,
            official_name: 'Test',
            confidence: 80,
            level: 'A', // Invalid
            reason_ar: 'Test'
        );
    }

    /**
     * Test level-confidence consistency (B >= 85)
     */
    public function testLevelConfidenceConsistencyB(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Level B requires confidence >= 85');

        new SuggestionDTO(
            supplier_id: 1,
            official_name: 'Test',
            confidence: 80, // Too low for B
            level: 'B',
            reason_ar: 'Test'
        );
    }

    /**
     * Test level-confidence consistency (C: 65-84)
     */
    public function testLevelConfidenceConsistencyC(): void
    {
        // Valid C
        $dto = new SuggestionDTO(
            supplier_id: 1,
            official_name: 'Test',
            confidence: 75,
            level: 'C',
            reason_ar: 'Test'
        );

        $this->assertEquals('C', $dto->level);

        // Invalid C (too high)
        $this->expectException(\InvalidArgumentException::class);
        
        new SuggestionDTO(
            supplier_id: 1,
            official_name: 'Test',
            confidence: 90, // Too high for C
            level: 'C',
            reason_ar: 'Test'
        );
    }

    /**
     * Test reason_ar non-empty validation
     */
    public function testReasonArValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reason_ar cannot be empty');

        new SuggestionDTO(
            supplier_id: 1,
            official_name: 'Test',
            confidence: 80,
            level: 'C',
            reason_ar: '' // Invalid
        );
    }

    /**
     * Test toArray method
     */
    public function testToArray(): void
    {
        $dto = new SuggestionDTO(
            supplier_id: 42,
            official_name: 'شركة النورس',
            english_name: 'Al-Nawras',
            confidence: 92,
            level: 'B',
            reason_ar: 'تطابق دقيق',
            confirmation_count: 5,
            rejection_count: 1,
            usage_count: 10
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(42, $array['supplier_id']);
        $this->assertEquals('شركة النورس', $array['official_name']);
        $this->assertEquals(92, $array['confidence']);
        $this->assertEquals('B', $array['level']);
    }
}
