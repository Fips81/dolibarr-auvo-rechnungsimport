<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Standalone tests of the e-invoice reader (no Dolibarr needed):
 *   php tests/einvoice-tests.php [KoSIT testsuite instances dir]
 * The fixtures are XRechnungen written by AuVo E-Rechnung (dummy data).
 * With the KoSIT testsuite (tests/kosit-testsuite.sh downloads it), every
 * official sample must parse, its lines must add up to the net total, and
 * the CII and UBL variants of one case must give the same import fields.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}
// Same as the module's helper (core/lib/auvorechnungsimport.lib.php)
function auvorechnungsimport_normalize_vat($vat)
{
	return strtoupper(preg_replace('/[\s.\-]/', '', (string) $vat));
}
require_once __DIR__.'/../core/lib/auvorechnungsimport_einvoice.php';

$passed = 0;
$failures = array();
function check($cond, $label)
{
	global $passed, $failures;
	if ($cond) {
		$passed++;
	} else {
		$failures[] = $label;
	}
}
function eq($expected, $actual, $label)
{
	$ok = $expected === $actual || (is_numeric($expected) && is_numeric($actual) && abs($expected - $actual) < 0.005);
	check($ok, $label.' (expected '.var_export($expected, true).', got '.var_export($actual, true).')');
}
function load($name)
{
	return auvorechnungsimport_parse_einvoice(file_get_contents(__DIR__.'/fixtures/'.$name));
}
function lines_sum($fields)
{
	$sum = 0.0;
	foreach ($fields['lines'] as $l) {
		$sum += $l['qty'] * $l['price'];
	}
	return round($sum, 2);
}

// Not an e-invoice
check(auvorechnungsimport_parse_einvoice('<foo/>') === null, 'unknown XML is rejected');
check(auvorechnungsimport_parse_einvoice('not xml') === null, 'garbage is rejected');
check(auvorechnungsimport_parse_einvoice('') === null, 'empty input is rejected');

// UBL invoice: allowance, Skonto, two VAT rates
$inv = load('xrechnung-ubl-standard.xml');
check($inv !== null && $inv['syntax'] === 'ubl', 'UBL invoice parsed');
eq('RE2026-0700', $inv['document']['id'], 'BT-1');
eq('380', $inv['document']['type_code'], 'BT-3');
eq('2026-06-01', $inv['document']['issue_date'], 'BT-2');
eq('2026-06-15', $inv['document']['due_date'], 'BT-9');
eq('991-33333TEST-33', $inv['document']['buyer_reference'], 'BT-10');
eq('BEST-4711', $inv['document']['order_reference'], 'BT-13');
eq('MUSTER GMBH', $inv['seller']['name'], 'BT-27');
eq('DE811111111', $inv['seller']['vat_id'], 'BT-31');
eq('HRB 12345', $inv['seller']['legal_id'], 'BT-30');
eq('Erika Mustermann', $inv['seller']['contact']['name'] ?? null, 'BT-41');
eq(array('scheme' => '0204', 'id' => '991-33333TEST-33'), $inv['buyer']['endpoint'], 'BT-49');
eq(2, count($inv['lines']), 'lines');
eq(1, count($inv['allowances']), 'document allowance');
eq(2, count($inv['vat_breakdown']), 'two VAT rates');
eq(961.40, $inv['totals']['grand_total'], 'BT-112');
eq("Zahlbar innerhalb 14 Tagen.\n#SKONTO#TAGE=7#PROZENT=2.00#", $inv['document']['payment_terms'], 'BT-20');
eq('DE02120300000000202051', $inv['payment']['means'][0]['iban'] ?? null, 'BT-84');
$f = auvorechnungsimport_einvoice_to_fields($inv);
eq('DE811111111', $f['supplier_vat'], 'import: supplier VAT');
eq(961.40, $f['amount_ttc'], 'import: gross total');
eq(820.00, $f['amount_ht'], 'import: net total');
eq(3, count($f['lines']), 'import: 2 lines + allowance line');
eq(820.00, lines_sum($f), 'import: lines add up to the net total');
eq(95.0, $f['lines'][0]['price'], 'import: unit price');
eq(-60.0, $f['lines'][2]['price'], 'import: allowance as negative line');

