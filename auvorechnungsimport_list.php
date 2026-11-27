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
		$object->source = 'manual';
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
	(string) ImportedInvoice::STATUS_SPAM_REVIEW => 'AuvoRechnungsimportStatusSpamReview',
	ImportedInvoice::STATUS_DUPLICATE.','.ImportedInvoice::STATUS_SPAM_CONFIRMED.','.ImportedInvoice::STATUS_REJECTED => 'AuvoRechnungsimportMenuIrrelevant',
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
$spamToReview = $counts[ImportedInvoice::STATUS_SPAM_REVIEW] ?? 0;
if ($spamToReview > 0) {
	print '<div class="marginbottomonly"><a class="button buttonDelete" href="'.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.auvorechnungsimport_next_to_review($db, 0, true).'">'.$langs->trans('AuvoRechnungsimportReviewSpamBtn', $spamToReview).'</a></div>';
}

if ($mode === 'upload') {
	// Upload view: form and queue state only
	llxFooter();
	$db->close();
	exit;
}

$searchRef = trim((string) GETPOST('search_ref', 'alphanohtml'));
$searchFilename = trim((string) GETPOST('search_filename', 'restricthtml'));
$searchDateFrom = GETPOST('search_date_from', 'alpha');
$searchDateTo = GETPOST('search_date_to', 'alpha');
$searchMethod = trim((string) GETPOST('search_method', 'alphanohtml'));
$searchSupplier = trim((string) GETPOST('search_supplier', 'restricthtml'));
$searchInvoiceRef = trim((string) GETPOST('search_invoice_ref', 'restricthtml'));
$searchAmount = trim((string) GETPOST('search_amount', 'alphanohtml'));
$filterStatus = GETPOST('filter_status', 'intcomma');
$searchSource = GETPOST('search_source', 'alpha');
$limit = GETPOSTINT('limit') ?: (int) $conf->liste_limit;
$page = max(0, GETPOSTINT('page'));
$offset = $page * $limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09'));
$sortorder = in_array($sortorder, array('ASC', 'DESC'), true) ? $sortorder : 'DESC';
$sortExpressions = array(
	't.ref' => 't.ref',
	't.original_filename' => 't.original_filename',
	't.date_creation' => 't.date_creation',
	't.extraction_method' => 't.extraction_method',
	't.source' => 't.source',
	's.nom' => 's.nom',
	't.fk_soc' => 't.fk_soc',
	't.status' => 't.status',
);
$jsonSortExpressions = array();
if (in_array(strtolower((string) $db->type), array('mysqli', 'mysql'), true)) {
	$jsonSortExpressions = array(
		'invoice_ref' => "CASE WHEN JSON_VALID(t.extracted_data) THEN JSON_UNQUOTE(JSON_EXTRACT(t.extracted_data, '$.invoice_ref')) ELSE '' END",
		'amount_ttc' => "CASE WHEN JSON_VALID(t.extracted_data) THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(t.extracted_data, '$.amount_ttc')) AS DECIMAL(24,8)) ELSE NULL END",
	);
} elseif (in_array(strtolower((string) $db->type), array('pgsql', 'postgresql'), true)) {
	$jsonSortExpressions = array(
		'invoice_ref' => "t.extracted_data::jsonb ->> 'invoice_ref'",
		'amount_ttc' => "NULLIF(t.extracted_data::jsonb ->> 'amount_ttc', '')::numeric",
	);
}
$sortExpressions += $jsonSortExpressions;
if (!isset($sortExpressions[$sortfield])) $sortfield = 't.date_creation';

$where = array('t.entity IN ('.getEntity('importedinvoice').')');
if ($searchStatus !== '') $where[] = 't.status IN ('.$db->sanitize($searchStatus).')';
if ($filterStatus !== '' && $filterStatus !== '-1') $where[] = 't.status = '.((int) $filterStatus);
if ($searchRef !== '') $where[] = "t.ref LIKE '%".$db->escape($db->escapeforlike($searchRef))."%'";
if ($searchFilename !== '') $where[] = "t.original_filename LIKE '%".$db->escape($db->escapeforlike($searchFilename))."%'";
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDateFrom)) $where[] = "t.date_creation >= '".$db->idate(dol_stringtotime($searchDateFrom))."'";
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDateTo)) $where[] = "t.date_creation <= '".$db->idate(dol_stringtotime($searchDateTo.' 23:59:59'))."'";
if ($searchMethod !== '') $where[] = "t.extraction_method LIKE '%".$db->escape($db->escapeforlike($searchMethod))."%'";
if ($searchSupplier !== '') {
	$supplierFilter = "s.nom LIKE '%".$db->escape($db->escapeforlike($searchSupplier))."%'";
	if (ctype_digit($searchSupplier)) $supplierFilter .= ' OR t.fk_soc = '.((int) $searchSupplier);
	$where[] = '('.$supplierFilter.')';
}
if ($searchInvoiceRef !== '') $where[] = "t.extracted_data LIKE '%\"invoice_ref\":\"%".$db->escape($db->escapeforlike($searchInvoiceRef))."%'";
if ($searchAmount !== '') {
	$amountNeedle = str_replace(',', '.', $searchAmount);
	if (is_numeric($amountNeedle)) $amountNeedle = rtrim(rtrim(sprintf('%.8F', (float) $amountNeedle), '0'), '.');
	$where[] = "t.extracted_data LIKE '%\"amount_ttc\":%".$db->escape($db->escapeforlike($amountNeedle))."%'";
}
if (in_array($searchSource, array('manual', 'emailcollector', 'upload', 'unknown'), true)) $where[] = "t.source = '".$db->escape($searchSource)."'";
$whereSql = implode(' AND ', $where);

