<?php
namespace DPCNSW\SilverstripeMailgunSync\Connector;
use Mailgun\Mailgun;
/**
 * Base connector to the Mailgun API
 * Read the Docs at http://mailgun-documentation.readthedocs.io/en/latest/api_reference.html for reference implementations
 */
abstract class Base {
	
	private static $api_version = 'v3';
	
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
	
	protected function getClient($api_key) {
		$client = new Mailgun( $api_key );
		return $client;
	}
	
	// TODO get key/domain from outside mailgunner ?
	protected function getApiKey() {
		$mailgunner_api_key = \Config::inst()->get('Kinglozzer\SilverStripeMailgunner\Mailer','api_key');
		return $mailgunner_api_key;
	}

	protected function getApiDomain() {
		$mailgunner_api_domain = \Config::inst()->get('Kinglozzer\SilverStripeMailgunner\Mailer','api_domain');
		return $mailgunner_api_domain;
	}
	
	/**
	 * @todo use mailgunner config as an option
	 */
	protected function getApiVersion() {
		$api_version = \Config::inst()->get('DPCNSW\SilverstripeMailgunSync\ApiClient','api_version');
		return $api_version;
	}
	
}
