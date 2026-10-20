<?php
/*
 * Copyright (C) 2026 auvo-design <r.vogel@auvo-design.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Reader for structured e-invoices (EN 16931): XRechnung, ZUGFeRD/Factur-X
 * and Peppol BIS documents in both syntaxes, UN/CEFACT CII and OASIS UBL 2.1
 * (Invoice and CreditNote).
 *
 * auvorechnungsimport_parse_einvoice() turns the XML into one syntax-
 * independent array keyed by the semantic model of EN 16931 (document,
 * seller, buyer, delivery, payment, lines, allowances, vat_breakdown, totals,
 * attachments) - the same shape as the model of AuVo E-Rechnung, so that the
 * review form and the readable view never have to care about the syntax.
 * Amounts are floats (null if absent), dates are Y-m-d strings ('' if absent).
 */

/**
 * Parses an e-invoice XML (CII or UBL).
 *
 * @param string $xml
 * @return ?array Semantic invoice, or null if the XML is not an EN 16931 invoice
 */
function auvorechnungsimport_parse_einvoice($xml)
{
	if (!is_string($xml) || trim($xml) === '') {
		return null;
	}
	$dom = new DOMDocument();
	// LIBXML_NONET: never resolve external entities or DTDs from the network.
	if (!@$dom->loadXML($xml, LIBXML_NONET)) {
		return null;
	}
	$root = $dom->documentElement;
	if (!$root) {
		return null;
	}
	$ns = (string) $root->namespaceURI;
	if ($root->localName === 'CrossIndustryInvoice' && $ns === 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100') {
		return auvorechnungsimport_parse_cii($dom);
	}
	if (in_array($root->localName, array('Invoice', 'CreditNote'), true) && $ns === 'urn:oasis:names:specification:ubl:schema:xsd:'.$root->localName.'-2') {
		return auvorechnungsimport_parse_ubl($dom, $root->localName === 'CreditNote');
	}
	return null;
}

/**
 * Helper set for XPath reads relative to a context node.
 *
 * @param DOMXPath $xp
 * @return array{str:callable,num:callable,nodes:callable,attr:callable}
 */
function auvorechnungsimport_xpath_helpers($xp)
{
	$nodes = function ($q, $ctx = null) use ($xp) {
		$r = $ctx ? $xp->query($q, $ctx) : $xp->query($q);
		return $r ?: array();
	};
	$str = function ($q, $ctx = null) use ($nodes) {
		foreach ($nodes($q, $ctx) as $n) {
			return trim($n->textContent);
		}
		return '';
	};
	$num = function ($q, $ctx = null) use ($str) {
		$v = $str($q, $ctx);
		return ($v !== '' && is_numeric($v)) ? (float) $v : null;
	};
	$attr = function ($q, $name, $ctx = null) use ($nodes) {
		foreach ($nodes($q, $ctx) as $n) {
			return $n instanceof DOMElement ? trim($n->getAttribute($name)) : '';
		}
		return '';
	};
	return array('str' => $str, 'num' => $num, 'nodes' => $nodes, 'attr' => $attr);
}

/**
 * CII date (format 102 = CCYYMMDD) to Y-m-d.
 *
 * @param string $raw
 * @return string
 */
function auvorechnungsimport_cii_date($raw)
{
	$raw = trim((string) $raw);
	if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
		return $m[1].'-'.$m[2].'-'.$m[3];
	}
	return preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) ? substr($raw, 0, 10) : '';
}

/**
 * Empty semantic invoice (all keys present).
 *
 * @param string $syntax 'cii'|'ubl'
 * @return array
 */
function auvorechnungsimport_einvoice_skeleton($syntax)
{
	return array(
		'syntax'   => $syntax,
		'document' => array(
			'id' => '', 'type_code' => '', 'is_credit_note' => false, 'issue_date' => '', 'due_date' => '',
			'currency' => '', 'buyer_reference' => '', 'order_reference' => '', 'contract_reference' => '',
			'project_reference' => '', 'specification' => '', 'business_process' => '', 'notes' => array(),
			'period_start' => '', 'period_end' => '', 'payment_terms' => '', 'preceding_invoices' => array(),
		),
		'seller'        => null,
		'buyer'         => null,
		'payee'         => '',
		'delivery'      => array('date' => '', 'name' => '', 'address' => null),
		'payment'       => array('means' => array(), 'mandate_id' => '', 'creditor_id' => '', 'remittance' => ''),
		'lines'         => array(),
		'allowances'    => array(),
		'vat_breakdown' => array(),
		'totals'        => array(
			'line_total' => null, 'allowance_total' => null, 'charge_total' => null, 'tax_basis' => null,
			'tax_total' => null, 'grand_total' => null, 'prepaid' => null, 'rounding' => null, 'due_payable' => null,
		),
		'attachments'   => array(),
	);
}

/**
 * Reads a UN/CEFACT CrossIndustryInvoice (CII D16B).
 *
 * @param DOMDocument $dom
 * @return array
 */