$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = t.fk_soc AND s.entity IN (".getEntity('societe').")";
$sql .= " WHERE ".$whereSql;
$resql = $db->query($sql);
$total = 0;
if ($resql && ($count = $db->fetch_object($resql))) $total = (int) $count->nb;
$num = min($limit, max(0, $total - $offset));

$param = '';
foreach (array('mode' => $mode, 'search_status' => $searchStatus, 'search_ref' => $searchRef, 'search_filename' => $searchFilename, 'search_date_from' => $searchDateFrom, 'search_date_to' => $searchDateTo, 'search_method' => $searchMethod, 'search_supplier' => $searchSupplier, 'search_invoice_ref' => $searchInvoiceRef, 'search_amount' => $searchAmount, 'filter_status' => $filterStatus, 'search_source' => $searchSource) as $key => $value) {
	if ($value !== '' && $value !== null) $param .= '&'.$key.'='.urlencode((string) $value);
}

$statusOptions = array(
	'-1' => '',
	(string) ImportedInvoice::STATUS_QUEUED => $langs->trans('AuvoRechnungsimportStatusQueued'),
	(string) ImportedInvoice::STATUS_SPAM_REVIEW => $langs->trans('AuvoRechnungsimportStatusSpamReview'),
	(string) ImportedInvoice::STATUS_DUPLICATE => $langs->trans('AuvoRechnungsimportStatusDuplicate'),
	(string) ImportedInvoice::STATUS_SPAM_CONFIRMED => $langs->trans('AuvoRechnungsimportStatusSpamConfirmed'),
	(string) ImportedInvoice::STATUS_REJECTED => $langs->trans('AuvoRechnungsimportStatusRejected'),
	(string) ImportedInvoice::STATUS_PROCESSING => $langs->trans('AuvoRechnungsimportStatusProcessing'),
	(string) ImportedInvoice::STATUS_NEEDS_REVIEW => $langs->trans('AuvoRechnungsimportStatusNeedsReview'),
	(string) ImportedInvoice::STATUS_MATCHED => $langs->trans('AuvoRechnungsimportStatusMatched'),
	(string) ImportedInvoice::STATUS_IMPORTED => $langs->trans('AuvoRechnungsimportStatusImported'),
	(string) ImportedInvoice::STATUS_ERROR => $langs->trans('AuvoRechnungsimportStatusError'),
);
$sourceLabels = array('manual' => 'AuvoRechnungsimportSourceManual', 'emailcollector' => 'AuvoRechnungsimportSourceEmail', 'upload' => 'AuvoRechnungsimportSourceUpload', 'unknown' => 'AuvoRechnungsimportSourceUnknown');
$sortLabels = array(
	't.ref' => $langs->trans('Ref'),
	't.original_filename' => $langs->trans('AuvoRechnungsimportOriginalFilename'),
	't.date_creation' => $langs->trans('DateCreation'),
	't.extraction_method' => $langs->trans('AuvoRechnungsimportExtractionMethod'),
	't.source' => $langs->trans('AuvoRechnungsimportSource'),
	's.nom' => $langs->trans('ThirdParty'),
	'invoice_ref' => $langs->trans('RefSupplier'),
	'amount_ttc' => $langs->trans('AmountTTC'),
	't.status' => $langs->trans('Status'),
);

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" id="searchFormList">';
print '<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">';
print '<input type="hidden" name="search_status" value="'.dol_escape_htmltag($searchStatus).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="page" value="0">';
print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $total, 'generic', 0, '', '', $limit);
print '<div class="div-table-responsive"><table class="noborder centpercent liste">';
print '<tr class="liste_titre">';
print '<td class="liste_titre"><span class="inline-block">'.$form->showFilterButtons().'</span> <input class="flat maxwidth75" type="text" name="search_ref" value="'.dol_escape_htmltag($searchRef).'" placeholder="'.dol_escape_htmltag($langs->trans('Ref')).'"></td>';
print '<td class="liste_titre"><input class="flat minwidth150" type="text" name="search_filename" value="'.dol_escape_htmltag($searchFilename).'"></td>';
print '<td class="liste_titre"><input class="flat maxwidth100" type="date" name="search_date_from" value="'.dol_escape_htmltag($searchDateFrom).'"><br><input class="flat maxwidth100" type="date" name="search_date_to" value="'.dol_escape_htmltag($searchDateTo).'"></td>';
print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_method" value="'.dol_escape_htmltag($searchMethod).'"></td>';
print '<td class="liste_titre"><select class="flat maxwidth100" name="search_source">';
print '<option value="">&nbsp;</option>';
foreach (array('manual' => 'AuvoRechnungsimportSourceManual', 'emailcollector' => 'AuvoRechnungsimportSourceEmail', 'upload' => 'AuvoRechnungsimportSourceUpload', 'unknown' => 'AuvoRechnungsimportSourceUnknown') as $sourceCode => $sourceLabel) {
	print '<option value="'.$sourceCode.'"'.($searchSource === $sourceCode ? ' selected' : '').'>'.$langs->trans($sourceLabel).'</option>';
}
print '</select></td>';
print '<td class="liste_titre"><input class="flat minwidth150" type="text" name="search_supplier" value="'.dol_escape_htmltag($searchSupplier).'"></td>';
print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_invoice_ref" value="'.dol_escape_htmltag($searchInvoiceRef).'"></td>';
print '<td class="liste_titre right"><input class="flat maxwidth100" type="text" name="search_amount" value="'.dol_escape_htmltag($searchAmount).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"><select class="flat maxwidth150" name="filter_status">';
foreach ($statusOptions as $value => $label) print '<option value="'.dol_escape_htmltag($value).'"'.((string) $filterStatus === (string) $value ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
print '</select></td>';
print '</tr><tr class="liste_titre">';
foreach (array('t.ref' => 't.ref', 't.original_filename' => 't.original_filename', 't.date_creation' => 't.date_creation', 't.extraction_method' => 't.extraction_method', 't.source' => 't.source', 's.nom' => 's.nom', 'invoice_ref' => 'invoice_ref', 'amount_ttc' => 'amount_ttc') as $labelKey => $field) {
	if (!isset($sortExpressions[$field])) {
		print '<th'.($labelKey === 'amount_ttc' ? ' class="right"' : '').'>'.$sortLabels[$labelKey].'</th>';
		continue;
	}
	print_liste_field_titre($sortLabels[$labelKey], $_SERVER['PHP_SELF'], $field, '', $param, '', $sortfield, $sortorder);
}
print '<th class="center">'.$langs->trans('AuvoRechnungsimportLinesCol').'</th>';
print_liste_field_titre($sortLabels['t.status'], $_SERVER['PHP_SELF'], 't.status', '', $param, '', $sortfield, $sortorder);
print '</tr>';

$sql = "SELECT t.* FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = t.fk_soc AND s.entity IN (".getEntity('societe').")";
$sql .= " WHERE ".$whereSql." ORDER BY ".$sortExpressions[$sortfield]." ".$sortorder;
$sql .= $db->plimit($limit, $offset);
$resql = $db->query($sql);
$records = array();
if ($resql) {
	while ($row = $db->fetch_object($resql)) {
		$rec = new ImportedInvoice($db);
		$rec->setVarsFromFetchObj($row);
		$records[] = $rec;
	}
}

if ($records) {
	foreach ($records as $rec) {
		print '<tr class="oddeven">';
		print '<td>'.$rec->getNomUrl(1).'</td>';
		print '<td>'.dol_escape_htmltag($rec->original_filename).'</td>';
		print '<td>'.dol_print_date($rec->date_creation, 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag($rec->extraction_method).'</td>';
		print '<td>'.(isset($sourceLabels[$rec->source]) ? $langs->trans($sourceLabels[$rec->source]) : dol_escape_htmltag($rec->source)).'</td>';
		print '<td>';
		if ($rec->fk_soc > 0) {
			$soc = new Societe($db);
			if ($soc->fetch($rec->fk_soc) > 0) print $soc->getNomUrl(1);
		} else print '<span class="opacitymedium">'.$langs->trans('AuvoRechnungsimportNoSupplierMatch').'</span>';
		print '</td>';
		$data = $rec->getExtractedData();
		print '<td>'.dol_escape_htmltag($data['invoice_ref'] ?? '').'</td>';
		print '<td class="right nowraponall">'.(isset($data['amount_ttc']) && $data['amount_ttc'] !== null ? price($data['amount_ttc']) : '').'</td>';
		print '<td class="center">';
		if (!empty($data['lines'])) {
			print count($data['lines']);
			if (($data['lines_check'] ?? '') === 'mismatch') print ' '.img_warning($langs->trans('AuvoRechnungsimportLinesSumMismatchShort'));
		}
		print '</td><td class="right">'.$rec->getLibStatut(1).'</td></tr>';
	}
} else {
	print '<tr><td colspan="10" class="opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
}
print '</table></div></form>';

llxFooter();
