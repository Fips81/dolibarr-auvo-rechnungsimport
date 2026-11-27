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
$deleteBlockedStatuses = array_map('intval', array_filter(explode(',', getDolGlobalString('AUVORECHNUNGSIMPORT_DELETE_BLOCKED_STATUSES')), 'strlen'));
$canDeleteImport = !in_array((int) $object->status, $deleteBlockedStatuses, true);
if (in_array($action, array('delete', 'confirm_delete'), true) && !$canDeleteImport) {
	accessforbidden($langs->trans('AuvoRechnungsimportDeleteBlocked'));
}
if (GETPOSTINT('ajax_due_date') && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$invoiceDate = trim((string) GETPOST('invoice_date', 'alpha'));
	$conditionId = GETPOSTINT('cond_reglement_id');
	$dueDate = ($invoiceDate !== '' && $conditionId > 0)
		? auvorechnungsimport_calculate_due_date($db, dol_stringtotime($invoiceDate), $conditionId)
		: 0;
	header('Content-Type: application/json; charset=UTF-8');
	print json_encode(array('due_date' => $dueDate > 0 ? dol_print_date($dueDate, '%Y-%m-%d') : ''));
	exit;
}
$extracted = $object->getExtractedData();
$duplicateCandidates = array();
$canConfirmDuplicate = $user->hasRight('auvorechnungsimport', 'imports', 'write')
	&& in_array((int) $object->status, array(ImportedInvoice::STATUS_NEEDS_REVIEW, ImportedInvoice::STATUS_MATCHED, ImportedInvoice::STATUS_ERROR), true);
if ($canConfirmDuplicate) {
	$duplicateCandidates = auvorechnungsimport_find_duplicate_imports($db, $object, $extracted);
}
$canUpdateBuyprice = $user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer');
$canCreateOrder = isModEnabled('supplier_order') && $user->hasRight('fournisseur', 'commande', 'creer');
$updateBuypriceMode = getDolGlobalString('AUVORECHNUNGSIMPORT_UPDATE_BUYPRICE_MODE', 'auto');
if (!in_array($updateBuypriceMode, array('auto', 'allow', 'deny'), true)) $updateBuypriceMode = 'auto';
$createOrderMode = getDolGlobalString('AUVORECHNUNGSIMPORT_CREATE_ORDER_MODE', 'allow');
if (!in_array($createOrderMode, array('auto', 'allow', 'deny'), true)) $createOrderMode = 'allow';
$allowCreateOrder = $canCreateOrder && $createOrderMode !== 'deny';
$allowReleaseOrderButton = $allowCreateOrder && getDolGlobalInt('AUVORECHNUNGSIMPORT_ALLOW_RELEASE_ORDER_BUTTON', 1);
$canCreateSupplierAndBank = getDolGlobalInt('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER', 1) && $user->hasRight('societe', 'creer');
$canCreateProduct = isModEnabled('variants') && $user->hasRight('produit', 'creer');

if (in_array((int) $object->status, array(ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_CONFIRMED, ImportedInvoice::STATUS_REJECTED), true)
	&& in_array($action, array('createinvoice', 'createinvoice_releaseorder', 'reprocess', 'attachpdf'), true)) {
	accessforbidden('Spam and duplicate imports are not available for invoice processing');
}
if ($action === 'createinvoice_releaseorder' && !$allowReleaseOrderButton) {
	accessforbidden('Combined invoice and order approval is disabled');
}

/**
 * Create the supplier order for goods receipt and report the outcome.
 *
 * @param DoliDB $db
 * @param User $user
 * @param Translate $langs
 * @param FactureFournisseur $invoice
 * @return int Order id or <0
 */
