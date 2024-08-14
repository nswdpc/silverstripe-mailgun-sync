<?php

namespace NSWDPC\Messaging\Mailgun;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use SilverStripe\Core\Config\Config;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

/**
 * @author James
 * Job used to requeue SendJob descriptors marked broken
 * This is helpful if you have high mail traffic and have many broken {@link SendJob} records sitting in the queue
 */
class RequeueJob extends AbstractQueuedJob
{
    public function getTitle()
    {
        return _t(
            self::class . ".JOB_TITLE",
            "Re-queue failed attempts to send messages via the Mailgun API"
        );
    }

    /**
     * Attempt to send the message via the Mailgun API
     */
    public function process()
    {
        $descriptors = QueuedJobDescriptor::get()
            ->filter([
                'JobStatus' => QueuedJob::STATUS_BROKEN,
                'Implementation' => SendJob::class
            ]);
        $count = $descriptors->count();
        $kick = 0;
        $skip = 0;
        if ($count > 0) {
            $this->totalSteps = $count;
            foreach ($descriptors as $descriptor) {
                $data = @unserialize($descriptor->SavedJobData);
                if (empty($data->parameters)) {
                    // parameters cleared so pointless re-queuing
                    $skip++;
                    continue;
                }

                // recreate this job as new
                $next = new \Datetime();
                $next->modify('+1 minute');

                $descriptor->StartAfter = $next->format('Y-m-d H:i:s');
                $descriptor->JobStatus = QueuedJob::STATUS_NEW;
                $descriptor->StepsProcessed = 0;
                $descriptor->LastProcessedCount = -1;
                $descriptor->Worker = '';// clear otherwise job is considered locked
                $descriptor->write();

                $kick++;
                $this->currentStep++;
            }

            $this->addMessage(
                _t(
                    self::class . '.JOB_STATUS',
                    "Marked {kick}, ignored {skip} broken SendJob descriptors as new",
                    [
                        'kick' => $kick,
                        'skip' => $skip
                    ]
                ),
                "info"
            );
        } else {
            $this->addMessage(
                _t(
                    self::class . '.JOB_STATUS_NO_JOBS',
                    "No jobs can be re-queued"
                ),
                "info"
            );
        }

        $this->isComplete = true;
    }
}
