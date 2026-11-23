<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Extraction pipeline for incoming supplier invoices:
 *   0. XML e-invoice (XRechnung / Peppol, CII or UBL) - read exactly
 *   1. PDF with embedded Factur-X/ZUGFeRD XML (exact, no OCR needed)
 *   2. Digital text layer of the PDF (smalot/pdfparser)
 *   3. Tesseract OCR on rendered pages (for scanned-only PDFs)
 * Whichever step first yields a usable set of fields wins; later steps are
 * skipped once we have a VAT number or invoice total.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
if (file_exists(__DIR__.'/../../vendor/autoload.php')) {
	require_once __DIR__.'/../../vendor/autoload.php';
}
require_once __DIR__.'/auvorechnungsimport_einvoice.php';
require_once __DIR__.'/auvorechnungsimport_view.php';

/** Find an earlier import with the same SHA-256, preferring a booked invoice. */
function auvorechnungsimport_find_duplicate_by_hash($db, $hash, $excludeId = 0, $md5 = '')
{
	$hash = strtolower(trim((string) $hash));
	if (!preg_match('/^[a-f0-9]{64}$/', $hash)) return null;
	// Backfill legacy PDF imports lazily so the first new arrival can match files
	// received before file hashes were introduced. Successfully hashed rows are
	// skipped on later calls.
	$sqlLegacy = "SELECT rowid, filepath FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice";
	$sqlLegacy .= " WHERE (file_hash IS NULL OR file_hash = '') AND LOWER(original_filename) LIKE '%.pdf'";
	$sqlLegacy .= " AND entity IN (".getEntity('importedinvoice').") ORDER BY rowid ASC";
	$resLegacy = $db->query($sqlLegacy);
	while ($resLegacy && ($legacy = $db->fetch_object($resLegacy))) {
		$legacyPath = auvorechnungsimport_resolve_file((string) $legacy->filepath);
		if ($legacyPath === false) continue;
		$legacyHash = hash_file('sha256', $legacyPath);
		if ($legacyHash === false) continue;
		$sqlUpdateHash = "UPDATE ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice SET file_hash = '".$db->escape($legacyHash)."'";
		$sqlUpdateHash .= " WHERE rowid = ".((int) $legacy->rowid)." AND (file_hash IS NULL OR file_hash = '')";
		$db->query($sqlUpdateHash);
	}
	$sql = "SELECT rowid, ref, status, fk_facture_fourn, extracted_data FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice";
	$sql .= " WHERE file_hash = '".$db->escape($hash)."' AND entity IN (".getEntity('importedinvoice').")";
	if ($excludeId > 0) $sql .= " AND rowid <> ".((int) $excludeId);
	$sql .= " ORDER BY CASE WHEN status = ".((int) ImportedInvoice::STATUS_IMPORTED)." AND fk_facture_fourn > 0 THEN 0 WHEN fk_facture_fourn > 0 THEN 1 ELSE 2 END, date_creation ASC, rowid ASC".$db->plimit(1);
	$resql = $db->query($sql);
	$match = null;
	if ($resql && ($row = $db->fetch_object($resql))) {
		$match = array('source' => 'import', 'id' => (int) $row->rowid, 'ref' => (string) $row->ref, 'fk_facture_fourn' => (int) $row->fk_facture_fourn);
		// Keep chains short: if this match was itself a hash duplicate, point to its original import.
		$metadata = json_decode((string) $row->extracted_data, true);
		$canonicalId = (int) ($metadata['duplicate_of_import_id'] ?? 0);
		if ($canonicalId > 0 && $canonicalId !== $match['id']) {
			$sqlCanonical = "SELECT rowid, ref, fk_facture_fourn FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice";
			$sqlCanonical .= " WHERE rowid = ".$canonicalId." AND entity IN (".getEntity('importedinvoice').")".$db->plimit(1);
			$resCanonical = $db->query($sqlCanonical);
			if ($resCanonical && ($canonical = $db->fetch_object($resCanonical))) {
				$match['id'] = (int) $canonical->rowid;
				$match['ref'] = (string) $canonical->ref;
				if ((int) $canonical->fk_facture_fourn > 0) $match['fk_facture_fourn'] = (int) $canonical->fk_facture_fourn;
			}
		}
	}

	// ECM's label is the MD5 of file contents. Only trust an indexed row if its
	// physical file still exists beneath the Dolibarr data root and verifies.
	$md5 = strtolower(trim((string) $md5));
	if ($md5 !== '' && preg_match('/^[a-f0-9]{32}$/', $md5) && isModEnabled('ecm')) {
		$sqlEcm = "SELECT rowid, filepath, filename, src_object_type, src_object_id FROM ".MAIN_DB_PREFIX."ecm_files";
		$sqlEcm .= " WHERE label = '".$db->escape($md5)."' AND entity IN (".getEntity('ecmfiles').") ORDER BY rowid ASC".$db->plimit(20);
		$resEcm = $db->query($sqlEcm);
		$root = realpath(DOL_DATA_ROOT);
		while ($resEcm && ($ecm = $db->fetch_object($resEcm))) {
			if ($root === false) break;
			$path = realpath($root.DIRECTORY_SEPARATOR.(string) $ecm->filepath.DIRECTORY_SEPARATOR.(string) $ecm->filename);
			$prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
			if ($path === false || strncmp($path, $prefix, strlen($prefix)) !== 0 || !is_file($path) || !is_readable($path)) continue;
			if (md5_file(dol_osencode($path)) !== $md5 || hash_file('sha256', dol_osencode($path)) !== $hash) continue;
			$ecmMatch = array('source' => 'ecm', 'id' => 0, 'ref' => '', 'ecm_id' => (int) $ecm->rowid, 'filename' => (string) $ecm->filename, 'fk_facture_fourn' => 0);
			if ($match) {
				$ecmMatch['import_id'] = (int) $match['id'];
				$ecmMatch['import_ref'] = (string) $match['ref'];
				if (!empty($match['fk_facture_fourn'])) $ecmMatch['fk_facture_fourn'] = (int) $match['fk_facture_fourn'];
			}
			if (!empty($ecm->src_object_id)) {
				$ecmMatch['object_type'] = (string) $ecm->src_object_type;
				$ecmMatch['object_id'] = (int) $ecm->src_object_id;
			}
			if (!empty($ecm->src_object_id) && in_array((string) $ecm->src_object_type, array('facture_fourn', 'supplier_invoice'), true)) {
				require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
				$invoice = new FactureFournisseur($db);
				if ($invoice->fetch((int) $ecm->src_object_id) > 0) $ecmMatch['fk_facture_fourn'] = (int) $invoice->id;
			}
			// An exact ECM file match is itself sufficient. Prefer it over a module
			// import match so the duplicate points at the already linked business doc.
			return $ecmMatch;
		}
	}
	return $match;
}

/** Build a Dolibarr card URL for a supported ECM source object. */
function auvorechnungsimport_ecm_business_object_url($type, $id)
{
	$id = (int) $id;
	if ($id <= 0) return '';
	$routes = array(
		'facture_fourn' => array('/fourn/facture/card.php', 'id'),
		'supplier_invoice' => array('/fourn/facture/card.php', 'id'),
		'invoice_supplier' => array('/fourn/facture/card.php', 'id'),
		'facture' => array('/compta/facture/card.php', 'facid'),
		'commande_fournisseur' => array('/fourn/commande/card.php', 'id'),
		'order_supplier' => array('/fourn/commande/card.php', 'id'),
		'commande' => array('/commande/card.php', 'id'),
		'order' => array('/commande/card.php', 'id'),
		'propal' => array('/comm/propal/card.php', 'id'),
		'proposal' => array('/comm/propal/card.php', 'id'),
		'societe' => array('/societe/card.php', 'socid'),
		'company' => array('/societe/card.php', 'socid'),
		'product' => array('/product/card.php', 'id'),
		'project' => array('/projet/card.php', 'id'),
		'expedition' => array('/expedition/card.php', 'id'),
	);
	$type = strtolower(trim((string) $type));
	if (!isset($routes[$type])) return '';
	return DOL_URL_ROOT.$routes[$type][0].'?'.$routes[$type][1].'='.$id;
}

/** Return a safe card link for a known duplicate target. */
function auvorechnungsimport_duplicate_target_link($match)
{
	global $langs;
	if (is_object($langs)) $langs->load('auvorechnungsimport');
	if (!empty($match['fk_facture_fourn'])) {
		return array(
			'url' => DOL_URL_ROOT.'/fourn/facture/card.php?id='.(int) $match['fk_facture_fourn'],
			'label' => is_object($langs) ? $langs->trans('AuvoRechnungsimportLinkedSupplierInvoice', (int) $match['fk_facture_fourn']) : 'Supplier invoice #'.(int) $match['fk_facture_fourn'],
		);
	}
	if (!empty($match['id'])) {
		return array(
			'url' => dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.(int) $match['id'],
			'label' => (string) ($match['ref'] ?? ''),
		);
	}
	if (!empty($match['object_type']) && !empty($match['object_id'])) {
		$url = auvorechnungsimport_ecm_business_object_url($match['object_type'], $match['object_id']);
		if ($url !== '') return array('url' => $url, 'label' => is_object($langs) ? $langs->trans('AuvoRechnungsimportEcmLinkedBusinessDoc') : 'Linked business document');
	}
	if (!empty($match['ecm_id'])) {
		return array(
			'url' => DOL_URL_ROOT.'/ecm/file_card.php?id='.(int) $match['ecm_id'],
			'label' => is_object($langs) ? $langs->trans('AuvoRechnungsimportLinkedEcmFile', (string) ($match['filename'] ?? '')) : (string) ($match['filename'] ?? 'ECM file'),
		);
	}
	return array('url' => '', 'label' => (string) ($match['ref'] ?? $match['import_ref'] ?? ''));
}

/** Build metadata and status fields for a newly received hash duplicate. */
function auvorechnungsimport_mark_hash_duplicate($object, $match)
{
	if (empty($match['id']) && empty($match['ecm_id'])) return;
	$data = $object->getExtractedData();
	if (!empty($match['id'])) {
		$data['duplicate_of_import_id'] = (int) $match['id'];
		$data['duplicate_of_import_ref'] = (string) $match['ref'];
	}
	if (!empty($match['ecm_id'])) {
		$data['duplicate_of_ecm_id'] = (int) $match['ecm_id'];
		$data['duplicate_of_ecm_filename'] = (string) $match['filename'];
		if (!empty($match['object_type']) && !empty($match['object_id'])) {
			$data['duplicate_of_ecm_object_type'] = (string) $match['object_type'];
			$data['duplicate_of_ecm_object_id'] = (int) $match['object_id'];
		}
	}
	$data['duplicate_reason'] = !empty($match['ecm_id']) ? 'ecm_md5' : 'sha256';
	$object->extracted_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$object->status = ImportedInvoice::STATUS_DUPLICATE;
	if (!empty($match['fk_facture_fourn'])) $object->fk_facture_fourn = (int) $match['fk_facture_fourn'];
}

/** Shared setup, create call and failure handling for module BlockedLog events. */
function auvorechnungsimport_blockedlog_write($db, $user, $action, $element, $objectId, $entity, $ref, $linkType, $linkRef, $objectData, $options = array())
{
	global $conf, $langs;
	if (!isModEnabled('blockedlog')) return -1;
	require_once DOL_DOCUMENT_ROOT.'/blockedlog/class/blockedlog.class.php';

	$block = new BlockedLog($db);
	$block->action = (string) $action;
	$block->module_source = 'auvorechnungsimport';
	$block->amounts = (float) ($options['amounts'] ?? 0);
	$block->amounts_taxexcl = (float) ($options['amounts_taxexcl'] ?? 0);
	$block->element = (string) $element;
	$block->fk_object = (int) $objectId;
	$block->entity = (int) ($entity ?: $conf->entity);
	$block->ref_object = (string) $ref;
	$block->linktype = (string) $linkType;
	$block->linktoref = (string) $linkRef;
	$block->date_object = $options['date_object'] ?? dol_now();
	$block->fk_user = (int) ($user->id ?? 0);
	$block->user_fullname = !empty($options['user_fullname']) ? (string) $options['user_fullname'] : (!empty($user->id) ? $user->getFullName($langs) : '(Anonymous)');
	$block->object_data = is_object($objectData) ? $objectData : (object) $objectData;
	$result = $block->create($user);
	if ($result <= 0) {
		dol_syslog('AuvoRechnungsimport: BlockedLog event '.$action.' failed: '.$block->error, LOG_ERR);
		return -1;
	}
	return $result;
}

/** Get the first immutable receipt record and its content hash for an import. */
function auvorechnungsimport_blockedlog_get_received($db, $importId)
{
	if (!isModEnabled('blockedlog') || (int) $importId <= 0) return array('id' => 0, 'sha256' => '');
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."blockedlog WHERE action = 'AUVORECHNUNGSIMPORT_RECEIVED' AND element = 'importedinvoice' AND fk_object = ".((int) $importId)." ORDER BY rowid ASC".$db->plimit(1);
	$resql = $db->query($sql);
	$info = array('id' => 0, 'sha256' => '');
	if ($resql && ($row = $db->fetch_object($resql))) {
		require_once DOL_DOCUMENT_ROOT.'/blockedlog/class/blockedlog.class.php';
		$block = new BlockedLog($db);
		if ($block->fetch((int) $row->rowid) > 0) {
			$info['id'] = (int) $row->rowid;
			$info['sha256'] = strtolower((string) ($block->object_data->sha256 ?? ''));
		}
	}
	if ($resql) $db->free($resql);
	return $info;
}

/** Core BlockedLog detail popup renders this field as HTML; all dynamic URL parts are escaped. */
function auvorechnungsimport_blockedlog_preview_markup($importId, $mime)
{
	global $langs;
	$url = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_file.php', 1).'?id='.(int) $importId.'&blockedlogpreview=1';
	$mime = in_array((string) $mime, array('application/pdf', 'application/xml', 'text/xml'), true) ? (string) $mime : 'application/octet-stream';
	if (is_object($langs)) {
		$langs->load('auvorechnungsimport');
		$title = $langs->trans($mime === 'application/pdf' ? 'AuvoRechnungsimportPreviewLoggedOriginal' : 'AuvoRechnungsimportDownloadLoggedOriginal');
	} else {
		$title = 'Preview logged original';
	}
	if ($mime === 'application/pdf') {
		return '<a class="pictopreview documentpreview" href="'.dol_escape_htmltag($url).'" mime="application/pdf" target="_blank" rel="noopener noreferrer" title="'.dol_escape_htmltag($title).'"><span class="fas fa-search-plus pictofixedwidth" style="color:#808080"></span></a>';
	}
	return '<a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener noreferrer" title="'.dol_escape_htmltag($title).'"><span class="fas fa-download pictofixedwidth" style="color:#808080"></span></a>';
}

/** Log an automatically detected duplicate without writing another filesystem copy. */
function auvorechnungsimport_blockedlog_log_auto_duplicate($db, $object, $content, $mime, $user, $source, $match)
{
	global $langs;
	if (!getDolGlobalInt('AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE')) return 1;
	$content = (string) $content;
	if ($content === '' || strlen($content) > 10000000) return -2;
	$data = array(
		'ref' => (string) $object->ref,
		'filename' => (string) $object->original_filename,
		'source' => (string) $source,
		'mime_type' => (string) $mime,
		'size' => strlen($content),
		'sha256' => hash('sha256', $content),
		'md5' => hash('md5', $content),
		'duplicate_reason' => (string) ($match['source'] === 'ecm' ? 'ecm_md5' : 'sha256'),
		'duplicate_of_import_id' => !empty($match['id']) ? (int) $match['id'] : (int) ($match['import_id'] ?? 0),
		'duplicate_of_import_ref' => !empty($match['ref']) ? (string) $match['ref'] : (string) ($match['import_ref'] ?? ''),
		'duplicate_of_ecm_id' => (int) ($match['ecm_id'] ?? 0),
		'duplicate_of_ecm_filename' => (string) ($match['filename'] ?? ''),
		'duplicate_of_ecm_object_type' => (string) ($match['object_type'] ?? ''),
		'duplicate_of_ecm_object_id' => (int) ($match['object_id'] ?? 0),
		'content_base64' => base64_encode($content),
		'link' => auvorechnungsimport_blockedlog_preview_markup($object->id, $mime),
	);
	return auvorechnungsimport_blockedlog_write(
		$db, $user, 'AUVORECHNUNGSIMPORT_DUPLICATE_AUTO', 'importedinvoice', $object->id, $object->entity,
		$object->ref,
		!empty($match['fk_facture_fourn']) ? 'invoice_supplier' : (!empty($match['id']) ? 'importedinvoice' : (string) ($match['object_type'] ?? 'ecm')),
		!empty($match['ref']) ? (string) $match['ref'] : (!empty($match['import_ref']) ? (string) $match['import_ref'] : (string) ($match['filename'] ?? '')),
		$data,
		array('date_object' => $object->date_creation ?: dol_now(), 'user_fullname' => !empty($user->id) ? $user->getFullName($langs) : '(Email Collector)')
	);
}

/** Store the first received copy in the signed BlockedLog as Base64. */
function auvorechnungsimport_blockedlog_store_received_file($db, $object, $path, $user, $source)
{
	global $langs;
	if (!getDolGlobalInt('AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE')) return 1;
	if (!is_readable($path)) return -1;
	$existing = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."blockedlog WHERE action = 'AUVORECHNUNGSIMPORT_RECEIVED' AND element = '".$db->escape((string) $object->element)."' AND fk_object = ".((int) $object->id).$db->plimit(1));
	if ($existing && $db->num_rows($existing) > 0) return 1;
	$size = filesize($path);
	// BlockedLog.object_data is MEDIUMTEXT (16 MiB). Keep headroom for JSON metadata.
	if ($size === false || $size > 10000000) {
		return -2;
	}
	$content = file_get_contents($path);
	if ($content === false) return -1;
	$extension = strtolower(pathinfo((string) $object->original_filename, PATHINFO_EXTENSION));
	$mime = $extension === 'pdf' ? 'application/pdf' : ($extension === 'xml' ? 'application/xml' : (function_exists('mime_content_type') ? (string) mime_content_type($path) : 'application/octet-stream'));
	$data = array(
		'ref' => (string) $object->ref,
		'filename' => (string) $object->original_filename,
		'source' => (string) $source,
		'mime_type' => $mime,
		'size' => (int) $size,
		'sha256' => hash('sha256', $content),
		'content_base64' => base64_encode($content),
		'link' => auvorechnungsimport_blockedlog_preview_markup($object->id, $mime),
	);
	return auvorechnungsimport_blockedlog_write(
		$db, $user, 'AUVORECHNUNGSIMPORT_RECEIVED', $object->element, $object->id, $object->entity,
		$object->ref, 'importedinvoice', $object->ref, $data,
		array('date_object' => $object->date_creation ?: dol_now(), 'user_fullname' => !empty($user->id) ? $user->getFullName($langs) : '(Email Collector)')
	);
}

