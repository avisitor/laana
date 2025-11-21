const express = require('express');
const puppeteer = require('puppeteer');
const puppeteerExtra = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const minimist = require('minimist');
const path = require('path');
const fs = require('fs');

puppeteerExtra.use(StealthPlugin());
puppeteerExtra.puppeteer = puppeteer;

const router = express.Router();

// Logging utilities - control all output in one place
const log = {
  info: (msg) => {
    if (!global.quiet) console.error(msg);
  },
  warn: (msg) => {
    console.warn(msg);
  },
  error: (msg, err) => {
    if (err) console.error(msg, err);
    else console.error(msg);
  }
};

function print(text) {
  if (global.print) {
    console.error(text);
  }
}

// Core function: scraping logic
async function handleDocRequest(documentOID, returnJson, res, options = {}) {
  const documentURL = options.url;
  global.quiet = options.quiet || false;
  global.print = options.print || false;
  
  if (!documentOID && !documentURL) {
    return res.status(400).send('Missing oid or url parameter');
  }
  
  if (documentURL) {
    if (!documentURL.startsWith('https://puke.ulukau.org/ulukau-books/')) {
      return res.status(400).send('URL must start with https://puke.ulukau.org/ulukau-books/');
    }
    // Extract OID from URL if not provided
    if (!documentOID) {
      const match = documentURL.match(/[?&]d=([^&]+)/);
      if (!match) {
        return res.status(400).send('Could not extract OID from URL');
      }
      documentOID = match[1];
    }
  }

  // Setup output directory and files
  const outputDir = options.outputDir || path.join(__dirname, 'output');
  const outputPath = path.join(outputDir, `${documentOID}.txt`);
  const htmlOutputPath = path.join(outputDir, `${documentOID}.html`);
  
  fs.mkdirSync(outputDir, { recursive: true });

  let browser;
  try {
    browser = await puppeteerExtra.launch({
      headless: 'new',
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
      executablePath: '/usr/share/httpd/.cache/puppeteer/chrome/linux-144.0.7534.0/chrome-linux64/chrome'
    });

    const page = await browser.newPage();
    await page.setUserAgent(
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
      '(KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36'
    );
    await page.setExtraHTTPHeaders({
      'Accept-Language': 'en-US,en;q=0.9',
      'Referer': 'https://google.com',
    });

    const targetURL = documentURL || `https://puke.ulukau.org/ulukau-books/?a=d&d=${documentOID}&e=-------en-20--1--txt-txPT-----------`;
    await page.goto(targetURL, { waitUntil: 'networkidle2' });

    const headHTML = await page.evaluate(() => {
      const clone = document.head.cloneNode(true);
      clone.querySelectorAll('script').forEach(el => el.remove());
      return clone.innerHTML;
    });

    const pageIDs = await page.evaluate(() => {
      if (typeof pageImageSizes !== 'undefined') return Object.keys(pageImageSizes);
      if (typeof pageToArticleMap !== 'undefined') return Object.keys(pageToArticleMap);
      return null;
    });
    if (!pageIDs) {
      await browser.close();
      return res.status(500).send('Could not find page IDs');
    }

    await page.waitForFunction(() => typeof oSeaDObj !== 'undefined' && oSeaDObj !== null);

    let previousText = '';
    let outputText = '';
    let outputHTML = '<!DOCTYPE html>\n<html lang="en">\n<head>\n' + headHTML + '\n</head>\n<body>\n';

    // Initialize output files
    fs.writeFileSync(outputPath, '');
    fs.writeFileSync(htmlOutputPath, outputHTML);
    print(outputHTML);

    if (!returnJson) {
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.write(outputHTML);
    }

    for (let i = 0; i < pageIDs.length; i++) {
      const pageID = pageIDs[i];
      const pageNum = i + 1;
      log.info(`🔍 Processing page ${pageNum} (pageID: ${pageID})`);

      await page.evaluate(pid => selectSection(pid, pid), pageID);

      let contentLoaded = false;
      try {
        // Fast path for pages with content
        await page.waitForFunction(
          prev => {
            const el = document.querySelector("#ulukaupagetextview");
            return el && el.innerText.trim() !== '' && el.innerText.trim() !== '(No text)' && el.innerText !== prev;
          },
          { timeout: 2000 },
          previousText
        );
        contentLoaded = true;
      } catch (e) {
        // Fast path failed, try the loading indicator method
      }

      if (!contentLoaded) {
        try {
          await page.waitForFunction(
            () => document.querySelector("#ulukaupagetextview") && document.querySelector("#ulukaupagetextview").innerText.trim().includes('Loading'),
            { timeout: 1000 }
          );
          await page.waitForFunction(
            () => document.querySelector("#ulukaupagetextview") && !document.querySelector("#ulukaupagetextview").innerText.trim().includes('Loading'),
            { timeout: 10000 }
          );
        } catch (e) {
          // All waiting strategies failed.
        }
      }

      const rawText = await page.$eval('#ulukaupagetextview', el => el.innerText);
      previousText = rawText;

      const cleanText = rawText
        .replace(/<[^>]+>/g, '')
        .replace(/&nbsp;/g, ' ')
        .replace(/ {2,}/g, ' ')
        .split('\n')
        .filter(line => !/^[\s()\/'0-9;.,–—\-]+$/.test(line))
        .filter(line => !/^\s*[ivxlcdmIVXLCDM]+\s*$/.test(line))
        .filter(line => line.trim().length > 0)
        .join('\n');

      if (cleanText.length > 0 && cleanText.trim() !== '(No text)') {
        outputText += cleanText + '\n\n';

        const sectionHTML = await page.$eval('#ulukaupagetextview', el => {
          let html = el.outerHTML;
          html = html.replace('id="ulukaupagetextview"', 'class="ulukaupagetextview"');
          return html;
        });

        outputHTML += `<!-- Page ${pageNum} -->\n${sectionHTML}\n\n`;

        // Append to files
        print(cleanText + '\n\n');
        fs.appendFileSync(outputPath, cleanText + '\n\n');
        const htmlChunk = `<!-- Page ${pageNum} -->\n${sectionHTML}\n\n`;
        print(htmlChunk);
        fs.appendFileSync(htmlOutputPath, htmlChunk);
        log.info(`📝 Text appended for page ${pageNum}`);

        if (!returnJson) {
          res.write(htmlChunk);
        }
      } else {
        log.info(`⏭ Skipped empty or boilerplate page ${pageNum}`);
      }
    }

    outputHTML += '</body>\n</html>\n';
    
    // Finalize output files
    const closingHTML = '</body>\n</html>\n';
    print(closingHTML);
    fs.appendFileSync(htmlOutputPath, closingHTML);
    log.info(`✅ Saved to ${outputPath}`);
    
    await browser.close();

    if (returnJson) {
      res.json({ text: outputText, html: outputHTML });
    } else {
      res.end('</body></html>\n');
    }
  } catch (err) {
    if (browser) await browser.close();
    log.error('💥 Fatal error:', err);
    if (returnJson) {
      res.status(500).json({ error: err.message });
    } else {
      res.write(`<!-- Fatal error: ${err.message} -->`);
      res.end('</body></html>');
    }
  }
}

// Express route
router.get('/', async (req, res) => {
  // Use the directory of this file, not the cwd
  const outputDir = path.join(path.dirname(__filename), 'output');
  await handleDocRequest(req.query.oid, req.query.json === 'true', res, { outputDir, url: req.query.url });
});

module.exports = router;

// CLI mode
if (require.main === module) {
  const args = minimist(process.argv.slice(2), {
    string: ['oid', 'output', 'url'],
    boolean: ['recreate', 'verbose', 'quiet', 'print', 'json'],
    alias: { o: 'oid', d: 'output', r: 'recreate', v: 'verbose', u: 'url' },
  });

  const documentOID = args.oid;
  const returnJson = args.json;
  const outputDir = args.output ? path.resolve(args.output) : path.join(__dirname, 'output');
  global.quiet = args.quiet;
  global.print = args.print;

  // Fake res object for CLI
  const res = {
    json: (obj) => console.log(JSON.stringify(obj, null, 2)),
    send: (msg) => console.log(msg),
    status: (code) => ({ send: (msg) => console.error(code, msg) }),
    setHeader: () => {},
    write: (chunk) => process.stdout.write(chunk),
    end: (chunk) => { if (chunk) process.stdout.write(chunk); }
  };

  handleDocRequest(documentOID, returnJson, res, { outputDir, url: args.url, quiet: args.quiet, print: args.print }).catch(err => {
    log.error('💥 Fatal error:', err);
    process.exit(1);
  });
}