function auvorechnungsimport_parse_cii($dom)
{
	$xp = new DOMXPath($dom);
	$xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
	$xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
	$xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
	$xp->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');
	$h = auvorechnungsimport_xpath_helpers($xp);
	$str = $h['str'];
	$num = $h['num'];
	$nodes = $h['nodes'];
	$attr = $h['attr'];

	$inv = auvorechnungsimport_einvoice_skeleton('cii');
	$d = &$inv['document'];
	$tx = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
	$ag = $tx.'/ram:ApplicableHeaderTradeAgreement';
	$dl = $tx.'/ram:ApplicableHeaderTradeDelivery';
	$st = $tx.'/ram:ApplicableHeaderTradeSettlement';

	$d['id'] = $str('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID');
	$d['type_code'] = $str('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode');
	$d['is_credit_note'] = ($d['type_code'] === '381');
	$d['issue_date'] = auvorechnungsimport_cii_date($str('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'));
	$d['specification'] = $str('/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');
	$d['business_process'] = $str('/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID');
	foreach ($nodes('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote') as $n) {
		$d['notes'][] = array('subject' => $str('ram:SubjectCode', $n), 'content' => $str('ram:Content', $n));
	}
	$d['currency'] = $str($st.'/ram:InvoiceCurrencyCode');
	$d['buyer_reference'] = $str($ag.'/ram:BuyerReference');
	$d['order_reference'] = $str($ag.'/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID');
	$d['contract_reference'] = $str($ag.'/ram:ContractReferencedDocument/ram:IssuerAssignedID');
	$d['project_reference'] = $str($ag.'/ram:SpecifiedProcuringProject/ram:ID');
	$d['period_start'] = auvorechnungsimport_cii_date($str($st.'/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString'));
	$d['period_end'] = auvorechnungsimport_cii_date($str($st.'/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString'));
	$d['due_date'] = auvorechnungsimport_cii_date($str($st.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'));
	$terms = array();
	foreach ($nodes($st.'/ram:SpecifiedTradePaymentTerms/ram:Description') as $n) {
		$terms[] = trim($n->textContent);
	}
	$d['payment_terms'] = implode("\n", array_filter($terms, 'strlen'));
	foreach ($nodes($st.'/ram:InvoiceReferencedDocument') as $n) {
		$d['preceding_invoices'][] = array('ref' => $str('ram:IssuerAssignedID', $n), 'date' => auvorechnungsimport_cii_date($str('ram:FormattedIssueDateTime/qdt:DateTimeString', $n)));
	}
	unset($d);

	$party = function ($node) use ($str, $nodes) {
		if (!$node) {
			return null;
		}
		$p = array('name' => $str('ram:Name', $node), 'trading_name' => '', 'ids' => array(), 'legal_id' => '', 'vat_id' => '', 'tax_id' => '',
			'address' => null, 'contact' => null, 'endpoint' => null);
		foreach ($nodes('ram:ID', $node) as $n) {
			$p['ids'][] = array('id' => trim($n->textContent), 'scheme' => '');
		}
		foreach ($nodes('ram:GlobalID', $node) as $n) {
			$p['ids'][] = array('id' => trim($n->textContent), 'scheme' => $n->getAttribute('schemeID'));
		}
		$p['legal_id'] = $str('ram:SpecifiedLegalOrganization/ram:ID', $node);
		$p['trading_name'] = $str('ram:SpecifiedLegalOrganization/ram:TradingBusinessName', $node);
		foreach ($nodes('ram:SpecifiedTaxRegistration/ram:ID', $node) as $n) {
			if ($n->getAttribute('schemeID') === 'VA') {
				$p['vat_id'] = trim($n->textContent);
			} else {
				$p['tax_id'] = trim($n->textContent);
			}
		}
		foreach ($nodes('ram:PostalTradeAddress', $node) as $a) {
			$p['address'] = array(
				'line1' => $str('ram:LineOne', $a), 'line2' => $str('ram:LineTwo', $a), 'line3' => $str('ram:LineThree', $a),
				'postcode' => $str('ram:PostcodeCode', $a), 'city' => $str('ram:CityName', $a),
				'subdivision' => $str('ram:CountrySubDivisionName', $a), 'country' => $str('ram:CountryID', $a),
			);
		}
		foreach ($nodes('ram:DefinedTradeContact', $node) as $c) {
			$p['contact'] = array(
				'name' => $str('ram:PersonName', $c) !== '' ? $str('ram:PersonName', $c) : $str('ram:DepartmentName', $c),
				'phone' => $str('ram:TelephoneUniversalCommunication/ram:CompleteNumber', $c),
				'email' => $str('ram:EmailURIUniversalCommunication/ram:URIID', $c),
			);
			break;
		}
		foreach ($nodes('ram:URIUniversalCommunication/ram:URIID', $node) as $n) {
			$p['endpoint'] = array('scheme' => $n->getAttribute('schemeID'), 'id' => trim($n->textContent));
		}
		return $p;
	};
	$first = function ($q) use ($nodes) {
		foreach ($nodes($q) as $n) {
			return $n;
		}
		return null;
	};
	$inv['seller'] = $party($first($ag.'/ram:SellerTradeParty'));
	$inv['buyer'] = $party($first($ag.'/ram:BuyerTradeParty'));
	$inv['payee'] = $str($st.'/ram:PayeeTradeParty/ram:Name');

	$shipTo = $party($first($dl.'/ram:ShipToTradeParty'));
	$inv['delivery'] = array(
		'date'    => auvorechnungsimport_cii_date($str($dl.'/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString')),
		'name'    => $shipTo ? $shipTo['name'] : '',
		'address' => $shipTo ? $shipTo['address'] : null,
	);

	$inv['payment']['creditor_id'] = $str($st.'/ram:CreditorReferenceID');
	$inv['payment']['remittance'] = $str($st.'/ram:PaymentReference');
	$inv['payment']['mandate_id'] = $str($st.'/ram:SpecifiedTradePaymentTerms/ram:DirectDebitMandateID');
	foreach ($nodes($st.'/ram:SpecifiedTradeSettlementPaymentMeans') as $pm) {
		$iban = $str('ram:PayeePartyCreditorFinancialAccount/ram:IBANID', $pm);
		$inv['payment']['means'][] = array(
			'code'         => $str('ram:TypeCode', $pm),
			'iban'         => $iban !== '' ? $iban : $str('ram:PayeePartyCreditorFinancialAccount/ram:ProprietaryID', $pm),
			'account_name' => $str('ram:PayeePartyCreditorFinancialAccount/ram:AccountName', $pm),
			'bic'          => $str('ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID', $pm),
			'debtor_iban'  => $str('ram:PayerPartyDebtorFinancialAccount/ram:IBANID', $pm),
		);
	}

	foreach ($nodes($st.'/ram:ApplicableTradeTax') as $t) {
		$inv['vat_breakdown'][] = array(
			'category' => $str('ram:CategoryCode', $t), 'rate' => $num('ram:RateApplicablePercent', $t),
			'basis' => $num('ram:BasisAmount', $t), 'tax' => $num('ram:CalculatedAmount', $t),
			'exemption_reason' => $str('ram:ExemptionReason', $t), 'exemption_code' => $str('ram:ExemptionReasonCode', $t),
		);
	}
	foreach ($nodes($st.'/ram:SpecifiedTradeAllowanceCharge') as $c) {
		$inv['allowances'][] = array(
			'is_charge' => strtolower($str('ram:ChargeIndicator/udt:Indicator', $c)) === 'true',
			'amount' => $num('ram:ActualAmount', $c), 'reason' => $str('ram:Reason', $c), 'reason_code' => $str('ram:ReasonCode', $c),
			'vat_category' => $str('ram:CategoryTradeTax/ram:CategoryCode', $c), 'vat_rate' => $num('ram:CategoryTradeTax/ram:RateApplicablePercent', $c),
		);
	}

	$sum = $st.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
	$taxTotal = null;
	foreach ($nodes($sum.'/ram:TaxTotalAmount') as $n) {
		// BT-110 in the invoice currency (a second TaxTotalAmount may carry BT-111)
		if ($taxTotal === null || $n->getAttribute('currencyID') === $inv['document']['currency']) {
			$taxTotal = is_numeric(trim($n->textContent)) ? (float) trim($n->textContent) : null;
		}
	}
	$inv['totals'] = array(
		'line_total' => $num($sum.'/ram:LineTotalAmount'), 'allowance_total' => $num($sum.'/ram:AllowanceTotalAmount'),
		'charge_total' => $num($sum.'/ram:ChargeTotalAmount'), 'tax_basis' => $num($sum.'/ram:TaxBasisTotalAmount'),
		'tax_total' => $taxTotal, 'grand_total' => $num($sum.'/ram:GrandTotalAmount'), 'prepaid' => $num($sum.'/ram:TotalPrepaidAmount'),
		'rounding' => $num($sum.'/ram:RoundingAmount'), 'due_payable' => $num($sum.'/ram:DuePayableAmount'),
	);

	foreach ($nodes($tx.'/ram:IncludedSupplyChainTradeLineItem') as $li) {
		$gtin = '';
		foreach ($nodes('ram:SpecifiedTradeProduct/ram:GlobalID', $li) as $g) {
			if ($g->getAttribute('schemeID') === '0160') {
				$gtin = trim($g->textContent);
			}
		}
		$inv['lines'][] = array(
			'id' => $str('ram:AssociatedDocumentLineDocument/ram:LineID', $li),
			'note' => $str('ram:AssociatedDocumentLineDocument/ram:IncludedNote/ram:Content', $li),
			'name' => $str('ram:SpecifiedTradeProduct/ram:Name', $li),
			'description' => $str('ram:SpecifiedTradeProduct/ram:Description', $li),
			'seller_item_id' => $str('ram:SpecifiedTradeProduct/ram:SellerAssignedID', $li),
			'buyer_item_id' => $str('ram:SpecifiedTradeProduct/ram:BuyerAssignedID', $li),
			'gtin' => $gtin,
			'quantity' => $num('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $li),
			'unit_code' => $attr('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', 'unitCode', $li),
			'net_price' => $num('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $li),
			'base_quantity' => $num('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', $li),
			'net_amount' => $num('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $li),
			'vat_category' => $str('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', $li),
			'vat_rate' => $num('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', $li),
			'period_start' => auvorechnungsimport_cii_date($str('ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString', $li)),
			'period_end' => auvorechnungsimport_cii_date($str('ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString', $li)),
		);
	}

	// BG-24 supporting documents only (TypeCode 916); 50 = tender reference
	// (BT-17) and 130 = invoiced object (BT-18) share the element in CII.
	foreach ($nodes($ag.'/ram:AdditionalReferencedDocument[ram:TypeCode = "916"]') as $ad) {
		$bin = null;
		foreach ($nodes('ram:AttachmentBinaryObject', $ad) as $b) {
			$bin = $b;
		}
		$inv['attachments'][] = array(
			'id' => $str('ram:IssuerAssignedID', $ad), 'description' => $str('ram:Name', $ad),
			'uri' => $str('ram:URIID', $ad),
			'name' => $bin ? $bin->getAttribute('filename') : '', 'mime' => $bin ? $bin->getAttribute('mimeCode') : '',
			'content' => $bin ? (string) base64_decode(preg_replace('/\s+/', '', $bin->textContent)) : '',
		);
	}

	return $inv;
}

/**
 * Reads an OASIS UBL 2.1 Invoice or CreditNote.
 *
 * @param DOMDocument $dom
 * @param bool        $isCreditNote
 * @return array
 */
function auvorechnungsimport_parse_ubl($dom, $isCreditNote)
{
	$root = $isCreditNote ? 'CreditNote' : 'Invoice';
	$xp = new DOMXPath($dom);
	$xp->registerNamespace('r', 'urn:oasis:names:specification:ubl:schema:xsd:'.$root.'-2');
	$xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
	$xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
	$h = auvorechnungsimport_xpath_helpers($xp);
	$str = $h['str'];
	$num = $h['num'];
	$nodes = $h['nodes'];
	$attr = $h['attr'];
	$r = '/r:'.$root;

	$inv = auvorechnungsimport_einvoice_skeleton('ubl');
	$d = &$inv['document'];
	$d['id'] = $str($r.'/cbc:ID');
	$d['type_code'] = $str($r.'/cbc:'.$root.'TypeCode');
	$d['is_credit_note'] = $isCreditNote || $d['type_code'] === '381';
	$d['issue_date'] = $str($r.'/cbc:IssueDate');
	$d['due_date'] = $str($r.'/cbc:DueDate') !== '' ? $str($r.'/cbc:DueDate') : $str($r.'/cac:PaymentMeans/cbc:PaymentDueDate');
	$d['specification'] = $str($r.'/cbc:CustomizationID');
	$d['business_process'] = $str($r.'/cbc:ProfileID');
	foreach ($nodes($r.'/cbc:Note') as $n) {
		// BT-21 has no UBL element: convention "#SUBJECTCODE#text"
		$text = trim($n->textContent);
		$subject = '';
		if (preg_match('/^#([A-Z]{3})#(.*)$/s', $text, $m)) {
			$subject = $m[1];
			$text = $m[2];
		}
		$d['notes'][] = array('subject' => $subject, 'content' => $text);
	}
	$d['currency'] = $str($r.'/cbc:DocumentCurrencyCode');
	$d['buyer_reference'] = $str($r.'/cbc:BuyerReference');
	// BT-13. UBL makes OrderReference/ID mandatory even when only a sales
	// order reference (BT-14) is given; Peppol fills it with "NA" then.
	$d['order_reference'] = $str($r.'/cac:OrderReference/cbc:ID');
	if (strtoupper($d['order_reference']) === 'NA') {
		$d['order_reference'] = '';
	}
	$d['contract_reference'] = $str($r.'/cac:ContractDocumentReference/cbc:ID');
	$d['project_reference'] = $str($r.'/cac:ProjectReference/cbc:ID');
	$d['period_start'] = $str($r.'/cac:InvoicePeriod/cbc:StartDate');
	$d['period_end'] = $str($r.'/cac:InvoicePeriod/cbc:EndDate');
	$terms = array();
	foreach ($nodes($r.'/cac:PaymentTerms/cbc:Note') as $n) {
		$terms[] = trim($n->textContent);
	}
	$d['payment_terms'] = implode("\n", array_filter($terms, 'strlen'));
	foreach ($nodes($r.'/cac:BillingReference/cac:InvoiceDocumentReference') as $n) {
		$d['preceding_invoices'][] = array('ref' => $str('cbc:ID', $n), 'date' => $str('cbc:IssueDate', $n));
	}
	unset($d);

	$creditorId = '';
	$party = function ($node) use ($str, $nodes, &$creditorId) {
		if (!$node) {
			return null;
		}
		$p = array('name' => $str('cac:PartyLegalEntity/cbc:RegistrationName', $node), 'trading_name' => $str('cac:PartyName/cbc:Name', $node),
			'ids' => array(), 'legal_id' => $str('cac:PartyLegalEntity/cbc:CompanyID', $node), 'vat_id' => '', 'tax_id' => '',
			'address' => null, 'contact' => null, 'endpoint' => null);
		if ($p['name'] === '') {
			$p['name'] = $p['trading_name'];
		}
		foreach ($nodes('cac:PartyIdentification/cbc:ID', $node) as $n) {
			if ($n->getAttribute('schemeID') === 'SEPA') {
				$creditorId = trim($n->textContent); // BT-90
				continue;
			}
			$p['ids'][] = array('id' => trim($n->textContent), 'scheme' => $n->getAttribute('schemeID'));
		}
		foreach ($nodes('cac:PartyTaxScheme', $node) as $ts) {
			if (strtoupper($str('cac:TaxScheme/cbc:ID', $ts)) === 'VAT') {
				$p['vat_id'] = $str('cbc:CompanyID', $ts);
			} else {
				$p['tax_id'] = $str('cbc:CompanyID', $ts);
			}
		}
		foreach ($nodes('cac:PostalAddress', $node) as $a) {
			$p['address'] = auvorechnungsimport_ubl_address($a, $str);
		}
		foreach ($nodes('cac:Contact', $node) as $c) {
			$p['contact'] = array('name' => $str('cbc:Name', $c), 'phone' => $str('cbc:Telephone', $c), 'email' => $str('cbc:ElectronicMail', $c));
		}
		foreach ($nodes('cbc:EndpointID', $node) as $n) {
			$p['endpoint'] = array('scheme' => $n->getAttribute('schemeID'), 'id' => trim($n->textContent));
		}
		return $p;
	};
	$first = function ($q) use ($nodes) {
		foreach ($nodes($q) as $n) {
			return $n;
		}
		return null;
	};
	$inv['seller'] = $party($first($r.'/cac:AccountingSupplierParty/cac:Party'));
	$inv['buyer'] = $party($first($r.'/cac:AccountingCustomerParty/cac:Party'));
	$inv['payee'] = $str($r.'/cac:PayeeParty/cac:PartyName/cbc:Name');
	$inv['payment']['creditor_id'] = $creditorId;

	$loc = $first($r.'/cac:Delivery/cac:DeliveryLocation/cac:Address');
	$inv['delivery'] = array(
		'date'    => $str($r.'/cac:Delivery/cbc:ActualDeliveryDate'),
		'name'    => $str($r.'/cac:Delivery/cac:DeliveryParty/cac:PartyName/cbc:Name'),
		'address' => $loc ? auvorechnungsimport_ubl_address($loc, $str) : null,
	);

	foreach ($nodes($r.'/cac:PaymentMeans') as $pm) {
		if ($inv['payment']['remittance'] === '') {
			$inv['payment']['remittance'] = $str('cbc:PaymentID', $pm);
		}
		if ($inv['payment']['mandate_id'] === '') {
			$inv['payment']['mandate_id'] = $str('cac:PaymentMandate/cbc:ID', $pm);
		}
		$inv['payment']['means'][] = array(
			'code'         => $str('cbc:PaymentMeansCode', $pm),
			'iban'         => $str('cac:PayeeFinancialAccount/cbc:ID', $pm),
			'account_name' => $str('cac:PayeeFinancialAccount/cbc:Name', $pm),
			'bic'          => $str('cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID', $pm),
			'debtor_iban'  => $str('cac:PaymentMandate/cac:PayerFinancialAccount/cbc:ID', $pm),
		);
	}

	// BG-23: the TaxTotal with subtotals (a second TaxTotal may carry BT-111)
	$taxTotal = null;
	foreach ($nodes($r.'/cac:TaxTotal') as $tt) {
		$subs = $nodes('cac:TaxSubtotal', $tt);
		if (count($subs) === 0 && $taxTotal !== null) {
			continue;
		}
		$taxTotal = $num('cbc:TaxAmount', $tt);
		foreach ($subs as $sub) {
			$inv['vat_breakdown'][] = array(
				'category' => $str('cac:TaxCategory/cbc:ID', $sub), 'rate' => $num('cac:TaxCategory/cbc:Percent', $sub),
				'basis' => $num('cbc:TaxableAmount', $sub), 'tax' => $num('cbc:TaxAmount', $sub),
				'exemption_reason' => $str('cac:TaxCategory/cbc:TaxExemptionReason', $sub), 'exemption_code' => $str('cac:TaxCategory/cbc:TaxExemptionReasonCode', $sub),
			);
		}
	}
	foreach ($nodes($r.'/cac:AllowanceCharge') as $c) {
		$inv['allowances'][] = array(
			'is_charge' => strtolower($str('cbc:ChargeIndicator', $c)) === 'true',
			'amount' => $num('cbc:Amount', $c), 'reason' => $str('cbc:AllowanceChargeReason', $c), 'reason_code' => $str('cbc:AllowanceChargeReasonCode', $c),
			'vat_category' => $str('cac:TaxCategory/cbc:ID', $c), 'vat_rate' => $num('cac:TaxCategory/cbc:Percent', $c),
		);
	}
	$lm = $r.'/cac:LegalMonetaryTotal';
	$inv['totals'] = array(
		'line_total' => $num($lm.'/cbc:LineExtensionAmount'), 'allowance_total' => $num($lm.'/cbc:AllowanceTotalAmount'),
		'charge_total' => $num($lm.'/cbc:ChargeTotalAmount'), 'tax_basis' => $num($lm.'/cbc:TaxExclusiveAmount'),
		'tax_total' => $taxTotal, 'grand_total' => $num($lm.'/cbc:TaxInclusiveAmount'), 'prepaid' => $num($lm.'/cbc:PrepaidAmount'),
		'rounding' => $num($lm.'/cbc:PayableRoundingAmount'), 'due_payable' => $num($lm.'/cbc:PayableAmount'),
	);

	$lineTag = $isCreditNote ? 'CreditNoteLine' : 'InvoiceLine';
	$qtyTag = $isCreditNote ? 'CreditedQuantity' : 'InvoicedQuantity';
	foreach ($nodes($r.'/cac:'.$lineTag) as $li) {
		$gtin = '';
		foreach ($nodes('cac:Item/cac:StandardItemIdentification/cbc:ID', $li) as $g) {
			if ($g->getAttribute('schemeID') === '0160') {
				$gtin = trim($g->textContent);
			}
		}
		$inv['lines'][] = array(
			'id' => $str('cbc:ID', $li),
			'note' => $str('cbc:Note', $li),
			'name' => $str('cac:Item/cbc:Name', $li),
			'description' => $str('cac:Item/cbc:Description', $li),
			'seller_item_id' => $str('cac:Item/cac:SellersItemIdentification/cbc:ID', $li),
			'buyer_item_id' => $str('cac:Item/cac:BuyersItemIdentification/cbc:ID', $li),
			'gtin' => $gtin,
			'quantity' => $num('cbc:'.$qtyTag, $li),
			'unit_code' => $attr('cbc:'.$qtyTag, 'unitCode', $li),
			'net_price' => $num('cac:Price/cbc:PriceAmount', $li),
			'base_quantity' => $num('cac:Price/cbc:BaseQuantity', $li),
			'net_amount' => $num('cbc:LineExtensionAmount', $li),
			'vat_category' => $str('cac:Item/cac:ClassifiedTaxCategory/cbc:ID', $li),
			'vat_rate' => $num('cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $li),
			'period_start' => $str('cac:InvoicePeriod/cbc:StartDate', $li),
			'period_end' => $str('cac:InvoicePeriod/cbc:EndDate', $li),
		);
	}

	// BG-24 supporting documents; DocumentTypeCode 130 is the invoiced object (BT-18)
	foreach ($nodes($r.'/cac:AdditionalDocumentReference[not(cbc:DocumentTypeCode = "130")]') as $ad) {
		$bin = null;
		foreach ($nodes('cac:Attachment/cbc:EmbeddedDocumentBinaryObject', $ad) as $b) {
			$bin = $b;
		}
		$inv['attachments'][] = array(
			'id' => $str('cbc:ID', $ad), 'description' => $str('cbc:DocumentDescription', $ad),
			'uri' => $str('cac:Attachment/cac:ExternalReference/cbc:URI', $ad),
			'name' => $bin ? $bin->getAttribute('filename') : '', 'mime' => $bin ? $bin->getAttribute('mimeCode') : '',
			'content' => $bin ? (string) base64_decode(preg_replace('/\s+/', '', $bin->textContent)) : '',
		);
	}

	return $inv;
}

/**
 * UBL address (PostalAddress / DeliveryLocation Address).
 *
 * @param DOMNode  $a
 * @param callable $str
 * @return array
 */
function auvorechnungsimport_ubl_address($a, $str)
{
	return array(
		'line1' => $str('cbc:StreetName', $a), 'line2' => $str('cbc:AdditionalStreetName', $a), 'line3' => $str('cac:AddressLine/cbc:Line', $a),
		'postcode' => $str('cbc:PostalZone', $a), 'city' => $str('cbc:CityName', $a),
		'subdivision' => $str('cbc:CountrySubentity', $a), 'country' => $str('cac:Country/cbc:IdentificationCode', $a),
	);
}

/**
 * Turns a parsed e-invoice into the review fields of the import record
 * (same keys as the text/OCR pipeline). Invoice lines carry net unit prices
 * normalized to one unit (price per BT-149 base quantity); document-level
 * allowances and charges become extra lines, so that the sum of all lines
 * matches the tax basis.
 *
 * @param array $inv Parsed e-invoice (auvorechnungsimport_parse_einvoice)
 * @return array<string,mixed>
 */
function auvorechnungsimport_einvoice_to_fields($inv)
{
	$seller = $inv['seller'] ?? array();
	$lines = array();
	foreach ($inv['lines'] as $l) {
		$qty = $l['quantity'] ?? null;
		$price = $l['net_price'];
		$total = $l['net_amount'];
		if ($price !== null && !empty($l['base_quantity'])) {
			$price /= $l['base_quantity'];
		}
		if ($qty === null) {
			$qty = 1.0;
		}
		// Line-level allowances/charges are netted into the line amount but not
		// into the unit price - derive the effective unit price from the amount
		// so that qty x price on the Dolibarr line reproduces the invoice line.
		if ($total !== null && $qty != 0 && ($price === null || abs($price * $qty - $total) > 0.01)) {
			$price = $total / $qty;
		}
		$lines[] = array(
			'source' => 'line',
			'ref_supplier' => (string) $l['seller_item_id'],
			'buyer_item_id' => (string) $l['buyer_item_id'],
			'gtin' => (string) $l['gtin'],
			'label' => $l['name'] !== '' ? $l['name'] : $l['description'],
			'qty' => $qty,
			'unit_code' => (string) $l['unit_code'],
			'price' => $price,
			'price_base' => 'HT',
			'vat_rate' => $l['vat_rate'],
			'total' => $total,
		);
	}
	foreach ($inv['allowances'] as $a) {
		if (!$a['amount']) {
			continue;
		}
		$signed = $a['is_charge'] ? $a['amount'] : -$a['amount'];
		$lines[] = array(
			'source' => $a['is_charge'] ? 'charge' : 'allowance',
			'ref_supplier' => '',
			'gtin' => '',
			'label' => $a['reason'] !== '' ? $a['reason'] : ($a['is_charge'] ? 'Zuschlag' : 'Nachlass'),
			'qty' => 1.0,
			'unit_code' => '',
			'price' => $signed,
			'price_base' => 'HT',
			'vat_rate' => $a['vat_rate'],
			'total' => $signed,
		);
	}

	// First payment means that names a creditor account (BG-17), else the first one
	$means = null;
	foreach ($inv['payment']['means'] as $pm) {
		if ($means === null || ($means['iban'] === '' && $pm['iban'] !== '')) {
			$means = $pm;
		}
	}

	$vat = (string) ($seller['vat_id'] ?? '');
	return array(
		'supplier_name' => (string) ($seller['name'] ?? ''),
		// Some sellers only give a tax number (Steuernummer, SIREN), no VAT ID.
		'supplier_vat' => auvorechnungsimport_normalize_vat($vat !== '' ? $vat : (string) ($seller['tax_id'] ?? '')),
		'supplier_tax_id' => (string) ($seller['tax_id'] ?? ''),
		'supplier' => auvorechnungsimport_seller_details($seller),
		'invoice_ref' => $inv['document']['id'],
		'invoice_date' => $inv['document']['issue_date'] !== '' ? $inv['document']['issue_date'] : null,
		'due_date' => $inv['document']['due_date'],
		'amount_ttc' => $inv['totals']['grand_total'] ?? $inv['totals']['due_payable'],
		'amount_ht' => $inv['totals']['tax_basis'],
		'amount_due' => $inv['totals']['due_payable'],
		'currency' => $inv['document']['currency'],
		'is_credit_note' => !empty($inv['document']['is_credit_note']),
		'type_code' => $inv['document']['type_code'],
		'buyer_reference' => $inv['document']['buyer_reference'],
		'order_reference' => $inv['document']['order_reference'],
		'contract_reference' => $inv['document']['contract_reference'],
		'preceding_invoices' => $inv['document']['preceding_invoices'],
		'period_start' => $inv['document']['period_start'],
		'period_end' => $inv['document']['period_end'],
		'payment_terms' => $inv['document']['payment_terms'],
		'payment_reference' => $inv['payment']['remittance'],
		'payment_means_code' => $means['code'] ?? '',
		'iban' => preg_replace('/\s+/', '', strtoupper((string) ($means['iban'] ?? ''))),
		'bic' => strtoupper((string) ($means['bic'] ?? '')),
		'account_name' => (string) ($means['account_name'] ?? ''),
		'syntax' => $inv['syntax'],
		'attachments' => count(array_filter($inv['attachments'], function ($a) {
			return $a['content'] !== '';
		})),
		'lines' => $lines,
	);
}

/**
 * Dolibarr payment type code (llx_c_paiement.code) for a UNTDID 4461 payment
 * means code (BT-81).
 *
 * @param string $code e.g. 58 (SEPA credit transfer)
 * @return string VIR, PRE, CB, LIQ, CHQ or '' if there is no sensible match
 */
function auvorechnungsimport_payment_means_to_dolibarr($code)
{
	$map = array(
		'30' => 'VIR', '31' => 'VIR', '42' => 'VIR', '58' => 'VIR',
		'49' => 'PRE', '59' => 'PRE',
		'48' => 'CB', '54' => 'CB', '55' => 'CB',
		'10' => 'LIQ',
		'20' => 'CHQ', '21' => 'CHQ',
	);
	return $map[trim((string) $code)] ?? '';
}

/**
 * Readable header details of an e-invoice that Dolibarr has no field for
 * (order/contract/buyer reference, service period, payment terms). They go
 * into the private note of the supplier invoice.
 *
 * @param array<string,mixed> $fields Review fields (auvorechnungsimport_einvoice_to_fields)
 * @param array<string,string> $labels Label per key: order, contract, buyer, period, terms, reference
 * @return string
 */
function auvorechnungsimport_header_note($fields, $labels)
{
	$out = array();
	foreach (array('order_reference' => 'order', 'contract_reference' => 'contract', 'buyer_reference' => 'buyer', 'payment_reference' => 'reference') as $key => $label) {
		if (!empty($fields[$key])) {
			$out[] = $labels[$label].': '.$fields[$key];
		}
	}
	$start = (string) ($fields['period_start'] ?? '');
	$end = (string) ($fields['period_end'] ?? '');
	if ($start !== '' || $end !== '') {
		$out[] = $labels['period'].': '.($start !== '' && $end !== '' && $start !== $end ? $start.' - '.$end : ($start !== '' ? $start : $end));
	}
	if (!empty($fields['payment_terms'])) {
		$out[] = $labels['terms'].': '.str_replace("\n", ' / ', trim($fields['payment_terms']));
	}
	return implode("\n", $out);
}

/**
 * Master data of the seller (BG-4) for creating a new supplier in Dolibarr.
 *
 * @param array<string,mixed> $seller Seller as parsed
 * @return array<string,string>
 */
function auvorechnungsimport_seller_details($seller)
{
	$a = $seller['address'] ?? array();
	$street = array_filter(array_map('trim', array((string) ($a['line1'] ?? ''), (string) ($a['line2'] ?? ''), (string) ($a['line3'] ?? ''))), 'strlen');
	$contact = $seller['contact'] ?? array();
	return array(
		'name' => trim((string) ($seller['name'] ?? '')),
		'address' => implode("\n", $street),
		'zip' => trim((string) ($a['postcode'] ?? '')),
		'town' => trim((string) ($a['city'] ?? '')),
		'country' => strtoupper(trim((string) ($a['country'] ?? ''))),
		'email' => trim((string) ($contact['email'] ?? '')),
		'phone' => trim((string) ($contact['phone'] ?? '')),
		'vat' => auvorechnungsimport_normalize_vat((string) ($seller['vat_id'] ?? '')),
		'tax_id' => trim((string) ($seller['tax_id'] ?? '')),
		'legal_id' => trim((string) ($seller['legal_id'] ?? '')),
	);
}

/**
 * Dolibarr unit code (llx_c_units.code) for a UN/ECE Recommendation 20 unit
 * (BT-130) - the reverse of the export's mapping in AuVo E-Rechnung.
 *
 * @param string $code e.g. HUR
 * @return string c_units code (e.g. H) or '' if Dolibarr has no such unit
 */
function auvorechnungsimport_unit_code_to_dolibarr($code)
{
	static $map = array(
		'TNE' => 'T', 'KGM' => 'KG', 'GRM' => 'G', 'MGM' => 'MG', 'ONZ' => 'OZ', 'LBR' => 'LB',
		'MTR' => 'M', 'DMT' => 'DM', 'CMT' => 'CM', 'MMT' => 'MM', 'FOT' => 'FT', 'INH' => 'IN',
		'MTK' => 'M2', 'DMK' => 'DM2', 'CMK' => 'CM2', 'MMK' => 'MM2', 'FTK' => 'FT2', 'INK' => 'IN2',
		'MTQ' => 'M3', 'DMQ' => 'DM3', 'CMQ' => 'CM3', 'MMQ' => 'MM3', 'FTQ' => 'FT3', 'INQ' => 'IN3',
		'OZA' => 'OZ3', 'LTR' => 'L', 'GLL' => 'GAL',
		'C62' => 'P', 'H87' => 'P', 'EA' => 'P', 'SET' => 'SET',
		'SEC' => 'S', 'MIN' => 'MI', 'HUR' => 'H', 'DAY' => 'D', 'WEE' => 'W', 'MON' => 'MO', 'ANN' => 'Y',
	);
	return $map[strtoupper(trim((string) $code))] ?? '';
}