/** Compare a supplier invoice attachment with the hash in the first receipt log. */
function auvorechnungsimport_verify_invoice_attachment($db, $import, $invoice)
{
	$result = array('status' => 'unavailable', 'attachment_exists' => false, 'log_hash' => '', 'attachment_hash' => '');
	if (empty($import->id) || empty($invoice->id)) return $result;
	$path = auvorechnungsimport_invoice_docdir($invoice).'/'.dol_sanitizeFileName($import->original_filename);
	if (is_readable($path)) {
		$result['attachment_exists'] = true;
		$hash = hash_file('sha256', $path);
		if ($hash !== false) $result['attachment_hash'] = strtolower($hash);
	}
	if (!isModEnabled('blockedlog')) return $result;
	$receipt = auvorechnungsimport_blockedlog_get_received($db, $import->id);
	if ($receipt['sha256'] === '') return $result;
	$result['log_hash'] = $receipt['sha256'];
	if (!$result['attachment_exists']) {
		$result['status'] = 'missing';
		return $result;
	}
	if ($result['attachment_hash'] === '') return $result;
	$result['status'] = hash_equals($result['log_hash'], strtolower($result['attachment_hash'])) ? 'match' : 'mismatch';
	return $result;
}

/** Record the validated supplier invoice in BlockedLog after review approval. */
function auvorechnungsimport_blockedlog_log_validated_invoice($db, $invoice, $user, $import)
{
	if (!getDolGlobalInt('AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE')) return 1;
	$invoice->fetch_thirdparty();
	$data = array(
		'ref' => (string) $invoice->ref,
		'ref_supplier' => (string) $invoice->ref_supplier,
		'socid' => (int) $invoice->socid,
		'date' => (int) $invoice->date,
		'total_ht' => (float) $invoice->total_ht,
		'total_tva' => (float) $invoice->total_tva,
		'total_ttc' => (float) $invoice->total_ttc,
		'import_ref' => (string) $import->ref,
		'original_filename' => (string) $import->original_filename,
		'thirdparty' => (object) array(
			'name' => (string) ($invoice->thirdparty->name ?? ''),
			'tva_intra' => (string) ($invoice->thirdparty->tva_intra ?? ''),
			'address' => (string) ($invoice->thirdparty->address ?? ''),
			'zip' => (string) ($invoice->thirdparty->zip ?? ''),
			'town' => (string) ($invoice->thirdparty->town ?? ''),
			'country_code' => (string) ($invoice->thirdparty->country_code ?? ''),
		),
	);
	$lines = array();
	foreach ((array) ($invoice->lines ?? array()) as $line) {
		$lines[] = array(
			'ref' => (string) ($line->ref ?? ''),
			'label' => (string) ($line->product_label ?? $line->desc ?? ''),
			'qty' => (float) ($line->qty ?? 0),
			'unit_price_ht' => (float) ($line->subprice ?? 0),
			'vat_rate' => (float) ($line->tva_tx ?? 0),
			'total_ht' => (float) ($line->total_ht ?? 0),
			'total_tva' => (float) ($line->total_tva ?? 0),
			'total_ttc' => (float) ($line->total_ttc ?? 0),
		);
	}
	$data['invoiceline'] = $lines;
	return auvorechnungsimport_blockedlog_write(
		$db, $user, 'BILL_SUPPLIER_VALIDATE', $invoice->element, $invoice->id, $invoice->entity,
		$invoice->ref, 'importedinvoice', $import->ref, $data,
		array('amounts' => $invoice->total_ttc, 'amounts_taxexcl' => $invoice->total_ht, 'date_object' => $invoice->date)
	);
}

/** Record that a received import was explicitly confirmed as a duplicate. */
function auvorechnungsimport_blockedlog_log_duplicate_confirmation($db, $import, $duplicateInvoice, $user, $supplierRef)
{
	global $langs;
	$data = $import->getExtractedData();
	$receipt = auvorechnungsimport_blockedlog_get_received($db, $import->id);
	$objectData = array(
		'import_ref' => (string) $import->ref,
		'original_filename' => (string) $import->original_filename,
		'received_sha256' => $receipt['sha256'],
		'supplier_id' => (int) $duplicateInvoice->socid,
		'supplier_invoice_id' => (int) $duplicateInvoice->id,
		'supplier_invoice_ref' => (string) $duplicateInvoice->ref,
		'supplier_invoice_ref_supplier' => (string) $supplierRef,
		'confirmed_by' => $user->getFullName($langs),
		'confirmed_at' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S', 'gmt'),
	);
	return auvorechnungsimport_blockedlog_write(
		$db, $user, 'AUVORECHNUNGSIMPORT_DUPLICATE_CONFIRMED', 'importedinvoice', $import->id, $import->entity,
		$import->ref, 'invoice_supplier', $duplicateInvoice->ref, $objectData,
		array('amounts' => $data['amount_ttc'] ?? 0)
	);
}

/** Record that a user explicitly confirmed a flagged email attachment as spam. */
function auvorechnungsimport_blockedlog_log_spam_confirmation($db, $import, $user)
{
	global $langs;
	$data = $import->getExtractedData();
	$spamDetection = is_array($data['spam_detection'] ?? null) ? $data['spam_detection'] : array();
	$receipt = auvorechnungsimport_blockedlog_get_received($db, $import->id);
	$objectData = array(
		'import_ref' => (string) $import->ref,
		'original_filename' => (string) $import->original_filename,
		'source' => (string) $import->source,
		'received_sha256' => $receipt['sha256'],
		'matched_header' => (string) ($spamDetection['header'] ?? ''),
		'matched_value' => (string) ($spamDetection['value'] ?? ''),
		'confirmed_by' => !empty($user->id) ? $user->getFullName($langs) : '(Anonymous)',
		'confirmed_at' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S', 'gmt'),
	);
	return auvorechnungsimport_blockedlog_write(
		$db, $user, 'AUVORECHNUNGSIMPORT_SPAM_CONFIRMED', 'importedinvoice', $import->id, $import->entity,
		$import->ref, 'importedinvoice', $import->ref, $objectData
	);
}

/** Record an import deletion inside the transaction that removes the row. */
function auvorechnungsimport_blockedlog_log_import_deleted($db, $import, $user, $filePath = false)
{
	global $langs;
	$receipt = auvorechnungsimport_blockedlog_get_received($db, $import->id);

	$fileHash = strtolower(trim((string) $import->file_hash));
	if ($filePath !== false && is_readable($filePath)) {
		$diskHash = hash_file('sha256', $filePath);
		if ($diskHash !== false) $fileHash = strtolower($diskHash);
	}
	$data = $import->getExtractedData();
	$objectData = array(
		'import_id' => (int) $import->id,
		'import_ref' => (string) $import->ref,
		'original_filename' => (string) $import->original_filename,
		'source' => (string) $import->source,
		'status_before_delete' => (int) $import->status,
		'extraction_method' => (string) $import->extraction_method,
		'invoice_ref' => (string) ($data['invoice_ref'] ?? ''),
		'linked_supplier_invoice_id' => (int) $import->fk_facture_fourn,
		'file_sha256' => $fileHash,
		'received_log_sha256' => $receipt['sha256'],
		'received_log_id' => $receipt['id'],
		'deleted_by' => !empty($user->id) ? $user->getFullName($langs) : '(Anonymous)',
		'deleted_at' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S', 'gmt'),
	);
	return auvorechnungsimport_blockedlog_write(
		$db, $user, 'AUVORECHNUNGSIMPORT_DELETED', 'importedinvoice', $import->id, $import->entity,
		$import->ref, 'importedinvoice_deleted', $import->ref, $objectData,
		array('amounts' => $data['amount_ttc'] ?? 0, 'amounts_taxexcl' => $data['amount_ht'] ?? 0)
	);
}

/**
 * Resolve an import's stored relative filepath inside an explicitly trusted
 * storage root. Mail collectors can place attachments in a separate root,
 * configured by an administrator; database values never choose a root.
 *
 * @param string $filepath Stored relative filepath
 * @return string|false Canonical readable file path
 */
function auvorechnungsimport_resolve_file($filepath)
{
	if (!is_string($filepath) || $filepath === '' || strpos($filepath, "\0") !== false) {
		return false;
	}
	$roots = array(DOL_DATA_ROOT.'/auvorechnungsimport');
	$file = false;
	foreach ($roots as $root) {
		$base = realpath($root);
		if ($base === false || !is_dir($base)) {
			continue;
		}
		$candidate = realpath($base.DIRECTORY_SEPARATOR.$filepath);
		$prefix = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
		if ($candidate !== false && strncmp($candidate, $prefix, strlen($prefix)) === 0 && is_file($candidate) && is_readable($candidate)) {
			$file = $candidate;
			break;
		}
	}
	return $file;
}

/**
 * Embedded Factur-X/ZUGFeRD XML of a PDF, or null if there is none.
 *
 * @param string $pdfpath Absolute path to the PDF file
 * @return ?string
 */
function auvorechnungsimport_pdf_embedded_xml($pdfpath)
{
	if (!class_exists('\Atgp\FacturX\Reader')) {
		return null;
	}
	$binary = @file_get_contents($pdfpath);
	if ($binary === false) {
		return null;
	}
	try {
		$reader = new \Atgp\FacturX\Reader();
		$xml = $reader->extractXML($binary, false);
	} catch (\Throwable $e) {
		return null;
	}
	return empty($xml) ? null : $xml;
}

/**
 * Structured e-invoice behind an import file: the XML itself, or the
 * Factur-X/ZUGFeRD XML embedded in a PDF. Null for ordinary PDFs.
 *
 * @param string $path Absolute path of the uploaded file
 * @return ?array Parsed e-invoice (auvorechnungsimport_parse_einvoice)
 */
function auvorechnungsimport_load_einvoice($path)
{
	if (auvorechnungsimport_is_xml_file($path)) {
		return auvorechnungsimport_parse_einvoice((string) @file_get_contents($path));
	}
	$xml = auvorechnungsimport_pdf_embedded_xml($path);
	return $xml !== null ? auvorechnungsimport_parse_einvoice($xml) : null;
}

/**
 * True for an uploaded XML file (by extension, or by content for files
 * without a usable extension).
 *
 * @param string $path
 * @return bool
 */
function auvorechnungsimport_is_xml_file($path)
{
	if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xml') {
		return true;
	}
	$head = (string) @file_get_contents($path, false, null, 0, 64);
	return (bool) preg_match('/^(\xEF\xBB\xBF)?\s*<(\?xml|[A-Za-z])/', $head) && strpos($head, '%PDF') === false;
}

/**
 * Try to read an embedded Factur-X/ZUGFeRD CII XML from the PDF.
 *
 * @param string $pdfpath Absolute path to the PDF file
 * @return ?array<string,mixed> Extracted fields, or null if no embedded XML found
 */
function auvorechnungsimport_extract_facturx($pdfpath)
{
	$xml = auvorechnungsimport_pdf_embedded_xml($pdfpath);
	$inv = $xml !== null ? auvorechnungsimport_parse_einvoice($xml) : null;
	if ($inv === null) {
		return null;
	}
	$fields = auvorechnungsimport_einvoice_to_fields($inv);
	if (empty($fields['supplier_vat']) && $fields['amount_ttc'] === null && empty($fields['invoice_ref'])) {
		return null;
	}
	return $fields;
}

/**
 * Extract the digital text layer of a PDF (no OCR, works only if the PDF was
 * not purely a scanned image).
 *
 * @param string $pdfpath
 * @return string
 */
function auvorechnungsimport_extract_pdftext($pdfpath)
{
	if (!class_exists('\Smalot\PdfParser\Parser')) {
		return '';
	}
	try {
		$parser = new \Smalot\PdfParser\Parser();
		$pdf = $parser->parseFile($pdfpath);
		$text = $pdf->getText();
	} catch (\Throwable $e) {
		return '';
	}
	return trim((string) $text);
}

/**
 * OCR fallback for scanned PDFs: render the first pages to PNG via pdftoppm,
 * then run tesseract on each page. Invoice header data (supplier, amounts,
 * dates) is virtually always on page 1, but the invoice lines can span all
 * pages - hence the configurable page limit.
 *
 * Besides the plain text, a layout text is rebuilt from tesseract's word
 * coordinates (TSV output of the same run): words of one visual row on one
 * line, at a column proportional to their x position - like pdftotext
 * -layout. The line detection needs that (rows torn apart by tesseract's block
 * segmentation, indentation of continuation lines).
 *
 * @param string $pdfpath
 * @param string $langs Tesseract language codes, e.g. "deu+fra+eng"
 * @param int $maxPages
 * @param ?string $layoutText Receives the layout text
 * @return string
 */
function auvorechnungsimport_extract_ocr($pdfpath, $langs = 'deu+fra+eng', $maxPages = 2, &$layoutText = null)
{
	$layoutText = '';
	if (!function_exists('shell_exec') || !function_exists('proc_open')) {
		return '';
	}
	$tmpdir = sys_get_temp_dir().'/auvorechnungsimport_'.bin2hex(random_bytes(8));
	if (!@mkdir($tmpdir, 0700, true)) {
		return '';
	}

	$text = '';
	try {
		$prefix = $tmpdir.'/page';
		$cmd = 'pdftoppm -png -r 300 -f 1 -l '.max(1, (int) $maxPages).' '.escapeshellarg($pdfpath).' '.escapeshellarg($prefix).' 2>&1';
		exec($cmd, $output, $retval);
		if ($retval !== 0) {
			return '';
		}

		$pages = glob($prefix.'*.png');
		sort($pages);
		foreach ($pages as $page) {
			$outbase = $page.'.ocrout';
			$safeLangs = preg_replace('/[^a-z+]/i', '', $langs);
			$cmd = 'tesseract '.escapeshellarg($page).' '.escapeshellarg($outbase).' -l '.escapeshellarg($safeLangs ?: 'eng').' txt tsv 2>&1';
			exec($cmd, $output2, $retval2);
			$txtfile = $outbase.'.txt';
			if ($retval2 === 0 && is_readable($txtfile)) {
				$text .= file_get_contents($txtfile)."\n";
			}
			$tsvfile = $outbase.'.tsv';
			if ($retval2 === 0 && is_readable($tsvfile)) {
				$layoutText .= auvorechnungsimport_tsv_to_layout(file_get_contents($tsvfile))."\n";
			}
		}
	} finally {
		foreach (glob($tmpdir.'/*') ?: array() as $f) {
			@unlink($f);
		}
		@rmdir($tmpdir);
	}

	return trim($text);
}

/**
 * Heuristic field extraction from plain text (digital layer or OCR output).
 *
 * @param string $text
 * @return array<string,mixed>
 */
