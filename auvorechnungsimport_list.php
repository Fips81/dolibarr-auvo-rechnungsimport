<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Upload a supplier invoice PDF and list past imports.
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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/class/importedinvoice.class.php';
require_once __DIR__.'/core/lib/auvorechnungsimport.lib.php';

$langs->loadLangs(array('auvorechnungsimport@auvorechnungsimport', 'companies', 'other'));

if (!isModEnabled('auvorechnungsimport')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('auvorechnungsimport', 'imports', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'upload' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	// Several PDFs or XML e-invoices at once: each is only stored and queued here - the analysis
	// (text layer, OCR, matching) runs in the background worker, so the upload
	// returns at once and isn't bound to the web request's time limit.
	$files = array();
	if (!empty($_FILES['invoicefiles']['name']) && is_array($_FILES['invoicefiles']['name'])) {
		foreach ($_FILES['invoicefiles']['name'] as $k => $name) {
			$files[] = array('name' => $name, 'tmp_name' => $_FILES['invoicefiles']['tmp_name'][$k], 'error' => $_FILES['invoicefiles']['error'][$k]);
		}
	}
	$queued = 0;
	$errors = array();
	foreach ($files as $file) {
		if ($file['error'] == UPLOAD_ERR_NO_FILE) {
			continue;
		}
		$origname = dol_sanitizeFileName($file['name']);
		if ($file['error'] != UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			$errors[] = $langs->trans('AuvoRechnungsimportErrUploadFile', $origname, $file['error']);
			continue;
		}
		if (!in_array(strtolower(pathinfo($origname, PATHINFO_EXTENSION)), array('pdf', 'xml'), true)) {
			$errors[] = $langs->trans('AuvoRechnungsimportErrNotPdfFile', $origname);
			continue;
		}
		$object = new ImportedInvoice($db);
		$object->date_creation = dol_now();
		$object->fk_user_creat = $user->id;
		$object->original_filename = $origname;
		$object->filepath = '';
		$object->status = ImportedInvoice::STATUS_QUEUED;

		$db->begin();
		$ok = $object->create($user) > 0;
		if ($ok) {
			$destdir = DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->id;
			dol_mkdir($destdir);
			$ok = dol_move_uploaded_file($file['tmp_name'], $destdir.'/'.$origname, 1, 0, $file['error']) === 1;
			if ($ok) {
				$object->filepath = $object->id.'/'.$origname;
				$ok = $object->update($user) > 0;
			}
		}
		if ($ok) {
			$db->commit();
			$queued++;
		} else {
			$db->rollback();
			$errors[] = $langs->trans('AuvoRechnungsimportErrUploadFile', $origname, '');
		}
	}
	if ($queued) {
		$started = auvorechnungsimport_queue_start();
		setEventMessages($langs->trans($started ? 'AuvoRechnungsimportQueued' : 'AuvoRechnungsimportQueuedNotStarted', $queued), null, $started ? 'mesgs' : 'warnings');
	}
	if ($errors) {
		setEventMessages(null, $errors, 'errors');
	}
	if (!$queued && !$errors) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrNoFile'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].(GETPOST('mode', 'aZ09') === 'upload' ? '?mode=upload' : ''));
	exit;
}

if ($action == 'startqueue' && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	if (!auvorechnungsimport_queue_start()) {
		setEventMessages($langs->trans('AuvoRechnungsimportQueueStartFailed'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].(GETPOST('mode', 'aZ09') === 'upload' ? '?mode=upload' : ''));
	exit;
}

// Views of the left menu: all, upload, or one status group.
$mode = GETPOST('mode', 'aZ09');
$searchStatus = GETPOST('search_status', 'intcomma');
$views = array(
	ImportedInvoice::STATUS_NEEDS_REVIEW.','.ImportedInvoice::STATUS_MATCHED => 'AuvoRechnungsimportFilterToReview',
	ImportedInvoice::STATUS_QUEUED.','.ImportedInvoice::STATUS_PROCESSING => 'AuvoRechnungsimportStatusQueued',
	(string) ImportedInvoice::STATUS_ERROR => 'AuvoRechnungsimportStatusError',
	(string) ImportedInvoice::STATUS_IMPORTED => 'AuvoRechnungsimportStatusImported',
);
if ($searchStatus !== '' && !isset($views[$searchStatus])) {
	$searchStatus = '';
}

$counts = array();
$resql = $db->query("SELECT status, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice WHERE entity IN (".getEntity('importedinvoice').") GROUP BY status");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$counts[(int) $obj->status] = (int) $obj->nb;
}
$countOf = function ($statusList) use ($counts) {
	$n = 0;
	foreach (explode(',', $statusList) as $st) {
		$n += $counts[(int) $st] ?? 0;
	}
	return $n;
};

if ($mode === 'upload') {
	$title = $langs->trans('AuvoRechnungsimportMenuUpload');
} elseif ($searchStatus !== '') {
	$title = $langs->trans($views[$searchStatus]).' ('.$countOf($searchStatus).')';
} else {
	$title = $langs->trans('ModuleAuvoRechnungsimportName');
}

llxHeader('', $title);

print load_fiche_titre($title, '', 'fa-file-invoice');

// Upload form on the upload view and the overview - not in the status lists
if (($mode === 'upload' || $searchStatus === '') && $user->hasRight('auvorechnungsimport', 'imports', 'write')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data" class="marginbottomonly">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="upload">';
	print '<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('AuvoRechnungsimportUpload').'</td></tr>';
	print '<tr class="oddeven"><td>';
	print '<input type="file" name="invoicefiles[]" accept="application/pdf,.pdf,application/xml,text/xml,.xml" multiple required> ';
	print '<input type="submit" class="button" value="'.$langs->trans('AuvoRechnungsimportUploadBtn').'">';
	// The web server's upload limits are what usually stops a big batch.
	print '<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportUploadLimits', (int) ini_get('max_file_uploads'), dol_escape_htmltag(ini_get('upload_max_filesize')), dol_escape_htmltag(ini_get('post_max_size'))).'</span>';
	print '</td></tr></table></div>';
	print '</form>';
}

