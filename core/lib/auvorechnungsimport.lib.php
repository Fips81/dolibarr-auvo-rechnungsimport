<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Extraction pipeline for incoming supplier invoices:
 *   0. XML e-invoice (XRechnung / Peppol, CII or UBL) - read exactly
 *   1. PDF with embedded Factur-X/ZUGFeRD XML (exact, no OCR needed)
 *   2. Digital text layer of the PDF (smalot/pdfparser)
 *   3. Tesseract OCR on rendered pages (for scanned-only PDFs)
 * Whichever step first yields a usable set of fields wins; later steps are
 * skipped once we have a VAT number or invoice total.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
if (file_exists(__DIR__.'/../../vendor/autoload.php')) {
	require_once __DIR__.'/../../vendor/autoload.php';
}
require_once __DIR__.'/auvorechnungsimport_einvoice.php';
require_once __DIR__.'/auvorechnungsimport_view.php';

/**
 * Embedded Factur-X/ZUGFeRD XML of a PDF, or null if there is none.
 *
 * @param string $pdfpath Absolute path to the PDF file
 * @return ?string
 */
function auvorechnungsimport_pdf_embedded_xml($pdfpath)
{
	if (!class_exists('\Atgp\FacturX\Reader')) {
		return null;
	}
	$binary = @file_get_contents($pdfpath);
	if ($binary === false) {
		return null;
	}
	try {
		$reader = new \Atgp\FacturX\Reader();
		$xml = $reader->extractXML($binary, false);
	} catch (\Throwable $e) {
		return null;
	}
	return empty($xml) ? null : $xml;
}

/**
 * Structured e-invoice behind an import file: the XML itself, or the
 * Factur-X/ZUGFeRD XML embedded in a PDF. Null for ordinary PDFs.
 *
 * @param string $path Absolute path of the uploaded file
 * @return ?array Parsed e-invoice (auvorechnungsimport_parse_einvoice)
 */
function auvorechnungsimport_load_einvoice($path)
{
	if (auvorechnungsimport_is_xml_file($path)) {
		return auvorechnungsimport_parse_einvoice((string) @file_get_contents($path));
	}
	$xml = auvorechnungsimport_pdf_embedded_xml($path);
	return $xml !== null ? auvorechnungsimport_parse_einvoice($xml) : null;
}

/**
 * True for an uploaded XML file (by extension, or by content for files
 * without a usable extension).
 *
 * @param string $path
 * @return bool
 */
function auvorechnungsimport_is_xml_file($path)
{
	if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xml') {
		return true;
	}
	$head = (string) @file_get_contents($path, false, null, 0, 64);
	return (bool) preg_match('/^(\xEF\xBB\xBF)?\s*<(\?xml|[A-Za-z])/', $head) && strpos($head, '%PDF') === false;
}

/**
 * Try to read an embedded Factur-X/ZUGFeRD CII XML from the PDF.
 *
 * @param string $pdfpath Absolute path to the PDF file
 * @return ?array<string,mixed> Extracted fields, or null if no embedded XML found
 */
function auvorechnungsimport_extract_facturx($pdfpath)
{
	$xml = auvorechnungsimport_pdf_embedded_xml($pdfpath);
	$inv = $xml !== null ? auvorechnungsimport_parse_einvoice($xml) : null;
	if ($inv === null) {
		return null;
	}
	$fields = auvorechnungsimport_einvoice_to_fields($inv);
	if (empty($fields['supplier_vat']) && $fields['amount_ttc'] === null && empty($fields['invoice_ref'])) {
		return null;
	}
	return $fields;
}

/**
 * Extract the digital text layer of a PDF (no OCR, works only if the PDF was
 * not purely a scanned image).
 *
 * @param string $pdfpath
 * @return string
 */
function auvorechnungsimport_extract_pdftext($pdfpath)
{
	if (!class_exists('\Smalot\PdfParser\Parser')) {
		return '';
	}
	try {
		$parser = new \Smalot\PdfParser\Parser();
		$pdf = $parser->parseFile($pdfpath);
		$text = $pdf->getText();
	} catch (\Throwable $e) {
		return '';
	}
	return trim((string) $text);
}

/**
 * OCR fallback for scanned PDFs: render the first pages to PNG via pdftoppm,
 * then run tesseract on each page. Invoice header data (supplier, amounts,
 * dates) is virtually always on page 1, but the invoice lines can span all
 * pages - hence the configurable page limit.
 *
 * Besides the plain text, a layout text is rebuilt from tesseract's word
 * coordinates (TSV output of the same run): words of one visual row on one
 * line, at a column proportional to their x position - like pdftotext
 * -layout. The line detection needs that (rows torn apart by tesseract's block
 * segmentation, indentation of continuation lines).
 *
 * @param string $pdfpath
 * @param string $langs Tesseract language codes, e.g. "deu+fra+eng"
 * @param int $maxPages
 * @param ?string $layoutText Receives the layout text
 * @return string
 */
function auvorechnungsimport_extract_ocr($pdfpath, $langs = 'deu+fra+eng', $maxPages = 2, &$layoutText = null)
{
	$layoutText = '';
	if (!function_exists('shell_exec') || !function_exists('proc_open')) {
		return '';
	}
	$tmpdir = sys_get_temp_dir().'/auvorechnungsimport_'.bin2hex(random_bytes(8));
	if (!@mkdir($tmpdir, 0700, true)) {
		return '';
	}

	$text = '';
	try {
		$prefix = $tmpdir.'/page';
		$cmd = 'pdftoppm -png -r 300 -f 1 -l '.max(1, (int) $maxPages).' '.escapeshellarg($pdfpath).' '.escapeshellarg($prefix).' 2>&1';
		exec($cmd, $output, $retval);
		if ($retval !== 0) {
			return '';
		}

		$pages = glob($prefix.'*.png');
		sort($pages);
		foreach ($pages as $page) {
			$outbase = $page.'.ocrout';
			$safeLangs = preg_replace('/[^a-z+]/i', '', $langs);
			$cmd = 'tesseract '.escapeshellarg($page).' '.escapeshellarg($outbase).' -l '.escapeshellarg($safeLangs ?: 'eng').' txt tsv 2>&1';
			exec($cmd, $output2, $retval2);
			$txtfile = $outbase.'.txt';
			if ($retval2 === 0 && is_readable($txtfile)) {
				$text .= file_get_contents($txtfile)."\n";
			}
			$tsvfile = $outbase.'.tsv';
			if ($retval2 === 0 && is_readable($tsvfile)) {
				$layoutText .= auvorechnungsimport_tsv_to_layout(file_get_contents($tsvfile))."\n";
			}
		}
	} finally {
		foreach (glob($tmpdir.'/*') ?: array() as $f) {
			@unlink($f);
		}
		@rmdir($tmpdir);
	}

	return trim($text);
}

/**
 * Heuristic field extraction from plain text (digital layer or OCR output).
 *
 * @param string $text
 * @return array<string,mixed>
 */
function auvorechnungsimport_parse_fields($text)
{
	$result = array(
		'supplier_name' => '',
		'supplier_name_confident' => false,
		'supplier_vat' => '',
		'invoice_ref' => '',
		'invoice_date' => null,
		'amount_ttc' => null,
		'amount_ht' => null,
		'currency' => '',
	);
	if (empty($text)) {
		return $result;
	}

	// VAT / tax ID: only trust a number that sits right next to a recognizable label -
	// a blind scan for "2 letters + digits" shaped tokens also matches product codes
	// (e.g. "XP 05") and other incidental text on the invoice.
	//
	// Two separate passes, EU VAT-style labels tried first: German invoices commonly
	// print both a national "Steuernummer" (not usable as a VATIN) and the actual
	// "USt-IdNr./Id-Nr." next to each other on the same line (e.g. "Steuer-Nr.:
	// 316/5750/1282 - Id.-Nr.: DE 293 640 208") - a single combined regex would match
	// whichever label happens to come first in the text, which is often the wrong one.
	$vatLabels = 'USt[.\s-]*Ident(?:ifikations)?[.\s-]*Nr\.?|USt-?IdNr\.?|UStIdNr\.?|Umsatzsteuer-?Identifikationsnummer|Umsatzsteuer-?ID(?:Nr\.?)?|Id[.\s]*-?Nr\.?|UID|VAT[ -]?(?:ID|No\.?|Reg(?:istration)?\.?\s*No\.?)?|TVA(?:\s*intracommunautaire)?|N°?\s*TVA';
	$taxRefLabels = 'Steuernummer|Steuer-?Nr\.?|SIRET|SIREN';
	foreach (array($vatLabels, $taxRefLabels) as $labels) {
		if (preg_match('/(?:'.$labels.')[ \t]*[:.]?[ \t]*([A-Z]{0,2}[ \t]?[0-9][0-9 \t\/]{3,18})/iu', $text, $m)) {
			$candidate = auvorechnungsimport_normalize_vat(trim($m[1]));
			if (preg_match('/\d{5,}/', $candidate)) {
				$result['supplier_vat'] = $candidate;
				break;
			}
		}
	}

	// Invoice number: look for a label followed by a token.
	if (preg_match('/(?:Rechnung(?:s)?[ \t]*-?Nr\.?|Rechnungsnummer|Beleg(?:s)?[ \t]*-?Nr\.?|Belegnummer|Invoice\s*No\.?|Facture\s*n[°o]\.?|N[°o]\s*de\s*facture)[ \t]*[:.]?[ \t]*([A-Za-z0-9.\-\/]{3,30})/iu', $text, $m)) {
		$result['invoice_ref'] = trim($m[1]);
	}

	// Date: dd.mm.yyyy, dd/mm/yyyy, or yyyy-mm-dd - take the first plausible one.
	if (preg_match('/\b(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})\b/', $text, $m)) {
		$result['invoice_date'] = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
	} elseif (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m)) {
		$result['invoice_date'] = $m[0];
	}

	// Total amount: label followed by a number, optionally with currency symbol.
	if (preg_match('/(?:Gesamtbetrag|Gesamtpreis|Gesamtsumme|Rechnungsbetrag|Rechnungswert|Rechnungsendbetrag|Bruttorechnungswert|Endbetrag|Zahlbetrag|Zu\s*zahlen(?:der\s*Betrag)?|Total\s*TTC|Total\s*à\s*payer|Total\s*due|Montant\s*total)[^\d\n]{0,10}([0-9]{1,3}(?:[.\s][0-9]{3})*[,.][0-9]{2})/iu', $text, $m)) {
		$result['amount_ttc'] = auvorechnungsimport_parse_amount($m[1]);
	}
	// Net total - only used to cross-check detected invoice lines.
	if (preg_match('/(?:Netto[- ]?(?:Gesamtwert|Gesamtbetrag|betrag|summe|wert|Warenwert)|Nettosumme|Summe\s*netto|Gesamt\s*netto|Warenwert(?:\s*netto)?|Total\s*HT|Montant\s*HT|Net\s*(?:amount|total)|Subtotal|Netto(?=\s*:))[^\d\n]{0,40}?([0-9]{1,3}(?:[.\s][0-9]{3})*[,.][0-9]{2})/iu', $text, $m)) {
		$result['amount_ht'] = auvorechnungsimport_parse_amount($m[1]);
	}
	if (strpos($text, '€') !== false || preg_match('/\bEUR\b/i', $text)) {
		$result['currency'] = 'EUR';
	}

	// Supplier name guess: a line naming its own legal form (GmbH, AG, SARL, ...) is
	// virtually always the registered company name itself - far more reliable than
	// "first line of the document", which just as often picks up the buyer's address
	// (multi-column letters: pdftotext's stream order frequently isn't visual order)
	// or the letterhead's e-mail/website line. Falls back to the first readable line
	// only if no such line exists (e.g. sole proprietors without a legal-form suffix).
	$candidateLines = array();
	foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
		$line = trim($line);
		if ($line === '' || dol_strlen($line) > 80 || !preg_match('/\p{L}{2}/u', $line)) {
			continue; // skip empty lines, page markers ("- Seite 1/2 -"), pure numbers/punctuation
		}
		if (preg_match('/^-?\s*(Seite|Page)\b/iu', $line)) {
			continue;
		}
		$candidateLines[] = $line;
	}
	foreach ($candidateLines as $line) {
		if (preg_match('/\b(?:GmbH|AG|KGaA|KG|OHG|e\.?K\.?|UG|SARL|SASU|SAS|SA|Ltd|Inc|LLC)\b/u', $line)) {
			$result['supplier_name'] = $line;
			$result['supplier_name_confident'] = true;
			break;
		}
	}
	if (empty($result['supplier_name']) && !empty($candidateLines)) {
		$result['supplier_name'] = $candidateLines[0];
	}

	return $result;
}

/**
 * Try to match a supplier by VAT number first (exact), then by name (loose).
 *
 * @param DoliDB $db
 * @param string $vat
 * @param string $name
 * @param string $taxId Tax number (BT-32) for sellers without VAT ID
 * @return array{0:?int,1:string} [fk_soc or null, confidence label]
 */
