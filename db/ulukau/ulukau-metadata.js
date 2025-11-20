const cheerio = require('cheerio');
const minimist = require('minimist');
const fs = require('fs');
const path = require('path');

// Utility function to add delay between requests
const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

// Logging utilities - control all output in one place
const log = {
  info: (msg) => {
    if (!global.quietMode) console.error(msg);
  },
  warn: (msg) => {
    if (!global.quietMode) console.warn(msg);
  },
  error: (msg, err) => {
    if (!global.quietMode) {
      if (err) console.error(msg, err);
      else console.error(msg);
    }
  }
};

// Retry fetch with exponential backoff
async function fetchWithRetry(fetch, url, maxRetries = 3, initialDelay = 1000) {
  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 30000); // 30 second timeout
      
      const response = await fetch(url, {
        signal: controller.signal,
        headers: {
          'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
          'Connection': 'keep-alive',
        }
      });
      
      clearTimeout(timeout);
      
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      
      return response;
    } catch (err) {
      const isLastAttempt = attempt === maxRetries - 1;
      
      if (isLastAttempt) {
        throw err;
      }
      
      const backoffDelay = initialDelay * Math.pow(2, attempt);
      log.info(`⏳ Retry ${attempt + 1}/${maxRetries} for ${url} after ${backoffDelay}ms (${err.message})`);
      await delay(backoffDelay);
    }
  }
}

(async () => {
  const fetch = (await import('node-fetch')).default;
  const args = minimist(process.argv.slice(2), {
    boolean: ['quiet'],
    string: ['limit', 'oid'],
    alias: { q: 'quiet', l: 'limit' },
  });

  const baseURL = 'https://puke.ulukau.org/ulukau-books/';
  const quiet = args.quiet;
  const limit = args.limit ? parseInt(args.limit) : null;
  const targetOid = args.oid || null;
  global.quietMode = quiet;

  try {
    const res = await fetchWithRetry(fetch, baseURL);
    const html = await res.text();
    const $ = cheerio.load(html);

    const rows = $('.ulukaubooks-book-browser-row');
    log.info(`🔍 Scanning ${rows.length} book entries${targetOid ? ` (filtering for: ${targetOid})` : limit ? ` (limit: ${limit})` : ''}`);
    const metadataArray = [];
    
    // Start JSON array output for streaming mode
    if (quiet) {
      console.log('[');
    }
    let firstEntry = true;

    for (let i = 0; i < rows.length; i++) {
      const el = rows[i];
      const lang = $(el).attr('data-language')?.trim().toLowerCase();
      if (lang !== 'haw') continue;
      
      const title = $(el).attr('data-title')?.trim() || '';
      const author = $(el).attr('data-author')?.trim() || '';
      const oidMatch = $(el).find('a[href*="d="]').attr('href')?.match(/d=([A-Z0-9\-]+)/);
      const oid = oidMatch?.[1];
      
      // Skip entries with no OID or invalid OIDs (e.g., just "EBOOK-")
      if (!oid || oid === 'EBOOK-' || oid.length <= 7) continue;
      
      // If filtering by OID, skip non-matching entries
      if (targetOid && oid !== targetOid) continue;
      
      // Stop if we've reached the limit (only when not filtering by OID)
      if (!targetOid && limit && metadataArray.length >= limit) {
        log.info(`✋ Reached limit of ${limit} entries`);
        break;
      }

      const full_url = `https://puke.ulukau.org/ulukau-books/?a=d&d=${oid}&e=-------en-20--1--txt-txPT-----------`;
      const full_image = `https://puke.ulukau.org/ulukau-books/cgi-bin/imageserver.pl?oid=${oid}&getcover=true`;

      let date = '';
      try {
        // Add small delay between requests to avoid overwhelming the server
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
      } catch (err) {
        log.warn(`⚠️ Could not extract date for ${oid}: ${err.message}`);
      }

      const entry = {
        sourcename: 'Ulukau: ' + oid,
        oid,
        url: full_url,
        image: full_image,
        title,
        groupname: 'ulukau',
        author,
        date,
      };
      
      metadataArray.push(entry);
      
      // Stream output in quiet mode
      if (quiet) {
        if (!firstEntry) {
          console.log(',');
        }
        console.log(JSON.stringify(entry, null, 2));
        firstEntry = false;
      }
      
      // If filtering by OID and found it, we're done
      if (targetOid && oid === targetOid) {
        log.info(`✅ Found OID: ${targetOid}`);
        break;
      }
    }

    // Close JSON array in streaming mode
    if (quiet) {
      console.log(']');
    }

    // Output full array in non-quiet mode
    if (!quiet) {
      console.log(JSON.stringify(metadataArray, null, 2));
    }

    log.info(`✅ Found ${metadataArray.length} Hawaiian language books`);

    // Save to file if this was an unrestricted fetch (no --oid or --limit)
    if (!targetOid && !limit) {
      const outputPath = path.join(__dirname, 'output', 'ulukau.json');
      try {
        fs.writeFileSync(outputPath, JSON.stringify(metadataArray, null, 2));
        log.info(`💾 Saved full metadata to ${outputPath}`);
      } catch (err) {
        log.warn(`⚠️ Could not save metadata to file: ${err.message}`);
      }
    }
  } catch (err) {
    log.error('💥 Metadata fetch failed:', err);
    process.exit(1);
  }
})().then(() => {
  process.exit(0);
}).catch(err => {
  log.error('💥 Unhandled error:', err);
  process.exit(1);
});
