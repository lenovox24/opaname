// Modern JavaScript for Stock Management System

// Global variables
let itemCounter = 0;
let productsData = [];
const batchesData = {};

// Formatting helper: show decimals only when present (no trailing zeros), use comma as decimal, no grouping
function formatNumberDisplay(value) {
  const num = Number(value);
  if (!isFinite(num)) return '';
  return num.toLocaleString('id-ID', { maximumFractionDigits: 6, useGrouping: false });
}

// Initialize when DOM is loaded
document.addEventListener("DOMContentLoaded", () => {
  initializeApp();
  initializeModernProductList();
});

function initializeApp() {
  // Load products data
  loadProductsData();

  // Initialize event listeners
  initializeEventListeners();

  // Initialize modals
  initializeModals();

  // Initialize mobile menu
  initializeMobileMenu();

  // Set default dates
  setDefaultDates();

  // Global product autocomplete overlays (always on top)
  initializeGlobalProductAutocomplete();
}

function loadProductsData() {
  // Load products from datalist
  const datalist = document.getElementById("datalistProducts");
  if (datalist) {
    productsData = Array.from(datalist.options).map((option) => ({
      id: option.dataset.id,
      name: option.value,
      sku: option.dataset.sku,
      standard_qty: Number.parseFloat(option.dataset.stdqty) || 25,
    }));
  }
}

function initializeEventListeners() {
  // Incoming transaction form listeners
  initializeIncomingFormListeners();

  // Outgoing transaction form listeners
  initializeOutgoingFormListeners();

  // 501 form listeners
  initialize501FormListeners();

  // General form listeners
  initializeGeneralListeners();
}

function initializeIncomingFormListeners() {
  const productNameInput = document.getElementById("incoming_product_name");
  const quantityKgInput = document.getElementById("incoming_quantity_kg");
  const quantitySacksInput = document.getElementById("incoming_quantity_sacks");
  const grossWeightInput = document.getElementById("incoming_gross_weight");
  const calcKgCheck = document.getElementById("calc_kg_check");
  const calcSakCheck = document.getElementById("calc_sak_check");

  if (productNameInput) {
    productNameInput.addEventListener("input", handleProductSelection);
    productNameInput.addEventListener("change", handleProductSelection);
  }

  if (quantityKgInput && quantitySacksInput) {
    quantityKgInput.addEventListener("input", () => {
      if (calcSakCheck && calcSakCheck.checked) {
        calculateSacksFromKg();
      }
      calculateLotNumber();
    });

    quantitySacksInput.addEventListener("input", () => {
      if (calcKgCheck && calcKgCheck.checked) {
        calculateKgFromSacks();
      }
      calculateLotNumber();
    });
  }

  if (grossWeightInput) {
    grossWeightInput.addEventListener("input", calculateLotNumber);
  }

  // Update checkbox IDs and logic
  const incomingCalcKgCheck = document.getElementById("incoming_calc_kg_check");
  const incomingCalcSakCheck = document.getElementById(
    "incoming_calc_sak_check"
  );

  if (incomingCalcKgCheck) {
    incomingCalcKgCheck.addEventListener("change", function () {
      if (this.checked) {
        if (incomingCalcSakCheck) incomingCalcSakCheck.checked = false;
        quantityKgInput.readOnly = true;
        quantitySacksInput.readOnly = false;
      } else {
        quantityKgInput.readOnly = false;
      }
      calculateKgFromSacks();
    });
  }

  if (incomingCalcSakCheck) {
    incomingCalcSakCheck.addEventListener("change", function () {
      if (this.checked) {
        if (incomingCalcKgCheck) incomingCalcKgCheck.checked = false;
        quantitySacksInput.readOnly = true;
        quantityKgInput.readOnly = false;
      } else {
        quantitySacksInput.readOnly = false;
      }
      calculateSacksFromKg();
    });
  }

  // Edit button listeners (scoped/guarded)
  // Jika halaman memiliki struktur incoming modern (dengan datalistProductsIncoming),
  // biarkan handler khusus di bawah (LOGIKA UNTUK MODAL BARANG MASUK) yang menangani via fetch.
  // Hindari double-binding yang dapat membuat halaman terasa berat.
  if (!document.getElementById('datalistProductsIncoming')) {
    document.querySelectorAll('.edit-btn[data-bs-target="#incomingTransactionModal"]').forEach((btn) => {
      btn.addEventListener('click', handleEditIncoming);
    });
  }
}

function initializeOutgoingFormListeners() {
  // Hindari double-binding pada halaman modern (memiliki datalistProductsOutgoing)
  const isModernOutgoingPage = !!document.getElementById('datalistProductsOutgoing');
  if (!isModernOutgoingPage) {
    const addItemBtn = document.getElementById('addItemBtn');
    if (addItemBtn) {
      addItemBtn.addEventListener('click', addOutgoingItem);
    }

    // Edit button listeners untuk versi lama
    document
      .querySelectorAll('[data-bs-target="#outgoingTransactionModal"]')
      .forEach((btn) => {
        if (btn.dataset.id || btn.dataset.docNumber) {
          btn.addEventListener('click', () => {
            if (btn.dataset.id) {
              handleEditOutgoing(null, btn.dataset.id);
            } else {
              handleEditOutgoing(btn.dataset.docNumber);
            }
          });
        }
      });
  }
}

function initialize501FormListeners() {
  const productSelect = document.getElementById("keluar501_product_id");
  const batchSelect = document.getElementById("keluar501_batch_select");
  const quantityInput = document.getElementById("keluar501_quantity");

  if (productSelect) {
    productSelect.addEventListener("change", load501Batches);
  }

  if (batchSelect) {
    batchSelect.addEventListener("change", update501SisaDisplay);
  }

  if (quantityInput) {
    quantityInput.addEventListener("input", validate501Quantity);
  }
}

function initializeGeneralListeners() {
  // Form validation
  document.querySelectorAll("form").forEach((form) => {
    form.addEventListener("submit", handleFormSubmit);
  });

  // Auto-dismiss alerts, but keep those marked as persistent
  setTimeout(() => {
    document.querySelectorAll(".alert").forEach((alert) => {
      if (alert.classList.contains('alert-persistent')) return;
      const bsAlert = new bootstrap.Alert(alert);
      bsAlert.close();
    });
  }, 5000);
}

function initializeModals() {
  // Reset forms when modals are hidden
  document.querySelectorAll(".modal").forEach((modal) => {
    modal.addEventListener("hidden.bs.modal", function () {
      const form = this.querySelector("form");
      if (form) {
        form.reset();
        resetModalState(this.id);
      }
    });
  });

  // Focus first input when modal is shown
  document.querySelectorAll(".modal").forEach((modal) => {
    modal.addEventListener("shown.bs.modal", function () {
      const firstInput = this.querySelector(
        'input:not([type="hidden"]):not([readonly]), select, textarea'
      );
      if (firstInput) {
        firstInput.focus();
      }
    });
  });
}

function initializeMobileMenu() {
  // DISABLED: Main toggle button in navbar is now always visible
  // This prevents duplicate toggle buttons and ensures consistent behavior
  const sidebar = document.getElementById("sidebar");
  if (sidebar) {
    document.addEventListener("click", (e) => {
      // Jangan tutup sidebar jika klik pada tombol toggle
      const toggleBtn = document.querySelector(".navbar-toggler");
      if (
        window.innerWidth <= 768 &&
        !sidebar.contains(e.target) &&
        !(toggleBtn && toggleBtn.contains(e.target))
      ) {
        const offcanvas = bootstrap.Offcanvas.getInstance(sidebar);
        if (offcanvas) {
          offcanvas.hide();
        }
      }
    });
  }
}

function setDefaultDates() {
  const today = new Date().toISOString().split("T")[0];
  document.querySelectorAll('input[type="date"]').forEach((input) => {
    if (!input.value) {
      input.value = today;
    }
  });
}

// Product selection handler
function handleProductSelection() {
  const input = document.getElementById("incoming_product_name");
  const hiddenInput = document.getElementById("incoming_product_id_hidden");

  if (!input || !hiddenInput) return;

  const selectedProduct = productsData.find((p) => p.name === input.value);
  if (selectedProduct) {
    hiddenInput.value = selectedProduct.id;

    // Auto-fill batch number with current date + product code
    const batchInput = document.getElementById("incoming_batch_number");
    if (batchInput && !batchInput.value) {
      const today = new Date();
      const dateStr =
        today.getFullYear().toString().substr(-2) +
        String(today.getMonth() + 1).padStart(2, "0") +
        String(today.getDate()).padStart(2, "0");
      batchInput.value = `${selectedProduct.sku}-${dateStr}`;
    }
  } else {
    hiddenInput.value = "";
  }
}

// Calculation functions
function calculateSacksFromKg() {
  const kgInput = document.getElementById("incoming_quantity_kg");
  const sacksInput = document.getElementById("incoming_quantity_sacks");
  const productNameInput = document.getElementById("incoming_product_name");

  if (!kgInput || !sacksInput || !productNameInput) return;

  const selectedProduct = productsData.find(
    (p) => p.name === productNameInput.value
  );
  if (selectedProduct && kgInput.value) {
    const kg = Number.parseFloat(kgInput.value);
    const standardQty = selectedProduct.standard_qty;
    const sacks = (kg / standardQty).toFixed(2);
    sacksInput.value = sacks;
  }
}

function calculateKgFromSacks() {
  const kgInput = document.getElementById("incoming_quantity_kg");
  const sacksInput = document.getElementById("incoming_quantity_sacks");
  const productNameInput = document.getElementById("incoming_product_name");

  if (!kgInput || !sacksInput || !productNameInput) return;

  const selectedProduct = productsData.find(
    (p) => p.name === productNameInput.value
  );
  if (selectedProduct && sacksInput.value) {
    const sacks = Number.parseFloat(sacksInput.value);
    const standardQty = selectedProduct.standard_qty;
    kgInput.value = (sacks && standardQty) ? String(sacks * standardQty) : "";
  }
}

function calculateLotNumber() {
  const grossWeightInput = document.getElementById("incoming_gross_weight");
  const quantityKgInput = document.getElementById("incoming_quantity_kg");
  const lotDisplay = document.getElementById("incoming_lot_number_display");

  if (!grossWeightInput || !quantityKgInput || !lotDisplay) return;

  const grossWeight = Number.parseFloat(grossWeightInput.value) || 0;
  const netWeight = Number.parseFloat(quantityKgInput.value) || 0;

  if (grossWeight > 0 && netWeight > 0) {
    const lot = grossWeight - netWeight;
    lotDisplay.value = lot.toFixed(2);
  } else {
    lotDisplay.value = "";
  }
}

// Edit handlers
function handleEditIncoming(event) {
  const btn = event.currentTarget;
  const modal = document.getElementById("incomingTransactionModal");
  const modalTitle = modal.querySelector(".modal-title");
  const submitBtn = document.getElementById("incomingSubmitButton");

  // Update modal title and button
  modalTitle.innerHTML =
    '<i class="bi bi-pencil-square me-2"></i>Edit Transaksi Barang Masuk';
  submitBtn.innerHTML = '<i class="bi bi-save-fill me-1"></i>Update Data';

  // Fill form with data
  const fields = [
    "id",
    "product_id",
    "product_name",
    "po_number",
    "supplier",
    "produsen",
    "license_plate",
    "quantity_kg",
    "quantity_sacks",
    "document_number",
    "batch_number",
    "lot_number",
    "transaction_date",
    "status",
  ];

  fields.forEach((field) => {
    const input = document.getElementById(
      `incoming_${
        field === "id"
          ? "transaction_id"
          : field === "product_id"
          ? "product_id_hidden"
          : field
      }`
    );
    if (input && btn.dataset[field]) {
      input.value = btn.dataset[field];
    }
  });

  // Calculate and display lot number
  const lotDisplay = document.getElementById("incoming_lot_number_display");
  if (lotDisplay && btn.dataset.lot_number) {
    lotDisplay.value = btn.dataset.lot_number;
  }
}

function handleEditOutgoing(docNumber, id) {
  const modal = document.getElementById("outgoingTransactionModal");
  const modalTitle = modal.querySelector(".modal-title");
  const submitBtn = document.getElementById("saveTransactionBtn");
  const docInput = document.getElementById("original_document_number");

  // Initialize outgoing items array
  window.outgoingItems = [];

  // Update modal for edit mode
  modalTitle.innerHTML =
    '<i class="bi bi-pencil-square me-2"></i>Edit Transaksi Barang Keluar';
  submitBtn.innerHTML = '<i class="bi bi-save-fill me-1"></i>Update Transaksi';

  // Prioritas: gunakan ID jika ada
  if (id) {
    // Load data berdasarkan ID spesifik
    loadOutgoingTransactionData(null, id);
  } else if (docNumber) {
    // Fallback ke document number
    if (docInput) {
      docInput.value = docNumber;
    }
    loadOutgoingTransactionData(docNumber);
  }
}

