"""
Shared configuration loader for Elasticsearch mapping and query templates.
Used by both Python indexing and querying code.
"""

import json
import os
from typing import Dict, Any, Optional
import re

class ConfigLoader:
    def __init__(self, config_dir: str = None):
        if config_dir is None:
            # Default to config directory relative to this file
            self.config_dir = os.path.join(os.path.dirname(__file__), 'config')
        else:
            self.config_dir = config_dir
    
    def load_config(self, config_file: str) -> Dict[str, Any]:
        """Load configuration from JSON file."""
        config_path = os.path.join(self.config_dir, config_file)
        if not os.path.exists(config_path):
            raise FileNotFoundError(f"Configuration file not found: {config_path}")
        
        try:
            with open(config_path, 'r') as f:
                return json.load(f)
        except json.JSONDecodeError as e:
            raise ValueError(f"Invalid JSON in configuration file {config_path}: {e}")
    
    def get_index_mapping(self) -> Dict[str, Any]:
        """Get the main index mapping configuration."""
        return self.load_config('index_mapping.json')
    
    def get_metadata_mapping(self) -> Dict[str, Any]:
        """Get the metadata index mapping configuration."""
        return self.load_config('metadata_mapping.json')
    
    def get_query_templates(self) -> Dict[str, Any]:
        """Get all query templates."""
        return self.load_config('query_templates.json')
    
    def build_query_from_template(self, template_name: str, variables: Dict[str, Any] = None) -> Dict[str, Any]:
        """Build a query from a template with variable substitution."""
        templates = self.get_query_templates()
        
        if template_name not in templates:
            raise ValueError(f"Query template not found: {template_name}")
        
        template = templates[template_name]
        
        if variables is None:
            variables = {}
        
        # Convert template to JSON string for variable substitution
        query_json = json.dumps(template)
        
        # Replace template variables
        for key, value in variables.items():
            placeholder = f"{{{{{key}}}}}"
            if isinstance(value, (list, dict)):
                # For complex types, replace the quoted placeholder with JSON
                query_json = query_json.replace(f'"{placeholder}"', json.dumps(value))
            else:
                # For simple types, replace directly
                query_json = query_json.replace(placeholder, json.dumps(str(value)).strip('"'))
        
        return json.loads(query_json)
    
    def create_index_params(self, index_name: str) -> Dict[str, Any]:
        """Create parameters for index creation."""
        mapping = self.get_index_mapping()
        return {
            'index': index_name,
            'body': mapping
        }
    
    def create_metadata_index_params(self, index_name: str) -> Dict[str, Any]:
        """Create parameters for metadata index creation."""
        mapping = self.get_metadata_mapping()
        return {
            'index': index_name,
            'body': mapping
        }

# Convenience instance
config_loader = ConfigLoader()
