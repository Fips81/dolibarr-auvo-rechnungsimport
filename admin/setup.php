<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die('main.inc.php not found');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->loadLangs(array('auvorechnungsimport@auvorechnungsimport', 'admin'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
	$ocrLangs = GETPOST('AUVORECHNUNGSIMPORT_OCR_LANGS', 'alphanohtml');
	if (!preg_match('/^[a-z]{3}(\+[a-z]{3})*$/i', $ocrLangs)) {
		$ocrLangs = 'deu+fra+eng';
	}
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_OCR_LANGS', $ocrLangs, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_OCR_MAXPAGES', min(20, max(1, GETPOSTINT('AUVORECHNUNGSIMPORT_OCR_MAXPAGES'))), 'chaine', 0, '', $conf->entity);
	foreach (array('AUVORECHNUNGSIMPORT_KEYWORDS_COST', 'AUVORECHNUNGSIMPORT_KEYWORDS_SKIP') as $const) {
		dolibarr_set_const($db, $const, trim(GETPOST($const, 'alphanohtml')), 'chaine', 0, '', $conf->entity);
	}
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER', GETPOSTINT('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER') ? 1 : 0, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'AuvoRechnungsimport');
print load_fiche_titre($langs->trans('ModuleAuvoRechnungsimportName'), '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>', 'title_setup');

$ocrLangs = getDolGlobalString('AUVORECHNUNGSIMPORT_OCR_LANGS', 'deu+fra+eng');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportOcrLangs').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportOcrLangsHelp').'</span></td><td><input type="text" name="AUVORECHNUNGSIMPORT_OCR_LANGS" class="minwidth200" value="'.dol_escape_htmltag($ocrLangs).'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportOcrMaxPages').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportOcrMaxPagesHelp').'</span></td><td><input type="number" min="1" max="20" name="AUVORECHNUNGSIMPORT_OCR_MAXPAGES" class="width50" value="'.getDolGlobalInt('AUVORECHNUNGSIMPORT_OCR_MAXPAGES', 5).'"></td></tr>';

foreach (array('AUVORECHNUNGSIMPORT_KEYWORDS_COST' => 'AuvoRechnungsimportKeywordsCost', 'AUVORECHNUNGSIMPORT_KEYWORDS_SKIP' => 'AuvoRechnungsimportKeywordsSkip') as $const => $label) {
	print '<tr class="oddeven"><td>'.$langs->trans($label).'<br><span class="opacitymedium small">'.$langs->trans($label.'Help').'</span></td>';
	print '<td><input type="text" name="'.$const.'" class="minwidth400" value="'.dol_escape_htmltag(getDolGlobalString($const)).'"></td></tr>';
}

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportAutocreateSupplier').'</td><td>';
print '<input type="checkbox" name="AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER" value="1"'.(getDolGlobalInt('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER') ? ' checked' : '').'> <span class="opacitymedium">'.$langs->trans('AuvoRechnungsimportAutocreateSupplierHelp').'</span>';
print '</td></tr>';

print '</table></div>';
print '<div class="center marginbottomonly"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

llxFooter();