function auvorechnungsimport_parse_fields($text)
{
	$result = array(
		'supplier_name' => '',
		'supplier_name_confident' => false,
		'supplier_address' => array(),
		'supplier_vat' => '',
		'supplier_tax_id' => '',
		'invoice_ref' => '',
		'invoice_date' => null,
		'amount_ttc' => null,
		'amount_ht' => null,
		'currency' => '',
		'supplier_email' => '',
		'supplier_phone' => '',
		'supplier_iban' => '',
		'supplier_country' => '',
		'supplier_address' => array(),
		'supplier_contact_firstname' => '',
		'supplier_contact_lastname' => '',
	);
	if (empty($text)) {
		return $result;
	}

	// VAT / tax ID: only trust a number that sits right next to a recognizable label -
	// a blind scan for "2 letters + digits" shaped tokens also matches product codes
	// (e.g. "XP 05") and other incidental text on the invoice.
	//
	// Two separate passes, EU VAT-style labels tried first: German invoices commonly
	// print both a national "Steuernummer" (not usable as a VATIN) and the actual
	// "USt-IdNr./Id-Nr." next to each other on the same line (e.g. "Steuer-Nr.:
	// 316/5750/1282 - Id.-Nr.: DE 293 640 208") - a single combined regex would match
	// whichever label happens to come first in the text, which is often the wrong one.
	$vatLabels = 'USt[.\s-]*Ident(?:ifikations)?[.\s-]*Nr\.?|USt-?IdNr\.?|UStIdNr\.?|Umsatzsteuer-?Identifikationsnummer|Umsatzsteuer-?ID(?:Nr\.?)?|Id[.\s]*-?Nr\.?|UID|VAT[ -]?(?:ID|No\.?|Reg(?:istration)?\.?\s*No\.?)?|TVA(?:\s*intracommunautaire)?|N°?\s*TVA';
	$taxRefLabels = 'Steuernummer|Steuer-?Nr\.?|SIRET|SIREN';
	foreach (array($vatLabels, $taxRefLabels) as $i => $labels) {
		if (preg_match('/(?:'.$labels.')[ \t]*[:.]?[ \t]*([A-Z]{0,2}[ \t]?[A-Z0-9][A-Z0-9 \t\/.-]{3,18})/iu', $text, $m)) {
			$candidate = auvorechnungsimport_normalize_vat(trim($m[1]));
			if (preg_match('/\d{5,}/', $candidate)) {
				$result[$i === 0 ? 'supplier_vat' : 'supplier_tax_id'] = $candidate;
			}
		}
	}

	// Invoice number: look for a label followed by a token.
	if (preg_match('/(?:Rechnung(?:s)?[ \t]*-?Nr\.?|Rechnungsnummer|Beleg(?:s)?[ \t]*-?Nr\.?|Belegnummer|Invoice\s*No\.?|Facture\s*n[°o]\.?|N[°o]\s*de\s*facture)[ \t]*[:.]?[ \t]*([A-Za-z0-9.\-\/]{3,30})/iu', $text, $m)) {
		$result['invoice_ref'] = trim($m[1]);
	}

	// Prefer a date explicitly paired with an invoice-date label. The first date
	// in item-heavy invoices may instead be a delivery date from the line table.
	$dateSource = $text;
	if (preg_match('/\b(?:Rechnungsdatum|Belegdatum|Datum|Invoice\s*Date|Date)\b[ \t:.-]{0,100}(\d{1,2}[.\/]\d{1,2}[.\/]\d{4}|\d{4}-\d{2}-\d{2})/iu', $text, $dateMatch)) {
		$dateSource = $dateMatch[1];
	} elseif (preg_match('/(\d{1,2}[.\/]\d{1,2}[.\/]\d{4}|\d{4}-\d{2}-\d{2})[ \t]*(?:Rechnungsdatum|Belegdatum|Datum)\b/iu', $text, $dateMatch)) {
		// Some PDF text extractors reverse the label and value in multi-column headers.
		$dateSource = $dateMatch[1];
	}
	// Date: dd.mm.yyyy, dd/mm/yyyy, or yyyy-mm-dd - fallback to first plausible one.
	if (preg_match('/\b(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})\b/', $dateSource, $m)) {
		$result['invoice_date'] = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
	} elseif (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $dateSource, $m)) {
		$result['invoice_date'] = $m[0];
	}

	// Accept cent amounts (625,00) and the German no-cents notation (625,- / 625,–).
	$amountToken = '[0-9]{1,3}(?:[.\s][0-9]{3})*(?:[,.][0-9]{2}|[,.]\s*[-–—]{1,2})';
	// Total amount: label followed by a number, optionally with currency symbol.
	if (preg_match('/(?:Gesamtbetrag|Gesamtpreis|Gesamtsumme|Rechnungsbetrag|Rechnungswert|Rechnungsendbetrag|Bruttorechnungswert|Endbetrag|Endsumme|Zahlbetrag|Zu\s*zahlen(?:der\s*Betrag)?|Betrag\s+von|Total\s*TTC|Total\s*à\s*payer|Total\s*due|Montant\s*total)[^\d\n]{0,40}('.$amountToken.')/iu', $text, $m)) {
		$result['amount_ttc'] = auvorechnungsimport_parse_amount($m[1]);
	}
	// Net total - only used to cross-check detected invoice lines.
	if (preg_match('/(?:Netto[- ]?(?:Gesamtwert|Gesamtbetrag|betrag|summe|wert|Warenwert)|Nettosumme|Summe\s*netto|Gesamt\s*netto|Warenwert(?:\s*netto)?|Total\s*HT|Montant\s*HT|Net\s*(?:amount|total)|Subtotal|Netto(?=\s*:))[^\d\n]{0,40}?('.$amountToken.')/iu', $text, $m)) {
		$result['amount_ht'] = auvorechnungsimport_parse_amount($m[1]);
	}
	// Some German invoices print no separate net total, but list the taxable
	// bases in their VAT summary ("7 % von 121,50"). Sum those bases so the
	// review can validate both net and gross totals independently.
	if ($result['amount_ht'] === null) {
		$vatBases = array();
		$vatLabel = '(?:MwSt|USt|Mehrwertsteuer|Umsatzsteuer|VAT|TVA)\.?';
		$baseNumber = '(-?[0-9]{1,3}(?:[. \x{00A0}][0-9]{3})*[,.][0-9]{2})';
		foreach (preg_split('/\r\n|\r|\n/u', $text) as $textLine) {
			if (preg_match('/\b'.$vatLabel.'\b/iu', $textLine) && preg_match('/\bvon\s*'.$baseNumber.'/iu', $textLine, $baseMatch)) {
				$baseValue = preg_replace('/[\s\x{00A0}]+/u', '', $baseMatch[1]);
				$base = auvorechnungsimport_parse_amount($baseValue);
				if ($base !== null) $vatBases[] = $base;
			}
		}
		if ($vatBases) $result['amount_ht'] = round(array_sum($vatBases), 2);
	}
	if (strpos($text, '€') !== false || preg_match('/\bEUR\b/i', $text)) {
		$result['currency'] = 'EUR';
	}

	// Read contact and payment details from ordinary text PDFs and OCR as well.
	if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $text, $m)) {
		$result['supplier_email'] = trim($m[0], " .,;:<>()[]{}");
	}
	if (preg_match('/\b(?:IBAN\s*[:.]?\s*)?([A-Z]{2}\s?\d{2}(?:[\s-]?\d){10,30})\b/iu', $text, $m)) {
		$iban = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $m[1]));
		if (checkIbanForAccount(null, $iban)) $result['supplier_iban'] = $iban;
	}
	if (preg_match('/(?:Telefon|Tel\.?|Phone|Mobil|Mobile|Fax)[^\d+]{0,12}(\+?[0-9][0-9 ()\/.\-]{5,20}[0-9])/iu', $text, $m)) {
		$result['supplier_phone'] = trim($m[1]);
	}
	if (preg_match('/\b(?:DE|AT|CH|FR|NL|BE|LU|IT|ES|PL|DK|SE|NO|FI|CZ|GB)\b/u', $text, $m)) {
		$result['supplier_country'] = strtoupper($m[0]);
		$result['supplier_address']['country'] = $result['supplier_country'];
	}

	// Supplier name guess: a line naming its own legal form (GmbH, AG, SARL, ...) is
	// virtually always the registered company name itself - far more reliable than
	// "first line of the document", which just as often picks up the buyer's address
	// (multi-column letters: pdftotext's stream order frequently isn't visual order)
	// or the letterhead's e-mail/website line. Falls back to the first readable line
	// only if no such line exists (e.g. sole proprietors without a legal-form suffix).
	$candidateLines = array();
	foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
		$line = trim($line);
		if ($line === '' || dol_strlen($line) > 80 || !preg_match('/\p{L}{2}/u', $line)) {
			continue; // skip empty lines, page markers ("- Seite 1/2 -"), pure numbers/punctuation
		}
		if (preg_match('/^-?\s*(Seite|Page)\b/iu', $line)) {
			continue;
		}
		$candidateLines[] = $line;
	}
	foreach ($candidateLines as $lineIndex => $line) {
		if (preg_match('/(?:\b(?:GmbH|AG|KGaA|KG|OHG|e\.?K\.?|UG|SARL|SASU|SAS|SA|Ltd|Inc|LLC)\b|\be\.?\s?V\.?(?=\W|$))/iu', $line, $legalForm, PREG_OFFSET_CAPTURE)) {
			$result['supplier_name'] = trim(substr($line, 0, $legalForm[0][1] + strlen($legalForm[0][0])));
			// OCR often wraps a long organization name at a word boundary. Preserve
			// common German organization prefixes that appear alone on the previous
			// line, e.g. "Sportgemeinschaft" + "Johannesberg 1926 e.V.".
			if ($lineIndex > 0 && preg_match('/^(?:Sportgemeinschaft|Arbeitsgemeinschaft|Interessengemeinschaft|Baugenossenschaft|Wohnungsbaugesellschaft|Erzeugergemeinschaft|Einkaufsgemeinschaft|Verwaltungsgemeinschaft)$/iu', trim($candidateLines[$lineIndex - 1]))) {
				$result['supplier_name'] = trim($candidateLines[$lineIndex - 1]).' '.$result['supplier_name'];
			}
			$result['supplier_name_confident'] = true;
			break;
		}
	}
	if (empty($result['supplier_name']) && !empty($candidateLines)) {
		$result['supplier_name'] = $candidateLines[0];
	}

	return $result;
}

/**
 * Try to match a supplier by VAT number first (exact), then by name (loose).
 *
 * @param DoliDB $db
 * @param string $vat
 * @param string $name
 * @param string $taxId Tax number (BT-32) for sellers without VAT ID
 * @return array{0:?int,1:string} [fk_soc or null, confidence label]
 */
function auvorechnungsimport_match_supplier($db, $vat, $name, $taxId = '')
{
	if (!empty($vat)) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE REPLACE(REPLACE(REPLACE(UPPER(tva_intra), ' ', ''), '.', ''), '-', '') = '".$db->escape(auvorechnungsimport_normalize_vat($vat))."'";
		$sql .= " AND fournisseur = 1";
		$sql .= " AND entity IN (".getEntity('societe').")";
		$sql .= $db->plimit(2);
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) === 1 && ($obj = $db->fetch_object($resql))) {
			return array((int) $obj->rowid, 'vat_exact');
		}
	}
	// Tax number (Steuernummer, BT-32) of sellers without VAT ID, e.g. small
	// businesses: Dolibarr keeps it in professional ID 1 for German companies.
	$taxDigits = preg_replace('/[^0-9]/', '', (string) $taxId);
	if (strlen($taxDigits) >= 10) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE REPLACE(REPLACE(REPLACE(idprof1, '/', ''), ' ', ''), '-', '') = '".$db->escape($taxDigits)."'";
		$sql .= " AND fournisseur = 1 AND entity IN (".getEntity('societe').")";
		$sql .= $db->plimit(2);
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) == 1 && ($obj = $db->fetch_object($resql))) {
			return array((int) $obj->rowid, 'taxid_exact');
		}
	}

	// Try the full extracted name first, then a version with legal-form suffixes
	// and country words stripped (e.g. "Muster-Textil Deutschland GmbH" -> "Muster-
	// Textil") - the registered thirdparty name rarely matches the letterhead word
	// for word, but the distinctive core of the name usually still is a substring
	// of it either way.
	foreach (array($name, auvorechnungsimport_strip_legal_suffix($name)) as $i => $candidate) {
		if (empty($candidate) || dol_strlen($candidate) < 3) {
			continue;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE UPPER(nom) LIKE '%".$db->escape(strtoupper($db->escapeforlike($candidate)))."%'";
		$sql .= " AND fournisseur = 1";
		$sql .= " AND entity IN (".getEntity('societe').")";
		$sql .= $db->plimit(2);
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) == 1 && ($obj = $db->fetch_object($resql))) {
			return array((int) $obj->rowid, $i === 0 ? 'name_fuzzy' : 'name_fuzzy_stripped');
		}
	}

	return array(null, 'none');
}

/**
 * Find a unique third party with this VAT number that is not yet a supplier.
 *
 * @param DoliDB $db
 * @param string $vat
 * @return ?int Third-party ID, or null when absent or ambiguous
 */
function auvorechnungsimport_find_non_supplier_by_vat($db, $vat)
{
	$vat = auvorechnungsimport_normalize_vat($vat);
	if ($vat === '') {
		return null;
	}
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
	$sql .= " WHERE REPLACE(REPLACE(REPLACE(UPPER(tva_intra), ' ', ''), '.', ''), '-', '') = '".$db->escape($vat)."'";
	$sql .= " AND fournisseur <> 1 AND entity IN (".getEntity('societe').")";
	$sql .= $db->plimit(2);
	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) !== 1) {
		return null;
	}
	$obj = $db->fetch_object($resql);
	return $obj ? (int) $obj->rowid : null;
}

/** Normalize a company name into distinctive tokens for own-company checks. */
function auvorechnungsimport_company_name_tokens($name)
{
	$name = strtr((string) $name, array('Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'));
	$name = strtolower($name);
	$name = preg_replace('/[^a-z0-9]+/', ' ', $name);
	$stop = array('e', 'v', 'ev', 'gmbh', 'ug', 'ag', 'kg', 'ohg', 'kgaa', 'ltd', 'inc', 'llc', 'sarl', 'sasu', 'sa', 'sv', 'fv', 'ev', 'der', 'die', 'das', 'und', 'von', 'zu');
	$tokens = array_filter(preg_split('/\s+/', trim($name)), function ($token) use ($stop) {
		return $token !== '' && !ctype_digit($token) && !in_array($token, $stop, true);
	});
	return array_values(array_unique($tokens));
}

/** Detect when OCR or the chosen supplier points back to Dolibarr's own company. */
function auvorechnungsimport_is_own_company_supplier($db, $fields = array(), $supplierId = 0)
{
	global $mysoc;
	$ownNames = array(getDolGlobalString('MAIN_INFO_SOCIETE_NOM'));
	$aliasValue = getDolGlobalString('AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES');
	// Older textarea values may contain actual line breaks or their literal
	// escaped spelling. Normalize those once, then semicolon is the only delimiter.
	$aliasValue = str_replace(array('\\r\\n', '\\n', '\\r', "\r\n", "\r", "\n"), ';', $aliasValue);
	$ownAliases = explode(';', $aliasValue);
	foreach ($ownAliases as $alias) {
		$alias = trim($alias);
		if ($alias !== '') $ownNames[] = $alias;
	}
	if (is_object($mysoc) && !empty($mysoc->name)) $ownNames[] = (string) $mysoc->name;
	$ownVat = auvorechnungsimport_normalize_vat(getDolGlobalString('MAIN_INFO_TVAINTRA'));
	$ownTokens = array();
	foreach ($ownNames as $ownName) {
		$tokens = auvorechnungsimport_company_name_tokens($ownName);
		if ($tokens) $ownTokens[] = $tokens;
	}
	$candidateName = trim((string) ($fields['supplier_name'] ?? ''));
	$candidateVat = auvorechnungsimport_normalize_vat($fields['supplier_vat'] ?? '');
	if ($ownVat !== '' && $candidateVat !== '' && $candidateVat === $ownVat) return true;

	$candidateTokens = auvorechnungsimport_company_name_tokens($candidateName);
	if ($candidateTokens) {
		foreach ($ownTokens as $tokens) {
			$common = count(array_intersect($tokens, $candidateTokens));
			$smaller = min(count($tokens), count($candidateTokens));
			if ($common > 0 && ($common === count($tokens) && $common === count($candidateTokens) || $common >= 2 && $common / max(1, $smaller) >= 0.8)) return true;
		}
	}

	if ($supplierId > 0) {
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$supplier = new Societe($db);
		if ($supplier->fetch((int) $supplierId) > 0) {
			$supplierVat = auvorechnungsimport_normalize_vat($supplier->tva_intra ?? '');
			if ($ownVat !== '' && $supplierVat !== '' && $supplierVat === $ownVat) return true;
			$supplierTokens = auvorechnungsimport_company_name_tokens($supplier->name ?? '');
			foreach ($ownTokens as $tokens) {
				$common = count(array_intersect($tokens, $supplierTokens));
				$smaller = min(count($tokens), count($supplierTokens));
				if ($common > 0 && ($common === count($tokens) && $common === count($supplierTokens) || $common >= 2 && $common / max(1, $smaller) >= 0.8)) return true;
			}
		}
	}
	return false;
}

/**
 * Create a supplier only when extraction has a reliable company name. VAT ID is
 * used for deduplication when present, but is not mandatory for supplier creation.
 *
 * @param DoliDB $db
 * @param User $user
 * @param array<string,mixed> $fields Extracted invoice fields
 * @return int Supplier id, or 0 when evidence/settings/permissions are insufficient
 */
function auvorechnungsimport_can_create_supplier_from_fields($user, $fields, $allowManualName = false)
{
	$name = trim((string) ($fields['supplier_name'] ?? ''));
	$vat = auvorechnungsimport_normalize_vat($fields['supplier_vat'] ?? '');
	return (bool) (getDolGlobalInt('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER') && $user
		&& $user->hasRight('societe', 'creer') && ($allowManualName || !empty($fields['supplier_name_confident']))
		&& $name !== '' && ($vat === '' || preg_match('/^[A-Z]{2}[A-Z0-9]{8,14}$/', $vat)));
}