// Outgoing transaction functions
function addOutgoingItem() {
  itemCounter++;
  const container = document.getElementById("itemsContainer");
  if (!container) return;

  const itemHtml = createOutgoingItemHtml(itemCounter);
  container.insertAdjacentHTML("beforeend", itemHtml);

  // Initialize new item listeners
  initializeOutgoingItemListeners(itemCounter);
}

function createOutgoingItemHtml(counter) {
  return `
        <div class="card mb-3 item-card" id="item_${counter}">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-box me-1"></i>Item #${counter}
                </h6>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeOutgoingItem(${counter})">
                    <i class="bi bi-trash3"></i>
                </button>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nama Barang</label>
                        <select class="form-select product-select" name="items[${counter}][product_id]" required>
                            <option value="">-- Pilih Produk --</option>
                            ${productsData
                              .map(
                                (p) =>
                                  `<option value="${p.id}">${p.name} (${p.sku})</option>`
                              )
                              .join("")}
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Batch</label>
                        <select class="form-select batch-select" name="items[${counter}][batch_number]" required disabled>
                            <option value="">-- Pilih produk dulu --</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Qty (Kg)</label>
                        <input type="number" step="any" class="form-control quantity-input" name="items[${counter}][quantity_kg]" placeholder="0.00" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Qty (Sak)</label>
                        <input type="number" step="any" class="form-control sacks-input" name="items[${counter}][quantity_sacks]" placeholder="0.00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">501 (Lot)</label>
                        <input type="number" step="any" class="form-control lot-input" name="items[${counter}][lot_number]" placeholder="0.00">
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info py-2 mb-0 stock-info" style="display: none;">
                            <small><i class="bi bi-info-circle me-1"></i>Stok tersedia: <span class="stock-amount">0</span> Kg</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function initializeOutgoingItemListeners(counter) {
  const itemCard = document.getElementById(`item_${counter}`);
  if (!itemCard) return;

  const productSelect = itemCard.querySelector(".product-select");
  const batchSelect = itemCard.querySelector(".batch-select");
  const quantityInput = itemCard.querySelector(".quantity-input");
  const sacksInput = itemCard.querySelector(".sacks-input");

  if (productSelect) {
    productSelect.addEventListener("change", () => loadBatchesForItem(counter));
  }

  if (batchSelect) {
    batchSelect.addEventListener("change", () => updateStockInfo(counter));
  }

  if (quantityInput) {
    quantityInput.addEventListener("input", () => {
      calculateSacksForItem(counter);
      validateStockForItem(counter);
    });
  }

  if (sacksInput) {
    sacksInput.addEventListener("input", () => calculateKgForItem(counter));
  }
}

function removeOutgoingItem(counter) {
  const item = document.getElementById(`item_${counter}`);
  if (item) {
    item.remove();
  }
}

function loadBatchesForItem(counter) {
  const itemCard = document.getElementById(`item_${counter}`);
  if (!itemCard) return;

  const productSelect = itemCard.querySelector(".product-select");
  const batchSelect = itemCard.querySelector(".batch-select");

  if (!productSelect || !batchSelect) return;

  const productId = productSelect.value;
  if (!productId) {
    batchSelect.innerHTML = '<option value="">-- Pilih produk dulu --</option>';
    batchSelect.disabled = true;
    return;
  }

  // Load batches via AJAX
  fetch(`api_get_batches.php?product_id=${productId}`)
    .then((response) => response.json())
    .then((data) => {
      batchSelect.innerHTML = '<option value="">-- Pilih Batch --</option>';
      
      // Hitung total sak keseluruhan dari semua batch
      let totalSakKeseluruhan = 0;
      if (data.length > 0) {
        totalSakKeseluruhan = parseFloat(data[0].total_sak_keseluruhan || 0);
      }
      
      data.forEach((batch) => {
        const option = document.createElement("option");
        option.value = batch.batch_number;
        // Menampilkan sisa stok kg per batch dan total sak keseluruhan
        const sisaKg = parseFloat(batch.sisa_stok_kg || 0).toFixed(2);
        option.textContent = `${batch.batch_number} (Sisa: ${sisaKg} Kg / Total: ${totalSakKeseluruhan.toFixed(2)} Sak)`;
        option.dataset.stock = batch.sisa_stok_kg;
        option.dataset.stockSak = batch.sisa_stok_sak;
        option.dataset.totalSakKeseluruhan = totalSakKeseluruhan;
        batchSelect.appendChild(option);
      });
      batchSelect.disabled = false;
    })
    .catch((error) => {
      console.error("Error loading batches:", error);
      batchSelect.innerHTML = '<option value="">Error loading batches</option>';
    });
}

function updateStockInfo(counter) {
  const itemCard = document.getElementById(`item_${counter}`);
  if (!itemCard) return;

  const batchSelect = itemCard.querySelector(".batch-select");
  const stockInfo = itemCard.querySelector(".stock-info");
  const stockAmount = itemCard.querySelector(".stock-amount");

  if (!batchSelect || !stockInfo || !stockAmount) return;

  const selectedOption = batchSelect.options[batchSelect.selectedIndex];
  if (selectedOption && selectedOption.dataset.stock) {
    const stock = Number.parseFloat(selectedOption.dataset.stock);
    stockAmount.textContent = stock.toLocaleString("id-ID", {
      minimumFractionDigits: 2,
    });
    stockInfo.style.display = "block";
  } else {
    stockInfo.style.display = "none";
  }
}

function calculateSacksForItem(counter) {
  const itemCard = document.getElementById(`item_${counter}`);
  if (!itemCard) return;

  const productSelect = itemCard.querySelector(".product-select");
  const quantityInput = itemCard.querySelector(".quantity-input");
  const sacksInput = itemCard.querySelector(".sacks-input");

  if (!productSelect || !quantityInput || !sacksInput) return;

  const productId = productSelect.value;
  const selectedProduct = productsData.find((p) => p.id === productId);

  if (selectedProduct && quantityInput.value) {
    const kg = Number.parseFloat((quantityInput.value || '').toString().replace(',', '.'));
    const standardQty = selectedProduct.standard_qty;
    sacksInput.value = (kg && standardQty) ? String(kg / standardQty) : '';
  }
}

function calculateKgForItem(counter) {
  const itemCard = document.getElementById(`item_${counter}`);
  if (!itemCard) return;

  const productSelect = itemCard.querySelector(".product-select");
  const quantityInput = itemCard.querySelector(".quantity-input");
  const sacksInput = itemCard.querySelector(".sacks-input");

  if (!productSelect || !quantityInput || !sacksInput) return;

  const productId = productSelect.value;
  const selectedProduct = productsData.find((p) => p.id === productId);

  if (selectedProduct && sacksInput.value) {
    const sacks = Number.parseFloat(sacksInput.value);
    const standardQty = selectedProduct.standard_qty;
    const kg = (sacks * standardQty).toFixed(2);
    quantityInput.value = kg;
  }
}

function validateStockForItem(counter) {
  const itemCard = document.getElementById(`item_${counter}`);
  if (!itemCard) return;

  const batchSelect = itemCard.querySelector(".batch-select");
  const quantityInput = itemCard.querySelector(".quantity-input");
  const stockInfo = itemCard.querySelector(".stock-info");

  if (!batchSelect || !quantityInput || !stockInfo) return;

  const selectedOption = batchSelect.options[batchSelect.selectedIndex];
  const requestedQty = Number.parseFloat(quantityInput.value) || 0;

  if (selectedOption && selectedOption.dataset.stock) {
    const availableStock = Number.parseFloat(selectedOption.dataset.stock);

    if (requestedQty > availableStock) {
      stockInfo.className = "alert alert-warning py-2 mb-0 stock-info";
      stockInfo.innerHTML = `<small><i class="bi bi-exclamation-triangle me-1"></i>Peringatan: Qty melebihi stok tersedia (${availableStock.toLocaleString(
        "id-ID",
        { minimumFractionDigits: 2 }
      )} Kg)</small>`;
      quantityInput.classList.add("is-invalid");
    } else {
      stockInfo.className = "alert alert-info py-2 mb-0 stock-info";
      stockInfo.innerHTML = `<small><i class="bi bi-info-circle me-1"></i>Stok tersedia: ${availableStock.toLocaleString(
        "id-ID",
        { minimumFractionDigits: 2 }
      )} Kg</small>`;
      quantityInput.classList.remove("is-invalid");
    }
    stockInfo.style.display = "block";
  }
}

// 501 functions
function load501Batches() {
  const productSelect = document.getElementById("keluar501_product_id");
  const batchSelect = document.getElementById("keluar501_batch_select");

  if (!productSelect || !batchSelect) return;

  const productId = productSelect.value;
  if (!productId) {
    batchSelect.innerHTML =
      '<option value="">-- Pilih produk terlebih dahulu --</option>';
    batchSelect.disabled = true;
    return;
  }

  // Load batches with 501 > 0
  fetch(`api_get_batches_501.php?product_id=${productId}`)
    .then((response) => response.json())
    .then((data) => {
      batchSelect.innerHTML = '<option value="">-- Pilih Batch --</option>';
      data.forEach((batch) => {
        const option = document.createElement("option");
        option.value = batch.batch_number;
        option.textContent = `${batch.batch_number} (Sisa 501: ${batch.remaining_501} Kg)`;
        option.dataset.sisa501 = batch.remaining_501;
        batchSelect.appendChild(option);
      });
      batchSelect.disabled = false;
    })
    .catch((error) => {
      console.error("Error loading 501 batches:", error);
      batchSelect.innerHTML = '<option value="">Error loading batches</option>';
    });
}

function update501SisaDisplay() {
  const batchSelect = document.getElementById("keluar501_batch_select");
  const sisaDisplay = document.getElementById("keluar501_sisa_display");
  if (!batchSelect || !sisaDisplay) return;
  const selectedOption = batchSelect.options[batchSelect.selectedIndex];
  const raw = (selectedOption?.dataset?.sisa_raw ?? selectedOption?.dataset?.sisa501 ?? '').toString();
  if (raw !== '') {
    sisaDisplay.value = raw.replace('.', ',') + " Kg";
  } else {
    sisaDisplay.value = "0 Kg";
  }
}

function validate501Quantity() {
  const batchSelect = document.getElementById("keluar501_batch_select");
  const quantityInput = document.getElementById("keluar501_quantity");

  if (!batchSelect || !quantityInput) return;

  const selectedOption = batchSelect.options[batchSelect.selectedIndex];
  const requestedQty = Number.parseFloat(quantityInput.value) || 0;

  if (selectedOption && selectedOption.dataset.sisa501) {
    const availableSisa = Number.parseFloat(selectedOption.dataset.sisa501);

    if (requestedQty > availableSisa) {
      quantityInput.classList.add("is-invalid");
      quantityInput.setCustomValidity(`Maksimum ${availableSisa} Kg`);
    } else {
      quantityInput.classList.remove("is-invalid");
      quantityInput.setCustomValidity("");
    }
  }
}

// Utility functions
function resetModalState(modalId) {
  switch (modalId) {
    case "incomingTransactionModal":
      const incomingTitle = document.querySelector(
        "#incomingTransactionModal .modal-title"
      );
      const incomingSubmitBtn = document.getElementById("incomingSubmitButton");
      if (incomingTitle) {
        incomingTitle.innerHTML =
          '<i class="bi bi-plus-circle-fill me-2"></i>Tambah Transaksi Barang Masuk';
      }
      if (incomingSubmitBtn) {
        incomingSubmitBtn.innerHTML =
          '<i class="bi bi-save-fill me-1"></i>Simpan Data';
      }
      break;

    case "outgoingTransactionModal":
      const outgoingTitle = document.querySelector(
        "#outgoingTransactionModal .modal-title"
      );
      const outgoingSubmitBtn = document.getElementById("saveTransactionBtn");
      if (outgoingTitle) {
        outgoingTitle.innerHTML =
          '<i class="bi bi-plus-circle-fill me-2"></i>Tambah Transaksi Barang Keluar';
      }
      if (outgoingSubmitBtn) {
        outgoingSubmitBtn.innerHTML =
          '<i class="bi bi-save-fill me-1"></i>Simpan Transaksi';
      }

      // Clear items using new system
      window.outgoingItems = [];
      const itemsList = document.getElementById("outgoing_items_list");
      if (itemsList) {
        itemsList.innerHTML = `
          <tr>
            <td colspan="6" class="text-center text-muted p-4">
              <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
              <span>Belum ada item yang ditambahkan</span>
              <br>
              <small>Gunakan form di atas untuk menambah item</small>
            </td>
          </tr>
        `;
      }
      
      // Clear embedded 501 items list and UI
      if (window.embedded501Items) {
        window.embedded501Items.length = 0;
      }
      const items501List = document.getElementById('outgoing_items_501_list');
      if (items501List) {
        items501List.innerHTML = `
          <tr>
            <td colspan="5" class="text-center text-muted p-4">
              <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
              <span>Belum ada item 501 yang ditambahkan</span>
            </td>
          </tr>
        `;
      }

      // Reset hidden fields
      const docInput = document.getElementById("original_document_number");
      const itemsJsonInput = document.getElementById("items_json");
      if (docInput) docInput.value = "";
      if (itemsJsonInput) itemsJsonInput.value = "";
      
      break;
  }
}

function handleFormSubmit(event) {
  const form = event.target;
  const submitBtn = form.querySelector('button[type="submit"]');

  if (submitBtn) {
    submitBtn.disabled = true;
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML =
      '<i class="bi bi-hourglass-split me-1"></i>Menyimpan...';

    // Re-enable button after 3 seconds to prevent permanent disable
    setTimeout(() => {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalText;
    }, 3000);
  }
}

function loadOutgoingTransactionData(docNumber, id) {
  // Build URL based on available parameters
  let url = 'api_get_outgoing_details.php?';
  
  if (id) {
    url += `id=${encodeURIComponent(id)}`;
  } else if (docNumber) {
    url += `doc_number=${encodeURIComponent(docNumber)}`;
  } else {
    console.error('No document number or ID provided');
    return;
  }
  
  // Load existing transaction data via AJAX
  fetch(url)
    .then((response) => response.json())
    .then((data) => {
      if (data.error) {
        alert('Error: ' + data.error);
        return;
      }
      
      if (data.main && data.items) {
        // Fill header data using name selectors since form fields don't have IDs
        const form = document.getElementById("outgoingTransactionForm");
        if (form) {
          const dateField = form.querySelector('input[name="transaction_date"]');
          const docField = form.querySelector('input[name="document_number"]');
          const statusField = form.querySelector('select[name="status"]');
          const descField = form.querySelector('textarea[name="description"]');
          const originalDocField = form.querySelector('input[name="original_document_number"]');
          
          if (dateField) dateField.value = data.main.transaction_date;
          if (docField) docField.value = data.main.document_number;
          if (statusField) statusField.value = data.main.status;
          if (descField) descField.value = data.main.description || '';
          if (originalDocField) originalDocField.value = data.main.document_number;
          const createdAtField = form.querySelector('input[name="group_created_at"]');
          if (createdAtField && data.main.created_at) createdAtField.value = data.main.created_at;
        }

        // Clear and populate items using the new system
        window.outgoingItems = [];
        
        data.items.forEach((item, index) => {
          // Standarisasi struktur item agar kompatibel dengan server (qty_kg/qty_sak) dan sertakan ID untuk update
          const newItem = {
            id: item.id,
            product_id: item.product_id,
            product_name: item.product_name,
            sku: item.sku,
            incoming_id: item.incoming_id,
            batch_number: item.batch_number,
            qty_kg: parseFloat(item.qty_kg) || 0,
            qty_sak: parseFloat(item.qty_sak) || 0
          };
          window.outgoingItems.push(newItem);
        });
        
        // Render the list and update JSON
        renderOutgoingItemsList();
        updateOutgoingItemsJSON();
      }
    })
    .catch((error) => {
      console.error("Error loading transaction data:", error);
    });
}

// Function to update items JSON in hidden field
function updateOutgoingItemsJSON() {
  const itemsJsonField = document.getElementById('items_json');
  if (itemsJsonField && window.outgoingItems) {
    itemsJsonField.value = JSON.stringify(window.outgoingItems);
  }
}

// Function to remove item from outgoing items
function removeOutgoingItem(index) {
  if (window.outgoingItems && window.outgoingItems[index]) {
    window.outgoingItems.splice(index, 1);
    renderOutgoingItemsList();
    updateOutgoingItemsJSON();
  }
}

// Function to render the items list
function renderOutgoingItemsList() {
  const itemsList = document.getElementById("outgoing_items_list");
  if (!itemsList || !window.outgoingItems) return;
  
  itemsList.innerHTML = "";
  
  if (window.outgoingItems.length === 0) {
    itemsList.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted p-4">
          <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
          <span>Belum ada item yang ditambahkan</span>
          <br>
          <small>Gunakan form di atas untuk menambah item</small>
        </td>
      </tr>
    `;
    return;
  }
  
  window.outgoingItems.forEach((item, index) => {
    const tr = document.createElement('tr');
    const tdNo = document.createElement('td');
    tdNo.className = 'fw-bold';
    tdNo.textContent = String(index + 1);

    const tdName = document.createElement('td');
    tdName.className = 'text-start';
    const nameDiv = document.createElement('div');
    nameDiv.className = 'fw-semibold';
    nameDiv.textContent = item.product_name || '';
    const skuSmall = document.createElement('small');
    skuSmall.className = 'text-muted';
    skuSmall.textContent = item.sku || '';
    tdName.appendChild(nameDiv);
    tdName.appendChild(skuSmall);

    const tdBatch = document.createElement('td');
    const batchSpan = document.createElement('span');
    batchSpan.className = 'badge bg-info text-white';
    batchSpan.textContent = item.batch_number || '';
    tdBatch.appendChild(batchSpan);

    const tdKg = document.createElement('td');
    const kgSpan = document.createElement('span');
    kgSpan.className = 'badge bg-primary';
      const kgRaw = (item.quantity_kg_display ?? item.qty_kg ?? item.quantity_kg);
  kgSpan.textContent = (kgRaw !== undefined && kgRaw !== null && String(kgRaw) !== '') ? String(kgRaw).replace('.', ',') : '';
    tdKg.appendChild(kgSpan);

    const tdSak = document.createElement('td');
    const sakSpan = document.createElement('span');
    sakSpan.className = 'badge bg-secondary';
      const sakRaw = (item.quantity_sacks_display ?? item.qty_sak ?? item.quantity_sacks);
  sakSpan.textContent = (sakRaw !== undefined && sakRaw !== null && String(sakRaw) !== '') ? String(sakRaw).replace('.', ',') : '';
    tdSak.appendChild(sakSpan);

    const tdAct = document.createElement('td');
    tdAct.className = 'text-center';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-danger btn-sm';
    btn.innerHTML = '<i class="bi bi-trash3"></i>';
    btn.addEventListener('click', () => removeOutgoingItem(index));
    tdAct.appendChild(btn);

    tr.appendChild(tdNo);
    tr.appendChild(tdName);
    tr.appendChild(tdBatch);
    tr.appendChild(tdKg);
    tr.appendChild(tdSak);
    tr.appendChild(tdAct);
    itemsList.appendChild(tr);
  });
}

