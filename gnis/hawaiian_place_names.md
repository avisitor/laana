# Hawaiian Place Names — crawl and assembly pipeline

Maintainer notes for `data/name_lists/hawaiian-place-names.txt` →
`data/name_lists/hawaiian_place_names.json` (+ `consistent/` mirror).

All pipeline scripts live in this directory (`gnis/`, at the repo root next to
`data/`) and are executed **from here** (all paths are relative). Paths below
are repo-root-relative for context. Written 2026-09-06.

---

## Deliverables and data flow

```
data/name_lists/hawaiian-place-names.txt   14,988 names, one per line, ASCII
        |
        |  gnis/build_final.py
        |    joins the txt against (1) the Ulukau HPN crawl, (2) three GNIS
        |    sources, (3) an ahupuaʻa-name fallback, (4) a suffix heuristic
        v
data/name_lists/hawaiian_place_names.json  14,859 entries (129 dup keys merged)
        |
        |  data/make_consistent.php  (transform already registered)
        v
data/name_lists/consistent/hawaiian_place_names.json
```

Output schema — same keyed-dict shape as `gnis_hawaii_places.json`, plus
location fields which are present **only when known** (omit-empty convention):

```json
"A Pohina": {
    "name": "A Pohina",
    "feature_class": "boundary point; place",
    "island": "Hawaiʻi",
    "moku": "Kaʻū",
    "ahupuaa": "Kapapala"
}
```

- key — `CorpusScanner::normalizeWord` semantics: strip `ʻ`/`'`/`’`, macrons →
  plain vowels, lowercase, trim (spaces kept; this is how the lookup side
  normalizes, so keys must match it exactly).
- `name` — the txt line (ASCII; identical to the HPN link text).
- `feature_class` — see precedence chain below. English GNIS classes
  (`Valley`, `Summit`, …) when GNIS has the feature; otherwise HPN's own
  vocabulary (`ʻili ʻaina`, `moʻo`, `ahupuaʻa`, `heiau`, `wahi pana`, …).
- `island` — canonical display spelling; multiple islands → comma-joined
  (`"Hawaiʻi, Oʻahu, Kauaʻi"` for names that exist on several islands).
- `moku` — the district part of HPN's "Mokupuni" value (`Hawaiʻi: Kaʻū` →
  island `Hawaiʻi`, moku `Kaʻū`). Only from HPN; GNIS has no district.
- `ahupuaa` — HPN ahupuaʻa values (comma-joined for multi-locale names).

**Coverage:** island 96.2% (14,291/14,859), feature_class 96.4%, moku 5,650,
ahupuaa 14,162. Island distribution: Hawaiʻi 4,703 · Oʻahu 2,992 · Kauaʻi 1,987 ·
Maui 1,799 · Molokaʻi 934 · Lānaʻi 261 · Niʻihau 77 · others (Kaula, Lehua,
Nihoa, Mokumanamana, Kure, …) a handful · none 568.

---

## Provenance of the txt file

`hawaiian-place-names.txt` was produced on 2026-08-24 by scraping
**Nā Inoa ʻĀina Hawaiʻi** ("Hawaiian Place Names", Lloyd J. Soehren) at
<https://www.ulukau.org/hpn/>, using a Tampermonkey userscript in the user's own
browser (see OpenCode session `ses_fcd4350c1ffe0sMuqFv0bHxaok`). The script is
preserved at `gnis/hpn_scrape.user.js`. The site caps
each `titleinitial` page at 500 rows and lazy-loads the rest on scroll; the
userscript scrolled every letter and saved all names. 14,988 lines after manual
`sort/uniq` deduplication.

### The userscript (`hpn_scrape.user.js`)

Runs in the user's own (Cloudflare-accepted) browser via Tampermonkey — the
route used before the server-side CloakBrowser approach below. Install:
Tampermonkey → new script → paste the file → save, then open any
`/hpn/?a=cl&cl=CL1…` page. It walks letters `a…w,y,1`, scrolls each list until
the lazy-loaded rows stop growing (stable for ~2s), appends the `.ti a` link
texts to `localStorage` (`__hpn_i` = letter index, `__hpn_names` = array —
reset both for a clean run), navigates itself to the next letter, and finally
downloads `hawaiian-place-names.txt`. Notes from the Aug 24 run:

- `@grant GM_info` forces the script into Tampermonkey's isolated world — the
  page CSP (`script-src 'self'`) blocks main-world injection, which is why
  earlier `@grant none` versions silently did nothing.
- Paste it **verbatim**; do not wrap it in Tampermonkey's
  `(function(){'use strict'; … })()` template.
