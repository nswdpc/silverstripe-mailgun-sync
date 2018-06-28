<?php
namespace NSWDPC\SilverstripeMailgunSync;
use SilverStripe\Dev\BuildTask;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class QueueMailgunSyncJobs extends BuildTask {

	protected $title = "Mailgun sync task queuer";
	protected $description = 'Queue up the Mailgun tasks';

	public function run($request) {
		$start_after = FailedEventsJob::getNextStartDateTime();//DateTime
		$start_after_formatted = $start_after->format('Y-m-d H:i:s');
		$service = singleton(QueuedJobService::class);
		$service->queueJob(new FailedEventsJob(), $start_after_formatted);
		print "Queued up the FailedEventsJob - {$start_after_formatted}\n";

		$start_after = DeliveryCheckJob::getNextStartDateTime();//DateTime
		$start_after_formatted = $start_after->format('Y-m-d H:i:s');
		$service = singleton(QueuedJobService::class);
		$service->queueJob(new DeliveryCheckJob(), $start_after_formatted);
		print "Queued up the DeliveryCheckJob - {$start_after_formatted}\n";

	}
}
