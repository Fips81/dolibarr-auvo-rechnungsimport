(function () {
	"use strict";

	var reviewFormContainer = document.getElementById("auvorechnungsimport-review-form");
	var dueDateInput = document.getElementById("auvorechnungsimport-due-date");
	if (reviewFormContainer && dueDateInput && window.jQuery) {
		var dueRequest = 0;
		jQuery(document).on("change", "#cond_reglement_id, #auvorechnungsimport-invoice-date", function () {
			var requestId = ++dueRequest;
			var invoiceDate = jQuery("#auvorechnungsimport-invoice-date").val();
			var conditionId = jQuery("#cond_reglement_id").val();
			var form = reviewFormContainer.querySelector("form");
			var token = form ? form.querySelector('input[name="token"]') : null;
			if (!invoiceDate || !conditionId) { dueDateInput.value = ""; return; }
			dueDateInput.value = "";
			jQuery.ajax({
				url: reviewFormContainer.dataset.dueDateUrl,
				method: "POST",
				dataType: "json",
				data: { token: token ? token.value : "", invoice_date: invoiceDate, cond_reglement_id: conditionId }
			}).done(function (data) {
				if (requestId === dueRequest) dueDateInput.value = data.due_date || "";
			}).fail(function (xhr) {
				if (window.console) console.error("AuVo due-date request failed", xhr.status, xhr.responseText);
			});
		});
	}

	var supplierRadios = document.querySelectorAll("input[name=supplier_mode]");
	var existingSupplier = document.getElementById("auvorechnungsimport-existing-supplier-row");
	var newSupplier = document.getElementById("auvorechnungsimport-new-supplier-row");
	var supplierName = document.getElementById("auvorechnungsimport-supplier-name");
	function syncSupplierMode() {
		var selected = document.querySelector("input[name=supplier_mode]:checked");
		var isNew = selected && selected.value === "new";
		if (existingSupplier) existingSupplier.style.display = isNew ? "none" : "";
		if (newSupplier) newSupplier.style.display = isNew ? "" : "none";
		if (supplierName) supplierName.required = !!isNew;
	}
	supplierRadios.forEach(function (radio) { radio.addEventListener("change", syncSupplierMode); });
	syncSupplierMode();

	var bankRadios = document.querySelectorAll("input[name=bank_mode]");
	var existingBank = document.getElementById("auvorechnungsimport-existing-bank-row");
	var newBankRows = document.querySelectorAll(".auvorechnungsimport-bank-new-row");
	var bankPreview = document.getElementById("auvorechnungsimport-bank-preview");
	var bankIban = document.querySelector("input[name=bank_iban]");
	var bankPreviewIban = document.getElementById("auvorechnungsimport-bank-preview-iban");
	function syncBankMode() {
		var selected = document.querySelector("input[name=bank_mode]:checked");
		var mode = selected ? selected.value : "skip";
		if (existingBank) existingBank.style.display = mode === "existing" ? "" : "none";
		newBankRows.forEach(function (row) { row.style.display = mode === "new" ? "" : "none"; });
		if (bankPreview) bankPreview.style.display = mode === "new" ? "" : "none";
		if (bankIban && bankPreviewIban) bankPreviewIban.textContent = bankIban.value;
	}
	bankRadios.forEach(function (radio) { radio.addEventListener("change", syncBankMode); });
	if (bankIban) bankIban.addEventListener("input", syncBankMode);
	syncBankMode();

	var menuToggle = document.getElementById("auvorechnungsimport-menu-toggle");
	var menu = document.getElementById("id-left");
	if (menuToggle) {
		var body = document.body;
		var pinned = false;
		body.classList.add("auvorechnungsimport-menu-hidden");
		function syncMenu() {
			var peek = body.classList.contains("auvorechnungsimport-menu-peek");
			menuToggle.style.left = peek && menu ? Math.max(0, menu.getBoundingClientRect().right - 25) + "px" : "0px";
			menuToggle.classList.toggle("is-pinned", peek && pinned);
			menuToggle.innerHTML = peek
				? '<span class="fas fa-thumbtack" aria-hidden="true"></span>'
				: '<span class="fas fa-angle-right" aria-hidden="true"></span>';
		}
		function showMenu() { if (!pinned) body.classList.add("auvorechnungsimport-menu-peek"); syncMenu(); }
		function hideMenu() { if (!pinned) body.classList.remove("auvorechnungsimport-menu-peek"); syncMenu(); }
		menuToggle.addEventListener("mouseenter", showMenu);
		menuToggle.addEventListener("focus", showMenu);
		menuToggle.addEventListener("click", function () {
			if (body.classList.contains("auvorechnungsimport-menu-peek")) {
				pinned = !pinned;
				if (!pinned) body.classList.remove("auvorechnungsimport-menu-peek");
			} else {
				pinned = true;
				body.classList.add("auvorechnungsimport-menu-peek");
			}
			syncMenu();
		});
		if (menu) {
			menu.addEventListener("mouseenter", showMenu);
			menu.addEventListener("mouseleave", function () { if (!menuToggle.matches(":hover")) hideMenu(); });
		}
		menuToggle.addEventListener("mouseleave", function () { if (!menu || !menu.matches(":hover")) hideMenu(); });
		menuToggle.addEventListener("blur", hideMenu);
		window.addEventListener("resize", syncMenu);
		syncMenu();
	}

	var reviewShell = document.getElementById("auvorechnungsimport-review-shell");
	var reviewWidthToggle = document.getElementById("auvorechnungsimport-review-width-toggle");
	if (reviewShell && reviewWidthToggle) {
		var ficheReference = document.querySelector("#id-right > .fiche");
		function syncFicheMargins() {
			if (!ficheReference) return;
			var ficheStyle = window.getComputedStyle(ficheReference);
			reviewShell.style.setProperty("--auvorechnungsimport-fiche-margin-left", ficheStyle.marginLeft);
			reviewShell.style.setProperty("--auvorechnungsimport-fiche-margin-right", ficheStyle.marginRight);
		}
		syncFicheMargins();
		window.addEventListener("resize", syncFicheMargins);
		var widthStorageKey = "auvorechnungsimport-review-wide";
		try { reviewShell.classList.toggle("is-wide", localStorage.getItem(widthStorageKey) === "1"); } catch (e) {}
		function updateWidthToggle() {
			var wide = reviewShell.classList.contains("is-wide");
			var icon = reviewWidthToggle.querySelector(".fas");
			if (icon) icon.className = "fas " + (wide ? "fa-compress" : "fa-expand");
			reviewWidthToggle.setAttribute("aria-pressed", wide ? "true" : "false");
		}
		reviewWidthToggle.addEventListener("click", function () {
			var wide = reviewShell.classList.toggle("is-wide");
			try { localStorage.setItem(widthStorageKey, wide ? "1" : "0"); } catch (e) {}
			updateWidthToggle();
			window.dispatchEvent(new Event("resize"));
		});
		updateWidthToggle();
	}

	var layout = document.getElementById("auvorechnungsimport-review-layout");
	var splitter = document.getElementById("auvorechnungsimport-review-splitter");
	if (layout && splitter) {
		var storageKey = "auvorechnungsimport-review-preview-ratio";
		var ratio = 0.4;
		var resizing = false;
		try {
			var storedRatio = parseFloat(localStorage.getItem(storageKey));
			if (isFinite(storedRatio) && storedRatio >= 0.25 && storedRatio <= 0.65) ratio = storedRatio;
		} catch (e) {}
		function bounds() {
			var available = Math.max(1, layout.clientWidth - splitter.offsetWidth);
			var min = Math.min(280, available * 0.3);
			return { available: available, min: min, max: Math.max(min, Math.min(available * 0.65, available - 420)) };
		}
		function applyRatio(save) {
			if (window.matchMedia("(max-width:1100px)").matches) return;
			var limits = bounds();
			var width = Math.max(limits.min, Math.min(limits.max, limits.available * ratio));
			ratio = width / limits.available;
			layout.style.setProperty("--auvorechnungsimport-preview-width", width + "px");
			splitter.setAttribute("aria-valuenow", String(Math.round(ratio * 100)));
			if (save) try { localStorage.setItem(storageKey, String(ratio)); } catch (e) {}
		}
		function moveSplitter(event) {
			var limits = bounds();
			ratio = (event.clientX - layout.getBoundingClientRect().left) / limits.available;
			applyRatio(false);
		}
		splitter.addEventListener("pointerdown", function (event) {
			if (window.matchMedia("(max-width:1100px)").matches) return;
			resizing = true;
			layout.classList.add("is-resizing");
			document.body.classList.add("auvorechnungsimport-resizing");
			splitter.setPointerCapture(event.pointerId);
			moveSplitter(event);
			event.preventDefault();
		});
		splitter.addEventListener("pointermove", function (event) { if (resizing) moveSplitter(event); });
		function stopResize(event) {
			if (!resizing) return;
			resizing = false;
			layout.classList.remove("is-resizing");
			document.body.classList.remove("auvorechnungsimport-resizing");
			applyRatio(true);
			if (splitter.hasPointerCapture(event.pointerId)) splitter.releasePointerCapture(event.pointerId);
		}
		splitter.addEventListener("pointerup", stopResize);
		splitter.addEventListener("pointercancel", stopResize);
		splitter.addEventListener("keydown", function (event) {
			if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
			event.preventDefault();
			ratio += event.key === "ArrowRight" ? (event.shiftKey ? 0.05 : 0.02) : -(event.shiftKey ? 0.05 : 0.02);
			applyRatio(true);
		});
		window.addEventListener("resize", function () { applyRatio(false); });
		applyRatio(false);
	}

	var feedback = document.getElementById("auvorechnungsimport-lines-total-feedback");
	var lineTable = document.getElementById("auvorechnungsimport-lines-table");
	if (!feedback || !lineTable) return;
	lineTable.addEventListener("change", function (event) {
		var product = event.target;
		if (!product.id || product.id.indexOf("lineprod_") !== 0) return;
		var picker = product.closest(".auvorechnungsimport-product-picker");
		if (!picker) return;
		var productId = picker.querySelector(".auvorechnungsimport-product-id");
		var lineIndex = product.id.substring(9);
		var touched = picker.querySelector('input[name="line_product_touched_' + lineIndex + '"]');
		var value = window.jQuery ? jQuery(product).val() : product.value;
		if (productId) productId.value = String(parseInt(value, 10) || 0);
		if (touched) touched.value = "1";
	});
	var decimal = feedback.dataset.decimal || ",";
	var thousands = feedback.dataset.thousands || ".";
	var expectedNet = feedback.dataset.expectedNet === "" ? null : parseFloat(feedback.dataset.expectedNet);
	var expectedGross = feedback.dataset.expectedGross === "" ? null : parseFloat(feedback.dataset.expectedGross);
	var totalMismatch = feedback.dataset.totalMismatch || "";
	var skipWarning = feedback.dataset.skipWarning || "";
	var requiredFields = document.getElementById("auvorechnungsimport-required-fields");
	var missingFieldsText = feedback.dataset.missingFields || "";
	var supplierLabel = feedback.dataset.fieldSupplier || "Supplier";
	var referenceLabel = feedback.dataset.fieldReference || "Supplier reference";
	var dateLabel = feedback.dataset.fieldDate || "Date";
	var germanTaxIdRequired = feedback.dataset.germanTaxIdRequired || "For German suppliers, enter either a VAT ID or a tax number.";
	var fieldLabels = {
		description: feedback.dataset.fieldDescription || "Description",
		quantity: feedback.dataset.fieldQuantity || "Quantity",
		price: feedback.dataset.fieldPrice || "Price",
		vat: feedback.dataset.fieldVat || "VAT",
		product: feedback.dataset.fieldProduct || "Product"
	};
	function formatAmount(value) {
		var parts = Math.abs(value).toFixed(2).split(".");
		return (value < 0 ? "-" : "") + parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousands) + decimal + parts[1];
	}
	function parseAmount(value) {
		value = (value || "").replace(/\s/g, "");
		if (thousands) value = value.split(thousands).join("");
		if (decimal && decimal !== ".") value = value.replace(decimal, ".");
		var amount = parseFloat(value);
		return Number.isFinite(amount) ? amount : null;
	}
	function updateTotals() {
		var sumNet = 0, sumGross = 0, booked = 0, skipped = 0, skippedNet = 0, skippedGross = 0, count = 0, valid = true;
		var missing = [];
		var lineNumber = 0;
		document.querySelectorAll("tr.auvorechnungsimport-line").forEach(function (row) {
			lineNumber++;
			var modeSelect = row.querySelector("select.auvorechnungsimport-mode");
			if (!modeSelect) return;
			var mode = modeSelect.value;
			var qtyInput = row.querySelector(".auvorechnungsimport-line-qty");
			var priceInput = row.querySelector(".auvorechnungsimport-line-price");
			var vatInput = row.querySelector(".auvorechnungsimport-line-vat");
			var labelInput = row.querySelector("input[name^=line_label_]");
			if (!qtyInput || !priceInput || !vatInput || !labelInput) return;
			var qty = parseAmount(qtyInput.value);
			var unit = parseAmount(priceInput.value);
			var vat = parseAmount(vatInput.value);
			var product = document.getElementById("lineprod_" + modeSelect.name.replace("line_mode_", ""));
			var variant = row.querySelector("input[name^=create_variant_]");
			var refInput = row.querySelector("input[name^=line_ref_supplier_]");
			var hasLineInput = labelInput.value.trim() || (refInput && refInput.value.trim()) || qtyInput.value.trim() || priceInput.value.trim() || vatInput.value.trim() || (product && parseInt(product.value, 10) > 0) || (variant && variant.checked);
			if (!hasLineInput) return;
			if (mode !== "skip") {
				if (!labelInput.value.trim()) missing.push(fieldLabels.description + " (Pos. " + lineNumber + ")");
				if (!qtyInput.value.trim() || qty === null || qty === 0) missing.push(fieldLabels.quantity + " (Pos. " + lineNumber + ")");
				if (!priceInput.value.trim() || unit === null) missing.push(fieldLabels.price + " (Pos. " + lineNumber + ")");
				if (!vatInput.value.trim() || vat === null || vat < 0 || vat > 100) missing.push(fieldLabels.vat + " (Pos. " + lineNumber + ")");
				if (mode === "book" && (!product || parseInt(product.value, 10) <= 0) && !(variant && variant.checked)) missing.push(fieldLabels.product + " (Pos. " + lineNumber + ")");
			}
			if (mode !== "skip" && (!labelInput.value.trim() || !qtyInput.value.trim() || qty === 0 || !priceInput.value.trim() || !vatInput.value.trim())) valid = false;
			if (mode === "book" && (!product || parseInt(product.value, 10) <= 0) && !(variant && variant.checked)) valid = false;
			if (mode !== "skip" && (qty === null || unit === null || vat === null || vat < 0 || vat > 100)) valid = false;
			var base = (qty || 0) * (unit || 0), net, gross;
			if (row.dataset.priceBase === "TTC") { gross = base; net = vat > 0 ? base / (1 + vat / 100) : base; }
			else { net = base; gross = base * (1 + vat / 100); }
			net = Math.round(net * 100) / 100;
			gross = Math.round(gross * 100) / 100;
			var lineTotal = row.querySelector(".auvorechnungsimport-line-total");
			if (lineTotal) lineTotal.textContent = formatAmount(base);
			row.classList.toggle("opacitymedium", mode === "skip");
			if (mode === "skip") { skipped += gross; skippedNet += net; skippedGross += gross; }
			else { sumNet += net; sumGross += gross; booked += row.dataset.priceBase === "TTC" ? gross : net; count++; }
		});
		var tolerance = Math.max(0.02, 0.01 * count);
		var netMismatch = count > 0 && expectedNet !== null && Math.abs(sumNet - (expectedNet - skippedNet)) > tolerance;
		var grossMismatch = count > 0 && expectedGross !== null && Math.abs(sumGross - (expectedGross - skippedGross)) > tolerance;
		var supplierMode = document.querySelector("input[name=supplier_mode]:checked");
		if (supplierMode && supplierMode.value === "new") {
			var newSupplierName = document.getElementById("auvorechnungsimport-supplier-name");
			if (newSupplierName && !newSupplierName.value.trim()) missing.push(supplierLabel + " name");
			var supplierCountry = document.querySelector('[name="supplier_value_country"]');
			var supplierVat = document.querySelector('[name="supplier_value_vat"]');
			var supplierTaxId = document.querySelector('[name="supplier_value_tax_id"]');
			if (supplierCountry && supplierCountry.value.toUpperCase() === "DE"
				&& !(supplierVat && supplierVat.value.trim()) && !(supplierTaxId && supplierTaxId.value.trim())) {
				missing.push(germanTaxIdRequired);
				valid = false;
			}
		} else {
			var supplierSelect = document.getElementById("fk_soc");
			if (supplierSelect && parseInt(supplierSelect.value, 10) <= 0) missing.push(supplierLabel);
		}
		var supplierReference = document.querySelector("input[name=ref_supplier]");
		var invoiceDate = document.getElementById("auvorechnungsimport-invoice-date");
		if (!supplierReference || !supplierReference.value.trim()) { missing.push(referenceLabel); valid = false; }
		if (!invoiceDate || !invoiceDate.value.trim()) { missing.push(dateLabel); valid = false; }
		if (requiredFields) {
			var requirements = [];
			if (missing.length) requirements.push(missingFieldsText.replace("__FIELDS__", missing.join(", ")));
			requiredFields.style.display = requirements.length ? "" : "none";
			requiredFields.textContent = requirements.join(" ");
		}
		feedback.style.display = netMismatch || grossMismatch ? "" : "none";
		feedback.textContent = netMismatch || grossMismatch ? totalMismatch : "";
		var netTotal = document.getElementById("auvorechnungsimport-sum-net");
		var grossTotal = document.getElementById("auvorechnungsimport-sum-gross");
		var toBook = document.getElementById("auvorechnungsimport-tobook");
		var skipFeedback = document.getElementById("auvorechnungsimport-skipwarn");
		if (netTotal) netTotal.textContent = formatAmount(sumNet);
		if (grossTotal) grossTotal.textContent = formatAmount(sumGross);
		if (toBook) toBook.textContent = formatAmount(booked);
		if (skipFeedback) skipFeedback.textContent = Math.abs(skipped) > 0.005 ? skipWarning.replace("__AMOUNT__", formatAmount(skipped)) : "";
		var createButton = document.getElementById("auvorechnungsimport-create-invoice");
		if (createButton) createButton.disabled = !valid || netMismatch || grossMismatch;
		var releaseOrderButton = document.getElementById("auvorechnungsimport-create-invoice-release-order");
		if (releaseOrderButton) releaseOrderButton.disabled = !valid || count === 0 || netMismatch || grossMismatch;
	}

	document.querySelectorAll("select.auvorechnungsimport-mode").forEach(function (select) {
		var previousMode = select.value;
		select.addEventListener("change", function () {
			if (select.value === "delete") {
				if (!window.confirm(select.dataset.deleteConfirm || "Delete this invoice line?")) {
					select.value = previousMode;
					var restoredIcon = select.parentElement.querySelector(".auvorechnungsimport-mode-code");
					if (restoredIcon) restoredIcon.textContent = { book: "➕", cost: "➖", skip: "✖️" }[previousMode] || "?";
					return;
				}
				var lineRow = select.closest("tr.auvorechnungsimport-line");
				var match = select.name.match(/^line_mode_(\d+)$/);
				var form = lineTable.closest("form");
				if (lineRow && match && form) {
					var deletedField = document.createElement("input");
					deletedField.type = "hidden";
					deletedField.name = "line_deleted_" + match[1];
					deletedField.value = "1";
					form.appendChild(deletedField);
					lineRow.remove();
					updateTotals();
				}
				return;
			}
			previousMode = select.value;
			var icon = select.parentElement.querySelector(".auvorechnungsimport-mode-code");
			if (icon) icon.textContent = { book: "➕", cost: "➖", skip: "✖️" }[select.value] || "?";
			updateTotals();
		});
	});
	document.querySelectorAll(".auvorechnungsimport-product-search").forEach(function (button) {
		var search = document.getElementById("search_" + button.dataset.select);
		var product = document.getElementById(button.dataset.select);
		var picker = button.closest(".auvorechnungsimport-product-picker");
		if (!product || !picker) return;
		function productValue() {
			var value = product.value;
			if (window.jQuery) value = jQuery(product).val();
			return parseInt(value, 10) || 0;
		}
		function closePicker() {
			picker.classList.remove("is-open");
			if (search && window.jQuery && jQuery.fn.autocomplete) {
				try { jQuery(search).autocomplete("close"); } catch (e) {}
			}
		}
		button.addEventListener("click", function () {
			picker.classList.add("is-open");
			if (search) { search.focus(); search.select(); }
			else if (window.jQuery && jQuery.fn.select2) jQuery(product).select2("open");
			else product.focus();
		});
		var preview = picker.querySelector(".auvorechnungsimport-product-preview");
		var clear = picker.querySelector(".auvorechnungsimport-product-clear");
		var productId = picker.querySelector(".auvorechnungsimport-product-id");
		function syncSelected() {
			var selected = productValue() > 0;
			picker.classList.toggle("is-selected", !!selected);
			if (selected && preview) {
				var cardBase = preview.dataset.productCardBase || "";
				if (cardBase && productValue() > 0) preview.href = cardBase + encodeURIComponent(productValue());
				if (search && search.value) preview.title = search.value;
				else if (product.tagName === "SELECT" && product.selectedIndex >= 0) preview.title = product.options[product.selectedIndex].text;
			}
		}
		if (search) {
			search.addEventListener("input", function () { if (productValue() <= 0) picker.classList.remove("is-selected"); });
			search.addEventListener("blur", function () { setTimeout(function () { closePicker(); syncSelected(); }, 250); });
			if (window.jQuery) jQuery(search).on("autocompleteselect", function (event, ui) {
				if (ui && ui.item && ui.item.id) product.value = ui.item.id;
				setTimeout(function () { syncSelected(); closePicker(); updateTotals(); }, 0);
			});
		}
		var onProductChange = function () { syncSelected(); if (productValue() > 0) setTimeout(closePicker, 100); updateTotals(); };
		if (window.jQuery) jQuery(product).on("change", onProductChange);
		else product.addEventListener("change", onProductChange);
		if (clear) clear.addEventListener("click", function () {
			product.value = product.tagName === "SELECT" ? "-1" : "0";
			if (search) search.value = "";
			if (window.jQuery) jQuery(product).val(product.value).trigger("change");
			else product.dispatchEvent(new Event("change", { bubbles: true }));
			picker.classList.remove("is-selected", "is-open");
			if (search) search.focus();
			updateTotals();
		});
		syncSelected();
	});
	var lineForm = lineTable.closest("form");
	if (lineForm) lineForm.addEventListener("submit", function () {
		lineForm.querySelectorAll(".auvorechnungsimport-product-picker").forEach(function (picker) {
			var button = picker.querySelector(".auvorechnungsimport-product-search");
			var selected = button ? document.getElementById(button.dataset.select) : null;
			var productId = picker.querySelector(".auvorechnungsimport-product-id");
			if (!selected || !productId) return;
			var value = selected.value;
			if (window.jQuery) value = jQuery(selected).val();
			productId.value = String(parseInt(value, 10) || 0);
		});
	});
	var addLineButton = document.getElementById("auvorechnungsimport-add-line");
	if (addLineButton) addLineButton.addEventListener("click", function () {
		var row = document.querySelector("tr.auvorechnungsimport-line-template");
		if (!row) { addLineButton.disabled = true; return; }
		row.classList.remove("auvorechnungsimport-line-template");
		row.classList.add("auvorechnungsimport-line");
		row.querySelectorAll(":disabled").forEach(function (field) { field.disabled = false; });
		var count = document.getElementById("auvorechnungsimport-line-count");
		if (count) count.value = String((parseInt(count.value, 10) || 0) + 1);
		if (!document.querySelector("tr.auvorechnungsimport-line-template")) addLineButton.disabled = true;
		updateTotals();
		var label = row.querySelector("input[name^=line_label_]");
		if (label) label.focus();
	});
	lineTable.querySelectorAll("input").forEach(function (input) { input.addEventListener("input", updateTotals); });
	document.querySelectorAll('input[name="supplier_mode"], input[name="supplier_value_vat"], input[name="supplier_value_tax_id"], [name="supplier_value_country"], input[name="ref_supplier"], #auvorechnungsimport-invoice-date').forEach(function (input) {
		input.addEventListener("input", updateTotals);
		input.addEventListener("change", updateTotals);
	});
	updateTotals();
})();
