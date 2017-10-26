<?php
namespace NSWDPC\SilverstripeMailgunSync;
/**
 * Represents a variable-length string of up to 4GB
 * See: https://mariadb.com/kb/en/library/longtext/
 * If the current schema is not mysql based, use the Text requirement e.g SQLite3 TEXT (2GB)
 *
 * Example definition in YAML for increasing SavedJobData field length
 * <code>
 * QueuedJobDescriptor:
 *   db:
 *     SavedJobData : 'NSWDPC\SilverstripeMailgunSync\LongText'
 * </code>
 */
class LongText extends \Text {
	/**
	 * (non-PHPdoc)
	 * @see DBField::requireField()
	 * @note values is passed in as a string to differentiate from mediumtext spec and trigger an alter table
	 */
	public function requireField() {
		$schema = \DB::get_schema();
		if($schema && $schema instanceof \MySQLSchemaManager) {
			// modify requirement to longtest for MySQL / MariaDB
			$values = "longtext character set utf8 collate utf8_general_ci";
			\DB::require_field($this->tableName, $this->name, $values, $this->default);
		} else {
			// different manager e.g SQLite3 TEXT = 2^31 - 1 length
			parent::requireField();
		}
	}
}
