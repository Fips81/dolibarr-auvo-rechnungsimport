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
$searchStatus = GETPOST('search_status', 'intcomma');

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
	$duplicates = array();
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
		$fileHash = strtolower(pathinfo($origname, PATHINFO_EXTENSION)) === 'pdf' ? hash_file('sha256', $file['tmp_name']) : null;
		if (strtolower(pathinfo($origname, PATHINFO_EXTENSION)) === 'pdf' && $fileHash === false) {
			$errors[] = $langs->trans('AuvoRechnungsimportErrHashFile', $origname);
			continue;
		}
		$fileMd5 = strtolower(pathinfo($origname, PATHINFO_EXTENSION)) === 'pdf' ? hash_file('md5', $file['tmp_name']) : null;
		$hashDuplicate = $fileHash ? auvorechnungsimport_find_duplicate_by_hash($db, $fileHash, 0, $fileMd5 ?: '') : null;
		if ($hashDuplicate) {
			$target = auvorechnungsimport_duplicate_target_link($hashDuplicate);
			$duplicates[] = array('filename' => $origname, 'target_url' => $target['url'], 'target_label' => $target['label']);
			continue;
		}
		$object = new ImportedInvoice($db);
		$object->date_creation = dol_now();
		$object->fk_user_creat = $user->id;
		$object->original_filename = $origname;
		$object->source = 'upload';
		$object->filepath = '';
		$object->file_hash = $fileHash;
		$object->status = ImportedInvoice::STATUS_QUEUED;
		$destdir = '';
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
			if ($ok && auvorechnungsimport_blockedlog_store_received_file($db, $object, $destdir.'/'.$origname, $user, 'upload') < 0) {
				$ok = false;
			}
		}
		if ($ok) {
			$db->commit();
			$queued++;
		} else {
			$db->rollback();
			if (!empty($destdir) && is_file($destdir.'/'.$origname)) @unlink($destdir.'/'.$origname);
			$errors[] = $langs->trans('AuvoRechnungsimportErrUploadFile', $origname, '');
		}
	}
	if ($queued) {
		setEventMessages($langs->trans('AuvoRechnungsimportQueuedCron', $queued), null, 'mesgs');
	}
	if ($duplicates) $_SESSION['auvorechnungsimport_upload_duplicates'] = $duplicates;
	if ($errors) {
		setEventMessages(null, $errors, 'errors');
	}
	if (!$queued && !$duplicates && !$errors) {
		setEventMessages($langs->trans('AuvoRechnungsimportErrNoFile'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].(GETPOST('mode', 'aZ09') === 'upload' ? '?mode=upload' : ''));
	exit;
}

if ($action == 'deleteimport' && $user->hasRight('auvorechnungsimport', 'imports', 'delete')) {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	$id = GETPOSTINT('id');
	$record = new ImportedInvoice($db);
	if ($id <= 0 || $record->fetch($id) <= 0) {
		accessforbidden('Not found');
	}
	if (in_array((int) $record->status, array(ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_SPAM_CONFIRMED), true)) {
		setEventMessages($langs->trans('AuvoRechnungsimportIrrelevantDeleteBlocked'), null, 'errors');
		$destination = ((int) $record->status === ImportedInvoice::STATUS_SPAM_REVIEW) ? (string) ImportedInvoice::STATUS_SPAM_REVIEW : ImportedInvoice::STATUS_DUPLICATE.','.ImportedInvoice::STATUS_SPAM_CONFIRMED;
		header('Location: '.$_SERVER['PHP_SELF'].'?search_status='.$destination);
		exit;
	}
	$isQueueRecord = in_array((int) $record->status, array(ImportedInvoice::STATUS_QUEUED, ImportedInvoice::STATUS_PROCESSING), true);
	$queueLock = $isQueueRecord ? auvorechnungsimport_queue_acquire_lock() : null;
	if ($isQueueRecord && $queueLock === false) {
		setEventMessages($langs->trans('AuvoRechnungsimportDeleteQueueRunning'), null, 'errors');
	} else {
		$file = auvorechnungsimport_resolve_file($record->filepath);
		$db->begin();
		$result = $record->delete($user);
		$deleteLogResult = $result > 0 ? auvorechnungsimport_blockedlog_log_import_deleted($db, $record, $user, $file) : 0;
		if ($result > 0 && $deleteLogResult > 0) {
			$db->commit();
			if ($file !== false) {
				dol_delete_file($file, 1);
				dol_delete_dir(dirname($file), 1);
			}
			setEventMessages($langs->trans('AuvoRechnungsimportImportDeleted'), null, 'mesgs');
		} else {
			$db->rollback();
			if ($result > 0 && $deleteLogResult <= 0) {
				setEventMessages($langs->trans('AuvoRechnungsimportErrDeleteLog'), null, 'errors');
			} else {
				setEventMessages($langs->trans('AuvoRechnungsimportErrDelete'), $record->errors, 'errors');
			}
		}
		if ($queueLock !== null) {
			auvorechnungsimport_queue_release_lock($queueLock);
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].(!empty($searchStatus) ? '?search_status='.urlencode($searchStatus) : ''));
	exit;
}

