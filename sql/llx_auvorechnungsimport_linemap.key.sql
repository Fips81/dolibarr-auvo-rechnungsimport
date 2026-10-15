ALTER TABLE llx_auvorechnungsimport_linemap ADD UNIQUE INDEX uk_auvorechnungsimport_linemap (entity, fk_soc, key_type, match_key);
ALTER TABLE llx_auvorechnungsimport_linemap ADD INDEX idx_auvorechnungsimport_linemap_fk_product (fk_product);
