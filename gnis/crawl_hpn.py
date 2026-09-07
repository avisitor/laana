#!/usr/bin/env python3
"""Crawl all HPN (Na Inoa Aina) document-list segments via the Veridian AJAX
endpoint through the Cloudflare-cleared CloakBrowser session on CDP :9242.

Prerequisites (see ../data/hawaiian_place_names.md):
  ./browser_start.sh   then click the Turnstile "Verify you are human" checkbox
  agent-browser on PATH (npm i -g agent-browser@0.31.1)

Endpoint: a=cla&command=getPublicationDocumentsListSegment&publicationcode=A
          &startindex=N&segmentsize=500&titleinitial=L[&l=en]&f=AJAX

Output: output/hpn_rows_{lang}.jsonl (one JSON per segment:
        {letter, startindex, nrows, rows:[{doc, title, ah, fc, is}]})
"""
import argparse
import json
import os
import subprocess
import sys
import time

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
LETTERS = list('abcdefghijklmnopqrstuvwyz') + ['x', 'z', '1']
SEG = 500


def build_js(url: str) -> str:
    return (
        'fetch("' + url + '",{credentials:"include"}).then(r=>r.text()).then(t=>{'
        'if(t.indexOf("Just a moment")>=0||t.indexOf("cf-chl")>=0){return JSON.stringify({challenge:true,len:t.length});}'
        'var doc=new DOMParser().parseFromString(t,"text/xml");'
        'var dl=doc.querySelector("DocumentList");'
        'var inner=dl?dl.textContent:"";'
        'var holder=document.createElement("div");holder.innerHTML=inner;'
        'var rows=[...holder.querySelectorAll("li.publicationdocumentcell")];'
        'var data=rows.map(function(li){'
        'var a=li.querySelector(".ti a");'
        'var ah=li.querySelector(".ah");'
        'var fc=li.querySelector(".fc");'
        'var is=li.querySelector(".is");'
        'return {doc: a?a.getAttribute("href"):"", title: a?a.textContent.trim():"",'
        'ah: ah?ah.textContent.trim():"", fc: fc?fc.textContent.trim():"", is: is?is.textContent.trim():""};'
        '});'
        'return JSON.stringify({nrows:data.length, rows:data, len:t.length});})'
    )


def run_eval(js: str, js_path: str, raw_path: str, cdp: str) -> str:
    with open(js_path, 'w', encoding='utf-8') as fh:
        fh.write(js)
    with open(raw_path, 'w', encoding='utf-8') as out:
        p = subprocess.run(['agent-browser', '--cdp', cdp, 'eval', js],
                           stdout=out, stderr=subprocess.PIPE, text=True, timeout=180)
    if p.returncode != 0:
        return ''
    return open(raw_path, encoding='utf-8').read().strip()


def parse_payload(raw: str):
    if not raw:
        return None
    try:
        d = json.loads(raw)
    except json.JSONDecodeError:
        return None
    if isinstance(d, str):
        try:
            d = json.loads(d)
        except json.JSONDecodeError:
            return None
    return d


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--lang', choices=['haw', 'en'], default='haw',
                    help="site interface language: haw (no l= param, the default "
                         "the site serves) or en (adds l=en)")
    ap.add_argument('--out', default=None,
                    help='output jsonl path (default: output/hpn_rows_{lang}.jsonl)')
    ap.add_argument('--cdp', default='9242', help='CloakBrowser CDP port')
    ap.add_argument('--only', default=None,
                    help='crawl a single titleinitial letter (for smoke tests)')
    args = ap.parse_args()

    lang_suffix = '' if args.lang == 'haw' else '&l=en'
    out_path = args.out or os.path.join(SCRIPT_DIR, 'output', f'hpn_rows_{args.lang}.jsonl')
    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    js_path = os.path.join(SCRIPT_DIR, 'output', f'seg_{args.lang}.js')
    raw_path = os.path.join(SCRIPT_DIR, 'output', f'seg_{args.lang}_raw.txt')

    letters = [args.only] if args.only else LETTERS
    total_rows = 0
    with open(out_path, 'w', encoding='utf-8') as out:
        for L in letters:
            si = 0
            letter_rows = 0
            while True:
                url = ('https://www.ulukau.org/hpn/?a=cla&command='
                       'getPublicationDocumentsListSegment&publicationcode=A'
                       f'&startindex={si}&segmentsize={SEG}&titleinitial={L}{lang_suffix}'
                       '&f=AJAX')
                d = None
                for attempt in range(3):
                    raw = run_eval(build_js(url), js_path, raw_path, args.cdp)
                    d = parse_payload(raw)
                    if d and not d.get('challenge'):
                        break
                    time.sleep(10)
                if not d:
                    print(f'FAIL {L}@{si} (no parseable response)', flush=True)
                    break
                if d.get('challenge'):
                    print(f'FAIL {L}@{si} (cloudflare challenge persists; '
                          'clear it in the browser and re-run)', flush=True)
                    break
                rows = d.get('rows') or []
                out.write(json.dumps({'letter': L, 'startindex': si,
                                      'nrows': len(rows), 'rows': rows},
                                     ensure_ascii=False) + '\n')
                out.flush()
                total_rows += len(rows)
                letter_rows += len(rows)
                print(f'{L}@{si}: {len(rows)} rows (letter total {letter_rows})', flush=True)
                if len(rows) < SEG:
                    break
                si += SEG
            sys.stdout.flush()
    print(f'DONE total rows: {total_rows}', flush=True)


if __name__ == '__main__':
    main()
