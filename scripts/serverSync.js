// serverSync.js - Server-side data synchronization
//
// This module handles communication with the server-side storage when configured.
// If the server is not configured (no ABS_URL env var), all functions gracefully
// return defaults and the app works in client-only mode.

/**
 * Cached server configuration status
 * @type {{checked: boolean, configured: boolean, serverUrl: string|null, authMethod: string|null}}
 */
let serverConfigCache = {
  checked: false,
  configured: false,
  serverUrl: null,
  authMethod: null,
  lastRefresh: null,
  refreshStatus: "idle",
  audibleRegion: "us",
  searchUrl: "",
};

/**
 * Check if server-side storage is available and configured.
 * Results are cached after first check.
 *
 * @param {boolean} [forceRefresh=false] - Force re-check instead of using cache
 * @returns {Promise<{configured: boolean, serverUrl: string|null, authMethod: string|null, lastRefresh: string|null, refreshStatus: string}>}
 */
export async function checkServerStatus(forceRefresh = false) {
  if (serverConfigCache.checked && !forceRefresh) {
    return serverConfigCache;
  }

  try {
    const response = await fetch("php/getConfig.php");
    if (!response.ok) {
      serverConfigCache = { checked: true, configured: false, serverUrl: null, authMethod: null, lastRefresh: null, refreshStatus: "idle", audibleRegion: "us", searchUrl: "" };
      return serverConfigCache;
    }

    const data = await response.json();
    serverConfigCache = {
      checked: true,
      configured: data.configured ?? false,
      serverUrl: data.serverUrl ?? null,
      authMethod: data.authMethod ?? null,
      lastRefresh: data.lastRefresh ?? null,
      refreshStatus: data.refreshStatus ?? "idle",
      audibleRegion: data.audibleRegion ?? "us",
      searchUrl: data.searchUrl ?? "",
    };
    return serverConfigCache;
  } catch (error) {
    console.warn("Failed to check server config:", error.message);
    serverConfigCache = { checked: true, configured: false, serverUrl: null, authMethod: null, lastRefresh: null, refreshStatus: "idle", audibleRegion: "us", searchUrl: "" };
    return serverConfigCache;
  }
}

/**
 * Returns whether the server is configured (uses cached status).
 * Call checkServerStatus() first to populate cache.
 *
 * @returns {boolean}
 */
export function isServerConfigured() {
  return serverConfigCache.configured;
}

/**
 * Returns the configured Audible region (uses cached status).
 * Call checkServerStatus() first to populate cache.
 *
 * @returns {string}
 */
export function getServerAudibleRegion() {
  return serverConfigCache.audibleRegion;
}

/**
 * Returns the configured search URL template (uses cached status).
 * Call checkServerStatus() first to populate cache.
 *
 * @returns {string} The search URL template, or empty string if not configured.
 */
export function getSearchUrl() {
  return serverConfigCache.searchUrl;
}

/**
 * Fetch data from server-side storage.
 * Returns null if server is not configured.
 *
 * @returns {Promise<{hiddenItems: Array, existingFirstBookASINs: Array, existingBookMetadata: Array}|null>}
 */
export async function fetchServerData() {
  try {
    const response = await fetch("php/getData.php");
    if (!response.ok) {
      console.warn("Server getData failed:", response.status);
      return null;
    }

    const result = await response.json();

    // If server is not configured, return null (use local storage)
    if (result.status === "not_configured") {
      return null;
    }

    if (result.status === "error") {
      console.warn("Server getData error:", result.message);
      return null;
    }

    return result.data ?? null;
  } catch (error) {
    console.warn("Failed to fetch server data:", error.message);
    return null;
  }
}

/**
 * Save data to server-side storage.
 * Silently fails if server is not configured.
 *
 * @param {Object} data - Data to save (hiddenItems, existingFirstBookASINs, existingBookMetadata)
 * @returns {Promise<{success: boolean, lastUpdated?: string}>}
 */
export async function saveServerData(data) {
  try {
    const response = await fetch("php/saveData.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      console.warn("Server saveData failed:", response.status);
      return { success: false };
    }

    const result = await response.json();

    // If server is not configured, just return silently
    if (result.status === "not_configured") {
      return { success: false };
    }

    if (result.status === "error") {
      console.warn("Server saveData error:", result.message);
      return { success: false };
    }

    return { success: true, lastUpdated: result.lastUpdated };
  } catch (error) {
    console.warn("Failed to save server data:", error.message);
    return { success: false };
  }
}

