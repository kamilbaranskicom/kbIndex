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

const selectAllCheckbox = document.getElementById("selectAll");
// Pobieramy wszystkie checkboxy, które mają nazwę "selected[]"
const checkboxes = document.querySelectorAll('input[name="selected[]"]');

selectAllCheckbox.addEventListener("change", function () {
  checkboxes.forEach((cb) => {
    cb.checked = this.checked;
  });
});

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
});

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

  console.log(filesJson);
  console.log(url);

  const eventSource = new EventSource(url);

  eventSource.onmessage = (event) => {
    const data = JSON.parse(event.data);

    // Update your UI element
    if (data.status === "progress") {
      progressBar.value = data.progress;
      statusMessage.innerText = `Preparing archive: ${data.file} (${data.percent}%)`;
    }

    if (data.status === "done") {
      eventSource.close();
      // Trigger the actual file download now that it's ready
      window.location.href = `${data.downloadUrl}`;
      setTimeout(() => statusBox.classList.add("hidden"), 3000);
    }
  };

  eventSource.onerror = () => {
    eventSource.close();
    console.error("Compression stream failed.");
  };
}
