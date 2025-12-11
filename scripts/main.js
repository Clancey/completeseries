// main.js

// Imports
import { getFormData, validateLibraryForm, validateForm, clearErrors } from "./formHandler.js";
import {
  setMessage,
  clearMessage,
  clearRateMessage,
  showSpinner,
  hideSpinner,
  toggleElementVisibility,
  showLibraryFilterInSettings,
  showDebugButtons,
  enableExportButtons,
} from "./uiFeedback.js";
import { collectBookMetadata, collectSeriesMetadata } from "./metadataFlow.js";
import { fetchExistingContent, fetchAudiobookShelfLibraries } from "./dataFetcher.js";
import { findMissingBooks, groupBooksBySeries } from "./dataCleaner.js";
import { renderSeriesAndBookTiles } from "./seriesTileBuilder.js";
import { populateHiddenItemsMenu } from "./tileVisibilityUpdater.js";
import {
  initializeUIInteractions,
  disableClickEventsOnLoad,
  enableClickEventsOnLoadEnd,
  libraryCheckboxWatcher,
} from "./interactions.js";
import { emptyDivContent, addLabeledCheckbox } from "./elementFactory.js";

import { bindDebugViewerControls } from "./interactions.js";
import { initDebugModal } from "./debugView.js";
import { isDebugEnabled, getDebugLogs } from "./debug.js";
import {
  beginRun,
  endRun,
  computeStoragePresence,
  ensureWorkingMemoryReady,
} from "./localStorage.js";
import { checkServerStatus, triggerServerRefresh, isServerConfigured, getServerLibraryData, getServerAudibleRegion } from "./serverSync.js";
import { syncHiddenItemsWithServer } from "./visibility.js";

// Stores current data fetched from AudiobookShelf
export let existingContent;
export let groupedMissingBooks;
// eslint-disable-next-line prefer-const
export let selectedLibraries = {
  authToken: "",
  librariesList: [],
};
export let libraryArrayObject = {};

/**
 * Initializes core UI and form behavior after DOM is ready
 */
document.addEventListener("DOMContentLoaded", async () => {
  await ensureWorkingMemoryReady();

  // Check server configuration and sync hidden items if configured
  const serverStatus = await checkServerStatus();
  if (serverStatus.configured) {
    console.log("Server storage configured, syncing hidden items...");
    await syncHiddenItemsWithServer();
    initServerRefreshUI(serverStatus);

    // Check if server has cached data - if so, offer to use it
    const serverData = await getServerLibraryData();
    if (serverData.hasData) {
      console.log("Server has cached library data, showing use server data option...");
      showUseServerDataOption(serverData);
    } else {
      // Server configured but no data yet - show prompt to refresh first
      showServerNeedsRefreshOption();
    }
  }

  // Set up UI event listeners and populate hidden series menu
  initializeUIInteractions();
  populateHiddenItemsMenu();
  computeStoragePresence();
  bindDebugViewerControls();

  const loginForm = document.getElementById("loginForm");
  const libraryForm = document.getElementById("libraryForm");
  const libraryList = document.getElementById("availableLibraries");
  const settingsLibraries = document.getElementById("availableLibrariesSettings");

  if (!loginForm || !libraryForm || !libraryList) return;

  // --- Handle login form submission ---
  loginForm.addEventListener("submit", async function (loginFormEvent) {
    loginFormEvent.preventDefault();
    clearErrors();

    const formData = getFormData();
    if (!validateForm(formData)) return;

    resetUserInterfaceAndStartLoadingProcess();

    try {
      setMessage("Logging in…");

      // Fetch all libraries from AudiobookShelf
      libraryArrayObject = await fetchAudiobookShelfLibraries(formData);

      if (!libraryArrayObject?.librariesList?.length) {
        errorHandler({ message: "No libraries found. Please check your AudiobookShelf setup." });
        return;
      }

      // Store all libraries found
      selectedLibraries.librariesList = structuredClone(libraryArrayObject.librariesList);
      selectedLibraries.authToken = libraryArrayObject.authToken;

      if (libraryArrayObject.librariesList.length === 1) {
        // Auto-process single-library users
        fetchExistingLibraryData(formData, libraryArrayObject);
      } else {
        // Build UI checkboxes for each library
        populateLibraryCheckboxes(libraryArrayObject.librariesList, libraryList);
      }
    } catch (error) {
      errorHandler(error);
    }
  });

  // --- Handle library checkbox form submission ---
  libraryForm.addEventListener("submit", async function (libraryFormEvent) {
    libraryFormEvent.preventDefault();
    clearErrors();

    const formData = getFormData();
    if (!validateForm(formData)) return;

    if (!validateLibraryForm(selectedLibraries.librariesList)) return;

    toggleElementVisibility("library-form-container", false);
    resetUserInterfaceAndStartLoadingProcess();

    // Move library check boxes
    settingsLibraries.appendChild(libraryList);
    // Show library filter in settings
    showLibraryFilterInSettings();

    fetchExistingLibraryData(formData, selectedLibraries);
  });
});