function auvorechnungsimport_card_create_order($db, $user, $langs, $invoice)
{
	$warnings = array();
	$db->begin();
	$orderId = auvorechnungsimport_create_supplier_order($db, $user, $invoice, $warnings);
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

/**
 * Continue releasing an already linked supplier order without leaving this card.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $orderId
 * @return array{done:bool,messages:array<int,string>,errors:array<int,string>}
 */
function auvorechnungsimport_card_release_order($db, $user, $langs, $orderId)
{
	$order = new CommandeFournisseur($db);
	if ($order->fetch($orderId) <= 0) return array('done' => false, 'messages' => array(), 'errors' => array($langs->trans('AuvoRechnungsimportOrderNotFound')));

	$warnings = array();
	$done = auvorechnungsimport_release_supplier_order($order, $user, $warnings) === 1;
	return array('done' => $done, 'messages' => array_filter($warnings), 'errors' => array());
}

if ($action === 'savereview' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) accessforbidden('CSRF');
	$saveDetails = auvorechnungsimport_supplier_details_from_fields($extracted);
	foreach (array('name', 'vat', 'tax_id', 'address', 'zip', 'town', 'email', 'phone', 'country', 'iban', 'bic', 'account_name', 'bank_name') as $field) {
		$postName = 'supplier_value_'.$field;
		if (isset($_POST[$postName])) $saveDetails[$field] = trim((string) GETPOST($postName, $field === 'country' ? 'alpha' : 'restricthtml'));
		$actionName = 'supplier_action_'.$field;
		if (isset($_POST[$actionName])) {
			if (!isset($extracted['supplier_actions']) || !is_array($extracted['supplier_actions'])) $extracted['supplier_actions'] = array();
			$extracted['supplier_actions'][$field] = GETPOST($actionName, 'alpha') === 'keep' ? 'keep' : 'take';
		}
	}
	$saveDetails['vat'] = auvorechnungsimport_normalize_vat($saveDetails['vat']);
	$saveDetails['iban'] = strtoupper(preg_replace('/\s+/', '', $saveDetails['iban']));
	$saveDetails['country'] = strtoupper($saveDetails['country']);
	$extracted['supplier'] = $saveDetails;
	$extracted['supplier_name'] = $saveDetails['name'];
	$extracted['supplier_vat'] = $saveDetails['vat'];
	$extracted['supplier_tax_id'] = $saveDetails['tax_id'];
	$extracted['supplier_email'] = $saveDetails['email'];
	$extracted['supplier_phone'] = $saveDetails['phone'];
	$extracted['supplier_country'] = $saveDetails['country'];
	$extracted['supplier_iban'] = $saveDetails['iban'];
	$extracted['iban'] = $saveDetails['iban'];
	$extracted['bic'] = $saveDetails['bic'];
	$extracted['account_name'] = $saveDetails['account_name'];
	$extracted['supplier_bank_details'] = array_merge(is_array($extracted['supplier_bank_details'] ?? null) ? $extracted['supplier_bank_details'] : array(), array('iban' => $saveDetails['iban'], 'bic' => $saveDetails['bic'], 'account_holder' => $saveDetails['account_name'], 'bank_name' => $saveDetails['bank_name']));
	$extracted['supplier_mode'] = GETPOST('supplier_mode', 'alpha') === 'new' ? 'new' : 'existing';
	if ($extracted['supplier_mode'] === 'existing') $object->fk_soc = GETPOSTINT('fk_soc');
	$bankMode = GETPOST('bank_mode', 'alpha');
	$extracted['bank_mode'] = in_array($bankMode, array('existing', 'new', 'skip'), true) ? $bankMode : 'skip';
	$extracted['existing_bank_id'] = GETPOSTINT('existing_bank_id');
	$bankInput = is_array($extracted['bank_details'] ?? null) ? $extracted['bank_details'] : array();
	if (isset($_POST['bank_iban'])) $bankInput['iban'] = strtoupper(preg_replace('/\\s+/', '', trim((string) GETPOST('bank_iban', 'alphanohtml'))));
	if (isset($_POST['bank_account_holder'])) $bankInput['account_holder'] = trim((string) GETPOST('bank_account_holder', 'restricthtml'));
	if (isset($_POST['bank_bic'])) $bankInput['bic'] = strtoupper(trim((string) GETPOST('bank_bic', 'alphanohtml')));
	if (isset($_POST['bank_name'])) $bankInput['bank_name'] = trim((string) GETPOST('bank_name', 'restricthtml'));
	$bankInput += array('iban' => $extracted['iban'] ?? $extracted['supplier_iban'] ?? '', 'account_holder' => $extracted['account_name'] ?? '', 'bic' => $extracted['bic'] ?? '', 'bank_name' => $extracted['supplier_bank_details']['bank_name'] ?? '');
	if (GETPOST('bank_action_account_holder', 'alpha') === 'blank') $bankInput['account_holder'] = '';
	if (GETPOST('bank_action_bic', 'alpha') === 'blank') $bankInput['bic'] = '';
	$extracted['bank_details'] = $bankInput;
	$extracted['bank_field_actions'] = array(
		'account_holder' => GETPOST('bank_action_account_holder', 'alpha') === 'blank' ? 'blank' : 'take',
		'bic' => GETPOST('bank_action_bic', 'alpha') === 'blank' ? 'blank' : 'take',
	);
	$extracted['iban'] = $bankInput['iban'];
	$extracted['bic'] = $bankInput['bic'];
	$extracted['account_name'] = $bankInput['account_holder'];
	$extracted['invoice_ref'] = GETPOST('ref_supplier', 'alphanohtml');
	$extracted['invoice_date'] = GETPOST('invoice_date', 'alpha');
	$extracted['due_date'] = GETPOST('due_date', 'alpha');
	$extracted['payment_condition_id'] = GETPOSTINT('cond_reglement_id');
	$extracted['payment_mode_id'] = GETPOSTINT('mode_reglement_id');
	$extracted['create_bank'] = $extracted['bank_mode'] === 'new' ? 1 : 0;
	$extracted['update_buyprice'] = ($updateBuypriceMode !== 'deny' && GETPOSTINT('update_buyprice')) ? 1 : 0;
	$extracted['create_order'] = ($allowCreateOrder && GETPOSTINT('create_order')) ? 1 : 0;
	$saveLineCount = max(1, GETPOSTINT('line_count'), count($extracted['lines'] ?? array()));
	$smallBusiness19 = !empty($extracted['small_business_19_detected']);
	$saveLines = array();
	for ($i = 0; $i < $saveLineCount; $i++) {
		if (GETPOSTINT('line_deleted_'.$i)) continue;
		$line = is_array($extracted['lines'][$i] ?? null) ? $extracted['lines'][$i] : array();
		$mode = GETPOST('line_mode_'.$i, 'aZ09');
		if (!in_array($mode, AUVORECHNUNGSIMPORT_LINE_MODES, true)) $mode = 'book';
		$line['mode'] = $mode;
		$line['mode_user_selected'] = 1;
		$line['ref_supplier'] = GETPOST('line_ref_supplier_'.$i, 'alphanohtml');
		$line['label'] = GETPOST('line_label_'.$i, 'restricthtml');
		$qtyRaw = trim((string) GETPOST('line_qty_'.$i, 'alpha'));
		$priceRaw = trim((string) GETPOST('line_price_'.$i, 'alpha'));
		$vatRaw = trim((string) GETPOST('line_vat_'.$i, 'alpha'));
		$line['qty'] = $qtyRaw === '' ? null : (float) price2num($qtyRaw);
		$line['price'] = $priceRaw === '' ? null : (float) price2num($priceRaw, 'MU');
		$line['vat_rate'] = $smallBusiness19 ? 0 : ($vatRaw === '' ? null : (float) price2num($vatRaw));
		$priceBase = GETPOST('line_price_base_'.$i, 'alpha');
		$line['price_base'] = $smallBusiness19 ? 'TTC' : (in_array($priceBase, array('HT', 'TTC'), true) ? $priceBase : 'HT');
		$productField = 'lineprod_'.$i;
		$selectedProduct = (int) ($_POST[$productField] ?? 0);
		$mirrorProduct = (int) ($_POST['line_product_id_'.$i] ?? 0);
		$productTouched = GETPOSTINT('line_product_touched_'.$i) === 1;
		if ($selectedProduct > 0 || $mirrorProduct > 0 || $productTouched) {
			$line['fk_product'] = max(0, $selectedProduct > 0 ? $selectedProduct : $mirrorProduct);
			$line['product_user_selected'] = 1;
		}
		$line['total'] = ($line['qty'] ?? 0) * ($line['price'] ?? 0);
		if ($line['label'] !== '' || $line['ref_supplier'] !== '' || $line['qty'] !== null || $line['price'] !== null || $line['vat_rate'] !== null || $line['fk_product'] > 0 || $mode !== 'book') $saveLines[] = $line;
	}
	$extracted['lines'] = $saveLines;
	$object->extracted_data = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($object->update($user) > 0) {
		setEventMessages($langs->trans('AuvoRechnungsimportReviewSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	} else {
		setEventMessages($langs->trans('AuvoRechnungsimportReviewSaveFailed'), $object->errors, 'errors');
	}
}

if (in_array($action, array('createinvoice', 'createinvoice_releaseorder'), true) && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$invoiceLines = isset($extracted['lines']) && is_array($extracted['lines']) ? $extracted['lines'] : array();
	$smallBusiness19 = !empty($extracted['small_business_19_detected']);
	if (empty($invoiceLines)) {
		$invoiceLines = array(0 => array('label' => '', 'qty' => '', 'price' => null, 'price_base' => $smallBusiness19 ? 'TTC' : 'HT', 'vat_rate' => $smallBusiness19 ? 0 : null, 'total' => 0, 'fk_product' => 0, 'ref_supplier' => '', 'unit_code' => ''));
	}
	$postedLineCount = max(count($invoiceLines), GETPOSTINT('line_count'));
	for ($i = count($invoiceLines); $i < $postedLineCount; $i++) {
		$invoiceLines[$i] = array('source' => 'manual', 'label' => '', 'qty' => '', 'price' => null, 'price_base' => $invoiceLines[0]['price_base'] ?? 'HT', 'vat_rate' => null, 'total' => 0, 'fk_product' => 0, 'ref_supplier' => '', 'unit_code' => '');
	}
	$lineInputError = '';
	$lineInputErrorMessage = '';
	$totalMismatchDetected = false;
	$allowDraftTotalMismatch = getDolGlobalInt('AUVORECHNUNGSIMPORT_ALLOW_DRAFT_TOTAL_MISMATCH', 0) === 1;
	$refSupplier = trim((string) GETPOST('ref_supplier', 'alphanohtml'));
	$invoiceDate = trim((string) GETPOST('invoice_date', 'alpha'));
	$headerInputError = $refSupplier === '' ? 'AuvoRechnungsimportErrSupplierRefRequired' : '';
	if ($headerInputError === '' && ($invoiceDate === '' || dol_stringtotime($invoiceDate) <= 0)) $headerInputError = 'AuvoRechnungsimportErrInvoiceDateRequired';
	$bookableLineCount = 0;
	$reviewNet = 0.0;
	$reviewGross = 0.0;
	$skippedNet = 0.0;
	$skippedGross = 0.0;
	foreach ($invoiceLines as $i => $line) {
		if (GETPOSTINT('line_deleted_'.$i)) continue;
		$lineMode = GETPOST('line_mode_'.$i, 'aZ09');
		if (!in_array($lineMode, AUVORECHNUNGSIMPORT_LINE_MODES, true)) $lineMode = 'book';
		$quantityInput = trim((string) GETPOST('line_qty_'.$i, 'alpha'));
		$priceInput = trim((string) GETPOST('line_price_'.$i, 'alpha'));
		$vatInput = trim((string) GETPOST('line_vat_'.$i, 'alpha'));
		$lineRefInput = trim((string) GETPOST('line_ref_supplier_'.$i, 'alphanohtml'));
		$lineProductInput = GETPOSTINT('lineprod_'.$i);
		$lineVariantInput = GETPOSTINT('create_variant_'.$i);
		$labelInput = trim((string) GETPOST('line_label_'.$i, 'restricthtml'));
		$lineHasInput = $labelInput !== '' || $lineRefInput !== '' || $quantityInput !== '' || $priceInput !== '' || $vatInput !== '' || $lineProductInput > 0 || $lineVariantInput > 0;
		if (!$lineHasInput) continue;
		if ($lineMode !== 'skip' && (trim((string) GETPOST('line_label_'.$i, 'restricthtml')) === ''
			|| $quantityInput === ''
			|| (float) price2num($quantityInput) == 0
			|| $priceInput === ''
			|| $vatInput === '')) {
			$lineInputError = 'AuvoRechnungsimportErrManualLine';
			break;
		}
		$normalizedVat = price2num($vatInput);
		$normalizedPrice = price2num($priceInput, 'MU');
		if ($lineMode !== 'skip' && (!is_numeric($normalizedVat) || (float) $normalizedVat < 0 || (float) $normalizedVat > 100)) {
			$lineInputError = 'AuvoRechnungsimportErrVatRate';
			break;
		}
		if ($lineMode !== 'skip' && !is_numeric($normalizedPrice)) {
			$lineInputError = 'AuvoRechnungsimportErrManualLine';
			break;
		}
		if ($lineMode === 'book' && GETPOSTINT('lineprod_'.$i) <= 0 && !(GETPOSTINT('create_variant_'.$i) && $canCreateProduct)) {
			$lineInputError = 'AuvoRechnungsimportErrProductRequired';
			break;
		}
		$qty = (float) price2num($quantityInput);
		$unit = (float) price2num($priceInput, 'MU');
		$vat = (float) price2num($vatInput);
		$priceBase = GETPOST('line_price_base_'.$i, 'alpha');
		$priceBase = $smallBusiness19 ? 'TTC' : (in_array($priceBase, array('HT', 'TTC'), true) ? $priceBase : (($line['price_base'] ?? 'HT') === 'TTC' ? 'TTC' : 'HT'));
		if ($smallBusiness19) $vat = 0.0;
		$base = $qty * $unit;
		$lineGross = $priceBase === 'TTC' ? $base : $base * (1 + $vat / 100);
		$lineNet = $priceBase === 'TTC' && $vat > 0 ? $base / (1 + $vat / 100) : $base;
		if ($lineMode === 'skip') {
			$skippedNet += round($lineNet, 2);
			$skippedGross += round($lineGross, 2);
		} else {
			$bookableLineCount++;
			$reviewNet += round($lineNet, 2);
			$reviewGross += round($lineGross, 2);
		}
	}
	if ($lineInputError === '' && $bookableLineCount > 0) {
		$tolerance = max(0.02, 0.01 * $bookableLineCount);
		$netMismatch = isset($extracted['amount_ht']) && $extracted['amount_ht'] !== null && abs($reviewNet - ((float) $extracted['amount_ht'] - $skippedNet)) > $tolerance;
		$grossMismatch = isset($extracted['amount_ttc']) && $extracted['amount_ttc'] !== null && abs($reviewGross - ((float) $extracted['amount_ttc'] - $skippedGross)) > $tolerance;
		if ($netMismatch || $grossMismatch) {
			$totalMismatchDetected = true;
			$numberFormat = array('decimal' => $langs->transnoentitiesnoconv('SeparatorDecimal'), 'thousands' => $langs->transnoentitiesnoconv('SeparatorThousand'));
			$decimalSeparator = $numberFormat['decimal'] === 'SeparatorDecimal' ? ',' : $numberFormat['decimal'];
			$thousandsSeparator = in_array($numberFormat['thousands'], array('SeparatorThousand', 'None'), true) ? '.' : $numberFormat['thousands'];
			$formatReviewAmount = function ($amount) use ($decimalSeparator, $thousandsSeparator) { return number_format((float) $amount, 2, $decimalSeparator, $thousandsSeparator); };
			$details = array();
			if ($grossMismatch) $details[] = $langs->trans('AuvoRechnungsimportMismatchGross', $formatReviewAmount((float) $extracted['amount_ttc'] - $skippedGross), $formatReviewAmount($reviewGross));
			if ($netMismatch) $details[] = $langs->trans('AuvoRechnungsimportMismatchNet', $formatReviewAmount((float) $extracted['amount_ht'] - $skippedNet), $formatReviewAmount($reviewNet));
			if (empty($extracted['lines'])) $details[] = $langs->trans('AuvoRechnungsimportMismatchNoLines');
			$lineInputErrorMessage = implode(' ', $details).' '.$langs->trans('AuvoRechnungsimportMismatchAdvice');
			if ($action === 'createinvoice_releaseorder') {
				$lineInputError = 'AuvoRechnungsimportErrReviewTotalMismatch';
				$lineInputErrorMessage .= ' '.$langs->trans('AuvoRechnungsimportMismatchBlocked');
			} elseif (!$allowDraftTotalMismatch) {
				$lineInputError = 'AuvoRechnungsimportErrReviewTotalMismatch';
				$lineInputErrorMessage .= ' '.$langs->trans('AuvoRechnungsimportMismatchDraftDenied');
			}
		}
	}
	if ($action === 'createinvoice_releaseorder' && $lineInputError === '' && $bookableLineCount === 0) {
		$lineInputError = 'AuvoRechnungsimportErrOrderNoLines';
	}
	$supplierCreateDetails = auvorechnungsimport_supplier_details_from_fields($extracted);
	foreach (array('name', 'vat', 'tax_id', 'address', 'zip', 'town', 'email', 'phone', 'country', 'iban', 'bic', 'account_name', 'bank_name') as $supplierField) {
		$postName = 'supplier_value_'.$supplierField;
		if (!isset($_POST[$postName])) continue;
		$supplierValue = trim((string) GETPOST($postName, $supplierField === 'country' ? 'alpha' : 'restricthtml'));
		if ($supplierField === 'vat') $supplierValue = auvorechnungsimport_normalize_vat($supplierValue);
		if ($supplierField === 'iban') $supplierValue = strtoupper(preg_replace('/\s+/', '', $supplierValue));
		if ($supplierField === 'country') $supplierValue = strtoupper($supplierValue);
		$actionName = 'supplier_action_'.$supplierField;
		if (isset($_POST[$actionName]) && GETPOST($actionName, 'alpha') === 'keep') $supplierValue = '';
		$supplierCreateDetails[$supplierField] = $supplierValue;
	}
	$bankMode = GETPOST('bank_mode', 'alpha');
	if (!in_array($bankMode, array('existing', 'new', 'skip'), true)) $bankMode = 'skip';
	$bankDetails = is_array($extracted['bank_details'] ?? null) ? $extracted['bank_details'] : array();
	if (isset($_POST['bank_iban'])) $bankDetails['iban'] = strtoupper(preg_replace('/\\s+/', '', trim((string) GETPOST('bank_iban', 'alphanohtml'))));
	if (isset($_POST['bank_account_holder'])) $bankDetails['account_holder'] = trim((string) GETPOST('bank_account_holder', 'restricthtml'));
	if (isset($_POST['bank_bic'])) $bankDetails['bic'] = strtoupper(trim((string) GETPOST('bank_bic', 'alphanohtml')));
	if (isset($_POST['bank_name'])) $bankDetails['bank_name'] = trim((string) GETPOST('bank_name', 'restricthtml'));
	$bankDetails += array('iban' => $extracted['iban'] ?? $extracted['supplier_iban'] ?? '', 'account_holder' => $extracted['account_name'] ?? '', 'bic' => $extracted['bic'] ?? '', 'bank_name' => $extracted['supplier_bank_details']['bank_name'] ?? '');
	$bankActions = array(
		'account_holder' => GETPOST('bank_action_account_holder', 'alpha') === 'blank' ? 'blank' : 'take',
		'bic' => GETPOST('bank_action_bic', 'alpha') === 'blank' ? 'blank' : 'take',
	);
	if ($bankActions['account_holder'] === 'blank') $bankDetails['account_holder'] = '';
	if ($bankActions['bic'] === 'blank') $bankDetails['bic'] = '';
	$extracted['bank_mode'] = $bankMode;
	$extracted['bank_details'] = $bankDetails;
	$extracted['bank_field_actions'] = $bankActions;
	$extracted['existing_bank_id'] = GETPOSTINT('existing_bank_id');
	$extracted['iban'] = $bankDetails['iban'];
	$extracted['bic'] = $bankDetails['bic'];
	$extracted['account_name'] = $bankDetails['account_holder'];
	$supplierCreateError = '';
	$supplierMode = GETPOST('supplier_mode', 'alpha');
	if (!in_array($supplierMode, array('existing', 'new'), true)) $supplierMode = GETPOSTINT('create_supplier') ? 'new' : 'existing';
	$fkSoc = $supplierMode === 'new' ? 0 : GETPOSTINT('fk_soc');
	$supplierIdentity = array('supplier_name' => $supplierCreateDetails['name'], 'supplier_vat' => $supplierCreateDetails['vat']);
	$detectedOwnCompany = auvorechnungsimport_is_own_company_supplier($db, $supplierIdentity);
	$selectedOwnCompany = auvorechnungsimport_is_own_company_supplier($db, array(), $fkSoc);
	$selfSupplierBlocked = $selectedOwnCompany || ($detectedOwnCompany && ($supplierMode === 'new' || $fkSoc <= 0));
	if ($selfSupplierBlocked) {
		$supplierCreateError = 'AuvoRechnungsimportErrOwnCompanySupplier';
	} elseif ($supplierMode === 'new' && !$canCreateSupplierAndBank) {
		$supplierCreateError = 'AuvoRechnungsimportErrSupplierCreatePermission';
	} elseif ($supplierMode === 'new' && strtoupper(trim((string) ($supplierCreateDetails['country'] ?? ''))) === 'DE'
		&& trim((string) ($supplierCreateDetails['vat'] ?? '')) === '' && trim((string) ($supplierCreateDetails['tax_id'] ?? '')) === '') {
		$supplierCreateError = 'AuvoRechnungsimportErrGermanSupplierTaxIdRequired';
	} elseif ($supplierMode === 'new' && $lineInputError === '' && $headerInputError === '') {
		if ($supplierCreateDetails['name'] === '') {
			$supplierCreateError = 'AuvoRechnungsimportErrSupplierNameRequired';
		} else {
			// Save the reviewed seller details with the imported record and reuse
			// the same values for supplier and bank-account creation.
			$extracted['supplier'] = $supplierCreateDetails;
			$extracted['supplier_name'] = $supplierCreateDetails['name'];
			$extracted['supplier_vat'] = $supplierCreateDetails['vat'];
			$extracted['supplier_tax_id'] = $supplierCreateDetails['tax_id'];
			$extracted['supplier_email'] = $supplierCreateDetails['email'];
			$extracted['supplier_phone'] = $supplierCreateDetails['phone'];
			$extracted['supplier_country'] = $supplierCreateDetails['country'];
			$extracted['supplier_account_holder'] = $supplierCreateDetails['account_name'];
			$extracted['account_name'] = $supplierCreateDetails['account_name'];
			$extracted['supplier_iban'] = $supplierCreateDetails['iban'];
			$extracted['iban'] = $supplierCreateDetails['iban'];
			$extracted['bic'] = $supplierCreateDetails['bic'] ?? ($extracted['bic'] ?? '');
			if (!isset($extracted['supplier_bank_details']) || !is_array($extracted['supplier_bank_details'])) $extracted['supplier_bank_details'] = array();
			$extracted['supplier_bank_details']['bank_name'] = $supplierCreateDetails['bank_name'];
			$object->extracted_data = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$supplierErrors = array();
			$fkSoc = auvorechnungsimport_create_supplier($db, $user, $supplierCreateDetails, $supplierErrors);
		if ($fkSoc > 0) {
			$object->fk_soc = $fkSoc;
			$object->supplier_match_confidence = 'created';
			$object->update($user);
			setEventMessages($langs->trans('AuvoRechnungsimportSupplierCreated', $supplierCreateDetails['name']), null, 'mesgs');
		} else {
			$fkSoc = 0;
			setEventMessages($langs->trans('AuvoRechnungsimportErrSupplier'), $supplierErrors, 'errors');
		}
		}
	}
	if ($supplierMode === 'existing' && $fkSoc <= 0 && $lineInputError === '' && $headerInputError === '' && !$selfSupplierBlocked) {
		$supplierCreateError = 'AuvoRechnungsimportErrNoSupplier';
	}
	if ($supplierMode === 'existing' && $fkSoc > 0 && $supplierCreateError === '') {
		$selectedSupplier = new Societe($db);
		if ($selectedSupplier->fetch($fkSoc) > 0 && strtoupper((string) ($selectedSupplier->country_code ?? '')) === 'DE'
			&& trim((string) ($selectedSupplier->tva_intra ?? '')) === '' && trim((string) ($selectedSupplier->idprof1 ?? '')) === '') {
			$supplierCreateError = 'AuvoRechnungsimportErrGermanSupplierTaxIdRequired';
		}
	}

	$existingInvoice = auvorechnungsimport_find_existing_invoice($db, $fkSoc, $refSupplier);
	$isCreditNote = !empty($extracted['is_credit_note']);
	$sourceId = $isCreditNote ? GETPOSTINT('fac_avoir') : 0;
	$creditNoteError = '';
	if ($isCreditNote) {
		// A credit note corrects an invoice of the same supplier (Dolibarr: fk_facture_source).
		// Its lines are positive; Dolibarr stores them negated. A negative line (an
		// allowance inside a credit note) cannot be represented that way.
		if ($sourceId > 0 && !auvorechnungsimport_is_source_invoice($db, $fkSoc, $sourceId)) {
			$creditNoteError = 'AuvoRechnungsimportErrCreditSource';
		} elseif ($sourceId <= 0 && !getDolGlobalString('INVOICE_CREDIT_NOTE_STANDALONE')) {
			$creditNoteError = 'AuvoRechnungsimportErrCreditNeedsSource';
		} else {
			foreach (isset($extracted['lines']) && is_array($extracted['lines']) ? $extracted['lines'] : array() as $i => $l) {
				if (GETPOST('line_mode_'.$i, 'aZ09') !== 'skip' && ((float) price2num(GETPOST('line_qty_'.$i, 'alpha')) * (float) price2num(GETPOST('line_price_'.$i, 'alpha'), 'MU')) < 0) {
					$creditNoteError = 'AuvoRechnungsimportErrCreditNegativeLine';
					break;
				}
			}
		}
	}
	if ($creditNoteError !== '') {
		setEventMessages($langs->trans($creditNoteError), null, 'errors');
	} elseif ($headerInputError !== '') {
		setEventMessages($langs->trans($headerInputError), null, 'errors');
	} elseif ($lineInputError !== '') {
		setEventMessages($lineInputErrorMessage !== '' ? $lineInputErrorMessage : $langs->trans($lineInputError), null, 'errors');
	} elseif ($supplierCreateError !== '') {
		setEventMessages($langs->trans($supplierCreateError), null, 'errors');
	} elseif (empty($fkSoc)) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrNoSupplier'), null, 'errors');
	} elseif ($existingInvoice) {
		// Dolibarr allows each supplier invoice number only once per supplier.
		setEventMessages($langs->trans('AuvoRechnungsimportErrDuplicateRef', dol_escape_htmltag($refSupplier), $existingInvoice->getNomUrl(1)), null, 'errors');
	} else {
		$db->begin();
		$invoice = new FactureFournisseur($db);
		$invoice->socid = $fkSoc;
		$invoice->ref_supplier = $refSupplier;
		$invoice->date = $invoiceDate ? dol_stringtotime($invoiceDate) : dol_now();
		$invoice->type = $isCreditNote ? FactureFournisseur::TYPE_CREDIT_NOTE : FactureFournisseur::TYPE_STANDARD;
		$invoice->fk_facture_source = $sourceId > 0 ? $sourceId : '';
		$invoice->fk_project = 0;
		$invoice->note_private = $langs->trans('AuvoRechnungsimportCreatedFrom', $object->ref);
		$headerNote = auvorechnungsimport_header_note($extracted, auvorechnungsimport_header_labels($langs));
		if ($headerNote !== '') {
			$invoice->note_private .= "\n".$headerNote;
		}
		$invoice->cond_reglement_id = GETPOSTINT('cond_reglement_id');
		$invoice->mode_reglement_id = GETPOSTINT('mode_reglement_id');
		$dueDate = GETPOST('due_date', 'alpha');
		if ($dueDate) {
			$invoice->date_echeance = dol_stringtotime($dueDate);
		} elseif ($invoice->cond_reglement_id > 0) {
			$invoice->date_echeance = auvorechnungsimport_calculate_due_date($db, $invoice->date, $invoice->cond_reglement_id);
		}

		$result = $invoice->create($user);
		if ($result > 0) {
			// addline() computes taxes with the supplier as seller.
			$invoice->fetch_thirdparty();
		}

		// Invoice lines: everything the user ticked in the review table, with the
		// values as corrected there. The chosen product is learned for next time.
		$extractedLines = $invoiceLines;
		$updateBuyprice = GETPOSTINT('update_buyprice') && $canUpdateBuyprice && $updateBuypriceMode !== 'deny' && !$isCreditNote;
		$productTypes = array();
		$createdVariants = array();
		foreach ($extractedLines as $i => $line) {
			if (GETPOSTINT('line_deleted_'.$i)) continue;
			if ($result <= 0) {
				break;
			}
			$mode = GETPOST('line_mode_'.$i, 'aZ09');
			if (!in_array($mode, AUVORECHNUNGSIMPORT_LINE_MODES, true)) {
				$mode = 'book';
			}
			$fkProduct = ($mode === 'book') ? GETPOSTINT('lineprod_'.$i) : 0;
			$line['ref_supplier'] = GETPOST('line_ref_supplier_'.$i, 'alphanohtml');
			$line['label'] = GETPOST('line_label_'.$i, 'restricthtml');
			$lineHasInput = trim((string) $line['label']) !== '' || trim((string) $line['ref_supplier']) !== ''
				|| trim((string) GETPOST('line_qty_'.$i, 'alpha')) !== '' || trim((string) GETPOST('line_price_'.$i, 'alpha')) !== ''
				|| trim((string) GETPOST('line_vat_'.$i, 'alpha')) !== '' || $fkProduct > 0 || GETPOSTINT('create_variant_'.$i);
			if (!$lineHasInput) continue;
			$submittedPriceBase = GETPOST('line_price_base_'.$i, 'alpha');
			if (in_array($submittedPriceBase, array('HT', 'TTC'), true)) {
				$line['price_base'] = $submittedPriceBase;
			}
			// Remember cost/skip (or that it's booked normally again) for next time.
			if (auvorechnungsimport_learn_line_mode($db, $user, $fkSoc, $line, $mode) < 0) {
				$invoice->errors[] = $db->lasterror();
				$result = -1;
				break;
			}
			if ($mode === 'skip') {
				continue;
			}
			$line['qty'] = (float) price2num(GETPOST('line_qty_'.$i, 'alpha'));
			$line['price'] = (float) price2num(GETPOST('line_price_'.$i, 'alpha'), 'MU');
			$line['vat_rate'] = $smallBusiness19 ? 0.0 : (float) price2num(GETPOST('line_vat_'.$i, 'alpha'));
			if ($smallBusiness19) $line['price_base'] = 'TTC';

			// Variant to be created from this line (only if no product was chosen).
			// Planned again here from the label as submitted - the user may have
			// corrected it, e.g. an OCR misread color.
			if ($mode === 'book' && $fkProduct <= 0 && GETPOSTINT('create_variant_'.$i) && $canCreateProduct) {
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
			// Unit of measure (BT-130) - only where Dolibarr uses units
			$fkUnit = getDolGlobalInt('PRODUCT_USE_UNITS') ? auvorechnungsimport_unit_id($db, (string) ($line['unit_code'] ?? '')) : null;
			$resline = $invoice->addline($line['label'], $pu, $line['vat_rate'], 0, 0, $qty, $fkProduct, 0, '', '', 0, 0, (($line['price_base'] ?? 'HT') === 'TTC' ? 'TTC' : 'HT'), $type, -1, 1, array(), $fkUnit, 0, 0, (string) ($line['ref_supplier'] ?? ''));
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

		if ($result > 0 && $bankMode === 'new' && $canCreateSupplierAndBank && !empty($bankDetails['iban'])) {
			if (auvorechnungsimport_create_supplier_bank($db, $user, $fkSoc, $bankDetails['iban'], $bankDetails['bic'], $bankDetails['account_holder'], $bankDetails['bank_name']) < 0) {
				setEventMessages($langs->trans('AuvoRechnungsimportErrBank'), null, 'warnings');
			}
		}

		if ($result > 0) {
			if ($totalMismatchDetected && $action === 'createinvoice') {
				setEventMessages($lineInputErrorMessage.' '.$langs->trans('AuvoRechnungsimportMismatchDraftAllowed'), null, 'warnings');
			}
			// The lines are only a proposal - warn (but don't block) if what we booked
			// doesn't add up to the invoice total the PDF states. Dolibarr rounds VAT
			// per line while invoices usually round on the total, so allow a cent of
			// drift per few lines.
			$tolerance = max(0.02, 0.0025 * count($extractedLines));
			if (!$totalMismatchDetected && !empty($extracted['amount_ttc']) && $invoice->fetch($invoice->id) > 0
				&& abs(abs((float) $invoice->total_ttc) - abs((float) $extracted['amount_ttc'])) > $tolerance && (float) $invoice->total_ttc != 0) {
				setEventMessages($langs->trans('AuvoRechnungsimportWarnTotalMismatch', price(abs($invoice->total_ttc)), price(abs($extracted['amount_ttc']))), null, 'warnings');
			}

			if (!empty($createdVariants)) {
				setEventMessages($langs->trans('AuvoRechnungsimportVariantsCreated', implode(', ', $createdVariants)), null, 'mesgs');
			}
			if (auvorechnungsimport_attach_original($object, $invoice) < 0) {
				setEventMessages($langs->trans('AuvoRechnungsimportErrAttach'), null, 'warnings');
			}
			$object->fk_facture_fourn = $invoice->id;
			$object->status = ImportedInvoice::STATUS_IMPORTED;
			$resultupdate = $object->update($user);
			if ($resultupdate > 0) {
				$db->commit();
				// After the commit: a failing order must not take the invoice with it.
				if (($action === 'createinvoice_releaseorder' || (GETPOSTINT('create_order') && $createOrderMode !== 'deny')) && $allowCreateOrder && !$isCreditNote && $invoice->fetch($invoice->id) > 0) {
					auvorechnungsimport_card_create_order($db, $user, $langs, $invoice);
				}
				$nextId = auvorechnungsimport_next_to_review($db, $object->id);
				if ($nextId) {
					setEventMessages($langs->trans('AuvoRechnungsimportNextHint', dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.$nextId), null, 'mesgs');
				}
				if ($action === 'createinvoice_releaseorder') {
					header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
				} else {
					header('Location: '.dol_buildpath('/fourn/facture/card.php', 1).'?facid='.$invoice->id);
				}
				exit;
			}
		}
		$db->rollback();
		setEventMessages($langs->trans('AuvoRechnungsimportErrCreate'), array_merge($invoice->errors, $object->errors), 'errors');
	}
}

if (in_array($action, array('createorder', 'releaseorder', 'attachpdf'), true) && $object->status == ImportedInvoice::STATUS_IMPORTED && $object->fk_facture_fourn > 0
	&& $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$invoice = new FactureFournisseur($db);
	if ($invoice->fetch($object->fk_facture_fourn) > 0) {
		if ($action == 'createorder' && $allowCreateOrder && !auvorechnungsimport_linked_order_id($db, $invoice)) {
			auvorechnungsimport_card_create_order($db, $user, $langs, $invoice);
		} elseif ($action === 'releaseorder' && $allowCreateOrder) {
			$orderId = auvorechnungsimport_linked_order_id($db, $invoice);
			if ($orderId > 0) {
				$releaseResult = auvorechnungsimport_card_release_order($db, $user, $langs, $orderId);
				setEventMessages($langs->trans($releaseResult['done'] ? 'AuvoRechnungsimportOrderReleased' : 'AuvoRechnungsimportOrderReleasePending'), $releaseResult['errors'] ? $releaseResult['errors'] : $releaseResult['messages'], $releaseResult['errors'] ? 'errors' : ($releaseResult['done'] ? 'mesgs' : 'warnings'));
			}
		} elseif ($action == 'attachpdf') {
			$res = auvorechnungsimport_attach_original($object, $invoice);
			setEventMessages($langs->trans($res < 0 ? 'AuvoRechnungsimportErrAttach' : 'AuvoRechnungsimportAttached'), null, $res < 0 ? 'errors' : 'mesgs');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'reprocess' && $user->hasRight('auvorechnungsimport', 'imports', 'write') && !in_array((int) $object->status, array(ImportedInvoice::STATUS_IMPORTED, ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_SPAM_CONFIRMED, ImportedInvoice::STATUS_REJECTED), true)) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$srcfile = DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath;
	if (is_readable($srcfile)) {
		// In the background like an upload: OCR of a long scan can exceed the
		// web request's time limit.
		$object->status = ImportedInvoice::STATUS_QUEUED;
		$object->update($user);
		if (auvorechnungsimport_queue_start()) {
			setEventMessages($langs->trans('AuvoRechnungsimportReprocessed'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('AuvoRechnungsimportQueueStartFailed'), null, 'errors');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	} else {
		setEventMessages($langs->trans('AuvoRechnungsimportErrFileMissing'), null, 'errors');
	}
}

if ($action === 'confirmspam' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) accessforbidden('CSRF');
	if ((int) $object->status !== ImportedInvoice::STATUS_SPAM_REVIEW) accessforbidden('Invalid import status');
	$confirmedAt = dol_now();
	$extracted['spam_confirmation'] = array('date' => $confirmedAt, 'user_id' => (int) $user->id);
	$object->extracted_data = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$object->status = ImportedInvoice::STATUS_SPAM_CONFIRMED;
	if ($object->update($user) > 0) {
		setEventMessages($langs->trans('AuvoRechnungsimportSpamConfirmed'), null, 'mesgs');
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1).'?search_status=6,7');
		exit;
	}
	setEventMessages($langs->trans('AuvoRechnungsimportSpamConfirmFailed'), $object->errors, 'errors');
}

if ($action === 'releasespam' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) accessforbidden('CSRF');
	if ((int) $object->status !== ImportedInvoice::STATUS_SPAM_REVIEW) accessforbidden('Invalid import status');
	$object->status = ImportedInvoice::STATUS_QUEUED;
	if ($object->update($user) > 0) {
		if (!auvorechnungsimport_queue_start()) {
			setEventMessages($langs->trans('AuvoRechnungsimportQueueStartFailed'), null, 'warnings');
		} else {
			setEventMessages($langs->trans('AuvoRechnungsimportSpamReleased'), null, 'mesgs');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	setEventMessages($langs->trans('AuvoRechnungsimportSpamReleaseFailed'), $object->errors, 'errors');
}

if ($action === 'reject' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) accessforbidden('CSRF');
	$rejectableStatuses = array(ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_NEEDS_REVIEW, ImportedInvoice::STATUS_MATCHED, ImportedInvoice::STATUS_ERROR);
	if (!in_array((int) $object->status, $rejectableStatuses, true)) accessforbidden('Invalid import status');
	$extracted['rejection'] = array('source_status' => (int) $object->status, 'date' => dol_now(), 'user_id' => (int) $user->id);
	$object->extracted_data = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$object->status = ImportedInvoice::STATUS_REJECTED;
	if ($object->update($user) > 0) {
		setEventMessages($langs->trans('AuvoRechnungsimportRejected'), null, 'mesgs');
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1).'?search_status=8');
		exit;
	}
	setEventMessages($langs->trans('AuvoRechnungsimportRejectFailed'), $object->errors, 'errors');
}

if ($action === 'confirmduplicate' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) accessforbidden('CSRF');
	if (!in_array((int) $object->status, array(ImportedInvoice::STATUS_NEEDS_REVIEW, ImportedInvoice::STATUS_MATCHED, ImportedInvoice::STATUS_ERROR), true)) accessforbidden('Invalid import status');
	$duplicateId = GETPOSTINT('duplicate_id');
	$duplicate = null;
	foreach (auvorechnungsimport_find_duplicate_imports($db, $object, $extracted) as $candidate) {
		if ((int) $candidate['id'] === $duplicateId) {
			$duplicate = $candidate;
			break;
		}
	}
	if (!$duplicate) {
		setEventMessages($langs->trans('AuvoRechnungsimportDuplicateConfirmFailed'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	$confirmedAt = dol_now();
	$extracted['duplicate_confirmation'] = array(
		'reason' => 'invoice_number_and_amount',
		'duplicate_of_import_id' => (int) $duplicate['id'],
		'duplicate_of_import_ref' => (string) $duplicate['ref'],
		'date' => $confirmedAt,
		'user_id' => (int) $user->id,
	);
	$object->extracted_data = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$object->status = ImportedInvoice::STATUS_DUPLICATE;
	if ($object->update($user) > 0) {
		setEventMessages($langs->trans('AuvoRechnungsimportDuplicateConfirmed'), null, 'mesgs');
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1).'?search_status=6,7');
		exit;
	}
	setEventMessages($langs->trans('AuvoRechnungsimportDuplicateConfirmFailed'), $object->errors, 'errors');
}

if ($action == 'confirm_delete' && GETPOST('confirm') == 'yes' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$filetoremove = DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath;
	$result = $object->delete($user);
	if ($result > 0) {
		if (is_file($filetoremove)) dol_delete_file($filetoremove, 1);
		dol_delete_dir(dirname($filetoremove), 1);
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1));
		exit;
	}
	setEventMessages($langs->trans('AuvoRechnungsimportErrDelete'), $object->errors, 'errors');
}

