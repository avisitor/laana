const express = require('express');
const app = express();

const doc = require('./ulukau-doc.js');
app.use('/doc', doc);

const metadata = require('./ulukau-metadata.js');
app.use('/metadata', metadata);

const PORT = 3000;

app.listen(PORT, () => {
  console.log(`Streaming Puppeteer service running on http://localhost:${PORT}`);
});