/**
 * Check if server has cached library data available.
 * Returns the data if available, null otherwise.
 *
 * @returns {Promise<{hasData: boolean, seriesFirstASIN?: Array, seriesAllASIN?: Array, lastRefresh?: string}>}
 */
export async function getServerLibraryData() {
  const status = await checkServerStatus();
  if (!status.configured) {
    return { hasData: false };
  }

  try {
    const serverData = await fetchServerData();
    if (!serverData) {
      return { hasData: false };
    }

    const hasData =
      Array.isArray(serverData.existingFirstBookASINs) &&
      serverData.existingFirstBookASINs.length > 0;

    if (hasData) {
      return {
        hasData: true,
        seriesFirstASIN: serverData.existingFirstBookASINs,
        seriesAllASIN: serverData.seriesAllASIN || [],
        lastRefresh: serverData.serverConfig?.lastRefresh || serverData.lastUpdated,
      };
    }

    return { hasData: false };
  } catch (error) {
    console.warn("Failed to get server library data:", error.message);
    return { hasData: false };
  }
}

/**
 * Trigger server-side data refresh using stored credentials.
 * Only works when server is configured.
 *
 * @returns {Promise<{success: boolean, seriesCount?: number, bookCount?: number, lastRefresh?: string, error?: string}>}
 */
export async function triggerServerRefresh() {
  try {
    const response = await fetch("php/refresh.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
    });

    const result = await response.json();

    if (!response.ok || result.status === "error") {
      return {
        success: false,
        error: result.message || "Refresh failed",
      };
    }

    // Update cached status
    serverConfigCache.lastRefresh = result.lastRefresh;
    serverConfigCache.refreshStatus = "complete";

    return {
      success: true,
      seriesCount: result.seriesCount,
      bookCount: result.bookCount,
      lastRefresh: result.lastRefresh,
    };
  } catch (error) {
    return {
      success: false,
      error: error.message || "Network error during refresh",
    };
  }
}

/**
 * Sync hidden items between client and server.
 * Server is treated as source of truth when configured.
 * If not configured, returns the local items unchanged.
 *
 * @param {Array} localHiddenItems - Local hidden items array
 * @returns {Promise<Array>} - Merged hidden items array (or local if not configured)
 */
export async function syncHiddenItems(localHiddenItems) {
  const status = await checkServerStatus();

  if (!status.configured) {
    // Server not configured, use local storage only
    return localHiddenItems;
  }

  try {
    const serverData = await fetchServerData();
    if (!serverData) {
      return localHiddenItems;
    }

    const serverHiddenItems = serverData.hiddenItems || [];

    // Merge: combine unique items from both sources
    const merged = mergeHiddenItems(localHiddenItems, serverHiddenItems);

    // If there are differences, save merged result to server
    if (merged.length !== serverHiddenItems.length || hasNewItems(merged, serverHiddenItems)) {
      await saveServerData({ hiddenItems: merged });
    }

    return merged;
  } catch (error) {
    console.warn("Server sync failed, using local data:", error.message);
    return localHiddenItems;
  }
}

/**
 * Save hidden items to server (fire-and-forget, non-blocking).
 * Only attempts if server is configured.
 *
 * @param {Array} hiddenItems - Hidden items to save
 */
export function saveHiddenItemsToServer(hiddenItems) {
  if (!serverConfigCache.configured) {
    return; // Server not configured, skip
  }

  // Fire and forget - don't block UI
  saveServerData({ hiddenItems }).catch((err) => {
    console.warn("Failed to sync hidden items to server:", err.message);
  });
}

/**
 * Merge two hidden items arrays, removing duplicates by ASIN.
 *
 * @param {Array} local - Local hidden items
 * @param {Array} server - Server hidden items
 * @returns {Array} - Merged array with duplicates removed
 */
function mergeHiddenItems(local, server) {
  const seen = new Set();
  const merged = [];

  // Server items first (source of truth)
  for (const item of [...server, ...local]) {
    const key = item.asin || `${item.type}-${item.series}-${item.title}`;
    if (!seen.has(key)) {
      seen.add(key);
      merged.push(item);
    }
  }

  return merged;
}

/**
 * Check if merged array has items not in server array.
 *
 * @param {Array} merged - Merged items
 * @param {Array} server - Server items
 * @returns {boolean}
 */
function hasNewItems(merged, server) {
  const serverKeys = new Set(server.map((item) => item.asin || `${item.type}-${item.series}-${item.title}`));
  return merged.some((item) => {
    const key = item.asin || `${item.type}-${item.series}-${item.title}`;
    return !serverKeys.has(key);
  });
}