// Initialize outgoing items when modal is shown for new transaction
const outgoingModalElGlobal = document.getElementById('outgoingTransactionModal');
if (outgoingModalElGlobal) {
  outgoingModalElGlobal.addEventListener('shown.bs.modal', () => {
    // Initialize items array if not exists
    if (!window.outgoingItems) {
      window.outgoingItems = [];
    }
    
    // If no original document number, it's a new transaction
    const docNumberHidden = document.getElementById('original_document_number');
    if (!docNumberHidden || !docNumberHidden.value) {
      // Reset items for new transaction
      window.outgoingItems = [];
      renderOutgoingItemsList();
      updateOutgoingItemsJSON();
    }
  });
}

// Responsive table handling
function handleResponsiveTables() {
  const tables = document.querySelectorAll(".table-responsive");
  tables.forEach((table) => {
    if (table.scrollWidth > table.clientWidth) {
      table.classList.add("table-scroll-indicator");
    }
  });
}

// Call responsive table handler on window resize
window.addEventListener("resize", handleResponsiveTables);
window.addEventListener("load", handleResponsiveTables);

// Export functions for global access
window.removeOutgoingItem = removeOutgoingItem;
window.addOutgoingItem = addOutgoingItem;
document.addEventListener("DOMContentLoaded", () => {
  // Helper function untuk format angka di JS
  function formatAngkaJS(angka) {
    const num = Number.parseFloat(angka);
    if (!isFinite(num)) return "";
    return num.toLocaleString('en-US', { maximumFractionDigits: 6, useGrouping: false });
  }

  // --- LOGIKA UNTUK HALAMAN DAFTAR PRODUK ---
  const productModalEl = document.getElementById("productModal");
  if (productModalEl) {
    const modalForm = document.getElementById("productForm");
    const modalTitle = document.getElementById("productModalLabel");
    const submitButton = document.getElementById("productSubmitButton");
    const productIdInput = document.getElementById("product_id_input");

    productModalEl.addEventListener("show.bs.modal", (event) => {
      const button = event.relatedTarget;
      modalForm.reset();

      if (button.classList.contains("edit-btn")) {
        modalTitle.textContent = "Edit Produk";
        submitButton.innerHTML =
          '<i class="bi bi-save-fill me-2"></i>Simpan Perubahan';
        productIdInput.value = button.dataset.id;
        document.getElementById("product_name").value =
          button.dataset.product_name;
        document.getElementById("sku").value = button.dataset.sku;
        document.getElementById("standard_qty").value =
          button.dataset.standard_qty;
      } else {
        modalTitle.textContent = "Tambah Produk Baru";
        submitButton.innerHTML =
          '<i class="bi bi-plus-circle-fill me-2"></i>Tambah Produk';
        productIdInput.value = "";
      }
    });
  }

  // --- LOGIKA UNTUK MODAL BARANG MASUK ---
  const incomingModalEl = document.getElementById("incomingTransactionModal");
  if (incomingModalEl && !document.getElementById('datalistProductsIncoming')) {
    const modalTitle = document.getElementById("incomingModalLabel");
    const submitButton = document.getElementById("incomingSubmitButton");
    const modalForm = document.getElementById("incomingTransactionForm");

    const transactionIdInput = document.getElementById(
      "incoming_transaction_id"
    );
    const productNameInput = document.getElementById("incoming_product_name");
    const productIdHidden = document.getElementById(
      "incoming_product_id_hidden"
    );
    const productDatalist = document.getElementById("datalistProducts");
    const incomingAutoInput = document.getElementById('item_product_name_incoming');
    const incomingAutoList = document.getElementById('incomingAutocompleteList');
    const qtyKgInput = document.getElementById("incoming_quantity_kg");
    const qtySakInput = document.getElementById("incoming_quantity_sacks");
    const calcKgCheck = document.getElementById("incoming_calc_kg_check");
    const calcSakCheck = document.getElementById("incoming_calc_sak_check");
    const grossWeightInput = document.getElementById("incoming_gross_weight");
    const lotNumberDisplay = document.getElementById(
      "incoming_lot_number_display"
    );

    let currentStdQty = 0;

    function updateStdQty() {
      const selectedOption = Array.from(productDatalist.options).find(
        (opt) => opt.value === productNameInput.value
      );
      if (selectedOption) {
        currentStdQty = Number.parseFloat(selectedOption.dataset.stdqty) || 0;
        productIdHidden.value = selectedOption.dataset.id;
      } else {
        currentStdQty = 0;
        productIdHidden.value = "";
      }
    }
    // Custom autocomplete for incoming item name (substring search)
    function buildIncomingSuggestions(term) {
      if (!incomingAutoList) return;
      const datalist = document.getElementById('datalistProductsIncoming');
      const query = (term || '').toLowerCase();
      incomingAutoList.innerHTML = '';
      if (!query || !datalist) {
        incomingAutoList.style.display = 'none';
        return;
      }
      let count = 0;
      Array.from(datalist.options).forEach((opt) => {
        const name = (opt.value || '').toLowerCase();
        const sku = (opt.dataset.sku || '').toLowerCase();
        if (name.includes(query) || sku.includes(query)) {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
          item.innerHTML = `<span>${opt.value}</span><code class="small">${opt.dataset.sku || ''}</code>`;
          item.addEventListener('click', () => {
            incomingAutoInput.value = opt.value;
            const skuDisplay = document.getElementById('item_sku_display_incoming');
            if (skuDisplay) skuDisplay.textContent = `Kode: ${opt.dataset.sku || ''}`;
            // Also set hidden id for item add section
            const hidden = document.getElementById('item_product_id_hidden');
            if (hidden) hidden.value = opt.dataset.id || '';
            incomingAutoList.style.display = 'none';
          });
          incomingAutoList.appendChild(item);
          count++;
        }
      });
      incomingAutoList.style.display = count > 0 ? 'block' : 'none';
    }

    if (incomingAutoInput) {
      incomingAutoInput.addEventListener('input', () => {
        // Show SKU under input as user types/selects
        const datalist = document.getElementById('datalistProductsIncoming');
        const selected = Array.from(datalist?.options || []).find(
          (opt) => opt.value === incomingAutoInput.value
        );
        const skuDisplay = document.getElementById('item_sku_display_incoming');
        if (selected && skuDisplay) skuDisplay.textContent = `Kode: ${selected.dataset.sku || ''}`;
        else if (skuDisplay) skuDisplay.textContent = '';
        buildIncomingSuggestions(incomingAutoInput.value);
      });
      // Hide when clicking outside
      document.addEventListener('click', (e) => {
        if (!incomingAutoList) return;
        if (!incomingAutoList.contains(e.target) && e.target !== incomingAutoInput) {
          incomingAutoList.style.display = 'none';
        }
      });
    }

    function autoCalculate() {
      const qtyKg = Number.parseFloat((qtyKgInput.value || '').toString().replace(',', '.'));
      const qtySak = Number.parseFloat((qtySakInput.value || '').toString().replace(',', '.'));

      if (calcSakCheck.checked && currentStdQty > 0 && !isNaN(qtyKg)) {
        qtySakInput.value = String(qtyKg / currentStdQty);
      } else if (calcKgCheck.checked && currentStdQty > 0 && !isNaN(qtySak)) {
        qtyKgInput.value = String(qtySak * currentStdQty);
      }
    }

    function calculateLotNumber() {
      const gross = Number.parseFloat(grossWeightInput.value);
      const net = Number.parseFloat(qtyKgInput.value);
      if (!isNaN(gross) && !isNaN(net)) {
        lotNumberDisplay.value = String(gross - net);
      } else {
        lotNumberDisplay.value = "";
      }
    }

    productNameInput.addEventListener("input", updateStdQty);
    qtyKgInput.addEventListener("input", () => {
      autoCalculate();
      calculateLotNumber();
    });
    qtySakInput.addEventListener("input", autoCalculate);
    grossWeightInput.addEventListener("input", calculateLotNumber);

    calcKgCheck.addEventListener("change", function () {
      if (this.checked) {
        calcSakCheck.checked = false;
        qtyKgInput.readOnly = true;
        qtySakInput.readOnly = false;
      } else {
        qtyKgInput.readOnly = false;
      }
      autoCalculate();
    });

    calcSakCheck.addEventListener("change", function () {
      if (this.checked) {
        calcKgCheck.checked = false;
        qtySakInput.readOnly = true;
        qtyKgInput.readOnly = false;
      } else {
        qtySakInput.readOnly = false;
      }
      autoCalculate();
    });

    incomingModalEl.addEventListener("show.bs.modal", (event) => {
      const button = event.relatedTarget;
      modalForm.reset();
      transactionIdInput.value = "";
      qtyKgInput.readOnly = false;
      qtySakInput.readOnly = false;

      if (button && button.classList.contains("edit-btn")) {
        modalTitle.textContent = "Edit Transaksi Barang Masuk";
        submitButton.innerHTML =
          '<i class="bi bi-save-fill me-2"></i>Simpan Perubahan';

        // Mengisi form dengan data dari tombol edit
        transactionIdInput.value = button.dataset.id;
        productIdHidden.value = button.dataset.product_id;
        productNameInput.value = button.dataset.product_name;
        document.getElementById("incoming_transaction_date").value =
          button.dataset.transaction_date;
        document.getElementById("incoming_po_number").value =
          button.dataset.po_number;
        document.getElementById("incoming_supplier").value =
          button.dataset.supplier;
        document.getElementById("incoming_produsen").value =
          button.dataset.produsen;
        document.getElementById("incoming_quantity_kg").value =
          button.dataset.quantity_kg;
        document.getElementById("incoming_quantity_sacks").value =
          button.dataset.quantity_sacks;
        document.getElementById("incoming_document_number").value =
          button.dataset.document_number;
        document.getElementById("incoming_batch_number").value =
          button.dataset.batch_number;
        document.getElementById("incoming_license_plate").value =
          button.dataset.license_plate;
        document.getElementById("incoming_status").value =
          button.dataset.status;

        // Trigger kalkulasi ulang
        updateStdQty();
        calculateLotNumber();
      } else {
        modalTitle.textContent = "Tambah Transaksi Barang Masuk";
        submitButton.innerHTML =
          '<i class="bi bi-plus-circle-fill me-2"></i>Tambah Data';
        // Set tanggal hari ini untuk form tambah baru
        document.getElementById("incoming_transaction_date").value = new Date()
          .toISOString()
          .slice(0, 10);
      }
    });
  }
  // --- LOGIKA UNTUK HALAMAN BARANG KELUAR ---
  const outgoingModalEl = document.getElementById("outgoingTransactionModal");
  if (outgoingModalEl) {
    let outgoingItems = [];
    // Make embedded 501 items available before submit handler so we can allow 501-only saves
    if (!window.embedded501Items) {
      window.embedded501Items = [];
    }
    let embedded501Items = window.embedded501Items;
    let batchCache = {};

    const modalTitle = document.getElementById("outgoingModalLabel");
    const itemProductNameInput = document.getElementById(
      "item_product_name_outgoing"
    );
    const itemProductIdHidden = document.getElementById(
      "item_product_id_hidden"
    );
    const itemProductDatalist = document.getElementById("datalistProductsOutgoing");
    const outgoingAutoList = document.getElementById('outgoingAutocompleteList');
    const itemIncomingSelect = document.getElementById("item_incoming_id");
    const itemQtyKg = document.getElementById("item_quantity_kg");
    const itemQtySacks = document.getElementById("item_quantity_sacks");
    const addItemBtn = document.getElementById("addItemBtn");
    const itemsListTbody = document.getElementById("outgoing_items_list");
    const mainForm = document.getElementById("outgoingTransactionForm");
    const hiddenJsonInput = document.getElementById("items_json");
    const originalDocInput = document.getElementById(
      "original_document_number"
    );

    // PERBAIKAN: Checkbox yang benar untuk barang keluar
    const outgoingCalcKgCheck = document.getElementById(
      "outgoing_calc_kg_check"
    ); // Menghitung KG (readonly) dari SAK
    const outgoingCalcSakCheck = document.getElementById(
      "outgoing_calc_sak_check"
    ); // Menghitung SAK (readonly) dari KG
    let outgoingCurrentStdQty = 0;

    function renderBatchDropdown(productId) {
      itemIncomingSelect.innerHTML =
        '<option value="" selected>-- Pilih Batch --</option>';
      if (batchCache[productId] && batchCache[productId].length > 0) {
        let availableBatches = 0;
        batchCache[productId].forEach((batch) => {
          const reservedQty = outgoingItems
            .filter((item) => item.incoming_id == batch.id)
            .reduce((sum, item) => sum + Number.parseFloat(item.qty_kg), 0);
          const currentSisa =
            Number.parseFloat(batch.sisa_stok_kg) - reservedQty;

          if (currentSisa > 0) {
            const sisa_kg_formatted = formatAngkaJS(currentSisa);
            const sisa_sak = outgoingCurrentStdQty > 0 ? currentSisa / outgoingCurrentStdQty : 0;
            const sisa_sak_formatted = formatAngkaJS(sisa_sak);
            const optionText = `Tgl: ${batch.transaction_date} - Batch: ${
              batch.batch_number || "N/A"
            } (Sisa: ${sisa_kg_formatted} Kg / ${sisa_sak_formatted} Sak)`;
            itemIncomingSelect.innerHTML += `<option value="${
              batch.id
            }" data-sisa_kg="${currentSisa}" data-sisa_sak="${sisa_sak}" data-batch_number="${
              batch.batch_number || ""
            }">${optionText}</option>`;
            availableBatches++;
          }
        });
        if (availableBatches === 0) {
          itemIncomingSelect.innerHTML =
            '<option value="">-- Stok batch habis --</option>';
        }
      } else {
        itemIncomingSelect.innerHTML =
          '<option value="">-- Tidak ada batch tersedia --</option>';
      }
      itemIncomingSelect.disabled = false;
    }

    function handleProductChange() {
      const selectedOption = Array.from(itemProductDatalist.options).find(
        (opt) => opt.value === itemProductNameInput.value
      );
      let productId = "";

      if (selectedOption) {
        productId = selectedOption.dataset.id;
        itemProductIdHidden.value = productId;
        outgoingCurrentStdQty =
          Number.parseFloat(selectedOption.dataset.stdqty) || 0;
      } else {
        itemProductIdHidden.value = "";
        outgoingCurrentStdQty = 0;
      }

      itemIncomingSelect.innerHTML = '<option value="">Memuat...</option>';
      itemIncomingSelect.disabled = true;

      if (!productId) {
        itemIncomingSelect.innerHTML =
          '<option value="">-- Pilih Barang dulu --</option>';
        itemIncomingSelect.disabled = true;
        return;
      }

      if (batchCache[productId]) {
        renderBatchDropdown(productId);
      } else {
        fetch(`api_get_batches.php?product_id=${productId}`)
          .then((response) => response.json())
          .then((data) => {
            batchCache[productId] = data;
            renderBatchDropdown(productId);
          })
          .catch((error) => {
            console.error("Error fetching batches:", error);
            itemIncomingSelect.innerHTML =
              '<option value="">Gagal memuat batch.</option>';
            itemIncomingSelect.disabled = false;
          });
      }
    }

    function buildOutgoingSuggestions(term) {
      if (!outgoingAutoList || !itemProductDatalist) return;
      const query = (term || '').toLowerCase();
      outgoingAutoList.innerHTML = '';
      if (!query) {
        outgoingAutoList.style.display = 'none';
        return;
      }
      let count = 0;
      Array.from(itemProductDatalist.options).forEach((opt) => {
        const name = (opt.value || '').toLowerCase();
        const sku = (opt.dataset.sku || '').toLowerCase();
        if (name.includes(query) || sku.includes(query)) {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
          item.innerHTML = `<span>${opt.value}</span><code class="small">${opt.dataset.sku || ''}</code>`;
          item.addEventListener('click', () => {
            itemProductNameInput.value = opt.value;
            const skuDisplay = document.getElementById('item_sku_display_outgoing');
            if (skuDisplay) skuDisplay.textContent = `Kode: ${opt.dataset.sku || ''}`;
            outgoingAutoList.style.display = 'none';
            // Trigger change handler to load batches etc
            handleProductChange();
          });
          outgoingAutoList.appendChild(item);
          count++;
        }
      });
      outgoingAutoList.style.display = count > 0 ? 'block' : 'none';
    }

    itemProductNameInput.addEventListener("input", () => {
      // Tampilkan kode barang di bawah input saat user memilih/menginput
      const skuDisplay = document.getElementById('item_sku_display_outgoing');
      const selectedOption = Array.from(itemProductDatalist?.options || []).find(
        (opt) => opt.value === itemProductNameInput.value
      );
      if (selectedOption && skuDisplay) {
        skuDisplay.textContent = `Kode: ${selectedOption.dataset.sku || ''}`;
      } else if (skuDisplay) {
        skuDisplay.textContent = '';
      }
      // Build custom suggestions for substring matching
      buildOutgoingSuggestions(itemProductNameInput.value);
      handleProductChange();
    });

    // Hide autocomplete when clicking outside
    document.addEventListener('click', (e) => {
      if (!outgoingAutoList) return;
      if (!outgoingAutoList.contains(e.target) && e.target !== itemProductNameInput) {
        outgoingAutoList.style.display = 'none';
      }
    });

    function autoCalculateOutgoing() {
      const qtyKg = Number.parseFloat((itemQtyKg.value || '').toString().replace(',', '.'));
      const qtySak = Number.parseFloat(itemQtySacks.value);

      if (
        outgoingCalcSakCheck.checked &&
        outgoingCurrentStdQty > 0 &&
        !isNaN(qtyKg)
      ) {
        itemQtySacks.value = String(qtyKg / outgoingCurrentStdQty);
      } else if (
        outgoingCalcKgCheck.checked &&
        outgoingCurrentStdQty > 0 &&
        !isNaN(qtySak)
      ) {
        itemQtyKg.value = String(qtySak * outgoingCurrentStdQty);
      }
    }

    itemQtyKg.addEventListener("input", autoCalculateOutgoing);
    itemQtySacks.addEventListener("input", autoCalculateOutgoing);

    // PERBAIKAN: Logika readonly yang benar
    outgoingCalcKgCheck.addEventListener("change", function () {
      if (this.checked) {
        outgoingCalcSakCheck.checked = false;
        itemQtyKg.readOnly = true; // Kunci input KG
        itemQtySacks.readOnly = false;
      } else {
        itemQtyKg.readOnly = false;
      }
      autoCalculateOutgoing();
    });

    outgoingCalcSakCheck.addEventListener("change", function () {
      if (this.checked) {
        outgoingCalcKgCheck.checked = false;
        itemQtySacks.readOnly = true; // Kunci input SAK
        itemQtyKg.readOnly = false;
      } else {
        itemQtySacks.readOnly = false;
      }
      autoCalculateOutgoing();
    });

    function renderItemsTable() {
      itemsListTbody.innerHTML = "";
      const normalItems = (outgoingItems || []).filter(it => !(Number.parseFloat(it.lot_number || '0') > 0));
      if (normalItems.length === 0) {
        itemsListTbody.innerHTML =
          '<tr><td colspan="6" class="text-center text-muted">Belum ada item yang ditambahkan.</td></tr>';
        // Update summary for empty state
        const summaryEl = document.getElementById('outgoing_items_summary');
        if (summaryEl) {
          summaryEl.innerHTML = '<small class="text-muted">Belum ada item yang ditambahkan</small>';
        }
        return;
      }
      itemsListTbody.innerHTML = '';
      normalItems.forEach((item, index) => {
        // Find the original index in outgoingItems array for delete function
        const originalIndex = outgoingItems.findIndex(originalItem => 
          originalItem.product_id === item.product_id && 
          originalItem.incoming_id === item.incoming_id &&
          originalItem.batch_number === item.batch_number
        );
        
        const mergeIndicator = item.is_merged ? 
          `<span class="badge bg-info text-white ms-1" title="Item ini merupakan gabungan ${item.merge_count} penambahan">
            <i class="bi bi-layers me-1"></i>${item.merge_count}x
          </span>` : '';
        
        const row = `<tr ${item.is_merged ? 'class="table-success"' : ''}>
          <td>${index + 1}</td>
          <td class="text-start">
            <div class="fw-semibold text-primary d-flex align-items-center">
              ${item.product_name}
              ${mergeIndicator}
            </div>
            <div class="d-flex align-items-center gap-2 mt-1">
              <small class="text-muted bg-light px-2 py-1 rounded">Kode: ${item.sku || "N/A"}</small>
              <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" data-copy="${item.sku || ""}" title="Copy Kode">
                <i class="bi bi-clipboard"></i>
              </button>
            </div>
          </td>
          <td><div class="d-flex align-items-center gap-1"><span>${item.batch_number}</span><button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" data-copy="${item.batch_number}" title="Copy Batch"><i class="bi bi-clipboard"></i></button></div></td>
          <td>
            <div class="d-flex align-items-center gap-1">
              <span class="badge bg-primary fs-6">${formatAngkaJS(item.qty_kg)}</span>
              <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" data-copy="${formatAngkaJS(item.qty_kg)}" title="Copy Kg"><i class="bi bi-clipboard"></i></button>
            </div>
          </td>
          <td>
            <div class="d-flex align-items-center gap-1">
              <span class="badge bg-secondary fs-6">${formatAngkaJS(item.qty_sak)}</span>
              <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" data-copy="${formatAngkaJS(item.qty_sak)}" title="Copy Sak"><i class="bi bi-clipboard"></i></button>
            </div>
          </td>
          <td>
            <button type="button" class="btn btn-danger btn-sm" data-original-index="${originalIndex}" title="Hapus Item"><i class="bi bi-trash3-fill"></i></button>
          </td>
        </tr>`;
        itemsListTbody.innerHTML += row;
      });
    }

    // Function to update hidden JSON field
    function updateItemsJSON() {
      if (hiddenJsonInput) {
        hiddenJsonInput.value = JSON.stringify(outgoingItems);
      }
    }
    
    // Function to update items summary
    function updateItemsSummary() {
      const normalItems = (outgoingItems || []).filter(it => !(Number.parseFloat(it.lot_number || '0') > 0));
      const mergedItems = normalItems.filter(it => it.is_merged);
      const totalQtyKg = normalItems.reduce((sum, item) => sum + Number.parseFloat(item.qty_kg || 0), 0);
      const totalQtySak = normalItems.reduce((sum, item) => sum + Number.parseFloat(item.qty_sak || 0), 0);
      
      // Update summary display if element exists
      const summaryEl = document.getElementById('outgoing_items_summary');
      if (summaryEl) {
        summaryEl.innerHTML = `
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <small class="text-muted">
                ${normalItems.length} item${normalItems.length !== 1 ? 's' : ''} total
                ${mergedItems.length > 0 ? ` (${mergedItems.length} digabungkan)` : ''}
              </small>
            </div>
            <div>
              <small class="text-muted">
                Total: <strong>${formatAngkaJS(totalQtyKg)} Kg</strong> / <strong>${formatAngkaJS(totalQtySak)} Sak</strong>
              </small>
            </div>
          </div>
        `;
      }
    }

    addItemBtn.addEventListener("click", () => {
      const productOption = Array.from(itemProductDatalist.options).find(
        (opt) => opt.value === itemProductNameInput.value
      );
      const batchOption =
        itemIncomingSelect.options[itemIncomingSelect.selectedIndex];
      const qtyKgDiminta = Number.parseFloat((itemQtyKg.value || '').toString().replace(',', '.'));

      if (
        !productOption ||
        !productOption.dataset.id ||
        !batchOption.value ||
        !batchOption.dataset.sisa_kg
      ) {
        Swal.fire(
          "Oops...",
          "Harap pilih Nama Barang dan Batch yang valid.",
          "warning"
        );
        return;
      }
      if (isNaN(qtyKgDiminta) || qtyKgDiminta <= 0) {
        Swal.fire("Oops...", "Harap masukkan Qty (Kg) yang valid.", "warning");
        return;
      }

      const sisaStok = Number.parseFloat(batchOption.dataset.sisa_kg);
      let qtyToAdd = qtyKgDiminta;

      if (qtyKgDiminta > sisaStok) {
        qtyToAdd = sisaStok;
        const kekurangan = qtyKgDiminta - sisaStok;
        Swal.fire({
          title: "Stok Tidak Cukup",
          html: `<div class="text-start small">
                   <div>Hanya <strong>${formatAngkaJS(sisaStok)} Kg</strong> yang ditambahkan.</div>
                   <div class="mt-2">Kekurangan:</div>
                   <div class="input-group input-group-sm mt-1">
                     <input id="copyKekurangan" class="form-control" type="text" value="${formatAngkaJS(kekurangan)}" readonly>
                     <button type="button" class="btn btn-outline-primary" id="btnCopyKekurangan"><i class="bi bi-clipboard me-1"></i>Copy</button>
                   </div>
                 </div>`,
          icon: "info",
          showConfirmButton: true,
          confirmButtonText: "OK",
          didOpen: () => {
            const btn = document.getElementById('btnCopyKekurangan');
            const inp = document.getElementById('copyKekurangan');
            if (btn && inp && navigator.clipboard) {
              btn.addEventListener('click', () => {
                navigator.clipboard.writeText(inp.value).then(() => {
                  btn.innerHTML = '<i class="bi bi-clipboard-check me-1"></i>Tersalin';
                  setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copy'; }, 1500);
                });
              });
            }
          }
        });
      }

      if (qtyToAdd > 0) {
        const stdQty = Number.parseFloat(productOption.dataset.stdqty);
        const qtySakToAdd = stdQty > 0 ? qtyToAdd / stdQty : 0;

        // Cek apakah sudah ada item dengan product_id dan batch_number yang sama
        const existingItemIndex = outgoingItems.findIndex(item => 
          item.product_id === productOption.dataset.id && 
          item.incoming_id === batchOption.value &&
          item.batch_number === batchOption.dataset.batch_number
        );

        if (existingItemIndex !== -1) {
          // Jika sudah ada, gabungkan qty
          const existingItem = outgoingItems[existingItemIndex];
          const newQtyKg = Number.parseFloat(existingItem.qty_kg) + qtyToAdd;
          const newQtySak = Number.parseFloat(existingItem.qty_sak) + Number.parseFloat(qtySakToAdd.toFixed(2));
          const mergeCount = (existingItem.merge_count || 1) + 1;
          
          outgoingItems[existingItemIndex] = {
            ...existingItem,
            qty_kg: newQtyKg,
            qty_sak: Number.parseFloat(newQtySak.toFixed(2)),
            merge_count: mergeCount,
            is_merged: true
          };
          
          // Show notification untuk merge
          Swal.fire({
            title: 'Item Digabungkan!',
            html: `<div class="text-start">
              <strong>${productOption.value}</strong><br>
              <small class="text-muted">Batch: ${batchOption.dataset.batch_number}</small><br>
              <small class="text-info"><i class="bi bi-layers me-1"></i>Penggabungan ke-${mergeCount}</small><br><br>
              Qty baru: <strong>${formatAngkaJS(newQtyKg)} Kg</strong> / <strong>${formatAngkaJS(newQtySak)} Sak</strong>
            </div>`,
            icon: 'success',
            timer: 2500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
          });
        } else {
          // Jika belum ada, tambah item baru
          outgoingItems.push({
            product_id: productOption.dataset.id,
            product_name: productOption.value,
            sku: productOption.dataset.sku,
            incoming_id: batchOption.value,
            batch_number: batchOption.dataset.batch_number,
            qty_kg: qtyToAdd,
            qty_sak: Number.parseFloat(qtySakToAdd.toFixed(2)),
            merge_count: 1,
            is_merged: false
          });
        }
        
        renderItemsTable();
        updateItemsJSON(); // Update hidden JSON field
        updateItemsSummary(); // Update items summary
        renderBatchDropdown(productOption.dataset.id);
      }

      itemQtyKg.value = "";
      itemQtySacks.value = "";
      itemProductNameInput.value = "";
      outgoingCalcKgCheck.checked = false;
      outgoingCalcSakCheck.checked = false;
      itemQtyKg.readOnly = false;
      itemQtySacks.readOnly = false;
      itemIncomingSelect.innerHTML =
        '<option value="">-- Pilih Barang dulu --</option>';
      itemIncomingSelect.disabled = true;
    });

    itemsListTbody.addEventListener("click", (e) => {
      const copyBtn = e.target.closest('button[data-copy]');
      if (copyBtn && navigator.clipboard) {
        const val = copyBtn.getAttribute('data-copy') || '';
        navigator.clipboard.writeText(val).then(() => {
          const prev = copyBtn.innerHTML;
          copyBtn.innerHTML = '<i class="bi bi-clipboard-check"></i>';
          setTimeout(() => { copyBtn.innerHTML = prev; }, 1200);
        });
        return;
      }
      const deleteButton = e.target.closest("button");
      if (deleteButton && deleteButton.dataset.originalIndex) {
        const indexToRemove = Number.parseInt(deleteButton.dataset.originalIndex, 10);
        const itemToRemove = outgoingItems[indexToRemove];
        
        // Show confirmation dialog
        Swal.fire({
          title: 'Hapus Item?',
          html: `<div class="text-start">
            <strong>${itemToRemove.product_name}</strong><br>
            <small class="text-muted">Batch: ${itemToRemove.batch_number}</small><br>
            Qty: <strong>${formatAngkaJS(itemToRemove.qty_kg)} Kg</strong>
          </div>`,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#dc3545',
          cancelButtonColor: '#6c757d',
          confirmButtonText: 'Ya, Hapus!',
          cancelButtonText: 'Batal'
        }).then((result) => {
          if (result.isConfirmed) {
            outgoingItems.splice(indexToRemove, 1);
            renderItemsTable();
            updateItemsJSON();
            updateItemsSummary();
            renderBatchDropdown(itemToRemove.product_id);
            
            Swal.fire({
              title: 'Terhapus!',
              text: 'Item berhasil dihapus dari daftar.',
              icon: 'success',
              timer: 1500,
              showConfirmButton: false
            });
          }
        });
      }
    });

    outgoingModalEl.addEventListener("show.bs.modal", (event) => {
      const button = event.relatedTarget;
      mainForm.reset();
      outgoingItems = [];
      batchCache = {};
      originalDocInput.value = "";
      renderItemsTable();
      updateItemsJSON();
      updateItemsSummary();
      itemIncomingSelect.innerHTML =
        '<option value="">-- Pilih Barang dulu --</option>';
      itemIncomingSelect.disabled = true;
      itemQtyKg.readOnly = false;
      itemQtySacks.readOnly = false;
      outgoingCalcKgCheck.checked = false;
      outgoingCalcSakCheck.checked = false;

      if (button && button.classList.contains("edit-btn")) {
        modalTitle.innerHTML =
          '<i class="bi bi-pencil-square me-2"></i>Edit Transaksi Barang Keluar';

        // Ambil data-id dan doc number
        const id = button.dataset.id;
        const docNumber = (button.dataset.docNumber || '').trim();

        // Simpan nomor dokumen ke hidden jika tersedia (berguna saat submit)
        if (docNumber) {
          originalDocInput.value = docNumber;
        }

        let requestUrl = "";
        // Jika doc_number ada, kirim juga anchor_id=id (bila tersedia) untuk mengikat ke kelompok inputan awal
        if (docNumber) {
          const anchorPart = id ? `&anchor_id=${encodeURIComponent(id)}` : '';
          requestUrl = `api_get_outgoing_details.php?doc_number=${encodeURIComponent(docNumber)}${anchorPart}`;
        } else if (id) {
          requestUrl = `api_get_outgoing_details.php?id=${encodeURIComponent(id)}`;
        } else {
          bootstrap.Modal.getInstance(outgoingModalEl).hide();
          Swal.fire(
            "Aksi Dibatalkan",
            "Transaksi tanpa ID atau nomor dokumen tidak dapat diedit.",
            "error"
          );
          return;
        }

        fetch(requestUrl)
          .then((response) => response.json())
          .then((data) => {
            if (data.error) {
              Swal.fire("Error!", data.error, "error");
              bootstrap.Modal.getInstance(outgoingModalEl).hide();
              return;
            }
            document.querySelector(
              '#outgoingTransactionForm input[name="transaction_date"]'
            ).value = data.main.transaction_date;
            document.querySelector(
              '#outgoingTransactionForm input[name="document_number"]'
            ).value = data.main.document_number || docNumber || "";
            document.querySelector(
              '#outgoingTransactionForm textarea[name="description"]'
            ).value = data.main.description;
            document.querySelector(
              '#outgoingTransactionForm select[name="status"]'
            ).value = data.main.status;

            // Pastikan anchor grup tersetel agar item tambahan tergabung dalam kelompok awal
            const createdAtHidden = document.querySelector('#outgoingTransactionForm input[name="group_created_at"]');
            if (createdAtHidden) {
              createdAtHidden.value = data.main.created_at || '';
            }

            // Jika original_document_number belum diisi (mis. edit via ID), set dari data
            const originalDocHidden = document.querySelector('#outgoingTransactionForm input[name="original_document_number"]');
            if (originalDocHidden && !originalDocHidden.value) {
              originalDocHidden.value = data.main.document_number || '';
            }
            // Split items: normal vs 501 (lot_number > 0)
            const normal = [];
            const only501 = [];
            (data.items || []).forEach((it) => {
              const lot = Number.parseFloat(it.lot_number || '0');
              if (lot > 0) {
                only501.push({
                  product_id: it.product_id,
                  product_name: it.product_name,
                  sku: it.sku,
                  incoming_id: it.incoming_id,
                  batch_number: it.batch_number,
                  qty_kg: 0,
                  qty_sak: 0,
                  lot_number: lot
                });
              } else {
                normal.push({ ...it });
              }
            });
            outgoingItems = normal.map((item, index) => ({ 
              ...item, 
              merge_count: item.merge_count || 1,
              is_merged: item.is_merged || false
            }));
            renderItemsTable();
            updateItemsJSON();
            updateItemsSummary();
            // Fill embedded 501 tab list without breaking the shared reference
            embedded501Items.length = 0;
            Array.prototype.push.apply(embedded501Items, only501);
            renderEmbedded501List();
          })
          .catch((err) => {
            console.error("Fetch Error:", err);
            Swal.fire("Error!", "Gagal memuat detail transaksi.", "error");
          });
      } else {
        modalTitle.innerHTML =
          '<i class="bi bi-plus-circle-fill me-2"></i>Tambah Transaksi Barang Keluar';
        document.querySelector(
          '#outgoingTransactionForm input[name="transaction_date"]'
        ).value = new Date().toISOString().slice(0, 10);
      }
    });

    mainForm.addEventListener("submit", (e) => {
      const totalItemsCount = (outgoingItems?.length || 0) + (embedded501Items?.length || 0);
      if (totalItemsCount === 0) {
        e.preventDefault();
        Swal.fire(
          "Daftar Kosong!",
          "Harap tambahkan minimal satu item (termasuk 501).",
          "warning"
        );
        return;
      }
      // Keep original behavior: set JSON from main items; 501 items will be merged by the later submit handler
      hiddenJsonInput.value = JSON.stringify(outgoingItems);
    });

    // Add embedded 501 handlers inside outgoing modal
    const productName501 = document.getElementById('keluar501_product_name_embedded');
    const productId501Hidden = document.getElementById('keluar501_product_id_embedded');
    const skuDisplay501 = document.getElementById('keluar501_sku_display_embedded');
    const datalistOutgoing = document.getElementById('datalistProductsOutgoing');
    const batchSelect501 = document.getElementById('keluar501_batch_select_embedded');
    const sisaDisplay501 = document.getElementById('keluar501_sisa_display_embedded');
    const qty501Input = document.getElementById('keluar501_quantity_embedded');

    const batches501Cache = {};

    function update501OptionLabel(opt) {
      if (!opt) return;
      const date = opt.dataset.date || '';
      const batchNum = opt.dataset.batch_number || '';
      const raw = (opt.dataset.sisa_raw ?? opt.dataset.sisa ?? '').toString();
      // Display exactly as entered/original (preserve commas/points and scale)
      const display = raw !== '' ? raw.replace('.', ',') : '0';
      opt.textContent = `Tgl: ${date} - Batch: ${batchNum || 'N/A'} (Sisa 501: ${display} Kg)`;
    }

    function populate501OptionsEmbedded(productId, data) {
      if (!batchSelect501) return;
      batchSelect501.innerHTML = '<option value="" selected disabled>-- Pilih Batch --</option>';
      if (data && data.length > 0) {
        data.forEach((batch) => {
          const raw = (batch.sisa_lot_number ?? batch.remaining_501 ?? '0').toString();
          const sisa = Number.parseFloat(raw.replace(',', '.'));
          if (sisa > 0) {
            const option = document.createElement('option');
            option.value = batch.id;
            option.dataset.date = batch.transaction_date || '';
            option.dataset.batch_number = batch.batch_number || '';
            option.dataset.sisa = String(sisa);
            option.dataset.sisa_raw = raw; // keep original formatting
            update501OptionLabel(option);
            batchSelect501.appendChild(option);
          }
        });
      } else {
        batchSelect501.innerHTML = '<option value="">-- Tidak ada batch dengan sisa 501 --</option>';
      }
      batchSelect501.disabled = false;
    }

    function load501BatchesEmbedded(productId) {
      if (!productId || !batchSelect501) return;
      batchSelect501.innerHTML = '<option value="">Memuat batch...</option>';
      batchSelect501.disabled = true;
      sisaDisplay501 && (sisaDisplay501.value = '0.00');
      qty501Input && (qty501Input.value = '');
      if (batches501Cache[productId]) {
        populate501OptionsEmbedded(productId, batches501Cache[productId]);
        return;
      }
      fetch(`api_get_batches_501.php?product_id=${productId}`)
        .then((r) => r.json())
        .then((data) => {
          batches501Cache[productId] = data || [];
          populate501OptionsEmbedded(productId, batches501Cache[productId]);
        })
        .catch(() => {
          batchSelect501.innerHTML = '<option value="">Gagal memuat batch</option>';
          batchSelect501.disabled = false;
        });
    }

    if (productName501 && datalistOutgoing) {
      productName501.addEventListener('input', () => {
        const opt = Array.from(datalistOutgoing.options).find(o => o.value === productName501.value);
        if (opt) {
          if (productId501Hidden) productId501Hidden.value = opt.dataset.id || '';
          if (skuDisplay501) skuDisplay501.textContent = opt.dataset.sku ? `Kode: ${opt.dataset.sku}` : '';
          load501BatchesEmbedded(opt.dataset.id);
        } else {
          if (productId501Hidden) productId501Hidden.value = '';
          if (skuDisplay501) skuDisplay501.textContent = '';
          if (batchSelect501) {
            batchSelect501.innerHTML = '<option value="">-- Pilih produk terlebih dahulu --</option>';
            batchSelect501.disabled = true;
          }
        }
      });
    }

    if (batchSelect501 && sisaDisplay501 && qty501Input) {
      batchSelect501.addEventListener('change', function() {
        const sel = this.options[this.selectedIndex];
        const raw = sel?.dataset?.sisa_raw ?? sel?.dataset?.sisa ?? '';
        const sisa = Number.parseFloat((raw || '0').toString().replace(',', '.'));
        // Display exactly as original (use comma in UI)
        sisaDisplay501.value = raw ? raw.replace('.', ',') : '0';
        qty501Input.value = '';
        qty501Input.max = isFinite(sisa) ? String(sisa) : '';
      });
      qty501Input.addEventListener('input', function() {
        // Hanya clamp nilai input agar tidak melebihi sisa, tapi jangan mengurangi sisa/label sampai klik Tambah
        const sel = batchSelect501?.options[batchSelect501.selectedIndex];
        if (!sel) return;
        const base = Number.parseFloat((sel.dataset.sisaBase || sel.dataset.sisa || '0').toString().replace(',', '.')) || 0;
        let val = Number.parseFloat((this.value || '0').toString().replace(',', '.')) || 0;
        if (base > 0 && val > base) {
          this.value = String(base).replace('.', ',');
        }
      });
    }

    // Note: Hook submit logic for 501 moved to merge embedded items section below

    // 501 embedded list elements
    const addItem501Btn = document.getElementById('addItem501OutgoingBtn');
    const items501Tbody = document.getElementById('outgoing_items_501_list');

    function renderEmbedded501List() {
      if (!items501Tbody) return;
      if (!embedded501Items.length) {
        items501Tbody.innerHTML = `
          <tr>
            <td colspan=\"5\" class=\"text-center text-muted p-4\">
              <i class=\"bi bi-inbox display-6 d-block mb-2 opacity-50\"></i>
              <span>Belum ada item 501 yang ditambahkan</span>
            </td>
          </tr>
        `;
        return;
      }
      items501Tbody.innerHTML = '';
      embedded501Items.forEach((it, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td class=\"fw-bold\">${idx + 1}</td>
          <td class=\"text-start\">${it.product_name}<br><small class=\"text-muted\">${it.sku || ''}</small></td>
          <td><span class=\"badge bg-info text-white\">${it.batch_number}</span></td>
          <td class=\"fw-bold text-success\">${formatAngkaJS(it.lot_number)} Kg</td>
          <td class=\"text-center\">
            <div class=\"btn-group btn-group-sm\" role=\"group\">
              <button type=\"button\" class=\"btn btn-outline-secondary\" data-copy=\"sku\" data-value=\"${it.sku || ''}\" title=\"Salin Kode Barang\"><i class=\"bi bi-clipboard\"></i> Kode</button>
              <button type=\"button\" class=\"btn btn-outline-secondary\" data-copy=\"batch\" data-value=\"${it.batch_number}\" title=\"Salin Batch\"><i class=\"bi bi-clipboard\"></i> Batch</button>
              <button type=\"button\" class=\"btn btn-outline-secondary\" data-copy=\"qty\" data-value=\"${formatAngkaJS(it.lot_number)}\" title=\"Salin Qty Kg\"><i class=\"bi bi-clipboard\"></i> Kg</button>
            </div>
          </td>
          <td class=\"text-center\">\n            <button type=\"button\" class=\"btn btn-outline-danger btn-sm\" data-index=\"${idx}\"><i class=\"bi bi-trash3\"></i></button>\n          </td>
        `;
        items501Tbody.appendChild(tr);
      });
    }

    if (addItem501Btn) {
      addItem501Btn.addEventListener('click', () => {
        const pid = productId501Hidden?.value;
        const sel = batchSelect501?.options[batchSelect501.selectedIndex];
        const qty501 = Number.parseFloat(qty501Input?.value || '0');
        if (!pid) { Swal?.fire?.('Oops...', 'Pilih Nama Barang 501 terlebih dahulu.', 'warning'); return; }
        if (!sel || !sel.value) { Swal?.fire?.('Oops...', 'Pilih Batch 501 terlebih dahulu.', 'warning'); return; }
        if (!(qty501 > 0)) { Swal?.fire?.('Oops...', 'Masukkan jumlah 501 (Kg) yang valid.', 'warning'); return; }

        const sisaBefore = Number.parseFloat(sel.dataset.sisa || '0');
        if (qty501 > sisaBefore) { Swal?.fire?.('Oops...', `Maksimum ${sisaBefore.toFixed(2)} Kg.`, 'warning'); return; }

        const productOpt = Array.from(datalistOutgoing?.options || []).find(o => o.dataset.id === pid);
        const item = {
          product_id: pid,
          product_name: productOpt?.value || '',
          sku: productOpt?.dataset?.sku || '',
          incoming_id: sel.value,
          batch_number: sel.dataset.batch_number || '',
          qty_kg: 0,
          qty_sak: 0,
          lot_number: qty501
        };
        embedded501Items.push(item);
        renderEmbedded501List();

        // Decrement sisa on selected option after Add (not on typing)
        const base = Number.parseFloat((sel.dataset.sisaBase || sel.dataset.sisa || '0').toString().replace(',', '.')) || 0;
        let remain = Math.max(0, base - qty501);
        remain = Math.round(remain * 1000) / 1000;
        sel.dataset.sisaBase = String(remain);
        sel.dataset.sisaBaseRaw = String(remain);
        sel.dataset.sisa = String(remain);
        sel.dataset.sisa_raw = String(remain);
        update501OptionLabel(sel);
        if (sisaDisplay501) sisaDisplay501.value = String(remain).replace('.', ',');
        if (qty501Input) { qty501Input.value = ''; qty501Input.max = String(remain); }
        if (remain <= 0) { sel.disabled = true; }
      });

      if (items501Tbody) {
        items501Tbody.addEventListener('click', (e) => {
          const copyBtn = e.target.closest('button[data-copy]');
          if (copyBtn) {
            const text = copyBtn.dataset.value || '';
            if (text) navigator.clipboard?.writeText(text);
            return;
          }
          const btn = e.target.closest('button[data-index]');
          if (!btn) return;
          const idx = Number.parseInt(btn.dataset.index, 10);
          if (idx >= 0) {
            const removed = embedded501Items.splice(idx, 1)[0];
            renderEmbedded501List();
            // Restore sisa back to option if still present in list
            const opt = Array.from(batchSelect501?.options || []).find(o => o.value === removed.incoming_id);
            if (opt) {
              const baseRaw = (opt.dataset.sisa_raw ?? opt.dataset.sisa ?? '0').toString();
              const base = Number.parseFloat(baseRaw.replace(',', '.')) || 0;
              const add = Number.parseFloat((removed.lot_number || '0').toString().replace(',', '.')) || 0;
              const restored = base + add;
              opt.dataset.sisa = String(restored);
              opt.dataset.sisa_raw = (baseRaw && baseRaw.includes(',')) ? String(restored).replace('.', ',') : String(restored);
              opt.disabled = false;
              update501OptionLabel(opt);
              // If this option is currently selected, update display
              if (batchSelect501 && batchSelect501.value === opt.value) {
                if (sisaDisplay501) sisaDisplay501.value = (opt.dataset.sisa_raw || String(restored)).replace('.', ',');
                if (qty501Input) qty501Input.max = String(restored);
              }
            }
          }
        });
      }
    }

    // Merge embedded 501 items into items_json on submit
    const outgoingForm = document.getElementById('outgoingTransactionForm');
    const itemsJsonHidden = document.getElementById('items_json');
    if (outgoingForm && itemsJsonHidden) {
      outgoingForm.addEventListener('submit', (e) => {
        try {
          const list = JSON.parse(itemsJsonHidden.value || '[]');
          
          if (embedded501Items.length) {
            const merged = list.concat(embedded501Items);
            itemsJsonHidden.value = JSON.stringify(merged);
            
            // Clear embedded501Items after merging to avoid duplication
            embedded501Items.length = 0;
            renderEmbedded501List();
          }
        } catch (err) { 
          console.error('Error merging 501 items:', err);
        }
      });
    }
  }
  // --- LOGIKA UNTUK HALAMAN KARTU STOK (STOCK JALUR) ---
  const stockJalurPage = document.getElementById("stockJalurPage");
  if (stockJalurPage) {
    const productNameInput = document.getElementById("product_name_kartu_stok");
    const productDatalist = document.getElementById(
      "datalistProductsKartuStok"
    );
    const productIdHidden = document.getElementById(
      "product_id_kartu_stok_hidden"
    );
    const batchSelect = document.getElementById("incoming_id");
    const selectedBatchId = stockJalurPage.dataset.selectedBatchId;

    function fetchAndPopulateBatches(productId) {
      batchSelect.innerHTML = '<option value="">Memuat batch...</option>';
      batchSelect.disabled = true;

      if (!productId) {
        batchSelect.innerHTML =
          '<option value="">-- Pilih Nama Barang dulu --</option>';
        return;
      }

      fetch(`api_get_batches.php?product_id=${productId}`)
        .then((response) => response.json())
        .then((data) => {
          batchSelect.innerHTML =
            '<option value="" selected disabled>-- Pilih Batch --</option>';
          if (data.length > 0) {
            data.forEach((batch) => {
              const optionText = `Tgl: ${batch.transaction_date} - Batch: ${
                batch.batch_number || "N/A"
              } - Supplier: ${batch.supplier || "-"}`;
              batchSelect.innerHTML += `<option value="${batch.id}">${optionText}</option>`;
            });
            batchSelect.disabled = false;

            if (selectedBatchId) {
              batchSelect.value = selectedBatchId;
            }
          } else {
            batchSelect.innerHTML =
              '<option value="">-- Tidak ada batch tersedia --</option>';
          }
        })
        .catch((error) => {
          console.error("Error fetching batches:", error);
          batchSelect.innerHTML = '<option value="">Gagal memuat</option>';
        });
    }

    productNameInput.addEventListener("input", function () {
      const selectedOption = Array.from(productDatalist.options).find(
        (opt) => opt.value === this.value
      );
      if (selectedOption) {
        const productId = selectedOption.dataset.id;
        productIdHidden.value = productId;
        fetchAndPopulateBatches(productId);
      } else {
        productIdHidden.value = "";
        batchSelect.innerHTML =
          '<option value="">-- Pilih Nama Barang dulu --</option>';
        batchSelect.disabled = true;
      }
    });

    // Cek saat halaman dimuat
    if (productNameInput.value) {
      const selectedOption = Array.from(productDatalist.options).find(
        (opt) => opt.value === productNameInput.value
      );
      if (selectedOption) {
        const productId = selectedOption.dataset.id;
        productIdHidden.value = productId; // Pastikan hidden input terisi
        fetchAndPopulateBatches(productId);
      }
    }
  }

  const keluarkan501ModalEl = document.getElementById('keluarkan501Modal');
  // Izinkan halaman untuk menonaktifkan handler global 501 via data-no-global-501="1"
  if (keluarkan501ModalEl && !keluarkan501ModalEl.dataset.noGlobal501) {
    const productSelect501 = document.getElementById("product_id_501");
    const batchSelect501 = document.getElementById("batch_id_501");
    const quantityInput501 = document.getElementById("quantity_501");

    productSelect501.addEventListener("change", function () {
      const productId = this.value;
      batchSelect501.innerHTML = '<option value="">Memuat batch...</option>';
      batchSelect501.disabled = true;
      quantityInput501.value = "";

      if (!productId) {
        batchSelect501.innerHTML =
          '<option value="">-- Pilih Nama Barang di atas --</option>';
        batchSelect501.disabled = false;
        return;
      }

      fetch(`api_get_batches_501.php?product_id=${productId}`)
        .then((response) => response.json())
        .then((data) => {
          batchSelect501.innerHTML =
            '<option value="" selected disabled>-- Pilih Batch --</option>';
          if (data && data.length > 0) {
            data.forEach((batch) => {
              const raw = (batch.sisa_lot_number ?? '').toString();
              const date = batch.transaction_date || '';
              const bnum = batch.batch_number || 'N/A';
              const optionText = `Tgl: ${date} - Batch: ${bnum} (Sisa 501: ${raw.replace('.', ',')} Kg)`;
              batchSelect501.innerHTML += `<option value="${batch.id}" data-date="${date}" data-batch_number="${bnum}" data-sisa="${Number.parseFloat(raw.replace(',', '.'))}" data-sisa-raw="${raw}" data-sisa-base="${Number.parseFloat(raw.replace(',', '.'))}" data-sisa-base-raw="${raw}">${optionText}</option>`;
            });
          } else {
            batchSelect501.innerHTML =
              '<option value="">-- Tidak ada batch dengan sisa 501 --</option>';
          }
          batchSelect501.disabled = false;
        });
    });

    // Otomatis isi jumlah 501 dengan sisa maksimalnya saat batch dipilih
    batchSelect501.addEventListener("change", function () {
      const selectedOption = this.options[this.selectedIndex];
      if (selectedOption) {
        // Reset current sisa to base when switching batch
        selectedOption.dataset.sisa = selectedOption.dataset.sisaBase || selectedOption.dataset.sisa;
        selectedOption.dataset.sisa_raw = selectedOption.dataset.sisaBaseRaw || selectedOption.dataset.sisa_raw;
        if (selectedOption.dataset.sisa_raw) {
          quantityInput501.value = selectedOption.dataset.sisa_raw.replace('.', ',');
        } else if (selectedOption.dataset.sisa) {
          quantityInput501.value = String(selectedOption.dataset.sisa).replace('.', ',');
        }
      }
      update501SisaDisplay();
    });
   }
});
 
 function initializeGlobalProductAutocomplete() {
  const CONFIGS = [
    {
      inputId: 'item_product_name_incoming',
      datalistId: 'datalistProductsIncoming',
      hiddenId: 'item_product_id_hidden',
      skuDisplayId: 'item_sku_display_incoming'
    },
    {
      inputId: 'item501_product_name',
      datalistId: 'datalistProductsIncoming',
      hiddenId: 'item501_product_id_hidden',
      skuDisplayId: 'item501_sku_display'
    },
    {
      inputId: 'item_product_name_outgoing',
      datalistId: 'datalistProductsOutgoing',
      hiddenId: 'item_product_id_hidden',
      skuDisplayId: 'item_sku_display_outgoing'
    },
    {
      inputId: 'keluar501_product_name_embedded',
      datalistId: 'datalistProductsOutgoing',
      hiddenId: 'keluar501_product_id_embedded',
      skuDisplayId: 'keluar501_sku_display_embedded'
    }
  ];

  CONFIGS.forEach((cfg) => {
    const input = document.getElementById(cfg.inputId);
    const datalist = document.getElementById(cfg.datalistId);
    if (!input || !datalist) return;
    const hidden = cfg.hiddenId ? document.getElementById(cfg.hiddenId) : null;
    const skuDisplay = cfg.skuDisplayId ? document.getElementById(cfg.skuDisplayId) : null;

    const dropdown = document.createElement('div');
    dropdown.className = 'list-group shadow';
    Object.assign(dropdown.style, {
      position: 'fixed',
      zIndex: '2147483647',
      display: 'none',
      maxHeight: '300px',
      overflowY: 'auto',
      background: '#fff',
      borderRadius: '0.375rem',
      border: '1px solid rgba(0,0,0,.125)'
    });
    document.body.appendChild(dropdown);

    let currentIndex = -1;
    let currentItems = [];

    function positionDropdown() {
      const rect = input.getBoundingClientRect();
      dropdown.style.left = `${Math.round(rect.left + window.scrollX)}px`;
      dropdown.style.top = `${Math.round(rect.bottom + window.scrollY)}px`;
      dropdown.style.width = `${Math.round(rect.width)}px`;
    }

    function clearDropdown() {
      dropdown.innerHTML = '';
      currentItems = [];
      currentIndex = -1;
    }

    function hideDropdown() {
      dropdown.style.display = 'none';
      clearDropdown();
    }

    function showSuggestions(query) {
      clearDropdown();
      const q = (query || '').trim().toLowerCase();
      if (!q) { hideDropdown(); return; }
      const options = Array.from(datalist.options);
      let count = 0;
      for (const opt of options) {
        const name = (opt.value || '').toLowerCase();
        const sku = (opt.dataset.sku || '').toLowerCase();
        if (name.includes(q) || sku.includes(q)) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
          btn.innerHTML = `<span>${opt.value}</span><code class="small">${opt.dataset.sku || ''}</code>`;
          btn.addEventListener('click', () => selectOption(opt));
          dropdown.appendChild(btn);
          currentItems.push({ button: btn, option: opt });
          count++;
          if (count >= 20) break;
        }
      }
      if (count === 0) { hideDropdown(); return; }
      positionDropdown();
      dropdown.style.display = 'block';
    }

    function selectOption(opt) {
      input.value = opt.value || '';
      if (hidden) hidden.value = opt.dataset.id || '';
      if (skuDisplay) skuDisplay.textContent = opt.dataset.sku ? `Kode: ${opt.dataset.sku}` : '';
      hideDropdown();
      // Trigger input event to let page-specific logic react (e.g., load batches)
      const evt = new Event('input', { bubbles: true });
      input.dispatchEvent(evt);
      input.focus();
    }

    function moveActive(delta) {
      if (!currentItems.length) return;
      currentIndex = (currentIndex + delta + currentItems.length) % currentItems.length;
      currentItems.forEach((item, idx) => {
        if (idx === currentIndex) item.button.classList.add('active');
        else item.button.classList.remove('active');
      });
    }

    input.addEventListener('input', () => {
      showSuggestions(input.value);
    });

    input.addEventListener('focus', () => {
      showSuggestions(input.value);
    });

    input.addEventListener('blur', () => {
      setTimeout(() => hideDropdown(), 120);
    });

    input.addEventListener('keydown', (e) => {
      if (dropdown.style.display !== 'block') return;
      if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); }
      else if (e.key === 'Enter') {
        if (currentIndex >= 0 && currentItems[currentIndex]) {
          e.preventDefault();
          selectOption(currentItems[currentIndex].option);
        }
      } else if (e.key === 'Escape') { hideDropdown(); }
    });

    window.addEventListener('scroll', () => {
      if (dropdown.style.display === 'block') positionDropdown();
    }, true);
    window.addEventListener('resize', () => {
      if (dropdown.style.display === 'block') positionDropdown();
    });

    document.addEventListener('click', (e) => {
      if (e.target === input || dropdown.contains(e.target)) return;
      hideDropdown();
    });
  });
}

// Delete confirmation (SweetAlert2)
if (typeof document !== 'undefined') {
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.delete-outgoing-btn, .delete-incoming-btn, .delete-unloading-btn');
    if (!btn) return;
    e.preventDefault();
    const url = btn.getAttribute('data-delete-url');
    const title = btn.classList.contains('delete-unloading-btn')
      ? 'Hapus Data Unloading?'
      : (btn.classList.contains('delete-incoming-btn') ? 'Hapus Item Barang Masuk?' : 'Hapus Kelompok Barang Keluar?');
    const text = btn.classList.contains('delete-outgoing-btn')
      ? 'Semua item dalam dokumen/kelompok ini akan dihapus dan tidak dapat dikembalikan.'
      : 'Data yang dihapus tidak dapat dikembalikan.';

    if (typeof Swal !== 'undefined') {
      Swal.fire({
        title: title,
        html: `<div class=\"text-muted\">${text}</div>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: '<i class=\"bi bi-trash3 me-2\"></i>Hapus',
        cancelButtonText: 'Batal',
        customClass: {
          confirmButton: 'btn btn-danger',
          cancelButton: 'btn btn-secondary'
        },
        buttonsStyling: false,
        reverseButtons: true,
        focusCancel: true,
        // Matikan animasi agar popup tampil instan
        showClass: { popup: 'swal2-noanimation', backdrop: '', icon: '' },
        hideClass: { popup: '', backdrop: '', icon: '' }
      }).then((result) => {
        if (result.isConfirmed) {
          window.location.href = url;
        }
      });
    } else {
      if (confirm(title + '\n\n' + text)) {
        window.location.href = url;
      }
    }
  });
}

