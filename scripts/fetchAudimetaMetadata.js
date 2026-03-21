import { setRateMessage } from "./uiFeedback.js";

/**
 * Fetches metadata from Audible via PHP proxy for a specific book or series.
 * Routes requests to our server-side scrapers that parse Audible pages directly.
 *
 * @param {Object} params - Input parameters for the request.
 * @param {string} params.type - Either "book" or "series" (defaults to "book").
 * @param {string} params.asin - Audible ASIN identifier (must be provided).
 * @param {string} params.region - Audible region code, e.g., "uk", "us", "de" (defaults to "uk").
 *
 * @returns {Promise<Object>} - An object containing:
 *   - audiMetaResponse: Parsed metadata from Audible.
 *   - responseHeaders: Metadata including rate limits and cache status.
 *
 * @throws {Error} If required fields are missing or the fetch request fails.
 */
export async function fetchAudimetaMetadata(params) {
  // Destructure input with defaults
  const { type = "book", asin = "", region = "uk", bookAsin = "" } = params;

  // Validate required fields
  if (!type || !asin || !region) throw new Error("Missing required fields: type, asin, or region.");

  // Clean up input values
  const trimmedASIN = asin.trim();
  const regionCode = region.trim().toLowerCase();
  const trimmedBookAsin = bookAsin.trim();

  // Route to the appropriate PHP endpoint
  const endpoint =
    type === "book" ? "php/audibleBookFetcher.php" : "php/audibleSeriesFetcher.php";

  // Build request body — include bookAsin hint for series lookups
  const requestBody = { asin: trimmedASIN, region: regionCode };
  if (type === "series" && trimmedBookAsin) {
    requestBody.bookAsin = trimmedBookAsin;
  }

  // Perform the fetch request with retry logic for rate limits
  return await fetchWithRateLimitRetry(endpoint, requestBody, type);
}

/**
 * Performs a POST request to our PHP proxy with automatic retry on 429 rate limit errors.
 * Shows a countdown message to the user while waiting.
 *
 * @param {string} endpoint - The PHP endpoint URL
 * @param {string} asin - The ASIN to look up
 * @param {string} region - The Audible region code
 * @param {string} type - "book" or "series"
 * @param {number} maxRetries - Maximum number of retries (default: 3)
 * @returns {Promise<Object>} - The response data and headers
 */
async function fetchWithRateLimitRetry(endpoint, requestBody, type, maxRetries = 3) {
  let attempts = 0;

  while (attempts < maxRetries) {
    const response = await fetch(endpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(requestBody),
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
      throw new Error(`Audible request failed (${response.status}): ${errorText}`);
    }

    // Success - parse the response
    const data = await response.json();

    // Check for application-level errors from our PHP endpoints
    if (data.status === "error") {
      throw new Error(data.message || "Audible fetch failed");
    }

    // Extract the response in the format the rest of the app expects
    let audiMetaResponse;
    let responseHeaders;

    if (type === "book") {
      // Book endpoint returns { audiMetaResponse: bookObj, responseHeaders: {...} }
      audiMetaResponse = data.audiMetaResponse;
      responseHeaders = data.responseHeaders || {};
    } else {
      // Series endpoint returns { audiMetaResponse: [...books], responseHeaders: {...}, ... }
      audiMetaResponse = data.audiMetaResponse || data.books || [];
      responseHeaders = data.responseHeaders || {};
    }

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
