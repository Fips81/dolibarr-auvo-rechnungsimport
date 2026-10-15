<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Readable view of a structured e-invoice (XRechnung / ZUGFeRD / Peppol):
 * an XML-only invoice has no picture, so the review page shows its content
 * as an HTML document instead - header data, parties, lines, VAT, totals,
 * payment (with Skonto terms spelled out), delivery, notes and attachments.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Label of a code from one of the EN 16931 code lists used in the view,
 * falling back to the raw code.
 *
 * @param string $list  'type'|'means'|'vat'|'unit'
 * @param string $code
 * @return string
 */
function auvorechnungsimport_code_label($list, $code)
{
	global $langs;
	$code = (string) $code;
	if ($code === '') {
		return '';
	}
	$key = 'AuvoRechnungsimportCode'.ucfirst($list).$code;
	$label = $langs->trans($key);
	return $label !== $key ? $label : $code;
}

/**
 * Payment terms with the KoSIT Skonto lines (#SKONTO#TAGE=7#PROZENT=2.00#)
 * spelled out as text.
 *
 * @param string $terms
 * @return string HTML
 */
function auvorechnungsimport_render_terms($terms)
{
	global $langs;
	$out = array();
	foreach (preg_split('/\r?\n/', (string) $terms) as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}
		if (preg_match('/^#SKONTO#TAGE=(\d+)#PROZENT=(\d+\.\d{2})(?:#BASISBETRAG=(-?\d+\.\d{2}))?#$/', $line, $m)) {
			$txt = $langs->trans('AuvoRechnungsimportSkontoLine', price((float) $m[2]), (int) $m[1]);
			if (!empty($m[3])) {
				$txt .= ' '.$langs->trans('AuvoRechnungsimportSkontoBase', price((float) $m[3]));
			}
			$out[] = dol_escape_htmltag($txt);
		} else {
			$out[] = dol_escape_htmltag($line);
		}
	}
	return implode('<br>', $out);
}

/**
 * Address block of a party (multi-line HTML).
 *
 * @param ?array $a
 * @return string
 */
function auvorechnungsimport_render_address($a)
{
	if (empty($a)) {
		return '';
	}
	$lines = array_filter(array($a['line1'] ?? '', $a['line2'] ?? '', $a['line3'] ?? '',
		trim(($a['postcode'] ?? '').' '.($a['city'] ?? '')), trim(($a['subdivision'] ?? '').' '.($a['country'] ?? ''))), 'strlen');
	return implode('<br>', array_map('dol_escape_htmltag', $lines));
}

/**
 * HTML view of a parsed e-invoice.
 *
 * @param array  $inv     Parsed e-invoice (auvorechnungsimport_parse_einvoice)
 * @param string $attUrl  URL to download attachment n: $attUrl.n ('' = no links)
 * @return string HTML
 */