// Views of the left menu: all, upload, or one status group.
$mode = GETPOST('mode', 'aZ09');
$uploadDuplicates = array();
if (!empty($_SESSION['auvorechnungsimport_upload_duplicates'])) {
	$uploadDuplicates = $_SESSION['auvorechnungsimport_upload_duplicates'];
	unset($_SESSION['auvorechnungsimport_upload_duplicates']);
}
$views = array(
	ImportedInvoice::STATUS_NEEDS_REVIEW.','.ImportedInvoice::STATUS_MATCHED => 'AuvoRechnungsimportFilterToReview',
	ImportedInvoice::STATUS_QUEUED.','.ImportedInvoice::STATUS_PROCESSING => 'AuvoRechnungsimportStatusQueued',
	(string) ImportedInvoice::STATUS_SPAM_REVIEW => 'AuvoRechnungsimportStatusSpamReview',
	ImportedInvoice::STATUS_DUPLICATE.','.ImportedInvoice::STATUS_SPAM_CONFIRMED => 'AuvoRechnungsimportMenuIrrelevant',
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

if ($uploadDuplicates) {
	print '<div id="upload-duplicates-dialog" title="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportDuplicateUploadModalTitle')).'" style="display:none">';
	print '<p>'.$langs->trans('AuvoRechnungsimportDuplicateUploadModalIntro', count($uploadDuplicates)).'</p><ul>';
	foreach ($uploadDuplicates as $duplicate) {
		print '<li><strong>'.dol_escape_htmltag($duplicate['filename']).'</strong>';
		if (!empty($duplicate['target_url'])) {
			print ' &mdash; <a href="'.dol_escape_htmltag($duplicate['target_url']).'">'.dol_escape_htmltag($duplicate['target_label']).'</a>';
		} elseif (!empty($duplicate['target_label'])) {
			print ' &mdash; '.dol_escape_htmltag($duplicate['target_label']);
		}
		print '</li>';
	}
	print '</ul></div>';
	print '<script>jQuery(function(){jQuery("#upload-duplicates-dialog").dialog({modal:true,width:Math.min(700,window.innerWidth-40),maxHeight:window.innerHeight-80,buttons:[{text:"'.dol_escape_js($langs->transnoentitiesnoconv('Close')).'",click:function(){jQuery(this).dialog("close");}}]});});</script>';
}

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
$running = false;
if ($pending > 0) {
	$running = auvorechnungsimport_queue_is_running();
	print '<div class="'.($running ? 'info' : 'warning').'">';
	if ($running) {
		print '<span class="fas fa-spinner fa-spin paddingright"></span>'.$langs->trans('AuvoRechnungsimportQueueRunning', $pending);
		// Reload while the Cron worker runs - but not while the user is choosing files.
		print '<script>setTimeout(function () { if (!document.querySelector(\'input[type=file]\') || !document.querySelector(\'input[type=file]\').value) { location.reload(); } }, 5000);</script>';
	} else {
		print $langs->trans('AuvoRechnungsimportQueueWaitingCron', $pending);
	}

	print '</div>';
}

