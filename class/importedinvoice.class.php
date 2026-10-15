<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * CRUD class for one uploaded supplier invoice awaiting (or past) import.
 */
class ImportedInvoice extends CommonObject
{
	public $module = 'auvorechnungsimport';
	public $element = 'importedinvoice';
	public $table_element = 'auvorechnungsimport_importedinvoice';
	public $picto = 'fa-file-invoice';
	public $isextrafieldmanaged = 0;
	public $ismultientitymanaged = 1;

	const STATUS_PROCESSING = 0;
	const STATUS_NEEDS_REVIEW = 1;
	const STATUS_IMPORTED = 2;
	const STATUS_MATCHED = 3;
	// Uploaded, waiting for the background analysis (scripts/process_queue.php)
	const STATUS_QUEUED = 4;
	const STATUS_ERROR = 9;

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1),
		'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'showoncombobox' => 1),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'default' => '1', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 15, 'index' => 1),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'position' => 25, 'notnull' => 0, 'visible' => 0),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 0),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'position' => 35, 'notnull' => 0, 'visible' => 0),
		'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'default' => '0', 'visible' => 1, 'arrayofkeyval' => array(
			self::STATUS_QUEUED => 'AuvoRechnungsimportStatusQueued',
			self::STATUS_PROCESSING => 'AuvoRechnungsimportStatusProcessing',
			self::STATUS_NEEDS_REVIEW => 'AuvoRechnungsimportStatusNeedsReview',
			self::STATUS_MATCHED => 'AuvoRechnungsimportStatusMatched',
			self::STATUS_IMPORTED => 'AuvoRechnungsimportStatusImported',
			self::STATUS_ERROR => 'AuvoRechnungsimportStatusError',
		)),
		'original_filename' => array('type' => 'varchar(255)', 'label' => 'AuvoRechnungsimportOriginalFilename', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'visible' => 1),
		'filepath' => array('type' => 'varchar(255)', 'label' => 'AuvoRechnungsimportFilepath', 'enabled' => 1, 'position' => 55, 'notnull' => 1, 'visible' => 0),
		'extraction_method' => array('type' => 'varchar(32)', 'label' => 'AuvoRechnungsimportExtractionMethod', 'enabled' => 1, 'position' => 60, 'notnull' => 0, 'visible' => 1),
		'extracted_data' => array('type' => 'text', 'label' => 'AuvoRechnungsimportExtractedData', 'enabled' => 1, 'position' => 65, 'notnull' => 0, 'visible' => 0),
		'fk_soc' => array('type' => 'integer:Societe:societe/class/societe.class.php', 'label' => 'ThirdParty', 'picto' => 'company', 'enabled' => 1, 'position' => 70, 'notnull' => -1, 'visible' => 1, 'index' => 1),
		'supplier_match_confidence' => array('type' => 'varchar(32)', 'label' => 'AuvoRechnungsimportMatchConfidence', 'enabled' => 1, 'position' => 75, 'notnull' => 0, 'visible' => 1),
		'fk_facture_fourn' => array('type' => 'integer:FactureFournisseur:fourn/class/fournisseur.facture.class.php', 'label' => 'AuvoRechnungsimportSupplierInvoice', 'enabled' => 1, 'position' => 80, 'notnull' => -1, 'visible' => 1),
		'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => 1, 'position' => 90, 'notnull' => 0, 'visible' => 0),
	);

	public $rowid;
	public $ref;
	public $entity;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $status;
	public $original_filename;
	public $filepath;
	public $extraction_method;
	public $extracted_data;
	public $fk_soc;
	public $supplier_match_confidence;
	public $fk_facture_fourn;
	public $note_private;

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param User $user
	 * @param int $notrigger
	 * @return int
	 */
	public function create(User $user, $notrigger = 0)
	{
		if (empty($this->ref)) {
			$this->ref = '(PROV)';
		}
		$result = $this->createCommon($user, $notrigger);
		if ($result > 0 && $this->ref === '(PROV)') {
			$this->ref = '(PROV'.$this->id.')';
			$this->updateCommon($user, 1);
		}
		return $result;
	}

	/**
	 * @param int $id
	 * @param ?string $ref
	 * @return int
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, $ref);
	}

	/**
	 * @param User $user
	 * @param int $notrigger
	 * @return int
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * @param User $user
	 * @param int $notrigger
	 * @return int
	 */
	public function delete(User $user, $notrigger = 0)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * @param string $sortorder
	 * @param string $sortfield
	 * @param int $limit
	 * @param int $offset
	 * @param string $filter
	 * @return array<int,self>|int
	 */
	public function fetchAll($sortorder = 'DESC', $sortfield = 't.rowid', $limit = 100, $offset = 0, $filter = '')
	{
		$records = array();
		$sql = "SELECT ".$this->getFieldList('t');
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.entity IN (".getEntity($this->element).")";
		if (!empty($filter)) {
			$sql .= " AND (".$filter.")";
		}
		if (!empty($sortfield)) {
			$sql .= $this->db->order($sortfield, $sortorder);
		}
		if ($limit) {
			$sql .= $this->db->plimit($limit, $offset);
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$line = new self($this->db);
			$line->setVarsFromFetchObj($obj);
			$records[$line->id] = $line;
		}
		return $records;
	}

	/**
	 * @param int $withpicto
	 * @param string $option
	 * @return string
	 */
	public function getNomUrl($withpicto = 0, $option = '')
	{
		$url = dol_buildpath('/auvorechnungsimport/auvorechnungsimport_card.php', 1).'?id='.$this->id;
		$label = '<u>'.$this->ref.'</u>';
		$link = '<a href="'.$url.'">';
		$linkend = '</a>';
		$result = $link;
		if ($withpicto) {
			$result .= img_picto($label, $this->picto, 'class="paddingright"').($withpicto != 2 ? $this->ref : '');
		} else {
			$result .= $this->ref;
		}
		$result .= $linkend;
		return $result;
	}

	/**
	 * @param int $mode 0=label, 1=picto, 2=picto+label, 3=picto+label short, 5=short label+picto
	 * @return string
	 */
	public function getLibStatut($mode = 0)
	{
		global $langs;
		$labels = array(
			self::STATUS_QUEUED => $langs->trans('AuvoRechnungsimportStatusQueued'),
			self::STATUS_PROCESSING => $langs->trans('AuvoRechnungsimportStatusProcessing'),
			self::STATUS_NEEDS_REVIEW => $langs->trans('AuvoRechnungsimportStatusNeedsReview'),
			self::STATUS_MATCHED => $langs->trans('AuvoRechnungsimportStatusMatched'),
			self::STATUS_IMPORTED => $langs->trans('AuvoRechnungsimportStatusImported'),
			self::STATUS_ERROR => $langs->trans('AuvoRechnungsimportStatusError'),
		);
		$status = isset($labels[$this->status]) ? $labels[$this->status] : $this->status;
		if ($mode == 0) {
			return $status;
		}
		$classes = array(
			self::STATUS_QUEUED => 'status0',
			self::STATUS_PROCESSING => 'status3',
			self::STATUS_NEEDS_REVIEW => 'status1',
			self::STATUS_MATCHED => 'status2',
			self::STATUS_IMPORTED => 'status4',
			self::STATUS_ERROR => 'status8',
		);
		$cssclass = isset($classes[$this->status]) ? $classes[$this->status] : 'status0';
		return '<span class="badge '.$cssclass.'">'.$status.'</span>';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getExtractedData()
	{
		if (empty($this->extracted_data)) {
			return array();
		}
		$data = json_decode($this->extracted_data, true);
		return is_array($data) ? $data : array();
	}
}
