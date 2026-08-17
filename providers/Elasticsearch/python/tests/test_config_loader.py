import json

import pytest

from config_loader import ConfigLoader


def test_config_loader_missing_dir_raises(tmp_path):
    loader = ConfigLoader(config_dir=str(tmp_path / "does-not-exist"))
    with pytest.raises(FileNotFoundError):
        loader.get_index_mapping()


def test_config_loader_invalid_json_raises(tmp_path):
    (tmp_path / "index_mapping.json").write_text("{not valid json")
    loader = ConfigLoader(config_dir=str(tmp_path))
    with pytest.raises(ValueError):
        loader.get_index_mapping()


def test_config_loader_get_index_mapping(tmp_path):
    mapping = {"mappings": {"properties": {"text": {"type": "text"}}}}
    (tmp_path / "index_mapping.json").write_text(json.dumps(mapping))
    loader = ConfigLoader(config_dir=str(tmp_path))
    assert loader.get_index_mapping() == mapping


def test_build_query_from_template(tmp_path):
    templates = {
        "wildcard_query": {"query": {"wildcard": {"text": "{{pattern}}"}}}
    }
    (tmp_path / "query_templates.json").write_text(json.dumps(templates))
    loader = ConfigLoader(config_dir=str(tmp_path))
    query = loader.build_query_from_template("wildcard_query", {"pattern": "*aloha*"})
    assert query["query"]["wildcard"]["text"] == "*aloha*"


def test_build_query_from_template_missing_variable_raises(tmp_path):
    (tmp_path / "query_templates.json").write_text("{}")
    loader = ConfigLoader(config_dir=str(tmp_path))
    with pytest.raises(ValueError):
        loader.build_query_from_template("no_such_template", {})