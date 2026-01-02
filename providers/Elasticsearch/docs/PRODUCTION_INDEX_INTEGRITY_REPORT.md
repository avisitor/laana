# Production Index Integrity Report

**Generated:** Tue Aug 12 03:23:00 PM HST 2025
**Test Suite:** Hawaiian Search System PHP Production Tests

## Overall Status: ⚠️ FUNCTIONAL WITH ISSUES

The production indices are operational but have some integrity issues that should be addressed.

## Index Existence ✅ ALL GOOD

- ✅ Main index `hawaiian_hybrid` exists
- ✅ Metadata index `hawaiian_hybrid-metadata` exists  
- ✅ Source metadata index `hawaiian_hybrid-source-metadata` exists

## Document Counts ✅ HEALTHY

- **Main index:** 950-953 documents
- **Metadata index:** 219,953-220,412 documents
- **Metadata-to-main ratio:** 231:1 (indicates proper sentence-level metadata)

## Source Metadata Tracking ✅ EXCELLENT

- **Processed source IDs:** 907 (100% have corresponding documents)
- **Discarded source IDs:** 3,041
- **English-only IDs:** 2
- **No-Hawaiian IDs:** 0
- **Data consistency:** 100% - all sampled documents are properly tracked

## Field Mapping Issues ⚠️ NEEDS ATTENTION

### Main Index Fields ✅ GOOD
- ✅ `doc_id` (keyword)
- ✅ `text` (text)
- ✅ Tue Aug 12 03:23:00 PM HST 2025 (date)

### Metadata Index Fields ⚠️ SOME MISSING
- ✅ `sentence_hash` (text) - *Should be keyword for exact matching*
- ❌ `source_id` - **MISSING CRITICAL FIELD**
- ❌ `sentence_text` - **MISSING CRITICAL FIELD**
- ✅ `word_count` (long)
- ✅ `hawaiian_word_ratio` (float)
- ✅ `boilerplate_score` (float)
- ✅ `entity_count` (long)
- ✅ `frequency` (long)

## Critical Issues Found 🚨

### 1. Missing Embeddings
- **Issue:** Sample document shows 0 embedding dimensions (should be 384)
- **Impact:** Vector similarity search will not work
- **Priority:** HIGH

### 2. Missing Metadata Fields  
- **Issue:** `source_id` and `sentence_text` fields missing from metadata index
- **Impact:** Cannot link sentences back to their source documents
- **Priority:** HIGH

### 3. Incorrect Field Type
- **Issue:** `sentence_hash` is `text` type instead of `keyword`
- **Impact:** Exact hash matching may be inefficient
- **Priority:** MEDIUM

## Recommendations

### Immediate Actions Required

1. **Fix Embedding Issue**
   - Check embedding service connectivity
   - Verify documents are being indexed with embeddings
   - May need to reindex recent documents

2. **Add Missing Metadata Fields**
   - Update metadata index mapping to include `source_id` and `sentence_text`
   - Rerun metadata extraction to populate missing fields

3. **Fix Field Types**
   - Update `sentence_hash` mapping from `text` to `keyword`

### System Health Summary

- **Index Infrastructure:** ✅ Healthy
- **Document Tracking:** ✅ Excellent
- **Data Consistency:** ✅ Excellent  
- **Field Mappings:** ⚠️ Needs fixes
- **Embeddings:** 🚨 Critical issue

## Next Steps

1. Run the metadata rebuilder with updated field mappings
2. Verify embedding service is running and accessible
3. Test a small batch reindex to confirm embeddings are working
4. Once embeddings are fixed, proceed with full reindexing if needed

The system is ready for continued indexing once the embedding issue is resolved and metadata fields are corrected.