function auvorechnungsimport_match_supplier($db, $vat, $name, $taxId = '')
{
	if (!empty($vat)) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE REPLACE(UPPER(tva_intra), ' ', '') = '".$db->escape($vat)."'";
		$sql .= " AND entity IN (".getEntity('societe').")";
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return array((int) $obj->rowid, 'vat_exact');
		}
	}
	// Tax number (Steuernummer, BT-32) of sellers without VAT ID, e.g. small
	// businesses: Dolibarr keeps it in professional ID 1 for German companies.
	$taxDigits = preg_replace('/[^0-9]/', '', (string) $taxId);
	if (strlen($taxDigits) >= 10) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE REPLACE(REPLACE(REPLACE(idprof1, '/', ''), ' ', ''), '-', '') = '".$db->escape($taxDigits)."'";
		$sql .= " AND fournisseur = 1 AND entity IN (".getEntity('societe').")";
		$sql .= $db->plimit(2);
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) == 1 && ($obj = $db->fetch_object($resql))) {
			return array((int) $obj->rowid, 'taxid_exact');
		}
	}

	// Try the full extracted name first, then a version with legal-form suffixes
	// and country words stripped (e.g. "Muster-Textil Deutschland GmbH" -> "Muster-
	// Textil") - the registered thirdparty name rarely matches the letterhead word
	// for word, but the distinctive core of the name usually still is a substring
	// of it either way.
	foreach (array($name, auvorechnungsimport_strip_legal_suffix($name)) as $i => $candidate) {
		if (empty($candidate) || dol_strlen($candidate) < 3) {
			continue;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE UPPER(nom) LIKE '%".$db->escape(strtoupper($db->escapeforlike($candidate)))."%'";
		$sql .= " AND fournisseur = 1";
		$sql .= " AND entity IN (".getEntity('societe').")";
		$sql .= $db->plimit(2);
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) == 1 && ($obj = $db->fetch_object($resql))) {
			return array((int) $obj->rowid, $i === 0 ? 'name_fuzzy' : 'name_fuzzy_stripped');
		}
	}

	return array(null, 'none');
}

/**
 * Run the full extraction pipeline on an uploaded file and fill the fields
 * directly onto the given ImportedInvoice instance (extraction_method,
 * extracted_data, fk_soc, supplier_match_confidence, status).
 *
 * @param DoliDB $db
 * @param ImportedInvoice $object
 * @param string $abspath Absolute path to the uploaded PDF
 * @return void
 */
function auvorechnungsimport_process_file($db, $object, $abspath)
{
	if (auvorechnungsimport_is_xml_file($abspath)) {
		// XML e-invoice (XRechnung, Peppol): exact data, nothing to guess. An
		// XML that is no EN 16931 invoice ends up as an error record.
		$inv = auvorechnungsimport_parse_einvoice((string) @file_get_contents($abspath));
		$fields = $inv !== null ? auvorechnungsimport_einvoice_to_fields($inv) : array('lines' => array());
		$method = $inv !== null ? 'xml-'.$inv['syntax'] : 'xml';
	} else {
		$fields = auvorechnungsimport_extract_facturx($abspath);
		$method = 'facturx';
	}

	if ($fields === null) {
		$ocrText = '';
		$ocrLayoutText = '';
		$ocrMaxPages = getDolGlobalInt('AUVORECHNUNGSIMPORT_OCR_MAXPAGES', 5);
		// Layout text first: it is cheap (external process) and tells whether the
		// PDF has a text layer at all. For image-only PDFs (scans) smalot is
		// skipped - it would decode the full page images into PHP memory just to
		// find no text, which can exhaust memory_limit on multi-page scans.
		$lineText = auvorechnungsimport_extract_pdftext_layout($abspath);
		$text = ($lineText === null || $lineText !== '') ? auvorechnungsimport_extract_pdftext($abspath) : '';
		$fields = auvorechnungsimport_parse_fields($text);
		$method = 'text';

		// The digital text layer of multi-column/letter-style invoices often comes out
		// of pdftotext in an order that doesn't match the visual layout (e.g. the
		// recipient address block before the sender's own letterhead), which throws
		// off the label-adjacency heuristics. OCR reads in visual order instead, so
		// it's worth the extra seconds whenever the text pass didn't get everything.
		if (empty($fields['supplier_vat']) || empty($fields['invoice_ref']) || empty($fields['amount_ttc'])) {
			$ocrLangs = getDolGlobalString('AUVORECHNUNGSIMPORT_OCR_LANGS', 'deu+fra+eng');
			$ocrText = auvorechnungsimport_extract_ocr($abspath, $ocrLangs, $ocrMaxPages, $ocrLayoutText);
			if (!empty($ocrText)) {
				$ocrFields = auvorechnungsimport_parse_fields($ocrText);
				// supplier_name is never empty just because it's wrong (both passes fall
				// back to "first readable line" if no legal-form-suffixed line is found),
				// so the usual "fill only if empty" rule doesn't help here. Prefer OCR's
				// guess only when it found a legal-form-anchored name ("...GmbH") and the
				// text pass didn't - OCR reads in visual order, so on multi-column layouts
				// where pdftotext's stream order isn't visual it sometimes finds the real
				// letterhead where the text pass's weak first-line fallback didn't.
				if (!$fields['supplier_name_confident'] && !empty($ocrFields['supplier_name_confident'])) {
					$fields['supplier_name'] = $ocrFields['supplier_name'];
				}
				// For the rest, keep whichever pass found something - don't let a
				// successful text-layer read get overwritten by a worse OCR read.
				foreach ($ocrFields as $key => $value) {
					if (!in_array($key, array('supplier_name', 'supplier_name_confident'), true) && empty($fields[$key]) && !empty($value)) {
						$fields[$key] = $value;
					}
				}
				$method = 'text+ocr';
			}
		}

		// Invoice lines: from the layout-preserving text layer, or from OCR for
		// scanned PDFs. Cross-checked against the totals (which are looked for in
		// the line source text too, in case the header pass missed them).
		$lines = auvorechnungsimport_parse_text_lines((string) $lineText);
		if (empty($lines) && $ocrLayoutText !== '') {
			$lineText = $ocrLayoutText;
			$lines = auvorechnungsimport_parse_text_lines($lineText);
		}
		$lineText = (string) $lineText;
		$totals = $fields;
		if (!empty($lines) && (empty($totals['amount_ht']) || empty($totals['amount_ttc']))) {
			$lineTextFields = auvorechnungsimport_parse_fields($lineText);
			foreach (array('amount_ht', 'amount_ttc') as $key) {
				if (empty($totals[$key])) {
					$totals[$key] = $lineTextFields[$key];
				}
			}
		}
		list($fields['lines'], $fields['lines_check']) = auvorechnungsimport_finish_text_lines($lines, $totals, $lineText);
		if (empty($fields['amount_ht']) && !empty($totals['amount_ht'])) {
			$fields['amount_ht'] = $totals['amount_ht'];
		}
	}

	list($fkSoc, $confidence) = auvorechnungsimport_match_supplier($db, $fields['supplier_vat'] ?? '', $fields['supplier_name'] ?? '', $fields['supplier_tax_id'] ?? '');

	$object->extraction_method = $method;
	$object->extracted_data = json_encode($fields, JSON_UNESCAPED_UNICODE);
	$object->fk_soc = $fkSoc;
	$object->supplier_match_confidence = $confidence;
	// Only a genuinely empty result counts as a hard failure - if we got at least
	// a usable hint (VAT, amount, supplier name or invoice ref), the record is
	// still worth a manual review rather than being flagged as an error. The
	// review form is shown either way, this only affects the status label.
	$gotSomething = !empty($fields['supplier_vat']) || !empty($fields['amount_ttc'])
		|| !empty($fields['supplier_name']) || !empty($fields['invoice_ref']);
	if (!$gotSomething) {
		$object->status = ImportedInvoice::STATUS_ERROR;
	} elseif ($fkSoc) {
		// Supplier was matched automatically - nothing left to look up, just confirm.
		$object->status = ImportedInvoice::STATUS_MATCHED;
	} else {
		$object->status = ImportedInvoice::STATUS_NEEDS_REVIEW;
	}
}

/**
 * Iteratively strips trailing legal-form suffixes and country words from a
 * company name, e.g. "Muster-Textil Deutschland GmbH" -> "Muster-Textil".
 *
 * @param string $name
 * @return string
 */
function auvorechnungsimport_strip_legal_suffix($name)
{
	$name = trim((string) $name);
	$pattern = '/\s*(?:GmbH\s*&\s*Co\.?\s*KG|GmbH|mbH|AG|KGaA|KG|OHG|e\.?K\.?|UG(?:\s*\(haftungsbeschränkt\))?|SARL|SASU|SAS|SA|S\.A\.?|B\.?V\.?|Ltd\.?|Inc\.?|LLC|Co\.?|Deutschland|International)\s*$/iu';
	do {
		$prev = $name;
		$name = trim((string) preg_replace($pattern, '', $name));
	} while ($name !== $prev && $name !== '');
	return $name;
}

/**
 * @param string $vat
 * @return string
 */
function auvorechnungsimport_normalize_vat($vat)
{
	return strtoupper(preg_replace('/[\s.\-]/', '', (string) $vat));
}

/**
 * @param ?string $amount
 * @return ?float
 */
function auvorechnungsimport_parse_amount($amount)
{
	if ($amount === null || $amount === '') {
		return null;
	}
	$amount = trim((string) $amount);
	// European format "1.234,56" -> "1234.56"; a lone comma is read as decimal separator.
	if (preg_match('/,\d{2}$/', $amount)) {
		$amount = str_replace('.', '', $amount);
		$amount = str_replace(',', '.', $amount);
	} else {
		$amount = str_replace(',', '', $amount);
	}
	return is_numeric($amount) ? (float) $amount : null;
}

/**
 * @param string $raw UN/CEFACT DateTimeString, usually format 102 = YYYYMMDD
 * @return ?string
 */
function auvorechnungsimport_parse_cii_date($raw)
{
	$raw = trim((string) $raw);
	if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
		return $m[1].'-'.$m[2].'-'.$m[3];
	}
	return null;
}

/**
 * Normalize an invoice line label into a stable lookup key for the learned
 * line mapping: lower case, dates and punctuation removed, whitespace
 * collapsed. Other numbers are kept on purpose - "Schraube M6" and "Schraube
 * M8" are different articles, while "Versand 03.10.2026" and "Versand
 * 07.10.2026" are the same one.
 *
 * @param string $label
 * @return string
 */
function auvorechnungsimport_normalize_line_label($label)
{
	$key = mb_strtolower(trim((string) $label), 'UTF-8');
	$key = preg_replace('/\b\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\b|\b\d{4}-\d{2}-\d{2}\b/u', ' ', $key);
	$key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);
	$key = trim(preg_replace('/\s+/u', ' ', $key));
	return dol_trunc($key, 255, 'right', 'UTF-8', 1);
}

/**
 * Lookup keys under which a line is learned / looked up, most specific first.
 * The label comes before the supplier ref: many suppliers use one article
 * number for all sizes/colors of a model (e.g. "F110K ... T 104 Black" and
 * "F110K ... T 116 Red"), which are different products here.
 *
 * @param array<string,mixed> $line
 * @return array<string,string> key_type => match_key
 */
function auvorechnungsimport_line_keys($line)
{
	$keys = array();
	$labelKey = auvorechnungsimport_normalize_line_label($line['label'] ?? '');
	if ($labelKey !== '') {
		$keys['label'] = $labelKey;
	}
	if (!empty($line['ref_supplier'])) {
		$keys['ref'] = dol_trunc(trim($line['ref_supplier']), 255, 'right', 'UTF-8', 1);
	}
	return $keys;
}

/**
 * Product learned for a line key of this supplier (0 if none or ambiguous).
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param string $keyType 'label' or 'ref'
 * @param string $matchKey
 * @return int
 */
function auvorechnungsimport_lookup_learned($db, $fkSoc, $keyType, $matchKey)
{
	$sql = "SELECT m.fk_product FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap as m";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = m.fk_product";
	$sql .= " WHERE m.fk_soc = ".((int) $fkSoc)." AND m.key_type = '".$db->escape($keyType)."'";
	$sql .= " AND m.match_key = '".$db->escape($matchKey)."'";
	$sql .= " AND m.entity IN (".getEntity('auvorechnungsimport').")";
	$resql = $db->query($sql.$db->plimit(1));
	return ($resql && ($obj = $db->fetch_object($resql))) ? (int) $obj->fk_product : 0;
}

