// metadataFlow.js
import { fetchAudibleMetadata, findFromStorage } from "./dataFetcher.js";
import { setMessage, setRateMessage } from "./uiFeedback.js";
import { storeMetadataToLocalStorage, removeFromStorage } from "./localStorage.js";

// Rate limit configuration
const rateLimitResetTime = 60000; // Time in milliseconds before rate limit resets
const seriesMetadataCacheTtlMs = 7 * 24 * 60 * 60 * 1000;
let processStartTime; // Tracks when the batch process started

/**
 * Fetches metadata for a list of Audible book ASINs.
 * Also tracks and manages rate limiting per response headers.
 *
 * @param {Array<Object>} existingSeries - List of books with { asin, series, title }
 * @param {string} audibleRegion - Audible region code (e.g., 'uk', 'us')
 * @param {boolean} includeSubSeries - Whether to include subseries or not
 * @param {boolean} [cacheOnly=false] - When true, only use cached data, skip API calls
 * @returns {Promise<Array<string>>} List of unique series ASINs
 */
export async function collectBookMetadata(existingSeries, audibleRegion, includeSubSeries, cacheOnly = false) {
  const seriesAsins = [];
  const seriesToBookMap = {}; // Maps seriesAsin -> bookAsin for the sims endpoint
  const totalSeries = existingSeries.length;
  let processedCount = 0;

  processStartTime = Date.now();

  for (const book of existingSeries) {
    const bookASIN = book.asin;

    // Skip empty or invalid ASINs
    if (!bookASIN || bookASIN === "Unknown ASIN") {
      processedCount++;
      continue;
    }

    try {
      let metadata = findFromStorage("asin", bookASIN, "existingFirstBookASINs");

      setMessage(`Fetching series unique ID: ${processedCount + 1} / ${totalSeries}`);

      if (!metadata) {
        // In cache-only mode, skip API calls - just continue without this book's metadata
        if (cacheOnly) {
          processedCount++;
          continue;
        }

        // If metadata is not found in local storage, fetch it from Audible
        const { audiMetaResponse, responseHeaders = {} } =
          (await fetchAudibleMetadata(bookASIN, audibleRegion, "book")) ?? {};

        if (!audiMetaResponse || typeof audiMetaResponse !== "object") {
          const err = new Error("Audible metadata missing or malformed.");
          err.details = { bookASIN, audibleRegion };
          throw err;
        }

        metadata = audiMetaResponse;

        const remainingRequestsEstimate = calculateRemainingRequests(
          totalSeries,
          processedCount,
          "book"
        );

        await checkForRateLimitDelay(responseHeaders, remainingRequestsEstimate);

        if (!metadata?.series) continue;

        storeMetadataToLocalStorage(metadata, "existingFirstBookASINs");
      }
      // If metadata is not available, skip this book
      if (!metadata || !metadata.series) continue;

      for (const bookSeries of metadata.series) {
        if (!seriesAsins.includes(bookSeries.asin)) {
          seriesAsins.push(bookSeries.asin);
          seriesToBookMap[bookSeries.asin] = bookASIN;
        }

        if (!includeSubSeries) break;
      }
    } catch (error) {
      console.warn(`Error fetching metadata for ASIN ${bookASIN}:`, error);
    }

    processedCount++;
  }

  return { seriesAsins, seriesToBookMap };
}

/**
 * Fetches metadata for each series using its ASIN.
 * Cleans out hidden books and respects rate limits.
 *
 * @param {Array<string>} seriesAsins - Audible series ASINs to fetch
 * @param {string} audibleRegion - Audible region code (e.g., 'uk')
 * @param {Object} existingContent - User's existing library content
 * @param {boolean} [cacheOnly=false] - When true, only use cached data, skip API calls
 * @returns {Promise<Array<Object>>} Array of { seriesAsin, response } entries
 */
export async function collectSeriesMetadata(seriesAsins, audibleRegion, existingContent, cacheOnly = false, seriesToBookMap = {}) {
  const seriesMetadataResults = [];
  const totalSeries = seriesAsins.length;
  let processedCount = 0;

  processStartTime = Date.now();

  for (const seriesAsin of seriesAsins) {
    try {
      let seriesMetadata = findFromStorage("seriesAsin", seriesAsin, "existingBookMetadata");

      // Invalidate cached entries that may hide newly added or newly released books.
      // Skip invalidation in cacheOnly mode — stale data is better than no data
      // when we can't re-fetch from the API.
      if (!cacheOnly && seriesMetadata && shouldRefreshCachedSeriesMetadata(seriesMetadata)) {
        removeFromStorage("seriesAsin", seriesAsin, "existingBookMetadata");
        seriesMetadata = null;
      }

      setMessage(`Fetching series metadata: ${processedCount + 1} / ${totalSeries}`);

      if (!seriesMetadata) {
        // In cache-only mode, skip API calls - just continue without this series' metadata
        if (cacheOnly) {
          processedCount++;
          continue;
        }

        // Fetch series metadata from Audible via PHP proxy
        // Pass the known book ASIN as a hint so the sims endpoint can be used directly
        const bookAsinHint = seriesToBookMap[seriesAsin] || "";
        const { audiMetaResponse, responseHeaders = {} } =
          (await fetchAudibleMetadata(seriesAsin, audibleRegion, "series", bookAsinHint)) ?? {};

        if (!audiMetaResponse || typeof audiMetaResponse !== "object") {
          const err = new Error("Audible metadata missing or malformed.");
          err.details = { seriesAsin, audibleRegion };
          throw err;
        }

        const remainingRequestsEstimate = calculateRemainingRequests(
          totalSeries,
          processedCount,
          "series"
        );

        await checkForRateLimitDelay(responseHeaders, remainingRequestsEstimate);

        if (!Array.isArray(audiMetaResponse)) continue;

        if (!existingContent) continue;

        // Safety net: fix books with empty series array.
        // Since we fetched from the series endpoint, these books belong to this series.
        const seriesName = audiMetaResponse.find((book) => book.series?.length > 0)?.series?.[0]?.name || "";

        const fixedResponse = audiMetaResponse.map((book) => {
          if (!book.series || book.series.length === 0) {
            return {
              ...book,
              series: [{ asin: seriesAsin, name: seriesName, position: extractBookNumber(book.title) }],
            };
          }
          return book;
        });

        seriesMetadata = {
          seriesAsin,
          response: fixedResponse,
          fetchedAt: new Date().toISOString(),
        };

        storeMetadataToLocalStorage(seriesMetadata, "existingBookMetadata");
      }

      seriesMetadataResults.push(seriesMetadata);
    } catch (error) {
      console.warn(`Error fetching series metadata for ASIN ${seriesAsin}:`, error);
    }

    processedCount++;
  }

  return seriesMetadataResults;
}

