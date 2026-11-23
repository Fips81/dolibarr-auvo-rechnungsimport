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
	$ownCompanyAliases = GETPOST('AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES', 'restricthtml');
	$ownCompanyAliases = str_replace(array('\\r\\n', '\\n', '\\r', "\r\n", "\r", "\n"), ';', $ownCompanyAliases);
	$ownCompanyAliases = preg_replace('/\s*;\s*/u', '; ', trim($ownCompanyAliases, "; \t"));
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES', $ownCompanyAliases, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE', GETPOSTINT('AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE') ? 1 : 0, 'chaine', 0, '', $conf->entity);
	$reminderEmail = trim(GETPOST('AUVORECHNUNGSIMPORT_REMINDER_EMAIL', 'alphanohtml'));
	if ($reminderEmail !== '' && !isValidEmail($reminderEmail)) {
		$reminderEmail = '';
		setEventMessages($langs->trans('AuvoRechnungsimportReminderEmailInvalid'), null, 'errors');
	}
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_REMINDER_EMAIL', $reminderEmail, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'AUVORECHNUNGSIMPORT_REMINDER_PERIOD_DAYS', min(365, max(1, GETPOSTINT('AUVORECHNUNGSIMPORT_REMINDER_PERIOD_DAYS'))), 'chaine', 0, '', $conf->entity);
	$spamHeader = trim(GETPOST('AUVORECHNUNGSIMPORT_SPAM_HEADER', 'alphanohtml'));
	$spamValue = trim(GETPOST('AUVORECHNUNGSIMPORT_SPAM_VALUE', 'alphanohtml'));
	if ($spamHeader !== '' && !preg_match('/^[A-Za-z0-9-]{1,78}$/', $spamHeader)) {
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

if ($action == 'registercron') {
	if (!GETPOSTISSET('token') || GETPOST('token') !== newToken()) {
		accessforbidden('CSRF');
	}
	if (!isModEnabled('cron')) {
		setEventMessages($langs->trans('AuvoRechnungsimportCronModuleRequired'), null, 'errors');
	} elseif (dol_include_once('/auvorechnungsimport/core/modules/modAuvoRechnungsimport.class.php')) {
		$module = new modAuvoRechnungsimport($db);
		if ($module->insert_cronjobs() === 0) {
			setEventMessages($langs->trans('AuvoRechnungsimportCronRegistered'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('AuvoRechnungsimportCronRegisterFailed'), array($module->error), 'errors');
		}
	} else {
		setEventMessages($langs->trans('AuvoRechnungsimportCronRegisterFailed'), null, 'errors');
	}
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
$ownCompanyAliases = getDolGlobalString('AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES');
$ownCompanyAliases = str_replace(array('\\r\\n', '\\n', '\\r', "\r\n", "\r", "\n"), ';', $ownCompanyAliases);
$ownCompanyAliases = preg_replace('/\s*;\s*/u', '; ', trim($ownCompanyAliases, "; \t"));
print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportOwnCompanyAliases').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportOwnCompanyAliasesHelp').'</span></td><td><input type="text" name="AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES" class="minwidth400 centpercent" value="'.dol_escape_htmltag($ownCompanyAliases).'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportBlockedLogEnable').'</td><td>';
print '<input type="checkbox" name="AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE" value="1"'.(getDolGlobalInt('AUVORECHNUNGSIMPORT_BLOCKEDLOG_ENABLE') ? ' checked' : '').'> <span class="opacitymedium">'.$langs->trans('AuvoRechnungsimportBlockedLogEnableHelp').'</span>';
if (!isModEnabled('blockedlog')) print '<br><span class="warning">'.$langs->trans('AuvoRechnungsimportBlockedLogModuleRequired').'</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportSpamHeader').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportSpamHeaderHelp').'</span></td><td>';
print '<input type="text" name="AUVORECHNUNGSIMPORT_SPAM_HEADER" class="minwidth200" value="'.dol_escape_htmltag(getDolGlobalString('AUVORECHNUNGSIMPORT_SPAM_HEADER')).'" placeholder="X-MS-Spam-Detected"> ';
print '<input type="text" name="AUVORECHNUNGSIMPORT_SPAM_VALUE" class="minwidth150" value="'.dol_escape_htmltag(getDolGlobalString('AUVORECHNUNGSIMPORT_SPAM_VALUE')).'" placeholder="true">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportReminderEmail').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportReminderEmailHelp').'</span></td><td><input type="email" name="AUVORECHNUNGSIMPORT_REMINDER_EMAIL" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('AUVORECHNUNGSIMPORT_REMINDER_EMAIL')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('AuvoRechnungsimportReminderPeriod').'<br><span class="opacitymedium small">'.$langs->trans('AuvoRechnungsimportReminderPeriodHelp').'</span></td><td><input type="number" min="1" max="365" name="AUVORECHNUNGSIMPORT_REMINDER_PERIOD_DAYS" class="width50" value="'.getDolGlobalInt('AUVORECHNUNGSIMPORT_REMINDER_PERIOD_DAYS', 2).'"></td></tr>';

print '</table></div>';
print '<div class="center marginbottomonly"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<div class="titre">'.$langs->trans('AuvoRechnungsimportQueueCronTitle').'</div>';
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('AuvoRechnungsimportQueueCronHelp').'</div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="inline-block">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="registercron">';
print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('AuvoRechnungsimportCronRegisterBtn')).'">';
print '</form>';

llxFooter();
