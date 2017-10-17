<?php
namespace NSWDPC\SilverstripeMailgunSync;
/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * This Job runs once per day and polls for events matching the given filter. It will attempt to resubmit the events if possible (based on config).
 * Mailgun stores messages for 3 days maximum, after that time there is a higher likelihood that the message will no longer exist at Mailgun
 */
class FailedEventsJob extends \AbstractQueuedJob {
	
	// This is to run once every day.
	private static $repeat_time = 86400;
	
	/**
	 * Default repeat at 11am
	 */
	private static $time_of_day = ['hour' => 11, 'minute' => 0, 'second' => 0 ];
	
	public function getJobType() {
		return \QueuedJob::QUEUED;
	}
	
	public function getTitle() {
		return "Mailgun Failed Events Job";
	}
	
	/**
	 * getNextStartDateTime - based on configured time for job and the current datetime, set the datetime for the next job
	 * @returns DateTime
	 */
	public static function getNextStartDateTime() {
		$time = \Config::inst()->get(__CLASS__, 'time_of_day');
		$now = new \DateTime();
		$next = new \DateTime();
		$next->setTime(
						(isset($time['hour']) ? $time['hour'] : 0),
						(isset($time['minute']) ? $time['minute'] : 0),
						(isset($time['second']) ? $time['second'] : 0)
		);
		
		// if we are currently after the next datetime, set it to tomorrow at the configured time
		// else, we are before, use current day at $time
		if($now > $next) {
			$next->modify('+1 day');
		}
		
		return $next;
		
	}
	
	/**
	 * polls for 'failed' events in the last day and tries to resubmit them
	 */
	public function process() {
		try {
			// poll for events
			$connector = new Connector\Event();
			$begin = Connector\Base::DateTime('now -25 hours');// allow for an overlap
			$event_filter = \MailgunEvent::FAILED . " OR " . \MailgunEvent::REJECTED;// query Mailgun for failed OR rejected events
			$resubmit = true;
			$extra_params = [
				'limit' => 25 // per page
			];
			// poll for failed events using these filters
			//\SS_Log::log("FailedEventsJob::process - polling from {$begin} onwards", \SS_Log::DEBUG);
			$events = $connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);
			//\SS_Log::log("FailedEventsJob::processing done - " . count($events) . " events polled", \SS_Log::DEBUG);
			$this->isComplete = true;
			return $events;
		} catch (\Exception $e) {
			// failed somewhere along the line
			\SS_Log::log("Caught an Exception in FailedEventsJob::process() - " . $e->getMessage(), \SS_Log::NOTICE);
		}
		$this->isComplete = true;
		return false;
	}
	
	/**
	 * Create another job in 24hrs
	 */
	public function afterComplete() {
		$next = self::getNextStartDateTime();
		$job = new FailedEventsJob(); 
		$service = singleton('QueuedJobService');
		$descriptor_id = $service->queueJob($job, $next->format('Y-m-d H:i:s'));
		if(!$descriptor_id) {
			\SS_Log::log("Failed to queue new FailedEventsJob!", \SS_Log::WARN);
		}
	}
	
}
