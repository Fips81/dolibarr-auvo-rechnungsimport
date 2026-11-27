<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

// Use Dolibarr's searchable multi-select (Select2) for the status chips below.
if (!defined('REQUIRE_JQUERY_MULTISELECT')) define('REQUIRE_JQUERY_MULTISELECT', 'select2');

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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../class/importedinvoice.class.php';

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
	foreach (array('AUVORECHNUNGSIMPORT_UPDATE_BUYPRICE_MODE', 'AUVORECHNUNGSIMPORT_CREATE_ORDER_MODE') as $modeConst) {
		$mode = GETPOST($modeConst, 'alpha');
		if (!in_array($mode, array('auto', 'allow', 'deny'), true)) $mode = 'deny';
		dolibarr_set_const($db, $modeConst, $mode, 'chaine', 0, '', $conf->entity);
	}
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_ALLOW_RELEASE_ORDER_BUTTON', GETPOSTINT('AUVORECHNUNGSIMPORT_ALLOW_RELEASE_ORDER_BUTTON') ? 1 : 0, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_ALLOW_DRAFT_TOTAL_MISMATCH', GETPOSTINT('AUVORECHNUNGSIMPORT_ALLOW_DRAFT_TOTAL_MISMATCH') ? 1 : 0, 'chaine', 0, '', $conf->entity);
	$deleteStatuses = GETPOST('AUVORECHNUNGSIMPORT_DELETE_BLOCKED_STATUSES', 'array');
	$validDeleteStatuses = array(
		ImportedInvoice::STATUS_PROCESSING, ImportedInvoice::STATUS_NEEDS_REVIEW, ImportedInvoice::STATUS_IMPORTED,
		ImportedInvoice::STATUS_MATCHED, ImportedInvoice::STATUS_QUEUED, ImportedInvoice::STATUS_SPAM_REVIEW,
		ImportedInvoice::STATUS_DUPLICATE, ImportedInvoice::STATUS_SPAM_CONFIRMED, ImportedInvoice::STATUS_REJECTED,
		ImportedInvoice::STATUS_ERROR,
	);
	$deleteStatuses = is_array($deleteStatuses) ? array_unique(array_map('intval', $deleteStatuses)) : array();
	$deleteStatuses = array_values(array_intersect($deleteStatuses, $validDeleteStatuses));
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_DELETE_BLOCKED_STATUSES', implode(',', $deleteStatuses), 'chaine', 0, '', $conf->entity);
	$ownCompanyAliases = GETPOST('AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES', 'array');
	$normalizedAliases = array();
	foreach ((array) $ownCompanyAliases as $alias) {
		foreach (preg_split('/[;\r\n]+/u', (string) $alias) as $part) {
			$part = trim(preg_replace('/\s+/u', ' ', $part));
			if ($part !== '') $normalizedAliases[] = $part;
		}
	}
	$normalizedAliases = array_values(array_unique($normalizedAliases));
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES', implode('; ', $normalizedAliases), 'chaine', 0, '', $conf->entity);
	$spamHeader = trim(GETPOST('AUVORECHNUNGSIMPORT_SPAM_HEADER', 'alphanohtml'));
	$spamValue = trim(GETPOST('AUVORECHNUNGSIMPORT_SPAM_VALUE', 'alphanohtml'));
	if ((($spamHeader === '') !== ($spamValue === '')) || ($spamHeader !== '' && !preg_match('/^[A-Za-z0-9-]{1,78}$/', $spamHeader)) || preg_match('/[\r\n]/', $spamValue) || strlen($spamValue) > 255) {
		$spamHeader = '';
		$spamValue = '';
		setEventMessages($langs->trans('AuvoRechnungsimportSpamHeaderInvalid'), null, 'errors');
	}
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_SPAM_HEADER', $spamHeader, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_SPAM_VALUE', $spamValue, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', 'AuvoRechnungsimport');
print load_fiche_titre($langs->trans('ModuleAuvoRechnungsimportName'), '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>', 'title_setup');
$adminCssPath = __DIR__.'/../css/auvorechnungsimport_admin.css';
$adminCssVersion = is_file($adminCssPath) ? filemtime($adminCssPath) : 1;
print '<link rel="stylesheet" href="'.dol_buildpath('/auvorechnungsimport/css/auvorechnungsimport_admin.css', 1).'?v='.(int) $adminCssVersion.'">';

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

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportAutocreateSupplier').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportAutocreateSupplierHelp').'</span></td><td>';
print '<input type="checkbox" name="AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER" value="1"'.(getDolGlobalInt('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER', 1) ? ' checked' : '').'>';
print '</td></tr>';