$toReview = $countOf(ImportedInvoice::STATUS_NEEDS_REVIEW.','.ImportedInvoice::STATUS_MATCHED);
if ($toReview > 0) {
	print '<div class="marginbottomonly"><a class="button" href="'.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.auvorechnungsimport_next_to_review($db, 0).'">'.$langs->trans('AuvoRechnungsimportReviewNextBtn', $toReview).'</a></div>';
}
$spamToReview = $countOf((string) ImportedInvoice::STATUS_SPAM_REVIEW);
if ($spamToReview > 0) {
	print '<div class="marginbottomonly"><a class="button buttonDelete" href="'.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.auvorechnungsimport_next_to_review($db, 0, true).'">'.$langs->trans('AuvoRechnungsimportReviewSpamBtn', $spamToReview).'</a></div>';
}

if ($mode === 'upload') {
	// Upload view: form and queue state only
	llxFooter();
	$db->close();
	exit;
}

$searchRef = trim(GETPOST('search_ref', 'alphanohtml'));
$searchFilename = trim(GETPOST('search_filename', 'alphanohtml'));
$searchMethod = trim(GETPOST('search_method', 'alphanohtml'));
$searchSupplier = trim(GETPOST('search_supplier', 'alphanohtml'));
$searchInvoiceRef = trim(GETPOST('search_invoice_ref', 'alphanohtml'));
$searchAmount = trim(GETPOST('search_amount', 'alphanohtml'));
$searchSource = GETPOST('search_source', 'aZ09');
$filterStatus = GETPOSTINT('filter_status');
$clearFilters = GETPOST('button_removefilter_x', 'alpha') !== '';
if ($clearFilters) {
	$searchRef = $searchFilename = $searchMethod = $searchSupplier = $searchInvoiceRef = $searchAmount = '';
	$searchSource = '';
	$filterStatus = 0;
}
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09'));
$sortFields = array('t.ref', 't.original_filename', 't.date_creation', 't.extraction_method', 't.source', 't.status');
if (!in_array($sortfield, $sortFields, true)) $sortfield = 't.date_creation';
if (!in_array($sortorder, array('ASC', 'DESC'), true)) $sortorder = 'DESC';
$page = max(0, GETPOSTINT('page'));
$limit = $conf->liste_limit > 0 ? (int) $conf->liste_limit : 25;
$offset = $page * $limit;

$filterParts = array();
if ($searchStatus !== '') $filterParts[] = 't.status IN ('.$db->sanitize($searchStatus).')';
if ($filterStatus > 0) $filterParts[] = 't.status = '.(int) $filterStatus;
if (in_array($searchSource, array('upload', 'emailcollector', 'unknown'), true)) {
	$filterParts[] = "t.source = '".$db->escape($searchSource)."'";
}
$addLikeFilter = function ($field, $value) use (&$filterParts, $db) {
	if ($value !== '') $filterParts[] = $field." LIKE '%".$db->escape($value)."%'";
};
$addLikeFilter('t.ref', $searchRef);
$addLikeFilter('t.original_filename', $searchFilename);
$addLikeFilter('t.extraction_method', $searchMethod);
$addLikeFilter('t.extracted_data', $searchSupplier);
$addLikeFilter('t.extracted_data', $searchInvoiceRef);
$addLikeFilter('t.extracted_data', $searchAmount);
$filter = implode(' AND ', $filterParts);

$contextpage = 'auvorechnungsimportlist';
$object = new ImportedInvoice($db);
if (GETPOSTISSET('formfilteraction')) {
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';
}
$countSql = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.$object->table_element.' as t WHERE t.entity IN ('.getEntity($object->element).')'.($filter !== '' ? ' AND ('.$filter.')' : '');
$countRes = $db->query($countSql);
$totalRecords = 0;
if ($countRes && ($countObj = $db->fetch_object($countRes))) $totalRecords = (int) $countObj->nb;
$records = $object->fetchAll($sortorder, $sortfield, $limit, $offset, $filter);

