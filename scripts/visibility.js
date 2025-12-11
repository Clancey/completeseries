// visibility.js

import { sortBySeriesThenTitle } from "./dataCleaner.js";
import { applyFilterButton } from "./interactions.js";
import {
  loadMetadataFromLocalStorage,
  storeUpdateFullValueForLocalStorage,
} from "./localStorage.js";
import { saveHiddenItemsToServer, syncHiddenItems } from "./serverSync.js";

// Local storage key value
const VISIBILITY_KEY = "hiddenItems";

/**
 * Retrieves hidden items from localStorage.
 * @returns {Array<Object>} List of hidden {type, series, title, asin} objects.
 */
export function getHiddenItems() {
  try {
    return loadMetadataFromLocalStorage(VISIBILITY_KEY);
  } catch (error) {
    console.error("Failed to parse hidden items from localStorage:", error);
    return [];
  }
}

/**
 * Stores hidden items into localStorage and syncs to server (if configured).
 * @param {Array<Object>} items - List of hidden items to persist.
 */
export function setHiddenItems(items) {
  try {
    const sortedItems = sortBySeriesThenTitle(items);
    storeUpdateFullValueForLocalStorage(sortedItems, VISIBILITY_KEY);

    // Also save to server (fire-and-forget, non-blocking)
    saveHiddenItemsToServer(sortedItems);
  } catch (error) {
    console.error("Failed to store hidden items to localStorage:", error);
  }
}

/**
 * Hides an item (series or book) by adding it to the local storage.
 * Updates the hidden items menu afterward.
 *
 * @param {Object} item - The item to hide.
 */
export function hideItem(item) {
  if (!isCurrentlyHidden(item)) {
    const currentHidden = getHiddenItems();
    const updatedHidden = [...currentHidden, item];
    setHiddenItems(updatedHidden);
  }
}

/**
 * Unhides an item by removing it from the local storage.
 *
 * @param {Object} item - The item to unhide.
 */
export function unhideItem(item) {
  const currentHidden = getHiddenItems();
  const updatedHidden = currentHidden.filter(
    (hiddenItem) =>
      !(
        hiddenItem.type === item.type &&
        hiddenItem.series === item.series &&
        hiddenItem.title === item.title &&
        hiddenItem.asin === item.asin
      )
  );
  setHiddenItems(updatedHidden);
}

/**
 * Toggles visibility of an item based on its current state and eye icon class.
 *
 * @param {Object} item - The item to toggle.
 * @param {HTMLElement} eyeIcon - The icon element indicating hidden state.
 */
export function toggleHiddenItem(item, eyeIcon) {
  if (eyeIcon.classList.contains("eyeClosed")) unhideItem(item);
  else hideItem(item);
}

/**
 * Updates the visibility menu UI based on the eye icon state.
 * Used when interacting with the "Visibility Manager".
 *
 * @param {HTMLElement} eyeIcon - The eye icon clicked inside the menu.
 */
export function toggleHiddenItemVisibilityMenu(eyeIcon) {
  const requestReload = document.getElementById("requestReloadDiv");

  if (eyeIcon.classList.contains("eyeClosed")) {
    requestReload.classList.remove("active");
    applyFilterButton.classList.remove("active");
  } else {
    requestReload.classList.add("active");
    applyFilterButton.classList.add("active");
  }
}

/**
 * Checks if a given book or series is currently hidden.
 *
 * @param {Object} item - The item to check (must contain type, series, and asin for books).
 * @returns {boolean} True if the item is hidden, otherwise false.
 */
export function isCurrentlyHidden(item) {
  const hiddenItems = getHiddenItems();
  return hiddenItems.some(
    (hiddenItem) =>
      hiddenItem.type === item.type &&
      hiddenItem.series === item.series &&
      // For books, match by ASIN (unique identifier) to handle same-title editions
      // For series, match by series name only
      (item.type === "series" || hiddenItem.asin === item.asin)
  );
}

/**
 * Checks whether a given ASIN is currently marked as hidden.
 *
 * This function scans the list of hidden items (retrieved from the local storage)
 * and returns `true` if an item with the provided ASIN exists.
 *
 * @param {string} asin - The ASIN to check.
 * @returns {boolean} - Returns true if the ASIN is hidden, false otherwise.
 */
export function isCurrentlyHiddenByAsin(asin) {
  const hiddenItems = getHiddenItems();

  // Check if any item in the hidden list has a matching ASIN
  return hiddenItems.some((item) => item.asin === asin);
}

/**
 * Returns the total number of hidden books for a given series.
 *
 * @param {string} seriesName - The name of the series to check.
 * @returns {number} Number of hidden books in the series.
 */
export function totalHiddenInSeries(seriesName) {
  const hiddenItems = getHiddenItems();
  return hiddenItems.filter((item) => item.type === "book" && item.series === seriesName).length;
}

/**
 * Syncs hidden items with the server (if configured).
 * Merges local and server hidden items, with server as source of truth.
 * Updates local storage with merged result.
 *
 * @returns {Promise<Array>} - The merged hidden items array
 */
export async function syncHiddenItemsWithServer() {
  const localItems = getHiddenItems();
  const mergedItems = await syncHiddenItems(localItems);

  // Update local storage with merged data (only if different)
  if (mergedItems.length !== localItems.length) {
    storeUpdateFullValueForLocalStorage(mergedItems, VISIBILITY_KEY);
  }

  return mergedItems;
}
