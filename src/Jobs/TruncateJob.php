<?php
namespace NSWDPC\SilverstripeMailgunSync;
use Mailgun\Model\Message\SendResponse;
use NSWDPC\SilverstripeMailgunSync\Connector\Message as MessageConnector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use SilverStripe\Core\Config\Config;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use DateTime;
use DateTimeZone;
use Exception;


/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * Truncate Job can run once per day and by default removes events/submissions > 90 (default) days old
 */
class TruncateJob extends AbstractQueuedJob {

	protected $totalSteps = 1;

	public function getJobType() {
		$this->totalSteps = 1;
		return QueuedJob::QUEUED;
	}

	public function getTitle() {
		return "Remove Mailgun data > {$this->days} days old";
	}

	/**
	 * @param int $days number of days to truncate up to
	 */
	public function __construct($days = 90) {
		$min = 30;
		$this->days = (int)$days;
		if($this->days < $min) {
			$this->days = $min;
		}
	}

	/**
	 * Truncate events and submissions
	 */
	public function process() {

		// date
		$dt = new DateTime('now -' . $this->days . ' day');
		$dt_formatted = $dt->format('Y-m-d H:i:s');

		$counts = [
			'e' => 0,
			'f' => 0,
			's' => 0,
		];

		// truncate events
		$events = MailgunEvent::get()->filter('Created:LessThan', $dt_formatted);
		foreach($events as $event) {
			try {
				$message = $event->MimeMessage();
				if(!empty($message->ID)) {
					$message->delete();
					$counts['f']++;
				}
			} catch (Exception $e) {
				Log::log("MailgunEvent/Message #{$message->ID} failed to delete", 'NOTICE');
			}

			try {
				$submission = $event->Submission();
				if(!empty($submission->ID)) {
					$submission->delete();
					$counts['s']++;
				}
			} catch (Exception $e) {
				Log::log("MailgunEvent/Submission #{$submission->ID} failed to delete", 'NOTICE');
			}

			try {
				$event->delete();
				$counts['e']++;
			} catch (Exception $e) {
				Log::log("MailgunEvent #{$event->ID} failed to delete", 'NOTICE');
			}
		}

		// remove any orphaned submissions
		$submissions = MailgunSubmission::get()->filter('Created:LessThan', $dt_formatted);
		foreach($submissions as $submission) {
			try {
				$submission->delete();
				$counts['s']++;
			} catch (Exception $e) {
				Log::log("MailgunSubmission #{$submission->ID} failed to delete", 'NOTICE');
			}
		}

		$this->addMessage("Removed {$counts['e']} events, {$counts['f']} files, {$counts['s']} submissions", "info");
		$this->isComplete = true;
		return;

	}

	/**
	 * Create another job in 24hrs
	 */
	public function afterComplete() {
		$next = new DateTime();
		$next->modify('+1 day');

		$job = new TruncateJob($this->days);
		$service = singleton(QueuedJobService::class);
		$descriptor_id = $service->queueJob($job, $next->format('Y-m-d H:i:s'));
		if(!$descriptor_id) {
			Log::log("Failed to queue new TruncateJob!", 'WARNING');
		}
	}

}