/**
 * Propose a product for each invoice line. Tried in this order, first hit wins:
 *   1. Supplier ref in Dolibarr's supplier prices (product_fournisseur_price)
 *   2. Learned mapping by normalized label
 *   3. Product variant: parent product found by supplier ref (learned or by
 *      article number), variant picked by the attribute values in the label
 *      ("6100K T-Shirt One 825/grau M" -> J6100, color 825, size M)
 *   4. Learned mapping by supplier ref
 *   5. EAN/GTIN against the product barcode
 *   6. Product ref equal to the supplier ref, or product label equal to the
 *      line label (weak - shown as suggestion only)
 * Sets 'fk_product' and 'match_source' on every line ('' if nothing found),
 * and 'variant_parent' when the parent was found but no unique variant.
 * Every step requires a unique hit - an ambiguous result proposes nothing.
 *
 * @param DoliDB $db
 * @param int $fkSoc Supplier the invoice is booked on (0 = only supplier-independent steps)
 * @param array<int,array<string,mixed>> $lines
 * @return array<int,array<string,mixed>>
 */
function auvorechnungsimport_match_lines($db, $fkSoc, $lines)
{
	$uniqueProduct = function ($sql) use ($db) {
		$resql = $db->query($sql.$db->plimit(2));
		if ($resql && $db->num_rows($resql) == 1 && ($obj = $db->fetch_object($resql))) {
			return (int) $obj->fk_product;
		}
		return 0;
	};

	foreach ($lines as $i => $line) {
		$fkProduct = 0;
		$source = '';
		$ref = trim((string) ($line['ref_supplier'] ?? ''));
		$keys = auvorechnungsimport_line_keys($line);

		if ($fkSoc > 0 && $ref !== '') {
			$sql = "SELECT DISTINCT pfp.fk_product FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pfp.fk_product";
			$sql .= " WHERE pfp.fk_soc = ".((int) $fkSoc)." AND pfp.ref_fourn = '".$db->escape($ref)."'";
			$sql .= " AND pfp.entity IN (".getEntity('productsupplierprice').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'supplier_price';
		}

		// A supplier price kept on a variant parent (one price for all variants):
		// never book the parent itself, resolve the variant from the label below.
		$variantParent = 0;
		if ($fkProduct && isModEnabled('variants') && !empty(auvorechnungsimport_variant_combinations($db, $fkProduct))) {
			$variantParent = $fkProduct;
			$fkProduct = 0;
		} elseif ($fkProduct && isModEnabled('variants') && ($parentOfHit = auvorechnungsimport_variant_parent_of($db, $fkProduct))) {
			// Hit on a variant via a supplier ref that may stand for the whole model
			// (e.g. stored there by "update purchase prices"): if the label clearly
			// names another variant of the same parent, the label wins. A unique,
			// variant-specific ref ("JN358_M") is kept when the label is unclear.
			$fromLabel = auvorechnungsimport_pick_variant($db, $parentOfHit, $line['label'] ?? '');
			if ($fromLabel && $fromLabel != $fkProduct) {
				$fkProduct = $fromLabel;
				$source = 'variant';
			}
		}

		// Learned label first (exact, per variant), then variant resolution, then
		// the learned supplier ref (which is ambiguous for multi-variant refs).
		$learnKeys = $keys;
		if (!$fkProduct && $fkSoc > 0 && isset($learnKeys['label'])) {
			$fkProduct = auvorechnungsimport_lookup_learned($db, $fkSoc, 'label', $learnKeys['label']);
			$source = 'learned_label';
		}
		unset($learnKeys['label']);

		if (!$fkProduct && isModEnabled('variants')) {
			if (!$variantParent && $ref !== '') {
				list($variantParent) = auvorechnungsimport_find_variant_parent($db, $fkSoc, $ref);
			}
			if ($variantParent) {
				$fkProduct = auvorechnungsimport_pick_variant($db, $variantParent, $line['label'] ?? '');
				$source = 'variant';
			}
		}

		// Not when the line is known to be some variant of a parent: the ref then
		// stands for all its variants, and the learned one would be a guess.
		if (!$fkProduct && $fkSoc > 0 && !$variantParent) {
			foreach ($learnKeys as $keyType => $matchKey) {
				$fkProduct = auvorechnungsimport_lookup_learned($db, $fkSoc, $keyType, $matchKey);
				if ($fkProduct) {
					$source = 'learned_'.$keyType;
					break;
				}
			}
		}

		// GTIN (BT-157): compared without leading zeros (EAN-13 vs. GTIN-14), in the
		// product's own barcode first, then in the supplier's barcode of its prices.
		$gtin = ltrim(preg_replace('/\D/', '', (string) ($line['gtin'] ?? '')), '0');
		if (!$fkProduct && $gtin !== '') {
			$sql = "SELECT rowid as fk_product FROM ".MAIN_DB_PREFIX."product";
			$sql .= " WHERE TRIM(LEADING '0' FROM barcode) = '".$db->escape($gtin)."'";
			$sql .= " AND entity IN (".getEntity('product').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'barcode';
			if (!$fkProduct && $fkSoc > 0) {
				$sql = "SELECT DISTINCT pfp.fk_product FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
				$sql .= " WHERE pfp.fk_soc = ".((int) $fkSoc)." AND TRIM(LEADING '0' FROM pfp.barcode) = '".$db->escape($gtin)."'";
				$sql .= " AND pfp.entity IN (".getEntity('productsupplierprice').")";
				$fkProduct = $uniqueProduct($sql);
			}
		}

		// Our own article number (BT-156), if the buyer gave one on the order
		$buyerRef = trim((string) ($line['buyer_item_id'] ?? ''));
		if (!$fkProduct && $buyerRef !== '') {
			$sql = "SELECT rowid as fk_product FROM ".MAIN_DB_PREFIX."product";
			$sql .= " WHERE ref = '".$db->escape($buyerRef)."'";
			$sql .= " AND entity IN (".getEntity('product').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'buyer_ref';
		}

		if (!$fkProduct && $ref !== '') {
			$sql = "SELECT rowid as fk_product FROM ".MAIN_DB_PREFIX."product";
			$sql .= " WHERE ref = '".$db->escape($ref)."'";
			$sql .= " AND entity IN (".getEntity('product').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'suggest_ref';
		}

		if (!$fkProduct && !empty($line['label'])) {
			$sql = "SELECT rowid as fk_product FROM ".MAIN_DB_PREFIX."product";
			$sql .= " WHERE UPPER(label) = '".$db->escape(mb_strtoupper(trim($line['label']), 'UTF-8'))."'";
			$sql .= " AND entity IN (".getEntity('product').")";
			$fkProduct = $uniqueProduct($sql);
			$source = 'suggest_label';
		}

		// Same rule for any later hit (barcode, suggestion) on a variant parent -
		// except a learned one: that parent was chosen by hand, on purpose.
		if ($fkProduct && strpos($source, 'learned_') !== 0 && isModEnabled('variants') && !empty(auvorechnungsimport_variant_combinations($db, $fkProduct))) {
			$variantParent = $fkProduct;
			$fkProduct = auvorechnungsimport_pick_variant($db, $variantParent, $line['label'] ?? '');
			$source = 'variant';
		}

		$lines[$i]['fk_product'] = $fkProduct;
		$lines[$i]['match_source'] = $fkProduct ? $source : '';
		// Parent known but the label didn't single out one variant: tell the user
		// which product to pick a variant of.
		$lines[$i]['variant_parent'] = (!$fkProduct && $variantParent) ? $variantParent : 0;
		// ... and what creating its own variant would take, if that's clear
		$lines[$i]['variant_plan'] = (!$fkProduct && $variantParent) ? auvorechnungsimport_plan_variant($db, $variantParent, $line['label'] ?? '') : null;

		// How to book the line: learned choice for this supplier and label, then
		// a matched product (booked as such), then the setup's keywords.
		list($lines[$i]['mode'], $lines[$i]['mode_source']) = auvorechnungsimport_line_mode($db, $fkSoc, $lines[$i], (bool) $fkProduct);
		if ($lines[$i]['mode'] !== 'book') {
			$lines[$i]['fk_product'] = 0;
			$lines[$i]['match_source'] = '';
			$lines[$i]['variant_plan'] = null;
		}
	}

	return $lines;
}

/**
 * Remember the product chosen for an invoice line, so the next invoice of the
 * same supplier gets it proposed automatically. Learns under every key of the
 * line (normalized label and supplier ref). Choosing "no product" for a line
 * forgets earlier mappings under its keys, so a wrong suggestion that the user
 * cleared doesn't come back.
 *
 * A supplier ref that turns out to stand for several products (one article
 * number for all variants) is marked ambiguous (fk_product = 0) and from then
 * on never proposes anything - only the label mapping is used for it.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkSoc
 * @param array<string,mixed> $line
 * @param int $fkProduct 0 = line booked without product
 * @return int <0 on error
 */
function auvorechnungsimport_learn_line($db, $user, $fkSoc, $line, $fkProduct)
{
	global $conf;

	if ($fkSoc <= 0) {
		return 0;
	}
	foreach (auvorechnungsimport_line_keys($line) as $keyType => $matchKey) {
		$where = " WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $fkSoc);
		$where .= " AND key_type = '".$db->escape($keyType)."' AND match_key = '".$db->escape($matchKey)."'";

		if ($fkProduct <= 0) {
			// Keep the ambiguity marker of a ref: clearing one variant doesn't make
			// the ref unique again.
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap".$where.($keyType === 'ref' ? " AND fk_product <> 0" : "");
		} else {
			$resql = $db->query("SELECT rowid, fk_product FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap".$where);
			if (!$resql) {
				return -1;
			}
			$obj = $db->fetch_object($resql);
			if ($obj) {
				// Same product again: count it. Different product under the same label:
				// the user corrected us, so the old mapping is replaced and its count
				// starts over. Different product under the same ref: the ref is shared
				// by several products and becomes ambiguous.
				$sql = "UPDATE ".MAIN_DB_PREFIX."auvorechnungsimport_linemap SET";
				if ((int) $obj->fk_product == $fkProduct) {
					$sql .= " nb_used = nb_used + 1";
				} elseif ($keyType === 'ref') {
					$sql .= " fk_product = 0, nb_used = 0";
				} else {
					$sql .= " fk_product = ".((int) $fkProduct).", nb_used = 1";
				}
				$sql .= " WHERE rowid = ".((int) $obj->rowid);
			} else {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."auvorechnungsimport_linemap";
				$sql .= " (entity, fk_soc, key_type, match_key, fk_product, nb_used, date_creation, fk_user_creat)";
				$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fkSoc).", '".$db->escape($keyType)."', '".$db->escape($matchKey)."',";
				$sql .= " ".((int) $fkProduct).", 1, '".$db->idate(dol_now())."', ".((int) $user->id).")";
			}
		}
		if (!$db->query($sql)) {
			return -1;
		}
	}

	// Chosen product is a variant: remember its parent for the supplier ref, so
	// the other colors/sizes of that article resolve by their attributes even
	// though they were never assigned by hand.
	$ref = trim((string) ($line['ref_supplier'] ?? ''));
	if ($fkProduct > 0 && $ref !== '' && isModEnabled('variants')) {
		$fkParent = auvorechnungsimport_variant_parent_of($db, $fkProduct);
		if ($fkParent > 0) {
			$matchKey = dol_trunc($ref, 255, 'right', 'UTF-8', 1);
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $fkSoc);
			$sql .= " AND key_type = 'parent' AND match_key = '".$db->escape($matchKey)."' AND fk_product <> ".((int) $fkParent);
			if (!$db->query($sql)) {
				return -1;
			}
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $fkSoc);
			$sql .= " AND key_type = 'parent' AND match_key = '".$db->escape($matchKey)."'";
			$resql = $db->query($sql);
			if (!$resql) {
				return -1;
			}
			if ($obj = $db->fetch_object($resql)) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."auvorechnungsimport_linemap SET nb_used = nb_used + 1 WHERE rowid = ".((int) $obj->rowid);
			} else {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."auvorechnungsimport_linemap";
				$sql .= " (entity, fk_soc, key_type, match_key, fk_product, nb_used, date_creation, fk_user_creat)";
				$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fkSoc).", 'parent', '".$db->escape($matchKey)."', ".((int) $fkParent).", 1, '".$db->idate(dol_now())."', ".((int) $user->id).")";
			}
			if (!$db->query($sql)) {
				return -1;
			}
		}
	}
	return 1;
}

/**
 * Store the invoice's net unit price as the product's purchase price for this
 * supplier (quantity 1 price row). An existing qty-1 row is updated in place,
 * keeping its other settings; quantity tiers (qty > 1) are left alone. Also
 * fills in the supplier ref, which makes step 1 of the matching hit next time.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkSoc
 * @param int $fkProduct
 * @param array<string,mixed> $line Needs price, price_base, vat_rate, ref_supplier
 * @return int <0 on error, 0 if nothing to do
 */