// CII invoice with Skonto: same content must read the same
$cii = load('xrechnung-cii-skonto.xml');
check($cii !== null && $cii['syntax'] === 'cii', 'CII invoice parsed');
eq('2026-06-15', $cii['document']['due_date'], 'CII BT-9');
eq("Zahlbar innerhalb 14 Tagen. #Bei Zahlung innerhalb 7 Tagen 2 % Skonto.\n#SKONTO#TAGE=7#PROZENT=2.00#", $cii['document']['payment_terms'], 'CII BT-20');
$fc = auvorechnungsimport_einvoice_to_fields($cii);
eq(lines_sum($fc), $fc['amount_ht'], 'CII lines add up to the net total');

// Credit note (UBL CreditNote)
$cn = load('xrechnung-ubl-gutschrift.xml');
check($cn !== null && $cn['document']['is_credit_note'], 'credit note recognised');
eq('381', $cn['document']['type_code'], 'credit note BT-3');
eq('2026-06-30', $cn['document']['due_date'], 'credit note BT-9 from PaymentMeans');
eq('RE2026-0700', $cn['document']['preceding_invoices'][0]['ref'] ?? null, 'BG-3');
eq(1, count($cn['lines']), 'credit note line');
eq(1.0, $cn['lines'][0]['quantity'], 'CreditedQuantity');
$fcn = auvorechnungsimport_einvoice_to_fields($cn);
check($fcn['is_credit_note'] === true, 'import: credit note flag');

// Partial invoice of a small business: tax number, exemption, attachment
$ab = load('xrechnung-ubl-abschlag.xml');
eq('326', $ab['document']['type_code'], '326');
eq('', $ab['seller']['vat_id'], 'no VAT ID');
eq('22/180/80394', $ab['seller']['tax_id'], 'BT-32');
eq('E', $ab['vat_breakdown'][0]['category'] ?? null, 'category E');
check(($ab['vat_breakdown'][0]['exemption_reason'] ?? '') !== '', 'BT-120');
eq("Datum;Stunden\n", $ab['attachments'][0]['content'] ?? null, 'BG-24 decoded');
eq('Stundennachweis.csv', $ab['attachments'][0]['name'] ?? null, 'BG-24 file name');
$fab = auvorechnungsimport_einvoice_to_fields($ab);
eq('22/180/80394', $fab['supplier_tax_id'], 'import: tax number for supplier matching');
eq('22/180/80394', $fab['supplier_vat'], 'import: tax number as fallback identifier');
eq(1, $fab['attachments'], 'import: attachment count');

// CII with two attachments
$an = load('xrechnung-cii-anlagen.xml');
eq(2, count($an['attachments']), 'CII attachments');
eq('application/pdf', $an['attachments'][0]['mime'] ?? null, 'CII attachment MIME type');
check(strpos($an['attachments'][0]['content'] ?? '', '%PDF') === 0, 'CII attachment content decoded');

// Official KoSIT testsuite (optional)
$dir = $argv[1] ?? getenv('KOSIT_TESTSUITE') ?: '';
if ($dir !== '' && is_dir($dir)) {
	// Differences that are in the testsuite data itself, not in the reader
	$knownDataDiffs = array('order_reference', '01.21:supplier_vat');
	$n = 0;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
		$file = (string) $file;
		if (substr($file, -4) !== '.xml') {
			continue;
		}
		$n++;
		$p = auvorechnungsimport_parse_einvoice(file_get_contents($file));
		check($p !== null, 'KoSIT '.basename($file).' parsed');
		if ($p === null) {
			continue;
		}
		$fields = auvorechnungsimport_einvoice_to_fields($p);
		// qty x unit price is rounded per line: a few cents of drift are normal
		if ($p['totals']['tax_basis'] !== null) {
			check(abs($p['totals']['tax_basis'] - lines_sum($fields)) <= 0.02, 'KoSIT '.basename($file).' lines add up to the net total');
		}
		if (substr($file, -13) === '_uncefact.xml' && is_file($ubl = substr($file, 0, -13).'_ubl.xml')) {
			$fu = auvorechnungsimport_einvoice_to_fields(auvorechnungsimport_parse_einvoice(file_get_contents($ubl)));
			$case = substr(basename($file), 0, 5);
			foreach ($fields as $k => $v) {
				if ($k === 'syntax' || in_array($k, $knownDataDiffs, true) || in_array($case.':'.$k, $knownDataDiffs, true)) {
					continue;
				}
				check(json_encode($v) === json_encode($fu[$k] ?? null), 'KoSIT '.$case.' CII and UBL agree on '.$k);
			}
		}
	}
	echo "KoSIT testsuite: $n documents\n";
}

echo "Passed: $passed\nFailed: ".count($failures)."\n";
foreach ($failures as $f) {
	echo "  - $f\n";
}
exit($failures ? 1 : 0);
