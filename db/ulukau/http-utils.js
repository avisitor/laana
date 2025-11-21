/**
 * HTTP utilities for making reliable requests with retry logic
 */

const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

/**
 * Fetch a URL with exponential backoff retry logic
 * @param {Function} fetch - The fetch function to use
 * @param {string} url - The URL to fetch
 * @param {number} maxRetries - Maximum number of retry attempts (default: 3)
 * @param {number} initialDelay - Initial delay in ms before first retry (default: 1000)
 * @returns {Promise<Response>} The fetch response
 * @throws {Error} If all retry attempts fail
 */
async function fetchWithRetry(fetch, url, maxRetries = 3, initialDelay = 1000) {
  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 30000);
      const response = await fetch(url, {
        signal: controller.signal,
        headers: {
          'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 ' +
                        '(KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
          'Connection': 'keep-alive',
        }
      });
      clearTimeout(timeout);
      if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      return response;
    } catch (err) {
      if (attempt === maxRetries - 1) throw err;
      await delay(initialDelay * Math.pow(2, attempt));
    }
  }
}

module.exports = {
  fetchWithRetry,
  delay
};