/**
 * Coordinates the process of fetching the user's existing AudiobookShelf library data.
 * - Resets the UI and displays loading state
 * - Fetches all series and books for the selected libraries
 * - Validates content and displays results or an error message
 *
 * @param {Object} formData - The form submission data (URL, username, password, region, etc.)
 * @param {Object} selectedLibraries - Object containing the selected library IDs and auth token
 */
export async function fetchExistingLibraryData(formData, selectedLibraries) {
  resetUserInterfaceAndStartLoadingProcess();
  // "auto" = load from DB only if workingCache isn't already a fresh snapshot
  await beginRun({ fresh: "auto" });

  try {
    setMessage("Fetching libraries and series...");

    // Fetch user's existing library data
    existingContent = await collectExistingSeriesFromAudiobookShelf(formData, selectedLibraries);

    if (!existingContent || !existingContent.seriesFirstASIN) {
      errorHandler({ message: "No series found in the selected library." });
      return;
    }

    // Store for global use (e.g. refreshes)
    await fetchAndDisplayResults(existingContent, formData);
  } catch (error) {
    errorHandler(error);
  }
}

/**
 * Resets interface state before starting a new metadata fetch.
 * Hides form, shows spinner and disables interaction during processing.
 */
export function resetUserInterfaceAndStartLoadingProcess() {
  disableClickEventsOnLoad();

  // Clear the results area
  const seriesOutputDiv = document.getElementById("seriesOutput");
  emptyDivContent(seriesOutputDiv);

  // Hide form and show feedback
  showLoadingState();
}

/**
 * Handles the full flow of fetching, filtering and rendering data.
 * Can be triggered by login or "apply filter" from settings.
 *
 * @param {Object} existingContent - Previously fetched library content
 * @param {Object} formData - Form configuration from user
 * @param {boolean} [refreshFilter=false] - Whether triggered by UI filter refresh
 */
export async function fetchAndDisplayResults(existingContent, formData, refreshFilter = false) {
  if (refreshFilter) setMessage("Refreshing filter results...");

  // Fetch book + series metadata
  const seriesMetadata = await fetchAllMetadataForBooks(existingContent, formData);

  // Clean and group missing books by series
  groupedMissingBooks = await groupMissingBooks(existingContent, seriesMetadata, formData);

  // Render tiles and update UI
  uiUpdateAndDrawResults(groupedMissingBooks);
}

/**
 * Fetches all known series from user's AudiobookShelf and filters out hidden entries.
 *
 * @param {Object} formData - Auth and config input from form
 * @returns {Promise<Object>} - Filtered content from AudiobookShelf
 */
async function collectExistingSeriesFromAudiobookShelf(formData, libraryArrayObject) {
  existingContent = await fetchExistingContent(formData, libraryArrayObject);

  setMessage("Login successful. Fetching book and series information...");

  return existingContent;
}

/**
 * Dynamically generates checkboxes for each available library.
 *
 * @param {Array<Object>} librariesList - List of libraries from the server
 * @param {HTMLElement} parentContainer - The container where checkboxes will be appended
 */
function populateLibraryCheckboxes(librariesList, parentContainer) {
  clearMessage();
  emptyDivContent(parentContainer);
  toggleElementVisibility("library-form-container", true);

  librariesList.forEach((library) => {
    addLabeledCheckbox(
      {
        id: library.id,
        labelText: library.name,
        checked: true, // default to all selected
      },
      parentContainer
    );
  });

  libraryCheckboxWatcher(parentContainer); // Sync changes to selectedLibraries
}

/**
 * Fetches book metadata, then maps to series ASINs and fetches their metadata.
 *
 * @param {Object} existingContent - Original fetched content
 * @param {Object} formData - Form configuration
 * @returns {Promise<Array>} - Full metadata per series
 */