// ===== MODERN PRODUCT LIST FUNCTIONALITY =====

function initializeModernProductList() {
  // Initialize search functionality
  initializeProductSearch();
  
  // Initialize sorting
  initializeTableSorting();
  
  // Initialize filters
  initializeProductFilters();
  
  // Initialize enhanced modals
  initializeEnhancedModals();
  
  // Initialize animations
  initializeAnimations();
}

// Search functionality
function initializeProductSearch() {
  const searchInput = document.getElementById('searchTable');
  if (!searchInput) return;
  
  let searchTimeout;
  
  searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      performSearch(this.value.toLowerCase());
    }, 300);
  });
}

function performSearch(searchTerm) {
  const table = document.getElementById('productsTable');
  if (!table) return;
  
  const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
  let visibleCount = 0;
  
  Array.from(rows).forEach(row => {
    if (row.classList.contains('no-data-row')) return;
    
    const text = row.textContent.toLowerCase();
    const isVisible = text.includes(searchTerm);
    
    row.style.display = isVisible ? '' : 'none';
    
    if (isVisible) {
      visibleCount++;
      // Add search highlight animation
      row.classList.add('search-highlight');
      setTimeout(() => row.classList.remove('search-highlight'), 1000);
    }
  });
  
  // Show/hide "no results" message
  updateNoResultsMessage(visibleCount, searchTerm);
}

