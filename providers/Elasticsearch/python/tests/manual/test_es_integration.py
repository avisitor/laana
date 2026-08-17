"""Manual integration tests for the Elasticsearch client.

These require a live Elasticsearch cluster, the API key from noiiolelo/.env,
and the sentence-transformer model. They are skipped by default and are
excluded from normal ``pytest`` runs via ``norecursedirs`` in pyproject.toml.

Run them explicitly with:

    pytest tests/manual/test_es_integration.py -s

Converted from the ad-hoc ``test.py`` and the ES portions of
``test_shared_config.py``.
"""

import os
import subprocess
import sys
from pathlib import Path

import pytest

PROJECT_ROOT = Path(__file__).resolve().parents[1]
NOIIOLELO_ENV = PROJECT_ROOT.parents[3] / ".env"

pytestmark = pytest.mark.skip(
    reason="requires live Elasticsearch + API_KEY; run manually with pytest tests/manual -s"
)


def _env_with_credentials():
    env = dict(os.environ)
    if NOIIOLELO_ENV.exists():
        for line in NOIIOLELO_ENV.read_text().splitlines():
            if "=" in line and not line.strip().startswith("#"):
                key, value = line.split("=", 1)
                env.setdefault(key.strip(), value.strip())
    env.setdefault("API_KEY", env.get("ES_API_KEY", ""))
    return env


def test_es_integration_script():
    result = subprocess.run(
        [sys.executable, str(PROJECT_ROOT / "test.py")],
        env=_env_with_credentials(),
        capture_output=True,
        text=True,
        timeout=600,
    )
    assert result.returncode == 0, result.stdout + result.stderr


def test_es_client_imports():
    import elasticsearchclient

    assert hasattr(elasticsearchclient, "ElasticsearchDB")