- The scraped list is names-only (no island/moku/ahupuaa). For location data
  use the server-side crawl (`crawl_hpn.py`) instead; the userscript is kept
  for provenance and as a working fallback if the AJAX endpoint ever changes.

## Technique 1 — crawling Ulukau HPN through Cloudflare

`www.ulukau.org` sits behind a Cloudflare **managed challenge** (Turnstile):

- Plain `curl`/`curl_cffi` TLS impersonation gets 403 (server IP is held at
  challenge level; a cookie alone does **not** transfer — `cf_clearance` is
  IP-bound, learned the hard way on Aug 24 when the user's cookie was injected
  into a server-side chromium and still rejected).
- What works: a stealth Chromium (CloakBrowser 0.4.10, source-patched
  fingerprints) driven over CDP by `agent-browser` 0.31.1. The managed
  challenge still presents a **"Verify you are human" checkbox** which must be
  clicked once; after that the clearance cookie lives in that browser profile
  and all subsequent in-page `fetch()` calls carry it.

### The HPN list endpoint (discovered from the user's saved page snapshot at
`/var/www/html/tmp/Hawaiian Place Names.html`, whose `veridian-publicationlevel.js`
reveals the lazy-load XHR):

```
GET https://www.ulukau.org/hpn/?a=cla&command=getPublicationDocumentsListSegment
    &publicationcode=A&startindex=<0|500|1000|...>&segmentsize=500
    &titleinitial=<letter>[&l=en]&f=AJAX
```

- Response: XML with a `<DocumentList>` element whose text is the next HTML
  `<li class="publicationdocumentcell">…</li>` segment.
- Each row: `.ti a` = title + doc link (`?a=d&d=A-…`), `.ah` = ahupuaʻa,
  `.fc` = feature type, `.is` = Mokupuni (`island: district`).
- `titleinitial` values: `a`–`w`, `y`, `1` (no x/z); empty pages return 0 rows.
- Whole collection = **21,373 rows / 64 segments** (letter k alone is 8,989).
- The `l=en` interface returns byte-identical title sets (verified by crawling
  both) — one crawl is sufficient; the `--lang en` option exists as verification.

### Files (all in this directory)

| File | Role |
|---|---|
| `browser_start.sh` | create `.cloak-venv` if needed (uv or python3.13 venv), install `cloakbrowser==0.4.10`, ensure the stealth Chromium binary, install `agent-browser@0.31.1` if missing, launch `cloak_keepalive.py`, wait for CDP `:9242` |
| `cloak_keepalive.py` | launches CloakBrowser headless with CDP and sleeps forever |
| `browser_stop.sh` | kill the browser |
| `crawl_hpn.py` | the segment crawler (in-page `fetch` via `agent-browser eval`, 3 retries, challenge detector). `--lang haw\|en`, `--only <letter>` for smoke tests |
| `hpn_scrape.user.js` | Tampermonkey userscript used on Aug 24 in the user's own browser to produce the txt (names only; see provenance section) |
| `output/hpn_rows.jsonl` | the successful 2026-09-06 crawl (haw) — the island/moku/ahupuaa source of record |
| `output/hpn_rows_en.jsonl` | en-side verification crawl (same 21,373 rows) |

### Manual step that cannot be scripted away

After `./browser_start.sh`, open any `/hpn/` URL in the browser and click the
Turnstile checkbox once:

```bash
./browser_start.sh
curl -s -X PUT "http://127.0.0.1:9242/json/new?https://www.ulukau.org/hpn/" > /dev/null
sleep 8
agent-browser --cdp 9242 --user-agent "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36" open "https://www.ulukau.org/hpn/"
sleep 10
agent-browser --cdp 9242 snapshot -i        # find the checkbox ref (usually e9)
agent-browser --cdp 9242 click e9
sleep 12 && agent-browser --cdp 9242 eval 'document.title'
# -> "Nā Inoa ʻĀina Hawaiʻi" means cleared; crawl away
```

If `crawl_hpn.py` prints `challenge persists`, Cloudflare re-armed — repeat the
checkbox click.

## Technique 2 — GNIS sources (English feature_class + coordinate fallback)

`build_final.py` joins three snapshots; all live in `gnis/data/`
(`download_sources.sh --force` re-fetches them):