llxHeader('', $object->ref);

	if ($action == 'delete' && $canDeleteImport) {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('AuvoRechnungsimportDeleteTitle'), $langs->trans('AuvoRechnungsimportDeleteConfirm'), 'confirm_delete', '', '', 1);
}

// Back to the list this record belongs to (to review / imported / ...)
$backStatus = array(
	ImportedInvoice::STATUS_NEEDS_REVIEW => '1,3', ImportedInvoice::STATUS_MATCHED => '1,3',
	ImportedInvoice::STATUS_QUEUED => '4,0', ImportedInvoice::STATUS_PROCESSING => '4,0',
	ImportedInvoice::STATUS_SPAM_REVIEW => '5',
	ImportedInvoice::STATUS_DUPLICATE => '6,7', ImportedInvoice::STATUS_SPAM_CONFIRMED => '6,7', ImportedInvoice::STATUS_REJECTED => '8',
	ImportedInvoice::STATUS_ERROR => '9', ImportedInvoice::STATUS_IMPORTED => '2',
);
$backUrl = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1).(isset($backStatus[(int) $object->status]) ? '?search_status='.$backStatus[(int) $object->status] : '');
$backLink = '<a href="'.dol_escape_htmltag($backUrl).'">'.img_picto('', 'back', 'class="paddingrightonly"').$langs->trans('BackToList').'</a>';


