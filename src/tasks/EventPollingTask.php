<?php
namespace DPCNSW\SilverstripeMailgunSync;

/**
 * Task polls for 'failed' OR 'rejected' events and attempts to resubmit them, this is for testing only
 */
class EventPollingTask extends \BuildTask {
	
	protected $enabled = false;
	protected $title = "Mailgun Sync Event Polling Task (dev only)";
	protected $description = 'Retrieves & resubmits Mailgun Events, currently failed OR rejected';

	public function run($request) {
		
		if(!\Director::isDev()) {
			print "This task is for testing and can only be run in dev mode\n";
			exit(1);
		}
		
		try {
			$connector = new Connector\Event();
			$timeframe = 'now -1 day';
			$begin = Connector\Base::DateTime($timeframe);
			$event_filter = \MailgunEvent::FAILED . " OR " . \MailgunEvent::REJECTED;// query Mailgun for failed OR rejected events
			
			\SS_Log::log("pollEvents with filter '{$event_filter}'", \SS_Log::DEBUG);
			
			$resubmit = true;// true = attempt to resubmit
			$events = $connector->pollEvents($begin, $event_filter, $resubmit);
			
			print "Polled " . count($events) . " events matching {$event_filter}/{$timeframe}\n";
			exit;
			
		} catch (\Exception $e) {
			print "Failed with error:" . $e->getMessage() . "\n";
			exit(1);
		}
		
	}
	
}
