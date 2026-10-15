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

if (!isModEnabled('auvorechnungsimport') || !$user->hasRight('auvorechnungsimport', 'imports', 'read')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$object = new ImportedInvoice($db);
if ($id <= 0 || $object->fetch($id) <= 0 || empty($object->filepath)) {
	accessforbidden('Not found');
}

$fullpath = realpath(DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath);
$basedir = realpath(DOL_DATA_ROOT.'/auvorechnungsimport');
if ($fullpath === false || $basedir === false || strncmp($fullpath, $basedir, strlen($basedir)) !== 0 || !is_readable($fullpath)) {
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