$morehtmlright = '';
if ($user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if ((int) $object->status === ImportedInvoice::STATUS_SPAM_REVIEW) {
		$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportConfirmSpam'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirmspam&token='.newToken(), '', 1, array('confirm' => array('content' => $langs->trans('AuvoRechnungsimportConfirmSpamPrompt'))));
		$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportSpamRelease'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=releasespam&token='.newToken(), '', 1, array('confirm' => array('content' => $langs->trans('AuvoRechnungsimportSpamReleaseConfirm'))));
	} elseif (!in_array((int) $object->status, array(ImportedInvoice::STATUS_IMPORTED, ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_CONFIRMED, ImportedInvoice::STATUS_REJECTED), true)) {
		$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportReprocessBtn'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=reprocess&token='.newToken(), '', 1);
	}
	foreach ($duplicateCandidates as $duplicateCandidate) {
		$duplicateRef = dol_escape_htmltag($duplicateCandidate['ref']);
		$duplicateLabel = $langs->trans('AuvoRechnungsimportConfirmDuplicate').' ('.$duplicateRef.')';
		$duplicatePrompt = $langs->trans('AuvoRechnungsimportConfirmDuplicatePrompt', $duplicateRef);
		$duplicateUrl = $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirmduplicate&duplicate_id='.(int) $duplicateCandidate['id'].'&token='.newToken();
		$morehtmlright .= dolGetButtonAction('', $duplicateLabel, 'default', $duplicateUrl, '', 1, array('confirm' => array('content' => $duplicatePrompt)));
	}
	if (in_array((int) $object->status, array(ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_NEEDS_REVIEW, ImportedInvoice::STATUS_MATCHED, ImportedInvoice::STATUS_ERROR), true)) {
		$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportReject'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=reject&token='.newToken(), '', 1, array('confirm' => array('content' => $langs->trans('AuvoRechnungsimportRejectPrompt'))));
	}
	if ($canDeleteImport) $morehtmlright .= dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete', '', 1);
}
$isSpamReview = (int) $object->status === ImportedInvoice::STATUS_SPAM_REVIEW;
$nextId = (int) $object->status === ImportedInvoice::STATUS_REJECTED ? 0 : auvorechnungsimport_next_to_review($db, $object->id, $isSpamReview);
if ($nextId) {
	$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportNextBtn'), 'default', $_SERVER['PHP_SELF'].'?id='.$nextId, '', 1);
}

