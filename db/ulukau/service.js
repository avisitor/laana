const express = require('express');
const puppeteer = require('puppeteer');
const puppeteerExtra = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

puppeteerExtra.use(StealthPlugin());
puppeteerExtra.puppeteer = puppeteer;

const app = express();
const PORT = 3000;

//app.get('/render', async (req, res) => {
app.get('/doc', async (req, res) => {
  const documentOID = req.query.oid;
  const returnJson = req.query.json === 'true';

  if (!documentOID) {
    return res.status(400).send('Missing oid parameter');
  }

  let browser;
  try {
    browser = await puppeteerExtra.launch({
      headless: 'new',   // or true/false depending on your needs
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage'
      ],
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

    await page.goto(
      `https://puke.ulukau.org/ulukau-books/?a=d&d=${documentOID}&e=-------en-20--1--txt-txPT-----------`,
      { waitUntil: 'networkidle2' }
    );

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
    let outputHTML = '<!DOCTYPE html>\n<html lang="en">\n<head></head>\n<body>\n';

    if (!returnJson) {
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.write(outputHTML);
    }

    for (let i = 0; i < pageIDs.length; i++) {
      const pageID = pageIDs[i];
      const pageNum = i + 1;

      await page.evaluate(pid => selectSection(pid, pid), pageID);

      try {
        await page.waitForFunction(
          prev => {
            const el = document.querySelector("#ulukaupagetextview");
            return el && el.innerText.trim() !== '' && el.innerText.trim() !== '(No text)' && el.innerText !== prev;
          },
          { timeout: 5000 },
          previousText
        );
      } catch (e) {
        await new Promise(resolve => setTimeout(resolve, 1000));
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

        if (!returnJson) {
          res.write(`<!-- Page ${pageNum} -->\n${sectionHTML}\n\n`);
        }
      }
    }

    outputHTML += '</body>\n</html>\n';

    await browser.close();

    if (returnJson) {
      res.json({ text: outputText, html: outputHTML });
    } else {
      res.end('</body></html>\n');
    }

  } catch (err) {
    if (browser) await browser.close();
    console.error('Fatal error:', err);
    if (returnJson) {
      res.status(500).json({ error: err.message });
    } else {
      res.write(`<!-- Fatal error: ${err.message} -->`);
      res.end('</body></html>');
    }
  }
});

app.listen(PORT, () => {
  console.log(`Streaming Puppeteer service running on http://localhost:${PORT}`);
});
