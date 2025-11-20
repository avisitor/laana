const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const fs = require('fs');
const path = require('path');
const minimist = require('minimist');

puppeteer.use(StealthPlugin());

// Parse CLI options
const args = minimist(process.argv.slice(2), {
  string: ['oid', 'output'],
  boolean: ['recreate', 'verbose'],
  alias: { o: 'oid', d: 'output', r: 'recreate', v: 'verbose' },
});

const documentOID = args.oid;
const outputDir = args.output ? path.resolve(args.output) : __dirname;

if (!documentOID) {
  console.error('❌ Usage: node ulukau.js --oid <OID> [--output <dir>] [--recreate] [--verbose]');
  process.exit(1);
}

const outputPath = path.join(outputDir, `${documentOID}.v2.txt`);
const htmlOutputPath = path.join(outputDir, `${documentOID}.v2.html`);

fs.mkdirSync(outputDir, { recursive: true });

if (args.recreate) {
  if (fs.existsSync(outputPath)) fs.unlinkSync(outputPath);
  if (fs.existsSync(htmlOutputPath)) fs.unlinkSync(htmlOutputPath);
  if (fs.existsSync(path.join(outputDir, 'debug.html'))) fs.unlinkSync(path.join(outputDir, 'debug.html'));
  console.log(`♻️ Recreated files by removing existing versions`);
}

fs.writeFileSync(outputPath, '');
fs.writeFileSync(htmlOutputPath, '');

(async () => {
  let browser;
  try {
    browser = await puppeteer.launch({
      headless: 'new',
      args: ['--no-sandbox', '--disable-setuid-sandbox'],
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

    await page.goto(
      `https://puke.ulukau.org/ulukau-books/?a=d&d=${documentOID}&e=-------en-20--1--txt-txPT-----------`,
      { waitUntil: 'networkidle2' }
    );

    const headHTML = await page.evaluate(() =>{
      const clone = document.head.cloneNode(true);
      clone.querySelectorAll('script').forEach(el => el.remove());
      return clone.innerHTML;
    });

    fs.appendFileSync(htmlOutputPath, '<!DOCTYPE html>\n<html lang="en">\n<head>\n' + headHTML + '\n</head>\n<body>\n');

    const pageIDs = await page.evaluate(() => {
      if (typeof pageImageSizes !== 'undefined') {
        return Object.keys(pageImageSizes);
      }
      if (typeof pageToArticleMap !== 'undefined') {
        return Object.keys(pageToArticleMap);
      }
      return null;
    });

    if (!pageIDs) {
      console.error('❌ Could not find page IDs from pageImageSizes or pageToArticleMap. Exiting.');
      if (browser) {
        await browser.close();
      }
      process.exit(1);
    }

    await page.waitForFunction(() => typeof oSeaDObj !== 'undefined' && oSeaDObj !== null);

    let previousText = '';
    for (let i = 0; i < pageIDs.length; i++) {
      const pageID = pageIDs[i];
      const pageNum = i + 1;
      console.log(`🔍 Processing page ${pageNum} (pageID: ${pageID})`);

      await page.evaluate((pageID) => {
        selectSection(pageID, pageID);
      }, pageID);

      let contentLoaded = false;
      try {
        // Fast path for pages with content
        await page.waitForFunction(
          (prevText) => {
            const el = document.querySelector("#ulukaupagetextview");
            return el && el.innerText.trim() !== '' && el.innerText.trim() !== '(No text)' && el.innerText !== prevText;
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

      const extractText = async () => await page.$eval('#ulukaupagetextview', el => el.innerText);

      const cleanText = text =>
        text.replace(/<[^>]+>/g, '')
          .replace(/&nbsp;/g, ' ')
          .replace(/ {2,}/g, ' ')
          .split('\n')
          .filter(line => !/^[\s()\/'0-9;.,–—\-]+$/.test(line))
          .filter(line => !/^\s*[ivxlcdmIVXLCDM]+\s*$/.test(line))
          .filter(line => line.trim().length > 0)
          .join('\n');

      const rawText = await extractText();
      const finalText = cleanText(rawText);
      previousText = rawText;

      if (finalText.length > 0 && finalText.trim() !== '(No text)') {
        fs.appendFileSync(outputPath, finalText + '\n\n');

        const sectionHTML = await page.$eval('#ulukaupagetextview', el => {
          let html = el.outerHTML;
          html = html.replace('id="ulukaupagetextview"', 'class="ulukaupagetextview"');
          return html;
        });

        fs.appendFileSync(htmlOutputPath, `<!-- Page ${pageNum} -->\n${sectionHTML}\n\n`);
        console.log(`📝 Text appended for page ${pageNum}`);
      } else {
        console.log(`⏭ Skipped empty or boilerplate page ${pageNum}`);
      }
    }

    fs.appendFileSync(htmlOutputPath, '</body>\n</html>\n');
    console.log(`✅ Saved to ${outputPath}`);
  } catch (err) {
    console.error('💥 Fatal error:', err);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();