async function fetchAllMetadataForBooks(existingContent, formData) {
  // Extract all series ASINs by examining first-book metadata
  const seriesASINs = await collectBookMetadata(
    existingContent.seriesFirstASIN,
    formData.region,
    formData.includeSubSeries
  );

  // Use those ASINs to get full series details
  return await collectSeriesMetadata(seriesASINs, formData.region, existingContent);
}

/**
 * Determines which books are missing from the user's library.
 * Optionally groups them by series/subseries.
 *
 * @param {Object} existingContent - AudiobookShelf content
 * @param {Array} seriesMetadata - Metadata for each series
 * @param {Object} formData - User form settings
 * @returns {Promise<Object>} - Grouped missing books ready to render
 */
async function groupMissingBooks(existingContent, seriesMetadata, formData) {
  const missingBooks = findMissingBooks(existingContent.seriesAllASIN, seriesMetadata, formData);

  return await groupBooksBySeries(missingBooks, formData.includeSubSeries);
}

/**
 * Updates the DOM and user interface with the final book tiles.
 *
 * @param {Object} groupedMissingBooks - Data structured by series
 */
async function uiUpdateAndDrawResults(groupedMissingBooks) {
  await renderSeriesAndBookTiles(groupedMissingBooks);
  toggleElementVisibility("form-container", false);
  toggleElementVisibility("message", false);
  hideSpinner();
  enableClickEventsOnLoadEnd();
  enableExportButtons();
  // After run completes (and logs have been written), re-render:
  populateDebugViewerIfResultsExist();
  await endRun({
    persist: true, // flush dirty keys to DB
    clearHeavy: true, // clear only heavy arrays in memory; keeps "hiddenItems"
  });
}

/**
 * Updates the UI to reflect a loading state.
 * - Hides the form container
 * - Displays the loading spinner
 * - Ensures the message element is visible (used for progress updates)
 */
function showLoadingState() {
  toggleElementVisibility("form-container", false);
  showSpinner();
  toggleElementVisibility("message", true, "block");
}

/**
 * Run this script when an error occurs during data fetching or processing.
 * @param {*} error Error details from failed operations
 */
function errorHandler(error) {
  console.error(error);
  hideSpinner();
  toggleElementVisibility("form-container", true);
  toggleElementVisibility("library-form-container", false);
  setMessage(error.message || "Something went wrong. Please try again.");
  clearRateMessage();
  enableClickEventsOnLoadEnd();
  throw new Error(error.message || "An unexpected error occurred. Please try again.");
}

/**
 * Populate the Debug Viewer once logs exist.
 *
 * Early-exits if debugging is disabled or if there are no logs to display.
 * Side effects:
 *  - Initializes the debug modal UI.
 *  - Reveals the debug-related buttons/controls.
 *
 * @returns {void}
 */
function populateDebugViewerIfResultsExist() {
  // Abort if debug features are not enabled.
  if (!isDebugEnabled()) return;

  // Ensure we actually have logs before initializing the viewer.
  const debugLogs = getDebugLogs();
  if (!Array.isArray(debugLogs) || debugLogs.length === 0) return;

  // Initialize the modal and show related UI controls.
  initDebugModal();
  showDebugButtons();
}

/**
 * Initializes the server refresh UI when server is configured.
 * Shows the refresh button and status in the settings panel.
 *
 * @param {Object} serverStatus - Server configuration status from checkServerStatus()
 */
function initServerRefreshUI(serverStatus) {
  const container = document.getElementById("serverRefreshContainer");
  if (!container) return;

  // Show the container
  container.classList.remove("menuHideable");
  container.style.display = "block";

  // Update status text
  const statusEl = document.getElementById("serverStatus");
  if (statusEl) {
    statusEl.textContent = `Connected to: ${serverStatus.serverUrl || "Server"}`;
    statusEl.classList.add("connected");
  }

  // Update last refresh time
  updateLastRefreshDisplay(serverStatus.lastRefresh);

  // Wire up refresh button
  const refreshBtn = document.getElementById("triggerRefresh");
  if (refreshBtn) {
    refreshBtn.disabled = false;
    refreshBtn.addEventListener("click", handleServerRefresh);
  }
}

/**
 * Handles the server refresh button click.
 * Triggers a server-side data refresh and updates the UI.
 */
