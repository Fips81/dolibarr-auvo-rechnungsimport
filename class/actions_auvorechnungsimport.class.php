<?php
/*
 * Email Collector integration for AuVo Rechnungsimport.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
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

	/** Add the import operation to Email Collector's operation list. */
	public function addMoreActionsEmailCollector($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$langs->load('auvorechnungsimport@auvorechnungsimport');
		$this->results = array('hookauvorechnungsimport' => $langs->trans('AuvoRechnungsimportEmailCollectorAction'));

		return 0;
	}

	/** Queue supported message attachments for invoice import. */
	public function doCollectImapOneCollector($parameters, &$object, &$action, $hookmanager)
	{
		if ($action !== 'hookauvorechnungsimport') {
			return 0;
		}

		global $conf, $langs, $user;
		$langs->load('auvorechnungsimport@auvorechnungsimport');

		// Email Collector's test run rolls its database transaction back. Do not
		// write files or launch a queue worker from that simulated run.
		if (GETPOST('action', 'aZ09') === 'collecttest') {
			$this->appendEmailCollectorLog($object, $langs->trans('AuvoRechnungsimportEmailTestSkipped'));
			return 0;
		}

		$attachments = isset($parameters['attachments']) && is_array($parameters['attachments']) ? $parameters['attachments'] : array();
		$spamHeaderName = trim(getDolGlobalString('AUVORECHNUNGSIMPORT_SPAM_HEADER'));
		$spamHeaderValue = trim(getDolGlobalString('AUVORECHNUNGSIMPORT_SPAM_VALUE'));
		$spamFlagged = false;
		if ($spamHeaderName !== '' && $spamHeaderValue !== '' && !empty($parameters['header'])) {
			$headerPattern = '/^'.preg_quote($spamHeaderName, '/').':\\s*'.preg_quote($spamHeaderValue, '/').'\\s*$/im';
			$spamFlagged = (bool) preg_match($headerPattern, (string) $parameters['header']);
		}
		$files = array();
		$readErrors = array();
		foreach ($attachments as $key => $attachment) {
			$name = '';
			$content = '';
			if (is_object($attachment)) {
				try {
					$name = (string) $attachment->getName();
				} catch (Throwable $e) {
					$readErrors[] = $langs->trans('AuvoRechnungsimportEmailReadFailed', is_scalar($key) ? (string) $key : 'attachment');
					dol_syslog('AuVo Rechnungsimport Email Collector: could not read attachment name: '.$e->getMessage(), LOG_ERR);
					continue;
				}
			} elseif (is_string($attachment)) {
				$name = (string) $key;
				$content = $attachment;
			}

			$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if (!in_array($extension, array('pdf', 'xml'), true)) {
				continue;
			}
			if (is_object($attachment)) {
				try {
					$content = (string) $attachment->getContent();
				} catch (Throwable $e) {
					$readErrors[] = $langs->trans('AuvoRechnungsimportEmailReadFailed', $name);
					dol_syslog('AuVo Rechnungsimport Email Collector: could not read supported attachment '.$name.': '.$e->getMessage(), LOG_ERR);
					continue;
				}
			}
			if ($content === '') {
				$readErrors[] = $langs->trans('AuvoRechnungsimportEmailReadFailed', $name !== '' ? $name : 'attachment');
				continue;
			}

			$filename = dol_sanitizeFileName($name);
			if ($filename === '' || $filename === '.' || $filename === '..') {
				$filename = 'email-attachment.'.$extension;
			}
			$filename = dol_trunc(pathinfo($filename, PATHINFO_FILENAME), 200).'.'.$extension;
			$files[] = array('name' => $filename, 'content' => $content);
		}

		if (!empty($readErrors)) {
			return $this->failEmailCollectorImport(implode('; ', $readErrors));
		}
		if (empty($files)) {
			$message = $langs->trans('AuvoRechnungsimportEmailNoSupportedAttachments');
			$this->appendEmailCollectorLog($object, $message);
			dol_syslog('AuVo Rechnungsimport Email Collector: '.$message.' Email skipped.', LOG_INFO);
			return 1;
		}

		$writtenFiles = array();
		foreach ($files as $file) {
			$import = new ImportedInvoice($this->db);
			$import->date_creation = dol_now();
			$import->fk_user_creat = !empty($user->id) ? (int) $user->id : 1;
			$import->original_filename = $file['name'];
			$import->source = 'emailcollector';
			$import->filepath = '';
			$import->status = $spamFlagged ? ImportedInvoice::STATUS_SPAM_REVIEW : ImportedInvoice::STATUS_QUEUED;
			if ($spamFlagged) {
				$import->extracted_data = json_encode(array('spam_detection' => array('header' => $spamHeaderName, 'value' => $spamHeaderValue)));
			}

			if ($import->create($user) <= 0) {
				$this->removeEmailCollectorFiles($writtenFiles);
				dol_syslog('AuVo Rechnungsimport Email Collector: could not create import row for '.$file['name'].': '.$this->db->lasterror(), LOG_ERR);
				return $this->failEmailCollectorImport($langs->trans('AuvoRechnungsimportEmailCreateFailed', $file['name']));
			}

			$directory = DOL_DATA_ROOT.'/auvorechnungsimport/'.$import->id;
			if (dol_mkdir($directory) < 0) {
				$this->removeEmailCollectorFiles($writtenFiles);
				dol_syslog('AuVo Rechnungsimport Email Collector: could not create storage directory for '.$file['name'], LOG_ERR);
				return $this->failEmailCollectorImport($langs->trans('AuvoRechnungsimportEmailStoreFailed', $file['name']));
			}

			$path = $directory.'/'.$file['name'];
			$tempDirectory = $conf->admin->dir_temp;
			if (empty($tempDirectory) || dol_mkdir($tempDirectory) < 0) {
				@rmdir($directory);
				$this->removeEmailCollectorFiles($writtenFiles);
				dol_syslog('AuVo Rechnungsimport Email Collector: could not access Dolibarr temporary directory for '.$file['name'], LOG_ERR);
				return $this->failEmailCollectorImport($langs->trans('AuvoRechnungsimportEmailStoreFailed', $file['name']));
			}
			$tempFile = @tempnam($tempDirectory, 'auvorechnungsimport_email_');
			$bytesWritten = $tempFile !== false ? @file_put_contents($tempFile, $file['content'], LOCK_EX) : false;
			if ($bytesWritten !== strlen($file['content'])) {
				if ($tempFile !== false) {
					@unlink($tempFile);
				}
				@rmdir($directory);
				$this->removeEmailCollectorFiles($writtenFiles);
				dol_syslog('AuVo Rechnungsimport Email Collector: could not stage attachment '.$file['name'].' for Dolibarr file checks', LOG_ERR);
				return $this->failEmailCollectorImport($langs->trans('AuvoRechnungsimportEmailStoreFailed', $file['name']));
			}

			try {
				$virusErrors = dolCheckVirus($tempFile, $path);
			} catch (Throwable $e) {
				@unlink($tempFile);
				@rmdir($directory);
				$this->removeEmailCollectorFiles($writtenFiles);
				dol_syslog('AuVo Rechnungsimport Email Collector: Dolibarr file checks failed for '.$file['name'].': '.$e->getMessage(), LOG_ERR);
				return $this->failEmailCollectorImport($langs->trans('AuvoRechnungsimportEmailStoreFailed', $file['name']));
			}
			if (!empty($virusErrors)) {
				@unlink($tempFile);
				@rmdir($directory);
				$this->removeEmailCollectorFiles($writtenFiles);
				dol_syslog('AuVo Rechnungsimport Email Collector: Dolibarr file checks rejected attachment '.$file['name'].': '.implode(', ', $virusErrors), LOG_ERR);
				return $this->failEmailCollectorImport($langs->trans('AuvoRechnungsimportEmailStoreFailed', $file['name']));
			}

			$copied = !file_exists($path) && @copy($tempFile, $path);
			@unlink($tempFile);
			clearstatcache(true, $path);
			if (!$copied || @filesize($path) !== strlen($file['content'])) {
				@unlink($path);
				@rmdir($directory);
				$this->removeEmailCollectorFiles($writtenFiles);
				dol_syslog('AuVo Rechnungsimport Email Collector: could not store attachment '.$file['name'], LOG_ERR);
				return $this->failEmailCollectorImport($langs->trans('AuvoRechnungsimportEmailStoreFailed', $file['name']));
			}
			$writtenFiles[] = $path;
			@chmod($path, octdec(getDolGlobalString('MAIN_UMASK', '0664')));

			$import->filepath = $import->id.'/'.$file['name'];
			if ($import->update($user) <= 0) {
				$this->removeEmailCollectorFiles($writtenFiles);
				dol_syslog('AuVo Rechnungsimport Email Collector: could not update import row for '.$file['name'].': '.$this->db->lasterror(), LOG_ERR);
				return $this->failEmailCollectorImport($langs->trans('AuvoRechnungsimportEmailCreateFailed', $file['name']));
			}
		}

		if ($spamFlagged) {
			$this->appendEmailCollectorLog($object, $langs->trans('AuvoRechnungsimportEmailSpamHeld', count($files)));
		} else {
			$this->scheduleEmailCollectorQueueStart(count($files));
			$this->appendEmailCollectorLog($object, $langs->trans('AuvoRechnungsimportEmailQueued', count($files)));
		}
		return 1;
	}

	/** Add a readable operation result to Email Collector's debug log. */
	private function appendEmailCollectorLog($object, $message)
	{
		if (!is_object($object)) {
			return;
		}
		if (!isset($object->debuginfo)) {
			$object->debuginfo = '';
		}
		$object->debuginfo .= '<br>'.dol_escape_htmltag($message);
	}

	/** Set useful diagnostics consumed by Email Collector when an action fails. */
	private function failEmailCollectorImport($message)
	{
		$this->error = $message;
		$this->errors = array($message);
		$this->resprints = $message;
		return -1;
	}

	/** Remove files created by a hook action that is about to roll back. */
	private function removeEmailCollectorFiles($files)
	{
		foreach ($files as $path) {
			@unlink($path);
			@rmdir(dirname($path));
		}
	}

	/** Start the detached worker after Email Collector commits its transaction. */
	private function scheduleEmailCollectorQueueStart($queuedCount)
	{
		static $registered = false;
		static $totalQueued = 0;
		$totalQueued += $queuedCount;
		if ($registered) {
			return;
		}
		$registered = true;

		register_shutdown_function(static function () use (&$totalQueued) {
			if (!auvorechnungsimport_queue_start()) {
				dol_syslog('AuVo Rechnungsimport Email Collector: '.$totalQueued.' import(s) remain queued; the background worker could not be started. Check PHP CLI and exec() configuration.', LOG_ERR);
			}
		});
	}
}
