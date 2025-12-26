<?php
/**
 * Setup Module - Text Normalizer
 * Isolated normalizer for setup module
 */

class SetupNormalizer
{
    public static function normalize(string $text): string
    {
        // Remove diacritics
        $text = preg_replace('/[\x{064B}-\x{065F}]/u', '', $text);
        
        // Normalize Arabic letters
        $replacements = [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
        ];
        
        $text = str_replace(array_keys($replacements), array_values($replacements), $text);
        
        // To lowercase
        $text = mb_strtolower($text, 'UTF-8');
        
        // Remove all non-letter, non-number characters
        $text = preg_replace('/[^\p{L}\p{N}]/u', '', $text);
        
        return trim($text);
    }
}
