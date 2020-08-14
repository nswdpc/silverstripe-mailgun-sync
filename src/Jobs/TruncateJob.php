<?php
namespace NSWDPC\Messaging\Mailgun;

use Mailgun\Model\Message\SendResponse;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
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
class TruncateJob extends AbstractQueuedJob
{
    protected $totalSteps = 1;

    public function getJobType()
    {
        $this->totalSteps = 1;
        return QueuedJob::QUEUED;
    }

    public function getTitle()
    {
        return "Remove Mailgun event data > {$this->days}d old, repeat in {$this->recreate_in}s";
    }

    /**
     * @param float $days number of days to truncate back to
     * @param int $recreate_in the number of seconds in the future this job will start its next run
     */
    public function __construct($days = 1, $recreate_in = 86400)
    {
        $this->recreate_in = $recreate_in;
        $this->days = $days;
        if ($this->days < 0) {
            $this->days = 1;
        }
    }

    /**
     * Truncate events and submissions
     */
    public function process()
    {
        // Base date to check back for records in the past
        if($this->days > 0) {
            // allow for parts of days to the nearest hour
            $hours = round($this->days * 24);
            $dt = new DateTime("now -{$hours}hour");
        } else {
            $dt = new DateTime();
        }
        $dt_formatted = $dt->format('Y-m-d H:i:s');
        $this->addMessage("Removing events created before {$dt_formatted}", "info");
        $events = MailgunEvent::get()->filter('Created:LessThan', $dt_formatted);
        $count = $events->count();
        if($count > 0) {
            $events->removeAll();
            $this->addMessage("Removed {$count} events", "info");
        } else {
            $this->addMessage("No events to remove", "info");
        }
        $this->currentStep = 1;
        $this->isComplete = true;
    }

    /**
     * Create another job in 24hrs
     */
    public function afterComplete()
    {
        $next = new DateTime();
        $next->modify('+' . $this->recreate_in . ' seconds');
        $job = new TruncateJob($this->days, $this->recreate_in);
        $service = singleton(QueuedJobService::class);
        $descriptor_id = $service->queueJob($job, $next->format('Y-m-d H:i:s'));
        if (!$descriptor_id) {
            Log::log("Failed to queue new TruncateJob!", \Psr\Log\LogLevel::WARNING);
        }
    }
}
