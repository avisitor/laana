#!/usr/bin/env python3
"""Assemble data/name_lists/hawaiian_place_names.json.

Inputs (paths relative to this script's directory):
  ../data/name_lists/hawaiian-place-names.txt  the requested list (one ASCII name per line)
  output/hpn_rows.jsonl                        Ulukau HPN crawl (see crawl_hpn.py)
  data/DomesticNames_HI.txt                    current GNIS HI domestic names
  data/hawaii_2017.txt                         2017 GNIS National File, HI rows
  data/page0.json, data/page1.json             geodata.hawaii.gov ArcGIS Place Names dump

Output:
  ../data/name_lists/hawaiian_place_names.json

Schema (per gnis_hawaii_places.json, plus location fields where known):
    { "<normalized-key>": {"name": ..., "feature_class": ..., "island": ...,
                           "moku": ..., "ahupuaa": ...}, ... }
- key           = CorpusScanner::normalizeWord semantics (strip okina/apostrophes,
                  macrons -> plain vowels, lowercase, trim; spaces kept)
- name          = txt line (ASCII, matches the HPN title)
- feature_class = GNIS English class when matched, else HPN fc verbatim,
                  else "ahupuaʻa" when the name matched an HPN ahupuaʻa value,
                  else trailing-word heuristic, else omitted
- island        = HPN mokupuni (island part), else the island of HPN entries
                  citing it as an ahupuaʻa, else coordinate-derived from GNIS,
                  else omitted; multiple -> comma-joined (canonical order)
- moku          = HPN mokupuni district part (comma-joined), omitted if unknown
- ahupuaa       = HPN ahupuaʻa values (comma-joined; the name itself when
                  matched via the ahupuaʻa fallback), omitted if unknown
"""
import json
import math
import os
import unicodedata
from collections import Counter, OrderedDict

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
TXT = os.path.normpath(os.path.join(SCRIPT_DIR, '..', 'data', 'name_lists', 'hawaiian-place-names.txt'))
OUT = os.path.normpath(os.path.join(SCRIPT_DIR, '..', 'data', 'name_lists', 'hawaiian_place_names.json'))
HPN = os.path.join(SCRIPT_DIR, 'output', 'hpn_rows.jsonl')
GNIS_FILES = [os.path.join(SCRIPT_DIR, 'data', 'DomesticNames_HI.txt'),
              os.path.join(SCRIPT_DIR, 'data', 'hawaii_2017.txt')]
SVC_PAGES = [os.path.join(SCRIPT_DIR, 'data', 'page0.json'),
             os.path.join(SCRIPT_DIR, 'data', 'page1.json')]

ISLAND_DISPLAY = {'Lanaʻi': 'Lānaʻi'}
ISLAND_ORDER = ['Hawaiʻi', 'Maui', 'Oʻahu', 'Kauaʻi', 'Molokaʻi', 'Lānaʻi',
                'Niʻihau', 'Kahoʻolawe']


def norm(w: str) -> str:
    for ch in "'\u02bb\u2019\u2018`":
        w = w.replace(ch, '')
    w = unicodedata.normalize('NFKD', w)
    w = ''.join(c for c in w if not unicodedata.combining(c))
    return w.lower().strip()


def fnum(s):
    try:
        return float(s)
    except (TypeError, ValueError):
        return None


def load_gnis():
    rows = []
    for path in GNIS_FILES:
        for line in open(path, encoding='utf-8-sig'):
            p = line.rstrip('\n').split('|')
            if len(p) < 12 or p[0] == 'feature_id':
                continue
            rows.append({'name': p[1], 'class': p[2].strip(), 'county': p[5],
                         'lat': fnum(p[15]) if len(p) > 15 else None,
                         'lon': fnum(p[16]) if len(p) > 16 else None})
    for path in SVC_PAGES:
        for f in json.load(open(path, encoding='utf-8'))['features']:
            a = f['attributes']
            rows.append({'name': a['feature_name'], 'class': a['feature_class'].strip(),
                         'county': a['county_name'], 'lat': a.get('prim_lat_dec'),
                         'lon': a.get('prim_long_dec')})
    return rows


