// ==UserScript==
// @name         Ulukau Hawaiian Place Names scraper
// @match        https://www.ulukau.org/hpn/*
// @include      https://www.ulukau.org/hpn/*
// @grant        GM_info
// @run-at       document-idle
// ==/UserScript==
(function () {
  const LETTERS = 'abcdefghijklmnopqrstuvwy1'.split(''); // x and z absent from site nav
  const STATE = 'e=-------en-20--1--txt-txTI%7CtxIN%7CtxIS%7CtxAH%7CtxFC-------';
  const ORIGIN = 'https://www.ulukau.org';
  const urlFor = l => `${ORIGIN}/hpn/?a=cl&cl=CL1&l=en&titleinitial=${l}&${STATE}`;
  const curLetter = () => new URL(location.href).searchParams.get('titleinitial');
  const fromDOM = () => Array.from(document.querySelectorAll('.ti a'))
    .map(a => a.textContent.trim()).filter(t => t && t !== 'Place name');

  let bannerEl = null;
  const banner = t => {
    if (!bannerEl) {
      bannerEl = document.createElement('div');
      bannerEl.style.cssText = 'position:fixed;top:8px;left:8px;z-index:2147483647;background:#111;'
        + 'color:#0f0;font:12px monospace;padding:6px 9px;border:1px solid #0f0;border-radius:6px;max-width:60vw;';
      (document.body || document.documentElement).appendChild(bannerEl);
    }
    bannerEl.textContent = 'HPN scraper: ' + t;
  };

  const waitForList = () => new Promise(res => {
    let n = 0;
    const iv = setInterval(() => {
      if (document.querySelectorAll('.ti a').length || n++ > 80) { clearInterval(iv); res(); }
    }, 400);
  });

  const scrollAll = () => new Promise(res => {
    let prev = -1, stable = 0, k = 0;
    const iv = setInterval(() => {
      window.scrollTo(0, document.body.scrollHeight);
      document.querySelectorAll('button,a').forEach(el => {
        if (/load more|show more/i.test(el.textContent) && el.offsetParent !== null) el.click();
      });
      const c = document.querySelectorAll('.ti a').length;
      if (c === prev) stable++; else stable = 0;
      prev = c;
      if (stable >= 5 || k++ > 200) { clearInterval(iv); res(); }
    }, 400);
  });

  (async () => {
    let i = parseInt(localStorage.getItem('__hpn_i') || '0', 10);
    if (!(i >= 0)) i = 0;
    let names = JSON.parse(localStorage.getItem('__hpn_names') || '[]');

    const L = LETTERS[i];
    banner(`jump -> ${L}`);
    if (curLetter() !== L) { location.href = urlFor(L); return; }

    await waitForList();
    await scrollAll();
    const got = fromDOM();
    names.push(...got);
    localStorage.setItem('__hpn_names', JSON.stringify(names));
    console.log(`letter ${L}: +${got.length}  (total ${names.length})`);
    banner(`letter ${L}: +${got.length}  (total ${names.length})`);

    i++;
    localStorage.setItem('__hpn_i', String(i));
    if (i < LETTERS.length) {
      location.href = urlFor(LETTERS[i]);
    } else {
      const blob = new Blob([names.join('\n') + '\n'], { type: 'text/plain' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'hawaiian-place-names.txt';
      a.click();
      console.log(`DONE — ${names.length} names written to hawaiian-place-names.txt`);
      banner(`DONE — ${names.length} names`);
    }
  })();
})();