function auvorechnungsimport_update_buyprice($db, $user, $fkSoc, $fkProduct, $line)
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	$price = (float) ($line['price'] ?? 0);
	if ($fkSoc <= 0 || $fkProduct <= 0 || $price <= 0) {
		return 0;
	}

	$supplier = new Societe($db);
	$pf = new ProductFournisseur($db);
	if ($supplier->fetch($fkSoc) <= 0 || $pf->fetch($fkProduct) <= 0) {
		return -1;
	}

	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
	$sql .= " WHERE fk_product = ".((int) $fkProduct)." AND fk_soc = ".((int) $fkSoc)." AND quantity <= 1";
	$sql .= " AND entity IN (".getEntity('productsupplierprice').")";
	$sql .= " ORDER BY rowid DESC".$db->plimit(1);
	$resql = $db->query($sql);
	$existingId = ($resql && ($obj = $db->fetch_object($resql))) ? (int) $obj->rowid : 0;

	$refFourn = trim((string) ($line['ref_supplier'] ?? ''));
	$availability = 0;
	$remisePercent = 0;
	$deliveryDays = 0;
	$reputation = '';
	$descFourn = '';
	$charges = 0;
	$defaultVatCode = '';
	$barcode = '';
	$barcodeType = 0;
	if ($existingId) {
		if ($pf->fetch_product_fournisseur_price($existingId) <= 0) {
			return -1;
		}
		$pf->product_fourn_price_id = $existingId;
		$availability = (int) $pf->fk_availability;
		$remisePercent = (float) $pf->fourn_remise_percent;
		$deliveryDays = (int) $pf->delivery_time_days;
		$reputation = (string) $pf->supplier_reputation;
		$descFourn = (string) $pf->desc_supplier;
		$charges = (float) ($pf->fourn_charges ?? 0);
		$defaultVatCode = (string) ($pf->default_vat_code ?? '');
		$barcode = (string) ($pf->supplier_barcode ?? '');
		$barcodeType = (int) ($pf->supplier_fk_barcode_type ?? 0);
		// update_buyprice() otherwise resets packaging to the price quantity.
		$pf->product_fourn_packaging = $pf->packaging ?? null;
	}
	// A supplier ref may sit on only one product per supplier and quantity
	// (unique key uk_product_fournisseur_price_ref) - and model numbers shared by
	// all variants ("4233K") can't. Only take over the invoice's ref while it is
	// free; otherwise keep the row's own ref, or use the product ref for a new row.
	$isFree = function ($candidate) use ($db, $fkSoc, $existingId) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
		$sql .= " WHERE fk_soc = ".((int) $fkSoc)." AND quantity = 1 AND ref_fourn = '".$db->escape($candidate)."'";
		$sql .= " AND entity IN (".getEntity('productsupplierprice').")";
		if ($existingId) {
			$sql .= " AND rowid <> ".((int) $existingId);
		}
		$resql = $db->query($sql.$db->plimit(1));
		return $resql && !$db->fetch_object($resql);
	};
	if ($refFourn !== '' && !$isFree($refFourn)) {
		$refFourn = '';
	}
	// On a variant, the invoice ref is typically the model number shared by all
	// variants: keep the supplier ref already on the variant, and give a new
	// price row the variant's own ref - not the model number, which only the
	// first variant could get.
	if (isModEnabled('variants') && auvorechnungsimport_variant_parent_of($db, $fkProduct)) {
		$refFourn = ($existingId && (string) $pf->ref_supplier !== '') ? (string) $pf->ref_supplier : $pf->ref;
	}
	if ($refFourn === '' && $existingId) {
		$refFourn = (string) $pf->ref_supplier;
	}
	if ($refFourn === '') {
		// ref_fourn is mandatory on a supplier price row in Dolibarr.
		$refFourn = $pf->ref;
	}

	$vatRate = isset($line['vat_rate']) && $line['vat_rate'] !== null ? (float) $line['vat_rate'] : (float) $pf->tva_tx;

	// With multicurrency enabled, update_buyprice() derives the price from the
	// currency amount - pass it explicitly, in the company currency at rate 1.
	$priceBase = (($line['price_base'] ?? 'HT') === 'TTC') ? 'TTC' : 'HT';
	$result = $pf->update_buyprice(1, $price, $user, $priceBase, $supplier, $availability, $refFourn, $vatRate, $charges, $remisePercent, 0, 0, $deliveryDays, $reputation, array(), $defaultVatCode, $price, $priceBase, 1, $conf->currency, $descFourn, $barcode, $barcodeType);
	return $result < 0 ? -1 : 1;
}

/**
 * Text layer of a PDF with the visual column layout preserved (poppler's
 * pdftotext -layout). Used for line detection: smalot's text keeps the stream
 * order, which tears table rows apart; -layout keeps each row on one line.
 *
 * @param string $pdfpath
 * @return ?string Empty string if the PDF has no text layer, null if pdftotext is unavailable
 */
function auvorechnungsimport_extract_pdftext_layout($pdfpath)
{
	if (!function_exists('exec')) {
		return null;
	}
	$output = array();
	exec('pdftotext -layout -enc UTF-8 '.escapeshellarg($pdfpath).' - 2>/dev/null', $output, $retval);
	return $retval === 0 ? trim(implode("\n", $output)) : null;
}

/**
 * Parse one whitespace-separated token as a number. Accepts German ("1.234,56",
 * "12,5") and English ("12.50") notation, optional trailing currency or unit
 * ("12,50€", "16ST") and a trailing "%".
 *
 * @param string $token
 * @return ?array{value:float,decimals:bool,percent:bool}
 */
function auvorechnungsimport_parse_number_token($token)
{
	$token = trim($token);
	$percent = substr($token, -1) === '%';
	$token = rtrim($token, '%€');
	if (!preg_match('/^(-?(?:\d{1,3}(?:\.\d{3})+|\d+)(?:,\d{1,4})?|-?\d+\.\d{2,4})(?:EUR|ST|Stk\.?|x)?$/iu', $token, $m)) {
		return null;
	}
	$num = $m[1];
	if (strpos($num, ',') !== false) {
		$decimals = (bool) preg_match('/,\d{2,4}$/', $num);
		$num = str_replace(array('.', ','), array('', '.'), $num);
	} elseif (preg_match('/^-?\d+\.\d{2,4}$/', $num)) {
		$decimals = true;
	} else {
		$decimals = false;
		$num = str_replace('.', '', $num);
	}
	return array('value' => (float) $num, 'decimals' => $decimals, 'percent' => $percent);
}

/**
 * Heuristic detection of invoice lines in plain text (pdftotext -layout or OCR).
 *
 * A row is recognized by its arithmetic, not by its layout: it must contain a
 * quantity q, a unit price p and a line total t with q x p = t (to the cent,
 * optionally after a "x %" discount between price and total). p and t must be
 * written with decimals. This holds for virtually every invoice layout and
 * practically never by accident on address, bank or payment-term lines - and
 * it tells which number is which, regardless of column order:
 *   "1   2 F110K Fruit of the Loom ... T 104      1,74     3,48"   (qty first)
 *   "1   1816 Rucksack Striker  -  16 ST   12,50   200,00 19,00"   (VAT column)
 *   "1   DTF Meterware   8   Stück   11,89   95,12"
 * Single-amount lines are only accepted for typical fee wording (shipping...).
 *
 * Indented text-only lines right below a row (color, variant) are appended to
 * its label. Totals/tax/payment lines are skipped by keyword.
 *
 * @param string $text
 * @return array<int,array<string,mixed>>
 */
function auvorechnungsimport_parse_text_lines($text)
{
	$skip = '/\b(?:summe|nettosumme|zwischensumme|gesamtsumme|gesamtbetrag|gesamtwert|gesamtmenge|netto|brutto|bruttorechnungswert|übertrag|uebertrag|mwst|ust|umsatzsteuer|mehrwertsteuer|skonto|zahlbetrag|rechnungsbetrag|endbetrag|zahlungsbedingung|zahlbar|iban|bic|total|subtotal|tva|vat|rabatt\s+von)\b/iu';
	$fee = '/\b(?:versand\w*|fracht\w*|porto|verpackung\w*|mindermengen\w*|transport\w*|lieferkosten|zustellung|shipping|delivery|frais\s+de\s+port)\b/iu';
	$unitWords = '/^(?:st|stk|stck|stück|stueck|pcs|pc|pce|x|m|lfm|meter|kg|g|l|h|std|pkt|paket|pck|set|paar|rolle|versand|einheit|pauschal)\.?$/iu';

	$rows = preg_split('/\r\n|\r|\n/', (string) $text);
	$lines = array();
	$last = null; // index into $lines of the row continuation lines may belong to
	$lastIndent = 0;

	foreach ($rows as $row) {
		if (trim($row) === '') {
			$last = null;
			continue;
		}
		$tokens = preg_split('/\s+/u', trim($row));
		$nums = array();
		foreach ($tokens as $idx => $token) {
			$n = auvorechnungsimport_parse_number_token($token);
			if ($n !== null) {
				$nums[$idx] = $n;
			}
		}
		$amountIdx = array_keys(array_filter($nums, function ($n) {
			return $n['decimals'] && !$n['percent'];
		}));

		$match = null;
		if (count($amountIdx) >= 2 && !preg_match($skip, $row)) {
			// Total: one of the last three amounts (the very last can be a VAT
			// column); price: any amount left of it; qty: any number left of the
			// price (rightmost first) or between price and total.
			foreach (array_reverse(array_slice($amountIdx, -3)) as $ti) {
				$t = $nums[$ti]['value'];
				foreach (array_reverse(array_filter($amountIdx, function ($i) use ($ti) {
					return $i < $ti;
				})) as $pi) {
					$p = $nums[$pi]['value'];
					$discount = 0;
					foreach ($nums as $i => $n) {
						if ($i > $pi && $i < $ti && $n['percent']) {
							$discount = $n['value'];
						}
					}
					$qtyCandidates = array();
					for ($i = $pi - 1; $i >= 0; $i--) {
						$qtyCandidates[] = $i;
					}
					for ($i = $pi + 1; $i < $ti; $i++) {
						$qtyCandidates[] = $i;
					}
					foreach ($qtyCandidates as $qi) {
						if (!isset($nums[$qi]) || $nums[$qi]['percent'] || $nums[$qi]['value'] == 0) {
							continue;
						}
						$q = $nums[$qi]['value'];
						if (abs($q * $p * (1 - $discount / 100) - $t) <= 0.011) {
							$match = array('q' => $qi, 'qty' => $q, 'p' => $pi, 't' => $ti, 'discount' => $discount);
							break 3;
						}
					}
				}
			}
			// No qty token: OCR regularly drops isolated single digits (a lone "2"
			// in the qty column). If total / price is a whole number, that is the
			// qty - still checked to the cent, just not against a printed number.
			if ($match === null) {
				foreach (array_reverse(array_slice($amountIdx, -3)) as $ti) {
					$pos = array_search($ti, $amountIdx, true);
					if ($pos === false || $pos === 0) {
						continue;
					}
					$pi = $amountIdx[$pos - 1];
					$p = $nums[$pi]['value'];
					$t = $nums[$ti]['value'];
					$q = $p > 0 ? round($t / $p) : 0;
					if ($q >= 1 && $q <= 10000 && abs($q * $p - $t) <= 0.011) {
						$match = array('q' => null, 'qty' => $q, 'p' => $pi, 't' => $ti, 'discount' => 0);
						break;
					}
				}
			}
		} elseif (count($amountIdx) == 1 && preg_match($fee, $row) && !preg_match($skip, $row)) {
			$ti = $amountIdx[0];
			$match = array('q' => null, 'qty' => 1.0, 'p' => $ti, 't' => $ti, 'discount' => 0);
		}

		if ($match === null) {
			// Continuation of the previous row: indented further than the row's own
			// first column, no amounts, no "Label: value" (customs/HS code lines).
			$indent = strlen($row) - strlen(ltrim($row));
			if ($last !== null && $indent > $lastIndent && empty($amountIdx) && strpos($row, ':') === false
				&& dol_strlen(trim($row)) <= 60 && preg_match('/\p{L}{2}/u', $row) && !preg_match($skip, $row)) {
				$lines[$last]['label'] .= ' '.trim($row);
			} else {
				$last = null;
			}
			continue;
		}

		// Label = text tokens left of the price (left of the qty if the qty sits
		// right of the text), minus position number, qty, units and placeholders.
		$firstText = null;
		foreach ($tokens as $idx => $token) {
			if (!isset($nums[$idx])) {
				$firstText = $idx;
				break;
			}
		}
		$end = ($match['q'] !== null && $firstText !== null && $match['q'] > $firstText && $match['q'] < $match['p']) ? $match['q'] : $match['p'];
		// Leading plain integers before the text: the qty (if it sits there), then
		// a position number, then possibly a purely numeric article number
		// ("1   1816 Rucksack ..."). A lone leading integer counts as position
		// number if it continues the numbering or is short.
		$skipIdx = array();
		if ($match['q'] !== null) {
			$skipIdx[$match['q']] = true;
			// A unit word right after a leading qty ("2 Stk F110K ...")
			if (isset($tokens[$match['q'] + 1]) && preg_match($unitWords, $tokens[$match['q'] + 1])) {
				$skipIdx[$match['q'] + 1] = true;
			}
		}
		$leadingInts = array();
		for ($i = 0; $firstText !== null && $i < $firstText; $i++) {
			if (!isset($skipIdx[$i]) && isset($nums[$i]) && !$nums[$i]['decimals']) {
				$leadingInts[] = $i;
			}
		}
		if (!empty($leadingInts)) {
			$first = $leadingInts[0];
			if (count($leadingInts) >= 2 || (int) $nums[$first]['value'] == count($lines) + 1 || strlen($tokens[$first]) <= 3) {
				$skipIdx[$first] = true;
			}
		}
		$labelTokens = array();
		for ($i = 0; $i < $end; $i++) {
			if (isset($skipIdx[$i]) || preg_match('/^[-–*]$/u', $tokens[$i])) {
				continue;
			}
			$labelTokens[] = $tokens[$i];
		}

		$ref = '';
		if (count($labelTokens) >= 2 && preg_match('/^(?=.*\d)[\p{L}\d][\p{L}\d._\-\/]{2,29}$/u', $labelTokens[0])) {
			$ref = array_shift($labelTokens);
		}
		$label = trim(implode(' ', $labelTokens));
		if ($label === '' && $ref === '') {
			continue;
		}

		// A percentage (or a plausible bare rate) right of the total is the VAT column.
		$vat = null;
		foreach ($nums as $i => $n) {
			if ($i > $match['t'] && $n['value'] >= 0 && $n['value'] <= 27 && ($n['percent'] || in_array((float) $n['value'], array(0.0, 5.0, 5.5, 7.0, 10.0, 16.0, 19.0, 20.0, 21.0), true))) {
				$vat = $n['value'];
				break;
			}
		}

		$qty = (float) $match['qty'];
		$price = $nums[$match['p']]['value'];
		$total = $nums[$match['t']]['value'];
		if ($match['discount']) {
			// Bake the discount into the unit price, like on Factur-X lines.
			$price = $total / $qty;
		}

		$lines[] = array(
			'source' => 'text',
			'ref_supplier' => $ref,
			'gtin' => '',
			'label' => $label !== '' ? $label : $ref,
			'qty' => $qty,
			'unit_code' => '',
			'price' => $price,
			'price_base' => 'HT',
			'vat_rate' => $vat,
			'total' => $total,
		);
		$last = count($lines) - 1;
		$lastIndent = strlen($row) - strlen(ltrim($row));
	}

	return $lines;
}