$arrayfields = array(
	'ref' => array('label' => 'Ref', 'checked' => '1', 'position' => 10),
	'filename' => array('label' => 'AuvoRechnungsimportOriginalFilename', 'checked' => '1', 'position' => 20),
	'date' => array('label' => 'DateCreation', 'checked' => '1', 'position' => 30),
	'method' => array('label' => 'AuvoRechnungsimportExtractionMethod', 'checked' => '1', 'position' => 40),
	'source' => array('label' => 'AuvoRechnungsimportSource', 'checked' => '1', 'position' => 50),
	'file_hash' => array('label' => 'AuvoRechnungsimportFileHash', 'checked' => '0', 'position' => 55),
	'supplier' => array('label' => 'ThirdParty', 'checked' => '1', 'position' => 60),
	'invoice_ref' => array('label' => 'RefSupplier', 'checked' => '1', 'position' => 70),
	'amount' => array('label' => 'AmountTTC', 'checked' => '1', 'position' => 80),
	'lines' => array('label' => 'AuvoRechnungsimportLinesCol', 'checked' => '1', 'position' => 90),
	'status' => array('label' => 'Status', 'checked' => '1', 'position' => 100),
	'reminder_count' => array('label' => 'AuvoRechnungsimportReminderCount', 'checked' => '0', 'position' => 110),
	'last_reminder' => array('label' => 'AuvoRechnungsimportLastReminder', 'checked' => '0', 'position' => 120),
);
$varpage = 'auvorechnungsimportlist';
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, $conf->main_checkbox_left_column);
$param = '&search_status='.urlencode($searchStatus).'&filter_status='.$filterStatus.'&search_ref='.urlencode($searchRef).'&search_filename='.urlencode($searchFilename).'&search_method='.urlencode($searchMethod).'&search_supplier='.urlencode($searchSupplier).'&search_invoice_ref='.urlencode($searchInvoiceRef).'&search_amount='.urlencode($searchAmount).'&search_source='.urlencode($searchSource);
$filterUrl = $_SERVER['PHP_SELF'].'?mode='.urlencode($mode).$param;
print '<form id="importFilterForm" method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';
print '<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">';
if ($searchStatus !== '') print '<input type="hidden" name="search_status" value="'.dol_escape_htmltag($searchStatus).'">';
print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, $selectedfields, count($records), $totalRecords, 'object_'.$object->picto, 0, '', '', $limit, 0, 0, 1);
print '<div class="liste_titre liste_titre_bydiv centpercent">';
print '<div class="divsearchfield">'.img_picto($langs->trans('AuvoRechnungsimportSource'), 'filter', 'class="pictofixedwidth"').$langs->trans('AuvoRechnungsimportSource').' '.$form->selectarray('search_source', array('' => '', 'upload' => $langs->trans('AuvoRechnungsimportSourceUpload'), 'emailcollector' => $langs->trans('AuvoRechnungsimportSourceEmail'), 'unknown' => $langs->trans('AuvoRechnungsimportSourceUnknown')), $searchSource, 1).'</div>';
print '</div>';
print '</form>';
print '<div class="div-table-responsive"><table class="tagtable nobottomiftotal liste listwithfilterbefore">';
print '<tr class="liste_titre_filter">';
$filterNames = array('ref' => 'search_ref', 'filename' => 'search_filename', 'method' => 'search_method', 'supplier' => 'search_supplier', 'invoice_ref' => 'search_invoice_ref', 'amount' => 'search_amount');