async function handleServerRefresh() {
  const refreshBtn = document.getElementById("triggerRefresh");
  const statusEl = document.getElementById("serverStatus");

  if (refreshBtn) {
    refreshBtn.disabled = true;
    refreshBtn.textContent = "Refreshing...";
  }

  if (statusEl) {
    statusEl.textContent = "Refreshing server data...";
  }

  try {
    const result = await triggerServerRefresh();

    if (result.success) {
      if (statusEl) {
        statusEl.textContent = `Refreshed: ${result.seriesCount} series, ${result.bookCount} books`;
        statusEl.classList.add("success");
      }
      updateLastRefreshDisplay(result.lastRefresh);
    } else {
      if (statusEl) {
        statusEl.textContent = `Error: ${result.error}`;
        statusEl.classList.add("error");
      }
    }
  } catch (error) {
    if (statusEl) {
      statusEl.textContent = `Error: ${error.message}`;
      statusEl.classList.add("error");
    }
  } finally {
    if (refreshBtn) {
      refreshBtn.disabled = false;
      refreshBtn.textContent = "Refresh Server Data";
    }
  }
}

/**
 * Updates the last refresh time display.
 *
 * @param {string|null} lastRefresh - ISO date string of last refresh
 */
function updateLastRefreshDisplay(lastRefresh) {
  const lastRefreshEl = document.getElementById("lastRefreshTime");
  if (!lastRefreshEl) return;

  if (lastRefresh) {
    const date = new Date(lastRefresh);
    lastRefreshEl.textContent = `Last refresh: ${date.toLocaleString()}`;
  } else {
    lastRefreshEl.textContent = "Never refreshed";
  }
}

/**
 * Formats a time difference as a human-readable string (e.g., "2 hours ago", "3 days ago").
 *
 * @param {string|Date} dateString - The date to compare against now.
 * @returns {string} Human-readable time difference.
 */
function formatCacheAge(dateString) {
  if (!dateString) return "Unknown";

  const date = new Date(dateString);
  if (isNaN(date.getTime())) return "Unknown";

  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMs / 3600000);
  const diffDays = Math.floor(diffMs / 86400000);

  if (diffMins < 1) return "Just now";
  if (diffMins < 60) return `${diffMins} minute${diffMins === 1 ? "" : "s"} ago`;
  if (diffHours < 24) return `${diffHours} hour${diffHours === 1 ? "" : "s"} ago`;
  if (diffDays < 30) return `${diffDays} day${diffDays === 1 ? "" : "s"} ago`;

  return date.toLocaleDateString();
}

/**
 * Shows the server mode UI when credentials are configured via environment.
 * Hides the login form completely.
 *
 * @param {Object} serverData - Server data containing seriesFirstASIN, seriesAllASIN, lastRefresh
 */
function showUseServerDataOption(serverData) {
  const formContainer = document.getElementById("form-container");
  if (!formContainer) return;

  // Hide the login form - we don't need it when server has credentials
  const loginForm = document.getElementById("loginForm");
  if (loginForm) loginForm.style.display = "none";

  // Create the server mode UI
  const serverOptionDiv = document.createElement("div");
  serverOptionDiv.id = "serverDataOption";
  serverOptionDiv.className = "server-data-option server-mode";

  const cacheAge = formatCacheAge(serverData.lastRefresh);
  const region = getServerAudibleRegion().toUpperCase();

  serverOptionDiv.innerHTML = `
    <div class="server-option-content">
      <div class="logoContainer">
        <img alt="Site Logo" src="/assets/logo-background-transparent.webp" />
      </div>
      <div class="headingContainer">
        <h2>Complete your collection</h2>
        <h4>Every series brought together</h4>
      </div>
      <div class="server-status-card">
        <div class="server-status-info">
          <p class="cache-age">Data cached: <strong>${cacheAge}</strong></p>
          <p class="server-stats">${serverData.seriesFirstASIN.length} series · Audible region: ${region}</p>
        </div>
        <div class="server-buttons">
          <button id="useServerDataBtn" class="accent-button">Use Cached Data</button>
          <button id="refreshServerDataBtn" class="accent-button secondary">Refresh from Server</button>
        </div>
      </div>
    </div>
  `;

  // Insert at the beginning
  formContainer.insertBefore(serverOptionDiv, formContainer.firstChild);

  // Set the region dropdown to match server config (for filter logic)
  const serverRegion = getServerAudibleRegion();
  const regionSelect = document.getElementById("audibleRegion");
  if (regionSelect) regionSelect.value = serverRegion;

  // Wire up buttons
  document.getElementById("useServerDataBtn").addEventListener("click", () => {
    processServerData(serverData);
  });

  document.getElementById("refreshServerDataBtn").addEventListener("click", async () => {
    await refreshAndUseServerData();
  });
}

