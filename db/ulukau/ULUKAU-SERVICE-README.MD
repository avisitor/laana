# Ulukau Scraper Service

This project provides two dual‑use modules for interacting with the Ulukau digital library:

- ulukau-doc.js — fetches and renders the text/HTML of a specific Ulukau document by OID.
- ulukau-metadata.js — scrapes metadata (title, author, date, cover image, URL) for Hawaiian language books in Ulukau.

Each module can be used either:
1. As an Express web service (mounted in service.js).
2. As a standalone CLI script (invoked directly with node).

---

## Project structure

project/
  ├── service.js           # Main entry point for web service
  ├── ulukau-doc.js        # Dual-use module: document scraper
  ├── ulukau-metadata.js   # Dual-use module: metadata scraper
  └── output/              # Optional output directory for saved JSON

---

## Running as a web service

Install the service:
  sudo cp ulukau.service /etc/systemd/system

Start the service:
  node service.js
or:
  sudo systemctl start ulukau

By default it listens on http://localhost:3000.

Endpoints:

- Document scraper
  GET /doc?oid=<OID>&json=true
  Parameters:
    - oid (required): Ulukau document OID (e.g. EBOOK-12345).
    - json (optional): true to return JSON ({ text, html }), otherwise streams HTML.
  Example curl:
    curl "http://localhost:3000/doc?oid=EBOOK-12345&json=true"

- Metadata scraper
  GET /metadata?limit=<N>&oid=<OID>
  Parameters:
    - limit (optional): maximum number of entries to return.
    - oid (optional): filter for a specific OID.
  Example curl:
    curl "http://localhost:3000/metadata?limit=5"
    curl "http://localhost:3000/metadata?oid=EBOOK-12345"

Results are returned as JSON arrays. If no filters are applied, metadata is also saved to output/ulukau.json.

---

## Running from the command line

Both modules can be run directly with node.

- Document scraper
  node ulukau-doc.js --oid=EBOOK-12345 --json=true

  Options:
    --oid, -o (required): Document OID.
    --json: Output JSON instead of streaming HTML.
    --output, -d: Directory to save output (default ./output).
    --quiet, -q: Suppress info logs.
    --print: Echo text content to stderr.
    --recreate, -r: Force recreation of output.
    --verbose, -v: Verbose logging.

- Metadata scraper
  node ulukau-metadata.js --limit=10
  node ulukau-metadata.js --oid=EBOOK-12345

  Options:
    --limit, -l: Maximum number of entries.
    --oid, -o: Filter for a specific OID.

Results are printed as JSON. If no filters are applied, full metadata is also saved to output/ulukau.json.

---

## Dependencies

- Express
- Puppeteer
- puppeteer-extra + puppeteer-extra-plugin-stealth
- cheerio
- node-fetch
- minimist

Install:
  npm install express puppeteer puppeteer-extra puppeteer-extra-plugin-stealth cheerio node-fetch minimist

---

## Notes

- The document scraper uses Puppeteer with a specified Chrome executable path. Adjust executablePath in ulukau-doc.js if your environment differs.
- Both modules implement retry logic and delays to avoid overwhelming the Ulukau servers.
- Metadata scraping is limited to Hawaiian language entries (data-language="haw").