function updateNoResultsMessage(count, searchTerm) {
  const tbody = document.querySelector('#productsTable tbody');
  if (!tbody) return;
  
  // Remove existing no-results row
  const existingNoResults = tbody.querySelector('.no-results-row');
  if (existingNoResults) {
    existingNoResults.remove();
  }
  
  if (count === 0 && searchTerm) {
    const noResultsRow = document.createElement('tr');
    noResultsRow.className = 'no-results-row';
    noResultsRow.innerHTML = `
      <td colspan="8" class="text-center p-5">
        <div class="empty-state">
          <i class="bi bi-search display-1 text-muted mb-3"></i>
          <h5 class="text-muted">Tidak ditemukan hasil</h5>
          <p class="text-muted mb-4">Tidak ada produk yang cocok dengan pencarian "${searchTerm}"</p>
          <button class="btn btn-outline-primary" onclick="clearSearch()">
            <i class="bi bi-x-circle me-2"></i>Hapus Pencarian
          </button>
        </div>
      </td>
    `;
    tbody.appendChild(noResultsRow);
  }
}

function clearSearch() {
  const searchInput = document.getElementById('searchTable');
  if (searchInput) {
    searchInput.value = '';
    performSearch('');
  }
}

// Table sorting functionality
function initializeTableSorting() {
  const sortableHeaders = document.querySelectorAll('.sortable');
  
  sortableHeaders.forEach(header => {
    header.addEventListener('click', function() {
      const sortField = this.dataset.sort;
      const currentOrder = this.dataset.order || 'asc';
      const newOrder = currentOrder === 'asc' ? 'desc' : 'asc';
      
      // Update all headers
      sortableHeaders.forEach(h => {
        h.dataset.order = '';
        h.querySelector('.sort-icon').className = 'bi bi-chevron-expand sort-icon';
      });
      
      // Update current header
      this.dataset.order = newOrder;
      const icon = this.querySelector('.sort-icon');
      icon.className = newOrder === 'asc' ? 'bi bi-chevron-up sort-icon' : 'bi bi-chevron-down sort-icon';
      
      // Perform sort
      sortTable(sortField, newOrder);
    });
  });
}

