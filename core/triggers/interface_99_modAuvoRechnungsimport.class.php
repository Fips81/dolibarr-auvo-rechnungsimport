<?php
/* Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de> */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

/** Keep validated supplier invoices immutable after they are recorded in BlockedLog. */
class InterfaceAuvoRechnungsimportTriggers extends DolibarrTriggers
{
	/** @var DoliDB */
	protected $db;

	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'financial';
		$this->description = 'Protect BlockedLog-recorded supplier invoices from modification, unvalidation, or deletion.';
		$this->version = '0.3.0';
		$this->picto = 'fa-file-invoice';
	}

	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf): int
	{
		if (!isModEnabled('blockedlog')) return 0;

		$invoiceId = 0;
		$headerActions = array('BILL_SUPPLIER_MODIFY', 'BILL_SUPPLIER_UNVALIDATE', 'BILL_SUPPLIER_DELETE');
		$lineActions = array('LINEBILL_SUPPLIER_CREATE', 'LINEBILL_SUPPLIER_MODIFY', 'LINEBILL_SUPPLIER_DELETE');
		if (in_array($action, $headerActions, true) && ($object->element ?? '') === 'invoice_supplier') {
			$invoiceId = (int) ($object->id ?? 0);
		} elseif (in_array($action, $lineActions, true) && ($object->element ?? '') === 'facture_fourn_det') {
			$invoiceId = (int) ($object->fk_facture_fourn ?? 0);
		} else {
			return 0;
		}
		if ($invoiceId <= 0) return 0;

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "blockedlog WHERE action = 'BILL_SUPPLIER_VALIDATE' AND element = 'invoice_supplier' AND fk_object = " . $invoiceId . $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			$object->error = $langs->trans('AuvoRechnungsimportErrLoggedInvoiceImmutable');
			$object->errors[] = $object->error;
			return -1;
		}
		return 0;
	}
}
