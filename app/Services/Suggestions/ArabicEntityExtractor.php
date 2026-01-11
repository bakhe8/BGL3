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
            $commonWords = [
                // Arabic
                'شركة', 'مؤسسة', 'مكتب', 'دار', 'في', 'من', 'الى', 'على',
                'مجموعة', 'العالمية', 'الوطنية', 'للتجارة', 'المقاولات', 'خدمات',
                'توريد', 'استيراد', 'تصدير', 'عامة', 'للمقاولات', 'التجارية',
                'المحدودة', 'القابضة', 'الاستثمارية', 'الاولى', 'العربية', 'السعودية',

                // English
                'company', 'co', 'corp', 'inc', 'ltd', 'limited', 'llc',
                'establishment', 'est', 'trading', 'general', 'group',
                'international', 'national', 'technology', 'services',
                'contracting', 'engineering', 'works', 'supplies',
                'import', 'export', 'holdings', 'investment', 'arabia', 'saudi',
                'the', 'and', 'for', 'of', 'to', 'in', 'at'
            ];
            
            if (in_array(mb_strtolower($word), $commonWords)) {
                continue;
            }
            
            $anchors[] = $word;
        }
        
        return array_unique($anchors);
    }
}
