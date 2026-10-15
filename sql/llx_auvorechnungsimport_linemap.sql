CREATE TABLE llx_auvorechnungsimport_linemap (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_soc integer NOT NULL,
	key_type varchar(16) NOT NULL,
	match_key varchar(255) NOT NULL,
	fk_product integer NOT NULL,
	nb_used integer DEFAULT 1 NOT NULL,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL
) ENGINE=innodb;