BOXES = [
    ('Kahoʻolawe', 20.50, 20.67, -156.72, -156.55),
    ('Lānaʻi', 20.72, 21.00, -157.05, -156.72),
    ('Niʻihau', 21.65, 22.12, -160.45, -160.05),
    ('Molokaʻi', 21.00, 21.28, -157.35, -156.62),
    ('Kauaʻi', 21.82, 22.28, -159.85, -159.30),
    ('Oʻahu', 21.15, 21.75, -158.35, -157.60),
    ('Maui', 20.52, 21.03, -156.78, -155.85),
    ('Hawaiʻi', 18.52, 20.35, -156.15, -154.45),
]
NW_CENTERS = [
    ('Nihoa', 23.0606, -161.9153), ('Mokumanamana', 23.5794, -164.7069),
    ('French Frigate Shoals', 23.75, -166.24), ('Gardner Pinnacles', 25.0172, -168.0136),
    ('Maro Reef', 25.4167, -170.5833), ('Laysan', 25.7667, -171.7333),
    ('Lisianski', 26.0333, -173.9667), ('Pearl and Hermes', 27.85, -176.0),
    ('Midway', 28.2333, -177.35), ('Kure Atoll', 28.4167, -178.3333),
]
COUNTY_FALLBACK = {'Hawaii': 'Hawaiʻi', 'Honolulu': 'Oʻahu', 'Kauai': 'Kauaʻi'}


def coord_island(lat, lon, county):
    if lat is None or lon is None:
        return COUNTY_FALLBACK.get(county)
    for name, n, s, w, e in BOXES:
        if n <= lat <= s and w <= lon <= e:
            return name
    for name, clat, clon in NW_CENTERS:
        if math.hypot(lat - clat, (lon - clon) * 0.9) < 0.75:
            return name
    return COUNTY_FALLBACK.get(county)


SUFFIX_CLASS = {
    'gulch': 'Valley', 'valley': 'Valley', 'stream': 'Stream', 'river': 'Stream',
    'point': 'Cape', 'pt.': 'Cape', 'pts.': 'Cape', 'bay': 'Bay', 'harbor': 'Bay',
    'falls': 'Falls', 'ridge': 'Ridge', 'reservoir': 'Reservoir', 'fishpond': 'Reservoir',
    'pond': 'Lake', 'lake': 'Lake', 'ditch': 'Canal', 'canal': 'Canal', 'channel': 'Channel',
    'spring': 'Spring', 'springs': 'Spring', 'village': 'Populated Place',
    'camp': 'Populated Place', 'landing': 'Populated Place', 'ranch': 'Populated Place',
    'mill': 'Populated Place', 'estate': 'Populated Place', 'crater': 'Crater',
    'cone': 'Summit', 'pali': 'Cliff', 'cliff': 'Cliff', 'island': 'Island',
    'rock': 'Island', 'rocks': 'Island', 'beach': 'Beach', 'hill': 'Summit',
    'head': 'Summit', 'peak': 'Summit', 'summit': 'Summit', 'flat': 'Flat',
    'flats': 'Flat', 'swamp': 'Swamp', 'marsh': 'Swamp', 'homesteads': 'Civil',
    'hmstds': 'Civil', 'homestead': 'Civil', 'park': 'Park', 'cemetery': 'Cemetery',
    'church': 'Church', 'trail': 'Trail', 'trl': 'Trail', 'reserve': 'Reserve',
    'tunnel': 'Tunnel', 'shaft': 'Mine', 'mine': 'Mine', 'grove': 'Woods',
    'woods': 'Woods', 'house': 'Building', 'building': 'Building', 'road': 'Road',
    'station': 'Locale', 'monument': 'Locale', 'school': 'School', 'dam': 'Dam',
    'airport': 'Airport', 'field': 'Airport', 'light': 'Tower', 'lighthouse': 'Tower',
    'well': 'Well', 'wells': 'Well', 'basin': 'Basin', 'gap': 'Gap', 'bar': 'Bar',
    'range': 'Range', 'plain': 'Plain', 'slopes': 'Slope', 'slope': 'Slope',
    'area': 'Area', 'arch': 'Arch',
}


def heuristic_class(name):
    toks = name.split()
    return SUFFIX_CLASS.get(toks[-1].lower()) if toks else None


def split_mokupuni(isv):
    parts = isv.strip().split(':')
    island = parts[0].strip() if parts[0].strip() else ''
    moku = parts[1].strip() if len(parts) > 1 else ''
    island = ISLAND_DISPLAY.get(island, island)
    return island, moku


