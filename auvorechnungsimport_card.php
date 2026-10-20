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
$extracted = $object->getExtractedData();
$canUpdateBuyprice = $user->hasRight('produit', 'creer') || $user->hasRight('service', 'creer');
$canCreateOrder = isModEnabled('supplier_order') && $user->hasRight('fournisseur', 'commande', 'creer');
$canCreateBank = $user->hasRight('societe', 'creer');   // also covers creating a supplier
$canCreateProduct = isModEnabled('variants') && $user->hasRight('produit', 'creer');

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

if ($action == 'createinvoice' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$fkSoc = GETPOSTINT('fk_soc');
	if (empty($fkSoc) && GETPOSTINT('create_supplier') && $canCreateBank && !empty($extracted['supplier']['name']) && empty($extracted['is_credit_note'])) {
		// New supplier from the seller data of the e-invoice. Kept even if the
		// invoice below fails - a second try then finds it.
		$supplierErrors = array();
		$fkSoc = auvorechnungsimport_create_supplier($db, $user, $extracted['supplier'], $supplierErrors);
		if ($fkSoc > 0) {
			$object->fk_soc = $fkSoc;
			$object->supplier_match_confidence = 'created';
			$object->update($user);
			setEventMessages($langs->trans('AuvoRechnungsimportSupplierCreated', $extracted['supplier']['name']), null, 'mesgs');
		} else {
			$fkSoc = 0;
			setEventMessages($langs->trans('AuvoRechnungsimportErrSupplier'), $supplierErrors, 'errors');
		}
	}
	$refSupplier = GETPOST('ref_supplier', 'alphanohtml');
	$invoiceDate = GETPOST('invoice_date', 'alpha');

	$existingInvoice = auvorechnungsimport_find_existing_invoice($db, $fkSoc, $refSupplier);
	if (!empty($extracted['is_credit_note'])) {
		// A supplier credit note needs the original invoice in Dolibarr - it is
		// not created as an ordinary invoice with positive amounts.
		setEventMessages($langs->trans('AuvoRechnungsimportErrCreditNote'), null, 'errors');
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
		$invoice->type = FactureFournisseur::TYPE_STANDARD;
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
			$invoice->date_echeance = $invoice->calculate_date_lim_reglement();
		}

		$result = $invoice->create($user);
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

		if ($result > 0 && GETPOSTINT('create_bank') && $canCreateBank && !empty($extracted['iban'])) {
			if (auvorechnungsimport_create_supplier_bank($db, $user, $fkSoc, (string) $extracted['iban'], (string) ($extracted['bic'] ?? ''), (string) ($extracted['account_name'] ?? '')) < 0) {
				setEventMessages($langs->trans('AuvoRechnungsimportErrBank'), null, 'warnings');
			}
		}

		if ($result > 0) {
			// The lines are only a proposal - warn (but don't block) if what we booked
			// doesn't add up to the invoice total the PDF states. Dolibarr rounds VAT
			// per line while invoices usually round on the total, so allow a cent of
			// drift per few lines.
			$tolerance = max(0.02, 0.0025 * count($extractedLines));
			if (!empty($extracted['amount_ttc']) && $invoice->fetch($invoice->id) > 0
				&& abs((float) $invoice->total_ttc - (float) $extracted['amount_ttc']) > $tolerance && (float) $invoice->total_ttc != 0) {
				setEventMessages($langs->trans('AuvoRechnungsimportWarnTotalMismatch', price($invoice->total_ttc), price($extracted['amount_ttc'])), null, 'warnings');
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

if ($action == 'reprocess' && $user->hasRight('auvorechnungsimport', 'imports', 'write') && $object->status != ImportedInvoice::STATUS_IMPORTED) {
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

if ($action == 'confirm_delete' && GETPOST('confirm') == 'yes' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$filetoremove = DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath;
	if (is_file($filetoremove)) {
		dol_delete_file($filetoremove, 1);
	}
	dol_delete_dir(dirname($filetoremove), 1);
	$result = $object->delete($user);
	if ($result > 0) {
		header('Location: '.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1));
		exit;
	} else {
		setEventMessages($langs->trans('AuvoRechnungsimportErrDelete'), $object->errors, 'errors');
	}
}

llxHeader('', $object->ref);

if ($action == 'delete') {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('AuvoRechnungsimportDeleteTitle'), $langs->trans('AuvoRechnungsimportDeleteConfirm'), 'confirm_delete', '', '', 1);
}

// Back to the list this record belongs to (to review / imported / ...)
$backStatus = array(
	ImportedInvoice::STATUS_NEEDS_REVIEW => '1,3', ImportedInvoice::STATUS_MATCHED => '1,3',
	ImportedInvoice::STATUS_QUEUED => '4,0', ImportedInvoice::STATUS_PROCESSING => '4,0',
	ImportedInvoice::STATUS_ERROR => '9', ImportedInvoice::STATUS_IMPORTED => '2',
);
$backUrl = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_list.php', 1).(isset($backStatus[(int) $object->status]) ? '?search_status='.$backStatus[(int) $object->status] : '');
print '<a href="'.$backUrl.'">'.img_picto('', 'back', 'class="paddingrightonly"').$langs->trans('BackToList').'</a>';


$morehtmlright = '';
if ($user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if ($object->status != ImportedInvoice::STATUS_IMPORTED) {
		$morehtmlright .= dolGetButtonAction('', $langs->trans('AuvoRechnungsimportReprocessBtn'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=reprocess&token='.newToken(), '', 1);
	}
	$morehtmlright .= dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete', '', 1);
}
$nextId = auvorechnungsimport_next_to_review($db, $object->id);
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
} elseif ($user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	print load_fiche_titre($langs->trans('AuvoRechnungsimportReviewTitle'), '', '');
	if (!empty($extracted['is_credit_note'])) {
		print '<div class="warning">'.$langs->trans('AuvoRechnungsimportErrCreditNote').'</div>';
	}
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="createinvoice">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans('ThirdParty').'</td><td>';
	print $form->select_company($object->fk_soc, 'fk_soc', '(fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
	$newSupplier = $extracted['supplier'] ?? array();
	if (empty($object->fk_soc) && !empty($newSupplier['name']) && $canCreateBank && empty($extracted['is_credit_note'])) {
		// Seller data of an e-invoice: offer to create the supplier from it
		print '<br><label><input type="checkbox" name="create_supplier" value="1"'.(getDolGlobalInt('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER') ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportSupplierCreate').'</label>';
		print '<div class="opacitymedium small">'.dol_escape_htmltag($newSupplier['name']).', '.dol_escape_htmltag(trim($newSupplier['zip'].' '.$newSupplier['town'].' '.$newSupplier['country']));
		print ($newSupplier['vat'] !== '' ? ', '.dol_escape_htmltag($newSupplier['vat']) : '').'</div>';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('RefSupplier').'</td><td><input type="text" name="ref_supplier" class="minwidth200" value="'.dol_escape_htmltag($extracted['invoice_ref'] ?? '').'">';
	$existingInvoice = auvorechnungsimport_find_existing_invoice($db, (int) $object->fk_soc, (string) ($extracted['invoice_ref'] ?? ''));
	if ($existingInvoice) {
		print ' '.img_warning().' '.$langs->trans('AuvoRechnungsimportWarnDuplicateRef', $existingInvoice->getNomUrl(1));
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('Date').'</td><td><input type="date" name="invoice_date" value="'.dol_escape_htmltag($extracted['invoice_date'] ?? '').'"></td></tr>';
	print '<tr><td>'.$langs->trans('DateDue').'</td><td><input type="date" name="due_date" value="'.dol_escape_htmltag($extracted['due_date'] ?? '').'"></td></tr>';

	// Payment terms and type: from the e-invoice, else the supplier's defaults
	$supplierPay = new Societe($db);
	if ($object->fk_soc > 0) {
		$supplierPay->fetch($object->fk_soc);
	}
	$modeId = auvorechnungsimport_payment_type_id($db, auvorechnungsimport_payment_means_to_dolibarr($extracted['payment_means_code'] ?? ''));
	if ($modeId <= 0) {
		$modeId = (int) $supplierPay->mode_reglement_supplier_id;
	}
	print '<tr><td>'.$langs->trans('PaymentConditionsShort').'</td><td>';
	print $form->getSelectConditionsPaiements((int) $supplierPay->cond_reglement_supplier_id, 'cond_reglement_id', -1, 1);
	if (!empty($extracted['payment_terms'])) {
		print ' <span class="opacitymedium small">'.auvorechnungsimport_render_terms($extracted['payment_terms']).'</span>';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('PaymentMode').'</td><td>';
	$form->select_types_paiements($modeId, 'mode_reglement_id', 'DBIT', 0, 1);
	print '</td></tr>';
	if (!empty($extracted['iban'])) {
		print '<tr><td>'.$langs->trans('IBAN').'</td><td>'.dol_escape_htmltag($extracted['iban']).(!empty($extracted['bic']) ? ' / '.dol_escape_htmltag($extracted['bic']) : '');
		if ($object->fk_soc > 0 && auvorechnungsimport_find_supplier_bank($db, (int) $object->fk_soc, (string) $extracted['iban'])) {
			print ' '.img_picto('', 'tick').' '.$langs->trans('AuvoRechnungsimportBankKnown');
		} elseif ($canCreateBank) {
			print ' <label><input type="checkbox" name="create_bank" value="1" checked> '.$langs->trans('AuvoRechnungsimportBankCreate').'</label>';
		}
		print '</td></tr>';
	}
	$headerNote = auvorechnungsimport_header_note($extracted, auvorechnungsimport_header_labels($langs));
	if ($headerNote !== '') {
		print '<tr><td>'.$langs->trans('NotePrivate').'</td><td class="opacitymedium small">'.dol_nl2br(dol_escape_htmltag($headerNote)).'</td></tr>';
	}
	print '</table>';

	$lines = isset($extracted['lines']) && is_array($extracted['lines']) ? $extracted['lines'] : array();
	if (!empty($lines)) {
		// Matched at display time against the currently assigned supplier, so what
		// was learned since the upload (or a supplier corrected via re-run) applies.
		$lines = auvorechnungsimport_match_lines($db, (int) $object->fk_soc, $lines);
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
		$compareTotal = $gross ? ($extracted['amount_ttc'] ?? null) : ($extracted['amount_ht'] ?? null);
		$supplierForVat = null;
		if ($object->fk_soc > 0) {
			$supplierForVat = new Societe($db);
			if ($supplierForVat->fetch($object->fk_soc) <= 0) {
				$supplierForVat = null;
			}
		}

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
		print '<td class="right">'.$langs->trans($gross ? 'PriceUTTC' : 'PriceUHT').'</td>';
		print '<td class="right">'.$langs->trans('VAT').' %</td>';
		print '<td class="right">'.$langs->trans($gross ? 'TotalTTC' : 'TotalHT').'</td>';
		print '<td>'.$langs->trans('Product').'</td>';
		print '</tr>';

		// One product picker per line: with the full product list in every <select>
		// the page grows by the whole catalog per line (MBs for a long invoice).
		// Use Dolibarr's ajax product search for this table regardless of the
		// global setting - for this request only, nothing is saved.
		$savedSearchToSelect = getDolGlobalString('PRODUIT_USE_SEARCH_TO_SELECT');
		$conf->global->PRODUIT_USE_SEARCH_TO_SELECT = $savedSearchToSelect ?: '2';

		$sumLines = 0;
		foreach ($lines as $i => $line) {
			$total = $line['total'] ?? ((float) $line['qty'] * (float) $line['price']);
			$sumLines += (float) $total;
			$mode = $line['mode'] ?? 'book';
			print '<tr class="oddeven auvorechnungsimport-line'.($mode === 'skip' ? ' opacitymedium' : '').'" data-total="'.price2num($total).'">';
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
			print '<td class="right"><input type="text" name="line_price_'.$i.'" class="width75 right" value="'.dol_escape_htmltag($line['price'] !== null ? price($line['price'], 0, '', 0, -1, -1) : '').'"></td>';
			$vatRate = $line['vat_rate'];
			if ($vatRate === null && $supplierForVat) {
				// Invoice names no (or several) rates: fall back to Dolibarr's default
				// rate for this supplier/product, as on a manually added line.
				$vatRate = get_default_tva($supplierForVat, $mysoc, (int) $line['fk_product']);
			}
			print '<td class="right"><input type="text" name="line_vat_'.$i.'" class="width40 right" value="'.dol_escape_htmltag($vatRate !== null ? price2num($vatRate) : '').'"></td>';
			print '<td class="right nowraponall">'.price($total).'</td>';
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
		print '<td colspan="6" class="right">'.$langs->trans('Total').'</td>';
		print '<td class="right nowraponall">'.price($sumLines).'</td>';
		print '<td>';
		if ($compareTotal !== null && abs($sumLines - (float) $compareTotal) > 0.01) {
			print img_warning().' '.$langs->trans($gross ? 'AuvoRechnungsimportLinesSumMismatchGross' : 'AuvoRechnungsimportLinesSumMismatch', price($compareTotal));
		}
		print '</td>';
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
	var dec = '.json_encode($fmt['dec'] === 'SeparatorDecimal' ? ',' : $fmt['dec']).', th = '.json_encode(in_array($fmt['th'], array('SeparatorThousand', 'None'), true) ? '.' : $fmt['th']).';
	var warn = '.json_encode($langs->transnoentitiesnoconv('AuvoRechnungsimportSkipWarning', '__AMOUNT__')).';
	function fmt(n) {
		var p = Math.abs(n).toFixed(2).split(".");
		return (n < 0 ? "-" : "") + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, th) + dec + p[1];
	}
	function update() {
		var sum = 0, skipped = 0;
		document.querySelectorAll("tr.auvorechnungsimport-line").forEach(function (tr) {
			var mode = tr.querySelector("select.auvorechnungsimport-mode").value, t = parseFloat(tr.dataset.total) || 0;
			tr.classList.toggle("opacitymedium", mode === "skip");
			if (mode === "skip") { skipped += t; } else { sum += t; }
		});
		document.getElementById("auvorechnungsimport-tobook").textContent = fmt(sum);
		document.getElementById("auvorechnungsimport-skipwarn").textContent = Math.abs(skipped) > 0.005 ? warn.replace("__AMOUNT__", fmt(skipped)) : "";
	}
	document.querySelectorAll("select.auvorechnungsimport-mode").forEach(function (s) { s.addEventListener("change", update); });
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
	print '<input type="submit" class="button" value="'.$langs->trans('AuvoRechnungsimportCreateInvoiceBtn').'">';
	print '</div>';
	print '</form>';
	print '<div class="opacitymedium">'.$langs->trans(!empty($lines) ? 'AuvoRechnungsimportLinesHint' : 'AuvoRechnungsimportNoLinesHint').'</div>';
}
print '</div>';

llxFooter();
