<?php

namespace NSWDPC\Messaging\Mailgun\ORM\FieldType;

use SilverStripe\ORM\FieldType\DBText;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Connect\MySQLSchemaManager;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\Connect\MySQLDatabase;

/**
 * Represents a variable-length string of up to 4GB
 * See: https://mariadb.com/kb/en/library/longtext/
 * If the current schema is not mysql based, use the Text requirement e.g SQLite3 TEXT (2GB)
 *
 * Example definition in YAML for increasing SavedJobData field length
 * <code>
 * QueuedJobDescriptor:
 *   db:
 *     SavedJobData : 'NSWDPC\Messaging\Mailgun\LongText'
 * </code>
 */
class DBLongText extends DBText
{
    /**
     * (non-PHPdoc)
     * @see DBField::requireField()
     * @note values is passed in as a string to differentiate from mediumtext spec and trigger an alter table
     */
    #[\Override]
    public function requireField()
    {
        $schema = DB::get_schema();
        if ($schema && $schema instanceof MySQLSchemaManager) {
            // modify requirement to longtest for MySQL / MariaDB
            $charset = Config::inst()->get(MySQLDatabase::class, 'charset');
            $collation = Config::inst()->get(MySQLDatabase::class, 'collation');
            $values = "longtext character set {$charset} collate {$collation}";
            DB::require_field($this->tableName, $this->name, $values);
        } else {
            // different manager e.g SQLite3 TEXT = 2^31 - 1 length
            parent::requireField();
        }
    }
}