/**
 * VAT rates named in the invoice's tax summary ("zzgl. 19,00 % MwSt",
 * "USt 19 %", "MwSt. 7%").
 *
 * @param string $text
 * @return array<int,float> Distinct rates
 */
function auvorechnungsimport_parse_vat_rates($text)
{
	$rates = array();
	$label = '(?:MwSt|USt|Mehrwertsteuer|Umsatzsteuer|VAT|TVA)\.?';
	$rate = '(\d{1,2}(?:[,.]\d{1,2})?)\s*%';
	if (preg_match_all('/'.$rate.'\s*'.$label.'|'.$label.'\s*(?:von\s*)?'.$rate.'/iu', (string) $text, $all, PREG_SET_ORDER)) {
		foreach ($all as $m) {
			$value = (float) str_replace(',', '.', !empty($m[1]) ? $m[1] : $m[2]);
			if ($value > 0 && $value <= 27) {
				$rates[(string) $value] = $value;
			}
		}
	}
	return array_values($rates);
}

/**
 * Finish text-detected lines against the invoice totals: fill in missing VAT
 * rates when the invoice names exactly one rate, and decide whether the prices
 * are net or gross by which total the lines add up to. Returns the lines and a
 * check result: 'ok' (sum matches the net or gross total), 'mismatch' or
 * 'unknown' (no total to compare with).
 *
 * @param array<int,array<string,mixed>> $lines
 * @param array<string,mixed> $fields Header fields (amount_ht, amount_ttc)
 * @param string $text
 * @return array{0:array<int,array<string,mixed>>,1:string}
 */
function auvorechnungsimport_finish_text_lines($lines, $fields, $text)
{
	if (empty($lines)) {
		return array($lines, 'unknown');
	}
	$rates = auvorechnungsimport_parse_vat_rates($text);
	if (count($rates) == 1) {
		foreach ($lines as $i => $line) {
			if ($line['vat_rate'] === null) {
				$lines[$i]['vat_rate'] = $rates[0];
			}
		}
	}

	$sum = 0;
	foreach ($lines as $line) {
		$sum += (float) $line['total'];
	}
	$tolerance = max(0.05, $sum * 0.001);
	$ht = $fields['amount_ht'] ?? null;
	$ttc = $fields['amount_ttc'] ?? null;
	if ($ht !== null && abs($sum - $ht) <= $tolerance) {
		return array($lines, 'ok');
	}
	if ($ttc !== null && abs($sum - $ttc) <= $tolerance && ($ht === null || abs($ht - $ttc) > $tolerance)) {
		foreach ($lines as $i => $line) {
			$lines[$i]['price_base'] = 'TTC';
		}
		return array($lines, 'ok');
	}
	return array($lines, ($ht === null && $ttc === null) ? 'unknown' : 'mismatch');
}

/**
 * Rebuild a layout text (like pdftotext -layout) from tesseract TSV output.
 * Words are grouped into visual rows by their vertical center - independent of
 * tesseract's own block segmentation, which on tables often puts each column
 * into its own block - and placed at a column proportional to their x
 * position. Larger vertical gaps become blank lines.
 *
 * @param string $tsv
 * @return string
 */
function auvorechnungsimport_tsv_to_layout($tsv)
{
	$words = array();
	foreach (preg_split('/\r\n|\r|\n/', (string) $tsv) as $i => $row) {
		$cols = explode("\t", $row);
		// level page block par line word left top width height conf text
		if ($i === 0 || count($cols) < 12 || (int) $cols[0] !== 5 || (float) $cols[10] < 0 || trim($cols[11]) === '') {
			continue;
		}
		$words[] = array('left' => (int) $cols[6], 'cy' => (int) $cols[7] + (int) $cols[9] / 2, 'width' => (int) $cols[8], 'height' => (int) $cols[9], 'text' => trim($cols[11]));
	}
	if (empty($words)) {
		return '';
	}

	$median = function (array $values) {
		sort($values);
		return $values[(int) floor(count($values) / 2)];
	};
	$charWidths = array();
	$heights = array();
	foreach ($words as $w) {
		$len = mb_strlen($w['text'], 'UTF-8');
		if ($len >= 2) {
			$charWidths[] = $w['width'] / $len;
		}
		$heights[] = $w['height'];
	}
	$charWidth = max(1, !empty($charWidths) ? $median($charWidths) : 10);
	$lineHeight = max(1, $median($heights));

	usort($words, function ($a, $b) {
		return $a['cy'] <=> $b['cy'];
	});
	$rows = array();
	foreach ($words as $w) {
		$n = count($rows);
		if ($n > 0 && abs($w['cy'] - $rows[$n - 1]['cy']) <= $lineHeight * 0.5) {
			$rows[$n - 1]['words'][] = $w;
		} else {
			$rows[] = array('cy' => $w['cy'], 'words' => array($w));
		}
	}

	$pitches = array();
	for ($i = 1; $i < count($rows); $i++) {
		$pitches[] = $rows[$i]['cy'] - $rows[$i - 1]['cy'];
	}
	$pitch = !empty($pitches) ? $median($pitches) : $lineHeight * 1.5;

	$out = array();
	foreach ($rows as $i => $row) {
		if ($i > 0 && $row['cy'] - $rows[$i - 1]['cy'] > $pitch * 1.6) {
			$out[] = '';
		}
		usort($row['words'], function ($a, $b) {
			return $a['left'] <=> $b['left'];
		});
		$line = '';
		foreach ($row['words'] as $w) {
			$col = (int) round($w['left'] / $charWidth);
			$len = mb_strlen($line, 'UTF-8');
			$line .= ($len === 0 ? str_repeat(' ', $col) : str_repeat(' ', max(1, $col - $len))).$w['text'];
		}
		$out[] = $line;
	}
	return implode("\n", $out);
}

/**
 * All variant combinations of a parent product with their attribute values
 * (Dolibarr module "variants"), cached per request.
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param bool $reset Drop the cached entry (after creating a variant)
 * @return array<int,array<int,array{id:int,ref:string,value:string}>> fk_child => [fk_attribute => value]
 */
function auvorechnungsimport_variant_combinations($db, $fkParent, $reset = false)
{
	static $cache = array();
	if ($reset) {
		unset($cache[$fkParent]);
		auvorechnungsimport_variant_attribute_values($db, $fkParent, true);
	}
	if (isset($cache[$fkParent])) {
		return $cache[$fkParent];
	}
	$combos = array();
	$sql = "SELECT c.fk_product_child, cv.fk_prod_attr, v.rowid as value_id, v.ref, v.value";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as c";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination2val as cv ON cv.fk_prod_combination = c.rowid";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_value as v ON v.rowid = cv.fk_prod_attr_val";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = c.fk_product_child";
	$sql .= " WHERE c.fk_product_parent = ".((int) $fkParent);
	$sql .= " AND c.entity IN (".getEntity('product').")";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$combos[(int) $obj->fk_product_child][(int) $obj->fk_prod_attr] = array('id' => (int) $obj->value_id, 'ref' => (string) $obj->ref, 'value' => (string) $obj->value);
	}
	$cache[$fkParent] = $combos;
	return $combos;
}

/**
 * The attributes a parent product varies by, in Dolibarr's attribute order,
 * each with all its values - also values no variant of this parent uses yet
 * (a color that exists for the attribute but was never ordered for it).
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param bool $reset
 * @return array<int,array{label:string,position:int,values:array<int,array{id:int,ref:string,value:string}>}>
 */
function auvorechnungsimport_variant_attribute_values($db, $fkParent, $reset = false)
{
	static $cache = array();
	if ($reset) {
		unset($cache[$fkParent]);
		return array();
	}
	if (isset($cache[$fkParent])) {
		return $cache[$fkParent];
	}
	$attrs = array();
	$sql = "SELECT a.rowid as attr_id, a.label, a.position, v.rowid as value_id, v.ref, v.value";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_attribute as a";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_value as v ON v.fk_product_attribute = a.rowid";
	$sql .= " WHERE a.rowid IN (SELECT DISTINCT cv.fk_prod_attr FROM ".MAIN_DB_PREFIX."product_attribute_combination as c";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination2val as cv ON cv.fk_prod_combination = c.rowid";
	$sql .= " WHERE c.fk_product_parent = ".((int) $fkParent).")";
	$sql .= " ORDER BY a.position, a.rowid, v.position, v.rowid";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$id = (int) $obj->attr_id;
		if (!isset($attrs[$id])) {
			$attrs[$id] = array('label' => (string) $obj->label, 'position' => (int) $obj->position, 'values' => array());
		}
		$attrs[$id]['values'][(int) $obj->value_id] = array('id' => (int) $obj->value_id, 'ref' => (string) $obj->ref, 'value' => (string) $obj->value);
	}
	$cache[$fkParent] = $attrs;
	return $attrs;
}

/**
 * Find the variant parent product an invoice line belongs to:
 *   1. learned: supplier ref -> parent, from an earlier manual assignment
 *   2. automatic: a parent product whose ref contains the numeric core of the
 *      supplier ref as a whole number ("4233K" / "4233" -> "J4233"). If several
 *      parents qualify, only those already linked to this supplier (a supplier
 *      price on one of their variants) count. Must be unique.
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param string $ref Supplier ref of the line
 * @return array{0:int,1:string} [fk_parent or 0, 'learned'|'auto'|'']
 */
function auvorechnungsimport_find_variant_parent($db, $fkSoc, $ref)
{
	$ref = trim($ref);
	if ($ref === '') {
		return array(0, '');
	}

	if ($fkSoc > 0) {
		$sql = "SELECT m.fk_product FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap as m";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = m.fk_product";
		$sql .= " WHERE m.fk_soc = ".((int) $fkSoc)." AND m.key_type = 'parent' AND m.match_key = '".$db->escape($ref)."'";
		$sql .= " AND m.entity IN (".getEntity('auvorechnungsimport').")";
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return array((int) $obj->fk_product, 'learned');
		}
	}

	// Numeric core: "4233K" -> "4233", "JN-358" -> "358". At least 3 digits, so
	// that short numbers don't hit half the catalog.
	if (!preg_match('/(\d{3,})/', $ref, $m)) {
		return array(0, '');
	}
	$core = $m[1];
	$sql = "SELECT DISTINCT p.rowid, p.ref,";
	$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."product_attribute_combination as c2";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_fournisseur_price as pfp ON pfp.fk_product = c2.fk_product_child";
	$sql .= " WHERE c2.fk_product_parent = p.rowid AND pfp.fk_soc = ".((int) $fkSoc).") as nb_supplier";
	$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination as c ON c.fk_product_parent = p.rowid";
	$sql .= " WHERE p.ref LIKE '%".$db->escape($db->escapeforlike($core))."%'";
	$sql .= " AND p.entity IN (".getEntity('product').")";
	$resql = $db->query($sql);
	$candidates = array();
	$linked = array();
	while ($resql && ($obj = $db->fetch_object($resql))) {
		if (preg_match('/(?<!\d)'.preg_quote($core, '/').'(?!\d)/', $obj->ref)) {
			$candidates[] = (int) $obj->rowid;
			if ($obj->nb_supplier > 0) {
				$linked[] = (int) $obj->rowid;
			}
		}
	}
	if (count($candidates) == 1) {
		return array($candidates[0], 'auto');
	}
	if (count($linked) == 1) {
		return array($linked[0], 'auto');
	}
	return array(0, '');
}