foreach (array(
	'AUVORECHNUNGSIMPORT_UPDATE_BUYPRICE_MODE' => 'AuvoRechnungsimportUpdateBuyprice',
	'AUVORECHNUNGSIMPORT_CREATE_ORDER_MODE' => 'AuvoRechnungsimportCreateOrder',
) as $modeConst => $label) {
	$defaultMode = $modeConst === 'AUVORECHNUNGSIMPORT_CREATE_ORDER_MODE' ? 'allow' : 'auto';
	$mode = getDolGlobalString($modeConst, $defaultMode);
	if (!in_array($mode, array('auto', 'allow', 'deny'), true)) $mode = $defaultMode;
	print '<tr class="oddeven"><td>'.$langs->trans($label).'<br><span class="opacitymedium small">'.$langs->trans($label.'ModeHelp').'</span></td><td>';
	print '<div class="auvorechnungsimport-admin-radio-options">';
	foreach (array('auto', 'allow', 'deny') as $choice) {
		print '<label><input type="radio" name="'.$modeConst.'" value="'.$choice.'"'.($mode === $choice ? ' checked' : '').'> '.$langs->trans('AuvoRechnungsimportSetting'.ucfirst($choice)).'</label>';
	}
	print '</div>';
	print '</td></tr>';
}

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportAllowReleaseOrderButton').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportAllowReleaseOrderButtonHelp').'</span></td><td>';
print '<input type="checkbox" name="AUVORECHNUNGSIMPORT_ALLOW_RELEASE_ORDER_BUTTON" value="1"'.(getDolGlobalInt('AUVORECHNUNGSIMPORT_ALLOW_RELEASE_ORDER_BUTTON', 1) ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportAllowDraftTotalMismatch').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportAllowDraftTotalMismatchHelp').'</span></td><td>';
print '<input type="checkbox" name="AUVORECHNUNGSIMPORT_ALLOW_DRAFT_TOTAL_MISMATCH" value="1"'.(getDolGlobalInt('AUVORECHNUNGSIMPORT_ALLOW_DRAFT_TOTAL_MISMATCH', 0) ? ' checked' : '').'>';
print '</td></tr>';

$deleteStatusOptions = array(
	ImportedInvoice::STATUS_PROCESSING => 'AuvoRechnungsimportStatusProcessing',
	ImportedInvoice::STATUS_NEEDS_REVIEW => 'AuvoRechnungsimportStatusNeedsReview',
	ImportedInvoice::STATUS_IMPORTED => 'AuvoRechnungsimportStatusImported',
	ImportedInvoice::STATUS_MATCHED => 'AuvoRechnungsimportStatusMatched',
	ImportedInvoice::STATUS_QUEUED => 'AuvoRechnungsimportStatusQueued',
	ImportedInvoice::STATUS_SPAM_REVIEW => 'AuvoRechnungsimportStatusSpamReview',
	ImportedInvoice::STATUS_DUPLICATE => 'AuvoRechnungsimportStatusDuplicate',
	ImportedInvoice::STATUS_SPAM_CONFIRMED => 'AuvoRechnungsimportStatusSpamConfirmed',
	ImportedInvoice::STATUS_REJECTED => 'AuvoRechnungsimportStatusRejected',
	ImportedInvoice::STATUS_ERROR => 'AuvoRechnungsimportStatusError',
);
$blockedDeleteStatuses = array_map('intval', array_filter(explode(',', getDolGlobalString('AUVORECHNUNGSIMPORT_DELETE_BLOCKED_STATUSES')), 'strlen'));
print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportDeleteBlockedStatuses').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportDeleteBlockedStatusesHelp').'</span></td><td>';
print Form::multiselectarray('AUVORECHNUNGSIMPORT_DELETE_BLOCKED_STATUSES', $deleteStatusOptions, array_map('strval', $blockedDeleteStatuses), 0, 0, 'minwidth300', 1, 400);
print '</td></tr>';

$ownCompanyAliases = getDolGlobalString('AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES');
$ownCompanyAliases = str_replace(array('\\r\\n', '\\n', '\\r', "\r\n", "\r", "\n"), ';', $ownCompanyAliases);
$ownCompanyAliases = array_values(array_unique(array_filter(array_map('trim', preg_split('/[;\r\n]+/u', $ownCompanyAliases)))));
print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportOwnCompanyAliases').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportOwnCompanyAliasesHelp').'</span></td><td>';
print '<select id="AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES" name="AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES[]" class="flat minwidth400 centpercent" multiple data-placeholder="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportAddAlias')).'">';
foreach ($ownCompanyAliases as $alias) print '<option value="'.dol_escape_htmltag($alias).'" selected>'.dol_escape_htmltag($alias).'</option>';
print '</select>';
print '<div class="auvorechnungsimport-admin-alias-entry"><input type="text" id="auvorechnungsimport-new-alias" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportNewAlias')).'"><button type="button" id="auvorechnungsimport-add-alias" class="button">'.$langs->trans('Add').'</button></div>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportSpamHeader').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportSpamHeaderHelp').'</span></td><td>';
print '<input type="text" name="AUVORECHNUNGSIMPORT_SPAM_HEADER" class="minwidth200" value="'.dol_escape_htmltag(getDolGlobalString('AUVORECHNUNGSIMPORT_SPAM_HEADER')).'" placeholder="X-MS-Spam-Detected"> ';
print '<input type="text" name="AUVORECHNUNGSIMPORT_SPAM_VALUE" class="minwidth150" value="'.dol_escape_htmltag(getDolGlobalString('AUVORECHNUNGSIMPORT_SPAM_VALUE')).'" placeholder="true">';
print '</td></tr>';

print '</table></div>';
print '<div class="center marginbottomonly"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';
$adminJsPath = __DIR__.'/../js/auvorechnungsimport_admin.js';
$adminJsVersion = is_file($adminJsPath) ? filemtime($adminJsPath) : 1;
print '<script src="'.dol_buildpath('/auvorechnungsimport/js/auvorechnungsimport_admin.js', 1).'?v='.(int) $adminJsVersion.'"></script>';

llxFooter();