| File | Source | Rows |
|---|---|---|
| `data/DomesticNames_HI.txt` | current USGS Domestic Names for HI — `https://prd-tnm.s3.amazonaws.com/StagedProducts/GeographicNames/DomesticNames/DomesticNames_HI_Text.zip` (also on ScienceBase item `6437030bd34ee8d4addcc379`) | 7,334 |
| `data/hawaii_2017.txt` | 2017 National File, HI slice — figshare mirror `https://ndownloader.figshare.com/files/9167146` (84 MB gz), filtered `awk -F'|' '$4=="HI"'` | 10,798 |
| `data/page0.json` + `page1.json` | geodata.hawaii.gov ArcGIS layer `HistoricCultural/MapServer/2` ("Place Names"), paginated at 5,000 | 7,323 |

Coordinate→island mapping (used only when HPN doesn't know the name): bounding
boxes for the main 8 islands (checked smallest-first to avoid Kahoʻolawe/Maui
and Lānaʻi/Maui overlap), nearest-center < 0.75° for the NW chain
(Nihoa → Kure), then county fallback (Hawaii→Hawaiʻi, Honolulu→Oʻahu,
Kauai→Kauaʻi; Maui county is ambiguous → nothing).

Also consulted during source hunting (not build inputs): HBGN official
correction PDFs at `https://files.hawaii.gov/dbedt/op/gis/bgn/placenames/`
(per-island, include features later dropped from federal GNIS — the current
DomesticNames file has *fewer* rows than 2017 because BGN deaccessions).

## Assembly rules (`build_final.py`)

1. Every txt line → key `norm(line)`; **first occurrence wins** on duplicate
   keys (the txt contains okina-variant pairs like `Oneula`/`Oneʻula`);
   later duplicates only merge their island/moku/ahupuaa values in.
2. `feature_class` precedence: GNIS English class → HPN `fc` (most common
   variant) → `"ahupuaʻa"` (when the name matches an HPN ahupuaʻa value) →
   trailing-word suffix map (`Gulch`→`Valley`, `Pt.`→`Cape`, `Pali`→`Cliff`,
   `fishpond`→`Reservoir`, …) → omit.
3. `island` precedence: HPN mokupuni → island of HPN entries citing the name
   as an ahupuaʻa → GNIS coordinates → omit. `Lanaʻi` is normalized to
   `Lānaʻi`; islands sort in canonical main-8-then-NW order when joined.
4. `moku`/`ahupuaa` come from the same HPN row; when a name spans locales the
   lists are comma-joined and lose 1:1 correspondence (schema limitation).

## Re-running the pipeline

```bash
cd /var/www/html/noiiolelo/gnis

# one-shot (browser + crawl + assemble + consistent mirror):
./run_all.sh                # add --with-en to redo the en verification crawl
                            # add --force-download to re-fetch GNIS sources

# or step by step:
./browser_start.sh                                   # CDP :9242 (see manual Turnstile step above)
python3 crawl_hpn.py --lang haw                      # ~3 min, 21,373 rows
python3 crawl_hpn.py --only q                        # 3-row smoke test
python3 build_final.py                               # writes ../name_lists/hawaiian_place_names.json
php ../make_consistent.php                           # regenerates consistent/ (all lists)

./browser_stop.sh
```

Verify the output:

```bash
python3 - <<'EOF'
import json
d = json.load(open('../name_lists/hawaiian_place_names.json', encoding='utf-8'))
n = len(d)
isl = sum(1 for v in d.values() if v.get('island'))
fc  = sum(1 for v in d.values() if v.get('feature_class'))
print(f'{n} entries; island {isl} ({isl/n:.1%}); feature_class {fc} ({fc/n:.1%})')
EOF
```

Expected: `14859 entries; island 14291 (96.2%); feature_class 14321 (96.4%)`.

Note: `build_final.py` writes `../name_lists/hawaiian_place_names.json`
relative to the script location — run it from this directory, not from a copy
elsewhere. After an intentional txt change, re-run only `build_final.py` +
`php ../make_consistent.php` (no re-crawl needed unless HPN itself changed).

## Known limitations

- 568 entries have no island from any source (absent from the current HPN DB,
  GNIS 2017/2024, the ArcGIS layer and the HBGN PDFs; they appear to be
  variants introduced by the Aug 24 sort/uniq dedupe — e.g. `Aalapapa`,
  `Ahinahena`).
- Multi-locale names collapse into one keyed entry; joined field lists are not
  row-aligned across islands.
- HPN titles carry no diacritics in `.ti` (site data), so `name` stays ASCII —
  faithful to the txt.
- `agent-browser`/CloakBrowser version pins matter (`0.31.1` / `0.4.10`);
  Cloudflare may tighten and require re-solving the challenge manually or a
  newer CloakBrowser.
- `data/` is gitignored — these files are working data, not committed.
