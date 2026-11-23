# Changelog

Notable changes to AuVo Rechnungsimport are documented here.

## Unreleased

- Process queued imports and send overdue-import reminders through Dolibarr's
  Cron module. Reminder count and last-send time are stored on each import.
- Add Email Collector integration with configurable header-based spam review,
  explicit spam confirmation, and an Irrelevant view for confirmed spam and
  duplicates.
- Record received invoice files and confirmation events in BlockedLog when
  enabled, including a Base64 copy and SHA-256 hash of the original file.
- Validate invoice totals before creating supplier invoices and require a
  manual review of detected supplier data and line handling.
- Block accidental self-supplier creation by comparing the detected supplier
  with the configured company name and user-configured company aliases.
- Protect supplier invoices recorded in BlockedLog from normal editing,
  unvalidation, line changes, and deletion.
- Add source, reminder-count, and last-reminder columns to the import list and
  allow users to choose which columns they see.
- Reject XML documents containing DTD or entity declarations.
- Hash incoming PDF files and automatically classify exact file duplicates,
  linking to the earlier import and, when available, its booked supplier invoice.
- When ECM is enabled, use its MD5 content index to locate candidate files and
  confirm matches with SHA-256 and the stored file before classifying duplicates.

### Upgrade notes

Reinitialize the module after updating so Dolibarr can add the reminder columns
to an existing import table. Existing installations must also have the `source`
column; automatic migration for that column is not included in this version.

## 0.3.0 (October 2026)

- First public version under the AuVo Rechnungsimport name, with data migration
  from the earlier InvoiceImport module.
- Read XRechnung, Peppol BIS, and ZUGFeRD/Factur-X XML in CII and UBL syntax.
- Show a readable view of structured e-invoices and expose their attachments.
- Match suppliers using VAT IDs and tax IDs; support PDF text extraction, OCR,
  line-item mapping, and product variants.

## 0.2.x and earlier

Internal InvoiceImport releases with Factur-X/ZUGFeRD, PDF text extraction and
OCR, line-item recognition, product mapping, and background analysis.