$columnTitles = array(
	'ref' => array($langs->trans('Ref'), 't.ref'),
	'filename' => array($langs->trans('AuvoRechnungsimportOriginalFilename'), 't.original_filename'),
	'date' => array($langs->trans('DateCreation'), 't.date_creation'),
	'method' => array($langs->trans('AuvoRechnungsimportExtractionMethod'), 't.extraction_method'),
	'source' => array($langs->trans('AuvoRechnungsimportSource'), 't.source'),
	'file_hash' => array($langs->trans('AuvoRechnungsimportFileHash'), 't.file_hash'),
	'supplier' => array($langs->trans('ThirdParty'), ''),
	'invoice_ref' => array($langs->trans('RefSupplier'), ''),
	'amount' => array($langs->trans('AmountTTC'), ''),
	'lines' => array($langs->trans('AuvoRechnungsimportLinesCol'), ''),
	'status' => array($langs->trans('Status'), 't.status'),
	'reminder_count' => array($langs->trans('AuvoRechnungsimportReminderCount'), 't.reminder_count'),
	'last_reminder' => array($langs->trans('AuvoRechnungsimportLastReminder'), 't.last_reminder'),
);
foreach ($columnTitles as $key => $column) {
	if (empty($arrayfields[$key]['checked'])) continue;
	print '<td class="liste_titre">';
	if ($key === 'ref') {
		print '<button class="button_search reposition" type="submit" form="importFilterForm" name="button_search_x" value="x" title="'.dol_escape_htmltag($langs->trans('Search')).'"><span class="fas fa-search"></span></button> ';
		print '<button class="button_removefilter reposition" type="submit" form="importFilterForm" name="button_removefilter_x" value="x" title="'.dol_escape_htmltag($langs->trans('Reset')).'"><span class="fas fa-times"></span></button> ';
	}
	if ($key === 'status') {
		print $form->selectarray('filter_status', array('' => '', (string) ImportedInvoice::STATUS_QUEUED => $langs->trans('AuvoRechnungsimportStatusQueued'), (string) ImportedInvoice::STATUS_SPAM_REVIEW => $langs->trans('AuvoRechnungsimportStatusSpamReview'), ImportedInvoice::STATUS_DUPLICATE.','.ImportedInvoice::STATUS_SPAM_CONFIRMED => $langs->trans('AuvoRechnungsimportMenuIrrelevant'), (string) ImportedInvoice::STATUS_NEEDS_REVIEW => $langs->trans('AuvoRechnungsimportStatusNeedsReview'), (string) ImportedInvoice::STATUS_MATCHED => $langs->trans('AuvoRechnungsimportStatusMatched'), (string) ImportedInvoice::STATUS_IMPORTED => $langs->trans('AuvoRechnungsimportStatusImported'), (string) ImportedInvoice::STATUS_ERROR => $langs->trans('AuvoRechnungsimportStatusError')), $filterStatus, 1, 0, 0, 'form="importFilterForm"', 0, 0, 0, '', 'maxwidth100', 1);
	} elseif (isset($filterNames[$key])) {
		$name = $filterNames[$key];
		$value = $key === 'ref' ? $searchRef : ($key === 'filename' ? $searchFilename : ($key === 'method' ? $searchMethod : ($key === 'supplier' ? $searchSupplier : ($key === 'invoice_ref' ? $searchInvoiceRef : $searchAmount))));
		print '<input class="flat searchstring maxwidth100" form="importFilterForm" type="text" name="'.$name.'" value="'.dol_escape_htmltag($value).'">';
	}
	print '</td>';
}
if ($user->hasRight('auvorechnungsimport', 'imports', 'delete')) print '<td class="liste_titre center"></td>';
print '</tr>';
print '<tr class="liste_titre">';
foreach ($columnTitles as $key => $column) {
	if (empty($arrayfields[$key]['checked'])) continue;
	if ($column[1] !== '') {
		print_liste_field_titre($column[0], $_SERVER['PHP_SELF'], $column[1], '', $param, '', $sortfield, $sortorder, in_array($key, array('amount', 'status'), true) ? 'right' : '');
	} else {
		print '<td'.(in_array($key, array('amount', 'status'), true) ? ' class="right"' : '').'>'.$column[0].'</td>';
	}
}
if ($user->hasRight('auvorechnungsimport', 'imports', 'delete')) print '<td class="center">'.$langs->trans('Actions').'</td>';
print '</tr>';

