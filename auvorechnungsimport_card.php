<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Review one uploaded invoice: check/correct the extracted data and supplier
 * match, then create the actual supplier invoice from it.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die('main.inc.php not found');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once __DIR__.'/class/importedinvoice.class.php';
require_once __DIR__.'/core/lib/auvorechnungsimport.lib.php';

$langs->loadLangs(array('auvorechnungsimport@auvorechnungsimport', 'companies', 'bills', 'other'));

if (!isModEnabled('auvorechnungsimport')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('auvorechnungsimport', 'imports', 'read')) {
	accessforbidden();
}

$form = new Form($db);

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

$object = new ImportedInvoice($db);
if ($object->fetch($id) <= 0) {
	accessforbidden('Not found');
}
$extracted = $object->getExtractedData();
$supplierMode = GETPOST('supplier_mode', 'alpha');
$savedSupplierMode = (string) ($extracted['review_supplier_mode'] ?? '');
if (!in_array($supplierMode, array('new', 'existing'), true) && in_array($savedSupplierMode, array('new', 'existing'), true)) {
	$supplierMode = $savedSupplierMode;
}
$manualSupplierCreateAllowed = getDolGlobalInt('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER') && $user->hasRight('societe', 'creer');
if (!in_array($supplierMode, array('new', 'existing'), true)) {
	$supplierMode = ((int) $object->fk_soc > 0 || !auvorechnungsimport_can_create_supplier_from_fields($user, $extracted)) ? 'existing' : 'new';
}
if ($supplierMode === 'new' && !$manualSupplierCreateAllowed) $supplierMode = 'existing';
$supplierPreviewFields = $extracted;
if ($supplierMode === 'new') {
	$postedSupplierName = GETPOST('supplier_value_name', 'restricthtml');
	if (isset($_POST['supplier_value_name'])) $supplierPreviewFields['supplier_name'] = trim((string) $postedSupplierName);
	if (isset($_POST['supplier_value_vat'])) $supplierPreviewFields['supplier_vat'] = GETPOST('supplier_value_vat', 'restricthtml');
	// Selecting "New supplier" is explicit confirmation to use the reviewed name,
	// even when OCR could not mark its extraction as confident.
	if (trim((string) ($supplierPreviewFields['supplier_name'] ?? '')) !== '') $supplierPreviewFields['supplier_name_confident'] = true;
}
if (in_array((int) $object->status, array(ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_SPAM_CONFIRMED), true) && in_array($action, array('createinvoice', 'reprocess', 'confirmduplicate', 'delete', 'confirm_delete'), true)) {
	accessforbidden('Spam imports are held for review and cannot be processed from this page');
}

