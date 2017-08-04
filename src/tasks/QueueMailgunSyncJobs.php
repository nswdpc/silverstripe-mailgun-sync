<?php
namespace DPCNSW\SilverstripeMailgunSync;

class QueueMailgunSyncJobs extends BuildTask {
	protected $description = 'Queue up the Mailgun FailedEventsJob';

	public function run($request) {
		$start_after = FailedEventsJob::getNextStartDateTime();
		$service = singleton('QueuedJobService');
		$service->queueJob(new FailedEventsJob(), $start_after);
		print "Queued up the FailedEventsJob - {$start_after}\n";
		
		$start_after = DeliveryCheckJob::getNextStartDateTime();
		$service = singleton('QueuedJobService');
		$service->queueJob(new DeliveryCheckJob(), $start_after);
		print "Queued up the DeliveryCheckJob - {$start_after}\n";
	}
}
