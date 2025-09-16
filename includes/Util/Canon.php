<?php

declare(strict_types=1);

namespace EVDataBridge\Util;

/**
 * Canonical data normalization utilities
 */
class Canon {
    
    /**
     * Normalize operator name to lowercase key without diacritics and legal suffixes
     */
    public static function opKey(string $name): string {
        if (empty($name)) {
            return '';
        }
        
        // Convert to lowercase
        $key = mb_strtolower($name, 'UTF-8');
        
        // Remove diacritics
        $key = self::removeDiacritics($key);
        
        // Remove legal suffixes
        $key = self::removeLegalSuffixes($key);
        
        // Clean up punctuation and extra spaces
        $key = preg_replace('/[^\w\s]/', ' ', $key);
        $key = preg_replace('/\s+/', ' ', $key);
        $key = trim($key);
        
        return $key;
    }
    
    /**
     * Generate unique key from operator key and coordinates
     */
    public static function uniqKey(string $opKey, float $lat, float $lon): string {
        $lat_5dp = round($lat, 5);
        $lon_5dp = round($lon, 5);
        
        return sprintf('%s|%.5f|%.5f', $opKey, $lat_5dp, $lon_5dp);
    }
    
    /**
     * Remove diacritics from string
     */
    private static function removeDiacritics(string $text): string {
        $diacritics = [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
            'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u'
        ];
        
        return strtr($text, $diacritics);
    }
    
    /**
     * Remove legal entity suffixes
     */
    private static function removeLegalSuffixes(string $text): string {
        $suffixes = [
            'gmbh', 'ag', 'kg', 'gbr', 'ug', 'mbh', 's.r.o.', 'a.s.', 'spol', 'k.s.',
            'sro', 'as', 'spol sro', 'spol s r o', 'spol s.r.o.',
            'inc', 'corp', 'llc', 'ltd', 'limited', 'plc', 'nv', 'bv', 'ab', 'oy'
        ];
        
        foreach ($suffixes as $suffix) {
            $pattern = '/\b' . preg_quote($suffix, '/') . '\b/i';
            $text = preg_replace($pattern, '', $text);
        }
        
        return trim($text);
    }
}
