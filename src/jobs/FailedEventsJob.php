<?php
namespace DPCNSW\SilverstripeMailgunSync;
/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * This Job runs once per day and polls for events matching the given filter. It will attempt to resubmit the events if possible (based on config).
 * Mailgun stores messages for 3 days maximum, after that time there is a higher likelihood that the message will no longer exist at Mailgun
 */
class FailedEventsJob extends AbstractQueuedJob {
	
	// This is to run once every day.
	private static $repeat_time = 86400;
	
	/**
	 * Repeat at 11am
	 */
	private static $time_of_day = '11:00:00';
	
	public function getJobType() {
		return QueuedJob::QUEUED;
	}
	
	public function getTitle() {
		return "Mailgun Failed Events Job";
	}
	
	/**
	 * getNextStartDateTime - based on configured time for job and the current datetime, set the datetime for the next job
	 */
	public static function getNextStartDateTime() {
		$time = $this->config()->time_of_day;
		$now = new DateTime();
		$next = new DateTime();
		$next->setTime( $time );
		
		// if we are currently after the next datetime, set it to tomorrow at the configured time
		// else, we are before, use current day at $time
		if($now > $next) {
			$next->modify('+1 day');
		}
		
		return $next;
		
	}

	/**
	 * @todo some specific Exceptions?
	 */
	public function process() {
		try {
			// poll for events
			$connector = new Connector\Event();
			$begin = Connector\Base::DateTime('now -1 day');// events created within the last day
			//$event_filter = "failed OR rejected";
			$event_filter = "failed";
			$resubmit = true;
			$events = $connector->pollEvents($begin, $event_filter, $resubmit);
			
		} catch (\Exception $e) {
			// failed somewhere along the line
		}
	}
	
	/**
	 * Create another job in 24hrs
	 */
	public function onAfterComplete() {
		$next = self::getNextStartDateTime();
		$job = new FailedEventsJob(); 
		$service = singleton('QueuedJobService');
		$descriptor_id = $service->queueJob($job, $next->format('Y-m-d H:i:s'));
		if($descriptor_id) {
			SS_Log::log("Queued new FailedEventsJob #{$descriptor_id}", SS_Log::DEBUG);
		} else {
			SS_Log::log("Failed to queue new FailedEventsJob!", SS_Log::WARN);
		}
	}
	
}
