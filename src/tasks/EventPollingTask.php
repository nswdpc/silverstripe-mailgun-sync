<?php
namespace DPCNSW\SilverstripeMailgunSync;

/**
 * Task polls for 'failed OR rejected' events, this is for testing only
 */
class EventPollingTask extends \BuildTask
{
	protected $title = "Mailgun Event Polling Task";
	protected $description = 'Retrieves Mailgun Events, currently failed OR rejected';

	public function run($request) {
		$connector = new Connector\Event();
		$begin = Connector\Base::DateTime('now -4 week');
		$event = NULL;//"failed OR rejected";
		$response = $connector->pollEvents($begin, $event);
	}
	
}