def main():
    hpn_islands = {}
    hpn_mokus = {}
    hpn_ah = {}
    hpn_fc_values = {}
    hpn_rows = 0
    ah_islands = {}
    ah_mokus = {}
    for line in open(HPN, encoding='utf-8'):
        d = json.loads(line)
        for r in d['rows']:
            hpn_rows += 1
            k = norm(r['title'])
            island, moku = split_mokupuni(r['is'])
            if k:
                if island:
                    hpn_islands.setdefault(k, set()).add(island)
                if moku:
                    hpn_mokus.setdefault(k, set()).add(moku)
                ah = r['ah'].strip()
                if ah:
                    hpn_ah.setdefault(k, set()).add(ah)
                fc = r['fc'].strip()
                if fc:
                    hpn_fc_values.setdefault(k, Counter())[fc] += 1
            if k := norm(r['ah']):
                if island:
                    ah_islands.setdefault(k, set()).add(island)
                if moku:
                    ah_mokus.setdefault(k, set()).add(moku)

    gnis_class = {}
    gnis_island = {}
    for r in load_gnis():
        k = norm(r['name'])
        if not k:
            continue
        if r['class']:
            gnis_class.setdefault(k, Counter())[r['class']] += 1
        ci = coord_island(r['lat'], r['lon'], r['county'])
        if ci:
            gnis_island.setdefault(k, set()).add(ci)

    txt_lines = [l.rstrip('\n') for l in open(TXT, encoding='utf-8') if l.strip()]

    out = OrderedDict()
    stats = Counter()
    ISLAND_RANK = {n: i for i, n in enumerate(ISLAND_ORDER)}

    def fmt_islands(uniq):
        if not uniq:
            return None
        return ', '.join(sorted(uniq, key=lambda i: (ISLAND_RANK.get(i, 50), i)))

    for line in txt_lines:
        key = norm(line)
        if key in out:
            stats['dup-key'] += 1
            prev = out[key]
            for field, src_map in (('island', hpn_islands), ('moku', hpn_mokus),
                                   ('ahupuaa', hpn_ah)):
                extra = src_map.get(key) or set()
                if not extra:
                    continue
                merged = set((prev.get(field) or '').split(', ')) | extra
                merged.discard('')
                if merged:
                    prev[field] = ', '.join(sorted(merged))
            continue
        entry = {'name': line}
        via_ah = False
        if key in gnis_class:
            entry['feature_class'] = gnis_class[key].most_common(1)[0][0]
            stats['class: GNIS'] += 1
        elif key in hpn_fc_values:
            entry['feature_class'] = hpn_fc_values[key].most_common(1)[0][0]
            stats['class: HPN'] += 1
        elif key in ah_islands:
            entry['feature_class'] = 'ahupua\u02bba'
            via_ah = True
            stats['class: ahupuaa'] += 1
        else:
            hc = heuristic_class(line)
            if hc:
                entry['feature_class'] = hc
                stats['class: suffix'] += 1
            else:
                stats['class: none'] += 1
        islands = hpn_islands.get(key) or set()
        mokus = hpn_mokus.get(key) or set()
        ahs = hpn_ah.get(key) or set()
        if via_ah:
            ahs = {line}
        src = 'HPN'
        if not islands:
            islands = ah_islands.get(key) or set()
            mokus = ah_mokus.get(key) or set()
            src = 'HPN-ahupuaa'
        if not islands:
            islands = gnis_island.get(key) or set()
            src = 'coords'
        isl = fmt_islands(islands)
        if isl:
            entry['island'] = isl
            stats[f'island: {src}'] += 1
        else:
            stats['island: none'] += 1
        mok = ', '.join(sorted(mokus)) if mokus else None
        if mok:
            entry['moku'] = mok
            stats['moku: set'] += 1
        ahs_join = ', '.join(sorted(ahs)) if ahs else None
        if ahs_join:
            entry['ahupuaa'] = ahs_join
            stats['ahupuaa: set'] += 1
        if key in hpn_islands or key in hpn_fc_values:
            stats['matched HPN'] += 1
        elif via_ah:
            stats['matched via ahupuaa'] += 1
        elif key in gnis_class:
            stats['matched GNIS only'] += 1
        else:
            stats['matched neither'] += 1
        out[key] = entry

    with open(OUT, 'w', encoding='utf-8') as fh:
        json.dump(out, fh, ensure_ascii=False, indent=4)
        fh.write('\n')

    print(f'HPN rows: {hpn_rows}')
    print(f'wrote {OUT}: {len(out)} entries from {len(txt_lines)} txt lines')
    for k, v in stats.most_common():
        print(f'  {v:6d}  {k}')


if __name__ == '__main__':
    main()
