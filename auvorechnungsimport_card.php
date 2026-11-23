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
if (in_array((int) $object->status, array(ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_SPAM_CONFIRMED), true) && in_array($action, array('createinvoice', 'reprocess', 'confirmduplicate', 'delete', 'confirm_delete'), true)) {
	accessforbidden('Spam imports are held for review and cannot be processed from this page');
}
$nonSupplierMatchId = auvorechnungsimport_find_non_supplier_by_vat($db, (string) ($extracted['supplier_vat'] ?? ''));
$previewSupplierId = GETPOSTINT('fk_soc') ?: (int) $object->fk_soc;
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
	if (GETPOSTINT('fk_soc') > 0 || auvorechnungsimport_can_create_supplier_from_fields($user, $extracted)) {
		setEventMessages($langs->trans('AuvoRechnungsimportSupplierPreviewUpdated'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('AuvoRechnungsimportErrNoSupplier'), null, 'errors');
	}
}

if ($action == 'createinvoice' && !GETPOSTINT('preview_supplier') && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$fkSoc = GETPOSTINT('fk_soc');
	$refSupplier = GETPOST('ref_supplier', 'alphanohtml');
	$invoiceDate = GETPOST('invoice_date', 'alpha');

	$reviewTotals = auvorechnungsimport_check_review_totals($extracted);
	$existingInvoice = auvorechnungsimport_find_existing_invoice($db, $fkSoc, $refSupplier);
	if (!empty($reviewTotals['missing_vat_lines'])) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrReviewVatRequired', implode(', ', $reviewTotals['missing_vat_lines'])), null, 'errors');
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
	} elseif ($fkSoc <= 0 && !auvorechnungsimport_can_create_supplier_from_fields($user, $extracted)) {
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
		if (!$autoCreateSupplier) {
			$selectedBank = new CompanyBankAccount($db);
			if ($selectedBank->fetch(0, '', $fkSoc, -1) > 0) $currentIban = (string) $selectedBank->iban;
		}
		$selectedContact = new Contact($db);
		$currentContact = '';
		if (!$autoCreateSupplier && !empty($extracted['supplier_email']) && $selectedContact->fetch(0, null, '', (string) $extracted['supplier_email'], 0, $fkSoc) > 0) {
			$currentContact = trim($selectedContact->firstname.' '.$selectedContact->lastname);
		}
		$postedSupplierData = array();
		foreach (array('name', 'address', 'zip', 'town', 'country', 'email', 'phone', 'vat') as $field) {
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
			'update_supplier_iban' => array($currentIban, (string) ($extracted['supplier_iban'] ?? ''), $user->hasRight('societe', 'creer'), 'iban'),
			'update_supplier_contact' => array($currentContact, trim((string) ($extracted['supplier_contact_firstname'] ?? '').' '.(string) ($extracted['supplier_contact_lastname'] ?? '')), $user->hasRight('societe', 'contact', 'creer'), 'contact'),
		);
		$comparisonRows['update_supplier_name'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_name'][0];
		$comparisonRows['update_supplier_address'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_address'][0];
		$comparisonRows['update_supplier_zip'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_zip'][0];
		$comparisonRows['update_supplier_town'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_town'][0];
		$comparisonRows['update_supplier_country'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_country'][0];
		$comparisonRows['update_supplier_vat'][0] = $autoCreateSupplier ? '' : $comparisonRows['update_supplier_vat'][0];
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
			$createFields = $extracted;
			$createFields['supplier_name'] = $supplierChanges['name'] !== '' ? $supplierChanges['name'] : (string) ($extracted['supplier_name'] ?? '');
			$createFields['supplier_email'] = $supplierChanges['email'] !== '' ? $supplierChanges['email'] : (string) ($extracted['supplier_email'] ?? '');
			$createFields['supplier_phone'] = $supplierChanges['phone'] !== '' ? $supplierChanges['phone'] : (string) ($extracted['supplier_phone'] ?? '');
			$createFields['supplier_country'] = $supplierChanges['country'] !== '' ? $supplierChanges['country'] : (string) ($extracted['supplier_country'] ?? '');
			$createFields['supplier_vat'] = $supplierChanges['vat'] !== '' ? $supplierChanges['vat'] : (string) ($extracted['supplier_vat'] ?? '');
			$createFields['supplier_address'] = array(
				'line1' => $supplierChanges['address'] !== '' ? $supplierChanges['address'] : (string) ($address['line1'] ?? ''), 'line2' => (string) ($address['line2'] ?? ''), 'line3' => (string) ($address['line3'] ?? ''),
				'postcode' => $supplierChanges['zip'] !== '' ? $supplierChanges['zip'] : (string) ($address['postcode'] ?? ''), 'city' => $supplierChanges['town'] !== '' ? $supplierChanges['town'] : (string) ($address['city'] ?? ''), 'country' => $supplierChanges['country'] !== '' ? $supplierChanges['country'] : (string) ($address['country'] ?? ''),
			);
			$supplierCreateError = '';
			$fkSoc = auvorechnungsimport_create_supplier_from_fields($db, $user, $createFields, $supplierChoices, $supplierCreateError);
			if ($fkSoc <= 0) {
				$result = -1;
				setEventMessages($langs->trans('AuvoRechnungsimportErrAutoCreateSupplier'), array_filter(array($supplierCreateError)), 'errors');
			}
		}
		if ($result > 0 && $user->hasRight('societe', 'creer') && ($supplierChoices['update_supplier_iban'] ?? '') === 'update' && !empty($extracted['supplier_iban'])) {
			$iban = preg_replace('/\\s+/', '', strtoupper((string) $extracted['supplier_iban']));
			if (!checkIbanForAccount(null, $iban)) {
				$result = -1;
				setEventMessages($langs->trans('IBANNotValid'), null, 'errors');
			}
			$ibanQuery = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."societe_rib WHERE fk_soc = ".((int) $fkSoc));
			$ibanExists = false;
			$hasExistingBankAccount = false;
			if ($result > 0 && $ibanQuery) {
				while ($ibanRow = $db->fetch_object($ibanQuery)) {
					$hasExistingBankAccount = true;
					$existingBank = new CompanyBankAccount($db);
					if ($existingBank->fetch((int) $ibanRow->rowid) > 0 && preg_replace('/\\s+/', '', strtoupper((string) $existingBank->iban)) === $iban) {
						$ibanExists = true;
						break;
					}
				}
			}
			if ($result > 0 && !$ibanExists) {
				$bank = new CompanyBankAccount($db);
				$bank->socid = $fkSoc;
				if ($bank->create($user) > 0) {
					$bank->iban = $iban;
					$bank->label = 'IBAN from e-invoice';
					$bank->default_rib = $hasExistingBankAccount ? 0 : 1;
					if ($bank->update($user) <= 0) $result = -1;
				} else {
					$result = -1;
				}
			} elseif ($result > 0 && !$hasExistingBankAccount && $ibanExists) {
				$bank = new CompanyBankAccount($db);
				if ($bank->fetch((int) $ibanRow->rowid) > 0) {
					$bank->default_rib = 1;
					if ($bank->update($user) <= 0) $result = -1;
				}
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
		$updateBuyprice = GETPOSTINT('update_buyprice') && $canUpdateBuyprice;
		$productTypes = array();
		$createdVariants = array();
		foreach ($extractedLines as $i => $line) {
			if ($result <= 0) {
				break;
			}
			$mode = GETPOST('line_mode_'.$i, 'aZ09');
			if (!in_array($mode, AUVORECHNUNGSIMPORT_LINE_MODES, true)) {
				$mode = 'book';
			}
			$fkProduct = ($mode === 'book') ? GETPOSTINT('lineprod_'.$i) : 0;
			$line['label'] = GETPOST('line_label_'.$i, 'restricthtml');
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
			$line['vat_rate'] = (float) price2num(GETPOST('line_vat_'.$i, 'alpha'));
			$submittedPriceBase = GETPOST('line_price_base_'.$i, 'alpha');
			if (in_array($submittedPriceBase, array('HT', 'TTC'), true)) $line['price_base'] = $submittedPriceBase;

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
			$resline = $invoice->addline($line['label'], $pu, $line['vat_rate'], 0, 0, $qty, $fkProduct, 0, '', '', 0, 0, (($line['price_base'] ?? 'HT') === 'TTC' ? 'TTC' : 'HT'), $type, -1, 1, array(), null, 0, 0, (string) ($line['ref_supplier'] ?? ''));
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
		if ($result > 0 && empty($extractedLines)) {
			$manualLabel = trim((string) GETPOST('manual_line_label', 'restricthtml'));
			$manualQtyRaw = GETPOST('manual_line_qty', 'alpha');
			$manualPriceRaw = GETPOST('manual_line_price', 'alpha');
			$manualVatRaw = GETPOST('manual_line_vat', 'alpha');
			$manualQty = (float) price2num($manualQtyRaw);
			$manualPrice = (float) price2num($manualPriceRaw, 'MU');
			$manualVat = (float) price2num($manualVatRaw);
			if ($manualLabel === '' || !is_numeric($manualQtyRaw) || !is_numeric($manualPriceRaw) || !is_numeric($manualVatRaw)
				|| $manualQty <= 0 || $manualPrice <= 0 || $manualVat < 0 || $manualVat > 100) {
				$result = -1;
				setEventMessages($langs->trans('AuvoRechnungsimportManualLineRequired'), null, 'errors');
			} else {
				$manualResult = $invoice->addline($manualLabel, $manualPrice, $manualVat, 0, 0, $manualQty, 0, 0, '', '', 0, 0, 'HT', 0);
				if ($manualResult <= 0) {
					$result = -1;
					$invoice->errors[] = $db->lasterror();
				}
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
	if ($filetoremove !== false) {
		dol_delete_file($filetoremove, 1);
		dol_delete_dir(dirname($filetoremove), 1);
	}
	$result = $object->delete($user);
	if ($result > 0) {
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1));
		exit;
	} else {
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
$nextId = auvorechnungsimport_next_to_review($db, $object->id);
if ($nextId && (int) $object->status !== ImportedInvoice::STATUS_SPAM_REVIEW) {
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
print '<tr><td>'.$langs->trans('AuvoRechnungsimportDetectedName').'</td><td>'.dol_escape_htmltag($extracted['supplier_name'] ?? '').'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportMatchConfidence').'</td><td>'.dol_escape_htmltag($object->supplier_match_confidence).'</td></tr>';
print '<tr><td>'.$langs->trans('AuvoRechnungsimportDetectedAmount').'</td><td>'.(isset($extracted['amount_ttc']) && $extracted['amount_ttc'] !== null ? price($extracted['amount_ttc']).' '.dol_escape_htmltag($extracted['currency'] ?? '') : '').'</td></tr>';
print '</table>';
print '</div>';
print '<div class="clearboth"></div>';
print '</div>';

print dol_get_fiche_end();

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
} elseif ((int) $object->status === ImportedInvoice::STATUS_DUPLICATE && $object->fk_facture_fourn > 0) {
	print '<div class="warning">'.$langs->trans('AuvoRechnungsimportDuplicateRecord').' ';
	$invoice = new FactureFournisseur($db);
	if ($invoice->fetch($object->fk_facture_fourn) > 0) print $invoice->getNomUrl(1);
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
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
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
	print $form->select_company($previewSupplierId, 'fk_soc', '(fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
	print ' <button type="submit" name="preview_supplier" value="1" class="button">'.$langs->trans('AuvoRechnungsimportPreviewSupplierUpdates').'</button>';
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('RefSupplier').'</td><td><input type="text" name="ref_supplier" class="minwidth200" value="'.dol_escape_htmltag($extracted['invoice_ref'] ?? '').'">';
	$existingInvoice = auvorechnungsimport_find_existing_invoice($db, $previewSupplierId, (string) ($extracted['invoice_ref'] ?? ''));
	if ($existingInvoice) {
		print ' '.img_warning().' '.$langs->trans('AuvoRechnungsimportWarnDuplicateRef', $existingInvoice->getNomUrl(1));
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('Date').'</td><td><input type="date" name="invoice_date" value="'.dol_escape_htmltag($extracted['invoice_date'] ?? '').'"></td></tr>';
	print '</table>';
	$autoCreatePreview = ($previewSupplierId <= 0 && auvorechnungsimport_can_create_supplier_from_fields($user, $extracted));
	$hasExtractedSupplierData = !empty($extracted['supplier_name']) || !empty($extracted['supplier_vat'])
		|| !empty($extracted['supplier_email']) || !empty($extracted['supplier_phone']) || !empty($extracted['supplier_iban'])
		|| !empty($extracted['supplier_country']) || !empty(array_filter((array) ($extracted['supplier_address'] ?? array())))
		|| (!empty($extracted['supplier_contact_firstname']) && !empty($extracted['supplier_contact_lastname']));
	if (($previewSupplierId > 0 || $autoCreatePreview || $hasExtractedSupplierData) && $hasExtractedSupplierData) {
		$matchedSupplier = new Societe($db);
		if ($previewSupplierId > 0) $matchedSupplier->fetch($previewSupplierId);
		$currentBankValue = '';
		if ($previewSupplierId > 0) {
			$currentBank = new CompanyBankAccount($db);
			if ($currentBank->fetch(0, '', $previewSupplierId, -1) > 0) $currentBankValue = (string) $currentBank->iban;
		}
		print '<br>'.load_fiche_titre($langs->trans('AuvoRechnungsimportSupplierDataTitle'), '', '');
		print '<div class="info">'.$langs->trans('AuvoRechnungsimportSupplierPreviewHelp').'</div>';
		if ($autoCreatePreview) {
			$vatSuffix = !empty($extracted['supplier_vat']) ? ' ('.$langs->transnoentities('AuvoRechnungsimportDetectedVat').' '.(string) $extracted['supplier_vat'].')' : '';
			// trans() HTML-escapes the completed message, so pass raw values here.
			print '<div class="warning">'.$langs->trans('AuvoRechnungsimportNewSupplierWillCreate', (string) ($extracted['supplier_name'] ?? ''), $vatSuffix).'</div>';
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
			'AuvoRechnungsimportSupplierName' => array('Name', (string) $matchedSupplier->name, (string) ($extracted['supplier_name'] ?? ''), 'name'),
			'VAT' => array('VAT', (string) $matchedSupplier->tva_intra, (string) ($extracted['supplier_vat'] ?? ''), 'vat'),
			'Address' => array('Address', (string) $matchedSupplier->address, trim(implode("\n", array_filter(array((string) ($address['line1'] ?? ''), (string) ($address['line2'] ?? ''), (string) ($address['line3'] ?? '')), 'strlen'))), 'address'),
			'Zip' => array('Zip', (string) $matchedSupplier->zip, (string) ($address['postcode'] ?? ''), 'zip'),
			'Town' => array('Town', (string) $matchedSupplier->town, (string) ($address['city'] ?? ''), 'town'),
			'Email' => array('Email', (string) $matchedSupplier->email, (string) ($extracted['supplier_email'] ?? ''), 'email'),
			'Phone' => array('Phone', (string) $matchedSupplier->phone, (string) ($extracted['supplier_phone'] ?? ''), 'phone'),
			'Country' => array('Country', (string) ($matchedSupplier->country_code ?? ''), (string) ($extracted['supplier_country'] ?? ''), 'country'),
			'IBAN' => array('IBAN', $currentBankValue, (string) ($extracted['supplier_iban'] ?? ''), 'iban'),
			'AuvoRechnungsimportSupplierContact' => array('AuvoRechnungsimportSupplierContact', $currentContactText, (!empty($extracted['supplier_contact_firstname']) && !empty($extracted['supplier_contact_lastname'])) ? trim($extracted['supplier_contact_firstname'].' '.$extracted['supplier_contact_lastname'].' · '.(string) ($extracted['supplier_email'] ?? '')) : '', 'contact'),
		);
		foreach ($supplierRows as $row) {
			list($labelKey, $currentValue, $extractedValue, $field) = $row;
			if ($field === 'contact' && (empty($extracted['supplier_contact_firstname']) || empty($extracted['supplier_contact_lastname']))) continue;
			if ($field === 'country' && $extractedValue === '') $extractedValue = 'DE';
			if ($extractedValue === '' && !$autoCreatePreview) continue;
			if ($autoCreatePreview) $currentValue = '';
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
				$choice = $conflict ? '' : ((trim($currentValue) === '' && $user->hasRight('societe', 'creer')) ? 'update' : 'keep');
			}
			$required = ($conflict || ($autoCreatePreview && $field === 'name')) && $user->hasRight('societe', 'creer') ? ' required="required"' : '';
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
	if (!empty($lines)) {
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

		// All lines of one invoice share the price base: text detection decides
		// net vs. gross per invoice, Factur-X is always net.
		$gross = (($lines[0]['price_base'] ?? 'HT') === 'TTC');
		print '<br>';
		print load_fiche_titre($langs->trans('AuvoRechnungsimportLinesTitle'), '', '');
		if (($lines[0]['source'] ?? '') === 'text') {
			$hintKey = (($extracted['lines_check'] ?? '') === 'ok') ? 'AuvoRechnungsimportLinesFromTextOk' : 'AuvoRechnungsimportLinesFromTextCheck';
			print '<div class="'.($hintKey === 'AuvoRechnungsimportLinesFromTextOk' ? 'info' : 'warning').'">'.$langs->trans($hintKey).($gross ? ' '.$langs->trans('AuvoRechnungsimportLinesGross') : '').'</div>';
		}
		print '<div class="div-table-responsive">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('AuvoRechnungsimportLineMode').'</td>';
		print '<td>'.$langs->trans('RefSupplier').'</td>';
		print '<td>'.$langs->trans('Description').'</td>';
		print '<td class="right">'.$langs->trans('Qty').'</td>';
		print '<td class="right" id="auvorechnungsimport-price-header" data-ht="'.dol_escape_htmltag($langs->trans('PriceUHT')).'" data-ttc="'.dol_escape_htmltag($langs->trans('PriceUTTC')).'" data-mixed="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportPriceMixed')).'">'.$langs->trans($gross ? 'PriceUTTC' : 'PriceUHT').'</td>';
		print '<td class="right">'.$langs->trans('VAT').' %</td>';
		print '<td class="right" id="auvorechnungsimport-total-header" data-ht="'.dol_escape_htmltag($langs->trans('TotalHT')).'" data-ttc="'.dol_escape_htmltag($langs->trans('TotalTTC')).'" data-mixed="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportTotalMixed')).'">'.$langs->trans($gross ? 'TotalTTC' : 'TotalHT').'</td>';
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
			$mode = $line['mode'] ?? 'book';
			$priceBase = (($line['price_base'] ?? 'HT') === 'TTC') ? 'TTC' : 'HT';
			print '<tr class="oddeven auvorechnungsimport-line'.($mode === 'skip' ? ' opacitymedium' : '').'" data-price-base="'.$priceBase.'">';
			print '<td class="nowraponall">';
			print '<select name="line_mode_'.$i.'" class="flat auvorechnungsimport-mode">';
			foreach (AUVORECHNUNGSIMPORT_LINE_MODES as $m) {
				print '<option value="'.$m.'"'.($m === $mode ? ' selected' : '').'>'.$langs->trans('AuvoRechnungsimportMode'.ucfirst($m)).'</option>';
			}
			print '</select>';
			if (!empty($line['mode_source'])) {
				print '<br><span class="badge status4 small">'.$langs->trans($line['mode_source'] === 'learned' ? 'AuvoRechnungsimportMatchLearned' : 'AuvoRechnungsimportModeKeyword').'</span>';
			}
			print '</td>';
			print '<td class="nowraponall">'.dol_escape_htmltag($line['ref_supplier'] ?? '');
			if (!empty($line['gtin'])) {
				print '<br><span class="opacitymedium small">EAN '.dol_escape_htmltag($line['gtin']).'</span>';
			}
			print '</td>';
			print '<td><input type="text" name="line_label_'.$i.'" class="minwidth200 maxwidth300 widthcentpercentminusx" value="'.dol_escape_htmltag($line['label'] ?? '').'"></td>';
			print '<td class="right"><input type="text" name="line_qty_'.$i.'" class="width50 right" value="'.dol_escape_htmltag(price2num($line['qty'] ?? 1)).'"></td>';
			print '<td class="right nowraponall"><span style="display:inline-flex;align-items:center;white-space:nowrap;gap:2px"><input type="text" name="line_price_'.$i.'" class="width75 right" value="'.dol_escape_htmltag($line['price'] !== null ? price($line['price'], 0, '', 0, -1, -1) : '').'">';
			if ($priceBase === 'TTC') {
				print ' <input type="hidden" name="line_price_base_'.$i.'" value="TTC">';
				print '<button type="button" class="flat auvorechnungsimport-net-preview-toggle" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportShowNetUnitPrice')).'" style="font-size:14px;line-height:18px;padding:0 2px;border:0;background:transparent;cursor:pointer" aria-label="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportShowNetUnitPrice')).'">&#128425;</button>';
			} else {
				print '<input type="hidden" name="line_price_base_'.$i.'" value="HT">';
			}
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
			print '<td class="right"><input type="text" name="line_vat_'.$i.'" class="width40 right" value="'.dol_escape_htmltag($vatRate !== null ? price2num($vatRate) : '').'" required></td>';
			print '<td class="right nowraponall"><span class="auvorechnungsimport-line-total">'.price($lineBase).'</span></td>';
			print '<td class="nowraponall">';
			print $form->select_produits($line['fk_product'], 'lineprod_'.$i, '', 0, 0, -1, 2, '', 1, array(), 0, '1', 0, 'minwidth250', 1, '', null, 1, 1);
			if (!empty($line['match_source']) && isset($sourceLabels[$line['match_source']])) {
				list($labelKey, $badge) = $sourceLabels[$line['match_source']];
				print ' <span class="badge '.$badge.'">'.$langs->trans($labelKey).'</span>';
			}
			if (!empty($line['fk_product'])) {
				$p = new Product($db);
				if ($p->fetch($line['fk_product']) > 0 && empty($p->status_buy)) {
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

		print '<tr class="liste_total">';
		print '<td colspan="6" class="right">'.$langs->trans('AuvoRechnungsimportReviewNetSum').'</td>';
		print '<td class="right nowraponall" id="auvorechnungsimport-sum-net">'.price($sumReviewNet).'</td>';
		print '<td class="error small" id="auvorechnungsimport-check-net"></td>';
		print '</tr>';
		print '<tr class="liste_total">';
		print '<td colspan="6" class="right">'.$langs->trans('AuvoRechnungsimportReviewGrossSum').'</td>';
		print '<td class="right nowraponall" id="auvorechnungsimport-sum-gross">'.price($sumReviewGross).'</td>';
		print '<td class="error small" id="auvorechnungsimport-check-gross"></td>';
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
	var warn = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportSkipWarning', '__AMOUNT__')).';
	var invoiceNet = '.json_encode(isset($extracted['amount_ht']) ? (float) $extracted['amount_ht'] : null).';
	var invoiceGross = '.json_encode(isset($extracted['amount_ttc']) ? (float) $extracted['amount_ttc'] : null).';
	var mismatchNet = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportErrReviewTotalMismatchHT', '__SUM__', '__AMOUNT__')).';
	var mismatchGross = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportErrReviewTotalMismatchTTC', '__SUM__', '__AMOUNT__')).';
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
		var net = 0, gross = 0, booked = 0, skippedGross = 0, skippedNet = 0, count = 0, missingVat = false;
		document.querySelectorAll("tr.auvorechnungsimport-line").forEach(function (tr) {
			var mode = tr.querySelector("select.auvorechnungsimport-mode").value;
			var qty = num(tr.querySelector("input[name^=\"line_qty_\"]").value);
			var unit = num(tr.querySelector("input[name^=\"line_price_\"]").value);
			var vatInput = tr.querySelector("input[name^=\"line_vat_\"]");
			if (vatInput.value.trim() === "") missingVat = true;
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
		document.getElementById("auvorechnungsimport-check-net").textContent = netMismatch ? mismatchNet.replace("__SUM__", fmt(net)).replace("__AMOUNT__", fmt(targetNet)) : "";
		document.getElementById("auvorechnungsimport-check-gross").textContent = grossMismatch ? mismatchGross.replace("__SUM__", fmt(gross)).replace("__AMOUNT__", fmt(targetGross)) : "";
		document.getElementById("auvorechnungsimport-tobook").textContent = fmt(booked);
		document.getElementById("auvorechnungsimport-skipwarn").textContent = Math.abs(skippedGross) > 0.005 ? warn.replace("__AMOUNT__", fmt(skippedGross)) : "";
		document.getElementById("auvorechnungsimport-create-invoice").disabled = !count || missingVat || netMismatch || grossMismatch;
	}
	document.querySelectorAll(".auvorechnungsimport-net-preview-toggle").forEach(function (button) {
		button.addEventListener("click", function () {
			var row = button.closest("tr.auvorechnungsimport-line");
			var priceInput = row.querySelector("input[name^=\"line_price_\"]");
			var vatInput = row.querySelector("input[name^=\"line_vat_\"]");
			var vat = num(vatInput.value);
			if (vatInput.value.trim() === "" || vat < 0 || vat > 100) { vatInput.focus(); return; }
			if (button.dataset.grossValue === undefined) button.dataset.grossValue = priceInput.value;
			var grossPrice = num(button.dataset.grossValue);
			priceInput.value = fmt(grossPrice / (1 + vat / 100));
			row.dataset.priceBase = "HT";
			row.querySelector("input[name^=\"line_price_base_\"]").value = "HT";
			var bases = Array.from(document.querySelectorAll("tr.auvorechnungsimport-line")).map(function (tr) { return tr.dataset.priceBase; });
			var mixed = bases.some(function (base) { return base !== bases[0]; });
			var priceHeader = document.getElementById("auvorechnungsimport-price-header");
			var totalHeader = document.getElementById("auvorechnungsimport-total-header");
			priceHeader.textContent = mixed ? priceHeader.dataset.mixed : priceHeader.dataset[row.dataset.priceBase.toLowerCase()];
			totalHeader.textContent = mixed ? totalHeader.dataset.mixed : totalHeader.dataset[row.dataset.priceBase.toLowerCase()];
			update();
		});
	});
	document.querySelectorAll("select.auvorechnungsimport-mode").forEach(function (s) { s.addEventListener("change", update); });
	document.querySelectorAll("input[name^=\"line_qty_\"], input[name^=\"line_price_\"], input[name^=\"line_vat_\"]").forEach(function (input) { input.addEventListener("input", update); });
	update();
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
	}

	print '<div class="center marginbottomonly">';
	print '<input type="submit" id="auvorechnungsimport-create-invoice" class="button" value="'.$langs->trans('AuvoRechnungsimportCreateInvoiceBtn').'">';
	print '</div>';
	if (!empty($lines)) {
		print '<div class="opacitymedium">'.$langs->trans('AuvoRechnungsimportLinesHint').'</div>';
	} else {
		print '<div class="warning">'.$langs->trans('AuvoRechnungsimportManualLineNeeded').'</div>';
		print '<table class="border centpercent marginbottomonly"><tr class="liste_titre"><td>'.$langs->trans('Description').'</td><td>'.$langs->trans('Qty').'</td><td>'.$langs->trans('PriceUHT').'</td><td>'.$langs->trans('VAT').' %</td></tr>';
		print '<tr><td><input type="text" name="manual_line_label" class="minwidth300" required value="'.dol_escape_htmltag(GETPOST('manual_line_label', 'restricthtml') ?: $langs->trans('AuvoRechnungsimportManualLineDefault')).'"></td>';
		print '<td><input type="number" name="manual_line_qty" min="0.001" step="0.001" required value="'.dol_escape_htmltag(GETPOST('manual_line_qty', 'alpha') ?: '1').'"></td>';
		print '<td><input type="number" name="manual_line_price" min="0" step="0.01" required value="'.dol_escape_htmltag(GETPOST('manual_line_price', 'alpha')).'"></td>';
		print '<td><input type="number" name="manual_line_vat" min="0" max="100" step="0.01" required value="'.dol_escape_htmltag(GETPOST('manual_line_vat', 'alpha')).'"></td></tr>';
		print '<tr class="liste_total"><td colspan="2" class="right">'.$langs->trans('AuvoRechnungsimportReviewNetSum').'</td><td id="auvorechnungsimport-manual-net"></td><td class="error small" id="auvorechnungsimport-manual-check-net"></td></tr>';
		print '<tr class="liste_total"><td colspan="2" class="right">'.$langs->trans('AuvoRechnungsimportReviewGrossSum').'</td><td id="auvorechnungsimport-manual-gross"></td><td class="error small" id="auvorechnungsimport-manual-check-gross"></td></tr>';
		print '</table>';
		$fmt = array('dec' => $langs->transnoentitiesnoconv('SeparatorDecimal'), 'th' => $langs->transnoentitiesnoconv('SeparatorThousand'));
		print '<script>
(function () {
	var dec = '.json_encode($fmt['dec'] === 'SeparatorDecimal' ? ',' : $fmt['dec']).', group = '.json_encode(in_array($fmt['th'], array('SeparatorThousand', 'None'), true) ? '' : $fmt['th']).';
	var targetNet = '.json_encode(isset($extracted['amount_ht']) ? (float) $extracted['amount_ht'] : null).';
	var targetGross = '.json_encode(isset($extracted['amount_ttc']) ? (float) $extracted['amount_ttc'] : null).';
	var mismatchNet = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportErrReviewTotalMismatchHT', '__SUM__', '__AMOUNT__')).';
	var mismatchGross = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportErrReviewTotalMismatchTTC', '__SUM__', '__AMOUNT__')).';
	function fmt(n) { return n.toFixed(2).replace(".", dec); }
	function num(v) {
		var s = String(v || "").replace(/\\s/g, "");
		if (dec !== "." && s.indexOf(dec) >= 0) { if (group) s = s.split(group).join(""); s = s.split(dec).join("."); }
		var n = parseFloat(s); return Number.isFinite(n) ? n : 0;
	}
	function update() {
		var qty = num(document.querySelector("input[name=manual_line_qty]").value), price = num(document.querySelector("input[name=manual_line_price]").value), vat = num(document.querySelector("input[name=manual_line_vat]").value);
		var net = Math.round(qty * price * 100) / 100, gross = Math.round(net * (1 + vat / 100) * 100) / 100, tolerance = 0.02;
		document.getElementById("auvorechnungsimport-manual-net").textContent = fmt(net);
		document.getElementById("auvorechnungsimport-manual-gross").textContent = fmt(gross);
		var netMismatch = targetNet !== null && Math.abs(net - targetNet) > tolerance;
		var grossMismatch = targetGross !== null && Math.abs(gross - targetGross) > tolerance;
		document.getElementById("auvorechnungsimport-manual-check-net").textContent = netMismatch ? mismatchNet.replace("__SUM__", fmt(net)).replace("__AMOUNT__", fmt(targetNet)) : "";
		document.getElementById("auvorechnungsimport-manual-check-gross").textContent = grossMismatch ? mismatchGross.replace("__SUM__", fmt(gross)).replace("__AMOUNT__", fmt(targetGross)) : "";
		document.getElementById("auvorechnungsimport-create-invoice").disabled = netMismatch || grossMismatch;
	}
	document.querySelectorAll("input[name^=\"manual_line_\"]").forEach(function (input) { input.addEventListener("input", update); });
	update();
})();
</script>';
	}
	print '</form>';
}
print '</div>';

llxFooter();