// Persist review edits as an import draft only. This deliberately does not
// create/update a third party, supplier invoice, bank account, or blocked-log entry.
if ($action === 'createinvoice' && GETPOST('save_review', 'alpha') === '1' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) accessforbidden('CSRF');
	if (!in_array((int) $object->status, array(ImportedInvoice::STATUS_NEEDS_REVIEW, ImportedInvoice::STATUS_MATCHED, ImportedInvoice::STATUS_ERROR), true)) accessforbidden('Invalid import status');

	$extracted['invoice_ref'] = trim(GETPOST('ref_supplier', 'alphanohtml'));
	$postedDate = GETPOST('invoice_date', 'alpha');
	if ($postedDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedDate)) $extracted['invoice_date'] = $postedDate;
	$extracted['review_supplier_mode'] = in_array($supplierMode, array('new', 'existing'), true) ? $supplierMode : 'existing';
	$selectedSupplierId = GETPOSTINT('fk_soc');
	if ($extracted['review_supplier_mode'] === 'existing' && $selectedSupplierId > 0) {
		$selectedSupplier = new Societe($db);
		if ($selectedSupplier->fetch($selectedSupplierId) <= 0 || empty($selectedSupplier->fournisseur)) accessforbidden('Invalid supplier selection');
	}
	$object->fk_soc = ($extracted['review_supplier_mode'] === 'existing' && $selectedSupplierId > 0) ? $selectedSupplierId : null;
	if ($object->fk_soc === null) $object->supplier_match_confidence = 'none';

	$address = is_array($extracted['supplier_address'] ?? null) ? $extracted['supplier_address'] : array();
	$reviewFields = array(
		'name' => 'supplier_name', 'vat' => 'supplier_vat', 'tax_id' => 'supplier_tax_id', 'address' => null,
		'zip' => null, 'town' => null, 'email' => 'supplier_email', 'phone' => 'supplier_phone',
		'country' => 'supplier_country', 'iban' => 'supplier_iban', 'bankowner' => 'supplier_account_holder',
	);
	foreach ($reviewFields as $formField => $dataField) {
		$postName = 'supplier_value_'.$formField;
		if (!isset($_POST[$postName])) continue;
		$value = trim((string) GETPOST($postName, $formField === 'country' ? 'alpha' : 'restricthtml'));
		if ($formField === 'vat') $value = auvorechnungsimport_normalize_vat($value);
		if ($formField === 'iban') $value = strtoupper(preg_replace('/\s+/', '', $value));
		if ($dataField !== null) $extracted[$dataField] = $value;
		elseif ($formField === 'address') $address['line1'] = $value;
		elseif ($formField === 'zip') $address['postcode'] = $value;
		elseif ($formField === 'town') $address['city'] = $value;
		if ($formField === 'country') $extracted['supplier_country'] = strtoupper($value);
	}
	if (isset($_POST['supplier_value_address'])) $address['line1'] = trim((string) GETPOST('supplier_value_address', 'restricthtml'));
	if (isset($_POST['supplier_value_zip'])) $address['postcode'] = trim((string) GETPOST('supplier_value_zip', 'restricthtml'));
	if (isset($_POST['supplier_value_town'])) $address['city'] = trim((string) GETPOST('supplier_value_town', 'restricthtml'));
	$extracted['supplier_address'] = $address;
	if (isset($_POST['supplier_value_bankname'])) {
		if (!isset($extracted['supplier_bank_details']) || !is_array($extracted['supplier_bank_details'])) $extracted['supplier_bank_details'] = array();
		$extracted['supplier_bank_details']['bank_name'] = trim((string) GETPOST('supplier_value_bankname', 'restricthtml'));
	}
	$choices = array();
	foreach (array('name', 'vat', 'tax_id', 'address', 'zip', 'town', 'email', 'phone', 'country', 'iban', 'bankowner', 'bankname', 'contact') as $field) {
		$key = 'update_supplier_'.$field;
		if (isset($_POST[$key])) {
			$choice = GETPOST($key, 'alpha');
			$choices[$field] = in_array($choice, array('update', 'keep'), true) ? $choice : '';
		}
	}
	$extracted['review_supplier_choices'] = $choices;

	$lines = isset($extracted['lines']) && is_array($extracted['lines']) ? $extracted['lines'] : array();
	foreach ($lines as $i => &$line) {
		$mode = GETPOST('line_mode_'.$i, 'aZ09');
		if (in_array($mode, AUVORECHNUNGSIMPORT_LINE_MODES, true)) $line['mode'] = $mode;
		$line['ref_supplier'] = trim((string) GETPOST('line_ref_supplier_'.$i, 'alphanohtml'));
		$line['label'] = GETPOST('line_label_'.$i, 'restricthtml');
		$line['qty'] = (float) price2num(GETPOST('line_qty_'.$i, 'alpha'));
		$priceRaw = GETPOST('line_price_'.$i, 'alpha');
		$line['price'] = ($priceRaw === '') ? null : (float) price2num($priceRaw, 'MU');
		$line['vat_rate'] = GETPOST('line_vat_'.$i, 'alpha') === '' ? null : (float) price2num(GETPOST('line_vat_'.$i, 'alpha'));
		$priceBase = GETPOST('line_price_base_'.$i, 'alpha');
		if (in_array($priceBase, array('HT', 'TTC'), true)) $line['price_base'] = $priceBase;
		$line['fk_product'] = GETPOSTINT('lineprod_'.$i);
	}
	unset($line);
	$extracted['lines'] = $lines;
	if (empty($lines)) {
		$extracted['manual_line'] = array(
			'mode' => in_array(GETPOST('manual_line_mode', 'aZ09'), AUVORECHNUNGSIMPORT_LINE_MODES, true) ? GETPOST('manual_line_mode', 'aZ09') : 'book',
			'label' => trim((string) GETPOST('manual_line_label', 'restricthtml')),
			'qty' => trim((string) GETPOST('manual_line_qty', 'alpha')),
			'price' => trim((string) GETPOST('manual_line_price', 'alpha')),
			'vat' => trim((string) GETPOST('manual_line_vat', 'alpha')),
			'ref_supplier' => trim((string) GETPOST('manual_line_ref_supplier', 'alphanohtml')),
			'fk_product' => GETPOSTINT('manual_line_product_id'),
		);
	}
	$object->extracted_data = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($object->update($user) > 0) {
		setEventMessages($langs->trans('AuvoRechnungsimportReviewSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('AuvoRechnungsimportReviewSaveFailed'), $object->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}
$nonSupplierMatchId = auvorechnungsimport_find_non_supplier_by_vat($db, (string) ($extracted['supplier_vat'] ?? ''));
$previewSupplierId = $supplierMode === 'new' ? 0 : (GETPOSTINT('fk_soc') ?: (int) $object->fk_soc);
$detectedOwnCompany = auvorechnungsimport_is_own_company_supplier($db, $supplierPreviewFields);
$selectedOwnCompany = auvorechnungsimport_is_own_company_supplier($db, array(), $previewSupplierId);
$selfSupplierBlocked = $selectedOwnCompany || ($detectedOwnCompany && ($supplierMode === 'new' || $previewSupplierId <= 0));
$duplicateCandidateSupplierId = $previewSupplierId;
$duplicateCandidateRef = trim(GETPOST('ref_supplier', 'alphanohtml'));
if ($duplicateCandidateRef === '') $duplicateCandidateRef = (string) ($extracted['invoice_ref'] ?? '');
$detectedDuplicate = auvorechnungsimport_find_existing_invoice($db, $duplicateCandidateSupplierId, $duplicateCandidateRef);
$canUpdateBuyprice = $user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer');
$canCreateOrder = isModEnabled('supplier_order') && $user->hasRight('fournisseur', 'commande', 'creer');
$canCreateProduct = isModEnabled('variants') && $user->hasRight('produit', 'creer');

if ($action === 'confirmduplicate' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) accessforbidden('CSRF');
	if (!in_array((int) $object->status, array(ImportedInvoice::STATUS_NEEDS_REVIEW, ImportedInvoice::STATUS_MATCHED, ImportedInvoice::STATUS_ERROR), true)) accessforbidden('Invalid import status');
	$duplicateSupplierId = GETPOSTINT('fk_soc') ?: (int) $object->fk_soc;
	$duplicateRef = trim(GETPOST('ref_supplier', 'alphanohtml'));
	if ($duplicateRef === '') $duplicateRef = (string) ($extracted['invoice_ref'] ?? '');
	$existingDuplicate = auvorechnungsimport_find_existing_invoice($db, $duplicateSupplierId, $duplicateRef);
	if (!$existingDuplicate) {
		setEventMessages($langs->trans('AuvoRechnungsimportDuplicateConfirmFailed'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	$previousStatus = (int) $object->status;
	$previousSupplierId = (int) $object->fk_soc;
	$previousInvoiceId = (int) $object->fk_facture_fourn;
	$object->status = ImportedInvoice::STATUS_DUPLICATE;
	$object->fk_soc = $duplicateSupplierId;
	$object->fk_facture_fourn = (int) $existingDuplicate->id;
	if ($object->update($user) > 0) {
		$logResult = auvorechnungsimport_blockedlog_log_duplicate_confirmation($db, $object, $existingDuplicate, $user, $duplicateRef);
		if ($logResult <= 0) {
			$object->status = $previousStatus;
			$object->fk_soc = $previousSupplierId;
			$object->fk_facture_fourn = $previousInvoiceId;
			if ($object->update($user) < 0) {
				dol_syslog('AuVoRechnungsimport: failed to restore import after duplicate confirmation could not be logged: '.$object->error, LOG_ERR);
			}
			setEventMessages($langs->trans('AuvoRechnungsimportDuplicateLogFailed'), null, 'errors');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages($langs->trans('AuvoRechnungsimportDuplicateConfirmed'), null, 'mesgs');
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1).'?search_status='.ImportedInvoice::STATUS_DUPLICATE.','.ImportedInvoice::STATUS_SPAM_CONFIRMED);
		exit;
	}
	setEventMessages($langs->trans('AuvoRechnungsimportDuplicateConfirmFailed'), $object->errors, 'errors');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action === 'confirmspam' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) accessforbidden('CSRF');
	if ((int) $object->status !== ImportedInvoice::STATUS_SPAM_REVIEW) accessforbidden('Invalid import status');
	$previousStatus = (int) $object->status;
	$object->status = ImportedInvoice::STATUS_SPAM_CONFIRMED;
	if ($object->update($user) > 0) {
		$logResult = auvorechnungsimport_blockedlog_log_spam_confirmation($db, $object, $user);
		if ($logResult <= 0) {
			$object->status = $previousStatus;
			if ($object->update($user) < 0) dol_syslog('AuVoRechnungsimport: failed to restore spam review status after BlockedLog failure for '.$object->ref, LOG_ERR);
			setEventMessages($langs->trans('AuvoRechnungsimportSpamLogFailed'), null, 'errors');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages($langs->trans('AuvoRechnungsimportSpamConfirmed'), null, 'mesgs');
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1).'?search_status='.ImportedInvoice::STATUS_DUPLICATE.','.ImportedInvoice::STATUS_SPAM_CONFIRMED);
		exit;
	}
	setEventMessages($langs->trans('AuvoRechnungsimportSpamConfirmFailed'), $object->errors, 'errors');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

/**
 * Create the supplier order for goods receipt and report the outcome.
 *
 * @param DoliDB $db
 * @param User $user
 * @param Translate $langs
 * @param FactureFournisseur $invoice
 * @param array<string,mixed> $extracted
 * @return int Order id or <0
 */
function auvorechnungsimport_card_create_order($db, $user, $langs, $invoice, $extracted)
{
	$priceBase = (($extracted['lines'][0]['price_base'] ?? 'HT') === 'TTC') ? 'TTC' : 'HT';
	$warnings = array();
	$db->begin();
	$orderId = auvorechnungsimport_create_supplier_order($db, $user, $invoice, $priceBase, $warnings);
	if ($orderId > 0) {
		$db->commit();
		$order = new CommandeFournisseur($db);
		$order->fetch($orderId);
		setEventMessages($langs->trans('AuvoRechnungsimportOrderCreated', $order->getNomUrl(1)), $warnings, empty($warnings) ? 'mesgs' : 'warnings');
	} else {
		$db->rollback();
		setEventMessages($langs->trans('AuvoRechnungsimportErrOrder'), array_filter($invoice->errors), 'errors');
	}
	return $orderId;
}

if (GETPOSTINT('preview_supplier') && $action == 'createinvoice' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	if (($supplierMode === 'existing' && GETPOSTINT('fk_soc') > 0) || ($supplierMode === 'new' && auvorechnungsimport_can_create_supplier_from_fields($user, $supplierPreviewFields, true))) {
		setEventMessages($langs->trans('AuvoRechnungsimportSupplierPreviewUpdated'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('AuvoRechnungsimportErrNoSupplier'), null, 'errors');
	}
}

if ($action == 'createinvoice' && !GETPOSTINT('preview_supplier') && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$fkSoc = $supplierMode === 'new' ? 0 : GETPOSTINT('fk_soc');
	$detectedOwnCompany = auvorechnungsimport_is_own_company_supplier($db, $supplierPreviewFields);
	$selectedOwnCompany = auvorechnungsimport_is_own_company_supplier($db, array(), $fkSoc);
	$selfSupplierBlocked = $selectedOwnCompany || ($detectedOwnCompany && ($supplierMode === 'new' || $fkSoc <= 0));
	$refSupplier = GETPOST('ref_supplier', 'alphanohtml');
	$invoiceDate = GETPOST('invoice_date', 'alpha');
	$requireInvoiceRef = getDolGlobalInt('AUVORECHNUNGSIMPORT_REQUIRE_INVOICE_REF');

	$reviewTotals = auvorechnungsimport_check_review_totals($extracted);
	$existingInvoice = auvorechnungsimport_find_existing_invoice($db, $fkSoc, $refSupplier);
	if ($requireInvoiceRef && trim($refSupplier) === '') {
		setEventMessages($langs->trans('AuvoRechnungsimportErrInvoiceRefRequired'), null, 'errors');
	} elseif ($selfSupplierBlocked) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrOwnCompanySupplier'), null, 'errors');
	} elseif (!empty($reviewTotals['missing_vat_lines'])) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrReviewVatRequired', implode(', ', $reviewTotals['missing_vat_lines'])), null, 'errors');
	} elseif (!empty($reviewTotals['missing_product_lines'])) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrProductRequiredForTakeover', implode(', ', $reviewTotals['missing_product_lines'])), null, 'errors');
	} elseif (!empty($reviewTotals['no_lines'])) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrReviewNoBookedLines'), null, 'errors');
	} elseif ($reviewTotals['checked'] && !$reviewTotals['valid']) {
		$mismatchMessages = array();
		foreach ($reviewTotals['errors'] as $mismatch) {
			$mismatchMessages[] = $langs->trans('AuvoRechnungsimportErrReviewTotalMismatch'.$mismatch[0], price($mismatch[1]), price($mismatch[2]));
		}
		setEventMessages(null, $mismatchMessages, 'errors');
	} elseif (!empty($extracted['is_credit_note'])) {
		// A supplier credit note needs the original invoice in Dolibarr - it is
		// not created as an ordinary invoice with positive amounts.
		setEventMessages($langs->trans('AuvoRechnungsimportErrCreditNote'), null, 'errors');
	} elseif ($fkSoc <= 0 && !auvorechnungsimport_can_create_supplier_from_fields($user, $supplierPreviewFields, $supplierMode === 'new')) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrNoSupplier'), null, 'errors');
	} elseif ($existingInvoice) {
		// Dolibarr allows each supplier invoice number only once per supplier.
		setEventMessages($langs->trans('AuvoRechnungsimportErrDuplicateRef', dol_escape_htmltag($refSupplier), $existingInvoice->getNomUrl(1)), null, 'errors');
	} else {
		$result = 1;
		// Resolve all conflicting XML values explicitly before creating the invoice.
		$autoCreateSupplier = ($fkSoc <= 0);
		$selectedSupplier = new Societe($db);
		if (!$autoCreateSupplier) $selectedSupplier->fetch($fkSoc);
		$currentIban = '';
		$currentBankOwner = '';
		$currentBankName = '';
		if (!$autoCreateSupplier) {
			$selectedBank = new CompanyBankAccount($db);
			if ($selectedBank->fetch(0, '', $fkSoc, -1) > 0) {
				$currentIban = (string) $selectedBank->iban;
				$currentBankOwner = (string) $selectedBank->owner_name;
				$currentBankName = (string) $selectedBank->bank;
			}
		}
		$selectedContact = new Contact($db);
		$currentContact = '';
		if (!$autoCreateSupplier && !empty($extracted['supplier_email']) && $selectedContact->fetch(0, null, '', (string) $extracted['supplier_email'], 0, $fkSoc) > 0) {
			$currentContact = trim($selectedContact->firstname.' '.$selectedContact->lastname);
		}
		$postedSupplierData = array();
		foreach (array('name', 'address', 'zip', 'town', 'country', 'email', 'phone', 'vat', 'tax_id', 'bankowner', 'bankname') as $field) {
			$postedSupplierData[$field] = GETPOST('supplier_value_'.$field, $field === 'country' ? 'alpha' : 'restricthtml');
		}
		$address = is_array($extracted['supplier_address'] ?? null) ? $extracted['supplier_address'] : array();
		$extractedAddress = trim(implode("\n", array_filter(array((string) ($address['line1'] ?? ''), (string) ($address['line2'] ?? ''), (string) ($address['line3'] ?? '')), 'strlen')));
		$comparisonRows = array(
			'update_supplier_name' => array((string) ($selectedSupplier->name ?? ''), isset($_POST['supplier_value_name']) ? $postedSupplierData['name'] : (string) ($extracted['supplier_name'] ?? ''), $user->hasRight('societe', 'creer'), 'text'),
			'update_supplier_address' => array((string) ($selectedSupplier->address ?? ''), isset($_POST['supplier_value_address']) ? $postedSupplierData['address'] : $extractedAddress, $user->hasRight('societe', 'creer'), 'text'),
			'update_supplier_zip' => array((string) ($selectedSupplier->zip ?? ''), isset($_POST['supplier_value_zip']) ? $postedSupplierData['zip'] : (string) ($address['postcode'] ?? ''), $user->hasRight('societe', 'creer'), 'text'),
			'update_supplier_town' => array((string) ($selectedSupplier->town ?? ''), isset($_POST['supplier_value_town']) ? $postedSupplierData['town'] : (string) ($address['city'] ?? ''), $user->hasRight('societe', 'creer'), 'text'),
			'update_supplier_email' => array((string) ($selectedSupplier->email ?? ''), isset($_POST['supplier_value_email']) ? $postedSupplierData['email'] : (string) ($extracted['supplier_email'] ?? ''), $user->hasRight('societe', 'creer'), 'email'),
			'update_supplier_phone' => array((string) ($selectedSupplier->phone ?? ''), isset($_POST['supplier_value_phone']) ? $postedSupplierData['phone'] : (string) ($extracted['supplier_phone'] ?? ''), $user->hasRight('societe', 'creer'), 'phone'),
			'update_supplier_country' => array(strtoupper((string) ($selectedSupplier->country_code ?? '')), strtoupper(isset($_POST['supplier_value_country']) ? $postedSupplierData['country'] : ((string) ($extracted['supplier_country'] ?? '') ?: 'DE')), $user->hasRight('societe', 'creer'), 'country'),
			'update_supplier_vat' => array(auvorechnungsimport_normalize_vat($selectedSupplier->tva_intra ?? ''), auvorechnungsimport_normalize_vat(isset($_POST['supplier_value_vat']) ? $postedSupplierData['vat'] : ($extracted['supplier_vat'] ?? '')), $user->hasRight('societe', 'creer'), 'vat'),
			'update_supplier_tax_id' => array((string) ($selectedSupplier->idprof1 ?? ''), isset($_POST['supplier_value_tax_id']) ? $postedSupplierData['tax_id'] : (string) ($extracted['supplier_tax_id'] ?? ''), $user->hasRight('societe', 'creer'), 'tax_id'),
			'update_supplier_iban' => array($currentIban, (string) ($extracted['supplier_iban'] ?? ''), $user->hasRight('societe', 'creer'), 'iban'),
			'update_supplier_bankowner' => array($currentBankOwner, isset($_POST['supplier_value_bankowner']) ? $postedSupplierData['bankowner'] : (string) ($extracted['supplier_account_holder'] ?? ''), $user->hasRight('societe', 'creer'), 'text'),
			'update_supplier_bankname' => array($currentBankName, (string) (($extracted['supplier_bank_details']['bank_name'] ?? '') ?: ''), $user->hasRight('societe', 'creer'), 'text'),
			'update_supplier_contact' => array($currentContact, trim((string) ($extracted['supplier_contact_firstname'] ?? '').' '.(string) ($extracted['supplier_contact_lastname'] ?? '')), $user->hasRight('societe', 'contact', 'creer'), 'contact'),
		);
		$comparisonRows['update_supplier_name'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_name'][0];
		$comparisonRows['update_supplier_address'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_address'][0];
		$comparisonRows['update_supplier_zip'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_zip'][0];
		$comparisonRows['update_supplier_town'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_town'][0];
		$comparisonRows['update_supplier_country'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_country'][0];
		$comparisonRows['update_supplier_vat'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_vat'][0];
		$comparisonRows['update_supplier_bankowner'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_bankowner'][0];
		$comparisonRows['update_supplier_bankname'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_bankname'][0];
		$unresolvedConflicts = array();
		$supplierChoices = array();
		foreach ($comparisonRows as $postName => $comparison) {
			list($currentValue, $xmlValue, $canUpdate, $type) = $comparison;
			if ($xmlValue === '') continue;
			$normalize = function ($value) use ($type) {
				$value = trim((string) $value);
				if ($type === 'email') return strtolower($value);
				if ($type === 'country' || $type === 'iban') return strtoupper(preg_replace('/\\s+/', '', $value));
				if ($type === 'phone') return preg_replace('/[^0-9+]/', '', $value);
				return preg_replace('/\\s+/', ' ', strtolower($value));
			};
			$isConflict = $currentValue !== '' && $normalize($currentValue) !== $normalize($xmlValue);
			$choice = GETPOST($postName, 'alpha');
			if (!in_array($choice, array('update', 'keep'), true) && !$isConflict) {
				$choice = (trim($currentValue) === '' && $canUpdate) ? 'update' : 'keep';
			}
			if (($isConflict && !in_array($choice, array('update', 'keep'), true)) || ($choice === 'update' && !$canUpdate)) {
				$unresolvedConflicts[] = $postName;
			}
			$supplierChoices[$postName] = $choice;
		}
		if ($unresolvedConflicts) {
			$result = -1;
			setEventMessages($langs->trans('AuvoRechnungsimportResolveSupplierConflicts'), null, 'errors');
		}
		$db->begin();
		// Apply only the supplier data fields explicitly selected in the review form.
		$supplierChanges = array(
			'name' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_name'] ?? '') === 'update' ? $comparisonRows['update_supplier_name'][1] : '',
			'address' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_address'] ?? '') === 'update' ? $comparisonRows['update_supplier_address'][1] : '',
			'zip' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_zip'] ?? '') === 'update' ? $comparisonRows['update_supplier_zip'][1] : '',
			'town' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_town'] ?? '') === 'update' ? $comparisonRows['update_supplier_town'][1] : '',
			'email' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_email'] ?? '') === 'update' ? $comparisonRows['update_supplier_email'][1] : '',
			'phone' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_phone'] ?? '') === 'update' ? $comparisonRows['update_supplier_phone'][1] : '',
			'country' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_country'] ?? '') === 'update' ? strtoupper($comparisonRows['update_supplier_country'][1]) : '',
			'vat' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_vat'] ?? '') === 'update' ? $comparisonRows['update_supplier_vat'][1] : '',
			'tax_id' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_tax_id'] ?? '') === 'update' ? $comparisonRows['update_supplier_tax_id'][1] : '',
			'bankowner' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_bankowner'] ?? '') === 'update' ? $comparisonRows['update_supplier_bankowner'][1] : '',
			'bankname' => $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_bankname'] ?? '') === 'update' ? $comparisonRows['update_supplier_bankname'][1] : '',
		);
		if ($result > 0 && !$autoCreateSupplier && count(array_filter($supplierChanges, 'strlen')) > 0) {
			$supplier = new Societe($db);
			if ($supplier->fetch($fkSoc) <= 0) {
				$result = -1;
			} else {
				if ($supplierChanges['name'] !== '') $supplier->name = $supplierChanges['name'];
				if ($supplierChanges['address'] !== '') $supplier->address = $supplierChanges['address'];
				if ($supplierChanges['zip'] !== '') $supplier->zip = $supplierChanges['zip'];
				if ($supplierChanges['town'] !== '') $supplier->town = $supplierChanges['town'];
				if ($supplierChanges['email'] !== '') $supplier->email = $supplierChanges['email'];
				if ($supplierChanges['phone'] !== '') $supplier->phone = $supplierChanges['phone'];
				if ($supplierChanges['vat'] !== '') $supplier->tva_intra = $supplierChanges['vat'];
				if ($supplierChanges['tax_id'] !== '') $supplier->idprof1 = $supplierChanges['tax_id'];
				if ($supplierChanges['country'] !== '') {
					$supplier->country_code = $supplierChanges['country'];
					$supplier->country_id = 0;
				}
				if ($supplierChanges['country'] !== '') {
					$countryId = getCountry($supplierChanges['country'], '3', $db);
					if (is_numeric($countryId)) $supplier->country_id = (int) $countryId;
				}
				if ($supplier->update($fkSoc, $user) <= 0) $result = -1;
			}
		}
		if ($result > 0 && $autoCreateSupplier) {
			$createFields = $supplierPreviewFields;
			$createFields['supplier_name'] = $supplierChanges['name'] !== '' ? $supplierChanges['name'] : (string) ($extracted['supplier_name'] ?? '');
			$createFields['supplier_email'] = $supplierChanges['email'] !== '' ? $supplierChanges['email'] : (string) ($extracted['supplier_email'] ?? '');
			$createFields['supplier_phone'] = $supplierChanges['phone'] !== '' ? $supplierChanges['phone'] : (string) ($extracted['supplier_phone'] ?? '');
			$createFields['supplier_country'] = $supplierChanges['country'] !== '' ? $supplierChanges['country'] : (string) ($extracted['supplier_country'] ?? '');
			$createFields['supplier_vat'] = $supplierChanges['vat'] !== '' ? $supplierChanges['vat'] : (string) ($extracted['supplier_vat'] ?? '');
			$createFields['supplier_tax_id'] = $supplierChanges['tax_id'];
			$createFields['supplier_address'] = array(
				'line1' => $supplierChanges['address'] !== '' ? $supplierChanges['address'] : (string) ($address['line1'] ?? ''), 'line2' => (string) ($address['line2'] ?? ''), 'line3' => (string) ($address['line3'] ?? ''),
				'postcode' => $supplierChanges['zip'] !== '' ? $supplierChanges['zip'] : (string) ($address['postcode'] ?? ''), 'city' => $supplierChanges['town'] !== '' ? $supplierChanges['town'] : (string) ($address['city'] ?? ''), 'country' => $supplierChanges['country'] !== '' ? $supplierChanges['country'] : (string) ($address['country'] ?? ''),
			);
			$supplierCreateError = '';
			$fkSoc = auvorechnungsimport_create_supplier_from_fields($db, $user, $createFields, $supplierChoices, $supplierCreateError, $supplierMode === 'new');
			if ($fkSoc <= 0) {
				$result = -1;
				setEventMessages($langs->trans('AuvoRechnungsimportErrAutoCreateSupplier'), array_filter(array($supplierCreateError)), 'errors');
			}
		}
		if ($result > 0 && $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_iban'] ?? '') === 'update' && !empty($extracted['supplier_iban'])) {
			$iban = preg_replace('/\\s+/', '', strtoupper((string) $extracted['supplier_iban']));
			$bankDetails = is_array($extracted['supplier_bank_details'] ?? null) ? $extracted['supplier_bank_details'] : auvorechnungsimport_bank_details_from_iban($iban);
			$bankName = ($supplierChoices['update_supplier_bankname'] ?? '') === 'update' ? (string) ($supplierChanges['bankname'] ?: ($bankDetails['bank_name'] ?? '')) : '';
			$validIban = checkIbanForAccount(null, $iban);
			if (strpos($iban, 'DE') === 0 && !auvorechnungsimport_is_valid_de_iban($iban)) $validIban = false;
			if (!$validIban) {
				$result = -1;
				setEventMessages($langs->trans('IBANNotValid'), null, 'errors');
			}
			$ibanQuery = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."societe_rib WHERE fk_soc = ".((int) $fkSoc));
			$ibanExists = false;
			$hasExistingBankAccount = false;
			$matchingBank = null;
			if ($result > 0 && $ibanQuery) {
				while ($ibanRow = $db->fetch_object($ibanQuery)) {
					$hasExistingBankAccount = true;
					$existingBank = new CompanyBankAccount($db);
					if ($existingBank->fetch((int) $ibanRow->rowid) > 0 && preg_replace('/\\s+/', '', strtoupper((string) $existingBank->iban)) === $iban) {
						$ibanExists = true;
						$matchingBank = $existingBank;
						break;
					}
				}
			}
			if ($result > 0 && !$ibanExists) {
				$bank = new CompanyBankAccount($db);
				$bank->socid = $fkSoc;
				$bank->type = 'ban';
				$bank->status = Account::STATUS_OPEN;
				if ($bank->create($user) > 0) {
					$bank->iban = $iban;
					$bank->bank = $bankName;
					$bank->label = $bankName !== '' ? dol_trunc($bankName, 200) : 'Bankkonto '.substr($iban, -4);
					$bank->code_banque = (string) ($bankDetails['bank_code'] ?? '');
					$bank->number = (string) ($bankDetails['account_number'] ?? '');
					$bank->bic = (string) ($bankDetails['bic'] ?? '');
					$bank->currency_code = (string) ($bankDetails['currency_code'] ?? 'EUR');
					$bank->fk_country = (int) getCountry((string) ($bankDetails['country_code'] ?? 'DE'), '3', $db);
					$bank->country_code = (string) ($bankDetails['country_code'] ?? 'DE');
					$bankAddress = trim((string) ($bankDetails['bank_postal_code'] ?? '').' '.(string) ($bankDetails['bank_city'] ?? ''));
					$bank->address = $bankAddress;
					$ownerName = ($supplierChoices['update_supplier_bankowner'] ?? '') === 'update' ? (string) $supplierChanges['bankowner'] : '';
					$bank->owner_name = dol_trunc($ownerName, 60);
					$supplierAddress = is_array($extracted['supplier_address'] ?? null) ? $extracted['supplier_address'] : array();
					$normalizedOwner = preg_replace('/[^a-z0-9]/', '', strtolower($ownerName));
					$normalizedSupplier = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($extracted['supplier_name'] ?? '')));
					if ($normalizedOwner !== '' && $normalizedOwner === $normalizedSupplier) {
						$bank->owner_address = trim(implode(', ', array_filter(array(
							(string) ($supplierAddress['line1'] ?? ''), (string) ($supplierAddress['line2'] ?? ''),
							trim((string) ($supplierAddress['postcode'] ?? '').' '.(string) ($supplierAddress['city'] ?? '')),
						), 'strlen')));
						$bank->owner_country_id = (int) getCountry((string) ($supplierAddress['country'] ?? $bankDetails['country_code'] ?? 'DE'), '3', $db);
					}
					$bank->default_rib = $hasExistingBankAccount ? 0 : 1;
					if ($bank->update($user) <= 0) $result = -1;
				} else {
					$result = -1;
				}
			} elseif ($result > 0 && $ibanExists && $matchingBank) {
				$bankChanged = false;
				if (($supplierChoices['update_supplier_bankname'] ?? '') === 'update' && $supplierChanges['bankname'] !== '') {
					$matchingBank->bank = $supplierChanges['bankname'];
					$matchingBank->label = dol_trunc($supplierChanges['bankname'], 200);
					$bankChanged = true;
				}
				$fillBankFields = array(
					'code_banque' => (string) ($bankDetails['bank_code'] ?? ''),
					'number' => (string) ($bankDetails['account_number'] ?? ''),
					'bic' => (string) ($bankDetails['bic'] ?? ''),
					'currency_code' => (string) ($bankDetails['currency_code'] ?? 'EUR'),
					'address' => trim((string) ($bankDetails['bank_postal_code'] ?? '').' '.(string) ($bankDetails['bank_city'] ?? '')),
				);
				foreach ($fillBankFields as $property => $value) {
					if (trim((string) ($matchingBank->$property ?? '')) === '' && trim($value) !== '') {
						$matchingBank->$property = $value;
						$bankChanged = true;
					}
				}
				if (empty($matchingBank->fk_country) && !empty($bankDetails['country_code'])) {
					$matchingBank->fk_country = (int) getCountry((string) $bankDetails['country_code'], '3', $db);
					$bankChanged = true;
				}
				if (trim((string) $matchingBank->label) === '' && $bankName !== '') {
					$matchingBank->label = dol_trunc($bankName, 200);
					$bankChanged = true;
				}
				if (!$hasExistingBankAccount) {
					$matchingBank->default_rib = 1;
					$bankChanged = true;
				}
				if (($supplierChoices['update_supplier_bankowner'] ?? '') === 'update' && $supplierChanges['bankowner'] !== '') {
					$matchingBank->owner_name = dol_trunc($supplierChanges['bankowner'], 60);
					$bankChanged = true;
				}
				if ($bankChanged && $matchingBank->update($user) <= 0) $result = -1;
			}
		}
		if ($result > 0 && $user->hasRight('societe', 'contact', 'creer') && ($supplierChoices['update_supplier_contact'] ?? '') === 'update'
			&& !empty($extracted['supplier_contact_firstname']) && !empty($extracted['supplier_contact_lastname'])) {
			$contact = new Contact($db);
			$contact->socid = $fkSoc;
			$contact->lastname = (string) $extracted['supplier_contact_lastname'];
			$contact->firstname = (string) $extracted['supplier_contact_firstname'];
			$contact->email = (string) ($extracted['supplier_email'] ?? '');
			$contact->phone_pro = (string) ($extracted['supplier_phone'] ?? '');
			$contact->status = 1;
			$contact->priv = 0;
			$contactEmail = (string) ($extracted['supplier_email'] ?? '');
			if ($contactEmail !== '' && $contact->fetch(0, null, '', $contactEmail, 0, $fkSoc) > 0) {
				$contact->lastname = (string) $extracted['supplier_contact_lastname'];
				$contact->firstname = (string) $extracted['supplier_contact_firstname'];
				$contact->phone_pro = (string) ($extracted['supplier_phone'] ?? '');
				if ($contact->update($contact->id, $user) <= 0) $result = -1;
			} elseif ($contact->create($user) <= 0) {
				$result = -1;
			}
		}
		$invoice = new FactureFournisseur($db);
		$invoice->socid = $fkSoc;
		$invoice->ref_supplier = $refSupplier;
		$invoice->date = $invoiceDate ? dol_stringtotime($invoiceDate) : dol_now();
		$invoice->type = FactureFournisseur::TYPE_STANDARD;
		$invoice->fk_project = 0;
		$invoice->note_private = $langs->trans('AuvoRechnungsimportCreatedFrom', $object->ref);

		$result = ($result > 0) ? $invoice->create($user) : -1;
		if ($result > 0) {
			// addline() computes taxes with the supplier as seller.
			$invoice->fetch_thirdparty();
		}

		// Invoice lines: everything the user ticked in the review table, with the
		// values as corrected there. The chosen product is learned for next time.
		$extractedLines = isset($extracted['lines']) && is_array($extracted['lines']) ? $extracted['lines'] : array();
		// Use the same invoice-line creation path for OCR/XML lines and the manual
		// fallback row. The fallback is only a rendered row when extraction found
		// nothing, so materialize its submitted values before processing it.
		if (empty($extractedLines)) {
			$extractedLines = array(array(
				'source' => 'manual', '_manual' => true,
				'mode' => GETPOST('manual_line_mode', 'aZ09'),
				'ref_supplier' => GETPOST('manual_line_ref_supplier', 'alphanohtml'),
				'label' => GETPOST('manual_line_label', 'restricthtml'),
				'qty' => GETPOST('manual_line_qty', 'alpha'),
				'price' => GETPOST('manual_line_price', 'alpha'),
				'vat_rate' => GETPOST('manual_line_vat', 'alpha'),
				'price_base' => GETPOST('manual_line_price_base', 'alpha'),
				'fk_product' => GETPOSTINT('manual_line_product_id'),
			));
		}
		$updateBuyprice = GETPOSTINT('update_buyprice') && $canUpdateBuyprice;
		$productTypes = array();
		$createdVariants = array();
		foreach ($extractedLines as $i => $line) {
			if ($result <= 0) {
				break;
			}
			$isManualLine = !empty($line['_manual']);
			$modeField = $isManualLine ? 'manual_line_mode' : 'line_mode_'.$i;
			$refField = $isManualLine ? 'manual_line_ref_supplier' : 'line_ref_supplier_'.$i;
			$labelField = $isManualLine ? 'manual_line_label' : 'line_label_'.$i;
			$qtyField = $isManualLine ? 'manual_line_qty' : 'line_qty_'.$i;
			$priceField = $isManualLine ? 'manual_line_price' : 'line_price_'.$i;
			$vatField = $isManualLine ? 'manual_line_vat' : 'line_vat_'.$i;
			$priceBaseField = $isManualLine ? 'manual_line_price_base' : 'line_price_base_'.$i;
			$productField = $isManualLine ? 'manual_line_product_id' : 'lineprod_'.$i;
			$mode = GETPOST($modeField, 'aZ09');
			if (!in_array($mode, AUVORECHNUNGSIMPORT_LINE_MODES, true)) {
				$mode = 'book';
			}
			$fkProduct = ($mode === 'book') ? GETPOSTINT($productField) : 0;
			$line['ref_supplier'] = trim(GETPOST($refField, 'alphanohtml'));
			$line['label'] = GETPOST($labelField, 'restricthtml');
			// Remember cost/skip (or that it's booked normally again) for next time.
			if (auvorechnungsimport_learn_line_mode($db, $user, $fkSoc, $line, $mode) < 0) {
				$invoice->errors[] = $db->lasterror();
				$result = -1;
				break;
			}
			if ($mode === 'skip') {
				continue;
			}
			$line['qty'] = (float) price2num(GETPOST($qtyField, 'alpha'));
			$line['price'] = (float) price2num(GETPOST($priceField, 'alpha'), 'MU');
			$line['vat_rate'] = (float) price2num(GETPOST($vatField, 'alpha'));
			$line['vat_src_code'] = (($extracted['vat_treatment'] ?? '') === 'DE_KLEINUNTERNEHMER_19') ? getDolGlobalString('AUVORECHNUNGSIMPORT_VAT_SOURCE_CODE', 'K19') : (string) ($line['vat_src_code'] ?? '');
			$submittedPriceBase = GETPOST($priceBaseField, 'alpha');
			if (in_array($submittedPriceBase, array('HT', 'TTC'), true)) $line['price_base'] = $submittedPriceBase;

			// Variant to be created from this line (only if no product was chosen).
			// Planned again here from the label as submitted - the user may have
			// corrected it, e.g. an OCR misread color.
			if (!$isManualLine && $mode === 'book' && $fkProduct <= 0 && GETPOSTINT('create_variant_'.$i) && $canCreateProduct) {
				$planned = auvorechnungsimport_match_lines($db, $fkSoc, array($line));
				$planned = $planned[0];
				if (!empty($planned['fk_product'])) {
					$fkProduct = (int) $planned['fk_product'];
				} elseif (!empty($planned['variant_plan'])) {
					$fkProduct = auvorechnungsimport_create_variant($db, $user, (int) $planned['variant_parent'], $planned['variant_plan'], $variantErrors);
					if ($fkProduct <= 0) {
						$invoice->errors[] = $langs->trans('AuvoRechnungsimportErrCreateVariant', $planned['variant_plan']['ref']);
						$invoice->errors = array_merge($invoice->errors, $variantErrors);
						$result = -1;
						break;
					}
					$createdVariants[] = $planned['variant_plan']['ref'];
				}
			}

			$type = 0;
			if ($fkProduct > 0) {
				if (!isset($productTypes[$fkProduct])) {
					$product = new Product($db);
					$productTypes[$fkProduct] = ($product->fetch($fkProduct) > 0) ? (int) $product->type : 0;
				}
				$type = $productTypes[$fkProduct];
			}

			// addline() wants a positive unit price; allowances (negative) are booked
			// as a negative quantity instead, like Dolibarr does for discount lines.
			$qty = $line['qty'];
			$pu = $line['price'];
			if ($pu < 0) {
				$pu = -$pu;
				$qty = -$qty;
			}
			$vatRateForDolibarr = (string) $line['vat_rate'];
			if ($line['vat_src_code'] !== '') $vatRateForDolibarr .= ' ('.$line['vat_src_code'].')';
			$resline = $invoice->addline($line['label'], $pu, $vatRateForDolibarr, 0, 0, $qty, $fkProduct, 0, '', '', 0, 0, (($line['price_base'] ?? 'HT') === 'TTC' ? 'TTC' : 'HT'), $type, -1, 1, array(), null, 0, 0, (string) ($line['ref_supplier'] ?? ''));
			if ($resline <= 0) {
				$result = -1;
				break;
			}
			// Product mapping only for normally booked lines - a cost line keeps
			// whatever product mapping the label may have had.
			if ($mode === 'book' && auvorechnungsimport_learn_line($db, $user, $fkSoc, $line, $fkProduct) < 0) {
				$invoice->errors[] = $db->lasterror();
				$result = -1;
				break;
			}
			if ($updateBuyprice && $fkProduct > 0 && $line['price'] > 0 && auvorechnungsimport_update_buyprice($db, $user, $fkSoc, $fkProduct, $line) < 0) {
				$invoice->errors[] = $langs->trans('AuvoRechnungsimportErrBuyprice', $line['label']);
				$result = -1;
				break;
			}
		}
		if ($result > 0) {
			$originalPath = auvorechnungsimport_resolve_file($object->filepath);
			if ($originalPath === false || auvorechnungsimport_blockedlog_store_received_file($db, $object, $originalPath, $user, 'review-approval') < 0) {
				if (getDolGlobalInt('AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE')) {
					$result = -1;
					setEventMessages($langs->trans('AuvoRechnungsimportErrBlockedLogStore'), null, 'errors');
				}
			}
		}
		if ($result > 0 && $invoice->fetch($invoice->id) <= 0) {
			$result = -1;
			setEventMessages($langs->trans('AuvoRechnungsimportErrValidateSupplierInvoice', $invoice->error), null, 'errors');
		}
		if ($result > 0) {
			// Confirm the amounts calculated by Dolibarr before validation. If they
			// differ from the invoice header, rollback the whole transaction below.
			$tolerance = max(0.02, 0.01 * (int) $reviewTotals['count']);
			$actualChecks = array();
			if ($reviewTotals['expected_net'] !== null) {
				$actualChecks[] = array('HT', (float) $invoice->total_ht, (float) $reviewTotals['expected_net']);
			}
			if ($reviewTotals['expected_gross'] !== null) {
				$actualChecks[] = array('TTC', (float) $invoice->total_ttc, (float) $reviewTotals['expected_gross']);
			}
			$actualMismatchMessages = array();
			foreach ($actualChecks as $check) {
				if (abs($check[1] - $check[2]) > $tolerance) {
					$actualMismatchMessages[] = $langs->trans('AuvoRechnungsimportErrReviewTotalMismatch'.$check[0], price($check[1]), price($check[2]));
				}
			}
			if ($actualMismatchMessages) {
				$result = -1;
				setEventMessages(null, $actualMismatchMessages, 'errors');
			}
		}
		if ($result > 0 && $invoice->validate($user) <= 0) {
			$result = -1;
			setEventMessages($langs->trans('AuvoRechnungsimportErrValidateSupplierInvoice', $invoice->error), null, 'errors');
		} elseif ($result > 0 && $invoice->fetch($invoice->id) <= 0) {
			$result = -1;
		}
		if ($result > 0) {

			if (!empty($createdVariants)) {
				setEventMessages($langs->trans('AuvoRechnungsimportVariantsCreated', implode(', ', $createdVariants)), null, 'mesgs');
			}
			if (auvorechnungsimport_attach_original($object, $invoice) < 0) {
				setEventMessages($langs->trans('AuvoRechnungsimportErrAttach'), null, 'warnings');
			}
			$object->fk_facture_fourn = $invoice->id;
			$object->status = ImportedInvoice::STATUS_IMPORTED;
			$resultupdate = $object->update($user);
			if ($resultupdate > 0 && auvorechnungsimport_blockedlog_log_validated_invoice($db, $invoice, $user, $object) <= 0) {
				$resultupdate = -1;
				setEventMessages($langs->trans('AuvoRechnungsimportErrBlockedLogValidation'), null, 'errors');
			}
			if ($resultupdate > 0) {
				$db->commit();
				// After the commit: a failing order must not take the invoice with it.
				if (GETPOSTINT('create_order') && $canCreateOrder && $invoice->fetch($invoice->id) > 0) {
					auvorechnungsimport_card_create_order($db, $user, $langs, $invoice, $extracted);
				}
				$nextId = auvorechnungsimport_next_to_review($db, $object->id);
				if ($nextId) {
					setEventMessages($langs->trans('AuvoRechnungsimportNextHint', dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.$nextId), null, 'mesgs');
				}
				header('Location: '.dol_buildpath('/fourn/facture/card.php', 1).'?facid='.$invoice->id);
				exit;
			}
		}
		$db->rollback();
		setEventMessages($langs->trans('AuvoRechnungsimportErrCreate'), array_merge($invoice->errors, $object->errors), 'errors');
	}
}

if (in_array($action, array('createorder', 'attachpdf'), true) && $object->status == ImportedInvoice::STATUS_IMPORTED && $object->fk_facture_fourn > 0
	&& $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$invoice = new FactureFournisseur($db);
	if ($invoice->fetch($object->fk_facture_fourn) > 0) {
		if ($action == 'createorder' && $canCreateOrder && !auvorechnungsimport_linked_order_id($db, $invoice)) {
			auvorechnungsimport_card_create_order($db, $user, $langs, $invoice, $extracted);
		} elseif ($action == 'attachpdf') {
			$res = auvorechnungsimport_attach_original($object, $invoice);
			setEventMessages($langs->trans($res < 0 ? 'AuvoRechnungsimportErrAttach' : 'AuvoRechnungsimportAttached'), null, $res < 0 ? 'errors' : 'mesgs');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'reprocess' && $user->hasRight('auvorechnungsimport', 'imports', 'write') && !in_array((int) $object->status, array(ImportedInvoice::STATUS_IMPORTED, ImportedInvoice::STATUS_DUPLICATE), true)) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$srcfile = auvorechnungsimport_resolve_file($object->filepath);
	if ($srcfile !== false) {
		// Requeue for the module's Dolibarr Cron job. The web request does not
		// run OCR or spawn a platform-specific process.
		$object->status = ImportedInvoice::STATUS_QUEUED;
		$object->update($user);
		setEventMessages($langs->trans('AuvoRechnungsimportReprocessedCron'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	} else {
		setEventMessages($langs->trans('AuvoRechnungsimportErrFileMissing'), null, 'errors');
	}
}

if ($action == 'confirm_delete' && GETPOST('confirm') == 'yes' && $user->hasRight('auvorechnungsimport', 'imports', 'delete')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	if ((int) $object->status === ImportedInvoice::STATUS_DUPLICATE) {
		setEventMessages($langs->trans('AuvoRechnungsimportDuplicateDeleteBlocked'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	$filetoremove = auvorechnungsimport_resolve_file($object->filepath);
	$db->begin();
	$logResult = auvorechnungsimport_blockedlog_log_import_deleted($db, $object, $user, $filetoremove);
	if ($logResult <= 0) {
		$db->rollback();
		setEventMessages($langs->trans('AuvoRechnungsimportErrDeleteLog'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	$result = $object->delete($user);
	if ($result > 0) {
		$db->commit();
		if ($filetoremove !== false) {
			dol_delete_file($filetoremove, 1);
			dol_delete_dir(dirname($filetoremove), 1);
		}
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1));
		exit;
	} else {
		$db->rollback();
		setEventMessages($langs->trans('AuvoRechnungsimportErrDelete'), $object->errors, 'errors');
	}
}

llxHeader('', $object->ref);

if ($action == 'delete' && (int) $object->status !== ImportedInvoice::STATUS_DUPLICATE && $user->hasRight('auvorechnungsimport', 'imports', 'delete')) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('AuvoRechnungsimportDeleteTitle'), $langs->trans('AuvoRechnungsimportDeleteConfirm'), 'confirm_delete', '', '', 1);
}

// Back to the list this record belongs to (to review / imported / ...)
$backStatus = array(
	ImportedInvoice::STATUS_NEEDS_REVIEW => '1,3', ImportedInvoice::STATUS_MATCHED => '1,3',
	ImportedInvoice::STATUS_QUEUED => '4,0', ImportedInvoice::STATUS_PROCESSING => '4,0',
	ImportedInvoice::STATUS_SPAM_REVIEW => (string) ImportedInvoice::STATUS_SPAM_REVIEW,
	ImportedInvoice::STATUS_ERROR => '9', ImportedInvoice::STATUS_IMPORTED => '2',
	ImportedInvoice::STATUS_DUPLICATE => ImportedInvoice::STATUS_DUPLICATE.','.ImportedInvoice::STATUS_SPAM_CONFIRMED,
);
$backUrl = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1).(isset($backStatus[(int) $object->status]) ? '?search_status='.$backStatus[(int) $object->status] : '');
print '<a href="'.$backUrl.'">'.img_picto('', 'back', 'class="paddingrightonly"').$langs->trans('BackToList').'</a>';


$morehtmlright = '';
if ((int) $object->status === ImportedInvoice::STATUS_SPAM_REVIEW && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	$spamConfirmUrl = $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirmspam&token='.newToken();
	$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportConfirmSpam'), 'default', $spamConfirmUrl, '', 1, array('confirm' => array('content' => $langs->trans('AuvoRechnungsimportConfirmSpamPrompt'))));
}
if ($user->hasRight('auvorechnungsimport', 'imports', 'write') && !in_array((int) $object->status, array(ImportedInvoice::STATUS_IMPORTED, ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_SPAM_CONFIRMED), true)) {
		$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportReprocessBtn'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=reprocess&token='.newToken(), '', 1);
	}
	if ($detectedDuplicate && $user->hasRight('auvorechnungsimport', 'imports', 'write') && !in_array((int) $object->status, array(ImportedInvoice::STATUS_IMPORTED, ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_SPAM_CONFIRMED), true)) {
		$duplicateUrl = $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirmduplicate&fk_soc='.(int) $duplicateCandidateSupplierId.'&ref_supplier='.urlencode($duplicateCandidateRef).'&token='.newToken();
		$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportConfirmDuplicate'), 'default', $duplicateUrl, '', 1, array('confirm' => array('content' => $langs->trans('AuvoRechnungsimportConfirmDuplicatePrompt'))));
	}
	if ($user->hasRight('auvorechnungsimport', 'imports', 'delete') && !in_array((int) $object->status, array(ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_SPAM_CONFIRMED), true)) $morehtmlright .= dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete', '', 1);
$isSpamReview = (int) $object->status === ImportedInvoice::STATUS_SPAM_REVIEW;
$nextId = auvorechnungsimport_next_to_review($db, $object->id, $isSpamReview);
if ($nextId) {
	$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportNextBtn'), 'default', $_SERVER['PHP_SELF'].'?id='.$nextId, '', 1);
}

// No tabs: a single "Card" tab only linked to the page itself.
print dol_get_fiche_head(array(), '', '', -1, '', 0, $morehtmlright);

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>'.dol_escape_htmltag($object->ref).'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportOriginalFilename').'</td><td>'.dol_escape_htmltag($object->original_filename).'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportExtractionMethod').'</td><td>'.dol_escape_htmltag($object->extraction_method).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(1).'</td></tr>';
if ((int) $object->status === ImportedInvoice::STATUS_ERROR && $object->getAnalysisError() !== '') {
	print '<tr><td>'.$langs->trans('AuvoRechnungsimportAnalysisErrorDetail').'</td><td><div class="error">'.dol_escape_htmltag($object->getAnalysisError()).'</div></td></tr>';
}
print '</table>';
print '</div>';

print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('AuvoRechnungsimportDetectedVat').'</td><td>'.dol_escape_htmltag($extracted['supplier_vat'] ?? '').'</td></tr>';
if (!empty($extracted['supplier_tax_id'])) print '<tr><td>'.$langs->trans('AuvoRechnungsimportDetectedTaxId').'</td><td>'.dol_escape_htmltag($extracted['supplier_tax_id']).'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportDetectedName').'</td><td>'.dol_escape_htmltag($extracted['supplier_name'] ?? '').'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportMatchConfidence').'</td><td>'.dol_escape_htmltag($object->supplier_match_confidence).'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportDetectedAmount').'</td><td>'.(isset($extracted['amount_ttc']) && $extracted['amount_ttc'] !== null ? price($extracted['amount_ttc']).' '.dol_escape_htmltag($extracted['currency'] ?? '') : '').'</td></tr>';
print '</table>';
print '</div>';
print '<div class="clearboth"></div>';
print '</div>';

print dol_get_fiche_end();

print '<script>document.body.classList.add("auvorechnungsimport-menu-hidden");</script>';
print '<style>
#auvorechnungsimport-review-layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:16px;align-items:start}
#auvorechnungsimport-review-preview,#auvorechnungsimport-review-form{min-width:0}
#auvorechnungsimport-review-preview{position:sticky;top:70px}
#auvorechnungsimport-review-preview iframe{height:calc(100vh - 100px);min-height:600px}
#auvorechnungsimport-lines-table{table-layout:fixed}
#auvorechnungsimport-lines-table td,#auvorechnungsimport-lines-table th{padding:5px!important}
#auvorechnungsimport-lines-table input:not([type="hidden"]):not([name^="search_"]){width:100%!important;min-width:0!important;max-width:none!important;box-sizing:border-box}
#auvorechnungsimport-lines-table th:nth-child(1),#auvorechnungsimport-lines-table td:nth-child(1){width:46px}
#auvorechnungsimport-lines-table td:nth-child(1) select{width:36px;max-width:36px}
.auvorechnungsimport-mode-picker{position:relative;display:inline-block;width:34px;height:30px;vertical-align:middle}
.auvorechnungsimport-mode-code{width:34px;height:30px;padding:0;border:1px solid #bbb;border-radius:3px;background:var(--colorbackbody);font-weight:bold;cursor:pointer}
.auvorechnungsimport-mode-picker select.auvorechnungsimport-mode{position:absolute;inset:0;width:34px!important;height:30px!important;opacity:0;cursor:pointer}
#auvorechnungsimport-lines-table th:nth-child(2),#auvorechnungsimport-lines-table td:nth-child(2){width:80px}
#auvorechnungsimport-lines-table th:nth-child(4),#auvorechnungsimport-lines-table td:nth-child(4){width:48px}
#auvorechnungsimport-lines-table th:nth-child(5),#auvorechnungsimport-lines-table td:nth-child(5){width:105px}
#auvorechnungsimport-lines-table th:nth-child(6),#auvorechnungsimport-lines-table td:nth-child(6){width:50px}
#auvorechnungsimport-lines-table th:nth-child(7),#auvorechnungsimport-lines-table td:nth-child(7){width:75px;white-space:nowrap}
#auvorechnungsimport-lines-table th:nth-child(8),#auvorechnungsimport-lines-table td:nth-child(8){width:48px}
.auvorechnungsimport-product-picker{position:relative;display:inline-flex;align-items:center;justify-content:center;width:34px;height:30px}
.auvorechnungsimport-product-search{width:30px;height:28px;padding:0;border:1px solid #bbb;border-radius:3px;background:var(--colorbackbody);cursor:pointer}
.auvorechnungsimport-product-search:hover{background:var(--colorbackvmenu1)}
.auvorechnungsimport-product-picker input[id^="search_"]{display:none!important;position:absolute;right:0;top:31px;z-index:1500;width:220px;min-width:220px;box-sizing:border-box;box-shadow:0 2px 6px #888}
.auvorechnungsimport-product-picker.is-open input[id^="search_"]{display:block!important}
.auvorechnungsimport-product-picker .ui-autocomplete{z-index:1501!important;max-height:260px;overflow-y:auto;overflow-x:hidden}
#auvorechnungsimport-menu-toggle{position:fixed;z-index:1200;left:0;top:50%;width:24px;height:34px;padding:0;border:1px solid #bbb;border-radius:0 4px 4px 0;background:var(--colorbackbody);cursor:pointer;box-shadow:1px 1px 4px #888}
#auvorechnungsimport-menu-toggle.is-open{border-radius:4px 0 0 4px}
#auvorechnungsimport-menu-toggle.is-pinned{color:#176b45;background:#e7f4ed}
#auvorechnungsimport-menu-toggle.is-pinned:hover:after{content:"";position:absolute;left:4px;right:4px;top:16px;border-top:2px solid currentColor;transform:rotate(-45deg)}
body.auvorechnungsimport-menu-hidden .side-nav{display:none!important}
body.auvorechnungsimport-menu-hidden #id-right{display:block!important;width:100%!important}
body.auvorechnungsimport-menu-hidden.auvorechnungsimport-menu-peek .side-nav{display:table-cell!important;position:fixed;z-index:1199;left:0;top:50px;bottom:0;width:260px;min-width:260px;max-width:min(360px,80vw);overflow-x:hidden;overflow-y:auto;background:var(--colorbackbody);box-shadow:2px 0 8px #777}
body.auvorechnungsimport-menu-hidden.auvorechnungsimport-menu-peek #id-left{display:block!important;width:260px!important;min-width:260px!important;max-width:min(360px,80vw)}
body.auvorechnungsimport-menu-hidden.auvorechnungsimport-menu-peek #id-right{margin-left:260px}
@media(max-width:1100px){#auvorechnungsimport-review-layout{display:block}#auvorechnungsimport-review-preview{position:static}#auvorechnungsimport-review-preview iframe{height:600px}}
</style>';
print '<button type="button" id="auvorechnungsimport-menu-toggle" title="Dolibarr-Menü anzeigen (Klick zum Fixieren)" aria-label="Dolibarr-Menü anzeigen"><span class="fas fa-angle-right" aria-hidden="true"></span></button>';
print '<div id="auvorechnungsimport-review-layout"><aside id="auvorechnungsimport-review-preview">';
$srcfile = auvorechnungsimport_resolve_file($object->filepath);
if ($srcfile !== false) {
	$fileUrl = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_file.php', 1).'?id='.$object->id;
	$einvoice = auvorechnungsimport_load_einvoice($srcfile);
	if (auvorechnungsimport_is_xml_file($srcfile)) {
		// XML e-invoice: no picture to show - the readable view instead.
		print '<div class="marginbottomonly"><a href="'.$fileUrl.'">'.img_picto('', 'download', 'class="pictofixedwidth"').$langs->trans('AuvoRechnungsimportDownloadXml').'</a></div>';
		if ($einvoice !== null) {
			print auvorechnungsimport_render_einvoice($einvoice, $fileUrl.'&att=');
		} else {
			print '<div class="error">'.$langs->trans('AuvoRechnungsimportErrNotEInvoice').'</div>';
		}
	} else {
		print '<div class="div-table-responsive-no-min marginbottomonly">';
		print '<div class="center">';
		print '<iframe src="'.$fileUrl.'" width="100%" height="600" style="border:1px solid #ccc;"></iframe>';
		print '</div>';
		print '</div>';
		if ($einvoice !== null) {
			// ZUGFeRD/Factur-X PDF: the embedded data, collapsed below the picture
			print '<details class="marginbottomonly"><summary class="cursorpointer">'.$langs->trans('AuvoRechnungsimportShowStructured').'</summary>';
			print auvorechnungsimport_render_einvoice($einvoice, $fileUrl.'&att=');
			print '</details>';
		}
	}
}

print '</aside><main id="auvorechnungsimport-review-form">';
print '<div class="div-table-responsive-no-min">';
if ($object->status == ImportedInvoice::STATUS_IMPORTED && $object->fk_facture_fourn > 0) {
	print '<div class="info">'.$langs->trans('AuvoRechnungsimportAlreadyImported').' ';
	$invoice = new FactureFournisseur($db);
	if ($invoice->fetch($object->fk_facture_fourn) > 0) {
		print $invoice->getNomUrl(1);
	}
	print '</div>';
	if ($invoice->id > 0 && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
		print '<table class="border centpercent">';
		// Goods receipt needs a supplier order (see auvorechnungsimport_create_supplier_order)
		if (isModEnabled('supplier_order')) {
			print '<tr><td class="titlefield">'.$langs->trans('AuvoRechnungsimportOrderForReception').'</td><td>';
			$orderId = auvorechnungsimport_linked_order_id($db, $invoice);
			if ($orderId) {
				$order = new CommandeFournisseur($db);
				$order->fetch($orderId);
				print $order->getNomUrl(1).' '.$order->getLibStatut(5);
			} elseif ($canCreateOrder) {
				print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=createorder&token='.newToken().'">'.$langs->trans('AuvoRechnungsimportCreateOrderBtn').'</a>';
			}
			print '</td></tr>';
		}
		$attachmentVerification = auvorechnungsimport_verify_invoice_attachment($db, $object, $invoice);
		print '<tr><td>'.$langs->trans('AuvoRechnungsimportOriginalAttached').'</td><td>';
		if (empty($attachmentVerification['attachment_exists'])) {
			print img_warning().' '.$langs->trans('AuvoRechnungsimportAttachmentMissing');
		} elseif ($attachmentVerification['status'] === 'match') {
			print img_picto('', 'tick').' '.$langs->trans('Yes');
		} elseif ($attachmentVerification['status'] === 'mismatch') {
			print img_warning().' '.$langs->trans('AuvoRechnungsimportAttachmentHashMismatch');
		} else {
			print img_warning().' '.$langs->trans('AuvoRechnungsimportAttachmentHashUnavailable');
		}
		if ($attachmentVerification['log_hash'] !== '') {
			print '<br>'.$langs->trans('AuvoRechnungsimportBlockedLogHash').': <code>'.dol_escape_htmltag($attachmentVerification['log_hash']).'</code>';
		}
		if ($attachmentVerification['attachment_hash'] !== '') {
			print '<br>'.$langs->trans('AuvoRechnungsimportAttachmentHash').': <code>'.dol_escape_htmltag($attachmentVerification['attachment_hash']).'</code>';
		}
		if (empty($attachmentVerification['attachment_exists']) && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
			print '<br><a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=attachpdf&token='.newToken().'">'.$langs->trans('AuvoRechnungsimportAttachBtn').'</a>';
		}
		print '</td></tr>';
		print '</table>';
	}
} elseif ((int) $object->status === ImportedInvoice::STATUS_DUPLICATE) {
	$duplicateData = $object->getExtractedData();
	$hashDuplicate = !empty($duplicateData['duplicate_reason']);
	$duplicateMessage = !empty($duplicateData['duplicate_reason']) && $duplicateData['duplicate_reason'] === 'ecm_md5' ? 'AuvoRechnungsimportEcmDuplicateRecord' : ($hashDuplicate ? 'AuvoRechnungsimportHashDuplicateRecord' : 'AuvoRechnungsimportDuplicateRecord');
	print '<div class="warning">'.$langs->trans($duplicateMessage).' ';
	if (!empty($duplicateData['duplicate_of_import_id'])) {
		$duplicateImport = new ImportedInvoice($db);
		if ($duplicateImport->fetch((int) $duplicateData['duplicate_of_import_id']) > 0) print $duplicateImport->getNomUrl(1);
	}
	if ($object->fk_facture_fourn > 0) {
		$invoice = new FactureFournisseur($db);
		if ($invoice->fetch($object->fk_facture_fourn) > 0) print ' · '.$invoice->getNomUrl(1);
	}
	if (!empty($duplicateData['duplicate_of_ecm_id'])) {
		$businessUrl = auvorechnungsimport_ecm_business_object_url($duplicateData['duplicate_of_ecm_object_type'] ?? '', $duplicateData['duplicate_of_ecm_object_id'] ?? 0);
		if ($businessUrl !== '') {
			print ' · <a href="'.dol_escape_htmltag($businessUrl).'">'.$langs->trans('AuvoRechnungsimportEcmLinkedBusinessDoc').'</a>';
		}
		$ecmUrl = DOL_URL_ROOT.'/ecm/file_card.php?id='.(int) $duplicateData['duplicate_of_ecm_id'];
		print ' · <a href="'.dol_escape_htmltag($ecmUrl).'">'.dol_escape_htmltag((string) $duplicateData['duplicate_of_ecm_filename']).'</a>';
	}
	print '</div>';
} elseif (in_array((int) $object->status, array(ImportedInvoice::STATUS_QUEUED, ImportedInvoice::STATUS_PROCESSING), true)) {
	print '<div class="info"><span class="fas fa-spinner fa-spin paddingright"></span>'.$langs->trans('AuvoRechnungsimportBeingAnalysed').'</div>';
	print '<script>setTimeout(function () { location.reload(); }, 5000);</script>';
	} elseif ((int) $object->status === ImportedInvoice::STATUS_SPAM_REVIEW) {
	print '<div class="info">'.$langs->trans('AuvoRechnungsimportSpamHeld').'</div>';
	} elseif ((int) $object->status === ImportedInvoice::STATUS_SPAM_CONFIRMED) {
	print '<div class="info">'.$langs->trans('AuvoRechnungsimportSpamConfirmed').'</div>';
} elseif ($user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	print load_fiche_titre($langs->trans('AuvoRechnungsimportReviewTitle'), '', '');
	if (!empty($extracted['is_credit_note'])) {
		print '<div class="warning">'.$langs->trans('AuvoRechnungsimportErrCreditNote').'</div>';
	}
	print '<div class="warning">'.$langs->trans(getDolGlobalInt('AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE') ? 'AuvoRechnungsimportValidationWarningLogged' : 'AuvoRechnungsimportValidationWarning').'</div>';
	if ($selfSupplierBlocked) {
		print '<div class="error">'.img_error().' '.$langs->trans('AuvoRechnungsimportOwnCompanySupplierWarning').'</div>';
	} elseif ($detectedOwnCompany) {
		print '<div class="warning">'.img_warning().' '.$langs->trans('AuvoRechnungsimportOwnCompanySupplierOverrideWarning').'</div>';
	}
	print '<form id="auvorechnungsimport-review-form" method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="createinvoice">';
	if ($nonSupplierMatchId) {
		$thirdparty = new Societe($db);
		if ($thirdparty->fetch($nonSupplierMatchId) > 0) {
			print '<div class="warning">'.$langs->trans('AuvoRechnungsimportWarnBusinessPartnerNotSupplier').' '.$thirdparty->getNomUrl(1).'</div>';
		}
	}
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans('ThirdParty').'</td><td>';
	print '<label class="inline-block paddingright"><input type="radio" name="supplier_mode" value="existing"'.($supplierMode === 'existing' ? ' checked="checked"' : '').'> '.$langs->trans('AuvoRechnungsimportSupplierExisting').'</label>';
	if ($manualSupplierCreateAllowed) {
		print '<label class="inline-block"><input type="radio" name="supplier_mode" value="new"'.($supplierMode === 'new' ? ' checked="checked"' : '').'> '.$langs->trans('AuvoRechnungsimportSupplierNew').'</label>';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('AuvoRechnungsimportSupplierNewUnavailable').'</span>';
	}
	print '<div id="existing_supplier_select_wrap"'.($supplierMode === 'new' ? ' style="display:none"' : '').'>';
	print $form->select_company($previewSupplierId, 'fk_soc', '(fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
	print ' <button type="submit" name="preview_supplier" value="1" class="button">'.$langs->trans('AuvoRechnungsimportPreviewSupplierUpdates').'</button></div>';
	print '</td></tr>';
	$requireInvoiceRef = getDolGlobalInt('AUVORECHNUNGSIMPORT_REQUIRE_INVOICE_REF');
	print '<tr><td'.($requireInvoiceRef ? ' class="fieldrequired"' : '').'>'.$langs->trans('RefSupplier').'</td><td><input type="text" name="ref_supplier" class="minwidth200" value="'.dol_escape_htmltag($extracted['invoice_ref'] ?? '').'">';
	if ($requireInvoiceRef) print ' <span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportInvoiceRefRequiredHint').'</span>';
	$existingInvoice = auvorechnungsimport_find_existing_invoice($db, $previewSupplierId, (string) ($extracted['invoice_ref'] ?? ''));
	if ($existingInvoice) {
		print ' '.img_warning().' '.$langs->trans('AuvoRechnungsimportWarnDuplicateRef', $existingInvoice->getNomUrl(1));
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('Date').'</td><td><input type="date" name="invoice_date" value="'.dol_escape_htmltag($extracted['invoice_date'] ?? '').'"></td></tr>';
	print '</table>';
	$autoCreatePreview = ($previewSupplierId <= 0 && $supplierMode === 'new' && auvorechnungsimport_can_create_supplier_from_fields($user, $supplierPreviewFields, true));
	$hasExtractedSupplierData = !empty($extracted['supplier_name']) || !empty($extracted['supplier_vat'])
		|| !empty($extracted['supplier_email']) || !empty($extracted['supplier_phone']) || !empty($extracted['supplier_iban']) || !empty($extracted['supplier_account_holder'])
		|| !empty($extracted['supplier_bank_details']['bank_name'])
		|| !empty($extracted['supplier_country']) || !empty(array_filter((array) ($extracted['supplier_address'] ?? array())))
		|| (!empty($extracted['supplier_contact_firstname']) && !empty($extracted['supplier_contact_lastname']));
	if (($previewSupplierId > 0 || $autoCreatePreview || $hasExtractedSupplierData) && $hasExtractedSupplierData) {
		$matchedSupplier = new Societe($db);
		if ($previewSupplierId > 0) $matchedSupplier->fetch($previewSupplierId);
		$currentBankValue = '';
		$currentBankOwner = '';
		$currentBankName = '';
		if ($previewSupplierId > 0) {
			$currentBank = new CompanyBankAccount($db);
			if ($currentBank->fetch(0, '', $previewSupplierId, -1) > 0) {
				$currentBankValue = (string) $currentBank->iban;
				$currentBankOwner = (string) $currentBank->owner_name;
				$currentBankName = (string) $currentBank->bank;
			}
		}
		print '<br>'.load_fiche_titre($langs->trans('AuvoRechnungsimportSupplierDataTitle'), '', '');
		print '<div class="info">'.$langs->trans('AuvoRechnungsimportSupplierPreviewHelp').'</div>';
		if ($autoCreatePreview) {
			$vatSuffix = !empty($supplierPreviewFields['supplier_vat']) ? ' ('.$langs->transnoentities('AuvoRechnungsimportDetectedVat').' '.(string) $supplierPreviewFields['supplier_vat'].')' : '';
			// trans() HTML-escapes the completed message, so pass raw values here.
			print '<div class="warning">'.$langs->trans('AuvoRechnungsimportNewSupplierWillCreate', (string) ($supplierPreviewFields['supplier_name'] ?? ''), $vatSuffix).'</div>';
		}
		print '<div class="div-table-responsive"><table class="border centpercent">';
		$currentContactText = '';
		if ($previewSupplierId > 0 && !empty($extracted['supplier_email'])) {
			$currentContact = new Contact($db);
			if ($currentContact->fetch(0, null, '', (string) $extracted['supplier_email'], 0, $previewSupplierId) > 0) {
				$currentContactText = $currentContact->getFullName($langs);
			}
		}
		print '<tr class="liste_titre"><td>'.$langs->trans('AuvoRechnungsimportSupplierDataField').'</td><td>'.$langs->trans('AuvoRechnungsimportSupplierDataCurrent').'</td><td>'.$langs->trans('AuvoRechnungsimportSupplierDataExtracted').'</td><td>'.$langs->trans('AuvoRechnungsimportSupplierDataAction').'</td></tr>';
		$address = is_array($extracted['supplier_address'] ?? null) ? $extracted['supplier_address'] : array();
		$supplierRows = array(
			'AuvoRechnungsimportSupplierName' => array('Name', (string) $matchedSupplier->name, (string) ($supplierPreviewFields['supplier_name'] ?? ''), 'name'),
			'VAT' => array('VAT', (string) $matchedSupplier->tva_intra, (string) ($extracted['supplier_vat'] ?? ''), 'vat'),
			'AuvoRechnungsimportSupplierTaxId' => array('AuvoRechnungsimportSupplierTaxId', (string) $matchedSupplier->idprof1, (string) ($supplierPreviewFields['supplier_tax_id'] ?? ''), 'tax_id'),
			'Address' => array('Address', (string) $matchedSupplier->address, trim(implode("\n", array_filter(array((string) ($address['line1'] ?? ''), (string) ($address['line2'] ?? ''), (string) ($address['line3'] ?? '')), 'strlen'))), 'address'),
			'Zip' => array('Zip', (string) $matchedSupplier->zip, (string) ($address['postcode'] ?? ''), 'zip'),
			'Town' => array('Town', (string) $matchedSupplier->town, (string) ($address['city'] ?? ''), 'town'),
			'Email' => array('Email', (string) $matchedSupplier->email, (string) ($extracted['supplier_email'] ?? ''), 'email'),
			'Phone' => array('Phone', (string) $matchedSupplier->phone, (string) ($extracted['supplier_phone'] ?? ''), 'phone'),
			'Country' => array('Country', (string) ($matchedSupplier->country_code ?? ''), (string) ($extracted['supplier_country'] ?? ''), 'country'),
			'IBAN' => array('IBAN', $currentBankValue, (string) ($extracted['supplier_iban'] ?? ''), 'iban'),
			'AuvoRechnungsimportAccountHolder' => array('AuvoRechnungsimportAccountHolder', $currentBankOwner, (string) ($extracted['supplier_account_holder'] ?? ''), 'bankowner'),
			'AuvoRechnungsimportBankName' => array('AuvoRechnungsimportBankName', $currentBankName, (string) ($extracted['supplier_bank_details']['bank_name'] ?? ''), 'bankname'),
			'AuvoRechnungsimportSupplierContact' => array('AuvoRechnungsimportSupplierContact', $currentContactText, (!empty($extracted['supplier_contact_firstname']) && !empty($extracted['supplier_contact_lastname'])) ? trim($extracted['supplier_contact_firstname'].' '.$extracted['supplier_contact_lastname'].' · '.(string) ($extracted['supplier_email'] ?? '')) : '', 'contact'),
		);
		foreach ($supplierRows as $row) {
			list($labelKey, $currentValue, $extractedValue, $field) = $row;
			if ($field === 'contact' && (empty($extracted['supplier_contact_firstname']) || empty($extracted['supplier_contact_lastname']))) continue;
			if ($field === 'country' && $extractedValue === '') $extractedValue = 'DE';
			if ($extractedValue === '' && !$autoCreatePreview && !($supplierMode === 'new' && $field === 'name')) continue;
			if ($supplierMode === 'new') $currentValue = '';
			$inputName = 'supplier_value_'.$field;
			$inputValue = isset($_POST[$inputName]) ? GETPOST($inputName, $field === 'country' ? 'alpha' : 'restricthtml') : $extractedValue;
			// A POST from the review form may carry an empty input (for example after
			// the supplier preview action). Keep the detected value visible in that case.
			if (trim((string) $inputValue) === '' && trim((string) $extractedValue) !== '') $inputValue = $extractedValue;
			$normalize = function ($value) use ($field) {
				$value = trim((string) $value);
				if ($field === 'email') return strtolower($value);
				if ($field === 'country' || $field === 'iban') return strtoupper(preg_replace('/\\s+/', '', $value));
				if ($field === 'vat') return auvorechnungsimport_normalize_vat($value);
				if ($field === 'phone') return preg_replace('/[^0-9+]/', '', $value);
				return preg_replace('/\\s+/', ' ', strtolower($value));
			};
			$conflict = trim($currentValue) !== '' && $normalize($currentValue) !== $normalize($inputValue);
			$postName = 'update_supplier_'.$field;
			$choice = GETPOST($postName, 'alpha');
			if (!in_array($choice, array('update', 'keep'), true)) {
				$savedChoice = (string) ($extracted['review_supplier_choices'][$field] ?? '');
				$choice = in_array($savedChoice, array('update', 'keep'), true) ? $savedChoice : ($conflict ? '' : ((trim($currentValue) === '' && $user->hasRight('societe', 'creer')) ? 'update' : 'keep'));
			}
			$required = ($conflict || ($supplierMode === 'new' && $field === 'name')) && $user->hasRight('societe', 'creer') ? ' required="required"' : '';
			$optionHtml = '<option value=""'.($choice === '' ? ' selected="selected"' : '').'>'.$langs->trans('AuvoRechnungsimportChooseAction').'</option>';
			if ($user->hasRight('societe', 'creer')) {
				$optionHtml .= '<option value="update"'.($choice === 'update' ? ' selected="selected"' : '').'>'.$langs->trans('AuvoRechnungsimportApplyUpdate').'</option>';
			}
			$optionHtml .= '<option value="keep"'.($choice === 'keep' ? ' selected="selected"' : '').'>'.$langs->trans('AuvoRechnungsimportKeepCurrent').'</option>';
			$inputHtml = '<input type="text" name="'.dol_escape_htmltag($inputName).'" class="minwidth200" value="'.dol_escape_htmltag($inputValue).'">';
			if ($field === 'country') {
				$countryValue = isset($_POST[$inputName]) ? GETPOST($inputName, 'alpha') : (string) ($extracted['supplier_country'] ?? '');
				if ($countryValue === '' && $extractedValue !== '') $countryValue = $extractedValue;
				$inputHtml = $form->select_country($countryValue, $inputName, '', 0, 'minwidth200', 'code2', 1, 0, 0, array(), 1);
			}
			print '<tr'.($conflict ? ' class="warning"' : '').'><td>'.$langs->trans($labelKey).'</td><td>'.dol_escape_htmltag($currentValue).'</td><td>'.$inputHtml.'</td><td><select name="'.dol_escape_htmltag($postName).'" class="flat"'.$required.'>'.$optionHtml.'</select></td></tr>';
		}
		print '</table></div>';
	}

	$lines = isset($extracted['lines']) && is_array($extracted['lines']) ? $extracted['lines'] : array();
	$manualEntry = empty($lines);
	$sourceLabels = array();
	if ($manualEntry) {
		$manual = is_array($extracted['manual_line'] ?? null) ? $extracted['manual_line'] : array();
		$manualMode = GETPOSTISSET('manual_line_mode') ? GETPOST('manual_line_mode', 'aZ09') : ($manual['mode'] ?? 'book');
		if (!in_array($manualMode, AUVORECHNUNGSIMPORT_LINE_MODES, true)) $manualMode = 'book';
		$manualQty = GETPOSTISSET('manual_line_qty') ? GETPOST('manual_line_qty', 'alpha') : ($manual['qty'] ?? '1');
		$manualPrice = GETPOSTISSET('manual_line_price') ? GETPOST('manual_line_price', 'alpha') : ($manual['price'] ?? '');
		$manualVat = GETPOSTISSET('manual_line_vat') ? GETPOST('manual_line_vat', 'alpha') : ($manual['vat'] ?? '');
		$lines = array(array(
			'source' => 'manual', '_manual' => true, 'mode' => $manualMode,
			'ref_supplier' => GETPOSTISSET('manual_line_ref_supplier') ? GETPOST('manual_line_ref_supplier', 'alphanohtml') : ($manual['ref_supplier'] ?? ''),
			'label' => GETPOSTISSET('manual_line_label') ? GETPOST('manual_line_label', 'restricthtml') : ($manual['label'] ?? $langs->trans('AuvoRechnungsimportManualLineDefault')),
			'qty' => $manualQty, 'price' => ($manualPrice === '' ? null : (float) price2num($manualPrice, 'MU')),
			'vat_rate' => ($manualVat === '' ? null : (float) price2num($manualVat)), 'price_base' => 'HT',
			'fk_product' => GETPOSTISSET('manual_line_product_id') ? GETPOSTINT('manual_line_product_id') : (int) ($manual['fk_product'] ?? 0),
		));
	} else {
		// Matched at display time against the currently assigned supplier, so what
		// was learned since the upload (or a supplier corrected via re-run) applies.
		$lines = auvorechnungsimport_match_lines($db, $previewSupplierId, $lines);
		$sourceLabels = array(
			'supplier_price' => array('AuvoRechnungsimportMatchSupplierPrice', 'status4'),
			'learned_ref' => array('AuvoRechnungsimportMatchLearned', 'status4'),
			'learned_label' => array('AuvoRechnungsimportMatchLearned', 'status4'),
			'barcode' => array('AuvoRechnungsimportMatchBarcode', 'status4'),
			'variant' => array('AuvoRechnungsimportMatchVariant', 'status4'),
			'suggest_ref' => array('AuvoRechnungsimportMatchSuggest', 'status1'),
			'suggest_label' => array('AuvoRechnungsimportMatchSuggest', 'status1'),
		);
	}

		// All lines of one invoice share the price base: text detection decides
		// net vs. gross per invoice, Factur-X is always net.
		$gross = (($lines[0]['price_base'] ?? 'HT') === 'TTC');
		print '<br>';
		print load_fiche_titre($langs->trans('AuvoRechnungsimportLinesTitle'), '', '');
		if (($extracted['vat_treatment'] ?? '') === 'DE_KLEINUNTERNEHMER_19') {
			print '<div class="info">'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportVatK19Notice', getDolGlobalString('AUVORECHNUNGSIMPORT_VAT_SOURCE_CODE', 'K19'))).'</div>';
		}
		if (($lines[0]['source'] ?? '') === 'text') {
			$hintKey = (($extracted['lines_check'] ?? '') === 'ok') ? 'AuvoRechnungsimportLinesFromTextOk' : 'AuvoRechnungsimportLinesFromTextCheck';
			$lineNotice = $gross ? $langs->trans('AuvoRechnungsimportLinesGross') : $langs->trans($hintKey);
			$lineNoticeClass = ($gross || $hintKey === 'AuvoRechnungsimportLinesFromTextOk') ? 'info' : 'warning';
			print '<div id="auvorechnungsimport-lines-feedback" class="'.$lineNoticeClass.'" style="white-space: pre-line">'.$lineNotice.'</div>';
		}
		if ($manualEntry) print '<div id="auvorechnungsimport-lines-feedback" class="warning">'.$langs->trans('AuvoRechnungsimportManualLineNeeded').'</div>';
		print '<div class="div-table-responsive">';
		print '<table id="auvorechnungsimport-lines-table" class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td class="center" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportLineMode')).'" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportLineMode')).'">🔧</td>';
		print '<td class="center" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportSupplierItemRefHelp')).'" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportSupplierItemRefHelp')).'">🆔</td>';
		print '<td>'.$langs->trans('Description').'</td>';
		print '<td class="right">'.$langs->trans('Qty').'</td>';
		print '<td class="right" id="auvorechnungsimport-price-header" data-ht="'.dol_escape_htmltag($langs->trans('PriceUHT')).'" data-ttc="'.dol_escape_htmltag($langs->trans('PriceUTTC')).'" data-mixed="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportPriceMixed')).'">'.$langs->trans($gross ? 'PriceUTTC' : 'PriceUHT').'</td>';
		print '<td class="right">'.$langs->trans('VAT').' %</td>';
		print '<td class="right" id="auvorechnungsimport-total-header" data-ht="'.dol_escape_htmltag($langs->trans('TotalHT')).'" data-ttc="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportTotalGrossShort')).'" data-mixed="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportTotalMixed')).'">'.$langs->trans($gross ? 'AuvoRechnungsimportTotalGrossShort' : 'TotalHT').'</td>';
		print '<td>'.$langs->trans('Product').'</td>';
		print '</tr>';

		// One product picker per line: with the full product list in every <select>
		// the page grows by the whole catalog per line (MBs for a long invoice).
		// Use Dolibarr's ajax product search for this table regardless of the
		// global setting - for this request only, nothing is saved.
		$savedSearchToSelect = getDolGlobalString('PRODUIT_USE_SEARCH_TO_SELECT');
		$conf->global->PRODUIT_USE_SEARCH_TO_SELECT = $savedSearchToSelect ?: '2';

		$sumReviewNet = 0;
		$sumReviewGross = 0;
		foreach ($lines as $i => $line) {
			$isManualLine = !empty($line['_manual']);
			$modeField = $isManualLine ? 'manual_line_mode' : 'line_mode_'.$i;
			$refField = $isManualLine ? 'manual_line_ref_supplier' : 'line_ref_supplier_'.$i;
			$labelField = $isManualLine ? 'manual_line_label' : 'line_label_'.$i;
			$qtyField = $isManualLine ? 'manual_line_qty' : 'line_qty_'.$i;
			$priceField = $isManualLine ? 'manual_line_price' : 'line_price_'.$i;
			$vatField = $isManualLine ? 'manual_line_vat' : 'line_vat_'.$i;
			$priceBaseField = $isManualLine ? 'manual_line_price_base' : 'line_price_base_'.$i;
			$mode = $line['mode'] ?? 'book';
			$priceBase = (($line['price_base'] ?? 'HT') === 'TTC') ? 'TTC' : 'HT';
			$productSelectId = $isManualLine ? 'manual_line_product_id' : 'lineprod_'.$i;
			print '<tr class="oddeven auvorechnungsimport-line'.($isManualLine ? ' auvorechnungsimport-manual-line' : '').($mode === 'skip' ? ' opacitymedium' : '').'" data-price-base="'.$priceBase.'" data-product-field="'.$productSelectId.'">';
			$modeCodes = array('book' => $langs->trans('AuvoRechnungsimportModeCodeBook'), 'cost' => $langs->trans('AuvoRechnungsimportModeCodeCost'), 'skip' => $langs->trans('AuvoRechnungsimportModeCodeSkip'));
			print '<td class="nowraponall">';
			print '<span class="auvorechnungsimport-mode-picker"><button type="button" class="auvorechnungsimport-mode-code" aria-hidden="true">'.dol_escape_htmltag($modeCodes[$mode] ?? '').'</button>';
			print '<select name="'.$modeField.'" class="flat auvorechnungsimport-mode" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportLineMode')).'" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportMode'.ucfirst($mode))).'">';
			foreach (AUVORECHNUNGSIMPORT_LINE_MODES as $m) {
				print '<option value="'.$m.'"'.($m === $mode ? ' selected' : '').'>'.$langs->trans('AuvoRechnungsimportMode'.ucfirst($m)).'</option>';
			}
			print '</select></span>';
			if (!empty($line['mode_source'])) {
				print '<br><span class="badge status4 small">'.$langs->trans($line['mode_source'] === 'learned' ? 'AuvoRechnungsimportMatchLearned' : 'AuvoRechnungsimportModeKeyword').'</span>';
			}
			print '</td>';
			print '<td class="nowraponall"><input type="text" name="'.$refField.'" value="'.dol_escape_htmltag($line['ref_supplier'] ?? '').'" title="'.dol_escape_htmltag($langs->trans('RefSupplier')).'">';
			if (!empty($line['gtin'])) {
				print '<br><span class="opacitymedium small">EAN '.dol_escape_htmltag($line['gtin']).'</span>';
			}
			print '</td>';
			print '<td><input type="text" name="'.$labelField.'"'.($isManualLine ? ' required' : '').' value="'.dol_escape_htmltag($line['label'] ?? '').'"></td>';
			print '<td class="right"><input type="text" name="'.$qtyField.'" class="right auvorechnungsimport-line-qty"'.($isManualLine ? ' required' : '').' value="'.dol_escape_htmltag(price2num($line['qty'] ?? 1)).'"></td>';
			print '<td class="right nowraponall"><span style="display:inline-flex;align-items:center;white-space:nowrap;gap:2px"><input type="text" name="'.$priceField.'" class="right auvorechnungsimport-line-price"'.($isManualLine ? ' required' : '').' value="'.dol_escape_htmltag($line['price'] !== null ? price($line['price'], 0, '', 0, -1, -1) : '').'">';
			print '<input type="hidden" name="'.$priceBaseField.'" value="'.$priceBase.'">';
			print '</span></td>';
			$vatRate = $line['vat_rate'];
			$inputQty = (float) ($line['qty'] ?? 0);
			$inputPrice = (float) ($line['price'] ?? 0);
			$inputVat = (float) ($vatRate ?? 0);
			$lineBase = $inputQty * $inputPrice;
			$lineNet = $priceBase === 'TTC' && $inputVat > 0 ? $lineBase / (1 + $inputVat / 100) : $lineBase;
			$lineGross = $priceBase === 'TTC' ? $lineBase : $lineBase * (1 + $inputVat / 100);
			$lineNet = round($lineNet, 2);
			$lineGross = round($lineGross, 2);
			if ($mode !== 'skip') {
				$sumReviewNet += $lineNet;
				$sumReviewGross += $lineGross;
			}
			print '<td class="right"><input type="text" name="'.$vatField.'" class="right auvorechnungsimport-line-vat" value="'.dol_escape_htmltag($vatRate !== null ? price2num($vatRate) : '').'" required></td>';
			print '<td class="right nowraponall"><span class="auvorechnungsimport-line-total">'.price($lineBase).'</span></td>';
			print '<td class="nowraponall">';
			$selectedProductLabel = '';
			if (!empty($line['fk_product'])) {
				$selectedProduct = new Product($db);
				if ($selectedProduct->fetch((int) $line['fk_product']) > 0) $selectedProductLabel = trim($selectedProduct->ref.' '.$selectedProduct->label);
			}
			print '<span class="auvorechnungsimport-product-picker"><button type="button" class="auvorechnungsimport-product-search" data-select="'.$productSelectId.'" title="'.dol_escape_htmltag($selectedProductLabel !== '' ? $selectedProductLabel : $langs->trans('Search')).'" aria-label="'.dol_escape_htmltag($langs->trans('Search')).'"><span class="fas fa-search" aria-hidden="true"></span></button>';
			print $form->select_produits($line['fk_product'], $productSelectId, '', 0, 0, -1, 2, '', 1, array(), 0, '1', 0, 'width30', 1, '', null, 1, 1).'</span>';
			if (!empty($line['match_source']) && isset($sourceLabels[$line['match_source']])) {
				list($labelKey, $badge) = $sourceLabels[$line['match_source']];
				print ' <span class="badge '.$badge.'">'.$langs->trans($labelKey).'</span>';
			}
			if (!empty($line['fk_product'])) {
				if (isset($selectedProduct) && $selectedProduct->id > 0 && empty($selectedProduct->status_buy)) {
					print '<br><span class="warning small">'.img_warning().' '.$langs->trans('AuvoRechnungsimportProductNotBuyable').'</span>';
				}
			}
			if (!empty($line['variant_parent'])) {
				$parent = new Product($db);
				if ($parent->fetch($line['variant_parent']) > 0) {
					if (!empty($line['variant_plan'])) {
						$plan = $line['variant_plan'];
						$newValues = array();
						foreach ($plan['attrs'] as $fkAttr => $v) {
							if (!empty($v['new'])) {
								$newValues[] = dol_escape_htmltag($v['ref'].' = '.$v['value']);
							}
						}
						print '<br><span class="small">'.$langs->trans('AuvoRechnungsimportVariantMissing', $parent->getNomUrl(0)).'</span>';
						if ($canCreateProduct) {
							print '<br><label class="small"><input type="checkbox" name="create_variant_'.$i.'" value="1"> '.$langs->trans('AuvoRechnungsimportCreateVariant', '<strong>'.dol_escape_htmltag($plan['ref']).'</strong>', dol_escape_htmltag($plan['label'])).'</label>';
							if ($newValues) {
								print '<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportCreateVariantNewValue', implode(', ', $newValues)).'</span>';
							}
						}
					} else {
						print '<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportVariantUnclear', $parent->getNomUrl(0)).'</span>';
					}
				}
			}
			print '</td>';
			print '</tr>';
		}

		$conf->global->PRODUIT_USE_SEARCH_TO_SELECT = $savedSearchToSelect;

		print '<tr class="liste_total"'.($gross ? ' style="display:none"' : '').'>';
		print '<td colspan="6" class="right">'.$langs->trans('AuvoRechnungsimportReviewNetSum').'</td>';
		print '<td class="right nowraponall" id="auvorechnungsimport-sum-net">'.price($sumReviewNet).'</td>';
		print '<td id="auvorechnungsimport-check-net"></td>';
		print '</tr>';
		print '<tr class="liste_total">';
		print '<td colspan="6" class="right">'.$langs->trans('AuvoRechnungsimportReviewGrossSum').'</td>';
		print '<td class="right nowraponall" id="auvorechnungsimport-sum-gross">'.price($sumReviewGross).'</td>';
		print '<td id="auvorechnungsimport-check-gross"></td>';
		print '</tr>';
		// What will actually be booked: without skipped lines (updated live)
		print '<tr class="liste_total">';
		print '<td colspan="6" class="right">'.$langs->trans('AuvoRechnungsimportToBook').'</td>';
		print '<td class="right nowraponall" id="auvorechnungsimport-tobook"></td>';
		print '<td id="auvorechnungsimport-skipwarn" class="warning small"></td>';
		print '</tr>';
		print '</table>';
		print '</div>';
		$fmt = array('dec' => $langs->transnoentitiesnoconv('SeparatorDecimal'), 'th' => $langs->transnoentitiesnoconv('SeparatorThousand'));
		print '<script>
