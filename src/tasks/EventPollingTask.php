<?php
namespace NSWDPC\SilverstripeMailgunSync;

/**
 * Task polls for 'failed' OR 'rejected' events but does not resubmit them, this is for testing only
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
			$event_filter = "";//poll all events
			//\SS_Log::log("pollEvents with filter '{$event_filter}'", \SS_Log::DEBUG);
			$extra_params = [
				'limit' => 100
			];
			
			$resubmit = false;// true = attempt to resubmit if 'failed' OR ' rejected' status
			$events = $connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);
			
			if(!empty($events)) {
				print "Polled " . count($events) . " events matching {$event_filter}/{$begin}\n";
				foreach($events as $event) {
					print "Message: {$event->MessageId}\n";
					print "\tEvent: {$event->ID} / {$event->EventType} / {$event->Recipient}\n";
					print "\n";
				}
			} else {
				print "No events found\n";
			}
			exit;
			
		} catch (\Exception $e) {
			print "Failed with error:" . $e->getMessage() . "\n";
			exit(1);
		}
		
	}
	
}
