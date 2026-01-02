# Comprehensive Duplication Analysis

## 🚨 Major Duplications Found

### 1. **Hawaiian Word Normalization - CRITICAL DUPLICATION**

#### **Location 1: CorpusIndexer.php** (lines 653-659)
```php
private function normalizeWordStatic(string $word): string
{
    $macrons = ['ā', 'ē', 'ī', 'ō', 'ū', 'Ā', 'Ē', 'Ī', 'Ō', 'Ū'];
    $plain = ['a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U'];
    $word = str_replace($macrons, $plain, $word);
    return strtolower(str_replace(["'", "'"], "", $word));
}
```

#### **Location 2: CorpusScanner.php** (lines 32-42)
```php
public static function normalizeWord(string $word): string {
    $word = str_replace(["'", "'"], "", $word);
    $macrons = ['ā', 'ē', 'ī', 'ō', 'ū', 'Ā', 'Ē', 'Ī', 'Ō', 'Ū'];
    $plain = ['a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U'];
    $word = str_replace($macrons, $plain, $word);
    return strtolower(trim($word));
}
```

**Impact**: Same logic, slightly different implementation (trim vs no trim)

### 2. **Hawaiian Words File Loading - CRITICAL DUPLICATION**

#### **Location 1: CorpusIndexer.php** (lines 625-650)
```php
private function loadHawaiianWordsAsHashSet(string $filePath): array
{
    if (!file_exists($filePath)) {
        return [];
    }
    
    $words = file_get_contents($filePath);
    $lines = explode("\n", $words);
    $wordSet = []; // Use associative array as hash set for O(1) lookups
    
    foreach ($lines as $line) {
        $commaParts = array_map('trim', explode(',', $line));
        foreach ($commaParts as $part) {
            $wordParts = explode(' ', $part);
            foreach ($wordParts as $word) {
                $word = trim($word);
                if ($word && strtolower($word) !== 'a' && strtolower($word) !== 'i') {
                    $normalized = $this->normalizeWordStatic($word);
                    $wordSet[$normalized] = true; // Hash set - key exists = true
                }
            }
        }
    }
    
    return $wordSet;
}
```

#### **Location 2: rebuild_metadata_index.php** (lines ~580-610)
```php
private function loadHawaiianWordsAsHashSet(string $filePath): array
{
    if (!file_exists($filePath)) {
        echo "⚠️  Hawaiian words file not found: {$filePath}\n";
        return [];
    }
    
    $words = file_get_contents($filePath);
    $lines = explode("\n", $words);
    $wordSet = [];
    
    foreach ($lines as $line) {
        $commaParts = array_map('trim', explode(',', $line));
        foreach ($commaParts as $part) {
            $wordParts = explode(' ', $part);
            foreach ($wordParts as $word) {
                $word = trim($word);
                if ($word && strtolower($word) !== 'a' && strtolower($word) !== 'i') {
                    $normalized = MetadataExtractor::normalizeWord($word);
                    $wordSet[$normalized] = true;
                }
            }
        }
    }
    
    return $wordSet;
}
```

**Impact**: Almost identical logic, different error handling and normalization calls

### 3. **Elasticsearch Client Reflection Pattern - MODERATE DUPLICATION**

#### **Pattern repeated 4 times in MetadataExtractor.php:**
```php
// Get the underlying Elasticsearch client
$reflection = new \ReflectionClass($this->client);
$clientProperty = $reflection->getProperty('client');
$clientProperty->setAccessible(true);
$esClient = $clientProperty->getValue($this->client);
```

**Locations:**
- Line 68-71 (saveSentenceMetadata)
- Line 94-97 (getSentenceMetadata) 
- Line 123-126 (bulkSaveSentenceMetadata)
- Line 166-169 (createMetadataIndex)

### 4. **HTTP Client Initialization - MINOR DUPLICATION**

