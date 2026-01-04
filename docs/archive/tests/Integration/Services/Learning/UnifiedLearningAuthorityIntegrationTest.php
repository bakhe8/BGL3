<?php

namespace Tests\Integration\Services\Learning;

use PHPUnit\Framework\TestCase;
use App\Services\Learning\AuthorityFactory;
use App\DTO\SuggestionDTO;

/**
 * Integration Tests for UnifiedLearningAuthority
 * 
 * Tests end-to-end suggestion flow
 * 
 * Note: These tests require database connection
 */
class UnifiedLearningAuthorityIntegrationTest extends TestCase
{
    private $authority;

    protected function setUp(): void
    {
        // Create Authority with all feeders
        $this->authority = AuthorityFactory::create();
    }

    /**
     * Test basic suggestion retrieval
     */
    public function testGetSuggestions(): void
    {
        $input = 'شركة النورس';

        $suggestions = $this->authority->getSuggestions($input);

        $this->assertIsArray($suggestions);
        
        // All results should be SuggestionDTO
        foreach ($suggestions as $suggestion) {
            $this->assertInstanceOf(SuggestionDTO::class, $suggestion);
        }
    }

    /**
     * Test Silence Rule (no signals = empty array)
     */
    public function testSilenceRule(): void
    {
        $input = 'xyz123nonexistent';

        $suggestions = $this->authority->getSuggestions($input);

        // Should return empty array (not throw exception)
        $this->assertIsArray($suggestions);
        $this->assertEmpty($suggestions);
    }

    /**
     * Test confidence ordering (descending)
     */
    public function testConfidenceOrdering(): void
    {
        $input = 'شركة'; // Generic input likely to have multiple results

        $suggestions = $this->authority->getSuggestions($input);

        if (count($suggestions) > 1) {
            $previousConfidence = 101; // Start higher than max

            foreach ($suggestions as $suggestion) {
                $this->assertLessThanOrEqual(
                    $previousConfidence,
                    $suggestion->confidence,
                    'Suggestions should be ordered by confidence descending'
                );
                $previousConfidence = $suggestion->confidence;
            }
        } else {
            $this->markTestSkipped('Need multiple results to test ordering');
        }
    }

    /**
     * Test all suggestions meet display threshold (>= 40)
     */
    public function testDisplayThreshold(): void
    {
        $input = 'شركة النورس';

        $suggestions = $this->authority->getSuggestions($input);

        foreach ($suggestions as $suggestion) {
            $this->assertGreaterThanOrEqual(
                40,
                $suggestion->confidence,
                'All suggestions should meet display threshold (>= 40)'
            );
        }
    }

    /**
     * Test SuggestionDTO format consistency
     */
    public function testSuggestionDTOFormat(): void
    {
        $input = 'شركة النورس';

        $suggestions = $this->authority->getSuggestions($input);

        if (empty($suggestions)) {
            $this->markTestSkipped('No suggestions returned');
        }

        $suggestion = $suggestions[0];

        // Required fields
        $this->assertIsInt($suggestion->supplier_id);
        $this->assertIsString($suggestion->official_name);
        $this->assertIsInt($suggestion->confidence);
        $this->assertContains($suggestion->level, ['B', 'C', 'D']);
        $this->assertIsString($suggestion->reason_ar);
        $this->assertNotEmpty($suggestion->reason_ar);

        // Confidence range
        $this->assertGreaterThanOrEqual(0, $suggestion->confidence);
        $this->assertLessThanOrEqual(100, $suggestion->confidence);
    }

    /**
     * Test normalization consistency (same normalized input = same results)
     */
    public function testNormalizationConsistency(): void
    {
        $input1 = 'شركة  النورس'; // Extra spaces
        $input2 = 'شركة النورس';   // Normal

        $suggestions1 = $this->authority->getSuggestions($input1);
        $suggestions2 = $this->authority->getSuggestions($input2);

        // Should return same results (normalization makes them identical)
        $this->assertCount(count($suggestions2), $suggestions1);

        if (!empty($suggestions1)) {
            $this->assertEquals(
                $suggestions1[0]->supplier_id,
                $suggestions2[0]->supplier_id,
                'Normalized inputs should return same suggestions'
            );
        }
    }
}
