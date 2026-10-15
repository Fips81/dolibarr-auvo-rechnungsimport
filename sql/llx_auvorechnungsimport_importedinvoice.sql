CREATE TABLE llx_auvorechnungsimport_importedinvoice (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	ref varchar(128) NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer,
	status integer DEFAULT 0 NOT NULL,
	original_filename varchar(255) NOT NULL,
	filepath varchar(255) NOT NULL,
	extraction_method varchar(32),
	extracted_data text,
	fk_soc integer,
	supplier_match_confidence varchar(32),
	fk_facture_fourn integer,
	note_private text
) ENGINE=innodb;