(function () {
	var dec = '.json_encode($fmt['dec'] === 'SeparatorDecimal' ? ',' : $fmt['dec']).', th = '.json_encode(in_array($fmt['th'], array('SeparatorThousand', 'None'), true) ? '.' : $fmt['th']).', group = '.json_encode(in_array($fmt['th'], array('SeparatorThousand', 'None'), true) ? '' : $fmt['th']).';
	var requireInvoiceRef = '.(getDolGlobalInt('AUVORECHNUNGSIMPORT_REQUIRE_INVOICE_REF') ? 'true' : 'false').';
	var warn = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportSkipWarning', '__AMOUNT__')).';
	var invoiceNet = '.json_encode(isset($extracted['amount_ht']) ? (float) $extracted['amount_ht'] : null).';
	var invoiceGross = '.json_encode(isset($extracted['amount_ttc']) ? (float) $extracted['amount_ttc'] : null).';
	var mismatchNet = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportErrReviewTotalMismatchHT', '__SUM__', '__AMOUNT__')).';
	var mismatchGross = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportErrReviewTotalMismatchTTC', '__SUM__', '__AMOUNT__')).';
	var productRequiredMessage = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportErrProductRequiredForTakeover', '__LINES__')).';
	var manualEntry = '.($manualEntry ? 'true' : 'false').';
	var feedback = document.getElementById("auvorechnungsimport-lines-feedback");
	var initialFeedbackHtml = feedback ? feedback.innerHTML : "";
	var initialFeedbackClass = feedback ? feedback.className : "info";
	function fmt(n) {
		var p = Math.abs(n).toFixed(2).split(".");
		return (n < 0 ? "-" : "") + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, th) + dec + p[1];
	}
	function num(v) {
		var s = String(v || "").replace(/\\s/g, "");
		if (dec !== "." && s.indexOf(dec) >= 0) {
			if (group) s = s.split(group).join("");
			s = s.split(dec).join(".");
		} else if (dec === "." && group) {
			s = s.split(group).join("");
		}
		var n = parseFloat(s);
		return Number.isFinite(n) ? n : 0;
	}
	function roundMoney(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }
	function update() {
		var net = 0, gross = 0, booked = 0, skippedGross = 0, skippedNet = 0, count = 0, missingVat = false, manualIncomplete = false, missingProductLines = [];
		document.querySelectorAll("tr.auvorechnungsimport-line").forEach(function (tr) {
			var mode = tr.querySelector("select.auvorechnungsimport-mode").value;
			var productField = document.getElementById(tr.dataset.productField);
			var variantCheckbox = tr.querySelector("input[name^=\"create_variant_\"]");
			if (mode === "book" && (!productField || !productField.value) && !(variantCheckbox && variantCheckbox.checked)) missingProductLines.push(missingProductLines.length + 1);
			var qty = num(tr.querySelector(".auvorechnungsimport-line-qty").value);
			var unit = num(tr.querySelector(".auvorechnungsimport-line-price").value);
			var vatInput = tr.querySelector(".auvorechnungsimport-line-vat");
			if (vatInput.value.trim() === "") missingVat = true;
			if (tr.classList.contains("auvorechnungsimport-manual-line")) {
				var labelInput = tr.querySelector("input[name=manual_line_label]");
				if (!labelInput.value.trim() || qty <= 0 || tr.querySelector(".auvorechnungsimport-line-price").value.trim() === "" || vatInput.value.trim() === "") manualIncomplete = true;
			}
			var vat = num(vatInput.value);
			var base = qty * unit, lineNet, lineGross;
			if (tr.dataset.priceBase === "TTC") {
				lineGross = base;
				lineNet = vat > 0 ? base / (1 + vat / 100) : base;
			} else {
				lineNet = base;
				lineGross = base * (1 + vat / 100);
			}
			lineNet = roundMoney(lineNet);
			lineGross = roundMoney(lineGross);
			tr.querySelector(".auvorechnungsimport-line-total").textContent = fmt(base);
			tr.classList.toggle("opacitymedium", mode === "skip");
			if (mode === "skip") { skippedNet += lineNet; skippedGross += lineGross; }
			else { net += lineNet; gross += lineGross; booked += tr.dataset.priceBase === "TTC" ? lineGross : lineNet; count++; }
		});
		document.getElementById("auvorechnungsimport-sum-net").textContent = fmt(net);
		document.getElementById("auvorechnungsimport-sum-gross").textContent = fmt(gross);
		var targetNet = invoiceNet !== null ? invoiceNet - skippedNet : null;
		var targetGross = invoiceGross !== null ? invoiceGross - skippedGross : null;
		var tolerance = Math.max(0.02, 0.01 * count);
		var netMismatch = targetNet !== null && count && Math.abs(net - targetNet) > tolerance;
		var grossMismatch = targetGross !== null && count && Math.abs(gross - targetGross) > tolerance;
		document.getElementById("auvorechnungsimport-check-net").textContent = "";
		document.getElementById("auvorechnungsimport-check-gross").textContent = "";
		if (feedback) {
			var messages = [];
			if (missingProductLines.length) messages.push(productRequiredMessage.replace("__LINES__", missingProductLines.join(", ")));
			if (netMismatch) messages.push(mismatchNet.replace("__SUM__", fmt(net)).replace("__AMOUNT__", fmt(targetNet)));
			if (grossMismatch) messages.push(mismatchGross.replace("__SUM__", fmt(gross)).replace("__AMOUNT__", fmt(targetGross)));
			if (messages.length) feedback.textContent = messages.join("\\n");
			else if (manualEntry && !manualIncomplete) { feedback.textContent = ""; feedback.className = ""; }
			else { feedback.innerHTML = initialFeedbackHtml; feedback.className = initialFeedbackClass; }
			feedback.style.whiteSpace = "pre-line";
		}
		document.getElementById("auvorechnungsimport-tobook").textContent = fmt(booked);
		document.getElementById("auvorechnungsimport-skipwarn").textContent = Math.abs(skippedGross) > 0.005 ? warn.replace("__AMOUNT__", fmt(skippedGross)) : "";
		var refInput = document.querySelector("input[name=ref_supplier]");
		var createButton = document.getElementById("auvorechnungsimport-create-invoice");
		if (createButton) createButton.disabled = !count || missingVat || manualIncomplete || missingProductLines.length > 0 || netMismatch || grossMismatch || (requireInvoiceRef && (!refInput || !refInput.value.trim()));
	}
	document.querySelectorAll("select.auvorechnungsimport-mode").forEach(function (s) {
		s.addEventListener("change", function () {
			var code = s.parentElement.querySelector(".auvorechnungsimport-mode-code");
			if (code) {
				var codes = {book: '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportModeCodeBook')).', cost: '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportModeCodeCost')).', skip: '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportModeCodeSkip')).'};
				code.textContent = codes[s.value] || "?";
				s.title = s.options[s.selectedIndex].text;
			}
			update();
		});
	});
	document.querySelectorAll(".auvorechnungsimport-product-search").forEach(function (button) {
		var select = document.getElementById(button.dataset.select);
		var search = document.getElementById("search_" + button.dataset.select);
		var picker = button.closest(".auvorechnungsimport-product-picker");
		if (!select || !search || !picker) return;
		button.addEventListener("click", function () {
			picker.classList.add("is-open");
			search.focus();
			search.select();
		});
		select.addEventListener("change", function () {
			if (select.value) {
				button.title = search.value || '.json_encode($langs->transnoentitiesnoconv('Search')).';
				setTimeout(function () { picker.classList.remove("is-open"); }, 100);
			}
			update();
		});
		search.addEventListener("keyup", function () { update(); });
		search.addEventListener("blur", function () { setTimeout(function () { if (!picker.matches(":hover")) picker.classList.remove("is-open"); }, 250); });
	});
	document.querySelectorAll(".auvorechnungsimport-line-qty, .auvorechnungsimport-line-price, .auvorechnungsimport-line-vat").forEach(function (input) { input.addEventListener("input", update); });
	var refInput = document.querySelector("input[name=ref_supplier]");
	if (refInput) refInput.addEventListener("input", update);
	if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", update); else update();
})();
</script>';

		if ($canUpdateBuyprice) {
			print '<div class="marginbottomonly"><label><input type="checkbox" name="update_buyprice" value="1"> '.$langs->trans('AuvoRechnungsimportUpdateBuyprice').'</label></div>';
		}
		if ($canCreateOrder) {
			// Pre-ticked when stock is managed and the invoice has stockable products.
			$hasStockable = false;
			foreach ($lines as $line) {
				if (!empty($line['fk_product'])) {
					$p = new Product($db);
					if ($p->fetch($line['fk_product']) > 0 && (int) $p->type == Product::TYPE_PRODUCT) {
						$hasStockable = true;
						break;
					}
				}
			}
			// Stock booked on validating the supplier invoice itself: the order is
			// only needed if someone wants one anyway, so not pre-ticked then.
			$stockOnBill = isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL');
			$checked = isModEnabled('stock') && $hasStockable && !$stockOnBill;
			print '<div class="marginbottomonly"><label><input type="checkbox" name="create_order" value="1"'.($checked ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportCreateOrder').'</label>';
			if ($stockOnBill && $hasStockable) {
				print ' <span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportStockOnBillHint').'</span>';
			}
			print '</div>';
		}

	print '<div class="center marginbottomonly">';
	print '<button type="submit" name="save_review" value="1" class="button" formnovalidate="formnovalidate">'.$langs->trans('AuvoRechnungsimportSaveReviewBtn').'</button> ';
	print '<input type="submit" id="auvorechnungsimport-create-invoice" class="button" value="'.$langs->trans('AuvoRechnungsimportCreateInvoiceBtn').'">';
	print '</div>';
	print '<div class="opacitymedium">'.$langs->trans('AuvoRechnungsimportLinesHint').'</div>';
	print '<script>(function(){var wrap=document.getElementById("existing_supplier_select_wrap");if(!wrap)return;function toggle(){var selected=document.querySelector("input[name=supplier_mode]:checked");var isNew=selected&&selected.value==="new";wrap.style.display=isNew?"none":"";var select=wrap.querySelector("select[name=fk_soc]");if(select){select.required=!isNew;}}document.querySelectorAll("input[name=supplier_mode]").forEach(function(r){r.addEventListener("change",toggle);});toggle();})();</script>';
	print '</form>';
	if ($requireInvoiceRef) {
		print '<script>(function(){var form=document.getElementById("auvorechnungsimport-review-form"),ref=form&&form.querySelector("input[name=ref_supplier]"),button=document.getElementById("auvorechnungsimport-create-invoice");if(!form||!ref||!button)return;var message='.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportErrInvoiceRefRequired')).';ref.addEventListener("input",function(){ref.setCustomValidity("");});form.addEventListener("submit",function(event){if(event.submitter===button&&!ref.value.trim()){event.preventDefault();ref.setCustomValidity(message);ref.reportValidity();}});})();</script>';
	}
}
print '</div>';
print '</main></div>';
print '<script>(function(){var button=document.getElementById("auvorechnungsimport-menu-toggle");if(!button)return;var body=document.body,menu=document.querySelector(".side-nav"),pinned=false;body.classList.add("auvorechnungsimport-menu-hidden");function sync(){var peek=body.classList.contains("auvorechnungsimport-menu-peek");button.style.left=peek&&menu?Math.max(0,menu.getBoundingClientRect().right-24)+"px":"0px";button.classList.toggle("is-open",peek);button.classList.toggle("is-pinned",peek&&pinned);button.innerHTML=peek?"<span class=\"fas fa-thumbtack\" aria-hidden=\"true\"></span>":"<span class=\"fas fa-angle-right\" aria-hidden=\"true\"></span>";button.title=peek?(pinned?"Menü lösen und schließen":"Menü fixieren") : "Dolibarr-Menü anzeigen";button.setAttribute("aria-label",button.title);}function show(){if(!pinned)body.classList.add("auvorechnungsimport-menu-peek");sync();}function hide(){if(!pinned)body.classList.remove("auvorechnungsimport-menu-peek");sync();}button.addEventListener("mouseenter",show);button.addEventListener("focus",show);button.addEventListener("click",function(){if(body.classList.contains("auvorechnungsimport-menu-peek")){pinned=!pinned;if(!pinned)body.classList.remove("auvorechnungsimport-menu-peek");}else{pinned=true;body.classList.add("auvorechnungsimport-menu-peek");}sync();});if(menu){menu.addEventListener("mouseenter",show);menu.addEventListener("mouseleave",function(){if(!button.matches(":hover"))hide();});}button.addEventListener("mouseleave",function(){if(!menu||!menu.matches(":hover"))hide();});button.addEventListener("blur",hide);window.addEventListener("resize",sync);sync();})();</script>';

llxFooter();
