<?php
/**
 * Fuzzy Matcher - Unscharfe Produktsuche für eigene Produkte
 * PHP 8.3+ compatible
 * 
 * Implementiert verschiedene Matching-Algorithmen:
 * - Levenshtein-Distanz
 * - Similar Text
 * - Wort-basiertes Matching
 * - Kategorie-Matching
 * - N-Gram Matching
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Fuzzy_Matcher {
    
    /**
     * Standard-Gewichtungen für verschiedene Matching-Faktoren
     */
    private const DEFAULT_WEIGHTS = [
        'title'       => 0.40,  // 40% - Titel ist am wichtigsten
        'description' => 0.25,  // 25% - Beschreibung
        'category'    => 0.20,  // 20% - Kategorie-Übereinstimmung
        'merchant'    => 0.10,  // 10% - Händler-Name
        'keywords'    => 0.05,  // 5% - Zusätzliche Keywords (Meta)
    ];
    
    /**
     * Standard-Schwellenwert für Mindest-Übereinstimmung (0-100)
     */
    private const DEFAULT_THRESHOLD = 30;
    
    /**
     * Deutsche Stoppwörter die beim Matching ignoriert werden
     */
    private const STOPWORDS_DE = [
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'einem', 'einen',
        'und', 'oder', 'aber', 'wenn', 'weil', 'dass', 'als', 'auch', 'nur', 'noch',
        'für', 'mit', 'von', 'bei', 'nach', 'aus', 'zum', 'zur', 'im', 'am', 'um',
        'ist', 'sind', 'war', 'hat', 'haben', 'wird', 'werden', 'kann', 'können',
        'auf', 'an', 'in', 'zu', 'so', 'wie', 'was', 'wer', 'wo', 'sehr', 'mehr',
        'nicht', 'kein', 'keine', 'keinen', 'keiner', 'ohne', 'bis', 'über', 'unter',
    ];
    
    /**
     * Englische Stoppwörter
     */
    private const STOPWORDS_EN = [
        'the', 'a', 'an', 'and', 'or', 'but', 'if', 'then', 'else', 'when', 'at', 'from',
        'by', 'on', 'off', 'for', 'in', 'out', 'over', 'to', 'into', 'with', 'is', 'are',
        'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
        'will', 'would', 'could', 'should', 'may', 'might', 'must', 'shall', 'can',
        'of', 'up', 'down', 'no', 'not', 'only', 'own', 'same', 'so', 'than', 'too',
        'very', 's', 't', 'just', 'don', 'now', 'new', 'old', 'all', 'any', 'both',
    ];
    
    /** @var array<string, float> */
    private array $weights;
    
    /** @var int */
    private int $threshold;
    
    /** @var array<string> */
    private array $stopwords;
    
    /**
     * Constructor
     * 
     * @param array<string, float>|null $weights Custom weights
     * @param int|null $threshold Minimum match score (0-100)
     */
    public function __construct(?array $weights = null, ?int $threshold = null) {
        $this->weights = $weights ?? $this->get_configured_weights();
        $this->threshold = $threshold ?? $this->get_configured_threshold();
        $this->stopwords = array_merge(self::STOPWORDS_DE, self::STOPWORDS_EN);
    }
    
    /**
     * Get configured weights from plugin settings
     * 
     * @return array<string, float>
     */
    private function get_configured_weights(): array {
        $weights = [];
        
        $weights['title'] = (float) yaa_get_option('fuzzy_weight_title', self::DEFAULT_WEIGHTS['title']);
        $weights['description'] = (float) yaa_get_option('fuzzy_weight_description', self::DEFAULT_WEIGHTS['description']);
        $weights['category'] = (float) yaa_get_option('fuzzy_weight_category', self::DEFAULT_WEIGHTS['category']);
        $weights['merchant'] = (float) yaa_get_option('fuzzy_weight_merchant', self::DEFAULT_WEIGHTS['merchant']);
        $weights['keywords'] = (float) yaa_get_option('fuzzy_weight_keywords', self::DEFAULT_WEIGHTS['keywords']);
        
        // Normalize weights to sum to 1.0
        $sum = array_sum($weights);
        if ($sum > 0 && $sum !== 1.0) {
            foreach ($weights as $key => $value) {
                $weights[$key] = $value / $sum;
            }
        }
        
        return $weights;
    }
    
    /**
     * Get configured threshold from plugin settings
     */
    private function get_configured_threshold(): int {
        return (int) yaa_get_option('fuzzy_threshold', self::DEFAULT_THRESHOLD);
    }
    
    /**
     * Calculate fuzzy match score between a keyword and a product
     * 
     * @param string $keyword Search keyword(s)
     * @param array<string, mixed> $product Product data array
     * @return array{score: float, matches: array<string, float>}
     */
    public function calculate_score(string $keyword, array $product): array {
        $keyword = $this->normalize_text($keyword);
        
        if ($keyword === '') {
            return ['score' => 0.0, 'matches' => []];
        }
        
        $matches = [];
        $total_score = 0.0;
        
        // 1. Title matching
        $title = $this->normalize_text($product['title'] ?? '');
        if ($title !== '') {
            $matches['title'] = $this->calculate_text_similarity($keyword, $title);
            $total_score += $matches['title'] * $this->weights['title'];
        }
        
        // 2. Description matching
        $description = $this->normalize_text($product['description'] ?? '');
        if ($description !== '') {
            $matches['description'] = $this->calculate_text_similarity($keyword, $description);
            $total_score += $matches['description'] * $this->weights['description'];
        }
        
        // 3. Category matching
        $categories = $product['categories'] ?? [];
        if (!empty($categories)) {
            $matches['category'] = $this->calculate_category_similarity($keyword, $categories);
            $total_score += $matches['category'] * $this->weights['category'];
        }
        
        // 4. Merchant matching
        $merchant = $this->normalize_text($product['merchant']['name'] ?? '');
        if ($merchant !== '') {
            $matches['merchant'] = $this->calculate_text_similarity($keyword, $merchant);
            $total_score += $matches['merchant'] * $this->weights['merchant'];
        }
        
        // 5. Additional keywords/tags matching
        $product_keywords = $product['keywords'] ?? $product['tags'] ?? [];
        if (!empty($product_keywords)) {
            if (is_string($product_keywords)) {
                $product_keywords = explode(',', $product_keywords);
            }
            $matches['keywords'] = $this->calculate_keywords_similarity($keyword, $product_keywords);
            $total_score += $matches['keywords'] * $this->weights['keywords'];
        }
        
        // Round to 2 decimal places
        $total_score = round($total_score * 100, 2);
        
        return [
            'score'   => $total_score,
            'matches' => $matches,
        ];
    }
    
    /**
     * Calculate text similarity using multiple algorithms
     * Returns a value between 0 and 1
     */
    private function calculate_text_similarity(string $search, string $target): float {
        if ($search === '' || $target === '') {
            return 0.0;
        }
        
        // Direct match bonus
        if ($target === $search) {
            return 1.0;
        }
        
        // Contains exact phrase bonus
        if (str_contains($target, $search)) {
            return 0.95;
        }
        
        $scores = [];
        
        // 1. Word-based matching (most important for product search)
        $scores[] = $this->word_match_score($search, $target) * 1.5; // Weight 1.5x
        
        // 2. Similar text percentage
        $similar_text_score = 0.0;
        similar_text($search, $target, $similar_text_score);
        $scores[] = $similar_text_score / 100;
        
        // 3. N-Gram matching (catches typos and partial matches)
        $scores[] = $this->ngram_similarity($search, $target, 2);
        
        // 4. Levenshtein for short strings (good for typo detection)
        if (strlen($search) <= 20 && strlen($target) <= 50) {
            $max_len = max(strlen($search), strlen($target));
            if ($max_len > 0) {
                $lev_distance = levenshtein(
                    substr($search, 0, 255), 
                    substr($target, 0, 255)
                );
                $scores[] = 1 - ($lev_distance / $max_len);
            }
        }
        
        // Weighted average (word matching is most important)
        $avg_score = count($scores) > 0 ? array_sum($scores) / count($scores) : 0.0;
        
        return min(1.0, max(0.0, $avg_score));
    }
    
    /**
     * Word-based matching score
     * Counts how many search words appear in the target
     */
    private function word_match_score(string $search, string $target): float {
        $search_words = $this->tokenize($search);
        $target_words = $this->tokenize($target);
        
        if (empty($search_words) || empty($target_words)) {
            return 0.0;
        }
        
        $matched_words = 0;
        $partial_matches = 0;
        
        foreach ($search_words as $search_word) {
            if (in_array($search_word, $target_words, true)) {
                // Exact word match
                $matched_words++;
            } else {
                // Check for partial matches (word starts with or contains)
                foreach ($target_words as $target_word) {
                    if (str_starts_with($target_word, $search_word) || 
                        str_starts_with($search_word, $target_word)) {
                        $partial_matches += 0.7;
                        break;
                    } elseif (str_contains($target_word, $search_word) || 
                              str_contains($search_word, $target_word)) {
                        $partial_matches += 0.4;
                        break;
                    }
                }
            }
        }
        
        $total_matches = $matched_words + $partial_matches;
        
        return $total_matches / count($search_words);
    }
    
    /**
     * N-Gram similarity (character-based)
     * Good for detecting typos and partial matches
     */
    private function ngram_similarity(string $a, string $b, int $n = 2): float {
        $ngrams_a = $this->get_ngrams($a, $n);
        $ngrams_b = $this->get_ngrams($b, $n);
        
        if (empty($ngrams_a) || empty($ngrams_b)) {
            return 0.0;
        }
        
        $intersection = array_intersect($ngrams_a, $ngrams_b);
        $union = array_unique(array_merge($ngrams_a, $ngrams_b));
        
        if (count($union) === 0) {
            return 0.0;
        }
        
        // Jaccard similarity
        return count($intersection) / count($union);
    }
    
    /**
     * Get n-grams from a string
     * 
     * @return array<string>
     */
    private function get_ngrams(string $text, int $n): array {
        $text = preg_replace('/\s+/', '', $text) ?? $text;
        $length = strlen($text);
        
        if ($length < $n) {
            return [$text];
        }
        
        $ngrams = [];
        for ($i = 0; $i <= $length - $n; $i++) {
            $ngrams[] = substr($text, $i, $n);
        }
        
        return $ngrams;
    }
    
    /**
     * Calculate category similarity
     * 
     * @param array<string> $categories
     */
    private function calculate_category_similarity(string $keyword, array $categories): float {
        if (empty($categories)) {
            return 0.0;
        }
        
        $keyword_normalized = $this->normalize_text($keyword);
        $keyword_words = $this->tokenize($keyword_normalized);
        
        $best_score = 0.0;
        
        foreach ($categories as $category) {
            $cat_normalized = $this->normalize_text($category);
            
            // Direct match
            if ($cat_normalized === $keyword_normalized) {
                return 1.0;
            }
            
            // Contains check
            if (str_contains($cat_normalized, $keyword_normalized)) {
                $best_score = max($best_score, 0.9);
                continue;
            }
            
            // Word match in category
            $cat_words = $this->tokenize($cat_normalized);
            foreach ($keyword_words as $kw) {
                if (in_array($kw, $cat_words, true)) {
                    $best_score = max($best_score, 0.8);
                }
            }
            
            // Partial similarity
            $similarity = $this->calculate_text_similarity($keyword_normalized, $cat_normalized);
            $best_score = max($best_score, $similarity * 0.7);
        }
        
        return $best_score;
    }
    
    /**
     * Calculate keywords/tags similarity
     * 
     * @param array<string> $product_keywords
     */
    private function calculate_keywords_similarity(string $search, array $product_keywords): float {
        if (empty($product_keywords)) {
            return 0.0;
        }
        
        $search_normalized = $this->normalize_text($search);
        $search_words = $this->tokenize($search_normalized);
        
        $matched = 0;
        $total = count($product_keywords);
        
        foreach ($product_keywords as $tag) {
            $tag_normalized = $this->normalize_text(trim($tag));
            
            // Direct match
            if ($tag_normalized === $search_normalized) {
                return 1.0;
            }
            
            // Word contains
            foreach ($search_words as $word) {
                if ($tag_normalized === $word || str_contains($tag_normalized, $word)) {
                    $matched++;
                    break;
                }
            }
        }
        
        return $total > 0 ? $matched / $total : 0.0;
    }
    
    /**
     * Normalize text for comparison
     */
    private function normalize_text(string $text): string {
        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');
        
        // Remove special characters but keep umlauts
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        
        // Replace umlauts for better matching
        $text = str_replace(
            ['ä', 'ö', 'ü', 'ß'],
            ['ae', 'oe', 'ue', 'ss'],
            $text
        );
        
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        
        return trim($text);
    }
    
    /**
     * Tokenize text into words (removing stopwords)
     * 
     * @return array<string>
     */
    private function tokenize(string $text): array {
        $words = explode(' ', $text);
        $words = array_filter($words, fn($word) => strlen($word) >= 2);
        
        // Remove stopwords
        $words = array_diff($words, $this->stopwords);
        
        return array_values($words);
    }
    
    /**
     * Check if score meets threshold
     */
    public function meets_threshold(float $score): bool {
        return $score >= $this->threshold;
    }
    
    /**
     * Get current threshold
     */
    public function get_threshold(): int {
        return $this->threshold;
    }
    
    /**
     * Set threshold
     */
    public function set_threshold(int $threshold): self {
        $this->threshold = max(0, min(100, $threshold));
        return $this;
    }
    
    /**
     * Get current weights
     * 
     * @return array<string, float>
     */
    public function get_weights(): array {
        return $this->weights;
    }
    
    /**
     * Sort products by score descending
     * 
     * @param array<array{product: array<string, mixed>, score: float}> $scored_products
     * @return array<array{product: array<string, mixed>, score: float}>
     */
    public function sort_by_score(array $scored_products): array {
        usort($scored_products, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored_products;
    }
    
    /**
     * Static helper: Quick similarity check
     */
    public static function quick_match(string $search, string $target): float {
        $matcher = new self();
        return $matcher->calculate_text_similarity(
            $matcher->normalize_text($search),
            $matcher->normalize_text($target)
        );
    }
}