function auvorechnungsimport_create_supplier_from_fields($db, $user, $fields, $choices = array(), &$error = null, $allowManualName = false)
{
	global $conf;
	if (!getDolGlobalInt('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER')) {
		$error = 'Automatische Lieferantenanlage ist in den Moduleinstellungen deaktiviert.';
		return 0;
	}
	if (!$user || !$user->hasRight('societe', 'creer')) {
		$error = 'Dem Benutzer fehlt das Geschäftspartnerrecht zum Anlegen.';
		return 0;
	}
	if (empty($fields['supplier_name_confident']) && !$allowManualName || trim((string) ($fields['supplier_name'] ?? '')) === '') {
		$error = 'Der erkannte Firmenname ist nicht als sicher markiert. Bitte Import erneut analysieren oder Lieferant manuell auswählen.';
		return 0;
	}
	$vatCheck = auvorechnungsimport_normalize_vat($fields['supplier_vat'] ?? '');
	if ($vatCheck !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9]{8,14}$/', $vatCheck)) {
		$error = 'Die erkannte USt-IdNr. hat ein ungültiges Format. Bitte USt-IdNr. korrigieren oder leeren.';
		return 0;
	}
	$name = trim((string) ($fields['supplier_name'] ?? ''));
	$vat = auvorechnungsimport_normalize_vat($fields['supplier_vat'] ?? '');

	// Reuse a unique existing partner by VAT when present; otherwise use an
	// exact company-name match to avoid duplicate suppliers without a VAT ID.
	$sql = "SELECT rowid, fournisseur FROM ".MAIN_DB_PREFIX."societe WHERE ";
	if ($vat !== '') {
		$sql .= "REPLACE(REPLACE(REPLACE(UPPER(tva_intra), ' ', ''), '.', ''), '-', '') = '".$db->escape($vat)."'";
	} else {
		$sql .= "UPPER(nom) = UPPER('".$db->escape($name)."')";
	}
	$sql .= " AND entity IN (".getEntity('societe').")".$db->plimit(2);
	$resql = $db->query($sql);
	if (!$resql) {
		$error = $db->lasterror();
		return 0;
	}
	if ($db->num_rows($resql) > 0) {
		if ($db->num_rows($resql) !== 1) {
			$error = 'Mehrere Geschäftspartner mit derselben USt-IdNr. oder demselben Firmennamen gefunden.';
			return 0;
		}
		$obj = $db->fetch_object($resql);
		if ($obj && (int) $obj->fournisseur === 1) return (int) $obj->rowid;
		$error = 'Ein Geschäftspartner mit diesem Identifikator existiert bereits, ist aber nicht als Lieferant markiert. Bitte den Lieferantenstatus beim Geschäftspartner ergänzen.';
		return 0;
	}

	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
	$supplier = new Societe($db);
	$supplier->name = dol_trunc($name, 128);
	$supplier->entity = (int) $conf->entity;
	$supplier->client = 0;
	$supplier->fournisseur = 1;
	$supplier->code_fournisseur = 'auto';
	$supplier->tva_intra = $vat;
	$supplier->tva_assuj = 1;
	$address = $fields['supplier_address'] ?? array();
	if (is_array($address)) {
		$supplier->address = trim(implode("\n", array_filter(array(
			(string) ($address['line1'] ?? ''), (string) ($address['line2'] ?? ''), (string) ($address['line3'] ?? ''),
		), 'strlen')));
		$supplier->zip = (string) ($address['postcode'] ?? '');
		$supplier->town = (string) ($address['city'] ?? '');
	}
	$countryCode = strtoupper(trim((string) ($address['country'] ?? '')));
	if (preg_match('/^[A-Z]{2}$/', $countryCode)) {
		$countryId = getCountry($countryCode, '3', $db);
		if (is_numeric($countryId) && (int) $countryId > 0) $supplier->country_id = (int) $countryId;
	}
	if (($choices['update_supplier_email'] ?? 'update') === 'update') $supplier->email = (string) ($fields['supplier_email'] ?? '');
	if (($choices['update_supplier_phone'] ?? 'update') === 'update') $supplier->phone = (string) ($fields['supplier_phone'] ?? '');
	if ($supplier->create($user) > 0) {
		return (int) $supplier->id;
	}
	dol_syslog('AuvoRechnungsimport: supplier auto-creation failed for VAT '.$vat.': '.implode(', ', $supplier->errors), LOG_WARNING);
	$error = !empty($supplier->errors) ? implode('; ', $supplier->errors) : ($supplier->error ?: $db->lasterror());
	return 0;
}

/** Validate the proposed review lines against extracted invoice totals before creation. */
function auvorechnungsimport_check_review_totals($extracted)
{
	$lines = isset($extracted['lines']) && is_array($extracted['lines']) ? $extracted['lines'] : array();
	$net = 0.0;
	$gross = 0.0;
	$skippedNet = 0.0;
	$skippedGross = 0.0;
	$count = 0;
	$missingVatLines = array();
	if ($lines) {
		foreach ($lines as $i => $line) {
			$skipLine = GETPOST('line_mode_'.$i, 'aZ09') === 'skip';
			$qtyRaw = GETPOST('line_qty_'.$i, 'alpha');
			$priceRaw = GETPOST('line_price_'.$i, 'alpha');
			$vatRaw = GETPOST('line_vat_'.$i, 'alpha');
			$submittedPriceBase = GETPOST('line_price_base_'.$i, 'alpha');
			$vatValue = price2num($vatRaw);
			if (trim((string) $vatRaw) === '' || !is_numeric($vatValue) || (float) $vatValue < 0 || (float) $vatValue > 100) $missingVatLines[] = (int) $i + 1;
			$qty = (float) price2num($qtyRaw);
			$price = (float) price2num($priceRaw, 'MU');
			$vat = (float) price2num($vatRaw);
			// Negative unit prices are valid discounts/credit lines. Invoice creation
			// books them as negative quantities, so the total check must include them.
			if ($qty <= 0 || $vat < 0 || $vat > 100) continue;
			$base = $qty * $price;
			$priceBase = in_array($submittedPriceBase, array('HT', 'TTC'), true) ? $submittedPriceBase : (($line['price_base'] ?? 'HT') === 'TTC' ? 'TTC' : 'HT');
			if ($priceBase === 'TTC') {
				$lineGross = $base;
				$lineNet = $vat > 0 ? $base / (1 + $vat / 100) : $base;
			} else {
				$lineNet = $base;
				$lineGross = $base * (1 + $vat / 100);
			}
			$lineNet = round($lineNet, 2);
			$lineGross = round($lineGross, 2);
			if ($skipLine) {
				$skippedNet += $lineNet;
				$skippedGross += $lineGross;
				continue;
			}
			$net += $lineNet;
			$gross += $lineGross;
			$count++;
		}
	} else {
		$qtyRaw = GETPOST('manual_line_qty', 'alpha');
		$priceRaw = GETPOST('manual_line_price', 'alpha');
		$vatRaw = GETPOST('manual_line_vat', 'alpha');
		$qty = (float) price2num($qtyRaw);
		$price = (float) price2num($priceRaw, 'MU');
		$vat = (float) price2num($vatRaw);
		if ($qty > 0 && $price >= 0 && $vat >= 0 && $vat <= 100) {
			$net = round($qty * $price, 2);
			$gross = round($net * (1 + $vat / 100), 2);
			$count = 1;
		}
	}
	if ($count === 0) {
		return array('checked' => true, 'valid' => false, 'errors' => array(), 'missing_vat_lines' => $missingVatLines, 'no_lines' => true, 'net' => 0.0, 'gross' => 0.0, 'skipped_net' => $skippedNet, 'skipped_gross' => $skippedGross, 'expected_net' => null, 'expected_gross' => null, 'count' => 0);
	}
	$checks = array();
	if (isset($extracted['amount_ht']) && $extracted['amount_ht'] !== null && $count > 0) {
		$checks[] = array('HT', $net, (float) $extracted['amount_ht'] - $skippedNet);
	}
	if (isset($extracted['amount_ttc']) && $extracted['amount_ttc'] !== null && $count > 0) {
		$checks[] = array('TTC', $gross, (float) $extracted['amount_ttc'] - $skippedGross);
	}
	$errors = array();
	$tolerance = max(0.02, 0.01 * $count);
	foreach ($checks as $check) {
		if (abs($check[1] - $check[2]) > $tolerance) $errors[] = $check;
	}
	return array(
		'checked' => !empty($checks), 'valid' => empty($errors) && empty($missingVatLines), 'errors' => $errors, 'missing_vat_lines' => $missingVatLines, 'no_lines' => false,
		'net' => $net, 'gross' => $gross, 'skipped_net' => $skippedNet, 'skipped_gross' => $skippedGross,
		'expected_net' => isset($extracted['amount_ht']) && $extracted['amount_ht'] !== null ? (float) $extracted['amount_ht'] - $skippedNet : null,
		'expected_gross' => isset($extracted['amount_ttc']) && $extracted['amount_ttc'] !== null ? (float) $extracted['amount_ttc'] - $skippedGross : null,
		'count' => $count
	);
}

/**
 * Run the full extraction pipeline on an uploaded file and fill the fields
 * directly onto the given ImportedInvoice instance (extraction_method,
 * extracted_data, fk_soc, supplier_match_confidence, status).
 *
 * @param DoliDB $db
 * @param ImportedInvoice $object
 * @param string $abspath Absolute path to the uploaded PDF
 * @return void
 */
function auvorechnungsimport_process_file($db, $object, $abspath, $user = null)
{
	if (auvorechnungsimport_is_xml_file($abspath)) {
		// XML e-invoice (XRechnung, Peppol): exact data, nothing to guess. An
		// XML that is no EN 16931 invoice ends up as an error record.
		$inv = auvorechnungsimport_parse_einvoice((string) @file_get_contents($abspath));
		$fields = $inv !== null ? auvorechnungsimport_einvoice_to_fields($inv) : array('lines' => array());
		$method = $inv !== null ? 'xml-'.$inv['syntax'] : 'xml';
	} else {
		$fields = auvorechnungsimport_extract_facturx($abspath);
		$method = 'facturx';
	}

	if ($fields === null) {
		$ocrText = '';
		$ocrLayoutText = '';
		$ocrMaxPages = getDolGlobalInt('AUVORECHNUNGSIMPORT_OCR_MAXPAGES', 5);
		// Layout text first: it is cheap (external process) and tells whether the
		// PDF has a text layer at all. For image-only PDFs (scans) smalot is
		// skipped - it would decode the full page images into PHP memory just to
		// find no text, which can exhaust memory_limit on multi-page scans.
		$lineText = auvorechnungsimport_extract_pdftext_layout($abspath);
		$text = ($lineText === null || $lineText !== '') ? auvorechnungsimport_extract_pdftext($abspath) : '';
		$fields = auvorechnungsimport_parse_fields($text);
		// On Windows, Poppler's layout text may be available even when the PDF
		// parser's stream text misses totals. Use those exact text-layer values
		// before deciding whether a costly OCR fallback is needed.
		if ($lineText !== null && $lineText !== '') {
			$layoutFields = auvorechnungsimport_parse_fields($lineText);
			foreach (array('supplier_vat', 'supplier_tax_id', 'supplier_iban', 'invoice_ref', 'invoice_date', 'amount_ttc', 'amount_ht', 'currency') as $key) {
				if (empty($fields[$key]) && !empty($layoutFields[$key])) {
					$fields[$key] = $layoutFields[$key];
				}
			}
		}
		$method = 'text';

		// The digital text layer of multi-column/letter-style invoices often comes out
		// of pdftotext in an order that doesn't match the visual layout (e.g. the
		// recipient address block before the sender's own letterhead), which throws
		// off the label-adjacency heuristics. OCR reads in visual order instead, so
		// it's worth the extra seconds whenever the text pass didn't get everything.
		if (empty($fields['supplier_vat']) || empty($fields['invoice_ref']) || empty($fields['amount_ttc'])) {
			$ocrLangs = getDolGlobalString('AUVORECHNUNGSIMPORT_OCR_LANGS', 'deu+fra+eng');
			$ocrText = auvorechnungsimport_extract_ocr($abspath, $ocrLangs, $ocrMaxPages, $ocrLayoutText);
			if (!empty($ocrText)) {
				$ocrFields = auvorechnungsimport_parse_fields($ocrText);
				// supplier_name is never empty just because it's wrong (both passes fall
				// back to "first readable line" if no legal-form-suffixed line is found),
				// so the usual "fill only if empty" rule doesn't help here. Prefer OCR's
				// guess only when it found a legal-form-anchored name ("...GmbH") and the
				// text pass didn't - OCR reads in visual order, so on multi-column layouts
				// where pdftotext's stream order isn't visual it sometimes finds the real
				// letterhead where the text pass's weak first-line fallback didn't.
				if (!$fields['supplier_name_confident'] && !empty($ocrFields['supplier_name_confident'])) {
					$fields['supplier_name'] = $ocrFields['supplier_name'];
				}
				// For the rest, keep whichever pass found something - don't let a
				// successful text-layer read get overwritten by a worse OCR read.
				foreach ($ocrFields as $key => $value) {
					if (!in_array($key, array('supplier_name', 'supplier_name_confident'), true) && empty($fields[$key]) && !empty($value)) {
						$fields[$key] = $value;
					}
				}
				$method = 'text+ocr';
			}
		}

		// Invoice lines: from the layout-preserving text layer, or from OCR for
		// scanned PDFs. Cross-checked against the totals (which are looked for in
		// the line source text too, in case the header pass missed them).
		$lines = auvorechnungsimport_parse_text_lines((string) $lineText);
		if (empty($lines) && $ocrLayoutText !== '') {
			$lineText = $ocrLayoutText;
			$lines = auvorechnungsimport_parse_text_lines($lineText);
		}
		$lineText = (string) $lineText;
		$totals = $fields;
		if (!empty($lines) && (empty($totals['amount_ht']) || empty($totals['amount_ttc']))) {
			$lineTextFields = auvorechnungsimport_parse_fields($lineText);
			foreach (array('amount_ht', 'amount_ttc') as $key) {
				if (empty($totals[$key])) {
					$totals[$key] = $lineTextFields[$key];
				}
			}
		}
		list($fields['lines'], $fields['lines_check']) = auvorechnungsimport_finish_text_lines($lines, $totals, $lineText);
		if (auvorechnungsimport_is_german_small_business_invoice($text.' '.$lineText)) {
			$fields['vat_treatment'] = 'DE_KLEINUNTERNEHMER_19';
		}
		if (empty($fields['amount_ht']) && !empty($totals['amount_ht'])) {
			$fields['amount_ht'] = $totals['amount_ht'];
		}
	}

	list($fkSoc, $confidence) = auvorechnungsimport_match_supplier($db, $fields['supplier_vat'] ?? '', $fields['supplier_name'] ?? '', $fields['supplier_tax_id'] ?? '');
	$object->extraction_method = $method;
	$object->extracted_data = json_encode($fields, JSON_UNESCAPED_UNICODE);
	$object->fk_soc = $fkSoc;
	$object->supplier_match_confidence = $confidence;
	// Only a genuinely empty result counts as a hard failure - if we got at least
	// a usable hint (VAT, amount, supplier name or invoice ref), the record is
	// still worth a manual review rather than being flagged as an error. The
	// review form is shown either way, this only affects the status label.
	$gotSomething = !empty($fields['supplier_vat']) || !empty($fields['amount_ttc'])
		|| !empty($fields['supplier_name']) || !empty($fields['invoice_ref']);
	if (!$gotSomething) {
		$object->status = ImportedInvoice::STATUS_ERROR;
		$details = array();
		if (auvorechnungsimport_is_xml_file($abspath)) {
			$details[] = 'Die XML-Datei konnte nicht als unterstützte XRechnung/EN-16931-Rechnung gelesen werden.';
		} else {
			$details[] = 'Keine Rechnungsdaten (Lieferant, Rechnungsnummer oder Betrag) erkannt.';
			$details[] = 'PDF-Text: '.($lineText === null ? 'pdftotext nicht verfügbar oder fehlgeschlagen' : (trim($lineText) === '' ? 'keine Textebene gefunden' : 'Text vorhanden'));
			if (!empty($ocrText)) $details[] = 'OCR: Text wurde erkannt, enthielt aber keine verwertbaren Rechnungsfelder.';
			else $details[] = 'OCR lieferte keinen Text; Poppler, Tesseract und Sprachpakete prüfen.';
		}
		$object->note_private = trim((string) $object->note_private."\n[Analysefehler] ".implode(' ', $details));
	} elseif ($fkSoc) {
		// Supplier was matched automatically - nothing left to look up, just confirm.
		$object->status = ImportedInvoice::STATUS_MATCHED;
	} else {
		$object->status = ImportedInvoice::STATUS_NEEDS_REVIEW;
	}
}

/**
 * Iteratively strips trailing legal-form suffixes and country words from a
 * company name, e.g. "Muster-Textil Deutschland GmbH" -> "Muster-Textil".
 *
 * @param string $name
 * @return string
 */
function auvorechnungsimport_strip_legal_suffix($name)
{
	$name = trim((string) $name);
	$pattern = '/\s*(?:GmbH\s*&\s*Co\.?\s*KG|GmbH|mbH|AG|KGaA|KG|OHG|e\.?K\.?|UG(?:\s*\(haftungsbeschränkt\))?|SARL|SASU|SAS|SA|S\.A\.?|B\.?V\.?|Ltd\.?|Inc\.?|LLC|Co\.?|Deutschland|International)\s*$/iu';
	do {
		$prev = $name;
		$name = trim((string) preg_replace($pattern, '', $name));
	} while ($name !== $prev && $name !== '');
	return $name;
}

/**
 * @param string $vat
 * @return string
 */
function auvorechnungsimport_normalize_vat($vat)
{
	return strtoupper(preg_replace('/[\s.\-]/', '', (string) $vat));
}

/**
 * @param ?string $amount
 * @return ?float
 */
function auvorechnungsimport_parse_amount($amount)
{
	if ($amount === null || $amount === '') {
		return null;
	}
	$amount = trim((string) $amount);
	if (preg_match('/[,.]\s*[-–—]{1,2}$/u', $amount)) {
		// German notation "625,-" means an integer euro amount.
		$amount = preg_replace('/[,.]\s*[-–—]{1,2}$/u', '', $amount);
		$amount = str_replace(array('.', ',', ' '), '', $amount);
		return is_numeric($amount) ? (float) $amount : null;
	}
	// European format "1.234,56" -> "1234.56"; a lone comma is read as decimal separator.
	if (preg_match('/,\d{2}$/', $amount)) {
		$amount = str_replace('.', '', $amount);
		$amount = str_replace(',', '.', $amount);
	} else {
		$amount = str_replace(',', '', $amount);
	}
	return is_numeric($amount) ? (float) $amount : null;
}

/**
 * @param string $raw UN/CEFACT DateTimeString, usually format 102 = YYYYMMDD
 * @return ?string
 */
function auvorechnungsimport_parse_cii_date($raw)
{
	$raw = trim((string) $raw);
	if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
		return $m[1].'-'.$m[2].'-'.$m[3];
	}
	return null;
}

/**
 * Normalize an invoice line label into a stable lookup key for the learned
 * line mapping: lower case, dates and punctuation removed, whitespace
 * collapsed. Other numbers are kept on purpose - "Schraube M6" and "Schraube
 * M8" are different articles, while "Versand 03.10.2026" and "Versand
 * 07.10.2026" are the same one.
 *
 * @param string $label
 * @return string
 */
function auvorechnungsimport_normalize_line_label($label)
{
	$key = mb_strtolower(trim((string) $label), 'UTF-8');
	$key = preg_replace('/\b\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\b|\b\d{4}-\d{2}-\d{2}\b/u', ' ', $key);
	$key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);
	$key = trim(preg_replace('/\s+/u', ' ', $key));
	return dol_trunc($key, 255, 'right', 'UTF-8', 1);
}

/**
 * Lookup keys under which a line is learned / looked up, most specific first.
 * The label comes before the supplier ref: many suppliers use one article
 * number for all sizes/colors of a model (e.g. "F110K ... T 104 Black" and
 * "F110K ... T 116 Red"), which are different products here.
 *
 * @param array<string,mixed> $line
 * @return array<string,string> key_type => match_key
 */