/**
 * Work out which attribute values of a parent product the line's label names.
 *
 * For every attribute of the parent, each value scores a point if its ref
 * ("825", "08", "M") is a word of the label, and one if its text ("grau",
 * "JAKO blau") appears in it - as whole words, or abbreviated: a label word of
 * at least 3 letters that the value text starts with ("JUN" -> "Junior").
 * Attributes are resolved strongest first, and the words a resolved value used
 * are consumed: in "Stutzen 01/sportrot JUN" the "01" belongs to the color
 * (ref + text match) and can no longer make size value "01 = Junior" a hit.
 * Attributes without a unique hit don't filter (a backpack's label naming only
 * its color is fine as long as that leaves one variant). Exactly one remaining
 * existing combination is the variant.
 *
 * Candidate values are all values of the parent's attributes, not only those
 * some variant uses, so that a combination that doesn't exist yet can be
 * recognized as such (see auvorechnungsimport_plan_variant()).
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param string $label
 * @return array{child:int,resolved:array<int,array{id:int,ref:string,value:string}>,unresolved:array<int,int>,words:array<int,string>,used:array<int,int>}
 */
function auvorechnungsimport_resolve_variant($db, $fkParent, $label)
{
	$result = array('child' => 0, 'resolved' => array(), 'unresolved' => array(), 'words' => array(), 'used' => array());
	$combos = auvorechnungsimport_variant_combinations($db, $fkParent);
	$attributes = auvorechnungsimport_variant_attribute_values($db, $fkParent);
	if (empty($combos) || empty($attributes)) {
		return $result;
	}
	$words = array_values(array_filter(explode(' ', auvorechnungsimport_normalize_line_label($label)), 'strlen'));
	$result['words'] = $words;

	// attribute => [value id => [id, ref, value]]
	$values = array();
	foreach ($attributes as $fkAttr => $attr) {
		$values[$fkAttr] = $attr['values'];
	}

	// Word positions of $label a value matches: [score, positions]
	$matchValue = function ($v, $available) use ($words) {
		$score = 0;
		$used = array();
		$refNorm = auvorechnungsimport_normalize_line_label($v['ref']);
		if ($refNorm !== '' && strpos($refNorm, ' ') === false) {
			foreach ($available as $pos) {
				if ($words[$pos] === $refNorm) {
					$score++;
					$used[] = $pos;
					break;
				}
			}
		}
		$valueWords = array_values(array_filter(explode(' ', auvorechnungsimport_normalize_line_label($v['value'])), 'strlen'));
		$n = count($valueWords);
		if ($n > 0) {
			// whole phrase on consecutive available words
			$availableSet = array_flip($available);
			for ($start = 0; $start + $n <= count($words); $start++) {
				$ok = true;
				for ($k = 0; $k < $n; $k++) {
					if (!isset($availableSet[$start + $k]) || $words[$start + $k] !== $valueWords[$k]) {
						$ok = false;
						break;
					}
				}
				if ($ok) {
					$score++;
					$used = array_merge($used, range($start, $start + $n - 1));
					return array($score, $used);
				}
			}
			// abbreviation of a single-word value ("jun" -> "junior")
			if ($n == 1 && !preg_match('/\d/', $valueWords[0])) {
				foreach ($available as $pos) {
					$w = $words[$pos];
					if (mb_strlen($w, 'UTF-8') >= 3 && !preg_match('/\d/', $w) && $w !== $valueWords[0] && mb_strpos($valueWords[0], $w, 0, 'UTF-8') === 0) {
						$score++;
						$used[] = $pos;
						break;
					}
				}
			}
		}
		return array($score, $used);
	};

	$available = array_keys($words);
	$remaining = $combos;
	$open = $values;
	while (!empty($open)) {
		// Best unique value per open attribute; resolve the strongest attribute.
		$pick = null;
		foreach ($open as $fkAttr => $attrValues) {
			$best = 0;
			$bestKeys = array();
			$bestUsed = array();
			foreach ($attrValues as $key => $v) {
				list($score, $used) = $matchValue($v, $available);
				if ($score > $best) {
					$best = $score;
					$bestKeys = array($key);
					$bestUsed = $used;
				} elseif ($score == $best && $score > 0) {
					$bestKeys[] = $key;
				}
			}
			if ($best > 0 && count($bestKeys) == 1 && ($pick === null || $best > $pick['score'])) {
				$pick = array('attr' => $fkAttr, 'key' => $bestKeys[0], 'score' => $best, 'used' => $bestUsed);
			}
		}
		if ($pick === null) {
			break;
		}
		$want = $open[$pick['attr']][$pick['key']];
		$fkAttr = $pick['attr'];
		$remaining = array_filter($remaining, function ($attrs) use ($fkAttr, $want) {
			return isset($attrs[$fkAttr]) && $attrs[$fkAttr]['id'] === $want['id'];
		});
		$result['resolved'][$fkAttr] = $want;
		$available = array_values(array_diff($available, $pick['used']));
		unset($open[$fkAttr]);
	}
	$result['unresolved'] = array_keys($open);
	$result['used'] = array_values(array_diff(array_keys($words), $available));
	$result['child'] = count($remaining) == 1 ? (int) array_key_first($remaining) : 0;
	return $result;
}

/**
 * Pick the existing variant of a parent product that the line's label
 * describes (see auvorechnungsimport_resolve_variant()).
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param string $label
 * @return int fk_product of the variant, 0 if not unique
 */
function auvorechnungsimport_pick_variant($db, $fkParent, $label)
{
	$r = auvorechnungsimport_resolve_variant($db, $fkParent, $label);
	return $r['child'];
}

/**
 * @param DoliDB $db
 * @param int $fkProduct
 * @return int Parent product if $fkProduct is a variant, else 0
 */
function auvorechnungsimport_variant_parent_of($db, $fkProduct)
{
	$sql = "SELECT fk_product_parent FROM ".MAIN_DB_PREFIX."product_attribute_combination";
	$sql .= " WHERE fk_product_child = ".((int) $fkProduct).$db->plimit(1);
	$resql = $db->query($sql);
	return ($resql && ($obj = $db->fetch_object($resql))) ? (int) $obj->fk_product_parent : 0;
}

/**
 * Supplier invoice already booked with this supplier invoice number, if any -
 * Dolibarr's unique key (ref_supplier, fk_soc, entity) would reject a second one.
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param string $refSupplier
 * @return ?FactureFournisseur
 */
function auvorechnungsimport_find_existing_invoice($db, $fkSoc, $refSupplier)
{
	global $conf;
	$refSupplier = trim((string) $refSupplier);
	if ($fkSoc <= 0 || $refSupplier === '') {
		return null;
	}
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn";
	$sql .= " WHERE fk_soc = ".((int) $fkSoc)." AND ref_supplier = '".$db->escape($refSupplier)."' AND entity = ".((int) $conf->entity);
	$resql = $db->query($sql.$db->plimit(1));
	if (!$resql || !($obj = $db->fetch_object($resql))) {
		return null;
	}
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
	$invoice = new FactureFournisseur($db);
	return $invoice->fetch((int) $obj->rowid) > 0 ? $invoice : null;
}

/**
 * Directory Dolibarr shows as the documents of a supplier invoice (same
 * computation as fourn/facture/document.php). Dolibarr renames it along with
 * the invoice ref on validation, so files put there stay attached.
 *
 * @param FactureFournisseur $invoice
 * @return string
 */
function auvorechnungsimport_invoice_docdir($invoice)
{
	return getMultidirOutput($invoice).'/'.get_exdir($invoice->id, 2, 0, 0, $invoice, 'invoice_supplier').dol_sanitizeFileName($invoice->ref);
}

/**
 * Supplier order linked to a supplier invoice, if any.
 *
 * @param DoliDB $db
 * @param FactureFournisseur $invoice
 * @return int Order id or 0
 */
function auvorechnungsimport_linked_order_id($db, $invoice)
{
	// Both link directions (Dolibarr stores ours as invoice -> order).
	$invoice->fetchObjectLinked(null, '', null, '', 'OR', 1, 'sourcetype', 0);
	if (!empty($invoice->linkedObjectsIds['order_supplier'])) {
		return (int) reset($invoice->linkedObjectsIds['order_supplier']);
	}
	return 0;
}

/**
 * Create a supplier order mirroring a supplier invoice, so goods can be received
 * into stock. With the lot/serial module enabled, Dolibarr only allows stock
 * increases through a reception of a supplier order, not on a supplier invoice.
 *
 * The order gets the invoice's lines, is linked to the invoice, validated,
 * approved and set to "ordered" (so "Receive" is available right away) and
 * marked billed - the invoice already exists. If the user lacks the right for
 * a step, the order stays at the previous status and a warning is returned.
 *
 * @param DoliDB $db
 * @param User $user
 * @param FactureFournisseur $invoice With lines fetched
 * @param string $priceBase 'HT' or 'TTC' - as the invoice lines were entered
 * @param array<int,string> $warnings Receives non-fatal problems
 * @return int Order id, <0 on error (errors in $invoice->errors)
 */
function auvorechnungsimport_create_supplier_order($db, $user, $invoice, $priceBase, &$warnings)
{
	global $langs;
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

	$warnings = array();

	// Dolibarr refuses to validate an order with a product not "for purchase" -
	// check upfront and name them, instead of leaving a stuck draft behind.
	$notBuyable = array();
	foreach ($invoice->lines as $line) {
		if ($line->fk_product > 0) {
			$product = new Product($db);
			if ($product->fetch($line->fk_product) > 0 && empty($product->status_buy)) {
				$notBuyable[$product->id] = $product->getNomUrl(1);
			}
		}
	}
	if (!empty($notBuyable)) {
		$invoice->errors[] = $langs->trans('AuvoRechnungsimportErrNotBuyable', implode(', ', $notBuyable));
		return -1;
	}

	$order = new CommandeFournisseur($db);
	$order->socid = $invoice->socid;
	$order->ref_supplier = $invoice->ref_supplier;
	$order->date_commande = $invoice->date;
	$order->cond_reglement_id = $invoice->cond_reglement_id;
	$order->mode_reglement_id = $invoice->mode_reglement_id;
	$order->fk_project = $invoice->fk_project;
	$order->note_private = $langs->trans('AuvoRechnungsimportOrderCreatedFrom', $invoice->ref);
	$order->linked_objects = array('invoice_supplier' => $invoice->id);

	if ($order->create($user) <= 0) {
		$invoice->errors = array_merge($invoice->errors, $order->errors, array($order->error));
		return -1;
	}
	$order->fetch_thirdparty();
	foreach ($invoice->lines as $line) {
		$ttc = ($priceBase === 'TTC');
		$result = $order->addline(
			$line->desc,
			$ttc ? 0 : (float) $line->subprice,
			(float) $line->qty,
			$line->tva_tx,
			(float) $line->localtax1_tx,
			(float) $line->localtax2_tx,
			(int) $line->fk_product,
			0,
			(string) $line->ref_supplier,
			(float) $line->remise_percent,
			$ttc ? 'TTC' : 'HT',
			$ttc ? (float) $line->pu_ttc : 0,
			(int) $line->product_type,
			(int) $line->info_bits,
			0,
			$line->date_start ?: null,
			$line->date_end ?: null,
			array(),
			$line->fk_unit
		);
		if ($result <= 0) {
			$invoice->errors = array_merge($invoice->errors, $order->errors, array($order->error));
			return -1;
		}
	}

	if ($order->valid($user) < 0) {
		$warnings[] = $langs->trans('AuvoRechnungsimportOrderStepFailed', $langs->transnoentities('Validate'), $order->error);
		return $order->id;
	}
	if ($order->approve($user) < 0) {
		$warnings[] = $langs->trans('AuvoRechnungsimportOrderStepFailed', $langs->transnoentities('Approve'), $order->error);
		return $order->id;
	}
	$order->fetch($order->id);
	if ($order->commande($user, $invoice->date ?: dol_now(), 0) < 0) {
		$warnings[] = $langs->trans('AuvoRechnungsimportOrderStepFailed', $langs->transnoentities('SupplierOrderSubmitedBack'), $order->error);
		return $order->id;
	}
	if ($order->classifyBilled($user) < 0) {
		$warnings[] = $langs->trans('AuvoRechnungsimportOrderStepFailed', $langs->transnoentities('ClassifyBilled'), $order->error);
	}
	return $order->id;
}

