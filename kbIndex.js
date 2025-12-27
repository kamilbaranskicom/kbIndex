/**
 * Sorts the file table, manages UI indicators, and maintains directory priority.
 * @param {string} columnName - The name of the clicked TH.
 */
function sortTable(columnName) {
  const TABLE_COLUMNS = ["", "", "name", "ext", "size", "mtime", "description"];
  const columnIndex = TABLE_COLUMNS.indexOf(columnName);

  const table = document.querySelector(".file-table");
  const tbody = table.querySelector("tbody");
  const headers = table.querySelectorAll("th");
  const clickedHeader = headers[columnIndex];
  const rows = Array.from(tbody.querySelectorAll("tr"));

  // 1. Determine direction: toggle if same column, default to asc if new
  let direction = "asc";
  if (clickedHeader.classList.contains("asc")) {
    direction = "desc";
  }

  // 2. Clear .asc and .desc classes from ALL headers
  headers.forEach((th) => {
    th.classList.remove("asc", "desc");
    // Optional: clear dataset.order if you use it for CSS
    delete th.dataset.order;
  });

  // 3. Add the active class to the clicked header
  clickedHeader.classList.add(direction);
  clickedHeader.dataset.order = direction;

  // 4. Perform Sort
  rows.sort((a, b) => {
    // Priority: Directories always first
    const aDir = parseInt(a.dataset.isdir || 0);
    const bDir = parseInt(b.dataset.isdir || 0);
    if (aDir !== bDir) return bDir - aDir;

    // Data Retrieval
    const tdA = a.children[columnIndex];
    const tdB = b.children[columnIndex];

    let valA = tdA.dataset.value ?? tdA.innerText.trim();
    let valB = tdB.dataset.value ?? tdB.innerText.trim();

    // Numeric vs Textual comparison
    // Poprawiona logika wykrywania liczb:
    // Sprawdzamy, czy columnName to 'size' lub 'mtime'
    const isNumeric = ["size", "mtime"].includes(columnName);

    if (isNumeric) {
      // Compare as numbers (size_raw, mtime_raw)
      return direction === "asc"
        ? parseFloat(valA) - parseFloat(valB)
        : parseFloat(valB) - parseFloat(valA);
    } else {
      // Compare as text (filenames) - using localeCompare for "natural sort" (e.g. 2 < 10)
      return direction === "asc"
        ? valA.localeCompare(valB, undefined, {
            numeric: true,
            sensitivity: "base",
          })
        : valB.localeCompare(valA, undefined, {
            numeric: true,
            sensitivity: "base",
          });
    }
  });

  // 5. Update DOM
  rows.forEach((row) => tbody.appendChild(row));

  // 6. Update URL
  window.history.pushState(
    "page",
    "title",
    updateURLParameters({
      sort: columnName,
      order: direction === "desc" ? "desc" : "asc",
    })
  );
}

/**
 * Updates the URL parameters based on the provided object.
 * @param {*} newParams
 * @returns {string} Updated URL string
 */
function updateURLParameters(newParams) {
  // Tworzymy obiekt URL na bazie aktualnego adresu
  const url = new URL(window.location.href);

  // Iterujemy po obiekcie i ustawiamy parametry
  Object.entries(newParams).forEach(([key, value]) => {
    if (value === null || value === undefined) {
      url.searchParams.delete(key); // Opcjonalnie: usuń, jeśli wartość to null
    } else {
      url.searchParams.set(key, value);
    }
  });

  return url.toString();
}
