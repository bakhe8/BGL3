<?php

namespace App\Services\Suggestions;

/**
 * Arabic Entity Extractor (Stub)
 * 
 * Extracts entity anchors from Arabic text.
 * This is a STUB for Phase 2 - full implementation in Phase 2B.
 * 
 * For now, returns simple word extraction.
 * 
 * TODO: Implement full entity extraction logic:
 * - Remove common prefixes (شركة، مؤسسة، مكتب)
 * - Extract distinctive words
 * - Handle compound entities
 */
class ArabicEntityExtractor
{
    /**
     * Extract entity anchors from text
     * 
     * @param string $text Normalized Arabic text
     * @return array<string> Array of anchors
     */
    public function extractAnchors(string $text): array
    {
        // Simple stub: split by space and return words > 3 chars
        $words = explode(' ', $text);
        
        $anchors = [];
        foreach ($words as $word) {
            $word = trim($word);
            
            // Skip empty, short words, and common prefixes
            if (empty($word) || mb_strlen($word) < 3) {
                continue;
            }
            
            // Skip very common words (simple list for stub)
            $commonWords = ['شركة', 'مؤسسة', 'مكتب', 'دار', 'في', 'من', 'الى', 'على'];
            if (in_array($word, $commonWords)) {
                continue;
            }
            
            $anchors[] = $word;
        }
        
        return array_unique($anchors);
    }
}