/**
 * Copy the uploaded original PDF into the supplier invoice's documents.
 *
 * @param ImportedInvoice $object
 * @param FactureFournisseur $invoice
 * @return int 1 if copied, 0 if already there, <0 on error
 */
function auvorechnungsimport_attach_original($object, $invoice)
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
	$srcfile = DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath;
	if (!is_readable($srcfile)) {
		return -1;
	}
	$destdir = auvorechnungsimport_invoice_docdir($invoice);
	$destfile = $destdir.'/'.dol_sanitizeFileName($object->original_filename);
	dol_mkdir($destdir);
	// Files embedded in an e-invoice (BG-24: often the invoice as PDF,
	// timesheets...) are attached too; an existing file is never replaced.
	$inv = auvorechnungsimport_load_einvoice($srcfile);
	foreach ($inv !== null ? $inv['attachments'] : array() as $att) {
		$name = dol_sanitizeFileName($att['name']);
		if ($att['content'] === '' || $name === '' || is_file($destdir.'/'.$name)) {
			continue;
		}
		file_put_contents($destdir.'/'.$name, $att['content']);
		@chmod($destdir.'/'.$name, octdec(getDolGlobalString('MAIN_UMASK', '0664')));
	}
	if (is_file($destfile)) {
		return 0;
	}
	return dol_copy($srcfile, $destfile, '0', 1) > 0 ? 1 : -1;
}

/**
 * What would have to be created so that the line has its own variant of the
 * parent product - for lines whose variant doesn't exist yet.
 *
 *   a) Every attribute's value is named in the label, but that combination of
 *      values has no variant yet ("J6100 ... 825/grau XS" with XS never
 *      ordered in grau): create the variant.
 *   b) All attributes but one are named, and the label carries a new value in
 *      the supplier's "code/name" notation ("335/gelb" when the color
 *      attribute has no 335 yet): create that attribute value too. The code
 *      must look like the attribute's existing refs (digits if they are
 *      digits), so a size or random token isn't mistaken for a color.
 *
 * @param DoliDB $db
 * @param int $fkParent
 * @param string $label
 * @return ?array{attrs:array<int,array{id:int,ref:string,value:string,new:bool}>,ref:string,label:string}
 *         Attribute values in Dolibarr's attribute order (new ones with id 0), preview of ref and label
 */
function auvorechnungsimport_plan_variant($db, $fkParent, $label)
{
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	$r = auvorechnungsimport_resolve_variant($db, $fkParent, $label);
	if ($r['child'] || empty($r['resolved'])) {
		return null;
	}
	$attributes = auvorechnungsimport_variant_attribute_values($db, $fkParent);

	if (count($r['unresolved']) == 1) {
		$fkAttr = $r['unresolved'][0];
		// "335/gelb L": code before the slash, name = rest of the label minus the
		// words already used by the other attributes ("L").
		if (!preg_match('/(?:^|\s)([\p{L}\d]{1,6})\s*\/\s*(.+)$/u', trim($label), $m)) {
			return null;
		}
		$code = $m[1];
		$restWords = preg_split('/\s+/u', trim($m[2]));
		$usedWords = array();
		foreach ($r['used'] as $pos) {
			$usedWords[$r['words'][$pos]] = true;
		}
		$nameWords = array();
		foreach ($restWords as $w) {
			$norm = auvorechnungsimport_normalize_line_label($w);
			if ($norm !== '' && isset($usedWords[$norm])) {
				continue;
			}
			$nameWords[] = $w;
		}
		$name = trim(implode(' ', $nameWords));
		if ($name === '' || !preg_match('/\p{L}/u', $name) || dol_strlen($name) > 40) {
			return null;
		}
		// Code shaped like the attribute's existing refs (all digits vs. not)
		$existing = $attributes[$fkAttr]['values'] ?? array();
		$digitRefs = 0;
		foreach ($existing as $v) {
			$digitRefs += ctype_digit($v['ref']) ? 1 : 0;
		}
		if (!empty($existing) && (ctype_digit($code) xor ($digitRefs * 2 >= count($existing)))) {
			return null;
		}
		foreach ($existing as $v) {
			if (strtoupper($v['ref']) === strtoupper($code)) {
				return null; // code exists with another name: a conflict, not a new value
			}
		}
		$r['resolved'][$fkAttr] = array('id' => 0, 'ref' => strtoupper($code), 'value' => $name, 'new' => true);
	} elseif (!empty($r['unresolved'])) {
		return null;
	}

	$parent = new Product($db);
	if ($parent->fetch($fkParent) <= 0) {
		return null;
	}
	// Same ref/label scheme as the variants module powervariantes builds:
	// PARENT-VAL1-VAL2 / "Parent label (Value 1, Value 2)", attributes in order.
	$ordered = array();
	foreach (array_keys($attributes) as $fkAttr) {
		if (isset($r['resolved'][$fkAttr])) {
			$ordered[$fkAttr] = $r['resolved'][$fkAttr] + array('new' => false);
		}
	}
	$refs = array();
	$values = array();
	foreach ($ordered as $v) {
		$refs[] = strtoupper(trim($v['ref']));
		$values[] = $v['value'];
	}
	return array(
		'attrs' => $ordered,
		'ref' => $parent->ref.'-'.implode('-', $refs),
		'label' => $parent->label.' ('.implode(', ', $values).')',
	);
}

/**
 * Create a planned variant (see auvorechnungsimport_plan_variant()): new attribute
 * values first, then the variant itself - through the powervariantes module
 * when it is enabled (it defines this installation's ref/label scheme), else
 * with Dolibarr's own variant creation.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkParent
 * @param array{attrs:array<int,array{id:int,ref:string,value:string,new:bool}>} $plan
 * @param array<int,string> $errors Receives error messages
 * @return int fk_product of the new variant, <0 on error
 */
function auvorechnungsimport_create_variant($db, $user, $fkParent, $plan, &$errors)
{
	require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttributeValue.class.php';
	require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';
	$errors = array();

	$valueIds = array();
	foreach ($plan['attrs'] as $fkAttr => $v) {
		if (!empty($v['new'])) {
			$value = new ProductAttributeValue($db);
			$value->fk_product_attribute = $fkAttr;
			$value->ref = $v['ref'];
			$value->value = $v['value'];
			if ($value->create($user) <= 0) {
				$errors = array_merge(array($value->error), $value->errors);
				return -1;
			}
			$valueIds[$fkAttr] = (int) $value->id;
		} else {
			$valueIds[$fkAttr] = (int) $v['id'];
		}
	}

	$parent = new Product($db);
	if ($parent->fetch($fkParent) <= 0) {
		$errors[] = 'Parent product not found';
		return -1;
	}
	if (isModEnabled('powervariantes') && is_readable(DOL_DOCUMENT_ROOT.'/custom/powervariantes/class/powervariantes.class.php')) {
		require_once DOL_DOCUMENT_ROOT.'/custom/powervariantes/class/powervariantes.class.php';
		$pv = new PowerVariantes($db);
		$selected = array();
		foreach ($valueIds as $fkAttr => $id) {
			$selected[$fkAttr] = array($id);
		}
		if ($pv->generateCombinationsFromValues($fkParent, $selected) <= 0) {
			$errors[] = $pv->error;
			return -1;
		}
	} else {
		$comb = new ProductCombination($db);
		if ($comb->createProductCombination($user, $parent, $valueIds, array()) < 0) {
			$errors = array_merge(array($comb->error), $comb->errors);
			return -1;
		}
	}

	// Find the new variant by its exact values.
	foreach (auvorechnungsimport_variant_combinations($db, $fkParent, true) as $fkChild => $attrs) {
		$ok = count($attrs) == count($valueIds);
		foreach ($valueIds as $fkAttr => $id) {
			$ok = $ok && isset($attrs[$fkAttr]) && $attrs[$fkAttr]['id'] === $id;
		}
		if ($ok) {
			// powervariantes computes the price but Product::create() doesn't store
			// it (variants come out at 0.00): sync price, weight etc. from the
			// parent + the combination's variation, as Dolibarr does itself when
			// the parent changes.
			$comb = new ProductCombination($db);
			if ($comb->fetchByFkProductChild($fkChild) > 0 && $comb->updateProperties($parent, $user) < 0) {
				$errors = array_merge(array($comb->error), $comb->errors);
				return -1;
			}
			return (int) $fkChild;
		}
	}
	$errors[] = 'Created variant not found';
	return -1;
}

/**
 * Lock file held by the background worker while it works through the queue.
 *
 * @return string
 */
function auvorechnungsimport_queue_lockfile()
{
	return DOL_DATA_ROOT.'/auvorechnungsimport/temp/queue.lock';
}

/**
 * Whether a background worker is currently running (holds the queue lock).
 *
 * @return bool
 */
function auvorechnungsimport_queue_is_running()
{
	$fh = @fopen(auvorechnungsimport_queue_lockfile(), 'c');
	if (!$fh) {
		return false;
	}
	$free = flock($fh, LOCK_EX | LOCK_NB);
	if ($free) {
		flock($fh, LOCK_UN);
	}
	fclose($fh);
	return !$free;
}

/**
 * Start the background worker (scripts/process_queue.php) as a detached PHP
 * CLI process, unless one is already running. It has no web request time
 * limit, so multi-page scans with OCR don't hit max_execution_time.
 *
 * @return bool True if a worker is running or was started
 */
function auvorechnungsimport_queue_start()
{
	global $conf;
	if (auvorechnungsimport_queue_is_running()) {
		return true;
	}
	if (!function_exists('exec')) {
		return false;
	}
	dol_mkdir(dirname(auvorechnungsimport_queue_lockfile()));
	$php = getDolGlobalString('AUVORECHNUNGSIMPORT_PHP_CLI_PATH');
	if ($php === '' || !is_executable($php)) {
		$php = is_executable(PHP_BINDIR.'/php') ? PHP_BINDIR.'/php' : '/usr/bin/php';
	}
	$script = dirname(__DIR__, 2).'/scripts/process_queue.php';
	// setsid + all streams redirected: fully detached from the web server
	// process, so the request returns immediately and the worker survives it.
	$cmd = (is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : 'nohup ').escapeshellarg($php).' '.escapeshellarg($script).' '.((int) $conf->entity).' > /dev/null 2>&1 < /dev/null &';
	exec($cmd, $output, $retval);
	return $retval === 0;
}

/**
 * Work through all queued imports of the current entity, oldest first. Only
 * one worker at a time (queue lock); returns at once if another one runs.
 *
 * A record left at "processing" by a worker that died (e.g. a PHP fatal
 * error on a broken PDF) is set to error first - otherwise that same file
 * would kill every following worker run.
 *
 * @param DoliDB $db
 * @return int Number of imports analysed, -1 if another worker holds the lock
 */
function auvorechnungsimport_process_queue($db)
{
	global $conf;
	require_once dirname(__DIR__, 2).'/class/importedinvoice.class.php';

	dol_mkdir(dirname(auvorechnungsimport_queue_lockfile()));
	$fh = fopen(auvorechnungsimport_queue_lockfile(), 'c');
	if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
		return -1;
	}

	$sql = "UPDATE ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice SET status = ".ImportedInvoice::STATUS_ERROR;
	$sql .= ", note_private = CONCAT(COALESCE(note_private, ''), 'Analyse abgebrochen (Hintergrundprozess beendet).')";
	$sql .= " WHERE status = ".ImportedInvoice::STATUS_PROCESSING." AND entity = ".((int) $conf->entity);
	$db->query($sql);

	$done = 0;
	$users = array();
	while (true) {
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice";
		$sql .= " WHERE status = ".ImportedInvoice::STATUS_QUEUED." AND entity = ".((int) $conf->entity);
		$sql .= " ORDER BY rowid".$db->plimit(1);
		$resql = $db->query($sql);
		$obj = $resql ? $db->fetch_object($resql) : null;
		if (!$obj) {
			break;
		}
		$object = new ImportedInvoice($db);
		if ($object->fetch((int) $obj->rowid) <= 0) {
			break;
		}
		if (!isset($users[$object->fk_user_creat])) {
			$users[$object->fk_user_creat] = new User($db);
			$users[$object->fk_user_creat]->fetch($object->fk_user_creat);
		}
		$runAs = $users[$object->fk_user_creat];

		$object->status = ImportedInvoice::STATUS_PROCESSING;
		$object->update($runAs, 1);

		$file = DOL_DATA_ROOT.'/auvorechnungsimport/'.$object->filepath;
		try {
			if (!is_readable($file)) {
				throw new Exception('file missing');
			}
			auvorechnungsimport_process_file($db, $object, $file);
		} catch (Throwable $e) {
			dol_syslog('AuvoRechnungsimport queue: '.$object->ref.': '.$e->getMessage(), LOG_ERR);
			$object->status = ImportedInvoice::STATUS_ERROR;
		}
		$object->update($runAs, 1);
		$done++;
	}

	flock($fh, LOCK_UN);
	fclose($fh);
	return $done;
}

