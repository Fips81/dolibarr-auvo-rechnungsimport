<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/** Dolibarr Cron entry point for processing queued supplier invoices. */
class AuvoRechnungsimportCron
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var array<int,string> */
	public $errors = array();
	/** @var string */
	public $output = '';

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Process one queued import. OCR may consume significant time;
	 * Dolibarr Cron must execute this method from its CLI runner for long scans.
	 *
	 * @param string $parameters Cron parameters (unused)
	 * @return int 0 on success, -1 on failure
	 */
	public function processQueue($parameters = '')
	{
		@set_time_limit(0);
		@ini_set('memory_limit', '512M');
		require_once __DIR__.'/../core/lib/auvorechnungsimport.lib.php';
		$result = auvorechnungsimport_process_queue($this->db, 1);
		if ($result < 0) {
			$this->error = 'Queue processing failed';
			return -1;
		}
		$this->output = 'Processed '.$result.' queued import(s).';
		return 0;
	}

	/** Send one digest of overdue imports and persist each notification timestamp. */
	public function sendReminders($parameters = '')
	{
		global $conf, $langs;
		$recipient = trim(getDolGlobalString('AUVORECHNUNGSIMPORT_REMINDER_EMAIL'));
		if ($recipient === '' || !isValidEmail($recipient)) {
			$this->output = 'Reminder email is not configured; no reminders sent.';
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		require_once DOL_DOCUMENT_ROOT.'/custom/auvorechnungsimport/class/importedinvoice.class.php';
		$tableImport = MAIN_DB_PREFIX.'auvorechnungsimport_importedinvoice';
		$sql = "SELECT i.rowid, i.ref, i.status, i.original_filename, i.date_creation, i.reminder_count, i.last_reminder";
		$sql .= " FROM ".$tableImport." i";
		$sql .= " WHERE i.entity IN (".getEntity('importedinvoice').")";
		$sql .= " AND i.status IN (".ImportedInvoice::STATUS_QUEUED.",".ImportedInvoice::STATUS_NEEDS_REVIEW.",".ImportedInvoice::STATUS_ERROR.",".ImportedInvoice::STATUS_SPAM_REVIEW.")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$period = max(1, getDolGlobalInt('AUVORECHNUNGSIMPORT_REMINDER_PERIOD_DAYS', 2)) * 86400;
		$now = dol_now();
		$due = array();
		while ($row = $this->db->fetch_object($resql)) {
			$count = (int) $row->reminder_count;
			$last = !empty($row->last_reminder) ? strtotime($row->last_reminder) : strtotime($row->date_creation);
			$interval = $period * (2 ** min($count, 8));
			if ($last !== false && ($now - $last) >= $interval) {
				$due[] = $row;
			}
		}
		$this->db->free($resql);
		if (!$due) {
			$this->output = 'No overdue invoice imports; no reminder sent.';
			return 0;
		}
		$rowsHtml = '';
		foreach ($due as $row) {
			$url = DOL_MAIN_URL_ROOT.'/custom/auvorechnungsimport/auvorechnungsimport_card.php?id='.(int) $row->rowid;
			$statusKey = ((int) $row->status === ImportedInvoice::STATUS_QUEUED) ? 'AuvoRechnungsimportStatusQueued' : (((int) $row->status === ImportedInvoice::STATUS_ERROR) ? 'AuvoRechnungsimportStatusError' : (((int) $row->status === ImportedInvoice::STATUS_SPAM_REVIEW) ? 'AuvoRechnungsimportStatusSpamReview' : 'AuvoRechnungsimportStatusNeedsReview'));
			$rowsHtml .= '<tr>';
			$rowsHtml .= '<td style="padding:12px;border-bottom:1px solid #e5e7eb"><a href="'.dol_escape_htmltag($url).'" style="color:#2457a7;font-weight:600;text-decoration:none">'.dol_escape_htmltag($row->ref).'</a></td>';
			$rowsHtml .= '<td style="padding:12px;border-bottom:1px solid #e5e7eb">'.dol_escape_htmltag($row->original_filename).'</td>';
			$rowsHtml .= '<td style="padding:12px;border-bottom:1px solid #e5e7eb">'.dol_escape_htmltag($langs->trans($statusKey)).'</td>';
			$rowsHtml .= '<td style="padding:12px;border-bottom:1px solid #e5e7eb"><a href="'.dol_escape_htmltag($url).'" style="display:inline-block;padding:8px 12px;background:#2457a7;color:#fff;text-decoration:none;border-radius:4px">Import öffnen</a></td>';
			$rowsHtml .= '</tr>';
		}
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		if ($from === '') {
			$this->error = 'Dolibarr sender email MAIN_MAIL_EMAIL_FROM is not configured.';
			return -1;
		}
		$message = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#263238">';
		$message .= '<div style="max-width:900px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">';
		$message .= '<div style="padding:22px 26px;background:#2457a7;color:#fff"><div style="font-size:20px;font-weight:700">Offene Lieferantenrechnungsimporte</div><div style="margin-top:6px;font-size:14px">'.count($due).' Beleg(e) warten auf Bearbeitung</div></div>';
		$message .= '<div style="padding:22px 26px"><p style="margin:0 0 16px">Bitte prüfe die folgenden überfälligen Belege:</p>';
		$message .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px"><thead><tr style="background:#f4f6f8;text-align:left"><th style="padding:10px 12px">Referenz</th><th style="padding:10px 12px">Datei</th><th style="padding:10px 12px">Status</th><th style="padding:10px 12px"></th></tr></thead><tbody>'.$rowsHtml.'</tbody></table>';
		$message .= '<p style="margin:20px 0 0;color:#667085;font-size:12px">Diese Erinnerung wurde automatisch von Dolibarr versendet.</p></div></div></body></html>';
		$mail = new CMailFile('Offene Lieferantenrechnungsimporte', $recipient, $from, $message, array(), array(), array(), '', '', 0, 1, '', '', '', '', 'auvorechnungsimport');
		if (!$mail->sendfile()) {
			$this->error = 'Could not send reminder digest: '.$mail->error;
			return -1;
		}
		foreach ($due as $row) {
			$count = (int) $row->reminder_count + 1;
			$sql = "UPDATE ".$tableImport." SET reminder_count = ".$count.", last_reminder = '".$this->db->idate($now)."' WHERE rowid = ".((int) $row->rowid);
			if (!$this->db->query($sql)) {
				$this->error = 'Reminder sent, but could not save reminder count: '.$this->db->lasterror();
				return -1;
			}
		}
		$this->output = count($due).' overdue invoice import(s) included in the reminder digest.';
		return 0;
	}
}
