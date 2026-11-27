-- The module loader accepts the duplicate-column error on later activations.
ALTER TABLE llx_auvorechnungsimport_importedinvoice ADD COLUMN source varchar(24) DEFAULT 'unknown' NOT NULL;
