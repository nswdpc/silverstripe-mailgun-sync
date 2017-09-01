<?php
namespace NSWDPC\SilverstripeMailgunSync\Connector;
use Mailgun\Mailgun;
/**
 * Base connector to the Mailgun API
 * Read the Docs at http://mailgun-documentation.readthedocs.io/en/latest/api_reference.html for reference implementations
 */
abstract class Base {
	
	private static $api_key = '';
	private static $api_domain = '';
	private static $api_testmode = false;// when true ALL emails are sent with o:testmode = 'yes'
	private static $sync_local_mime = false;// download messages when failed
	private static $resubmit_failures = 2;// number of resubmit failures before the message is stored (when sync_local_mime is true)
	private static $track_userform = false;// track userform submissions (userform module support)
	
	private static $workaround_testmode = false;// this works around an oddity where testmode is 'yes', the recipient is in the supression list but messages are 'delivered' in testmode
	
	/**
	 * Returns an RFC2822 datetime in the format accepted by Mailgun
	 * @param string $relative a strtotime compatible format e.g 'now -4 weeks'
	 */
	public static function DateTime($relative) {
		if($relative) {
			return gmdate('r', strtotime($relative));
		} else {
			return gmdate('r');
		}
	}
	
	public function getClient($api_key = null) {
		if(!$api_key) {
			$api_key = $this->getApiKey();
		}
		$client = Mailgun::create( $api_key );
		return $client;
	}
	
	public function getApiKey() {
		$mailgun_api_key = \Config::inst()->get(__CLASS__,'api_key');
		return $mailgun_api_key;
	}

	public function getApiDomain() {
		$mailgun_api_domain = \Config::inst()->get(__CLASS__,'api_domain');
		return $mailgun_api_domain;
	}
	
	/**
	 * Does config state the module should track userform submissions?
	 * {@link NSWDPC\SilverstripeMailgunSync\UserDefinedFormSubmissionExtension::updateEmail()}
	 */
	public static function trackUserFormSubmissions() {
		return \Config::inst()->get(__CLASS__,'track_userform');
	}
	
	/**
	 * Returns whether or not syncing remote message to a local file is allowed in config
	 */
	final protected function syncLocalMime() {
		return \Config::inst()->get(__CLASS__,'sync_local_mime');
	}
	
	/**
	 * Returns configured number of resubmit failures, before the MIME message is downloaded (if configured)
	 */
	final protected function resubmitFailures() {
		return \Config::inst()->get(__CLASS__,'resubmit_failures');
	}
	
	/**
	 * Returns whether testmode workaround is on.
	 * When true this worksaround a quirk in Mailgun where sending messages with testmode on to recipients in the supression list are 'delivered' (should be 'failed')
	 */
	final protected function workaroundTestMode() {
		return \Config::inst()->get(__CLASS__,'workaround_testmode');
	}
	
	/**
	 * When true, the Sender header is always set to the From value. When false, use {@link NSWDPC\SilverstripeMailgunSync\Mailer::setSender()} to set the Sender header as required
	 */
	final protected function alwaysSetSender() {
		return \Config::inst()->get(__CLASS__,'always_set_sender');
	}
	
	/**
	 * Prior to any send/sendMime action, check config and set testmode if config says so
	 */
	final protected function applyTestMode(&$parameters) {
		$mailgun_testmode = \Config::inst()->get(__CLASS__,'api_testmode');
		if($mailgun_testmode) {
			$parameters['o:testmode'] = 'yes';
		}
	}
	
}