// No tabs: a single "Card" tab only linked to the page itself.
print '<div class="auvorechnungsimport-card-toolbar">';
print '<div>'.$backLink.'</div>';
print '<div class="auvorechnungsimport-card-toolbar-actions">'.$morehtmlright.'</div>';
print '</div>';

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>'.dol_escape_htmltag($object->ref).'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportOriginalFilename').'</td><td>'.dol_escape_htmltag($object->original_filename).'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportExtractionMethod').'</td><td>'.dol_escape_htmltag($object->extraction_method).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(1).'</td></tr>';
print '</table>';
print '</div>';

print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('AuvoRechnungsimportDetectedVat').'</td><td>'.dol_escape_htmltag($extracted['supplier_vat'] ?? '').'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportDetectedName').'</td><td>'.dol_escape_htmltag($extracted['supplier_name'] ?? '').'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportMatchConfidence').'</td><td>'.dol_escape_htmltag($object->supplier_match_confidence).'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportDetectedAmount').'</td><td>'.(isset($extracted['amount_ttc']) && $extracted['amount_ttc'] !== null ? price($extracted['amount_ttc']).' '.dol_escape_htmltag($extracted['currency'] ?? '') : '').'</td></tr>';
print '</table>';
print '</div>';
print '<div class="clearboth"></div>';
print '</div>';
print dol_get_fiche_end();

$reviewCssPath = __DIR__.'/css/auvorechnungsimport_review.css';
$reviewCssVersion = is_file($reviewCssPath) ? filemtime($reviewCssPath) : 1;
print '<link rel="stylesheet" href="'.dol_buildpath('/auvorechnungsimport/css/auvorechnungsimport_review.css', 1).'?v='.(int) $reviewCssVersion.'">';
print '<button type="button" id="auvorechnungsimport-menu-toggle" aria-label="Dolibarr-Menü anzeigen" title="Dolibarr-Menü anzeigen"><span class="fas fa-angle-right valignmiddle btnTitle-icon" aria-hidden="true"></span></button>';
print '<div id="auvorechnungsimport-review-shell"><div class="auvorechnungsimport-review-width-control"><button type="button" id="auvorechnungsimport-review-width-toggle" class="flat" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportToggleWideReview')).'" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportToggleWideReview')).'"><span class="fas fa-expand" aria-hidden="true"></span></button></div>';
print '<div id="auvorechnungsimport-review-layout"><aside id="auvorechnungsimport-review-preview">';
$srcfile = DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath;
if (is_readable($srcfile)) {
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
		print '<iframe class="auvorechnungsimport-review-pdf-frame" src="'.$fileUrl.'" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportOriginalFilename')).'"></iframe>';
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