function auvorechnungsimport_line_keys($line)
{
	$keys = array();
	$labelKey = auvorechnungsimport_normalize_line_label($line['label'] ?? '');
	if ($labelKey !== '') {
		$keys['label'] = $labelKey;
	}
	if (!empty($line['ref_supplier'])) {
		$keys['ref'] = dol_trunc(trim($line['ref_supplier']), 255, 'right', 'UTF-8', 1);
	}
	return $keys;
}

/**
 * Product learned for a line key of this supplier (0 if none or ambiguous).
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param string $keyType 'label' or 'ref'
 * @param string $matchKey
 * @return int
 */
function auvorechnungsimport_lookup_learned($db, $fkSoc, $keyType, $matchKey)
{
	$sql = "SELECT m.fk_product FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap as m";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = m.fk_product";
	$sql .= " WHERE m.fk_soc = ".((int) $fkSoc)." AND m.key_type = '".$db->escape($keyType)."'";
	$sql .= " AND m.match_key = '".$db->escape($matchKey)."'";
	$sql .= " AND m.entity IN (".getEntity('auvorechnungsimport').")";
	$resql = $db->query($sql.$db->plimit(1));
	return ($resql && ($obj = $db->fetch_object($resql))) ? (int) $obj->fk_product : 0;
}

/**
 * Propose a product for each invoice line. Tried in this order, first hit wins:
 *   1. Supplier ref in Dolibarr's supplier prices (product_fournisseur_price)
 *   2. Learned mapping by normalized label
 *   3. Product variant: parent product found by supplier ref (learned or by
 *      article number), variant picked by the attribute values in the label
 *      ("6100K T-Shirt One 825/grau M" -> J6100, color 825, size M)
 *   4. Learned mapping by supplier ref
 *   5. EAN/GTIN against the product barcode
 *   6. Product ref equal to the supplier ref, or product label equal to the
 *      line label (weak - shown as suggestion only)
 * Sets 'fk_product' and 'match_source' on every line ('' if nothing found),
 * and 'variant_parent' when the parent was found but no unique variant.
 * Every step requires a unique hit - an ambiguous result proposes nothing.
 *
 * @param DoliDB $db
 * @param int $fkSoc Supplier the invoice is booked on (0 = only supplier-independent steps)
 * @param array<int,array<string,mixed>> $lines
 * @return array<int,array<string,mixed>>
 */
function auvorechnungsimport_match_lines($db, $fkSoc, $lines)
{
	$uniqueProduct = function ($sql) use ($db) {
		$resql = $db->query($sql.$db->plimit(2));
		if ($resql && $db->num_rows($resql) == 1 && ($obj = $db->fetch_object($resql))) {
			return (int) $obj->fk_product;
		}
		return 0;
	};

	foreach ($lines as $i => $line) {
		$fkProduct = 0;
		$source = '';
		$ref = trim((string) ($line['ref_supplier'] ?? ''));
		$keys = auvorechnungsimport_line_keys($line);

		if ($fkSoc > 0 && $ref !== '') {
			$sql = "SELECT DISTINCT pfp.fk_product FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pfp.fk_product";
			$sql .= " WHERE pfp.fk_soc = ".((int) $fkSoc)." AND pfp.ref_fourn = '".$db->escape($ref)."'";
			$sql .= " AND pfp.entity IN (".getEntity('productsupplierprice').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'supplier_price';
		}

		// A supplier price kept on a variant parent (one price for all variants):
		// never book the parent itself, resolve the variant from the label below.
		$variantParent = 0;
		if ($fkProduct && isModEnabled('variants') && !empty(auvorechnungsimport_variant_combinations($db, $fkProduct))) {
			$variantParent = $fkProduct;
			$fkProduct = 0;
		} elseif ($fkProduct && isModEnabled('variants') && ($parentOfHit = auvorechnungsimport_variant_parent_of($db, $fkProduct))) {
			// Hit on a variant via a supplier ref that may stand for the whole model
			// (e.g. stored there by "update purchase prices"): if the label clearly
			// names another variant of the same parent, the label wins. A unique,
			// variant-specific ref ("JN358_M") is kept when the label is unclear.
			$fromLabel = auvorechnungsimport_pick_variant($db, $parentOfHit, $line['label'] ?? '');
			if ($fromLabel && $fromLabel != $fkProduct) {
				$fkProduct = $fromLabel;
				$source = 'variant';
			}
		}

		// Learned label first (exact, per variant), then variant resolution, then
		// the learned supplier ref (which is ambiguous for multi-variant refs).
		$learnKeys = $keys;
		if (!$fkProduct && $fkSoc > 0 && isset($learnKeys['label'])) {
			$fkProduct = auvorechnungsimport_lookup_learned($db, $fkSoc, 'label', $learnKeys['label']);
			$source = 'learned_label';
		}
		unset($learnKeys['label']);

		if (!$fkProduct && isModEnabled('variants')) {
			if (!$variantParent && $ref !== '') {
				list($variantParent) = auvorechnungsimport_find_variant_parent($db, $fkSoc, $ref);
			}
			if ($variantParent) {
				$fkProduct = auvorechnungsimport_pick_variant($db, $variantParent, $line['label'] ?? '');
				$source = 'variant';
			}
		}

		// Not when the line is known to be some variant of a parent: the ref then
		// stands for all its variants, and the learned one would be a guess.
		if (!$fkProduct && $fkSoc > 0 && !$variantParent) {
			foreach ($learnKeys as $keyType => $matchKey) {
				$fkProduct = auvorechnungsimport_lookup_learned($db, $fkSoc, $keyType, $matchKey);
				if ($fkProduct) {
					$source = 'learned_'.$keyType;
					break;
				}
			}
		}

		if (!$fkProduct && !empty($line['gtin'])) {
			$sql = "SELECT rowid as fk_product FROM ".MAIN_DB_PREFIX."product";
			$sql .= " WHERE barcode = '".$db->escape($line['gtin'])."'";
			$sql .= " AND entity IN (".getEntity('product').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'barcode';
		}

		if (!$fkProduct && $ref !== '') {
			$sql = "SELECT rowid as fk_product FROM ".MAIN_DB_PREFIX."product";
			$sql .= " WHERE ref = '".$db->escape($ref)."'";
			$sql .= " AND entity IN (".getEntity('product').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'suggest_ref';
		}

		if (!$fkProduct && !empty($line['label'])) {
			$sql = "SELECT rowid as fk_product FROM ".MAIN_DB_PREFIX."product";
			$sql .= " WHERE UPPER(label) = '".$db->escape(mb_strtoupper(trim($line['label']), 'UTF-8'))."'";
			$sql .= " AND entity IN (".getEntity('product').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'suggest_label';
		}

		// Same rule for any later hit (barcode, suggestion) on a variant parent -
		// except a learned one: that parent was chosen by hand, on purpose.
		if ($fkProduct && strpos($source, 'learned_') !== 0 && isModEnabled('variants') && !empty(auvorechnungsimport_variant_combinations($db, $fkProduct))) {
			$variantParent = $fkProduct;
			$fkProduct = auvorechnungsimport_pick_variant($db, $variantParent, $line['label'] ?? '');
			$source = 'variant';
		}

		$lines[$i]['fk_product'] = $fkProduct;
		$lines[$i]['match_source'] = $fkProduct ? $source : '';
		// Parent known but the label didn't single out one variant: tell the user
		// which product to pick a variant of.
		$lines[$i]['variant_parent'] = (!$fkProduct && $variantParent) ? $variantParent : 0;
		// ... and what creating its own variant would take, if that's clear
		$lines[$i]['variant_plan'] = (!$fkProduct && $variantParent) ? auvorechnungsimport_plan_variant($db, $variantParent, $line['label'] ?? '') : null;

		// How to book the line: learned choice for this supplier and label, then
		// a matched product (booked as such), then the setup's keywords.
		list($lines[$i]['mode'], $lines[$i]['mode_source']) = auvorechnungsimport_line_mode($db, $fkSoc, $lines[$i], (bool) $fkProduct);
		if ($lines[$i]['mode'] !== 'book') {
			$lines[$i]['fk_product'] = 0;
			$lines[$i]['match_source'] = '';
			$lines[$i]['variant_plan'] = null;
		}
	}

	return $lines;
}

/**
 * Remember the product chosen for an invoice line, so the next invoice of the
 * same supplier gets it proposed automatically. Learns under every key of the
 * line (normalized label and supplier ref). Choosing "no product" for a line
 * forgets earlier mappings under its keys, so a wrong suggestion that the user
 * cleared doesn't come back.
 *
 * A supplier ref that turns out to stand for several products (one article
 * number for all variants) is marked ambiguous (fk_product = 0) and from then
 * on never proposes anything - only the label mapping is used for it.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkSoc
 * @param array<string,mixed> $line
 * @param int $fkProduct 0 = line booked without product
 * @return int <0 on error
 */
function auvorechnungsimport_learn_line($db, $user, $fkSoc, $line, $fkProduct)
{
	global $conf;

	if ($fkSoc <= 0) {
		return 0;
	}
	foreach (auvorechnungsimport_line_keys($line) as $keyType => $matchKey) {
		$where = " WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $fkSoc);
		$where .= " AND key_type = '".$db->escape($keyType)."' AND match_key = '".$db->escape($matchKey)."'";

		if ($fkProduct <= 0) {
			// Keep the ambiguity marker of a ref: clearing one variant doesn't make
			// the ref unique again.
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap".$where.($keyType === 'ref' ? " AND fk_product <> 0" : "");
		} else {
			$resql = $db->query("SELECT rowid, fk_product FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap".$where);
			if (!$resql) {
				return -1;
			}
			$obj = $db->fetch_object($resql);
			if ($obj) {
				// Same product again: count it. Different product under the same label:
				// the user corrected us, so the old mapping is replaced and its count
				// starts over. Different product under the same ref: the ref is shared
				// by several products and becomes ambiguous.
				$sql = "UPDATE ".MAIN_DB_PREFIX."auvorechnungsimport_linemap SET";
				if ((int) $obj->fk_product == $fkProduct) {
					$sql .= " nb_used = nb_used + 1";
				} elseif ($keyType === 'ref') {
					$sql .= " fk_product = 0, nb_used = 0";
				} else {
					$sql .= " fk_product = ".((int) $fkProduct).", nb_used = 1";
				}
				$sql .= " WHERE rowid = ".((int) $obj->rowid);
			} else {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."auvorechnungsimport_linemap";
				$sql .= " (entity, fk_soc, key_type, match_key, fk_product, nb_used, date_creation, fk_user_creat)";
				$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fkSoc).", '".$db->escape($keyType)."', '".$db->escape($matchKey)."',";
				$sql .= " ".((int) $fkProduct).", 1, '".$db->idate(dol_now())."', ".((int) $user->id).")";
			}
		}
		if (!$db->query($sql)) {
			return -1;
		}
	}

	// Chosen product is a variant: remember its parent for the supplier ref, so
	// the other colors/sizes of that article resolve by their attributes even
	// though they were never assigned by hand.
	$ref = trim((string) ($line['ref_supplier'] ?? ''));
	if ($fkProduct > 0 && $ref !== '' && isModEnabled('variants')) {
		$fkParent = auvorechnungsimport_variant_parent_of($db, $fkProduct);
		if ($fkParent > 0) {
			$matchKey = dol_trunc($ref, 255, 'right', 'UTF-8', 1);
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $fkSoc);
			$sql .= " AND key_type = 'parent' AND match_key = '".$db->escape($matchKey)."' AND fk_product <> ".((int) $fkParent);
			if (!$db->query($sql)) {
				return -1;
			}
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $fkSoc);
			$sql .= " AND key_type = 'parent' AND match_key = '".$db->escape($matchKey)."'";
			$resql = $db->query($sql);
			if (!$resql) {
				return -1;
			}
			if ($obj = $db->fetch_object($resql)) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."auvorechnungsimport_linemap SET nb_used = nb_used + 1 WHERE rowid = ".((int) $obj->rowid);
			} else {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."auvorechnungsimport_linemap";
				$sql .= " (entity, fk_soc, key_type, match_key, fk_product, nb_used, date_creation, fk_user_creat)";
				$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fkSoc).", 'parent', '".$db->escape($matchKey)."', ".((int) $fkParent).", 1, '".$db->idate(dol_now())."', ".((int) $user->id).")";
			}
			if (!$db->query($sql)) {
				return -1;
			}
		}
	}
	return 1;
}

/**
 * Store the invoice's net unit price as the product's purchase price for this
 * supplier (quantity 1 price row). An existing qty-1 row is updated in place,
 * keeping its other settings; quantity tiers (qty > 1) are left alone. Also
 * fills in the supplier ref, which makes step 1 of the matching hit next time.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkSoc
 * @param int $fkProduct
 * @param array<string,mixed> $line Needs price, price_base, vat_rate, ref_supplier
 * @return int <0 on error, 0 if nothing to do
 */
function auvorechnungsimport_update_buyprice($db, $user, $fkSoc, $fkProduct, $line)
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	$price = (float) ($line['price'] ?? 0);
	if ($fkSoc <= 0 || $fkProduct <= 0 || $price <= 0) {
		return 0;
	}

	$supplier = new Societe($db);
	$pf = new ProductFournisseur($db);
	if ($supplier->fetch($fkSoc) <= 0 || $pf->fetch($fkProduct) <= 0) {
		return -1;
	}

	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
	$sql .= " WHERE fk_product = ".((int) $fkProduct)." AND fk_soc = ".((int) $fkSoc)." AND quantity <= 1";
	$sql .= " AND entity IN (".getEntity('productsupplierprice').")";
	$sql .= " ORDER BY rowid DESC".$db->plimit(1);
	$resql = $db->query($sql);
	$existingId = ($resql && ($obj = $db->fetch_object($resql))) ? (int) $obj->rowid : 0;

	$refFourn = trim((string) ($line['ref_supplier'] ?? ''));
	$availability = 0;
	$remisePercent = 0;
	$deliveryDays = 0;
	$reputation = '';
	$descFourn = '';
	$charges = 0;
	$defaultVatCode = '';
	$barcode = '';
	$barcodeType = 0;
	if ($existingId) {
		if ($pf->fetch_product_fournisseur_price($existingId) <= 0) {
			return -1;
		}
		$pf->product_fourn_price_id = $existingId;
		$availability = (int) $pf->fk_availability;
		$remisePercent = (float) $pf->fourn_remise_percent;
		$deliveryDays = (int) $pf->delivery_time_days;
		$reputation = (string) $pf->supplier_reputation;
		$descFourn = (string) $pf->desc_supplier;
		$charges = (float) ($pf->fourn_charges ?? 0);
		$defaultVatCode = (string) ($pf->default_vat_code ?? '');
		$barcode = (string) ($pf->supplier_barcode ?? '');
		$barcodeType = (int) ($pf->supplier_fk_barcode_type ?? 0);
		// update_buyprice() otherwise resets packaging to the price quantity.
		$pf->product_fourn_packaging = $pf->packaging ?? null;
	}
	// A supplier ref may sit on only one product per supplier and quantity
	// (unique key uk_product_fournisseur_price_ref) - and model numbers shared by
	// all variants ("4233K") can't. Only take over the invoice's ref while it is
	// free; otherwise keep the row's own ref, or use the product ref for a new row.
	$isFree = function ($candidate) use ($db, $fkSoc, $existingId) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
		$sql .= " WHERE fk_soc = ".((int) $fkSoc)." AND quantity = 1 AND ref_fourn = '".$db->escape($candidate)."'";
		$sql .= " AND entity IN (".getEntity('productsupplierprice').")";
		if ($existingId) {
			$sql .= " AND rowid <> ".((int) $existingId);
		}
		$resql = $db->query($sql.$db->plimit(1));
		return $resql && !$db->fetch_object($resql);
	};
	if ($refFourn !== '' && !$isFree($refFourn)) {
		$refFourn = '';
	}
	// On a variant, the invoice ref is typically the model number shared by all
	// variants: keep the supplier ref already on the variant, and give a new
	// price row the variant's own ref - not the model number, which only the
	// first variant could get.
	if (isModEnabled('variants') && auvorechnungsimport_variant_parent_of($db, $fkProduct)) {
		$refFourn = ($existingId && (string) $pf->ref_supplier !== '') ? (string) $pf->ref_supplier : $pf->ref;
	}
	if ($refFourn === '' && $existingId) {
		$refFourn = (string) $pf->ref_supplier;
	}
	if ($refFourn === '') {
		// ref_fourn is mandatory on a supplier price row in Dolibarr.
		$refFourn = $pf->ref;
	}

	$vatRate = isset($line['vat_rate']) && $line['vat_rate'] !== null ? (float) $line['vat_rate'] : (float) $pf->tva_tx;

	// With multicurrency enabled, update_buyprice() derives the price from the
	// currency amount - pass it explicitly, in the company currency at rate 1.
	$priceBase = (($line['price_base'] ?? 'HT') === 'TTC') ? 'TTC' : 'HT';
	$result = $pf->update_buyprice(1, $price, $user, $priceBase, $supplier, $availability, $refFourn, $vatRate, $charges, $remisePercent, 0, 0, $deliveryDays, $reputation, array(), $defaultVatCode, $price, $priceBase, 1, $conf->currency, $descFourn, $barcode, $barcodeType);
	return $result < 0 ? -1 : 1;
}

/**
 * Text layer of a PDF with the visual column layout preserved (poppler's
 * pdftotext -layout). Used for line detection: smalot's text keeps the stream
 * order, which tears table rows apart; -layout keeps each row on one line.
 *
 * @param string $pdfpath
 * @return ?string Empty string if the PDF has no text layer, null if pdftotext is unavailable
 */
function auvorechnungsimport_extract_pdftext_layout($pdfpath)
{
	if (!function_exists('exec')) {
		return null;
	}
	$output = array();
	$stderrRedirect = (DIRECTORY_SEPARATOR === '\\') ? '2>NUL' : '2>/dev/null';
	exec('pdftotext -layout -enc UTF-8 '.escapeshellarg($pdfpath).' - '.$stderrRedirect, $output, $retval);
	return $retval === 0 ? trim(implode("\n", $output)) : null;
}

