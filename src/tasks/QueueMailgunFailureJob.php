<?php
namespace DPCNSW\SilverstripeMailgunSync;

class QueueMailgunFailureJob extends BuildTask {
	protected $description = 'Queue up the Mailgun FailedEventsJob';

	public function run($request) {
		$start_after = FailedEventsJob::getNextStartDateTime();
		$service = singleton('QueuedJobService');
		$service->queueJob(new FailedEventsJob(), $start_after);
		print "Queued up the FailedEventsJob - {$start_after}\n";
	}
}