$dueAjaxUrl = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.(int) $object->id.'&ajax_due_date=1';
print '</aside><div id="auvorechnungsimport-review-splitter" role="separator" aria-orientation="vertical" aria-label="Breite der Vorschau ändern" aria-valuemin="25" aria-valuemax="65" aria-valuenow="40" tabindex="0"></div><main id="auvorechnungsimport-review-form" data-due-date-url="'.dol_escape_htmltag($dueAjaxUrl).'"><div class="div-table-responsive-no-min">';
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
				$releasableStatuses = array(CommandeFournisseur::STATUS_DRAFT, CommandeFournisseur::STATUS_VALIDATED);
				if ($allowCreateOrder && in_array((int) $order->status, $releasableStatuses, true)) {
					print ' <a class="button smallpaddingimp auvorechnungsimport-release-order" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=releaseorder&token='.newToken().'">'.$langs->trans('AuvoRechnungsimportReleaseOrderBtn').'</a>';
				}
			} elseif ($allowCreateOrder) {
				print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=createorder&token='.newToken().'">'.$langs->trans('AuvoRechnungsimportCreateOrderBtn').'</a>';
			}
			print '</td></tr>';
		}
		print '<tr><td>'.$langs->trans('AuvoRechnungsimportOriginalAttached').'</td><td>';
		if (is_file(auvorechnungsimport_invoice_docdir($invoice).'/'.dol_sanitizeFileName($object->original_filename))) {
			print img_picto('', 'tick').' '.$langs->trans('Yes');
		} else {
			print '<a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=attachpdf&token='.newToken().'">'.$langs->trans('AuvoRechnungsimportAttachBtn').'</a>';
		}
		print '</td></tr>';
		print '</table>';
	}
} elseif (in_array((int) $object->status, array(ImportedInvoice::STATUS_QUEUED, ImportedInvoice::STATUS_PROCESSING), true)) {
	print '<div class="info"><span class="fas fa-spinner fa-spin paddingright"></span>'.$langs->trans('AuvoRechnungsimportBeingAnalysed').'</div>';
	print '<script>setTimeout(function () { location.reload(); }, 5000);</script>';
	} elseif ((int) $object->status === ImportedInvoice::STATUS_SPAM_REVIEW) {
	$spamDetection = is_array($extracted['spam_detection'] ?? null) ? $extracted['spam_detection'] : array();
	print '<div class="warning">'.$langs->trans('AuvoRechnungsimportSpamHeld', dol_escape_htmltag((string) ($spamDetection['header'] ?? '')),
		dol_escape_htmltag((string) ($spamDetection['value'] ?? ''))).'</div>';
	} elseif ((int) $object->status === ImportedInvoice::STATUS_SPAM_CONFIRMED) {
	print '<div class="info">'.$langs->trans('AuvoRechnungsimportSpamConfirmed').'</div>';
	} elseif ((int) $object->status === ImportedInvoice::STATUS_DUPLICATE) {
	$duplicateConfirmation = is_array($extracted['duplicate_confirmation'] ?? null) ? $extracted['duplicate_confirmation'] : array();
	print '<div class="warning">'.$langs->trans('AuvoRechnungsimportDuplicateConfirmed').'</div>';
	if (!empty($duplicateConfirmation['duplicate_of_import_id'])) {
		$duplicateTarget = new ImportedInvoice($db);
		if ($duplicateTarget->fetch((int) $duplicateConfirmation['duplicate_of_import_id']) > 0) print '<p>'.$langs->trans('AuvoRechnungsimportDuplicateOf').' '.$duplicateTarget->getNomUrl(1).'</p>';
	}
	} elseif ((int) $object->status === ImportedInvoice::STATUS_REJECTED) {
		$rejection = is_array($extracted['rejection'] ?? null) ? $extracted['rejection'] : array();
		print '<div class="info">'.$langs->trans('AuvoRechnungsimportRejected');
		if (!empty($rejection['date'])) print ' <span class="opacitymedium small">'.dol_print_date($rejection['date'], 'dayhour').'</span>';
		print '</div>';
} elseif ($user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	print load_fiche_titre($langs->trans('AuvoRechnungsimportReviewTitle'), '', '');
	if (!empty($extracted['is_credit_note'])) {
		print '<div class="info">'.$langs->trans('AuvoRechnungsimportCreditNoteInfo').'</div>';
	}
	// Plausibility hints: arithmetic and details of the e-invoice, and a record of the same invoice
	$hints = array();
	$srcForCheck = DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath;
	$einvoiceForCheck = is_readable($srcForCheck) ? auvorechnungsimport_load_einvoice($srcForCheck) : null;
	if ($einvoiceForCheck !== null) {
		foreach (auvorechnungsimport_check_einvoice($einvoiceForCheck) as $h) {
			$hints[] = call_user_func_array(array($langs, 'transnoentities'), array_merge(array('AuvoRechnungsimportCheck'.$h['key']), $h['args']));
		}
	}
	if (!empty($hints) || !empty($duplicateCandidates)) {
		print '<div class="warning"><b>'.$langs->trans('AuvoRechnungsimportCheckTitle').'</b><ul class="nomargintop nomarginbottom">';
		foreach ($hints as $h) {
			print '<li>'.dol_escape_htmltag($h).'</li>';
		}
		foreach ($duplicateCandidates as $duplicateCandidate) {
			$duplicateLink = '<a href="'.$_SERVER['PHP_SELF'].'?id='.(int) $duplicateCandidate['id'].'">'.dol_escape_htmltag($duplicateCandidate['ref']).' ('.dol_escape_htmltag($duplicateCandidate['filename']).')</a>';
			print '<li>'.$langs->trans('AuvoRechnungsimportCheckDuplicate', $duplicateLink).'</li>';
		}
		print '</ul></div>';
	}
	$previewSupplier = auvorechnungsimport_supplier_details_from_fields($extracted);
	if (isset($_POST['supplier_value_name'])) $previewSupplier['name'] = trim((string) GETPOST('supplier_value_name', 'restricthtml'));
	if (isset($_POST['supplier_value_vat'])) $previewSupplier['vat'] = auvorechnungsimport_normalize_vat(GETPOST('supplier_value_vat', 'restricthtml'));
	$previewSupplierMode = isset($_POST['supplier_mode']) ? GETPOST('supplier_mode', 'alpha') : (string) ($extracted['supplier_mode'] ?? '');
	if (!in_array($previewSupplierMode, array('existing', 'new'), true)) $previewSupplierMode = ($object->fk_soc > 0 || $previewSupplier['name'] === '') ? 'existing' : 'new';
	if (!$canCreateSupplierAndBank) $previewSupplierMode = 'existing';
	$previewSupplierId = isset($_POST['fk_soc']) ? GETPOSTINT('fk_soc') : (int) $object->fk_soc;
	$detectedOwnCompany = auvorechnungsimport_is_own_company_supplier($db, array('supplier_name' => $previewSupplier['name'], 'supplier_vat' => $previewSupplier['vat']));
	$selectedOwnCompany = auvorechnungsimport_is_own_company_supplier($db, array(), $previewSupplierId);
	$selfSupplierBlocked = $selectedOwnCompany || ($detectedOwnCompany && ($previewSupplierMode === 'new' || $previewSupplierId <= 0));
	if ($selfSupplierBlocked) {
		print '<div class="error">'.img_error().' '.$langs->trans('AuvoRechnungsimportOwnCompanySupplierWarning').'</div>';
	} elseif ($detectedOwnCompany) {
		print '<div class="warning">'.img_warning().' '.$langs->trans('AuvoRechnungsimportOwnCompanySupplierOverrideWarning').'</div>';
	}
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="line_count" id="auvorechnungsimport-line-count" value="'.max(1, count($extracted['lines'] ?? array())).'">';
	print '<table class="border centpercent">';
	$newSupplier = auvorechnungsimport_supplier_details_from_fields($extracted);
	if ($newSupplier['country'] === '') $newSupplier['country'] = 'DE';
	$postedSupplierMode = isset($_POST['supplier_mode']) ? GETPOST('supplier_mode', 'alpha') : (string) ($extracted['supplier_mode'] ?? '');
	$supplierModeForForm = in_array($postedSupplierMode, array('existing', 'new'), true) ? $postedSupplierMode : ($object->fk_soc > 0 ? 'existing' : ($newSupplier['name'] !== '' ? 'new' : 'existing'));
	if (!$canCreateSupplierAndBank) $supplierModeForForm = 'existing';
	$companyForForm = isset($_POST['fk_soc']) ? GETPOSTINT('fk_soc') : (int) $object->fk_soc;
	$hasSupplierForReview = ($companyForForm > 0 || trim((string) ($newSupplier['name'] ?? '')) !== '');
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans('AuvoRechnungsimportSupplier').'</td><td>';
	print '<label class="marginrightonly"><input type="radio" name="supplier_mode" value="existing"'.($supplierModeForForm === 'existing' ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportExistingSupplier').'</label>';
	if ($canCreateSupplierAndBank) print '<label><input type="radio" name="supplier_mode" value="new"'.($supplierModeForForm === 'new' ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportNewSupplier').'</label>';
	print '</td></tr>';
	print '<tr id="auvorechnungsimport-existing-supplier-row"><td class="titlefield">'.$langs->trans('AuvoRechnungsimportSupplier').'</td><td>';
	print $form->select_company($companyForForm, 'fk_soc', '(fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
	print '</td></tr>';
	$refValueForForm = isset($_POST['ref_supplier']) ? GETPOST('ref_supplier', 'alphanohtml') : (string) ($extracted['invoice_ref'] ?? '');
	// This checks the actual supplier invoice table. The broader warning above
	// checks imported documents, so keep this specific duplicate warning by the
	// supplier invoice reference field where the user can resolve it.
	$existingInvoice = auvorechnungsimport_find_existing_invoice($db, $supplierModeForForm === 'existing' ? $companyForForm : 0, $refValueForForm);
	if ($existingInvoice) {
		print '<tr><td colspan="2">'.img_warning().' '.$langs->trans('AuvoRechnungsimportWarnDuplicateRef', $existingInvoice->getNomUrl(1)).'</td></tr>';
	}
	$dateValueForForm = isset($_POST['invoice_date']) ? GETPOST('invoice_date', 'alpha') : (string) ($extracted['invoice_date'] ?? '');
	$dueValueForForm = isset($_POST['due_date']) ? GETPOST('due_date', 'alpha') : (string) ($extracted['due_date'] ?? '');
	print '<tr><td>'.$langs->trans('RefSupplier').'</td><td><input type="text" name="ref_supplier" class="minwidth200" value="'.dol_escape_htmltag($refValueForForm).'"></td></tr>';
	print '<tr><td>'.$langs->trans('Date').'</td><td><input type="date" id="auvorechnungsimport-invoice-date" name="invoice_date" value="'.dol_escape_htmltag($dateValueForForm).'"></td></tr>';
	// Keep payment terms and payment method with the other invoice header fields.
	$supplierPay = new Societe($db);
	$paymentSupplierId = $supplierModeForForm === 'existing' ? $companyForForm : 0;
	if ($paymentSupplierId > 0) $supplierPay->fetch($paymentSupplierId);
	$modeId = auvorechnungsimport_payment_type_id($db, auvorechnungsimport_payment_means_to_dolibarr($extracted['payment_means_code'] ?? ''));
	if ($modeId <= 0) $modeId = (int) $supplierPay->mode_reglement_supplier_id;
	$conditionId = isset($_POST['cond_reglement_id']) ? GETPOSTINT('cond_reglement_id') : (int) ($extracted['payment_condition_id'] ?? $supplierPay->cond_reglement_supplier_id);
	$modeId = isset($_POST['mode_reglement_id']) ? GETPOSTINT('mode_reglement_id') : (int) ($extracted['payment_mode_id'] ?? $modeId);
	if (!isset($_POST['due_date']) && $dueValueForForm === '' && $dateValueForForm !== '' && $conditionId > 0) {
		$calculatedDue = auvorechnungsimport_calculate_due_date($db, dol_stringtotime($dateValueForForm), $conditionId);
		if ($calculatedDue > 0) $dueValueForForm = dol_print_date($calculatedDue, '%Y-%m-%d');
	}
	print '<tr><td>'.$langs->trans('PaymentConditionsShort').'</td><td>';
	print $form->getSelectConditionsPaiements($conditionId, 'cond_reglement_id', -1, 1);
	if (!empty($extracted['payment_terms'])) print ' <span class="opacitymedium small">'.auvorechnungsimport_render_terms($extracted['payment_terms']).'</span>';
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('DateDue').'</td><td><input type="date" id="auvorechnungsimport-due-date" name="due_date" value="'.dol_escape_htmltag($dueValueForForm).'"></td></tr>';
	print '<tr><td>'.$langs->trans('PaymentMode').'</td><td>';
	$form->select_types_paiements($modeId, 'mode_reglement_id', 'DBIT', 0, 1);
	print '</td></tr>';
	if ($hasSupplierForReview && $canCreateSupplierAndBank) {
		print '<tr id="auvorechnungsimport-new-supplier-row"><td colspan="2"><div class="marginbottomonly">';
		print '<b>'.$langs->trans('AuvoRechnungsimportSupplierCreateDetails').'</b>';
		if ($supplierModeForForm === 'new') {
			print '<div class="warning">'.$langs->trans('AuvoRechnungsimportNewSupplierWillCreate', $newSupplier['name'], $newSupplier['vat'] !== '' ? ' ('.$langs->trans('VAT').' '.$newSupplier['vat'].')' : '').'</div>';
		}
		print '<table class="border centpercent auvorechnungsimport-review-data-table"><colgroup><col class="auvorechnungsimport-col-field"><col class="auvorechnungsimport-col-current"><col class="auvorechnungsimport-col-recognized"><col class="auvorechnungsimport-col-action"></colgroup><tr class="liste_titre"><td>'.$langs->trans('AuvoRechnungsimportField').'</td><td>'.$langs->trans('AuvoRechnungsimportCurrentValue').'</td><td>'.$langs->trans('AuvoRechnungsimportRecognizedValue').'</td><td>'.$langs->trans('Action').'</td></tr>';
		$supplierCreateRows = array(
			'Name' => 'name',
			'VAT' => 'vat',
			'AuvoRechnungsimportSupplierTaxId' => 'tax_id',
			'Address' => 'address',
			'Zip' => 'zip',
			'Town' => 'town',
			'Email' => 'email',
			'Phone' => 'phone',
			'Country' => 'country',
		);
		foreach ($supplierCreateRows as $labelKey => $field) {
			$postName = 'supplier_value_'.$field;
			$value = isset($_POST[$postName]) ? (string) GETPOST($postName, $field === 'country' ? 'alpha' : 'restricthtml') : (string) ($newSupplier[$field] ?? '');
			$actionName = 'supplier_action_'.$field;
			$fieldAction = isset($_POST[$actionName]) && GETPOST($actionName, 'alpha') === 'keep' ? 'keep' : 'take';
			print '<tr><td class="titlefield">'.$langs->trans($labelKey).($field === 'name' ? ' *' : '').'</td><td class="opacitymedium"></td><td>';
			if ($field === 'country') {
				print $form->select_country($value, $postName, '', 0, 'minwidth200', 'code2', 1, 0, 0, array(), 1);
			} else {
				print '<input type="text" name="'.dol_escape_htmltag($postName).'" value="'.dol_escape_htmltag($value).'" class="minwidth200"'.($field === 'name' ? ' id="auvorechnungsimport-supplier-name"' : '').'>';
			}
			print '</td><td><select name="'.dol_escape_htmltag($actionName).'"><option value="take"'.($fieldAction === 'take' ? ' selected' : '').'>'.$langs->trans('AuvoRechnungsimportTakeOnCreate').'</option><option value="keep"'.($fieldAction === 'keep' ? ' selected' : '').'>'.$langs->trans('AuvoRechnungsimportKeepBlank').'</option></select>';
			print '</td></tr>';
		}
		print '</table></div></td></tr>';
	}
	print '<!-- supplier bank account review -->';
	$bankDetailsForForm = array_merge(array('iban' => $extracted['iban'] ?? $newSupplier['iban'] ?? '', 'account_holder' => $extracted['account_name'] ?? '', 'bic' => $extracted['bic'] ?? '', 'bank_name' => $extracted['supplier_bank_details']['bank_name'] ?? ''), is_array($extracted['bank_details'] ?? null) ? $extracted['bank_details'] : array());
	foreach (array('iban' => 'bank_iban', 'account_holder' => 'bank_account_holder', 'bic' => 'bank_bic', 'bank_name' => 'bank_name') as $key => $postKey) {
		if (isset($_POST[$postKey])) $bankDetailsForForm[$key] = (string) GETPOST($postKey, in_array($key, array('iban', 'bic'), true) ? 'alphanohtml' : 'restricthtml');
	}
	$bankDetailsForForm['iban'] = strtoupper(preg_replace('/\\s+/', '', (string) $bankDetailsForForm['iban']));
	$existingBankId = 0;
	$bankSupplier = null;
	$bankAccounts = array();
	if ($supplierModeForForm === 'existing' && $companyForForm > 0) {
		$bankSupplier = new Societe($db);
		if ($bankSupplier->fetch($companyForForm) > 0) {
			$bankAccounts = $bankSupplier->get_all_rib();
			if (!is_array($bankAccounts)) $bankAccounts = array();
			$existingBankId = auvorechnungsimport_find_supplier_bank($db, $companyForForm, $bankDetailsForForm['iban']);
		}
	}
	$bankModeForForm = isset($_POST['bank_mode']) ? GETPOST('bank_mode', 'alpha') : (string) ($extracted['bank_mode'] ?? '');
	if (!in_array($bankModeForForm, array('existing', 'new', 'skip'), true)) {
		$bankModeForForm = $existingBankId > 0 ? 'existing' : ($bankDetailsForForm['iban'] !== '' ? 'new' : 'skip');
	}
	if ($bankModeForForm === 'existing' && empty($bankAccounts)) $bankModeForForm = ($canCreateSupplierAndBank && $bankDetailsForForm['iban'] !== '') ? 'new' : 'skip';
	if ($bankModeForForm === 'new' && !$canCreateSupplierAndBank) $bankModeForForm = 'skip';
	$selectedExistingBank = isset($_POST['existing_bank_id']) ? GETPOSTINT('existing_bank_id') : (int) ($extracted['existing_bank_id'] ?? $existingBankId);
	$bankActionsForForm = is_array($extracted['bank_field_actions'] ?? null) ? $extracted['bank_field_actions'] : array();
	if ($hasSupplierForReview) {
	print '<tr><td colspan="2"><div class="div-table-responsive-no-min">';
	print '<table class="border centpercent auvorechnungsimport-review-data-table"><colgroup><col class="auvorechnungsimport-col-field"><col class="auvorechnungsimport-col-current"><col class="auvorechnungsimport-col-recognized"><col class="auvorechnungsimport-col-action"></colgroup><tr class="liste_titre"><td colspan="4">'.$langs->trans('AuvoRechnungsimportBankReviewTitle').'</td></tr>';
	print '<tr><td class="titlefield">'.$langs->trans('AuvoRechnungsimportBankMode').'</td><td colspan="3">';
	print '<label class="marginrightonly"><input type="radio" name="bank_mode" value="existing"'.($bankModeForForm === 'existing' ? ' checked' : '').(empty($bankAccounts) ? ' disabled' : '').'> '.$langs->trans('AuvoRechnungsimportBankUseExisting').'</label>';
	if ($canCreateSupplierAndBank) print '<label class="marginrightonly"><input type="radio" name="bank_mode" value="new"'.($bankModeForForm === 'new' ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportBankCreateNew').'</label>';
	print '<label><input type="radio" name="bank_mode" value="skip"'.($bankModeForForm === 'skip' ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportBankSkip').'</label></td></tr>';
	print '<tr id="auvorechnungsimport-existing-bank-row"><td class="titlefield">'.$langs->trans('AuvoRechnungsimportBankExistingAccount').'</td><td colspan="3"><select name="existing_bank_id" class="minwidth300"><option value="0"></option>';
	$selectedBank = null;
	foreach ($bankAccounts as $bankAccount) {
		$accountIban = preg_replace('/\\s+/', '', strtoupper((string) ($bankAccount->iban ?? '')));
		$accountLabel = trim((string) ($bankAccount->label ?? ''));
		if ($accountLabel === '') $accountLabel = $langs->trans('AuvoRechnungsimportBankAccount');
		$display = $accountLabel.($accountIban !== '' ? ' — '.$accountIban : '');
		print '<option value="'.((int) $bankAccount->id).'"'.(((int) $bankAccount->id === $selectedExistingBank) ? ' selected' : '').'>'.dol_escape_htmltag($display).'</option>';
		if ((int) $bankAccount->id === $selectedExistingBank) $selectedBank = $bankAccount;
	}
	print '</select></td></tr>';
	print '<tr id="auvorechnungsimport-bank-preview"><td colspan="4"><div class="warning">'.$langs->trans('AuvoRechnungsimportBankWillCreate', '<span id="auvorechnungsimport-bank-preview-iban">'.dol_escape_htmltag($bankDetailsForForm['iban']).'</span>').'</div></td></tr>';
	$bankCurrent = array(
		'iban' => (string) ($selectedBank->iban ?? ''),
		'account_holder' => (string) ($selectedBank->owner_name ?? ''),
		'bic' => (string) ($selectedBank->bic ?? ''),
		'bank_name' => (string) ($selectedBank->label ?? ''),
	);
	$bankSupplierName = $supplierModeForForm === 'existing' ? (string) ($bankSupplier->name ?? '') : (string) ($newSupplier['name'] ?? '');
	if ($bankDetailsForForm['account_holder'] === '') $bankDetailsForForm['account_holder'] = $bankSupplierName;
	foreach (array('IBAN' => array('iban', 'bank_iban', false), 'AuvoRechnungsimportAccountHolder' => array('account_holder', 'bank_account_holder', true), 'BIC' => array('bic', 'bank_bic', true), 'AuvoRechnungsimportBankName' => array('bank_name', 'bank_name', false)) as $labelKey => $bankField) {
		list($key, $postName, $withAction) = $bankField;
		$value = (string) ($bankDetailsForForm[$key] ?? '');
		$currentValue = (string) ($bankCurrent[$key] ?? '');
		print '<tr class="auvorechnungsimport-bank-new-row"><td class="titlefield">'.$langs->trans($labelKey).'</td><td class="opacitymedium">'.dol_escape_htmltag($currentValue).'</td><td><input type="text" name="'.dol_escape_htmltag($postName).'" value="'.dol_escape_htmltag($value).'" class="minwidth200">';
		if ($withAction) {
			$action = isset($_POST['bank_action_'.$key]) ? GETPOST('bank_action_'.$key, 'alpha') : (string) ($bankActionsForForm[$key] ?? 'take');
			print '</td><td><select name="bank_action_'.$key.'"><option value="take"'.($action !== 'blank' ? ' selected' : '').'>'.$langs->trans('AuvoRechnungsimportTakeValue').'</option><option value="blank"'.($action === 'blank' ? ' selected' : '').'>'.$langs->trans('AuvoRechnungsimportLeaveValueBlank').'</option></select></td></tr>';
		} else {
			print '</td><td></td></tr>';
		}
	}
	print '</table></div></td></tr>';
	}
	if (!empty($extracted['is_credit_note'])) {
		$candidates = auvorechnungsimport_source_invoice_candidates($db, (int) $object->fk_soc, (string) $extracted['invoice_ref']);
		$wanted = array();
		foreach ($extracted['preceding_invoices'] ?? array() as $pi) {
			$wanted[] = (string) $pi['ref'];
		}
		print '<tr><td class="'.(getDolGlobalString('INVOICE_CREDIT_NOTE_STANDALONE') ? '' : 'fieldrequired').'">'.$langs->trans('AuvoRechnungsimportCreditSource').'</td><td>';
		print '<select name="fac_avoir" class="minwidth300"><option value="0"></option>';
		$preselected = 0;
		foreach ($candidates as $c) {
			$isWanted = in_array($c['ref_supplier'], $wanted, true);
			if ($isWanted && !$preselected) {
				$preselected = $c['id'];
			}
			print '<option value="'.$c['id'].'"'.($isWanted ? ' selected' : '').'>'.dol_escape_htmltag($c['ref_supplier'].' ('.$c['ref'].') '.dol_print_date($c['date'], 'day').' '.price($c['total_ttc']).' '.($object->fk_soc > 0 ? '' : '- '.$c['socname'])).'</option>';
		}
		print '</select>';
		if (!$preselected && !empty($wanted)) {
			print ' <span class="opacitymedium">'.img_warning().' '.$langs->trans('AuvoRechnungsimportCreditSourceNotFound', dol_escape_htmltag(implode(', ', $wanted))).'</span>';
		}
		print '</td></tr>';
	}

	$headerNote = auvorechnungsimport_header_note($extracted, auvorechnungsimport_header_labels($langs));
	if ($headerNote !== '') {
		print '<tr><td>'.$langs->trans('NotePrivate').'</td><td class="opacitymedium small">'.dol_nl2br(dol_escape_htmltag($headerNote)).'</td></tr>';
	}
	print '</table>';

	$lines = isset($extracted['lines']) && is_array($extracted['lines']) ? $extracted['lines'] : array();
	$manualLine = empty($lines);
	if ($manualLine) {
		// Keep the same editable table available even if text/OCR/XML found no
		// positions. The user must explicitly enter the VAT rate (including 0).
		$smallBusiness19 = !empty($extracted['small_business_19_detected']);
		$lines = array(0 => array('source' => 'manual', 'ref_supplier' => '', 'gtin' => '', 'label' => '', 'qty' => '', 'price' => null, 'price_base' => $smallBusiness19 ? 'TTC' : 'HT', 'vat_rate' => $smallBusiness19 ? 0 : null, 'total' => 0, 'fk_product' => 0));
	}
	$smallBusiness19 = !empty($extracted['small_business_19_detected']);
	if (!empty($lines)) {
		// Matched at display time against the currently assigned supplier, so what
		// was learned since the upload (or a supplier corrected via re-run) applies.
		if (!$manualLine) {
			$lines = auvorechnungsimport_match_lines($db, (int) $object->fk_soc, $lines);
		}
		$sourceLabels = array(
			'supplier_price' => array('AuvoRechnungsimportMatchSupplierPrice', 'status4'),
			'learned_ref' => array('AuvoRechnungsimportMatchLearned', 'status4'),
			'learned_label' => array('AuvoRechnungsimportMatchLearned', 'status4'),
			'barcode' => array('AuvoRechnungsimportMatchBarcode', 'status4'),
			'buyer_ref' => array('AuvoRechnungsimportMatchBuyerRef', 'status4'),
			'variant' => array('AuvoRechnungsimportMatchVariant', 'status4'),
			'suggest_ref' => array('AuvoRechnungsimportMatchSuggest', 'status1'),
			'suggest_label' => array('AuvoRechnungsimportMatchSuggest', 'status1'),
		);

		// All lines of one invoice share the detected price base. E-invoice lines
		// are net by default; a detected German § 19 note can override that.
		$gross = $smallBusiness19 || (($lines[0]['price_base'] ?? 'HT') === 'TTC');
		$reviewNumberFormat = array('decimal' => $langs->transnoentitiesnoconv('SeparatorDecimal'), 'thousands' => $langs->transnoentitiesnoconv('SeparatorThousand'));
		$reviewThousands = in_array($reviewNumberFormat['thousands'], array('SeparatorThousand', 'None'), true) ? '.' : $reviewNumberFormat['thousands'];
		$reviewExpectedNet = isset($extracted['amount_ht']) && $extracted['amount_ht'] !== null ? (string) (float) $extracted['amount_ht'] : '';
		$reviewExpectedGross = isset($extracted['amount_ttc']) && $extracted['amount_ttc'] !== null ? (string) (float) $extracted['amount_ttc'] : '';

		print '<div id="auvorechnungsimport-lines-section">';
		print '<div id="auvorechnungsimport-lines-heading">'.load_fiche_titre($langs->trans('AuvoRechnungsimportLinesTitle'), '', '').'</div>';
		print '<div id="auvorechnungsimport-lines-notices"><div id="auvorechnungsimport-lines-notice-content">';
		if (($lines[0]['source'] ?? '') === 'text' && !$smallBusiness19) {
			if (($extracted['lines_check'] ?? '') === 'mismatch') {
				print '<div class="warning">'.$langs->trans('AuvoRechnungsimportLinesFromTextCheck').'</div>';
			}
			print '<div class="info">'.$langs->trans($gross ? 'AuvoRechnungsimportLinesGross' : 'AuvoRechnungsimportLinesNet').'</div>';
		}
		if ($smallBusiness19) print '<div class="info">'.$langs->trans('AuvoRechnungsimportSmallBusiness19Detected').'</div>';
		print '<div id="auvorechnungsimport-lines-total-feedback" class="error small" style="display:none" data-decimal="'.dol_escape_htmltag($reviewNumberFormat['decimal'] === 'SeparatorDecimal' ? ',' : $reviewNumberFormat['decimal']).'" data-thousands="'.dol_escape_htmltag($reviewThousands).'" data-expected-net="'.dol_escape_htmltag($reviewExpectedNet).'" data-expected-gross="'.dol_escape_htmltag($reviewExpectedGross).'" data-total-mismatch="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportErrReviewTotalMismatch')).'" data-mismatch-gross="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportMismatchGross', '__EXPECTED__', '__ACTUAL__')).'" data-mismatch-net="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportMismatchNet', '__EXPECTED__', '__ACTUAL__')).'" data-mismatch-no-lines="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportMismatchNoLines')).'" data-mismatch-advice="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportMismatchAdvice')).'" data-no-detected-lines="'.(empty($extracted['lines']) ? '1' : '0').'" data-allow-draft-mismatch="'.(getDolGlobalInt('AUVORECHNUNGSIMPORT_ALLOW_DRAFT_TOTAL_MISMATCH', 0) ? '1' : '0').'" data-mismatch-draft-denied="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportMismatchDraftDenied')).'" data-mismatch-draft-allowed="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportMismatchDraftCanCreate')).'" data-skip-warning="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportSkipWarning', '__AMOUNT__')).'" data-missing-fields="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportMissingFields', '__FIELDS__')).'" data-field-description="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Description')).'" data-field-quantity="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Qty')).'" data-field-price="'.dol_escape_htmltag($langs->transnoentitiesnoconv($gross ? 'PriceUTTC' : 'PriceUHT')).'" data-field-vat="'.dol_escape_htmltag($langs->transnoentitiesnoconv('VAT')).'" data-field-product="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Product')).'" data-field-supplier="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportSupplier')).'" data-field-reference="'.dol_escape_htmltag($langs->transnoentitiesnoconv('RefSupplier')).'" data-field-date="'.dol_escape_htmltag($langs->transnoentitiesnoconv('Date')).'" data-german-tax-id-required="'.dol_escape_htmltag($langs->transnoentitiesnoconv('AuvoRechnungsimportGermanSupplierTaxIdField')).'"></div>';
		print '</div><button type="button" id="auvorechnungsimport-add-line" class="button-info" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportAddLine')).'" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportAddLine')).'"><span class="fa fa-plus-circle" aria-hidden="true"></span></button></div>';
		print '<div class="div-table-responsive">';
		print '<table id="auvorechnungsimport-lines-table" class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td class="center" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportLineMode')).'" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportLineMode')).'">🔧</td>';
		print '<td class="center" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportSupplierItemRefHelp')).'" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportSupplierItemRefHelp')).'">🆔</td>';
		print '<td>'.$langs->trans('Description').'</td>';
		print '<td class="right">'.$langs->trans('Qty').'</td>';
		print '<td class="right" id="auvorechnungsimport-price-header">'.$langs->trans($gross ? 'AuvoRechnungsimportPriceGrossShort' : 'PriceUHT').'</td>';
		print '<td class="right">'.$langs->trans('VAT').' %</td>';
		print '<td class="right" id="auvorechnungsimport-total-header">'.$langs->trans($gross ? 'AuvoRechnungsimportTotalGrossShort' : 'AuvoRechnungsimportTotalNetShort').'</td>';
		print '<td>'.$langs->trans('Product').'</td>';
		print '</tr>';

		// Respect Dolibarr's product-search preference. If unset, use AJAX search
		// by default so a large catalogue is not duplicated in every table row.
		$savedSearchToSelect = getDolGlobalString('PRODUIT_USE_SEARCH_TO_SELECT');
		$conf->global->PRODUIT_USE_SEARCH_TO_SELECT = ($savedSearchToSelect === '') ? '2' : $savedSearchToSelect;

		$sumReviewNet = 0;
		$sumReviewGross = 0;
		$lineCount = count($lines);
		$renderLines = $lines;
		$extraRows = 10;
		for ($extra = 0; $extra < $extraRows; $extra++) {
			$renderLines[$lineCount + $extra] = array('source' => 'manual', 'mode' => 'book', 'ref_supplier' => '', 'gtin' => '', 'label' => '', 'qty' => '', 'price' => null, 'price_base' => $gross ? 'TTC' : ($lines[0]['price_base'] ?? 'HT'), 'vat_rate' => $smallBusiness19 ? 0 : null, 'total' => 0, 'fk_product' => 0, 'unit_code' => '');
		}
		foreach ($renderLines as $i => $line) {
			$isTemplateRow = $i >= $lineCount;
			$disabledAttr = $isTemplateRow ? ' disabled' : '';
			$total = $line['total'] ?? ((float) $line['qty'] * (float) $line['price']);
			$mode = $line['mode'] ?? 'book';
			$priceBase = $smallBusiness19 || (($line['price_base'] ?? 'HT') === 'TTC') ? 'TTC' : 'HT';
			$vatRate = $smallBusiness19 ? 0 : $line['vat_rate'];
			$qtyForSum = (float) ($line['qty'] ?? 0);
			$priceForSum = (float) ($line['price'] ?? 0);
			$vatForSum = (float) ($vatRate ?? 0);
			$baseForSum = $qtyForSum * $priceForSum;
			$netForSum = $priceBase === 'TTC' && $vatForSum > 0 ? $baseForSum / (1 + $vatForSum / 100) : $baseForSum;
			$grossForSum = $priceBase === 'TTC' ? $baseForSum : $baseForSum * (1 + $vatForSum / 100);
			if (!$isTemplateRow && $mode !== 'skip') {
				$sumReviewNet += round($netForSum, 2);
				$sumReviewGross += round($grossForSum, 2);
			}
			$modeCodes = array('book' => '➕', 'cost' => '➖', 'skip' => '✖️');
			if (!isset($modeCodes[$mode])) $mode = 'book';
			print '<tr class="oddeven'.($isTemplateRow ? ' auvorechnungsimport-line-template' : ' auvorechnungsimport-line'.($mode === 'skip' ? ' opacitymedium' : '')).'" data-manual="'.($manualLine ? '1' : '0').'" data-price-base="'.$priceBase.'" data-total="'.price2num($total).'">';
			print '<td class="nowraponall">';
			print '<span class="auvorechnungsimport-mode-picker"><button type="button" class="auvorechnungsimport-mode-code" aria-hidden="true"'.$disabledAttr.'>'.$modeCodes[$mode].'</button>';
			print '<select name="line_mode_'.$i.'" class="flat auvorechnungsimport-mode" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportLineMode')).'" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportMode'.ucfirst($mode))).'" data-delete-confirm="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportDeleteLineConfirm')).'"'.$disabledAttr.'>';
			foreach (AUVORECHNUNGSIMPORT_LINE_MODES as $m) {
				print '<option value="'.$m.'"'.($m === $mode ? ' selected' : '').'>'.$modeCodes[$m].' '.$langs->trans('AuvoRechnungsimportMode'.ucfirst($m)).'</option>';
			}
			print '<option value="delete">🗑️ '.$langs->trans('AuvoRechnungsimportDeleteLine').'</option>';
			print '</select></span>';
			if (in_array($line['mode_source'] ?? '', array('learned', 'keyword'), true)) {
				print '<br><span class="badge status4 small">'.$langs->trans($line['mode_source'] === 'learned' ? 'AuvoRechnungsimportMatchLearned' : 'AuvoRechnungsimportModeKeyword').'</span>';
			}
			print '</td>';
			print '<td class="nowraponall"><input type="text" name="line_ref_supplier_'.$i.'" value="'.dol_escape_htmltag($line['ref_supplier'] ?? '').'" title="'.dol_escape_htmltag($langs->trans('RefSupplier')).'"'.$disabledAttr.'>';
			if (!empty($line['gtin'])) {
				print '<br><span class="opacitymedium small">EAN '.dol_escape_htmltag($line['gtin']).'</span>';
			}
			print '</td>';
			print '<td><input type="text" name="line_label_'.$i.'" value="'.dol_escape_htmltag($line['label'] ?? '').'"'.$disabledAttr.'></td>';
			$quantityValue = $manualLine ? '' : price2num($line['qty'] ?? 1);
			print '<td class="right"><input type="text" name="line_qty_'.$i.'" class="right auvorechnungsimport-line-qty" value="'.dol_escape_htmltag($isTemplateRow ? '' : $quantityValue).'"'.$disabledAttr.'>'.(!empty($line['unit_code']) && $line['unit_code'] !== 'C62' ? '<br><span class="opacitymedium small">'.dol_escape_htmltag($line['unit_code']).'</span>' : '').'</td>';
			print '<td class="right nowraponall"><input type="text" name="line_price_'.$i.'" class="right auvorechnungsimport-line-price" value="'.dol_escape_htmltag($line['price'] !== null ? price($line['price'], 0, '', 0, -1, -1) : '').'"'.$disabledAttr.'><input type="hidden" name="line_price_base_'.$i.'" value="'.$priceBase.'"'.$disabledAttr.'></td>';
			print '<td class="right"><input type="text" name="line_vat_'.$i.'" class="right auvorechnungsimport-line-vat" value="'.dol_escape_htmltag($vatRate !== null ? price2num($vatRate) : '').'"'.$disabledAttr.'></td>';
			print '<td class="right nowraponall auvorechnungsimport-line-total">'.price($baseForSum).'</td>';
			print '<td class="nowraponall">';
			$selectedProduct = null;
			$selectedProductNotBuyable = false;
			if (!empty($line['fk_product'])) {
				$selectedProduct = new Product($db);
				if ($selectedProduct->fetch($line['fk_product']) <= 0) $selectedProduct = null;
				elseif (empty($selectedProduct->status_buy)) $selectedProductNotBuyable = true;
			}
			$productPreview = $selectedProduct ? trim($selectedProduct->ref.' - '.$selectedProduct->label."\n".$selectedProduct->description) : '';
			print '<span class="auvorechnungsimport-product-picker'.($selectedProduct ? ' is-selected' : '').'">';
			print '<button type="button" class="auvorechnungsimport-product-search" data-select="lineprod_'.$i.'" title="'.dol_escape_htmltag($langs->trans('Search')).'" aria-label="'.dol_escape_htmltag($langs->trans('Search')).'"'.$disabledAttr.'><span class="fas fa-search" aria-hidden="true"></span></button>';
			print '<span class="auvorechnungsimport-product-actions"><a class="auvorechnungsimport-product-preview" href="'.($selectedProduct ? dol_buildpath('/product/card.php', 1).'?id='.(int) $selectedProduct->id : '#').'" data-product-card-base="'.dol_escape_htmltag(dol_buildpath('/product/card.php', 1).'?id=').'" title="'.dol_escape_htmltag($productPreview).'" aria-label="'.dol_escape_htmltag($selectedProduct ? $selectedProduct->label : $langs->trans('Product')).'" target="_blank" rel="noopener noreferrer"><span class="far fa-sticky-note" aria-hidden="true"></span></a><button type="button" class="auvorechnungsimport-product-clear" title="'.dol_escape_htmltag($langs->trans('Delete')).'" aria-label="'.dol_escape_htmltag($langs->trans('Delete')).'"'.$disabledAttr.'>×</button></span>';
			print '<input type="hidden" class="auvorechnungsimport-product-id" name="line_product_id_'.$i.'" value="'.(int) ($line['fk_product'] ?? 0).'"'.$disabledAttr.'>';
			print '<input type="hidden" name="line_product_touched_'.$i.'" value="0"'.$disabledAttr.'>';
			print $form->select_produits($line['fk_product'], 'lineprod_'.$i, '', 0, 0, -1, 2, '', 1, array(), 0, '1', 0, 'width30', 1, '', null, 1, 1).'</span>';
			if (!empty($line['match_source']) && isset($sourceLabels[$line['match_source']])) {
				list($labelKey, $badge) = $sourceLabels[$line['match_source']];
				print ' <span class="badge '.$badge.'">'.$langs->trans($labelKey).'</span>';
			}
			if ($selectedProductNotBuyable) {
				print '<br><span class="warning small">'.img_warning().' '.$langs->trans('AuvoRechnungsimportProductNotBuyable').'</span>';
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

		print '<tr class="liste_total">';
		print '<td colspan="6" class="right">'.$langs->trans('AuvoRechnungsimportReviewNetSum').'</td>';
		print '<td class="right nowraponall" id="auvorechnungsimport-sum-net">'.price($sumReviewNet).'</td>';
		print '<td></td>';
		print '</tr>';
		print '<tr class="liste_total">';
		print '<td colspan="6" class="right">'.$langs->trans('AuvoRechnungsimportReviewGrossSum').'</td>';
		print '<td class="right nowraponall" id="auvorechnungsimport-sum-gross">'.price($sumReviewGross).'</td>';
		print '<td></td>';
		print '</tr>';
		// What will actually be booked: without skipped lines (updated live)
		print '<tr class="liste_total">';
		print '<td colspan="6" class="right">'.$langs->trans('AuvoRechnungsimportToBook').'</td>';
		print '<td class="right nowraponall" id="auvorechnungsimport-tobook"></td>';
		print '<td id="auvorechnungsimport-skipwarn" class="warning small"></td>';
		print '</tr>';
		print '</table>';
		print '</div>';
		print '</div>';

		if ($canUpdateBuyprice && $updateBuypriceMode !== 'deny' && empty($extracted['is_credit_note'])) {
			$defaultUpdateBuyprice = $updateBuypriceMode === 'auto';
			$updateBuypriceChecked = isset($_POST['update_buyprice']) ? GETPOSTINT('update_buyprice') === 1 : (array_key_exists('update_buyprice', $extracted) ? !empty($extracted['update_buyprice']) : $defaultUpdateBuyprice);
			print '<div class="marginbottomonly"><label><input type="checkbox" name="update_buyprice" value="1"'.($updateBuypriceChecked ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportUpdateBuyprice').'</label> <span class="classfortooltip" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportUpdateBuypriceHelp')).'" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportUpdateBuypriceHelp')).'"><span class="fas fa-info-circle" aria-hidden="true"></span></span></div>';
		}
		if ($allowCreateOrder && $createOrderMode !== 'deny' && empty($extracted['is_credit_note'])) {
			// Pre-ticked when stock is managed and the invoice has stockable products.
			$hasStockable = false;
			foreach ($lines as $line) {
				if (($line['mode'] ?? 'book') !== 'book') continue;
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
			$defaultCreateOrder = $createOrderMode === 'auto' && isModEnabled('stock') && $hasStockable && !$stockOnBill;
			$checked = isset($_POST['create_order']) ? GETPOSTINT('create_order') === 1 : (array_key_exists('create_order', $extracted) ? !empty($extracted['create_order']) : $defaultCreateOrder);
			print '<div class="marginbottomonly"><label><input type="checkbox" name="create_order" value="1"'.($checked ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportCreateOrder').'</label> <span class="classfortooltip" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportCreateOrderHelp')).'" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportCreateOrderHelp')).'"><span class="fas fa-info-circle" aria-hidden="true"></span></span>';
			if ($stockOnBill && $hasStockable) {
				print ' <span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportStockOnBillHint').'</span>';
			}
			print '</div>';
		}
	}

	print '<div class="center marginbottomonly">';
	print '<button type="submit" name="action" value="savereview" class="button auvorechnungsimport-save-review">'.$langs->trans('AuvoRechnungsimportSaveReview').'</button>';
	print '<button type="submit" name="action" id="auvorechnungsimport-create-invoice" value="createinvoice" class="button auvorechnungsimport-create-invoice">'.$langs->trans('AuvoRechnungsimportCreateInvoiceBtn').'</button>';
	if ($allowReleaseOrderButton && empty($extracted['is_credit_note'])) {
		print ' <button type="submit" name="action" id="auvorechnungsimport-create-invoice-release-order" value="createinvoice_releaseorder" class="butAction button auvorechnungsimport-release-order">'.$langs->trans('AuvoRechnungsimportCreateInvoiceAndReleaseOrderBtn').'</button>';
	}
	print '</div>';
	print '</form>';
	print '<div id="auvorechnungsimport-required-fields" class="warning small" style="display:none"></div>';
}
print '</div></main></div></div>';
$reviewJsPath = __DIR__.'/js/auvorechnungsimport_review.js';
$reviewJsVersion = is_file($reviewJsPath) ? filemtime($reviewJsPath) : 1;
print '<script src="'.dol_buildpath('/auvorechnungsimport/js/auvorechnungsimport_review.js', 1).'?v='.(int) $reviewJsVersion.'"></script>';

llxFooter();