// Queue state: refresh the list by itself while invoices are being analysed.
$pending = ($counts[ImportedInvoice::STATUS_QUEUED] ?? 0) + ($counts[ImportedInvoice::STATUS_PROCESSING] ?? 0);
if ($pending > 0) {
	$running = auvorechnungsimport_queue_is_running();
	print '<div class="'.($running ? 'info' : 'warning').'">';
	if ($running) {
		print '<span class="fas fa-spinner fa-spin paddingright"></span>'.$langs->trans('AuvoRechnungsimportQueueRunning', $pending);
		// Reload while the worker runs - but not while the user is choosing files.
		print '<script>setTimeout(function () { if (!document.querySelector(\'input[type=file]\') || !document.querySelector(\'input[type=file]\').value) { location.reload(); } }, 5000);</script>';
	} else {
		print $langs->trans('AuvoRechnungsimportQueueStopped', $pending);
		if ($user->hasRight('auvorechnungsimport', 'imports', 'write')) {
			print ' <a class="button smallpaddingimp" href="'.$_SERVER['PHP_SELF'].'?action=startqueue&token='.newToken().($mode === 'upload' ? '&mode=upload' : '').'">'.$langs->trans('AuvoRechnungsimportQueueStartBtn').'</a>';
		}
	}
	print '</div>';
}

$toReview = $countOf(ImportedInvoice::STATUS_NEEDS_REVIEW.','.ImportedInvoice::STATUS_MATCHED);
if ($toReview > 0) {
	print '<div class="marginbottomonly"><a class="button" href="'.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.auvorechnungsimport_next_to_review($db, 0).'">'.$langs->trans('AuvoRechnungsimportReviewNextBtn', $toReview).'</a></div>';
}

if ($mode === 'upload') {
	// Upload view: form and queue state only
	llxFooter();
	$db->close();
	exit;
}

$filter = '';
if ($searchStatus !== '') {
	$filter = 't.status IN ('.$db->sanitize($searchStatus).')';
}
$object = new ImportedInvoice($db);
$records = $object->fetchAll('DESC', 't.rowid', 200, 0, $filter);

print '<div class="div-table-responsive"><table class="noborder centpercent liste">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Ref').'</td>';
print '<td>'.$langs->trans('AuvoRechnungsimportOriginalFilename').'</td>';
print '<td>'.$langs->trans('DateCreation').'</td>';
print '<td>'.$langs->trans('AuvoRechnungsimportExtractionMethod').'</td>';
print '<td>'.$langs->trans('ThirdParty').'</td>';
print '<td>'.$langs->trans('RefSupplier').'</td>';
print '<td class="right">'.$langs->trans('AmountTTC').'</td>';
print '<td class="center">'.$langs->trans('AuvoRechnungsimportLinesCol').'</td>';
print '<td class="right">'.$langs->trans('Status').'</td>';
print '</tr>';

if (is_array($records) && count($records) > 0) {
	foreach ($records as $rec) {
		print '<tr class="oddeven">';
		print '<td>'.$rec->getNomUrl(1).'</td>';
		print '<td>'.dol_escape_htmltag($rec->original_filename).'</td>';
		print '<td>'.dol_print_date($rec->date_creation, 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag($rec->extraction_method).'</td>';
		print '<td>';
		if ($rec->fk_soc > 0) {
			$soc = new Societe($db);
			if ($soc->fetch($rec->fk_soc) > 0) {
				print $soc->getNomUrl(1);
			}
		} else {
			print '<span class="opacitymedium">'.$langs->trans('AuvoRechnungsimportNoSupplierMatch').'</span>';
		}
		print '</td>';
		$data = $rec->getExtractedData();
		print '<td>'.dol_escape_htmltag($data['invoice_ref'] ?? '').'</td>';
		print '<td class="right nowraponall">'.(isset($data['amount_ttc']) && $data['amount_ttc'] !== null ? price($data['amount_ttc']) : '').'</td>';
		print '<td class="center">';
		if (!empty($data['lines'])) {
			print count($data['lines']);
			if (($data['lines_check'] ?? '') === 'mismatch') {
				print ' '.img_warning($langs->trans('AuvoRechnungsimportLinesSumMismatchShort'));
			}
		}
		print '</td>';
		print '<td class="right">'.$rec->getLibStatut(1).'</td>';
		print '</tr>';
	}
} else {
	print '<tr><td colspan="9" class="opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
}
print '</table></div>';

llxFooter();
