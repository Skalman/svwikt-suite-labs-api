CREATE TABLE page (
 page_title VARBINARY(255) NOT NULL,
 page_touched BINARY(14) NOT NULL,
 PRIMARY KEY (page_title)
) ENGINE=InnoDB DEFAULT CHARSET=binary;

CREATE TABLE template_use (
 use_id int unsigned NOT NULL AUTO_INCREMENT,
 page_title VARBINARY(255) NOT NULL,
 template VARBINARY(255) NOT NULL,
 is_dubious tinyint unsigned NOT NULL,
 PRIMARY KEY (use_id),
 FOREIGN KEY page_title (page_title)
  REFERENCES page(page_title)
  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=binary;

CREATE TABLE inflection (
 use_id int(10) unsigned NOT NULL,
 form VARBINARY(255) NOT NULL,
 type VARBINARY(31) NOT NULL,
 FOREIGN KEY use_id (use_id)
  REFERENCES template_use(use_id)
  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=binary;
