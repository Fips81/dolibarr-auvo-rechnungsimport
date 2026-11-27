<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Standalone structured e-invoice view for detached review.
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('main.inc.php not found');

require_once __DIR__.'/class/importedinvoice.class.php';
require_once __DIR__.'/core/lib/auvorechnungsimport.lib.php';

$langs->loadLangs(array('auvorechnungsimport@auvorechnungsimport', 'companies', 'bills', 'other'));
if (!isModEnabled('auvorechnungsimport') || !$user->hasRight('auvorechnungsimport', 'imports', 'read')) accessforbidden();

$id = GETPOSTINT('id');
$object = new ImportedInvoice($db);
if ($id <= 0 || $object->fetch($id) <= 0 || empty($object->filepath)) accessforbidden('Not found');

$fullpath = realpath(DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath);
$basedir = realpath(DOL_DATA_ROOT.'/auvorechnungsimport');
if ($fullpath === false || $basedir === false || strncmp($fullpath, $basedir, strlen($basedir)) !== 0 || !is_readable($fullpath)) accessforbidden('Not found');

$einvoice = auvorechnungsimport_load_einvoice($fullpath);
if ($einvoice === null) accessforbidden('No structured invoice data');

$title = $object->ref.' - '.$langs->trans('AuvoRechnungsimportShowStructured');
llxHeader('', $title);
$cssPath = __DIR__.'/css/auvorechnungsimport_review.css';
$cssVersion = is_file($cssPath) ? filemtime($cssPath) : 1;
print '<link rel="stylesheet" href="'.dol_buildpath('/auvorechnungsimport/css/auvorechnungsimport_review.css', 1).'?v='.(int) $cssVersion.'">';
print '<style>#id-top,#id-left{display:none!important}#id-right{width:100%!important;margin:0!important}#id-container{padding:0!important;margin:0!important}.auvorechnungsimport-detached-viewer{max-width:none;margin:12px!important}.auvorechnungsimport-detached-back{margin-bottom:10px}</style>';
print '<div class="fiche auvorechnungsimport-detached-viewer">';
print '<div class="auvorechnungsimport-detached-back"><a href="'.dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.(int) $object->id.'">'.img_picto('', 'back', 'class="pictofixedwidth"').$langs->trans('AuvoRechnungsimportBackToReview').'</a></div>';
print '<details class="auvorechnungsimport-structured-einvoice" open>';
print '<summary class="cursorpointer"><span class="fas fa-file-code" aria-hidden="true"></span> '.$langs->trans('AuvoRechnungsimportShowStructured').'<span class="fas fa-chevron-up auvorechnungsimport-structured-einvoice-collapse" aria-hidden="true"></span></summary>';
$fileUrl = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_file.php', 1).'?id='.(int) $object->id;
print '<div class="auvorechnungsimport-structured-einvoice-content">'.auvorechnungsimport_render_einvoice($einvoice, $fileUrl.'&att=').'</div>';
print '</details></div>';
llxFooter();
