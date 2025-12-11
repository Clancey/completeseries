import { setRateMessage } from "./uiFeedback.js";

/**
 * Fetches metadata from audimeta.de for a specific Audible book or series.
 * Includes response headers such as rate limits and cache status.
 * Automatically waits and retries on 429 rate limit errors.
 *
 * @param {Object} params - Input parameters for the request.
 * @param {string} params.type - Either "book" or "series" (defaults to "book").
 * @param {string} params.asin - Audible ASIN identifier (must be provided).
 * @param {string} params.region - Audible region code, e.g., "uk", "us", "de" (defaults to "uk").
 *
 * @returns {Promise<Object>} - An object containing:
 *   - audiMetaResponse: Parsed JSON data from audimeta.de.
 *   - responseHeaders: Metadata from the response headers including rate limits and cache status.
 *
 * @throws {Error} If required fields are missing or the fetch request fails.
 */
export async function fetchAudimetaMetadata(params) {
  // Destructure input with defaults
  const { type = "book", asin = "", region = "uk" } = params;

  // Validate required fields
  if (!type || !asin || !region) throw new Error("Missing required fields: type, asin, or region.");

  // Clean up input values
  const trimmedASIN = asin.trim();
  const regionCode = region.trim().toLowerCase();

  // Build the API URL based on type
  const apiUrl =
    type === "book"
      ? `https://audimeta.de/book/${trimmedASIN}?cache=true&region=${regionCode}`
      : `https://audimeta.de/series/${trimmedASIN}/books?region=${regionCode}&cache=true`;

  // Perform the fetch request with retry logic for rate limits
  return await fetchWithRateLimitRetry(apiUrl);
}

/**
 * Performs a fetch request with automatic retry on 429 rate limit errors.
 * Shows a countdown message to the user while waiting.
 *
 * @param {string} url - The URL to fetch
 * @param {number} maxRetries - Maximum number of retries (default: 3)
 * @returns {Promise<Object>} - The response data and headers
 */
async function fetchWithRateLimitRetry(url, maxRetries = 3) {
  let attempts = 0;

  while (attempts < maxRetries) {
    const response = await fetch(url, {
      method: "GET",
      headers: {
        Accept: "application/json",
      },
    });

    // Handle rate limit (429) with wait and retry
    if (response.status === 429) {
      attempts++;
      const errorBody = await response.text();
      let retryAfter = 60; // Default to 60 seconds

      // Try to parse retryAfter from response body
      try {
        const errorJson = JSON.parse(errorBody);
        if (errorJson.errors?.[0]?.retryAfter) {
          retryAfter = parseInt(errorJson.errors[0].retryAfter, 10);
        }
      } catch {
        // Use default if parsing fails
      }

      // Also check Retry-After header
      const retryHeader = response.headers.get("Retry-After");
      if (retryHeader) {
        retryAfter = parseInt(retryHeader, 10) || retryAfter;
      }

      if (attempts >= maxRetries) {
        throw new Error(`Rate limit exceeded after ${maxRetries} retries`);
      }

      // Show countdown and wait
      await waitWithCountdown(retryAfter);
      continue;
    }

    // Handle other non-OK responses
    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(`audimeta.de request failed (${response.status}): ${errorText}`);
    }

    // Success - extract and return both data and headers
    const audiMetaResponse = await response.json();
    const responseHeaders = {
      requestLimit: response.headers.get("x-ratelimit-limit"),
      requestRemaining: response.headers.get("x-ratelimit-remaining"),
      cached: response.headers.get("x-cached"),
    };

    return { audiMetaResponse, responseHeaders };
  }

  throw new Error("Max retries exceeded");
}

/**
 * Waits for the specified duration while showing a countdown message.
 *
 * @param {number} seconds - Number of seconds to wait
 */
async function waitWithCountdown(seconds) {
  for (let remaining = seconds; remaining > 0; remaining--) {
    const minutes = Math.floor(remaining / 60);
    const secs = remaining % 60;
    const timeStr = minutes > 0 ? `${minutes}m ${secs}s` : `${secs}s`;
    setRateMessage(`Rate limit reached. Waiting ${timeStr} before resuming...`);
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  setRateMessage("");
}
