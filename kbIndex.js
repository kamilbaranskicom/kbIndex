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
    // Priority 1: Directories always first
    const aDir = parseInt(a.dataset.isdir || 0);
    const bDir = parseInt(b.dataset.isdir || 0);
    if (aDir !== bDir) return bDir - aDir;

    // Data Retrieval
    const tdA = a.children[columnIndex];
    const tdB = b.children[columnIndex];
    let valA = tdA.dataset.value ?? tdA.innerText.trim();
    let valB = tdB.dataset.value ?? tdB.innerText.trim();

    // Priority 2: Main comparison
    let comparison = 0;
    const isNumeric = ["size", "mtime"].includes(columnName);

    if (isNumeric) {
      comparison = parseFloat(valA) - parseFloat(valB);
    } else {
      comparison = valA.localeCompare(valB, undefined, {
        numeric: true,
        sensitivity: "base",
      });
    }

    // APPLY DIRECTION
    if (direction === "desc") comparison *= -1;

    // Priority 3: Tie-breaker (if values are equal, sort by Name)
    if (comparison === 0 && columnName !== "name") {
      // index 2 is "name" column (according to TABLE_COLUMNS)
      const nameA = a.children[2].innerText.trim();
      const nameB = b.children[2].innerText.trim();
      comparison2 = nameA.localeCompare(nameB, undefined, {
        numeric: true,
        sensitivity: "base",
      });
      // APPLY DIRECTION
      if (direction === "desc") comparison2 *= -1;

      return comparison2;
    }

    return comparison;
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
  // create a URL object from the current window location
  const url = new URL(window.location.href);

  // iterate over the newParams object and set or delete parameters
  Object.entries(newParams).forEach(([key, value]) => {
    if (value === null || value === undefined) {
      url.searchParams.delete(key); // Opcjonalnie: usuń, jeśli wartość to null
    } else {
      url.searchParams.set(key, value);
    }
  });

  return url.toString();
}

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("kbIndexForm");
  if (!form) return;

  form.addEventListener("submit", function (e) {
    e.preventDefault(); // Zatrzymujemy standardowy POST

    const submitter = e.submitter; // Przycisk, który wywołał submit
    let files = [];

    if (submitter.name === "zip_all") {
      // Pobierz wszystkie pliki z tabeli
      files = Array.from(form.querySelectorAll('input[name="selected[]"]')).map(
        (cb) => cb.value
      );
    } else if (submitter.name === "zip_selected") {
      // Pobierz tylko zaznaczone
      files = Array.from(
        form.querySelectorAll('input[name="selected[]"]:checked')
      ).map((cb) => cb.value);
    }

    if (files.length === 0) {
      alert("Proszę zaznaczyć przynajmniej jeden plik.");
      return;
    }

    startZipProgress(files);
  });

  form.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
    cb.addEventListener("change", function () {
      const checkedCheckboxes = form.querySelectorAll(
        'input[name="selected[]"]:checked'
      );
      showOnlyNeededButtons();

      if (checkedCheckboxes.length === 0) {
        document.getElementById("selectedMessage").innerText = "";
        return;
      }

      let totalSize = 0;
      let directoryCount = 0;
      let fileCount = 0;
      checkedCheckboxes.forEach((checkedCb) => {
        // Find the parent row (tr) of the checked checkbox
        const row = checkedCb.closest("tr");
        // Find the 'size' td within that row and get its data-value
        const sizeTd = row.querySelector("td.size");
        if (sizeTd && sizeTd.dataset.value)
          totalSize += parseFloat(sizeTd.dataset.value);
        if (row.dataset.isdir === "1") directoryCount++;
        if (row.dataset.isdir === "0") fileCount++;
      });
      document.getElementById("selectedMessage").innerText = `Selected ${
        checkedCheckboxes.length
      } items (directories: ${directoryCount}, files: ${fileCount}), total size: ${humanSize(
        totalSize
      )}.`; // TODO: humansize in JS
    });
  });
});

function humanSize(bytes) {
  if (bytes <= 0) return "-";

  const units = ["B", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));

  return (bytes / Math.pow(1024, i)).toFixed(2) + " " + units[i];
}

/**
 * Initiates the download with a progress overlay.
 */
function startZipProgress(files) {
  const statusBox = document.getElementById("status-box");
  statusBox.classList.remove("hidden");
  const progressBar = document.querySelector("#status-progress");
  const statusMessage = document.querySelector("#status-message");

  const filesJson = JSON.stringify(files);
  const url = `?action=zip&files=${encodeURIComponent(filesJson)}`;

  // console.log(filesJson);
  // console.log(url);

  const eventSource = new EventSource(url);

  eventSource.onmessage = (event) => {
    // console.log(event);
    const data = JSON.parse(event.data);

    // Update your UI element
    progressBar.value = data.percent;
    statusMessage.innerText = `Preparing archive: (${data.percent}%)`;

    if (data.status === "done") {
      eventSource.close();
      // Trigger the actual file download now that it's ready
      window.location.href = `${data.downloadUrl}`;
      setTimeout(() => statusBox.classList.add("hidden"), 1000);
    }
  };

  eventSource.onerror = () => {
    eventSource.close();
    console.error("Compression stream failed.");
  };
}

const checkboxes = Array.from(
  document.querySelectorAll('input[name="selected[]"]')
);
const selectAllCheckbox = document.getElementById("selectAll");

// "Kotwica" to ostatni checkbox zaznaczony BEZ shiftu
let anchor = null;

checkboxes.forEach((checkbox) => {
  checkbox.addEventListener("click", function (e) {
    if (e.shiftKey && anchor) {
      const startInd = checkboxes.indexOf(anchor);
      const endInd = checkboxes.indexOf(this);

      const min = Math.min(startInd, endInd);
      const max = Math.max(startInd, endInd);

      // Pobieramy stan elementu, który właśnie kliknęliśmy (true/false)
      const newState = this.checked;

      // Przechodzimy przez zakres
      for (let i = min; i <= max; i++) {
        // POMIJAMY kotwicę - jej stan ma zostać taki, jaki był w momencie
        // gdy stała się kotwicą (czyli zazwyczaj zaznaczona)
        if (checkboxes[i] !== anchor) {
          checkboxes[i].checked = newState;
        }
      }
    } else {
      // Jeśli klikasz normalnie (bez shiftu), ten element zostaje nową kotwicą
      anchor = this;
    }
  });
});

// Obsługa "Zaznacz wszystko"
selectAllCheckbox.addEventListener("change", function () {
  checkboxes.forEach((cb) => {
    cb.checked = this.checked;
  });
  // Po kliknięciu "Zaznacz wszystko" resetujemy kotwicę
  anchor = null;
});

function showOnlyNeededButtons() {
  const buttonDownloadAll = document.querySelector("#zipAll");
  const buttonDownloadSelected = document.querySelector("#zipSelected");
  if (
    checkboxes.every((cb) => !cb.checked) ||
    checkboxes.every((cb) => cb.checked)
  ) {
    console.log(checkboxes);
    buttonDownloadAll.classList.remove("hidden");
    buttonDownloadSelected.classList.add("hidden");
  } else {
    buttonDownloadAll.classList.add("hidden");
    buttonDownloadSelected.classList.remove("hidden");
  }
}

showOnlyNeededButtons();