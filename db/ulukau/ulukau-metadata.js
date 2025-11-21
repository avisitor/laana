const express = require('express');
const cheerio = require('cheerio');
const fs = require('fs');
const path = require('path');
const minimist = require('minimist');
const { fetchWithRetry, delay } = require('./http-utils');

const router = express.Router();

// Core function: scrape metadata
async function fetchMetadata({ oid, limit }) {
  const fetch = (await import('node-fetch')).default;
  const baseURL = 'https://puke.ulukau.org/ulukau-books/';
  const response = await fetchWithRetry(fetch, baseURL);
  const html = await response.text();
  const $ = cheerio.load(html);

  const rows = $('.ulukaubooks-book-browser-row');
  const metadataArray = [];

  for (let i = 0; i < rows.length; i++) {
    const el = rows[i];
    const lang = $(el).attr('data-language')?.trim().toLowerCase();
    if (lang !== 'haw') continue;

    const title = $(el).attr('data-title')?.trim() || '';
    const author = $(el).attr('data-author')?.trim() || '';
    const oidMatch = $(el).find('a[href*="d="]').attr('href')?.match(/d=([A-Z0-9\-]+)/);
    const oidVal = oidMatch?.[1];

    if (!oidVal || oidVal === 'EBOOK-' || oidVal.length <= 7) continue;
    if (oid && oidVal !== oid) continue;
    if (!oid && limit && metadataArray.length >= limit) break;

    const full_url = `https://puke.ulukau.org/ulukau-books/?a=d&d=${oidVal}&e=-------en-20--1--txt-txPT-----------`;
    const full_image = `https://puke.ulukau.org/ulukau-books/cgi-bin/imageserver.pl?oid=${oidVal}&getcover=true`;

    let date = '';
    try {
      await delay(100);
      const bookPageRes = await fetchWithRetry(fetch, full_url);
      const bookHTML = await bookPageRes.text();
      const $$ = cheerio.load(bookHTML);

      const pubKeywords = /(Published|Publication Date|Date Published|Year)/i;
      let dateMatch;

      $$('td, div, span, label').each((i, el) => {
        const text = $$(el).text();
        if (pubKeywords.test(text)) {
          const match = text.match(/\b(19|20)\d{2}\b/);
          if (match) {
            dateMatch = match[0];
            return false;
          }
        }
      });

      if (!dateMatch) {
        $$('div').each((i, el) => {
          const label = $$(el).find('.label').text();
          const content = $$(el).find('.content').text();
          if (/Copyright/i.test(label) || /Copyright/i.test(content)) {
            const allYears = content.match(/\b(19|20)\d{2}\b/g);
            if (allYears && allYears.length > 0) {
              dateMatch = allYears.sort()[0];
              return false;
            }
          }
        });
      }

      date = dateMatch || '';
    } catch {
      // ignore date extraction errors
    }

    const entry = {
      sourcename: 'Ulukau: ' + oidVal,
      oid: oidVal,
      url: full_url,
      image: full_image,
      title,
      groupname: 'ulukau',
      author,
      date,
    };

    metadataArray.push(entry);

    if (oid && oidVal === oid) break;
  }

  return metadataArray;
}

// Express route
router.get('/', async (req, res) => {
  try {
    const data = await fetchMetadata({ oid: req.query.oid, limit: req.query.limit });
    res.json(data);

    // Optionally save to file if no filters
    if (!req.query.oid && !req.query.limit) {
      const outputPath = path.join(__dirname, 'output', 'ulukau.json');
      try {
        fs.writeFileSync(outputPath, JSON.stringify(data, null, 2));
      } catch (err) {
        console.warn(`⚠️ Could not save metadata to file: ${err.message}`);
      }
    }
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;

// CLI mode
if (require.main === module) {
  const args = minimist(process.argv.slice(2), {
    string: ['oid', 'limit'],
    alias: { o: 'oid', l: 'limit' },
  });

  fetchMetadata({ oid: args.oid, limit: args.limit ? parseInt(args.limit) : null })
    .then(data => {
      console.log(JSON.stringify(data, null, 2));
      if (!args.oid && !args.limit) {
        const outputPath = path.join(__dirname, 'output', 'ulukau.json');
        try {
          fs.writeFileSync(outputPath, JSON.stringify(data, null, 2));
          console.error(`💾 Saved full metadata to ${outputPath}`);
        } catch (err) {
          console.error(`⚠️ Could not save metadata to file: ${err.message}`);
        }
      }
    })
    .catch(err => {
      console.error('💥 Metadata fetch failed:', err);
      process.exit(1);
    });
}
