<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Streams the original file (PDF or XML e-invoice) of one import record, or
 * a file embedded in the e-invoice (?att=n), scoped strictly to that
 * record's own stored filepath (never trusts a path from the request).
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

require_once __DIR__.'/class/importedinvoice.class.php';
require_once __DIR__.'/core/lib/auvorechnungsimport.lib.php';

if (!isModEnabled('auvorechnungsimport')) {
	accessforbidden();
}

$isBlockedLogPreview = GETPOSTINT('blockedlogpreview') > 0;
$canReadImport = $user->hasRight('auvorechnungsimport', 'imports', 'read');
$canReadBlockedLog = isModEnabled('blockedlog') && ($user->admin || $user->hasRight('blockedlog', 'read'));
if ($isBlockedLogPreview ? (!$canReadImport && !$canReadBlockedLog) : !$canReadImport) accessforbidden();

$id = GETPOSTINT('id');

// Preview the immutable copy held in BlockedLog. This also works for
// auto-detected duplicates and after the import row/file has been deleted.
// The URL's id is the stable fk_object retained in BlockedLog, not a disk path.
if ($isBlockedLogPreview) {
	if (!isModEnabled('blockedlog') || $id <= 0) accessforbidden('Not found');
	require_once DOL_DOCUMENT_ROOT.'/blockedlog/class/blockedlog.class.php';
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."blockedlog WHERE element = 'importedinvoice' AND fk_object = ".((int) $id);
	$sql .= " AND action IN ('AUVORECHNUNGSIMPORT_RECEIVED', 'AUVORECHNUNGSIMPORT_DUPLICATE_AUTO')";
	$sql .= " AND entity IN (".getEntity('blockedlog').") ORDER BY rowid ASC".$db->plimit(1);
	$resql = $db->query($sql);
	$block = null;
	if ($resql && ($row = $db->fetch_object($resql))) {
		$block = new BlockedLog($db);
		if ($block->fetch((int) $row->rowid) <= 0) $block = null;
	}
	if ($resql) $db->free($resql);
	$data = $block ? $block->object_data : null;
	$binary = is_object($data) ? base64_decode((string) ($data->content_base64 ?? ''), true) : false;
	if ($binary === false || $binary === '' || !hash_equals(strtolower((string) ($data->sha256 ?? '')), hash('sha256', $binary))) {
		accessforbidden('Not found');
	}
	$mime = (string) ($data->mime_type ?? 'application/octet-stream');
	$inline = $mime === 'application/pdf';
	if (!$inline && !in_array($mime, array('application/xml', 'text/xml'), true)) $mime = 'application/octet-stream';
	$name = dol_sanitizeFileName((string) ($data->filename ?? 'original-document'));
	header('Content-Type: '.$mime);
	header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$name.'"');
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: private, no-store, max-age=0');
	header('Content-Length: '.strlen($binary));
	print $binary;
	exit;
}

$object = new ImportedInvoice($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden('Not found');
}

if (empty($object->filepath)) accessforbidden('Not found');

$fullpath = auvorechnungsimport_resolve_file($object->filepath);
if ($fullpath === false) {
	accessforbidden('Not found');
}

// ?att=n: file n embedded in the e-invoice (BG-24), decoded on the fly
if (GETPOSTISSET('att')) {
	require_once __DIR__.'/core/lib/auvorechnungsimport.lib.php';
	$inv = auvorechnungsimport_load_einvoice($fullpath);
	$att = $inv['attachments'][GETPOSTINT('att')] ?? null;
	if ($att === null || $att['content'] === '') {
		accessforbidden('Not found');
	}
	$name = dol_sanitizeFileName($att['name'] !== '' ? $att['name'] : 'anlage-'.GETPOSTINT('att'));
	// Only the safe types are shown inline; everything else is downloaded.
	$mime = in_array($att['mime'], array('application/pdf', 'image/png', 'image/jpeg'), true) ? $att['mime'] : 'application/octet-stream';
	header('Content-Type: '.$mime);
	header('Content-Disposition: '.($mime === 'application/octet-stream' ? 'attachment' : 'inline').'; filename="'.$name.'"');
	header('X-Content-Type-Options: nosniff');
	header('Content-Length: '.strlen($att['content']));
	print $att['content'];
	exit;
}

$isXml = strtolower(pathinfo($fullpath, PATHINFO_EXTENSION)) === 'xml';
header('Content-Type: '.($isXml ? 'application/xml' : 'application/pdf'));
header('Content-Disposition: '.($isXml ? 'attachment' : 'inline').'; filename="'.dol_sanitizeFileName($object->original_filename).'"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: '.filesize($fullpath));
readfile($fullpath);
exit;
