# AGENTS.md — Noiiolelo

Guidance for AI agents and developers working in this repository. Topic docs live in `docs/` — start with `docs/README.md` for the index (ingestion, index structures, searching, save manager).

## Provider philosophy

The existence of four parallel providers is an artifact of an ongoing exploration into which one works best - most functionality and accuracy, good resource management. Eventually all but one will be retired. That means each one must be completely independent and able to do all indexing, verification and searches without reliance on any other provider. In the meantime, to save resource usage and time, there are scripts like createindex.php to bootstrap one from another. As a consequence, there should never be a requirement that one provider exists in order to deliver or verify correctness of another, although during the current development we do that frequently as part of the ongoing assessment.

The four providers today: MySQL, Postgres, Elasticsearch, OpenSearch (see `PROVIDERS` in `scripts/updatenoiilelo.sh`). When a provider is retired, everything needed to index, verify and search must retire with it or be replaced within the survivors.