if (is_array($records) && count($records) > 0) {
	foreach ($records as $rec) {
		$row = array();
		$row['ref'] = $rec->getNomUrl(1);
		$row['filename'] = dol_escape_htmltag($rec->original_filename);
		$row['date'] = dol_print_date($rec->date_creation, 'dayhour');
		$row['method'] = dol_escape_htmltag($rec->extraction_method);
		$source = strtolower((string) ($rec->source ?? 'unknown'));
		$sourceLabel = $source === 'emailcollector' ? 'AuvoRechnungsimportSourceEmail' : ($source === 'upload' ? 'AuvoRechnungsimportSourceUpload' : 'AuvoRechnungsimportSourceUnknown');
		$row['source'] = $langs->trans($sourceLabel);
		$row['file_hash'] = !empty($rec->file_hash) ? '<code class="wordbreak">'.dol_escape_htmltag($rec->file_hash).'</code>' : '';
		$data = $rec->getExtractedData();
		$row['supplier'] = '';
		$invoiceSupplierId = 0;
		$linkedInvoiceId = 0;
		$linkedInvoiceRef = '';
		if (in_array((int) $rec->status, array(ImportedInvoice::STATUS_IMPORTED, ImportedInvoice::STATUS_DUPLICATE), true) && $rec->fk_facture_fourn > 0) {
			$sqlInvoice = "SELECT rowid, ref, fk_soc FROM ".MAIN_DB_PREFIX."facture_fourn";
			$sqlInvoice .= " WHERE rowid = ".((int) $rec->fk_facture_fourn)." AND entity IN (".getEntity('invoice_supplier').")";
			$resqlInvoice = $db->query($sqlInvoice);
			if ($resqlInvoice && ($invoiceRow = $db->fetch_object($resqlInvoice))) {
				$linkedInvoiceId = (int) $invoiceRow->rowid;
				$linkedInvoiceRef = (string) $invoiceRow->ref;
				$invoiceSupplierId = (int) $invoiceRow->fk_soc;
			}
		}
		$linkedInvoiceUrl = $linkedInvoiceId > 0 ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$linkedInvoiceId.'&save_lastsearch_values=1' : '';
		$linkedInvoiceLink = $linkedInvoiceId > 0 ? '<a href="'.dol_escape_htmltag($linkedInvoiceUrl).'" title="'.dol_escape_htmltag($linkedInvoiceRef).'">'.img_object($linkedInvoiceRef, 'bill').' '.dol_escape_htmltag($linkedInvoiceRef).'</a>' : '';
		if ($invoiceSupplierId > 0) {
			$soc = new Societe($db);
			if ($soc->fetch($invoiceSupplierId) > 0) $row['supplier'] = $soc->getNomUrl(1);
		} elseif ($rec->fk_soc > 0) {
			$soc = new Societe($db);
			if ($soc->fetch($rec->fk_soc) > 0) {
				$row['supplier'] = $soc->getNomUrl(1);
			}
		} else {
			$row['supplier'] = '<span class="opacitymedium">'.$langs->trans('AuvoRechnungsimportNoSupplierMatch').'</span>';
		}
		$row['invoice_ref'] = dol_escape_htmltag($data['invoice_ref'] ?? '');
		if ((int) $rec->status === ImportedInvoice::STATUS_DUPLICATE && !empty($data['duplicate_of_import_id'])) {
			$duplicateUrl = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.(int) $data['duplicate_of_import_id'];
			$duplicateImportLink = '<a href="'.dol_escape_htmltag($duplicateUrl).'">'.dol_escape_htmltag((string) ($data['duplicate_of_import_ref'] ?? $langs->trans('AuvoRechnungsimportPriorImport'))).'</a>';
			$row['invoice_ref'] .= '<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportDuplicateOf').' '.$duplicateImportLink;
			if ($linkedInvoiceLink !== '') $row['invoice_ref'] .= ' · '.$linkedInvoiceLink;
			$row['invoice_ref'] .= '</span>';
		}
		if ((int) $rec->status === ImportedInvoice::STATUS_DUPLICATE && !empty($data['duplicate_of_ecm_id'])) {
			$businessUrl = auvorechnungsimport_ecm_business_object_url($data['duplicate_of_ecm_object_type'] ?? '', $data['duplicate_of_ecm_object_id'] ?? 0);
			if ($businessUrl !== '') {
				$row['invoice_ref'] .= '<br><span class="opacitymedium small"><a href="'.dol_escape_htmltag($businessUrl).'">'.$langs->trans('AuvoRechnungsimportEcmLinkedBusinessDoc').'</a></span>';
			}
			$ecmUrl = DOL_URL_ROOT.'/ecm/file_card.php?id='.(int) $data['duplicate_of_ecm_id'];
			$row['invoice_ref'] .= '<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportDuplicateOf').' <a href="'.dol_escape_htmltag($ecmUrl).'">'.dol_escape_htmltag((string) $data['duplicate_of_ecm_filename']).'</a></span>';
		}
		if ((int) $rec->status === ImportedInvoice::STATUS_IMPORTED && $linkedInvoiceLink !== '') {
			$row['invoice_ref'] .= '<br><span class="opacitymedium small">'.$linkedInvoiceLink.'</span>';
		}
		if ((int) $rec->status === ImportedInvoice::STATUS_DUPLICATE && empty($data['duplicate_of_import_id']) && $linkedInvoiceLink !== '') {
			$row['invoice_ref'] .= '<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportDuplicateOf').' '.$linkedInvoiceLink.'</span>';
		}
		$row['amount'] = isset($data['amount_ttc']) && $data['amount_ttc'] !== null ? price($data['amount_ttc']) : '';
		$row['lines'] = '';
		if (!empty($data['lines'])) {
			$row['lines'] = count($data['lines']);
			if (($data['lines_check'] ?? '') === 'mismatch') {
				$row['lines'] .= ' '.img_warning($langs->trans('AuvoRechnungsimportLinesSumMismatchShort'));
			}
		}
		$row['status'] = $rec->getLibStatut(1);
		$row['reminder_count'] = (int) $rec->reminder_count;
		$row['last_reminder'] = !empty($rec->last_reminder) ? dol_print_date($rec->last_reminder, 'dayhour') : '';
		print '<tr class="oddeven">';
		foreach ($columnTitles as $key => $column) {
			if (empty($arrayfields[$key]['checked'])) continue;
			$class = in_array($key, array('amount', 'status', 'reminder_count'), true) ? ' class="right nowraponall"' : (in_array($key, array('lines', 'last_reminder'), true) ? ' class="center nowraponall"' : '');
			print '<td'.$class.'>'.$row[$key].'</td>';
		}
		if ($user->hasRight('auvorechnungsimport', 'imports', 'delete')) {
			print '<td class="center">';
			$isQueueRecord = in_array((int) $rec->status, array(ImportedInvoice::STATUS_QUEUED, ImportedInvoice::STATUS_PROCESSING), true);
			$isIrrelevant = in_array((int) $rec->status, array(ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_REVIEW, ImportedInvoice::STATUS_SPAM_CONFIRMED), true);
			if (!$isIrrelevant && (!$isQueueRecord || !$running)) {
				print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block" onsubmit="return confirm(\''.dol_escape_js($langs->trans('AuvoRechnungsimportDeleteConfirm')).'\');">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="deleteimport">';
				print '<input type="hidden" name="id" value="'.(int) $rec->id.'">';
				print '<input type="hidden" name="search_status" value="'.dol_escape_htmltag($searchStatus).'">';
				print '<button type="submit" class="butActionDelete" title="'.dol_escape_htmltag($langs->trans('Delete')).'">'.img_delete($langs->trans('Delete')).'</button>';
				print '</form>';
			} elseif ($isQueueRecord && $running) {
				print img_delete($langs->trans('AuvoRechnungsimportDeleteQueueRunning'));
			}
			print '</td>';
		}
		print '</tr>';
	}
} else {
	$visibleColumnCount = count(array_filter($arrayfields, function ($field) { return !empty($field['checked']); })) + ($user->hasRight('auvorechnungsimport', 'imports', 'delete') ? 1 : 0);
	print '<tr><td colspan="'.$visibleColumnCount.'" class="opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
}
print '</table></div>';

llxFooter();
