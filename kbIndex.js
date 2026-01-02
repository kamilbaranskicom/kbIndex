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

    if (submitter.id === "zipAll") {
      // Pobierz wszystkie pliki z tabeli
      files = Array.from(form.querySelectorAll('input[name="selected[]"]')).map(
        (cb) => cb.value
      );
    } else if (submitter.id === "zipSelected") {
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
      updateButtonVisibility();

      if (checkedCheckboxes.length === 0) {
        document.getElementById("selectedMessage").innerText = "";
        return;
      }

      calculateTotalSizeOfSelectedItems(checkedCheckboxes);
    });
  });
});

function calculateTotalSizeOfSelectedItems(rows) {
  let totalSize = 0;
  let directoryCount = 0;
  let fileCount = 0;
  rows.forEach((checkedCb) => {
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
    rows.length
  } items (directories: ${directoryCount}, files: ${fileCount}), total size: ${humanSize(
    totalSize
  )}.`;
}

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
    progressBar.style.width = data.percent + "%";
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

// checkboxes and file selection

const tableBody = document.querySelector(".file-table tbody");
const selectAllCheckbox = document.getElementById("selectAll");
const checkboxes = Array.from(
  document.querySelectorAll('input[name="selected[]"]')
);

let anchor = null;
let snapshot = []; // Stan wszystkich checkboxów z momentu wybrania kotwicy

function updateButtonVisibility() {
  const buttonAll = document.querySelector("#zipAll");
  const buttonSelected = document.querySelector("#zipSelected");
  if (!buttonAll || !buttonSelected) return;

  const checkedCount = checkboxes.filter((cb) => cb.checked).length;
  const totalCount = checkboxes.length;

  if (checkedCount === 0 || checkedCount === totalCount) {
    buttonAll.classList.remove("hidden");
    buttonSelected.classList.add("hidden");
  } else {
    buttonAll.classList.add("hidden");
    buttonSelected.classList.remove("hidden");
  }

  const buttonDelete = document.querySelector("#deleteSelected");
  if (buttonDelete) {
    if (checkedCount > 0) {
      buttonDelete.classList.remove("hidden");
    } else {
      buttonDelete.classList.add("hidden");
    }
  }
}

/**
 * Logika zaznaczania - ujednolicona dla wiersza i checkboxa
 */
function performSelection(targetCheckbox, isShift) {
  const targetIdx = checkboxes.indexOf(targetCheckbox);

  if (isShift && anchor !== null) {
    const anchorIdx = checkboxes.indexOf(anchor);
    const min = Math.min(anchorIdx, targetIdx);
    const max = Math.max(anchorIdx, targetIdx);

    // Stan zakresu zależy od tego, jaki stan ma kotwica
    const rangeState = anchor.checked;

    checkboxes.forEach((cb, i) => {
      if (i >= min && i <= max) {
        // Elementy w aktualnym zakresie [kotwica <-> target]
        cb.checked = rangeState;
      } else {
        // Elementy POZA zakresem wracają do stanu, który miały przed Shiftem
        cb.checked = snapshot[i];
      }
    });
  } else {
    // Kliknięcie bez shiftu: ustawiamy nową kotwicę i zapamiętujemy stan wszystkiego
    anchor = targetCheckbox;
    snapshot = checkboxes.map((cb) => cb.checked);
  }

  updateButtonVisibility();
}

if (tableBody) {
  // 1. Nawigacja Double-click
  tableBody.addEventListener("dblclick", (e) => {
    const row = e.target.closest("tr");
    const link = row?.querySelector("td a");
    if (link) window.location.href = link.href;
  });

  // 2. Kliknięcie (Wiersz lub Checkbox)
  tableBody.addEventListener("click", (e) => {
    const row = e.target.closest("tr");
    if (!row) return;

    const checkbox = row.querySelector('input[name="selected[]"]');
    if (!checkbox || e.target.tagName === "A" || e.target.closest("a")) return;

    // Jeśli kliknięto w wiersz (a nie w sam checkbox), odwracamy stan checkboxa
    if (e.target.type !== "checkbox") {
      checkbox.checked = !checkbox.checked;
    }

    performSelection(checkbox, e.shiftKey);
  });
}

// 3. Obsługa "Zaznacz wszystko"
if (selectAllCheckbox) {
  selectAllCheckbox.addEventListener("change", function () {
    checkboxes.forEach((cb) => (cb.checked = this.checked));
    anchor = null; // Reset kotwicy po masowej akcji
    updateButtonVisibility();
  });
}

// Inicjalizacja
updateButtonVisibility();

// Obsługa kliknięcia w Delete:
const btnDelete = document.querySelector("#deleteSelected");
if (btnDelete) {
  btnDelete.addEventListener("click", function () {
    // Pobieramy zaznaczone wiersze, żeby sprawdzić czy są tam foldery
    const selectedRows = Array.from(
      document.querySelectorAll(".file-table tbody tr")
    ).filter((row) => row.querySelector('input[name="selected[]"]:checked'));

    const selectedNames = selectedRows.map(
      (row) => row.querySelector('input[name="selected[]"]').value
    );
    const hasDirectory = selectedRows.some((row) => row.dataset.isdir === "1");

    if (selectedNames.length === 0) return;

    // Budujemy dynamiczny komunikat
    let msg = `Czy na pewno chcesz usunąć te elementy: ${selectedNames.length} szt.?`;
    if (hasDirectory) {
      msg =
        `UWAGA! Wybrano co najmniej jeden folder.\n\n` +
        `Usunięcie folderu spowoduje BEZPOWROTNE skasowanie wszystkich plików i podfolderów wewnątrz.\n\n` +
        `Czy na pewno chcesz kontynuować?`;
    }

    if (confirm(msg)) {
      const formData = new FormData();
      selectedNames.forEach((name) => formData.append("selected[]", name));

      fetch("?action=delete", {
        method: "POST",
        body: formData,
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.status === "success") {
            window.location.reload();
          } else {
            alert("Błąd: " + data.message);
          }
        })
        .catch((err) => console.error("Delete error:", err));
    }
  });
}
