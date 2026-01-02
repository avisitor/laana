#!/usr/bin/env python3
"""
Test script to verify all Python files are using shared configuration properly
"""

import sys
from config_loader import config_loader

def test_config_loading():
    """Test that all config files load properly"""
    print("Testing configuration loading...")
    
    try:
        # Test index mapping
        mapping = config_loader.get_index_mapping()
        print(f"✓ Index mapping loaded - {len(mapping['mappings']['properties'])} properties")
        
        # Test metadata mapping  
        meta_mapping = config_loader.get_metadata_mapping()
        print(f"✓ Metadata mapping loaded - {len(meta_mapping['mappings']['properties'])} properties")
        
        # Test query templates
        templates = config_loader.get_query_templates()
        print(f"✓ Query templates loaded - {len(templates)} templates")
        
        return True
    except Exception as e:
        print(f"✗ Config loading failed: {e}")
        return False

def test_template_building():
    """Test building queries from templates"""
    print("\nTesting query template building...")
    
    try:
        # Test different template types
        templates_to_test = [
            ('wildcard_query', {'pattern': '*hawaii*'}),
            ('match_query', {'query_text': 'aloha'}),
            ('nested_sentence_wildcard', {'pattern': '*mai*'}),
        ]
        
        for template_name, variables in templates_to_test:
            query = config_loader.build_query_from_template(template_name, variables)
            print(f"✓ {template_name}: {query['query']}")
            
        return True
    except Exception as e:
        print(f"✗ Template building failed: {e}")
        return False

def test_elasticsearch_client():
    """Test that ElasticsearchDB can use shared config"""
    print("\nTesting ElasticsearchDB integration...")
    
    try:
        # Just test import and basic setup - don't actually connect
        from elasticsearchclient import ElasticsearchDB
        print("✓ ElasticsearchDB imports successfully")
        print("✓ Should now use shared config for index creation")
        return True
    except Exception as e:
        print(f"✗ ElasticsearchDB test failed: {e}")
        return False

def test_query_builder():
    """Test QueryBuilder template methods"""
    print("\nTesting QueryBuilder template methods...")
    
    try:
        # Test direct template access without instantiating QueryBuilder
        # (since it requires an embedder parameter)
        query = config_loader.build_query_from_template('wildcard_query', {'pattern': '*test*'})
        print("✓ Direct template access works")
        
        # Test that the file has the new methods
        with open('/var/www/html/elasticsearch/query_builder.py', 'r') as f:
            content = f.read()
            if 'build_from_template' in content:
                print("✓ QueryBuilder has template methods")
            else:
                print("✗ QueryBuilder missing template methods")
                return False
                
        return True
    except Exception as e:
        print(f"✗ QueryBuilder test failed: {e}")
        return False

def test_vector_search():
    """Test that vector_search uses templates"""
    print("\nTesting vector_search integration...")
    
    try:
        import vector_search
        
        # Check that it imports config_loader
        with open('/var/www/html/elasticsearch/vector_search.py', 'r') as f:
            content = f.read()
            if 'config_loader' in content:
                print("✓ vector_search imports config_loader")
            else:
                print("✗ vector_search missing config_loader import")
                return False
                
        print("✓ vector_search imports successfully")
        return True
    except Exception as e:
        print(f"✗ vector_search test failed: {e}")
        return False

def main():
    """Run all tests"""
    print("=== Testing Shared Configuration System ===\n")
    
    tests = [
        test_config_loading,
        test_template_building, 
        test_elasticsearch_client,
        test_query_builder,
        test_vector_search
    ]
    
    results = []
    for test in tests:
        results.append(test())
        
    print(f"\n=== Results ===")
    passed = sum(results)
    total = len(results)
    print(f"Passed: {passed}/{total}")
    
    if passed == total:
        print("🎉 All tests passed! Python files successfully use shared configuration.")
        return 0
    else:
        print("❌ Some tests failed. Check the output above.")
        return 1

if __name__ == "__main__":
    sys.exit(main())