/**
 * Parse one whitespace-separated token as a number. Accepts German ("1.234,56",
 * "12,5") and English ("12.50") notation, optional trailing currency or unit
 * ("12,50€", "16ST") and a trailing "%".
 *
 * @param string $token
 * @return ?array{value:float,decimals:bool,percent:bool}
 */
function auvorechnungsimport_parse_number_token($token)
{
	$token = trim($token);
	$percent = substr($token, -1) === '%';
	$token = rtrim($token, '%€');
	// German invoices commonly write whole euro amounts as "625,-".
	$wholeEuro = preg_match('/^-?(?:\d{1,3}(?:\.\d{3})+|\d+)[,.][ \t]*[-–—]{1,2}$/u', $token);
	if ($wholeEuro) {
		$token = preg_replace('/[,.][ \t]*[-–—]{1,2}$/u', '', $token);
	}
	if (!preg_match('/^(-?(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,4})?|-?\d+\.\d{2,4})(?:EUR|ST|Stk\.?|x)?$/iu', $token, $m)) {
		return null;
	}
	$num = $m[1];
	if ($wholeEuro) {
		$decimals = true;
		$num = str_replace('.', '', $num);
	} elseif (strpos($num, ',') !== false) {
		$decimals = (bool) preg_match('/,\d{2,4}$/', $num);
		$num = str_replace(array('.', ','), array('', '.'), $num);
	} elseif (preg_match('/^-?\d+\.\d{2,4}$/', $num)) {
		$decimals = true;
	} else {
		$decimals = false;
		$num = str_replace('.', '', $num);
	}
	return array('value' => (float) $num, 'decimals' => $decimals, 'percent' => $percent);
}

/**
 * Heuristic detection of invoice lines in plain text (pdftotext -layout or OCR).
 *
 * A row is recognized by its arithmetic, not by its layout: it must contain a
 * quantity q, a unit price p and a line total t with q x p = t (to the cent,
 * optionally after a "x %" discount between price and total). p and t must be
 * written with decimals. This holds for virtually every invoice layout and
 * practically never by accident on address, bank or payment-term lines - and
 * it tells which number is which, regardless of column order:
 *   "1   2 F110K Fruit of the Loom ... T 104      1,74     3,48"   (qty first)
 *   "1   1816 Rucksack Striker  -  16 ST   12,50   200,00 19,00"   (VAT column)
 *   "1   DTF Meterware   8   Stück   11,89   95,12"
 * Single-amount lines are only accepted for typical fee wording (shipping...).
 *
 * Indented text-only lines right below a row (color, variant) are appended to
 * its label. Totals/tax/payment lines are skipped by keyword.
 *
 * @param string $text
 * @return array<int,array<string,mixed>>
 */
function auvorechnungsimport_parse_text_lines($text)
{
	$skip = '/\b(?:summe|nettosumme|zwischensumme|gesamtsumme|gesamtbetrag|gesamtwert|gesamtmenge|netto|brutto|bruttorechnungswert|übertrag|uebertrag|mwst|ust|umsatzsteuer|mehrwertsteuer|skonto|zahlbetrag|rechnungsbetrag|endbetrag|zahlungsbedingung|zahlbar|iban|bic|total|subtotal|tva|vat|rabatt\s+von)\b/iu';
	$fee = '/\b(?:versand\w*|fracht\w*|porto|verpackung\w*|mindermengen\w*|transport\w*|lieferkosten|zustellung|shipping|delivery|frais\s+de\s+port)\b/iu';
	$unitWords = '/^(?:st|stk|stck|stück|stueck|pcs|pc|pce|x|m|lfm|meter|kg|g|l|h|std|pkt|paket|pck|set|paar|rolle|versand|einheit|pauschal)\.?$/iu';

	$rows = preg_split('/\r\n|\r|\n/', (string) $text);
	$lines = array();
	$last = null; // index into $lines of the row continuation lines may belong to
	$lastIndent = 0;

	foreach ($rows as $row) {
		if (trim($row) === '') {
			$last = null;
			continue;
		}
		$tokens = preg_split('/\s+/u', trim($row));
		$nums = array();
		foreach ($tokens as $idx => $token) {
			$n = auvorechnungsimport_parse_number_token($token);
			if ($n !== null) {
				$nums[$idx] = $n;
			}
		}
		$amountIdx = array_keys(array_filter($nums, function ($n) {
			return $n['decimals'] && !$n['percent'];
		}));

		$match = null;
		if (count($amountIdx) >= 2 && !preg_match($skip, $row)) {
			// Total: one of the last three amounts (the very last can be a VAT
			// column); price: any amount left of it; qty: any number left of the
			// price (rightmost first) or between price and total.
			foreach (array_reverse(array_slice($amountIdx, -3)) as $ti) {
				$t = $nums[$ti]['value'];
				foreach (array_reverse(array_filter($amountIdx, function ($i) use ($ti) {
					return $i < $ti;
				})) as $pi) {
					$p = $nums[$pi]['value'];
					$discount = 0;
					foreach ($nums as $i => $n) {
						if ($i > $pi && $i < $ti && $n['percent']) {
							$discount = $n['value'];
						}
					}
					$qtyCandidates = array();
					for ($i = $pi - 1; $i >= 0; $i--) {
						$qtyCandidates[] = $i;
					}
					for ($i = $pi + 1; $i < $ti; $i++) {
						$qtyCandidates[] = $i;
					}
					foreach ($qtyCandidates as $qi) {
						if (!isset($nums[$qi]) || $nums[$qi]['percent'] || $nums[$qi]['value'] == 0) {
							continue;
						}
						$q = $nums[$qi]['value'];
						if (abs($q * $p * (1 - $discount / 100) - $t) <= 0.011) {
							$match = array('q' => $qi, 'qty' => $q, 'p' => $pi, 't' => $ti, 'discount' => $discount);
							break 3;
						}
					}
				}
			}
			// No qty token: OCR regularly drops isolated single digits (a lone "2"
			// in the qty column). If total / price is a whole number, that is the
			// qty - still checked to the cent, just not against a printed number.
			if ($match === null) {
				foreach (array_reverse(array_slice($amountIdx, -3)) as $ti) {
					$pos = array_search($ti, $amountIdx, true);
					if ($pos === false || $pos === 0) {
						continue;
					}
					$pi = $amountIdx[$pos - 1];
					$p = $nums[$pi]['value'];
					$t = $nums[$ti]['value'];
					$q = $p > 0 ? round($t / $p) : 0;
					if ($q >= 1 && $q <= 10000 && abs($q * $p - $t) <= 0.011) {
						$match = array('q' => null, 'qty' => $q, 'p' => $pi, 't' => $ti, 'discount' => 0);
						break;
					}
				}
			}
		} elseif (count($amountIdx) == 1 && preg_match($fee, $row) && !preg_match($skip, $row)) {
			$ti = $amountIdx[0];
			$match = array('q' => null, 'qty' => 1.0, 'p' => $ti, 't' => $ti, 'discount' => 0);
		}

		if ($match === null) {
			// Continuation of the previous row: indented further than the row's own
			// first column, no amounts, no "Label: value" (customs/HS code lines).
			$indent = strlen($row) - strlen(ltrim($row));
			if ($last !== null && $indent > $lastIndent && empty($amountIdx) && strpos($row, ':') === false
				&& dol_strlen(trim($row)) <= 60 && preg_match('/\p{L}{2}/u', $row) && !preg_match($skip, $row)) {
				$lines[$last]['label'] .= ' '.trim($row);
			} else {
				$last = null;
			}
			continue;
		}

		// Label = text tokens left of the price (left of the qty if the qty sits
		// right of the text), minus position number, qty, units and placeholders.
		$firstText = null;
		foreach ($tokens as $idx => $token) {
			if (!isset($nums[$idx])) {
				$firstText = $idx;
				break;
			}
		}
		$end = ($match['q'] !== null && $firstText !== null && $match['q'] > $firstText && $match['q'] < $match['p']) ? $match['q'] : $match['p'];
		// Leading plain integers before the text: the qty (if it sits there), then
		// a position number, then possibly a purely numeric article number
		// ("1   1816 Rucksack ..."). A lone leading integer counts as position
		// number if it continues the numbering or is short.
		$skipIdx = array();
		if ($match['q'] !== null) {
			$skipIdx[$match['q']] = true;
			// A unit word right after a leading qty ("2 Stk F110K ...")
			if (isset($tokens[$match['q'] + 1]) && preg_match($unitWords, $tokens[$match['q'] + 1])) {
				$skipIdx[$match['q'] + 1] = true;
			}
		}
		$leadingInts = array();
		for ($i = 0; $firstText !== null && $i < $firstText; $i++) {
			if (!isset($skipIdx[$i]) && isset($nums[$i]) && !$nums[$i]['decimals']) {
				$leadingInts[] = $i;
			}
		}
		if (!empty($leadingInts)) {
			$first = $leadingInts[0];
			if (count($leadingInts) >= 2 || (int) $nums[$first]['value'] == count($lines) + 1 || strlen($tokens[$first]) <= 3) {
				$skipIdx[$first] = true;
			}
		}
		$labelTokens = array();
		for ($i = 0; $i < $end; $i++) {
			if (isset($skipIdx[$i]) || preg_match('/^[-–*]$/u', $tokens[$i])) {
				continue;
			}
			$labelTokens[] = $tokens[$i];
		}

		$ref = '';
		if (count($labelTokens) >= 2 && preg_match('/^(?=.*\d)[\p{L}\d][\p{L}\d._\-\/]{2,29}$/u', $labelTokens[0])) {
			$ref = array_shift($labelTokens);
		}
		$label = trim(implode(' ', $labelTokens));
		if ($label === '' && $ref === '') {
			continue;
		}

		// A percentage (or a plausible bare rate) right of the total is the VAT column.
		$vat = null;
		foreach ($nums as $i => $n) {
			if ($i > $match['t'] && $n['value'] >= 0 && $n['value'] <= 27 && ($n['percent'] || in_array((float) $n['value'], array(0.0, 5.0, 5.5, 7.0, 10.0, 16.0, 19.0, 20.0, 21.0), true))) {
				$vat = $n['value'];
				break;
			}
		}

		$qty = (float) $match['qty'];
		$price = $nums[$match['p']]['value'];
		$total = $nums[$match['t']]['value'];
		if ($match['discount']) {
			// Bake the discount into the unit price, like on Factur-X lines.
			$price = $total / $qty;
		}

		$lines[] = array(
			'source' => 'text',
			'ref_supplier' => $ref,
			'gtin' => '',
			'label' => $label !== '' ? $label : $ref,
			'qty' => $qty,
			'unit_code' => '',
			'price' => $price,
			'price_base' => 'HT',
			'vat_rate' => $vat,
			'total' => $total,
		);
		$last = count($lines) - 1;
		$lastIndent = strlen($row) - strlen(ltrim($row));
	}

	return $lines;
}

/**
 * VAT rates named in the invoice's tax summary ("zzgl. 19,00 % MwSt",
 * "USt 19 %", "MwSt. 7%").
 *
 * @param string $text
 * @return array<int,float> Distinct rates
 */
function auvorechnungsimport_parse_vat_rates($text)
{
	$rates = array();
	$label = '/\b(?:MwSt|USt|Mehrwertsteuer|Umsatzsteuer|VAT|TVA)\b/iu';
	foreach (preg_split('/\r\n|\r|\n/u', (string) $text) as $textLine) {
		// Tax summaries may place tax code and wording between the tax label and
		// percentage (e.g. "MwSt. mit Steuercode 502 7,00 % von 121,50").
		if (!preg_match($label, $textLine)) continue;
		if (preg_match_all('/(\d{1,2}(?:[,.]\d{1,2})?)\s*%/u', $textLine, $matches)) {
			foreach ($matches[1] as $rawRate) {
				$value = (float) str_replace(',', '.', $rawRate);
				if ($value >= 0 && $value <= 27) $rates[(string) $value] = $value;
			}
		}
	}
	return array_values($rates);
}

/**
 * Finish text-detected lines against the invoice totals: fill in missing VAT
 * rates when the invoice names exactly one rate, and decide whether the prices
 * are net or gross by which total the lines add up to. Returns the lines and a
 * check result: 'ok' (sum matches the net or gross total), 'mismatch' or
 * 'unknown' (no total to compare with).
 *
 * @param array<int,array<string,mixed>> $lines
 * @param array<string,mixed> $fields Header fields (amount_ht, amount_ttc)
 * @param string $text
 * @return array{0:array<int,array<string,mixed>>,1:string}
 */
function auvorechnungsimport_finish_text_lines($lines, $fields, $text)
{
	$smallBusiness = auvorechnungsimport_is_german_small_business_invoice($text);
	if (empty($lines)) {
		// A small-business invoice can contain one payable amount and no
		// arithmetic line (qty x unit price). Treat it as a gross line at 0%.
		$gross = $fields['amount_ttc'] ?? null;
		if ($smallBusiness && $gross !== null && (float) $gross > 0 && empty($fields['amount_ht'])) {
			$label = 'Leistung laut Rechnung';
			foreach (preg_split('/\r\n|\r|\n/u', (string) $text) as $row) {
				if (preg_match('/\b(Entschädigung|Entschaedigung|Gebühr|Gebuehr|Pauschale|Ablöse|Abloese|Leistung)\b/iu', $row)) {
					$label = trim($row);
					if (dol_strlen($label) > 120) $label = dol_substr($label, 0, 120);
					break;
				}
			}
			$lines = array(array(
				'source' => 'text', 'ref_supplier' => '', 'gtin' => '', 'label' => $label,
				'qty' => 1.0, 'unit_code' => '', 'price' => (float) $gross,
				'price_base' => 'TTC', 'vat_rate' => 0.0, 'vat_src_code' => 'K19', 'total' => (float) $gross,
			));
			return array($lines, 'ok');
		}
		return array($lines, 'unknown');
	}
	if ($smallBusiness) {
		foreach ($lines as $i => $line) {
			$lines[$i]['vat_rate'] = 0.0;
			$lines[$i]['vat_src_code'] = 'K19';
		}
	}
	$rates = auvorechnungsimport_parse_vat_rates($text);
	if (count($rates) == 1) {
		foreach ($lines as $i => $line) {
			if ($line['vat_rate'] === null) {
				$lines[$i]['vat_rate'] = $rates[0];
			}
		}
	}

	$sum = 0;
	foreach ($lines as $line) {
		$sum += (float) $line['total'];
	}
	$tolerance = max(0.05, $sum * 0.001);
	$ht = $fields['amount_ht'] ?? null;
	$ttc = $fields['amount_ttc'] ?? null;
	if ($ht !== null && abs($sum - $ht) <= $tolerance) {
		return array($lines, 'ok');
	}
	if ($ttc !== null && abs($sum - $ttc) <= $tolerance && ($ht === null || abs($ht - $ttc) > $tolerance)) {
		foreach ($lines as $i => $line) {
			$lines[$i]['price_base'] = 'TTC';
		}
		return array($lines, 'ok');
	}
	return array($lines, ($ht === null && $ttc === null) ? 'unknown' : 'mismatch');
}

/** Detect a German small-business VAT note, including spacing and line-break variants. */
function auvorechnungsimport_is_german_small_business_invoice($text)
{
	return auvorechnungsimport_einvoice_is_small_business_note($text);
}

/**
 * Rebuild a layout text (like pdftotext -layout) from tesseract TSV output.
 * Words are grouped into visual rows by their vertical center - independent of
 * tesseract's own block segmentation, which on tables often puts each column
 * into its own block - and placed at a column proportional to their x
 * position. Larger vertical gaps become blank lines.
 *
 * @param string $tsv
 * @return string
 */
function auvorechnungsimport_tsv_to_layout($tsv)
{
	$words = array();
	foreach (preg_split('/\r\n|\r|\n/', (string) $tsv) as $i => $row) {
		$cols = explode("\t", $row);
		// level page block par line word left top width height conf text
		if ($i === 0 || count($cols) < 12 || (int) $cols[0] !== 5 || (float) $cols[10] < 0 || trim($cols[11]) === '') {
			continue;
		}
		$words[] = array('left' => (int) $cols[6], 'cy' => (int) $cols[7] + (int) $cols[9] / 2, 'width' => (int) $cols[8], 'height' => (int) $cols[9], 'text' => trim($cols[11]));
	}
	if (empty($words)) {
		return '';
	}

	$median = function (array $values) {
		sort($values);
		return $values[(int) floor(count($values) / 2)];
	};
	$charWidths = array();
	$heights = array();
	foreach ($words as $w) {
		$len = mb_strlen($w['text'], 'UTF-8');
		if ($len >= 2) {
			$charWidths[] = $w['width'] / $len;
		}
		$heights[] = $w['height'];
	}
	$charWidth = max(1, !empty($charWidths) ? $median($charWidths) : 10);
	$lineHeight = max(1, $median($heights));

	usort($words, function ($a, $b) {
		return $a['cy'] <=> $b['cy'];
	});
	$rows = array();
	foreach ($words as $w) {
		$n = count($rows);
		if ($n > 0 && abs($w['cy'] - $rows[$n - 1]['cy']) <= $lineHeight * 0.5) {
			$rows[$n - 1]['words'][] = $w;
		} else {
			$rows[] = array('cy' => $w['cy'], 'words' => array($w));
		}
	}

	$pitches = array();
	for ($i = 1; $i < count($rows); $i++) {
		$pitches[] = $rows[$i]['cy'] - $rows[$i - 1]['cy'];
	}
	$pitch = !empty($pitches) ? $median($pitches) : $lineHeight * 1.5;

	$out = array();
	foreach ($rows as $i => $row) {
		if ($i > 0 && $row['cy'] - $rows[$i - 1]['cy'] > $pitch * 1.6) {
			$out[] = '';
		}
		usort($row['words'], function ($a, $b) {
			return $a['left'] <=> $b['left'];
		});
		$line = '';
		foreach ($row['words'] as $w) {
			$col = (int) round($w['left'] / $charWidth);
			$len = mb_strlen($line, 'UTF-8');
			$line .= ($len === 0 ? str_repeat(' ', $col) : str_repeat(' ', max(1, $col - $len))).$w['text'];
		}
		$out[] = $line;
	}
	return implode("\n", $out);
}

