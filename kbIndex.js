/**
 * Sorts the file table, manages UI indicators, and maintains directory priority.
 * @param {number} columnIndex - The index of the clicked TH.
 */
function sortTable(columnIndex) {
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
    if (tdA.dataset.value) {
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

  // 6. Update URL (using your setGetParameter)
  const sortParam = columnIndex + (direction === "desc" ? "" : "!");
  window.history.pushState("page", "title", setGetParameter("sort", sortParam));
}

/**
 * Sets or updates a GET parameter in the URL.
 * @param {string} paramName - The name of the parameter to set.
 * @param {string} paramValue - The value of the parameter to set.
 * @returns {string} - The updated URL with the new parameter.
 */
function setGetParameter(paramName, paramValue) {
  var url = window.location.href;
  var hash = location.hash;
  url = url.replace(hash, "");
  if (url.indexOf("?") >= 0) {
    var params = url.substring(url.indexOf("?") + 1).split("&");
    var paramFound = false;
    params.forEach(function (param, index) {
      var p = param.split("=");
      if (p[0] == paramName) {
        params[index] = paramName + "=" + paramValue;
        paramFound = true;
      }
    });
    if (!paramFound) params.push(paramName + "=" + paramValue);
    url = url.substring(0, url.indexOf("?") + 1) + params.join("&");
  } else url += "?" + paramName + "=" + paramValue;
  return url + hash;
}