function auvorechnungsimport_render_einvoice($inv, $attUrl = '')
{
	global $langs;
	$e = 'dol_escape_htmltag';
	$doc = $inv['document'];
	$cur = $doc['currency'] !== '' ? $doc['currency'] : 'EUR';
	$money = function ($v) use ($cur) {
		return $v === null ? '' : price($v, 0, '', 1, -1, -1, $cur);
	};
	$date = function ($d) {
		return $d !== '' ? dol_print_date(dol_stringtotime($d), 'day') : '';
	};
	$row = function ($label, $value) {
		return $value === '' || $value === null ? '' : '<tr><td class="titlefield">'.$label.'</td><td>'.$value.'</td></tr>';
	};

	$html = '<div class="auvorechnungsimport-einvoice">';
	$title = auvorechnungsimport_code_label('type', $doc['type_code']).' '.$e($doc['id']);
	$html .= '<h3 style="margin:6px 0 10px;">'.$title.'</h3>';
	if (!empty($doc['is_credit_note'])) {
		$html .= '<div class="warning">'.$langs->trans('AuvoRechnungsimportIsCreditNote').'</div>';
	}

	// Header
	$html .= '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent tableforfield">';
	$html .= $row($langs->trans('AuvoRechnungsimportViewIssueDate'), $date($doc['issue_date']));
	$html .= $row($langs->trans('AuvoRechnungsimportViewDueDate'), $date($doc['due_date']));
	$period = trim($date($doc['period_start']).' – '.$date($doc['period_end']), ' –');
	$html .= $row($langs->trans('AuvoRechnungsimportViewPeriod'), $period);
	$html .= $row($langs->trans('AuvoRechnungsimportViewDeliveryDate'), $date($inv['delivery']['date']));
	$html .= '</table></div><div class="fichehalfright"><table class="border centpercent tableforfield">';
	$html .= $row($langs->trans('AuvoRechnungsimportViewBuyerRef'), $e($doc['buyer_reference']));
	$html .= $row($langs->trans('AuvoRechnungsimportViewOrderRef'), $e($doc['order_reference']));
	$html .= $row($langs->trans('AuvoRechnungsimportViewContractRef'), $e($doc['contract_reference']));
	$html .= $row($langs->trans('AuvoRechnungsimportViewProjectRef'), $e($doc['project_reference']));
	$prev = array();
	foreach ($doc['preceding_invoices'] as $p) {
		$prev[] = $e($p['ref']).($p['date'] !== '' ? ' ('.$date($p['date']).')' : '');
	}
	$html .= $row($langs->trans('AuvoRechnungsimportViewPreceding'), implode(', ', $prev));
	$spec = $doc['specification'];
	$format = stripos($spec, 'xrechnung') !== false ? 'XRechnung' : (stripos($spec, 'peppol') !== false ? 'Peppol BIS' : 'EN 16931');
	$html .= $row($langs->trans('AuvoRechnungsimportViewFormat'), $e($format.' ('.strtoupper($inv['syntax']).')').'<br><span class="opacitymedium small">'.$e($spec).'</span>');
	$html .= '</table></div></div><div class="clearboth"></div>';

	// Parties
	$party = function ($p, $title) use ($e, $row, $langs) {
		if (empty($p)) {
			return '';
		}
		$h = '<table class="border centpercent tableforfield"><tr class="liste_titre"><td colspan="2">'.$title.'</td></tr>';
		$name = $e($p['name']).($p['trading_name'] !== '' && $p['trading_name'] !== $p['name'] ? '<br><span class="opacitymedium">'.$e($p['trading_name']).'</span>' : '');
		$h .= $row($langs->trans('Name'), $name);
		$h .= $row($langs->trans('Address'), auvorechnungsimport_render_address($p['address']));
		$h .= $row($langs->trans('VATIntra'), $e($p['vat_id']));
		$h .= $row($langs->trans('AuvoRechnungsimportViewTaxId'), $e($p['tax_id']));
		$h .= $row($langs->trans('AuvoRechnungsimportViewLegalId'), $e($p['legal_id']));
		$ids = array();
		foreach ($p['ids'] as $id) {
			$ids[] = $e($id['id']).($id['scheme'] !== '' ? ' <span class="opacitymedium">('.$e($id['scheme']).')</span>' : '');
		}
		$h .= $row($langs->trans('AuvoRechnungsimportViewPartyIds'), implode('<br>', $ids));
		if (!empty($p['contact'])) {
			$c = array_filter(array($e($p['contact']['name']), $e($p['contact']['phone']), $e($p['contact']['email'])), 'strlen');
			$h .= $row($langs->trans('AuvoRechnungsimportViewContact'), implode('<br>', $c));
		}
		if (!empty($p['endpoint'])) {
			$h .= $row($langs->trans('AuvoRechnungsimportViewEndpoint'), $e($p['endpoint']['id']).' <span class="opacitymedium">('.$e($p['endpoint']['scheme']).')</span>');
		}
		return $h.'</table>';
	};
	$html .= '<div class="fichecenter" style="margin-top:10px;"><div class="fichehalfleft">'.$party($inv['seller'], $langs->trans('AuvoRechnungsimportViewSeller')).'</div>';
	$html .= '<div class="fichehalfright">'.$party($inv['buyer'], $langs->trans('AuvoRechnungsimportViewBuyer')).'</div></div><div class="clearboth"></div>';

	// Lines
	$html .= '<div class="div-table-responsive-no-min" style="margin-top:10px;"><table class="noborder centpercent">';
	$html .= '<tr class="liste_titre"><td>#</td><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('Qty').'</td><td>'.$langs->trans('AuvoRechnungsimportViewUnit').'</td>';
	$html .= '<td class="right">'.$langs->trans('PriceUHT').'</td><td class="right">'.$langs->trans('VAT').'</td><td class="right">'.$langs->trans('TotalHT').'</td></tr>';
	foreach ($inv['lines'] as $l) {
		$desc = '<strong>'.$e($l['name']).'</strong>';
		if ($l['description'] !== '' && $l['description'] !== $l['name']) {
			$desc .= '<br>'.nl2br($e($l['description']));
		}
		$meta = array_filter(array(
			$l['seller_item_id'] !== '' ? $langs->trans('AuvoRechnungsimportViewItemNo').' '.$e($l['seller_item_id']) : '',
			$l['gtin'] !== '' ? 'GTIN '.$e($l['gtin']) : '',
			$l['note'] !== '' ? $e($l['note']) : '',
			($l['period_start'] !== '' || $l['period_end'] !== '') ? trim($date($l['period_start']).' – '.$date($l['period_end']), ' –') : '',
		), 'strlen');
		if ($meta) {
			$desc .= '<br><span class="opacitymedium small">'.implode(' · ', $meta).'</span>';
		}
		$price = $l['net_price'] === null ? '' : price($l['net_price'], 0, '', 1, -1, -1).(!empty($l['base_quantity']) && (float) $l['base_quantity'] != 1.0 ? ' / '.price($l['base_quantity'], 0, '', 1, -1, -1) : '');
		$vat = auvorechnungsimport_code_label('vat', $l['vat_category']).($l['vat_rate'] !== null ? ' '.price($l['vat_rate'], 0, '', 1, -1, -1).' %' : '');
		$html .= '<tr class="oddeven"><td>'.$e($l['id']).'</td><td>'.$desc.'</td><td class="right nowraponall">'.($l['quantity'] === null ? '' : price($l['quantity'], 0, '', 1, -1, -1)).'</td>';
		$html .= '<td>'.$e(auvorechnungsimport_code_label('unit', $l['unit_code'])).'</td><td class="right nowraponall">'.$price.'</td><td class="right nowraponall">'.$e($vat).'</td><td class="right nowraponall">'.$money($l['net_amount']).'</td></tr>';
	}
	foreach ($inv['allowances'] as $a) {
		$label = ($a['is_charge'] ? $langs->trans('AuvoRechnungsimportViewCharge') : $langs->trans('AuvoRechnungsimportViewAllowance')).($a['reason'] !== '' ? ': '.$e($a['reason']) : '');
		$vat = auvorechnungsimport_code_label('vat', $a['vat_category']).($a['vat_rate'] !== null ? ' '.price($a['vat_rate'], 0, '', 1, -1, -1).' %' : '');
		$html .= '<tr class="oddeven"><td></td><td><em>'.$label.'</em></td><td></td><td></td><td></td><td class="right nowraponall">'.$e($vat).'</td><td class="right nowraponall">'.$money($a['is_charge'] ? $a['amount'] : -$a['amount']).'</td></tr>';
	}
	$html .= '</table></div>';

	// VAT breakdown and totals
	$t = $inv['totals'];
	$html .= '<div class="fichecenter" style="margin-top:10px;"><div class="fichehalfleft"><table class="noborder centpercent">';
	$html .= '<tr class="liste_titre"><td>'.$langs->trans('AuvoRechnungsimportViewVatCategory').'</td><td class="right">'.$langs->trans('AuvoRechnungsimportViewVatBasis').'</td><td class="right">'.$langs->trans('VAT').'</td></tr>';
	foreach ($inv['vat_breakdown'] as $v) {
		$cat = auvorechnungsimport_code_label('vat', $v['category']).($v['rate'] !== null ? ' '.price($v['rate'], 0, '', 1, -1, -1).' %' : '');
		$reason = trim($v['exemption_reason'].($v['exemption_code'] !== '' ? ' ('.$v['exemption_code'].')' : ''));
		$html .= '<tr class="oddeven"><td>'.$e($cat).($reason !== '' ? '<br><span class="opacitymedium small">'.$e($reason).'</span>' : '').'</td>';
		$html .= '<td class="right nowraponall">'.$money($v['basis']).'</td><td class="right nowraponall">'.$money($v['tax']).'</td></tr>';
	}
	$html .= '</table></div><div class="fichehalfright"><table class="border centpercent tableforfield">';
	foreach (array(
		'line_total' => 'AuvoRechnungsimportViewLineTotal', 'allowance_total' => 'AuvoRechnungsimportViewAllowanceTotal',
		'charge_total' => 'AuvoRechnungsimportViewChargeTotal', 'tax_basis' => 'TotalHT', 'tax_total' => 'TotalVAT',
		'grand_total' => 'TotalTTC', 'prepaid' => 'AuvoRechnungsimportViewPrepaid', 'rounding' => 'AuvoRechnungsimportViewRounding',
	) as $k => $label) {
		if ($t[$k] !== null && ($t[$k] != 0 || in_array($k, array('line_total', 'tax_basis', 'tax_total', 'grand_total'), true))) {
			$html .= '<tr><td class="titlefield">'.$langs->trans($label).'</td><td class="right">'.$money($t[$k]).'</td></tr>';
		}
	}
	$html .= '<tr><td><strong>'.$langs->trans('AuvoRechnungsimportViewDuePayable').'</strong></td><td class="right"><strong>'.$money($t['due_payable']).'</strong></td></tr>';
	$html .= '</table></div></div><div class="clearboth"></div>';

	// Payment
	$pay = $inv['payment'];
	$html .= '<table class="border centpercent tableforfield" style="margin-top:10px;"><tr class="liste_titre"><td colspan="2">'.$langs->trans('AuvoRechnungsimportViewPayment').'</td></tr>';
	foreach ($pay['means'] as $pm) {
		$v = $e(auvorechnungsimport_code_label('means', $pm['code']));
		if ($pm['iban'] !== '') {
			$v .= '<br>IBAN '.$e($pm['iban']).($pm['bic'] !== '' ? ' · BIC '.$e($pm['bic']) : '').($pm['account_name'] !== '' ? ' · '.$e($pm['account_name']) : '');
		}
		if ($pm['debtor_iban'] !== '') {
			$v .= '<br>'.$langs->trans('AuvoRechnungsimportViewDebtorIban').' '.$e($pm['debtor_iban']);
		}
		$html .= $row($langs->trans('PaymentMode'), $v);
	}
	$html .= $row($langs->trans('AuvoRechnungsimportViewRemittance'), $e($pay['remittance']));
	$html .= $row($langs->trans('AuvoRechnungsimportViewMandate'), $e($pay['mandate_id']));
	$html .= $row($langs->trans('AuvoRechnungsimportViewCreditorId'), $e($pay['creditor_id']));
	$html .= $row($langs->trans('PaymentConditions'), auvorechnungsimport_render_terms($doc['payment_terms']));
	$html .= $row($langs->trans('AuvoRechnungsimportViewPayee'), $e($inv['payee']));
	$html .= '</table>';

	// Delivery
	if ($inv['delivery']['name'] !== '' || !empty($inv['delivery']['address'])) {
		$html .= '<table class="border centpercent tableforfield" style="margin-top:10px;"><tr class="liste_titre"><td colspan="2">'.$langs->trans('AuvoRechnungsimportViewDelivery').'</td></tr>';
		$html .= $row($langs->trans('Name'), $e($inv['delivery']['name']));
		$html .= $row($langs->trans('Address'), auvorechnungsimport_render_address($inv['delivery']['address']));
		$html .= '</table>';
	}

	// Notes
	if (!empty($doc['notes'])) {
		$html .= '<table class="border centpercent tableforfield" style="margin-top:10px;"><tr class="liste_titre"><td colspan="2">'.$langs->trans('AuvoRechnungsimportViewNotes').'</td></tr>';
		foreach ($doc['notes'] as $n) {
			$html .= '<tr><td class="titlefield opacitymedium">'.$e($n['subject']).'</td><td>'.nl2br($e($n['content'])).'</td></tr>';
		}
		$html .= '</table>';
	}

	// Attachments
	if (!empty($inv['attachments'])) {
		$html .= '<table class="border centpercent tableforfield" style="margin-top:10px;"><tr class="liste_titre"><td colspan="2">'.$langs->trans('AuvoRechnungsimportViewAttachments').'</td></tr>';
		foreach ($inv['attachments'] as $i => $a) {
			$name = $a['name'] !== '' ? $a['name'] : $a['id'];
			if ($a['content'] !== '' && $attUrl !== '') {
				$link = '<a href="'.$e($attUrl.$i).'">'.img_picto('', 'download', 'class="pictofixedwidth"').$e($name).'</a> <span class="opacitymedium small">('.dol_print_size(strlen($a['content'])).')</span>';
			} elseif ($a['uri'] !== '') {
				$link = $e($name).' <span class="opacitymedium small">'.$e($a['uri']).'</span>';
			} else {
				$link = $e($name);
			}
			$html .= '<tr><td class="titlefield">'.$e($a['description'] !== '' ? $a['description'] : $a['id']).'</td><td>'.$link.'</td></tr>';
		}
		$html .= '</table>';
	}

	return $html.'</div>';
}
