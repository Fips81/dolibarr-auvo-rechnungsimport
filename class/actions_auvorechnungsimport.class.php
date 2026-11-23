<?php
/*
 * Email Collector integration for AuVo Rechnungsimport.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once __DIR__.'/importedinvoice.class.php';
require_once __DIR__.'/../core/lib/auvorechnungsimport.lib.php';

class ActionsAuvoRechnungsimport extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	public function __construct($db)
	{
		$this->db = $db;
	}

	/** Prevent deleting a supplier invoice once its validation is in BlockedLog. */
	public function isErasable($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		if (($object->element ?? '') !== 'invoice_supplier' || empty($object->id)) return 0;
		if ($this->hasSupplierValidationLog($object->id)) {
			$hookmanager->resArray['result'] = -10;
			$hookmanager->resArray['error'] = $langs->trans('AuvoRechnungsimportErrLoggedInvoiceImmutable');
			return 1;
		}
		return 0;
	}

	/** Keep the core button bar, but hide its Modify action for logged invoices. */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		if (($object->element ?? '') !== 'invoice_supplier' || empty($object->id) || !$this->hasSupplierValidationLog($object->id)) {
			return 0;
		}
		$ventilExportCompta = $object->getVentilExportCompta();
		if ($object->status == FactureFournisseur::STATUS_VALIDATED && $action != 'confirm_edit'
			&& $object->getSommePaiement() == 0 && ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer')) && $ventilExportCompta == 0) {
			// Dolibarr has no per-button hook here. Keep its action bar intact and
			// hide only the core Modify link for this invoice; doActions below also
			// rejects a manually constructed edit URL.
			$this->resprints = '<style>.tabsAction a[href*="id='.((int) $object->id).'&action=edit"]{display:none!important}</style>';
		}
		return 0;
	}

	/** Block direct navigation to the edit form, not only the visible button. */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		if (($object->element ?? '') === 'invoice_supplier' && $action === 'edit' && !empty($object->id) && $this->hasSupplierValidationLog($object->id)) {
			setEventMessages($langs->trans('AuvoRechnungsimportErrLoggedInvoiceImmutable'), null, 'errors');
			$action = '';
			return 1;
		}
		return 0;
	}

	private function hasSupplierValidationLog($invoiceId)
	{
		if (!isModEnabled('blockedlog')) return false;
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."blockedlog WHERE action = 'BILL_SUPPLIER_VALIDATE' AND element = 'invoice_supplier' AND fk_object = ".((int) $invoiceId).$this->db->plimit(1);
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/** Add this module action to Email Collector's operation list. */
	public function addMoreActionsEmailCollector($parameters, &$object, &$action, $hookmanager)
	{
		$langs = $GLOBALS['langs'];
		$langs->load('auvorechnungsimport@auvorechnungsimport');
		$this->results = array('hookauvorechnungsimport' => $langs->trans('AuvoRechnungsimportEmailCollectorAction'));
		return 0;
	}

	/** Import PDF/XML attachments delivered by Email Collector. */
	public function doCollectImapOneCollector($parameters, &$object, &$action, $hookmanager)
	{
		if ($action !== 'hookauvorechnungsimport') {
			return 0;
		}
		global $conf, $langs, $user;
		$langs->load('auvorechnungsimport@auvorechnungsimport');
		$attachments = isset($parameters['attachments']) && is_array($parameters['attachments']) ? $parameters['attachments'] : array();
		$spamHeaderName = trim(getDolGlobalString('AUVORECHNUNGSIMPORT_SPAM_HEADER'));
		$spamHeaderValue = trim(getDolGlobalString('AUVORECHNUNGSIMPORT_SPAM_VALUE'));
		$spamFlagged = false;
		if ($spamHeaderName !== '' && $spamHeaderValue !== '' && !empty($parameters['header'])) {
			$headerPattern = '/^'.preg_quote($spamHeaderName, '/').':\\s*'.preg_quote($spamHeaderValue, '/').'\\s*$/im';
			$spamFlagged = (bool) preg_match($headerPattern, (string) $parameters['header']);
		}
		if (!$attachments) {
			$this->error = $langs->trans('AuvoRechnungsimportEmailNoAttachments');
			return -1;
		}

		$root = DOL_DATA_ROOT.'/auvorechnungsimport';
		$accepted = 0;
		$errors = array();
		foreach ($attachments as $key => $attachment) {
			$name = '';
			$content = '';
			if (is_object($attachment)) {
				try {
					$name = (string) $attachment->getName();
					$content = (string) $attachment->getContent();
				} catch (Throwable $e) {
					continue;
				}
			} elseif (is_string($attachment)) {
				$name = (string) $key;
				$content = $attachment;
			}
			$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if (!in_array($extension, array('pdf', 'xml'), true) || $content === '') {
				continue;
			}
			$safeName = dol_trunc(dol_sanitizeFileName($name), 220);
			if ($safeName === '') {
				$safeName = 'email-attachment.'.$extension;
			}
			$import = new ImportedInvoice($this->db);
			$import->date_creation = dol_now();
			$import->fk_user_creat = !empty($user->id) ? (int) $user->id : 1;
			$import->original_filename = $safeName;
			$import->source = 'emailcollector';
			$import->filepath = '';
			$import->status = $spamFlagged ? ImportedInvoice::STATUS_SPAM_REVIEW : ImportedInvoice::STATUS_QUEUED;
			if ($spamFlagged) {
				$import->extracted_data = json_encode(array('spam_detection' => array('header' => $spamHeaderName, 'value' => $spamHeaderValue)));
			}
			$this->db->begin();
			if ($import->create($user) <= 0) {
				$this->db->rollback();
				$errors[] = $safeName;
				continue;
			}
			$dir = $root.'/'.$import->id;
			if (dol_mkdir($dir) < 0 || file_put_contents($dir.'/'.$safeName, $content, LOCK_EX) === false) {
				$this->db->rollback();
				$errors[] = $safeName;
				continue;
			}
			@chmod($dir.'/'.$safeName, octdec(getDolGlobalString('MAIN_UMASK', '0664')));
			$import->filepath = $import->id.'/'.$safeName;
			if ($import->update($user) <= 0) {
				@unlink($dir.'/'.$safeName);
				$this->db->rollback();
				$errors[] = $safeName;
				continue;
			}
			if (auvorechnungsimport_blockedlog_store_received_file($this->db, $import, $dir.'/'.$safeName, $user, 'emailcollector') < 0) {
				@unlink($dir.'/'.$safeName);
				$this->db->rollback();
				$errors[] = $safeName;
				continue;
			}
			$this->db->commit();
			$accepted++;
		}

		if ($accepted === 0) {
			$this->error = $errors ? $langs->trans('AuvoRechnungsimportEmailStoreFailed', implode(', ', $errors)) : $langs->trans('AuvoRechnungsimportEmailNoSupportedAttachments');
			return -1;
		}
		return 1;
	}
}