/**
 * All variant combinations of a parent product with their attribute values
 * (Dolibarr module "variants"), cached per request.
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param bool $reset Drop the cached entry (after creating a variant)
 * @return array<int,array<int,array{id:int,ref:string,value:string}>> fk_child => [fk_attribute => value]
 */
function auvorechnungsimport_variant_combinations($db, $fkParent, $reset = false)
{
	static $cache = array();
	if ($reset) {
		unset($cache[$fkParent]);
		auvorechnungsimport_variant_attribute_values($db, $fkParent, true);
	}
	if (isset($cache[$fkParent])) {
		return $cache[$fkParent];
	}
	$combos = array();
	$sql = "SELECT c.fk_product_child, cv.fk_prod_attr, v.rowid as value_id, v.ref, v.value";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as c";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination2val as cv ON cv.fk_prod_combination = c.rowid";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_value as v ON v.rowid = cv.fk_prod_attr_val";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = c.fk_product_child";
	$sql .= " WHERE c.fk_product_parent = ".((int) $fkParent);
	$sql .= " AND c.entity IN (".getEntity('product').")";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$combos[(int) $obj->fk_product_child][(int) $obj->fk_prod_attr] = array('id' => (int) $obj->value_id, 'ref' => (string) $obj->ref, 'value' => (string) $obj->value);
	}
	$cache[$fkParent] = $combos;
	return $combos;
}

/**
 * The attributes a parent product varies by, in Dolibarr's attribute order,
 * each with all its values - also values no variant of this parent uses yet
 * (a color that exists for the attribute but was never ordered for it).
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param bool $reset
 * @return array<int,array{label:string,position:int,values:array<int,array{id:int,ref:string,value:string}>}>
 */
function auvorechnungsimport_variant_attribute_values($db, $fkParent, $reset = false)
{
	static $cache = array();
	if ($reset) {
		unset($cache[$fkParent]);
		return array();
	}
	if (isset($cache[$fkParent])) {
		return $cache[$fkParent];
	}
	$attrs = array();
	$sql = "SELECT a.rowid as attr_id, a.label, a.position, v.rowid as value_id, v.ref, v.value";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_attribute as a";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_value as v ON v.fk_product_attribute = a.rowid";
	$sql .= " WHERE a.rowid IN (SELECT DISTINCT cv.fk_prod_attr FROM ".MAIN_DB_PREFIX."product_attribute_combination as c";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination2val as cv ON cv.fk_prod_combination = c.rowid";
	$sql .= " WHERE c.fk_product_parent = ".((int) $fkParent).")";
	$sql .= " ORDER BY a.position, a.rowid, v.position, v.rowid";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$id = (int) $obj->attr_id;
		if (!isset($attrs[$id])) {
			$attrs[$id] = array('label' => (string) $obj->label, 'position' => (int) $obj->position, 'values' => array());
		}
		$attrs[$id]['values'][(int) $obj->value_id] = array('id' => (int) $obj->value_id, 'ref' => (string) $obj->ref, 'value' => (string) $obj->value);
	}
	$cache[$fkParent] = $attrs;
	return $attrs;
}

/**
 * Find the variant parent product an invoice line belongs to:
 *   1. learned: supplier ref -> parent, from an earlier manual assignment
 *   2. automatic: a parent product whose ref contains the numeric core of the
 *      supplier ref as a whole number ("4233K" / "4233" -> "J4233"). If several
 *      parents qualify, only those already linked to this supplier (a supplier
 *      price on one of their variants) count. Must be unique.
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param string $ref Supplier ref of the line
 * @return array{0:int,1:string} [fk_parent or 0, 'learned'|'auto'|'']
 */
function auvorechnungsimport_find_variant_parent($db, $fkSoc, $ref)
{
	$ref = trim($ref);
	if ($ref === '') {
		return array(0, '');
	}

	if ($fkSoc > 0) {
		$sql = "SELECT m.fk_product FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap as m";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = m.fk_product";
		$sql .= " WHERE m.fk_soc = ".((int) $fkSoc)." AND m.key_type = 'parent' AND m.match_key = '".$db->escape($ref)."'";
		$sql .= " AND m.entity IN (".getEntity('auvorechnungsimport').")";
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return array((int) $obj->fk_product, 'learned');
		}
	}

	// Numeric core: "4233K" -> "4233", "JN-358" -> "358". At least 3 digits, so
	// that short numbers don't hit half the catalog.
	if (!preg_match('/(\d{3,})/', $ref, $m)) {
		return array(0, '');
	}
	$core = $m[1];
	$sql = "SELECT DISTINCT p.rowid, p.ref,";
	$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."product_attribute_combination as c2";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_fournisseur_price as pfp ON pfp.fk_product = c2.fk_product_child";
	$sql .= " WHERE c2.fk_product_parent = p.rowid AND pfp.fk_soc = ".((int) $fkSoc).") as nb_supplier";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination as c ON c.fk_product_parent = p.rowid";
	$sql .= " WHERE p.ref LIKE '%".$db->escape($db->escapeforlike($core))."%'";
	$sql .= " AND p.entity IN (".getEntity('product').")";
	$resql = $db->query($sql);
	$candidates = array();
	$linked = array();
	while ($resql && ($obj = $db->fetch_object($resql))) {
		if (preg_match('/(?<!\d)'.preg_quote($core, '/').'(?!\d)/', $obj->ref)) {
			$candidates[] = (int) $obj->rowid;
			if ($obj->nb_supplier > 0) {
				$linked[] = (int) $obj->rowid;
			}
		}
	}
	if (count($candidates) == 1) {
		return array($candidates[0], 'auto');
	}
	if (count($linked) == 1) {
		return array($linked[0], 'auto');
	}
	return array(0, '');
}

/**
 * Work out which attribute values of a parent product the line's label names.
 *
 * For every attribute of the parent, each value scores a point if its ref
 * ("825", "08", "M") is a word of the label, and one if its text ("grau",
 * "JAKO blau") appears in it - as whole words, or abbreviated: a label word of
 * at least 3 letters that the value text starts with ("JUN" -> "Junior").
 * Attributes are resolved strongest first, and the words a resolved value used
 * are consumed: in "Stutzen 01/sportrot JUN" the "01" belongs to the color
 * (ref + text match) and can no longer make size value "01 = Junior" a hit.
 * Attributes without a unique hit don't filter (a backpack's label naming only
 * its color is fine as long as that leaves one variant). Exactly one remaining
 * existing combination is the variant.
 *
 * Candidate values are all values of the parent's attributes, not only those
 * some variant uses, so that a combination that doesn't exist yet can be
 * recognized as such (see auvorechnungsimport_plan_variant()).
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param string $label
 * @return array{child:int,resolved:array<int,array{id:int,ref:string,value:string}>,unresolved:array<int,int>,words:array<int,string>,used:array<int,int>}
 */
function auvorechnungsimport_resolve_variant($db, $fkParent, $label)
{
	$result = array('child' => 0, 'resolved' => array(), 'unresolved' => array(), 'words' => array(), 'used' => array());
	$combos = auvorechnungsimport_variant_combinations($db, $fkParent);
	$attributes = auvorechnungsimport_variant_attribute_values($db, $fkParent);
	if (empty($combos) || empty($attributes)) {
		return $result;
	}
	$words = array_values(array_filter(explode(' ', auvorechnungsimport_normalize_line_label($label)), 'strlen'));
	$result['words'] = $words;

	// attribute => [value id => [id, ref, value]]
	$values = array();
	foreach ($attributes as $fkAttr => $attr) {
		$values[$fkAttr] = $attr['values'];
	}

	// Word positions of $label a value matches: [score, positions]
	$matchValue = function ($v, $available) use ($words) {
		$score = 0;
		$used = array();
		$refNorm = auvorechnungsimport_normalize_line_label($v['ref']);
		if ($refNorm !== '' && strpos($refNorm, ' ') === false) {
			foreach ($available as $pos) {
				if ($words[$pos] === $refNorm) {
					$score++;
					$used[] = $pos;
					break;
				}
			}
		}
		$valueWords = array_values(array_filter(explode(' ', auvorechnungsimport_normalize_line_label($v['value'])), 'strlen'));
		$n = count($valueWords);
		if ($n > 0) {
			// whole phrase on consecutive available words
			$availableSet = array_flip($available);
			for ($start = 0; $start + $n <= count($words); $start++) {
				$ok = true;
				for ($k = 0; $k < $n; $k++) {
					if (!isset($availableSet[$start + $k]) || $words[$start + $k] !== $valueWords[$k]) {
						$ok = false;
						break;
					}
				}
				if ($ok) {
					$score++;
					$used = array_merge($used, range($start, $start + $n - 1));
					return array($score, $used);
				}
			}
			// abbreviation of a single-word value ("jun" -> "junior")
			if ($n == 1 && !preg_match('/\d/', $valueWords[0])) {
				foreach ($available as $pos) {
					$w = $words[$pos];
					if (mb_strlen($w, 'UTF-8') >= 3 && !preg_match('/\d/', $w) && $w !== $valueWords[0] && mb_strpos($valueWords[0], $w, 0, 'UTF-8') === 0) {
						$score++;
						$used[] = $pos;
						break;
					}
				}
			}
		}
		return array($score, $used);
	};

	$available = array_keys($words);
	$remaining = $combos;
	$open = $values;
	while (!empty($open)) {
		// Best unique value per open attribute; resolve the strongest attribute.
		$pick = null;
		foreach ($open as $fkAttr => $attrValues) {
			$best = 0;
			$bestKeys = array();
			$bestUsed = array();
			foreach ($attrValues as $key => $v) {
				list($score, $used) = $matchValue($v, $available);
				if ($score > $best) {
					$best = $score;
					$bestKeys = array($key);
					$bestUsed = $used;
				} elseif ($score == $best && $score > 0) {
					$bestKeys[] = $key;
				}
			}
			if ($best > 0 && count($bestKeys) == 1 && ($pick === null || $best > $pick['score'])) {
				$pick = array('attr' => $fkAttr, 'key' => $bestKeys[0], 'score' => $best, 'used' => $bestUsed);
			}
		}
		if ($pick === null) {
			break;
		}
		$want = $open[$pick['attr']][$pick['key']];
		$fkAttr = $pick['attr'];
		$remaining = array_filter($remaining, function ($attrs) use ($fkAttr, $want) {
			return isset($attrs[$fkAttr]) && $attrs[$fkAttr]['id'] === $want['id'];
		});
		$result['resolved'][$fkAttr] = $want;
		$available = array_values(array_diff($available, $pick['used']));
		unset($open[$fkAttr]);
	}
	$result['unresolved'] = array_keys($open);
	$result['used'] = array_values(array_diff(array_keys($words), $available));
	$result['child'] = count($remaining) == 1 ? (int) array_key_first($remaining) : 0;
	return $result;
}

/**
 * Pick the existing variant of a parent product that the line's label
 * describes (see auvorechnungsimport_resolve_variant()).
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param string $label
 * @return int fk_product of the variant, 0 if not unique
 */
function auvorechnungsimport_pick_variant($db, $fkParent, $label)
{
	$r = auvorechnungsimport_resolve_variant($db, $fkParent, $label);
	return $r['child'];
}

/**
 * @param DoliDB $db
 * @param int $fkProduct
 * @return int Parent product if $fkProduct is a variant, else 0
 */
function auvorechnungsimport_variant_parent_of($db, $fkProduct)
{
	$sql = "SELECT fk_product_parent FROM ".MAIN_DB_PREFIX."product_attribute_combination";
	$sql .= " WHERE fk_product_child = ".((int) $fkProduct).$db->plimit(1);
	$resql = $db->query($sql);
	return ($resql && ($obj = $db->fetch_object($resql))) ? (int) $obj->fk_product_parent : 0;
}

/**
 * Supplier invoice already booked with this supplier invoice number, if any -
 * Dolibarr's unique key (ref_supplier, fk_soc, entity) would reject a second one.
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param string $refSupplier
 * @return ?FactureFournisseur
 */
function auvorechnungsimport_find_existing_invoice($db, $fkSoc, $refSupplier)
{
	global $conf;
	$refSupplier = trim((string) $refSupplier);
	if ($fkSoc <= 0 || $refSupplier === '') {
		return null;
	}
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn";
	$sql .= " WHERE fk_soc = ".((int) $fkSoc)." AND ref_supplier = '".$db->escape($refSupplier)."' AND entity = ".((int) $conf->entity);
	$resql = $db->query($sql.$db->plimit(1));
	if (!$resql || !($obj = $db->fetch_object($resql))) {
		return null;
	}
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
	$invoice = new FactureFournisseur($db);
	return $invoice->fetch((int) $obj->rowid) > 0 ? $invoice : null;
}

/**
 * Directory Dolibarr shows as the documents of a supplier invoice (same
 * computation as fourn/facture/document.php). Dolibarr renames it along with
 * the invoice ref on validation, so files put there stay attached.
 *
 * @param FactureFournisseur $invoice
 * @return string
 */
function auvorechnungsimport_invoice_docdir($invoice)
{
	return getMultidirOutput($invoice).'/'.get_exdir($invoice->id, 2, 0, 0, $invoice, 'invoice_supplier').dol_sanitizeFileName($invoice->ref);
}

/**
 * Supplier order linked to a supplier invoice, if any.
 *
 * @param DoliDB $db
 * @param FactureFournisseur $invoice
 * @return int Order id or 0
 */
function auvorechnungsimport_linked_order_id($db, $invoice)
{
	// Both link directions (Dolibarr stores ours as invoice -> order).
	$invoice->fetchObjectLinked(null, '', null, '', 'OR', 1, 'sourcetype', 0);
	if (!empty($invoice->linkedObjectsIds['order_supplier'])) {
		return (int) reset($invoice->linkedObjectsIds['order_supplier']);
	}
	return 0;
}

/**
 * Create a supplier order mirroring a supplier invoice, so goods can be received
 * into stock. With the lot/serial module enabled, Dolibarr only allows stock
 * increases through a reception of a supplier order, not on a supplier invoice.
 *
 * The order gets the invoice's lines, is linked to the invoice, validated,
 * approved and set to "ordered" (so "Receive" is available right away) and
 * marked billed - the invoice already exists. If the user lacks the right for
 * a step, the order stays at the previous status and a warning is returned.
 *
 * @param DoliDB $db
 * @param User $user
 * @param FactureFournisseur $invoice With lines fetched
 * @param string $priceBase 'HT' or 'TTC' - as the invoice lines were entered
 * @param array<int,string> $warnings Receives non-fatal problems
 * @return int Order id, <0 on error (errors in $invoice->errors)
 */
function auvorechnungsimport_create_supplier_order($db, $user, $invoice, $priceBase, &$warnings)
{
	global $langs;
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$warnings = array();

	// Dolibarr refuses to validate an order with a product not "for purchase" -
	// check upfront and name them, instead of leaving a stuck draft behind.
	$notBuyable = array();
	foreach ($invoice->lines as $line) {
		if ($line->fk_product > 0) {
			$product = new Product($db);
			if ($product->fetch($line->fk_product) > 0 && empty($product->status_buy)) {
				$notBuyable[$product->id] = $product->getNomUrl(1);
			}
		}
	}
	if (!empty($notBuyable)) {
		$invoice->errors[] = $langs->trans('AuvoRechnungsimportErrNotBuyable', implode(', ', $notBuyable));
		return -1;
	}

	$order = new CommandeFournisseur($db);
	$order->socid = $invoice->socid;
	$order->ref_supplier = $invoice->ref_supplier;
	$order->date_commande = $invoice->date;
	$order->cond_reglement_id = $invoice->cond_reglement_id;
	$order->mode_reglement_id = $invoice->mode_reglement_id;
	$order->fk_project = $invoice->fk_project;
	$order->note_private = $langs->trans('AuvoRechnungsimportOrderCreatedFrom', $invoice->ref);
	$order->linked_objects = array('invoice_supplier' => $invoice->id);

	if ($order->create($user) <= 0) {
		$invoice->errors = array_merge($invoice->errors, $order->errors, array($order->error));
		return -1;
	}
	$order->fetch_thirdparty();
	foreach ($invoice->lines as $line) {
		$ttc = ($priceBase === 'TTC');
		$result = $order->addline(
			$line->desc,
			$ttc ? 0 : (float) $line->subprice,
			(float) $line->qty,
			$line->tva_tx,
			(float) $line->localtax1_tx,
			(float) $line->localtax2_tx,
			(int) $line->fk_product,
			0,
			(string) $line->ref_supplier,
			(float) $line->remise_percent,
			$ttc ? 'TTC' : 'HT',
			$ttc ? (float) $line->pu_ttc : 0,
			(int) $line->product_type,
			(int) $line->info_bits,
			0,
			$line->date_start ?: null,
			$line->date_end ?: null,
			array(),
			$line->fk_unit
		);
		if ($result <= 0) {
			$invoice->errors = array_merge($invoice->errors, $order->errors, array($order->error));
			return -1;
		}
	}

	if ($order->valid($user) < 0) {
		$warnings[] = $langs->trans('AuvoRechnungsimportOrderStepFailed', $langs->transnoentities('Validate'), $order->error);
		return $order->id;
	}
	if ($order->approve($user) < 0) {
		$warnings[] = $langs->trans('AuvoRechnungsimportOrderStepFailed', $langs->transnoentities('Approve'), $order->error);
		return $order->id;
	}
	$order->fetch($order->id);
	if ($order->commande($user, $invoice->date ?: dol_now(), 0) < 0) {
		$warnings[] = $langs->trans('AuvoRechnungsimportOrderStepFailed', $langs->transnoentities('SupplierOrderSubmitedBack'), $order->error);
		return $order->id;
	}
	if ($order->classifyBilled($user) < 0) {
		$warnings[] = $langs->trans('AuvoRechnungsimportOrderStepFailed', $langs->transnoentities('ClassifyBilled'), $order->error);
	}
	return $order->id;
}