/**
 * Shows a prompt when server is configured but has no data yet.
 * Hides the login form completely.
 */
function showServerNeedsRefreshOption() {
  const formContainer = document.getElementById("form-container");
  if (!formContainer) return;

  // Hide the login form - we don't need it when server has credentials
  const loginForm = document.getElementById("loginForm");
  if (loginForm) loginForm.style.display = "none";

  const serverOptionDiv = document.createElement("div");
  serverOptionDiv.id = "serverDataOption";
  serverOptionDiv.className = "server-data-option server-mode";

  const region = getServerAudibleRegion().toUpperCase();
  serverOptionDiv.innerHTML = `
    <div class="server-option-content">
      <div class="logoContainer">
        <img alt="Site Logo" src="/assets/logo-background-transparent.webp" />
      </div>
      <div class="headingContainer">
        <h2>Complete your collection</h2>
        <h4>Every series brought together</h4>
      </div>
      <div class="server-status-card">
        <div class="server-status-info">
          <p class="cache-age">No cached data yet</p>
          <p class="server-stats">Audible region: ${region}</p>
        </div>
        <div class="server-buttons">
          <button id="refreshServerDataBtn" class="accent-button">Fetch Library Data</button>
        </div>
      </div>
    </div>
  `;

  formContainer.insertBefore(serverOptionDiv, formContainer.firstChild);

  // Set the region dropdown to match server config (for filter logic)
  const serverRegion = getServerAudibleRegion();
  const regionSelect = document.getElementById("audibleRegion");
  if (regionSelect) regionSelect.value = serverRegion;

  document.getElementById("refreshServerDataBtn").addEventListener("click", async () => {
    await refreshAndUseServerData();
  });
}

/**
 * Refreshes server data and then uses it.
 */
async function refreshAndUseServerData() {
  const refreshBtn = document.getElementById("refreshServerDataBtn");
  const useBtn = document.getElementById("useServerDataBtn");

  if (refreshBtn) {
    refreshBtn.disabled = true;
    refreshBtn.textContent = "Refreshing...";
  }
  if (useBtn) useBtn.disabled = true;

  try {
    setMessage("Fetching library data from server...");
    toggleElementVisibility("message", true, "block");

    const result = await triggerServerRefresh();

    if (result.success) {
      // Now get the fresh data and use it
      const serverData = await getServerLibraryData();
      if (serverData.hasData) {
        processServerData(serverData);
        return;
      }
    }

    setMessage(`Error: ${result.error || "Failed to refresh server data"}`);
  } catch (error) {
    setMessage(`Error: ${error.message}`);
  } finally {
    if (refreshBtn) {
      refreshBtn.disabled = false;
      refreshBtn.textContent = "Refresh Server Data";
    }
    if (useBtn) useBtn.disabled = false;
  }
}

/**
 * Processes server-cached data directly without manual login.
 * Uses the same flow as manual login but with server data.
 *
 * @param {Object} serverData - Server data containing seriesFirstASIN, seriesAllASIN
 */
async function processServerData(serverData) {
  // Hide the server option UI
  const serverOptionDiv = document.getElementById("serverDataOption");
  if (serverOptionDiv) serverOptionDiv.style.display = "none";

  resetUserInterfaceAndStartLoadingProcess();
  await beginRun({ fresh: "auto" });

  try {
    setMessage("Using server-cached library data...");

    // Build existingContent from server data
    existingContent = {
      seriesFirstASIN: serverData.seriesFirstASIN,
      seriesAllASIN: serverData.seriesAllASIN,
    };

    if (!existingContent.seriesFirstASIN?.length) {
      setMessage("No series found in server data. Try refreshing.");
      hideSpinner();
      toggleElementVisibility("form-container", true);
      if (serverOptionDiv) serverOptionDiv.style.display = "block";
      return;
    }

    // Set the form's region dropdown to match server config
    // This is needed because isBookViable() reads directly from the form
    const serverRegion = getServerAudibleRegion();
    const regionSelect = document.getElementById("audibleRegion");
    if (regionSelect) {
      regionSelect.value = serverRegion;
    }

    // Get form data for filter settings
    const formData = getFormData();

    // Process the data
    await fetchAndDisplayResults(existingContent, formData);
  } catch (error) {
    console.error("Error processing server data:", error);
    setMessage(`Error: ${error.message}`);
    hideSpinner();
    toggleElementVisibility("form-container", true);
    if (serverOptionDiv) serverOptionDiv.style.display = "block";
  }
}
