const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const minimist = require('minimist');

// Logging utilities
const log = {
  info: (msg) => console.error(msg),
  error: (msg, err) => {
    if (err) console.error(msg, err);
    else console.error(msg);
  }
};

(async () => {
  const args = minimist(process.argv.slice(2), {
    boolean: ['recreate'],
    string: ['output', 'limit'],
    alias: { r: 'recreate', o: 'output', l: 'limit' },
  });

  const outputDir = args.output ? path.resolve(args.output) : './output';
  const recreate = args.recreate;
  const limit = args.limit ? parseInt(args.limit) : null;

  fs.mkdirSync(outputDir, { recursive: true });

  try {
    // Fetch metadata from the metadata script
    log.info('📚 Fetching book metadata from Ulukau...');
    const metadataCmd = `node ulukau-metadata.js${limit ? ` --limit ${limit}` : ''}`;
    const metadataJson = execSync(metadataCmd, { encoding: 'utf8' });
    const metadataArray = JSON.parse(metadataJson);
    
    log.info(`✅ Found ${metadataArray.length} Hawaiian language books`);

    // Save metadata to file
    const jsonPath = path.join(outputDir, 'ulukau-books.json');
    fs.writeFileSync(jsonPath, JSON.stringify(metadataArray, null, 2));
    log.info(`📁 Saved metadata to ${jsonPath}`);

    // Process each book with ulukau.js
    const oids = metadataArray.map(item => item.oid).sort();
    for (const oid of oids) {
      log.info(`🚀 Processing ${oid}`);

      const txtPath = path.join(outputDir, `${oid}.txt`);
      const htmlPath = path.join(outputDir, `${oid}.html`);

      if (recreate) {
        if (fs.existsSync(txtPath)) fs.unlinkSync(txtPath);
        if (fs.existsSync(htmlPath)) fs.unlinkSync(htmlPath);
      }

      try {
        execSync(
          `node ulukau.js --oid ${oid} --output "${outputDir}"${recreate ? ' --recreate' : ''}`,
          { stdio: 'inherit' }
        );
      } catch (err) {
        log.error(`❌ Failed for ${oid}: ${err.message}`);
      }
    }
  } catch (err) {
    log.error('💥 Batch processing failed:', err);
    process.exit(1);
  }
})().then(() => {
  process.exit(0);
}).catch(err => {
  log.error('💥 Unhandled error:', err);
  process.exit(1);
});