/**
 * Estimates how many API requests are left based on current progress.
 * For "book", the total estimated count is doubled to account for subseries.
 *
 * @param {number} total - Total items in original list
 * @param {number} processed - Number of items already processed
 * @param {string} type - Either "book" or "series"
 * @returns {number} Remaining estimated API requests
 */
function calculateRemainingRequests(total, processed, type) {
  return type === "book"
    ? Math.max(0, total - processed + total) // Estimate subseries
    : Math.max(0, total - processed);
}

/**
 * Applies a dynamic wait if API rate limit has been reached and response is uncached.
 *
 * @param {Object} responseHeaders - The headers returned from the API response
 * @param {number} remainingRequestsEstimate - Approximate requests left in batch
 */
async function checkForRateLimitDelay(responseHeaders, remainingRequestsEstimate) {
  if (responseHeaders.cached) return;

  const elapsed = Date.now() - processStartTime;

  if (Number(responseHeaders.requestRemaining) === 0) {
    await calculateRateLimitDelay(elapsed, remainingRequestsEstimate, responseHeaders.requestLimit);
  }
}

/**
 * Calculates a wait period based on time left in window and expected future requests.
 * This helps avoid hitting limits before reset.
 *
 * @param {number} elapsed - Time in ms since process began
 * @param {number} remainingRequestsEstimate - Number of requests remaining
 * @param {number|string} rateLimit - Max requests allowed in window
 */
async function calculateRateLimitDelay(elapsed, remainingRequestsEstimate, rateLimit) {
  const millisecondsUntilReset = Math.max(rateLimitResetTime - elapsed, 0);
  const waitTimeInSeconds = Math.ceil(millisecondsUntilReset / 1000);
  const resetWindowSeconds = Math.ceil(rateLimitResetTime / 1000);

  const estimatedQuotaCycles = Math.floor(
    (remainingRequestsEstimate / rateLimit) * resetWindowSeconds
  );
  const estimatedOverhead = (remainingRequestsEstimate % rateLimit) * 0.5;

  const estimatedTimeLeft = waitTimeInSeconds + estimatedQuotaCycles + estimatedOverhead;
  const timeLeftMinutes = Math.ceil(estimatedTimeLeft / 60);
  const timeLeftSeconds = Math.ceil(estimatedTimeLeft % 60);

  const readableWait = `${timeLeftMinutes} minute(s) and ${timeLeftSeconds} second(s)`;
  const message = `Rate limit reached. Waiting ${waitTimeInSeconds}s before next request. Estimated time left: ${readableWait}`;

  setRateMessage(message);
  await delay(millisecondsUntilReset);
  setRateMessage(""); // Clear rate limit message after waiting
  processStartTime = Date.now(); // Reset the start time
}

/**
 * Creates a blocking delay.
 *
 * @param {number} delayInMilliseconds - Time to wait
 * @returns {Promise<void>} Resolved after delay
 */
function delay(delayInMilliseconds) {
  return new Promise((resolve) => setTimeout(resolve, delayInMilliseconds));
}

/**
 * Check if a cached series metadata entry contains an unavailable book whose
 * non-placeholder release date has passed. Future releases and Audible's 2200
 * placeholders should not make a freshly fetched cache stale immediately.
 *
 * @param {Object} seriesMetadata - Cached entry with { seriesAsin, response: book[] }
 * @returns {boolean} True if the cache should be refreshed.
 */
function hasReleasedUnavailableBooks(seriesMetadata) {
  const books = seriesMetadata?.response;
  if (!Array.isArray(books)) return false;

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  return books.some((book) => {
    if (book.isAvailable !== false || !book.releaseDate) return false;
    const release = new Date(book.releaseDate);
    if (isNaN(release.getTime()) || release.getFullYear() >= 2100) return false;
    release.setHours(0, 0, 0, 0);
    return release <= today;
  });
}

/**
 * Check if a cached series entry should be refreshed.
 * Legacy entries without `fetchedAt` are refreshed once so caches created before
 * a later Audible release do not permanently hide that new book.
 *
 * @param {Object} seriesMetadata - Cached entry with { seriesAsin, response: book[], fetchedAt?: string }
 * @returns {boolean} True when the cache should be refreshed from Audible.
 */
export function shouldRefreshCachedSeriesMetadata(seriesMetadata) {
  const fetchedAtMs = Date.parse(seriesMetadata?.fetchedAt ?? "");
  if (!Number.isFinite(fetchedAtMs)) return true;

  return Date.now() - fetchedAtMs > seriesMetadataCacheTtlMs ||
    hasReleasedUnavailableBooks(seriesMetadata);
}

function extractBookNumber(title) {
  if (!title) return "";
  const match = title.match(/(\d+)/);
  return match ? match[1] : "";
}
