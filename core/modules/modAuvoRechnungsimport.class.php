<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Module descriptor for AuVo Rechnungsimport (formerly InvoiceImport)
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modAuvoRechnungsimport extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;
		// Block 194202–194211 reserved for auvo-design in the Dolibarr wiki
		$this->numero = 194203;
		$this->rights_class = 'auvorechnungsimport';
		$this->family = "financial";
		$this->module_position = 90;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Eingangsrechnungen per Upload erfassen: liest XRechnung (CII/UBL), Factur-X/ZUGFeRD oder Text/OCR aus dem PDF und ordnet den Lieferanten automatisch zu";
		$this->descriptionlong = "Eingangsrechnungen als PDF oder XML hochladen. Das Modul liest XRechnung und andere E-Rechnungen (CII und UBL), eingebettetes Factur-X/ZUGFeRD-XML, sonst den Textlayer des PDFs oder per Tesseract-OCR. Lieferant, Rechnungsnummer, Datum, Betrag und Positionen werden vorgeschlagen; nach Prüfung wird daraus eine Lieferantenrechnung angelegt.";
		$this->version = '0.3.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-file-invoice';
		$this->editor_name = 'auvo-design';
		$this->editor_url = 'https://auvo-design.de';

		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(19, 0);

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'theme' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
		);

		$this->dirs = array('/auvorechnungsimport/temp');
		$this->config_page_url = array('setup.php@auvorechnungsimport');

		$this->depends = array('modFournisseur', 'modSociete');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('auvorechnungsimport@auvorechnungsimport');

		$this->const = array(
			array('AUVORECHNUNGSIMPORT_OCR_LANGS', 'chaine', 'deu+fra+eng', 'Sprachen fuer Tesseract-OCR (tesseract --list-langs fuer verfuegbare Codes)', 1, 'current', 0),
			array('AUVORECHNUNGSIMPORT_OCR_MAXPAGES', 'int', '5', 'Maximale Seitenzahl fuer OCR bei gescannten PDFs (Positionen koennen ueber mehrere Seiten gehen)', 1, 'current', 0),
			array('AUVORECHNUNGSIMPORT_AUTOCREATE_SUPPLIER', 'int', '0', 'Unbekannten Lieferanten automatisch anlegen statt manuell nachzufragen', 1, 'current', 0),
		);

		if (!isset($conf->auvorechnungsimport) || !isset($conf->auvorechnungsimport->enabled)) {
			$conf->auvorechnungsimport = new stdClass();
			$conf->auvorechnungsimport->enabled = 0;
		}

		$r = 0;
		$this->rights = array();
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Eingangsrechnungs-Importe einsehen';
		$this->rights[$r][4] = 'imports';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1);
		$this->rights[$r][1] = 'Rechnungen hochladen und daraus Lieferantenrechnungen anlegen';
		$this->rights[$r][4] = 'imports';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->menu = array();
		$this->menu[] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'AuvoRechnungsimportTopMenu',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'auvorechnungsimport',
			'leftmenu' => '',
			'url' => '/auvorechnungsimport/auvorechnungsimport_list.php',
			'langs' => 'auvorechnungsimport@auvorechnungsimport',
			'position' => 1100,
			'enabled' => "isModEnabled('auvorechnungsimport')",
			'perms' => '$user->hasRight("auvorechnungsimport", "imports", "read")',
			'target' => '',
			'user' => 2,
		);

		// Left menu: the list by status - "to review" is where a batch upload
		// is worked through.
		$list = '/auvorechnungsimport/auvorechnungsimport_list.php';
		$left = array(
			// leftmenu => [label, url, position]
			'auvorechnungsimport' => array('AuvoRechnungsimportMenuAll', $list, 1100),
			'auvorechnungsimport_upload' => array('AuvoRechnungsimportMenuUpload', $list.'?mode=upload', 1101),
			'auvorechnungsimport_review' => array('AuvoRechnungsimportFilterToReview', $list.'?search_status=1,3', 1102),
			'auvorechnungsimport_queued' => array('AuvoRechnungsimportStatusQueued', $list.'?search_status=4,0', 1103),
			'auvorechnungsimport_error' => array('AuvoRechnungsimportStatusError', $list.'?search_status=9', 1104),
			'auvorechnungsimport_imported' => array('AuvoRechnungsimportStatusImported', $list.'?search_status=2', 1105),
		);
		foreach ($left as $leftmenu => list($label, $url, $position)) {
			$this->menu[] = array(
				'fk_menu' => $leftmenu === 'auvorechnungsimport' ? 'fk_mainmenu=auvorechnungsimport' : 'fk_mainmenu=auvorechnungsimport,fk_leftmenu=auvorechnungsimport',
				'type' => 'left',
				'titre' => $label,
				'prefix' => $leftmenu === 'auvorechnungsimport' ? img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"') : '',
				'mainmenu' => 'auvorechnungsimport',
				'leftmenu' => $leftmenu,
				'url' => $url,
				'langs' => 'auvorechnungsimport@auvorechnungsimport',
				'position' => $position,
				'enabled' => "isModEnabled('auvorechnungsimport')",
				'perms' => $leftmenu === 'auvorechnungsimport_upload' ? '$user->hasRight("auvorechnungsimport", "imports", "write")' : '$user->hasRight("auvorechnungsimport", "imports", "read")',
				'target' => '',
				'user' => 2,
			);
		}
		$this->menu[] = array(
			'fk_menu' => 'fk_mainmenu=auvorechnungsimport,fk_leftmenu=auvorechnungsimport',
			'type' => 'left',
			'titre' => 'Setup',
			'mainmenu' => 'auvorechnungsimport',
			'leftmenu' => 'auvorechnungsimport_setup',
			'url' => '/auvorechnungsimport/admin/setup.php',
			'langs' => 'admin',
			'position' => 1110,
			'enabled' => "isModEnabled('auvorechnungsimport')",
			'perms' => '$user->admin',
			'target' => '',
			'user' => 2,
		);
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('/auvorechnungsimport/sql/');
		if ($result < 0) {
			return -1;
		}
		// Before _init(): migrated settings must not be replaced by the defaults.
		$this->migrateFromInvoiceImport();
		$result = $this->_init(array(), $options);
		$this->migrateRightsFromInvoiceImport();
		return $result;
	}

	/**
	 * Takes over an installation of the module under its former name
	 * InvoiceImport (folder custom/invoiceimport, module number 500410).
	 * Copies, never moves: the old constants, tables and files stay in place,
	 * so the old module can still be switched back on. Idempotent.
	 *  - constants INVOICEIMPORT_* → AUVORECHNUNGSIMPORT_* (unless already set);
	 *  - tables llx_invoiceimport_* → llx_auvorechnungsimport_* (only while the
	 *    new table is empty), row ids kept;
	 *  - uploaded PDFs documents/invoiceimport → documents/auvorechnungsimport
	 *    (the stored file paths are relative to that directory).
	 */
	private function migrateFromInvoiceImport()
	{
		$sql = "SELECT name, entity, value, type, visible, note FROM ".MAIN_DB_PREFIX."const";
		$sql .= " WHERE name LIKE 'INVOICEIMPORT\\_%'";
		$resql = $this->db->query($sql);
		$rows = array();
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rows[] = $obj;
		}
		foreach ($rows as $obj) {
			$newName = 'AUVORECHNUNGSIMPORT_'.substr($obj->name, strlen('INVOICEIMPORT_'));
			$check = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."const WHERE name = '".$this->db->escape($newName)."' AND entity = ".((int) $obj->entity));
			if ($check && $this->db->num_rows($check) > 0) {
				continue;
			}
			$ins = "INSERT INTO ".MAIN_DB_PREFIX."const (name, entity, value, type, visible, note) VALUES (";
			$ins .= "'".$this->db->escape($newName)."', ".((int) $obj->entity).", '".$this->db->escape($obj->value)."', '".$this->db->escape($obj->type)."', ".((int) $obj->visible).", '".$this->db->escape((string) $obj->note)."')";
			$this->db->query($ins);
		}

		foreach (array('importedinvoice', 'linemap') as $table) {
			$old = MAIN_DB_PREFIX.'invoiceimport_'.$table;
			$new = MAIN_DB_PREFIX.'auvorechnungsimport_'.$table;
			$exists = $this->db->query("SHOW TABLES LIKE '".$this->db->escape($old)."'");
			if (!$exists || $this->db->num_rows($exists) === 0) {
				continue;
			}
			$count = $this->db->query("SELECT COUNT(*) as nb FROM ".$new);
			$obj = $count ? $this->db->fetch_object($count) : null;
			if ($obj && (int) $obj->nb === 0) {
				$this->db->query("INSERT INTO ".$new." SELECT * FROM ".$old);
			}
		}

		$oldDir = DOL_DATA_ROOT.'/invoiceimport';
		$newDir = DOL_DATA_ROOT.'/auvorechnungsimport';
		if (is_dir($oldDir) && count(glob($newDir.'/[0-9]*', GLOB_ONLYDIR) ?: array()) === 0) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
			dolCopyDir($oldDir, $newDir, '0', 0);
		}
	}

	/**
	 * Grants the new permissions to the users and groups that held the
	 * matching permissions of InvoiceImport (50041001 → 19420301, 50041002 →
	 * 19420302). Run after _init(), once the new permissions exist. Idempotent.
	 */
	private function migrateRightsFromInvoiceImport()
	{
		$map = array(50041001 => (int) ($this->numero.'01'), 50041002 => (int) ($this->numero.'02'));
		foreach ($map as $oldId => $newId) {
			foreach (array('user_rights' => 'fk_user', 'usergroup_rights' => 'fk_usergroup') as $table => $col) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX.$table." (entity, ".$col.", fk_id)";
				$sql .= " SELECT o.entity, o.".$col.", ".$newId." FROM ".MAIN_DB_PREFIX.$table." as o";
				$sql .= " WHERE o.fk_id = ".$oldId;
				$sql .= " AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX.$table." as n WHERE n.entity = o.entity AND n.".$col." = o.".$col." AND n.fk_id = ".$newId.")";
				$this->db->query($sql);
			}
		}
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
