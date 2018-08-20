<?php
namespace NSWDPC\SilverstripeMailgunSync;

/**
 * Ultra basic logging for tests
 * @todo plug into logger
 */
class TestLog {

  const ERR = 'ERR';
	const WARN = 'WRN';
	const NOTICE = 'NOT';
	const INFO = 'INF';
	const DEBUG = 'DBG';

  public static function log($line, $level = self::DEBUG) {
    print $level . "\t" . $line . "\n";
  }

}