function sortTable(field, order) {
  const table = document.getElementById('productsTable');
  if (!table) return;
  
  const tbody = table.getElementsByTagName('tbody')[0];
  const rows = Array.from(tbody.getElementsByTagName('tr')).filter(row => 
    !row.classList.contains('no-data-row') && !row.classList.contains('no-results-row')
  );
  
  rows.sort((a, b) => {
    let aVal, bVal;
    
    if (field === 'product_name') {
      aVal = a.querySelector('.product-name').textContent.trim();
      bVal = b.querySelector('.product-name').textContent.trim();
    } else if (field === 'sku') {
      aVal = a.querySelector('.sku-code').textContent.trim();
      bVal = b.querySelector('.sku-code').textContent.trim();
    }
    
    if (order === 'asc') {
      return aVal.localeCompare(bVal);
    } else {
      return bVal.localeCompare(aVal);
    }
  });
  
  // Re-append sorted rows without animation
  rows.forEach((row) => {
    row.style.animationDelay = '';
    row.classList.remove('fade-in-row');
    tbody.appendChild(row);
  });
}

// Filter functionality
function initializeProductFilters() {
  const statusFilter = document.getElementById('statusFilter');
  if (!statusFilter) return;
  
  statusFilter.addEventListener('change', function() {
    filterByStatus(this.value.toLowerCase());
  });
}