/**
 * Next import waiting for review (oldest first), after $afterId if possible -
 * to go through a batch one by one.
 *
 * @param DoliDB $db
 * @param int $afterId Current record; 0 = the first one
 * @return int Record id, 0 if none left
 */
function auvorechnungsimport_next_to_review($db, $afterId)
{
	require_once dirname(__DIR__, 2).'/class/importedinvoice.class.php';
	$base = "SELECT rowid FROM ".MAIN_DB_PREFIX."auvorechnungsimport_importedinvoice";
	$base .= " WHERE status IN (".ImportedInvoice::STATUS_NEEDS_REVIEW.", ".ImportedInvoice::STATUS_MATCHED.")";
	$base .= " AND entity IN (".getEntity('importedinvoice').") AND rowid <> ".((int) $afterId);
	foreach (array(" AND rowid > ".((int) $afterId), "") as $after) {
		$resql = $db->query($base.$after." ORDER BY rowid".$db->plimit(1));
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
	}
	return 0;
}

/**
 * Line handling modes:
 *   book - take over: with the matched/chosen product, else as free text line
 *   cost - book without product (free text cost line), e.g. shipping costs
 *   skip - leave out, e.g. free promotional material
 * Only "skip" changes the invoice total; the review form warns about it.
 */
const AUVORECHNUNGSIMPORT_LINE_MODES = array('book', 'cost', 'skip');

/**
 * Keywords from the setup that pre-select a mode for all suppliers.
 *
 * @return array<string,array<int,string>> mode => keywords
 */
function auvorechnungsimport_line_mode_keywords()
{
	$rules = array();
	foreach (array('cost' => 'AUVORECHNUNGSIMPORT_KEYWORDS_COST', 'skip' => 'AUVORECHNUNGSIMPORT_KEYWORDS_SKIP') as $mode => $const) {
		$rules[$mode] = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', getDolGlobalString($const))), 'strlen'));
	}
	return $rules;
}

/**
 * Mode for a line, with where it came from ('learned', 'keyword' or '').
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param array<string,mixed> $line
 * @param bool $hasProduct A product was matched for the line
 * @return array{0:string,1:string}
 */
function auvorechnungsimport_line_mode($db, $fkSoc, $line, $hasProduct)
{
	$labelKey = auvorechnungsimport_normalize_line_label($line['label'] ?? '');
	if ($fkSoc > 0 && $labelKey !== '') {
		$sql = "SELECT key_type FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap";
		$sql .= " WHERE fk_soc = ".((int) $fkSoc)." AND key_type IN ('mode_cost', 'mode_skip')";
		$sql .= " AND match_key = '".$db->escape($labelKey)."' AND entity IN (".getEntity('auvorechnungsimport').")";
		$resql = $db->query($sql.$db->plimit(1));
		if ($resql && ($obj = $db->fetch_object($resql))) {
			return array(substr($obj->key_type, 5), 'learned');
		}
	}
	if ($hasProduct) {
		return array('book', '');
	}
	// Skip before cost: "Versandkosten" in a skip list is the more specific wish.
	$keywords = auvorechnungsimport_line_mode_keywords();
	foreach (array('skip', 'cost') as $mode) {
		foreach ($keywords[$mode] as $keyword) {
			if (preg_match('/(?<![\p{L}\d])'.preg_quote($keyword, '/').'/iu', (string) ($line['label'] ?? ''))) {
				return array($mode, 'keyword');
			}
		}
	}
	return array('book', '');
}

/**
 * Remember the mode chosen for a line (per supplier and normalized label).
 * Stored in the line map with fk_product = 0, so it never counts as a product
 * mapping. Choosing "book" again forgets an earlier cost/skip choice.
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkSoc
 * @param array<string,mixed> $line
 * @param string $mode
 * @return int <0 on error
 */
function auvorechnungsimport_learn_line_mode($db, $user, $fkSoc, $line, $mode)
{
	global $conf;
	$labelKey = auvorechnungsimport_normalize_line_label($line['label'] ?? '');
	if ($fkSoc <= 0 || $labelKey === '') {
		return 0;
	}
	$where = " WHERE entity = ".((int) $conf->entity)." AND fk_soc = ".((int) $fkSoc);
	$where .= " AND key_type IN ('mode_cost', 'mode_skip') AND match_key = '".$db->escape($labelKey)."'";
	if (!$db->query("DELETE FROM ".MAIN_DB_PREFIX."auvorechnungsimport_linemap".$where)) {
		return -1;
	}
	if ($mode === 'cost' || $mode === 'skip') {
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."auvorechnungsimport_linemap";
		$sql .= " (entity, fk_soc, key_type, match_key, fk_product, nb_used, date_creation, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fkSoc).", 'mode_".$mode."', '".$db->escape($labelKey)."', 0, 1, '".$db->idate(dol_now())."', ".((int) $user->id).")";
		if (!$db->query($sql)) {
			return -1;
		}
	}
	return 1;
}

/**
 * Id of a payment type (llx_c_paiement) by its code.
 *
 * @param DoliDB $db
 * @param string $code e.g. VIR
 * @return int Id or 0
 */
function auvorechnungsimport_payment_type_id($db, $code)
{
	if ($code === '') {
		return 0;
	}
	$resql = $db->query("SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = '".$db->escape($code)."' AND active = 1");
	$obj = $resql ? $db->fetch_object($resql) : null;
	return $obj ? (int) $obj->id : 0;
}

/**
 * Whether the supplier already has a bank account with this IBAN.
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param string $iban
 * @return int Id of the bank account or 0
 */
function auvorechnungsimport_find_supplier_bank($db, $fkSoc, $iban)
{
	$iban = preg_replace('/\s+/', '', strtoupper($iban));
	if ($fkSoc <= 0 || $iban === '') {
		return 0;
	}
	$resql = $db->query("SELECT rowid, iban_prefix FROM ".MAIN_DB_PREFIX."societe_rib WHERE fk_soc = ".((int) $fkSoc)." AND type = 'ban'");
	while ($resql && ($obj = $db->fetch_object($resql))) {
		if (preg_replace('/\s+/', '', strtoupper((string) dolDecrypt($obj->iban_prefix))) === $iban) {
			return (int) $obj->rowid;
		}
	}
	return 0;
}

/**
 * Adds the IBAN of an e-invoice to the supplier's bank accounts (never
 * replaces the default account).
 *
 * @param DoliDB $db
 * @param User $user
 * @param int $fkSoc
 * @param string $iban
 * @param string $bic
 * @param string $owner Account holder (BT-85), the supplier's name if empty
 * @return int Id of the new bank account, <=0 on error (0 = IBAN invalid or known)
 */
function auvorechnungsimport_create_supplier_bank($db, $user, $fkSoc, $iban, $bic, $owner)
{
	require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
	$iban = preg_replace('/\s+/', '', strtoupper($iban));
	if ($iban === '' || !checkIbanForAccount(null, $iban) || auvorechnungsimport_find_supplier_bank($db, $fkSoc, $iban)) {
		return 0;
	}
	$societe = new Societe($db);
	if ($societe->fetch($fkSoc) <= 0) {
		return -1;
	}
	$account = new CompanyBankAccount($db);
	$account->socid = $fkSoc;
	$account->type = 'ban';
	$account->default_rib = 0;
	$account->label = 'IBAN '.substr($iban, 0, 4).'…'.substr($iban, -4);
	$account->iban = $iban;
	$account->bic = $bic;
	$account->owner_name = $owner !== '' ? $owner : $societe->name;
	$account->owner_address = $societe->getFullAddress();
	$account->status = 1;
	$account->fk_country = (int) $societe->country_id;
	$account->country_code = $societe->country_code;
	$id = $account->create($user);
	if ($id > 0 && $account->update($user) <= 0) {
		return -1;
	}
	return $id;
}

/**
 * Translated labels for auvorechnungsimport_header_note().
 *
 * @param Translate $langs
 * @return array<string,string>
 */
function auvorechnungsimport_header_labels($langs)
{
	return array(
		'order' => $langs->transnoentities('AuvoRechnungsimportViewOrderRef'),
		'contract' => $langs->transnoentities('AuvoRechnungsimportViewContractRef'),
		'buyer' => $langs->transnoentities('AuvoRechnungsimportViewBuyerRef'),
		'reference' => $langs->transnoentities('AuvoRechnungsimportViewPaymentRef'),
		'period' => $langs->transnoentities('AuvoRechnungsimportViewPeriod'),
		'terms' => $langs->transnoentities('AuvoRechnungsimportViewPaymentTerms'),
	);
}

/**
 * Creates a supplier from the seller data of an e-invoice. Looks for an
 * existing one first (same matching as the import) so that nothing is
 * duplicated.
 *
 * @param DoliDB $db
 * @param User $user
 * @param array<string,string> $details auvorechnungsimport_seller_details()
 * @param string[] $errors Error messages
 * @return int Thirdparty id (existing or new), <=0 on error
 */
function auvorechnungsimport_create_supplier($db, $user, $details, &$errors)
{
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	if (($details['name'] ?? '') === '') {
		$errors[] = 'Name';
		return -1;
	}
	list($existing) = auvorechnungsimport_match_supplier($db, $details['vat'] ?? '', $details['name'], $details['tax_id'] ?? '');
	if ($existing) {
		return (int) $existing;
	}
	$soc = new Societe($db);
	$soc->name = $details['name'];
	$soc->client = 0;
	$soc->fournisseur = 1;
	$soc->code_fournisseur = -1;
	$soc->address = $details['address'];
	$soc->zip = $details['zip'];
	$soc->town = $details['town'];
	$soc->email = $details['email'];
	$soc->phone = $details['phone'];
	$soc->tva_intra = $details['vat'];
	$soc->idprof1 = $details['tax_id'] !== '' ? $details['tax_id'] : $details['legal_id'];
	if ($details['country'] !== '') {
		$soc->country_code = $details['country'];
		$soc->country_id = (int) getCountry($details['country'], '3');
	}
	$soc->tva_assuj = $details['vat'] !== '' ? 1 : 0;
	$id = $soc->create($user);
	if ($id <= 0) {
		$errors = array_merge($errors, $soc->errors ? $soc->errors : array($soc->error));
	}
	return $id;
}

/**
 * Invoices of a supplier a credit note can refer to: validated standard
 * invoices, newest first. Without a supplier, those of all suppliers.
 *
 * @param DoliDB $db
 * @param int $fkSoc Supplier or 0
 * @param string $exceptRef Supplier ref of the credit note itself (not offered)
 * @return array<int,array{id:int,ref:string,ref_supplier:string,date:int,total_ttc:float,socname:string}>
 */
function auvorechnungsimport_source_invoice_candidates($db, $fkSoc, $exceptRef = '')
{
	$sql = "SELECT f.rowid, f.ref, f.ref_supplier, f.datef, f.total_ttc, s.nom";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn as f INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
	$sql .= " WHERE f.type = 0 AND f.fk_statut >= 1 AND f.entity IN (".getEntity('facture_fourn').")";
	if ($fkSoc > 0) {
		$sql .= " AND f.fk_soc = ".((int) $fkSoc);
	}
	if ($exceptRef !== '') {
		$sql .= " AND f.ref_supplier <> '".$db->escape($exceptRef)."'";
	}
	$sql .= " ORDER BY f.datef DESC, f.rowid DESC".$db->plimit(200);
	$out = array();
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$out[] = array('id' => (int) $o->rowid, 'ref' => $o->ref, 'ref_supplier' => (string) $o->ref_supplier, 'date' => $db->jdate($o->datef), 'total_ttc' => (float) $o->total_ttc, 'socname' => $o->nom);
	}
	return $out;
}

/**
 * Whether an invoice is a valid origin for a credit note of this supplier.
 *
 * @param DoliDB $db
 * @param int $fkSoc
 * @param int $invoiceId
 * @return bool
 */
function auvorechnungsimport_is_source_invoice($db, $fkSoc, $invoiceId)
{
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn WHERE rowid = ".((int) $invoiceId)." AND fk_soc = ".((int) $fkSoc)
		." AND type = 0 AND fk_statut >= 1 AND entity IN (".getEntity('facture_fourn').")");
	return $resql && $db->num_rows($resql) > 0;
}

/**
 * Id of the Dolibarr unit (llx_c_units) for a UN/ECE unit code, or null.
 *
 * @param DoliDB $db
 * @param string $uneceCode e.g. HUR
 * @return int|null
 */
function auvorechnungsimport_unit_id($db, $uneceCode)
{
	static $cache = array();
	$code = auvorechnungsimport_unit_code_to_dolibarr($uneceCode);
	if ($code === '') {
		return null;
	}
	if (!array_key_exists($code, $cache)) {
		$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_units WHERE code = '".$db->escape($code)."' AND active = 1");
		$obj = $resql ? $db->fetch_object($resql) : null;
		$cache[$code] = $obj ? (int) $obj->rowid : null;
	}
	return $cache[$code];
}