#### **Location 1: CorpusIndexer.php** (line 94)
```php
$this->httpClient = new HttpClient();
```

#### **Location 2: EmbeddingClient.php** (line 13)
```php
$this->httpClient = new HttpClient();
```

**Impact**: Standard pattern, minimal impact

### 5. **Hawaiian Word Ratio Calculation - RESOLVED BUT INCONSISTENT**

#### **Location 1: CorpusIndexer.php** (lines 285-328)
- Has its own `calculateHawaiianWordRatio()` method
- Uses `$this->scanner->normalizeWord($word)` (line 315)
- Has caching mechanism

#### **Location 2: CorpusScanner.php** (lines 47-76)
- Has `calculateHawaiianWordRatio()` method  
- Uses `self::normalizeWord($word)` (line 68)
- No caching

**Impact**: CorpusIndexer should delegate to CorpusScanner but doesn't

## 🔧 Recommended Refactoring

### **Priority 1: Critical Duplications**

#### **1. Consolidate Word Normalization**
**Action**: Remove `normalizeWordStatic()` from CorpusIndexer, use CorpusScanner's static method
```php
// Replace in CorpusIndexer:
$normalized = $this->normalizeWordStatic($word);
// With:
$normalized = CorpusScanner::normalizeWord($word);
```

#### **2. Create Hawaiian Words Utility Class**
**Action**: Create new `HawaiianWordLoader` utility class
```php
class HawaiianWordLoader 
{
    public static function loadAsHashSet(string $filePath): array
    {
        // Consolidated logic here
    }
}
```

#### **3. Fix CorpusIndexer Hawaiian Word Ratio**
**Action**: Remove duplicate method, delegate to CorpusScanner
```php
// Replace in CorpusIndexer:
private function calculateHawaiianWordRatio(string $text): float
// With delegation to:
$this->scanner->calculateHawaiianWordRatio($text)
```

### **Priority 2: Moderate Duplications**

#### **4. Create Elasticsearch Client Helper**
**Action**: Add method to ElasticsearchClient or create utility
```php
// In ElasticsearchClient:
public function getRawClient(): Client 
{
    return $this->client;
}

// Then replace reflection pattern with:
$esClient = $this->client->getRawClient();
```

### **Priority 3: Minor Optimizations**

#### **5. HTTP Client Factory (Optional)**
**Action**: Create shared HTTP client configuration if needed

## 📊 Impact Assessment

### **Lines of Duplicated Code:**
- Hawaiian word loading: ~50 lines (2 locations)
- Word normalization: ~10 lines (2 locations)  
- Elasticsearch reflection: ~16 lines (4 locations)
- Hawaiian word ratio: ~45 lines (2 locations)

**Total: ~121 lines of duplicated code**

### **Maintenance Risk:**
- **High**: Changes to Hawaiian word processing need multiple updates
- **Medium**: Elasticsearch access pattern changes need multiple updates
- **Low**: HTTP client changes are minimal

### **Performance Impact:**
- **High**: Multiple file reads of hawaiian_words.txt
- **Medium**: Redundant ratio calculations in CorpusIndexer
- **Low**: Multiple HTTP client instances

## 🎯 Immediate Action Items

### **Quick Wins (15 minutes each):**
1. Remove `normalizeWordStatic()` from CorpusIndexer
2. Add `getRawClient()` method to ElasticsearchClient
3. Replace reflection pattern in MetadataExtractor

### **Larger Refactoring (30-60 minutes each):**
1. Create `HawaiianWordLoader` utility class
2. Fix CorpusIndexer Hawaiian word ratio delegation
3. Consolidate caching mechanisms

## ✅ Testing Strategy

1. **Unit tests** for each refactored method
2. **Integration tests** for Hawaiian word processing
3. **Performance tests** to ensure no regression
4. **Rebuild metadata script** dry-run validation

The most critical issue is the **Hawaiian word processing duplication** which affects performance and maintainability across the entire system.