function normalizeStatus(text) {
  if (!text) return '';
  const t = text.toLowerCase();
  if (t.includes('baik') || t.includes('active') || t.includes('aktif') || t.includes('good')) return 'baik';
  if (t.includes('rendah') || t.includes('low')) return 'rendah';
  if (t.includes('kosong') || t.includes('empty')) return 'kosong';
  return t;
}

function getRowStatus(row) {
  const badge = row.querySelector('.status-badge');
  if (badge) {
    if (badge.classList.contains('status-empty')) return 'kosong';
    if (badge.classList.contains('status-low')) return 'rendah';
    if (badge.classList.contains('status-good')) return 'baik';
  }
  return normalizeStatus(row.dataset.status);
}

function filterByStatus(status) {
  const table = document.getElementById('productsTable');
  if (!table) return;
  
  const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
  let visibleCount = 0;
  
  const normalizedFilter = normalizeStatus(status);
  Array.from(rows).forEach(row => {
    if (row.classList.contains('no-data-row')) return;
    
    const rowStatus = getRowStatus(row);
    const isVisible = !normalizedFilter || rowStatus === normalizedFilter;
    
    row.style.display = isVisible ? '' : 'none';
    if (isVisible) visibleCount++;
  });
  
  // Update no results message for filter
  if (visibleCount === 0 && status) {
    updateNoResultsMessage(0, `filter: ${status}`);
  }
}

