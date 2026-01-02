# Hawaiian Search System Documentation

This directory contains comprehensive documentation for the Hawaiian Search system.

## Documentation Files

### Main Documentation
- **[README.md](README.md)** - Complete guide to all testing and analysis scripts
- **[README_metadata_implementation.md](README_metadata_implementation.md)** - Metadata system implementation details

### Development Documentation  
- **[REFACTORING_SUMMARY.md](REFACTORING_SUMMARY.md)** - Summary of system refactoring and improvements
- **[COMPREHENSIVE_DUPLICATION_ANALYSIS.md](COMPREHENSIVE_DUPLICATION_ANALYSIS.md)** - Analysis of code duplication and consolidation

## Script Directory
The executable scripts are located in: `../scripts/`

### Available Scripts (7 PHP + 1 Python)
1. **search_mode_tester.php** - Test all search modes with flexible options
2. **regex_tester.php** - Comprehensive regex testing including chunked documents  
3. **index_operations.php** - Complete Elasticsearch index management
4. **document_analyzer.php** - Document-specific analysis and processing
5. **performance_tester.php** - Performance testing and benchmarking suite
6. **metadata_manager.php** - Metadata management, migration, and validation
7. **system_diagnostics.php** - System health checks and diagnostics
8. **nlp_enhancer.py** - NLP analysis and text enhancement (Python)

## Quick Start
```bash
# Navigate to scripts directory
cd ../scripts/

# Get help for any script
php search_mode_tester.php --help
php regex_tester.php --help
python3 nlp_enhancer.py --help

# Example usage
php search_mode_tester.php --modes=term,phrase --query=aloha --limit=5
php performance_tester.php --config-test
php system_diagnostics.php --all-diagnostics
```

## Features
- ✅ All scripts are production-ready and fully tested
- ✅ Comprehensive command-line interfaces with `--help` documentation  
- ✅ Robust error handling and validation
- ✅ Dynamic index name resolution from ElasticsearchClient
- ✅ Safe field mapping detection and handling
- ✅ Export capabilities for analysis results

This consolidation reduced **55 individual files to 8 comprehensive tools** (85% reduction) while enhancing functionality and maintainability.