/**
 * Copy the uploaded original PDF into the supplier invoice's documents.
 *
 * @param ImportedInvoice $object
 * @param FactureFournisseur $invoice
 * @return int 1 if copied, 0 if already there, <0 on error
 */
function auvorechnungsimport_attach_original($object, $invoice)
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
	$srcfile = auvorechnungsimport_resolve_file($object->filepath);
	if ($srcfile === false) {
		return -1;
	}
	$destdir = auvorechnungsimport_invoice_docdir($invoice);
	$destfile = $destdir.'/'.dol_sanitizeFileName($object->original_filename);
	dol_mkdir($destdir);
	// Files embedded in an e-invoice (BG-24: often the invoice as PDF,
	// timesheets...) are attached too; an existing file is never replaced.
	$inv = auvorechnungsimport_load_einvoice($srcfile);
	foreach ($inv !== null ? $inv['attachments'] : array() as $att) {
		$name = dol_sanitizeFileName($att['name']);
		if ($att['content'] === '' || $name === '' || is_file($destdir.'/'.$name)) {
			continue;
		}
		file_put_contents($destdir.'/'.$name, $att['content']);
		@chmod($destdir.'/'.$name, octdec(getDolGlobalString('MAIN_UMASK', '0664')));
	}
	if (is_file($destfile)) {
		return 0;
	}
	return dol_copy($srcfile, $destfile, '0', 1) > 0 ? 1 : -1;
}

/**
 * What would have to be created so that the line has its own variant of the
 * parent product - for lines whose variant doesn't exist yet.
 *
 *   a) Every attribute's value is named in the label, but that combination of
 *      values has no variant yet ("J6100 ... 825/grau XS" with XS never
 *      ordered in grau): create the variant.
 *   b) All attributes but one are named, and the label carries a new value in
 *      the supplier's "code/name" notation ("335/gelb" when the color
 *      attribute has no 335 yet): create that attribute value too. The code
 *      must look like the attribute's existing refs (digits if they are
 *      digits), so a size or random token isn't mistaken for a color.
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param string $label
 * @return ?array{attrs:array<int,array{id:int,ref:string,value:string,new:bool}>,ref:string,label:string}
 *         Attribute values in Dolibarr's attribute order (new ones with id 0), preview of ref and label
 */
function auvorechnungsimport_plan_variant($db, $fkParent, $label)
{
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	$r = auvorechnungsimport_resolve_variant($db, $fkParent, $label);
	if ($r['child'] || empty($r['resolved'])) {
		return null;
	}
	$attributes = auvorechnungsimport_variant_attribute_values($db, $fkParent);

	if (count($r['unresolved']) == 1) {
		$fkAttr = $r['unresolved'][0];
		// "335/gelb L": code before the slash, name = rest of the label minus the
		// words already used by the other attributes ("L").
		if (!preg_match('/(?:^|\s)([\p{L}\d]{1,6})\s*\/\s*(.+)$/u', trim($label), $m)) {
			return null;
		}
		$code = $m[1];
		$restWords = preg_split('/\s+/u', trim($m[2]));
		$usedWords = array();
		foreach ($r['used'] as $pos) {
			$usedWords[$r['words'][$pos]] = true;
		}
		$nameWords = array();
		foreach ($restWords as $w) {
			$norm = auvorechnungsimport_normalize_line_label($w);
			if ($norm !== '' && isset($usedWords[$norm])) {
				continue;
			}
			$nameWords[] = $w;
		}
		$name = trim(implode(' ', $nameWords));
		if ($name === '' || !preg_match('/\p{L}/u', $name) || dol_strlen($name) > 40) {
			return null;
		}
		// Code shaped like the attribute's existing refs (all digits vs. not)
		$existing = $attributes[$fkAttr]['values'] ?? array();
		$digitRefs = 0;
		foreach ($existing as $v) {
			$digitRefs += ctype_digit($v['ref']) ? 1 : 0;
		}
		if (!empty($existing) && (ctype_digit($code) xor ($digitRefs * 2 >= count($existing)))) {
			return null;
		}
		foreach ($existing as $v) {
			if (strtoupper($v['ref']) === strtoupper($code)) {
				return null; // code exists with another name: a conflict, not a new value
			}
		}
		$r['resolved'][$fkAttr] = array('id' => 0, 'ref' => strtoupper($code), 'value' => $name, 'new' => true);
	} elseif (!empty($r['unresolved'])) {
		return null;
	}

	$parent = new Product($db);
	if ($parent->fetch($fkParent) <= 0) {
		return null;
	}
	// Same ref/label scheme as the variants module powervariantes builds:
	// PARENT-VAL1-VAL2 / "Parent label (Value 1, Value 2)", attributes in order.
	$ordered = array();
	foreach (array_keys($attributes) as $fkAttr) {
		if (isset($r['resolved'][$fkAttr])) {
			$ordered[$fkAttr] = $r['resolved'][$fkAttr] + array('new' => false);
		}
	}
	$refs = array();
	$values = array();
	foreach ($ordered as $v) {
		$refs[] = strtoupper(trim($v['ref']));
		$values[] = $v['value'];
	}
	return array(
		'attrs' => $ordered,
		'ref' => $parent->ref.'-'.implode('-', $refs),
		'label' => $parent->label.' ('.implode(', ', $values).')',
	);
}

/**
 * Create a planned variant (see auvorechnungsimport_plan_variant()): new attribute
 * values first, then the variant itself - through the powervariantes module
 * when it is enabled (it defines this installation's ref/label scheme), else
 * with Dolibarr's own variant creation.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkParent
 * @param array{attrs:array<int,array{id:int,ref:string,value:string,new:bool}>} $plan
 * @param array<int,string> $errors Receives error messages
 * @return int fk_product of the new variant, <0 on error
 */
function auvorechnungsimport_create_variant($db, $user, $fkParent, $plan, &$errors)
{
	require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttributeValue.class.php';
	require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
	$errors = array();

	$valueIds = array();
	foreach ($plan['attrs'] as $fkAttr => $v) {
		if (!empty($v['new'])) {
			$value = new ProductAttributeValue($db);
			$value->fk_product_attribute = $fkAttr;
			$value->ref = $v['ref'];
			$value->value = $v['value'];
			if ($value->create($user) <= 0) {
				$errors = array_merge(array($value->error), $value->errors);
				return -1;
			}
			$valueIds[$fkAttr] = (int) $value->id;
		} else {
			$valueIds[$fkAttr] = (int) $v['id'];
		}
	}

	$parent = new Product($db);
	if ($parent->fetch($fkParent) <= 0) {
		$errors[] = 'Parent product not found';
		return -1;
	}
	if (isModEnabled('powervariantes') && is_readable(DOL_DOCUMENT_ROOT.'/custom/powervariantes/class/powervariantes.class.php')) {
		require_once DOL_DOCUMENT_ROOT.'/custom/powervariantes/class/powervariantes.class.php';
		$pv = new PowerVariantes($db);
		$selected = array();
		foreach ($valueIds as $fkAttr => $id) {
			$selected[$fkAttr] = array($id);
		}
		if ($pv->generateCombinationsFromValues($fkParent, $selected) <= 0) {
			$errors[] = $pv->error;
			return -1;
		}
	} else {
		$comb = new ProductCombination($db);
		if ($comb->createProductCombination($user, $parent, $valueIds, array()) < 0) {
			$errors = array_merge(array($comb->error), $comb->errors);
			return -1;
		}
	}

	// Find the new variant by its exact values.
	foreach (auvorechnungsimport_variant_combinations($db, $fkParent, true) as $fkChild => $attrs) {
		$ok = count($attrs) == count($valueIds);
		foreach ($valueIds as $fkAttr => $id) {
			$ok = $ok && isset($attrs[$fkAttr]) && $attrs[$fkAttr]['id'] === $id;
		}
		if ($ok) {
			// powervariantes computes the price but Product::create() doesn't store
			// it (variants come out at 0.00): sync price, weight etc. from the
			// parent + the combination's variation, as Dolibarr does itself when
			// the parent changes.
			$comb = new ProductCombination($db);
			if ($comb->fetchByFkProductChild($fkChild) > 0 && $comb->updateProperties($parent, $user) < 0) {
				$errors = array_merge(array($comb->error), $comb->errors);
				return -1;
			}
			return (int) $fkChild;
		}
	}
	$errors[] = 'Created variant not found';
	return -1;
}

/**
 * Lock file held by the background worker while it works through the queue.
 *
 * @return string
 */
function auvorechnungsimport_queue_lockfile()
{
	return DOL_DATA_ROOT.'/auvorechnungsimport/temp/queue.lock';
}

/** Acquire the queue lock without waiting. Caller must release the handle. */
function auvorechnungsimport_queue_acquire_lock()
{
	$lockfile = auvorechnungsimport_queue_lockfile();
	if (!is_dir(dirname($lockfile)) && dol_mkdir(dirname($lockfile)) < 0) {
		return false;
	}
	$fh = @fopen($lockfile, 'c');
	if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
		if ($fh) {
			fclose($fh);
		}
		return false;
	}
	return $fh;
}

/** Release a queue lock acquired with auvorechnungsimport_queue_acquire_lock(). */
function auvorechnungsimport_queue_release_lock($fh)
{
	if (is_resource($fh)) {
		flock($fh, LOCK_UN);
		fclose($fh);
	}
}

/**
 * Whether a background worker is currently running (holds the queue lock).
 *
 * @return bool
 */
function auvorechnungsimport_queue_is_running()
{
	$fh = auvorechnungsimport_queue_acquire_lock();
	if ($fh === false) {
		return true;
	}
	auvorechnungsimport_queue_release_lock($fh);
	return false;
}

/**
 * Work through all queued imports of the current entity, oldest first. Only
 * one worker at a time (queue lock); returns at once if another one runs.
 *
 * A record left at "processing" by a worker that died (e.g. a PHP fatal
 * error on a broken PDF) is set to error first - otherwise that same file
 * would kill every following worker run.
 *
 * @param DoliDB $db
 * @return int Number of imports analysed, -1 if another worker holds the lock
 */
function auvorechnungsimport_process_queue($db, $maxItems = 2)
{
	global $conf, $user;
	require_once dirname(__DIR__, 2).'/class/importedinvoice.class.php';
	require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
	$maxItems = max(1, (int) $maxItems);

	$fh = auvorechnungsimport_queue_acquire_lock();
	if ($fh === false) {
		return -1;
	}

	// Recover processing rows left behind by an interrupted previous Cron run.
	$sql = "UPDATE ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice SET status = ".ImportedInvoice::STATUS_ERROR;
	$sql .= ", note_private = CONCAT(COALESCE(note_private, ''), 'Analyse durch Cronlauf unterbrochen. ')";
	$sql .= " WHERE status = ".ImportedInvoice::STATUS_PROCESSING." AND entity = ".((int) $conf->entity);
	if (!$db->query($sql)) {
		auvorechnungsimport_queue_release_lock($fh);
		return -1;
	}

	$done = 0;
	$users = array();
	while ($done < $maxItems) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice";
		$sql .= " WHERE status = ".ImportedInvoice::STATUS_QUEUED." AND entity = ".((int) $conf->entity);
		$sql .= " ORDER BY rowid".$db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			break;
		}
		$object = new ImportedInvoice($db);
		if ($object->fetch((int) $obj->rowid) <= 0) {
			break;
		}
		if (!isset($users[$object->fk_user_creat])) {
			$users[$object->fk_user_creat] = new User($db);
			if ($users[$object->fk_user_creat]->fetch($object->fk_user_creat) <= 0) {
				$users[$object->fk_user_creat] = new User($db);
				$users[$object->fk_user_creat]->fetch(0, 'admin');
			}
		}
		$runAs = $users[$object->fk_user_creat];
		if (empty($runAs->id)) {
			dol_syslog('AuvoRechnungsimport queue: import '.$object->ref.' has no usable creator user', LOG_ERR);
			if (!empty($user->id)) {
				$object->status = ImportedInvoice::STATUS_ERROR;
				$object->note_private = trim((string) $object->note_private."\nAnalyse abgebrochen: Erstellerkonto nicht gefunden.");
				$object->update($user, 1);
			}
			$done++;
			continue;
		}

		$object->status = ImportedInvoice::STATUS_PROCESSING;
		if ($object->update($runAs, 1) <= 0) {
			dol_syslog('AuvoRechnungsimport queue: could not claim '.$object->ref, LOG_ERR);
			break;
		}

		$file = auvorechnungsimport_resolve_file($object->filepath);
		try {
			if ($file === false) {
				throw new Exception('file missing');
			}
			auvorechnungsimport_process_file($db, $object, $file, $runAs);
		} catch (Throwable $e) {
			$errorDetail = get_class($e).': '.$e->getMessage();
			dol_syslog('AuvoRechnungsimport queue: '.$object->ref.': '.$errorDetail, LOG_ERR);
			$object->status = ImportedInvoice::STATUS_ERROR;
			$object->note_private = trim((string) $object->note_private."\n[Analysefehler] ".$errorDetail);
		}
		if ((int) $object->status === ImportedInvoice::STATUS_ERROR && $object->getAnalysisError() === '') {
			$object->note_private = trim((string) $object->note_private."\n[Analysefehler] Keine verwertbaren Rechnungsdaten erkannt. Bitte prüfen Sie Dateiformat, PDF-Textebene und OCR-Konfiguration.");
		}
		if ($object->update($runAs, 1) <= 0) {
			dol_syslog('AuvoRechnungsimport queue: could not save result '.$object->ref, LOG_ERR);
		}
		$done++;
	}

	auvorechnungsimport_queue_release_lock($fh);
	return $done;
}

/**
 * Next import waiting for review (oldest first), after $afterId if possible -
 * to go through a batch one by one.
 *
 * @param DoliDB $db
 * @param int $afterId Current record; 0 = the first one
 * @return int Record id, 0 if none left
 */
function auvorechnungsimport_next_to_review($db, $afterId)
{
	require_once dirname(__DIR__, 2).'/class/importedinvoice.class.php';
	$base = "SELECT rowid FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice";
	$base .= " WHERE status IN (".ImportedInvoice::STATUS_NEEDS_REVIEW.", ".ImportedInvoice::STATUS_MATCHED.")";
	$base .= " AND entity IN (".getEntity('importedinvoice').") AND rowid <> ".((int) $afterId);
	foreach (array(" AND rowid > ".((int) $afterId), "") as $after) {
		$resql = $db->query($base.$after." ORDER BY rowid".$db->plimit(1));
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
	}
	return 0;
}

/**
 * Line handling modes:
 *   book - take over: with the matched/chosen product, else as free text line
 *   cost - book without product (free text cost line), e.g. shipping costs
 *   skip - leave out, e.g. free promotional material
 * Only "skip" changes the invoice total; the review form warns about it.
 */
const AUVORECHNUNGSIMPORT_LINE_MODES = array('book', 'cost', 'skip');

/**
 * Keywords from the setup that pre-select a mode for all suppliers.
 *
 * @return array<string,array<int,string>> mode => keywords
 */
function auvorechnungsimport_line_mode_keywords()
{
	$rules = array();
	foreach (array('cost' => 'AUVORECHNUNGSIMPORT_KEYWORDS_COST', 'skip' => 'AUVORECHNUNGSIMPORT_KEYWORDS_SKIP') as $mode => $const) {
		$rules[$mode] = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', getDolGlobalString($const))), 'strlen'));
	}
	return $rules;
}

/**
 * Mode for a line, with where it came from ('learned', 'keyword' or '').
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param array<string,mixed> $line
 * @param bool $hasProduct A product was matched for the line
 * @return array{0:string,1:string}
 */
function auvorechnungsimport_line_mode($db, $fkSoc, $line, $hasProduct)
{
	$labelKey = auvorechnungsimport_normalize_line_label($line['label'] ?? '');
	if ($fkSoc > 0 && $labelKey !== '') {
		$sql = "SELECT key_type FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap";
		$sql .= " WHERE fk_soc = ".((int) $fkSoc)." AND key_type IN ('mode_cost', 'mode_skip')";
		$sql .= " AND match_key = '".$db->escape($labelKey)."' AND entity IN (".getEntity('auvorechnungsimport').")";
		$resql = $db->query($sql.$db->plimit(1));
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return array(substr($obj->key_type, 5), 'learned');
		}
	}
	if ($hasProduct) {
		return array('book', '');
	}
	// Skip before cost: "Versandkosten" in a skip list is the more specific wish.
	$keywords = auvorechnungsimport_line_mode_keywords();
	foreach (array('skip', 'cost') as $mode) {
		foreach ($keywords[$mode] as $keyword) {
			if (preg_match('/(?<![\p{L}\d])'.preg_quote($keyword, '/').'/iu', (string) ($line['label'] ?? ''))) {
				return array($mode, 'keyword');
			}
		}
	}
	return array('book', '');
}

/**
 * Remember the mode chosen for a line (per supplier and normalized label).
 * Stored in the line map with fk_product = 0, so it never counts as a product
 * mapping. Choosing "book" again forgets an earlier cost/skip choice.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkSoc
 * @param array<string,mixed> $line
 * @param string $mode
 * @return int <0 on error
 */
function auvorechnungsimport_learn_line_mode($db, $user, $fkSoc, $line, $mode)
{
	global $conf;
	$labelKey = auvorechnungsimport_normalize_line_label($line['label'] ?? '');
	if ($fkSoc <= 0 || $labelKey === '') {
		return 0;
	}
	$where = " WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $fkSoc);
	$where .= " AND key_type IN ('mode_cost', 'mode_skip') AND match_key = '".$db->escape($labelKey)."'";
	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap".$where)) {
		return -1;
	}
	if ($mode === 'cost' || $mode === 'skip') {
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."auvorechnungsimport_linemap";
		$sql .= " (entity, fk_soc, key_type, match_key, fk_product, nb_used, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fkSoc).", 'mode_".$mode."', '".$db->escape($labelKey)."', 0, 1, '".$db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$db->query($sql)) {
			return -1;
		}
	}
	return 1;
}