function clearFilters() {
  // Clear search
  clearSearch();
  
  // Clear status filter
  const statusFilter = document.getElementById('statusFilter');
  if (statusFilter) {
    statusFilter.value = '';
    filterByStatus('');
  }
}

// Enhanced modal functionality
function initializeEnhancedModals() {
  const productModal = document.getElementById('productModal');
  if (!productModal) return;
  
  // Enhanced edit button handling
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      // Add loading state
      this.innerHTML = '<span class="loading-spinner"></span>';
      
      setTimeout(() => {
        // Restore original state
        this.innerHTML = '<i class="bi bi-pencil-square"></i>';
        
        // Fill form with animation
        fillProductForm(this.dataset);
      }, 500);
    });
  });
}

function fillProductForm(data) {
  const modal = document.getElementById('productModal');
  const modalTitle = modal.querySelector('.modal-title');
  const submitBtn = modal.querySelector('#productSubmitButton');
  
  // Animate modal title change
  modalTitle.style.opacity = '0';
  setTimeout(() => {
    modalTitle.innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Produk';
    modalTitle.style.opacity = '1';
  }, 150);
  
  // Animate submit button change
  submitBtn.style.opacity = '0';
  setTimeout(() => {
    submitBtn.innerHTML = '<i class="bi bi-save-fill me-1"></i>Update Produk';
    submitBtn.style.opacity = '1';
  }, 150);
  
  // Fill form fields with animation
  const fields = ['id', 'product_name', 'sku', 'standard_qty'];
  fields.forEach((field, index) => {
    setTimeout(() => {
      const input = document.getElementById(field === 'id' ? 'product_id_input' : field);
      if (input && data[field]) {
        input.style.transform = 'scale(1.05)';
        input.value = data[field];
        setTimeout(() => {
          input.style.transform = 'scale(1)';
        }, 200);
      }
    }, index * 100);
  });
}

// Delete confirmation with modern styling
function confirmDelete(deleteUrl, productName) {
  if (typeof Swal !== 'undefined') {
    Swal.fire({
      title: 'Konfirmasi Hapus',
      html: `Apakah Anda yakin ingin menghapus produk<br><strong>"${productName}"</strong>?`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#6b7280',
      confirmButtonText: '<i class="bi bi-trash3-fill me-2"></i>Ya, Hapus!',
      cancelButtonText: '<i class="bi bi-x-circle me-2"></i>Batal',
      customClass: {
        popup: 'animate__animated animate__zoomIn',
        confirmButton: 'btn-gradient-danger',
        cancelButton: 'btn-outline-secondary'
      },
      background: '#ffffff',
      backdrop: 'rgba(0,0,0,0.8)'
    }).then((result) => {
      if (result.isConfirmed) {
        // Add loading animation
        Swal.fire({
          title: 'Menghapus...',
          html: 'Mohon tunggu sebentar',
          icon: 'info',
          allowOutsideClick: false,
          showConfirmButton: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });
        
        // Redirect to delete URL
        window.location.href = `index.php?${deleteUrl}`;
      }
    });
  } else {
    // Fallback to native confirm if SweetAlert is not available
    if (confirm(`Apakah Anda yakin ingin menghapus produk "${productName}"?`)) {
      window.location.href = `index.php?${deleteUrl}`;
    }
  }
}

// Utility functions for modern features
function refreshTable() {
  // Add loading animation
  const table = document.getElementById('productsTable');
  if (table) {
    table.style.opacity = '0.5';
    
    // Simulate refresh
    setTimeout(() => {
      table.style.opacity = '1';
      window.location.reload();
    }, 1000);
  }
}

// Export actions removed per request on product list page

// Animation initialization
function initializeAnimations() {
  // Add CSS for search highlight
  if (!document.getElementById('modern-animations-style')) {
    const style = document.createElement('style');
    style.id = 'modern-animations-style';
    style.textContent = `
      .search-highlight {
        background: linear-gradient(135deg, rgba(79, 70, 229, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%) !important;
        animation: searchPulse 1s ease-out;
      }
      
      @keyframes searchPulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.02); }
        100% { transform: scale(1); }
      }
      
      .btn-gradient-danger {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        border: none;
        color: white;
      }
      
      .export-options .btn {
        transition: all 0.3s ease;
      }
      
      .export-options .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      }
    `;
    document.head.appendChild(style);
  }
  
  // Initialize intersection observer for animations
  if (typeof IntersectionObserver !== 'undefined') {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('animate__animated', 'animate__fadeInUp');
        }
      });
    }, { threshold: 0.1 });
    
    // Observe elements for animation
    document.querySelectorAll('.stats-card, .modern-card').forEach(el => {
      observer.observe(el);
    });
  }
}
