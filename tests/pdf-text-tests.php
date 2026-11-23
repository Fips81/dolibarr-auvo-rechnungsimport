<?php
/*
 * Regression test for PDF text extraction and supplier-name recognition.
 * Run from an installed Dolibarr checkout with:
 *   php tests/pdf-text-tests.php
 */

if (PHP_SAPI !== 'cli') exit(1);

define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 3));
require_once __DIR__.'/../core/lib/auvorechnungsimport.lib.php';

$pdf = __DIR__.'/fixtures/text-sender-name-linebreak.pdf';
$text = auvorechnungsimport_extract_pdftext($pdf);
if ($text === '') {
	fwrite(STDERR, "PDF text extraction failed; check that smalot/pdfparser is installed.\n");
	exit(1);
}

$fields = auvorechnungsimport_parse_fields($text);
$expected = 'Sportgemeinschaft Zusammenspiel e V';
if (($fields['supplier_name'] ?? '') !== $expected) {
	fwrite(STDERR, "Expected supplier name: {$expected}\nGot: ".($fields['supplier_name'] ?? '(empty)')."\n");
	exit(1);
}
if (empty($fields['supplier_name_confident'])) {
	fwrite(STDERR, "Supplier name was not marked as confidently recognized.\n");
	exit(1);
}

foreach (array('§ 19, Abs. 1 UStG', "§19\nAbsatz 1 UStG", '§ 19 Abs. 1 Umsatzsteuergesetz', 'gemäß §19 UStG', 'Kleinunternehmerregelung') as $smallBusinessText) {
	if (!auvorechnungsimport_is_german_small_business_invoice($smallBusinessText)) {
		fwrite(STDERR, "§ 19 note was not recognized: {$smallBusinessText}\n");
		exit(1);
	}
}
$sampleLines = array(array('vat_rate' => 19.0, 'total' => 100.0));
list($smallBusinessLines) = auvorechnungsimport_finish_text_lines($sampleLines, array('amount_ht' => 100.0), '§ 19, Abs. 1 UStG');
if ((float) ($smallBusinessLines[0]['vat_rate'] ?? -1) !== 0.0 || ($smallBusinessLines[0]['vat_src_code'] ?? '') !== 'K19') {
	fwrite(STDERR, "§ 19 text invoice lines were not classified at 0% with K19.\n");
	exit(1);
}

echo "PDF sender-name and § 19 VAT regressions passed.\n";
