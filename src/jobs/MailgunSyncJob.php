<?php
namespace DPCNSW\SilverstripeMailgunSync;
/**
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 */
class FailedMessagesJob extends AbstractQueuedJob {
	
	// This is to run once every hour.
	private static $repeat_time = 3600;
	
	public function getTitle() {
		return "Mailgun Failed Messages Job";
	}

	/**
	 * @TODO: 1. create a job that downloads rejected/failed messages from Mailgun for the previous day
	 *				2. match MailgunSubmission using custom data contained in the response from Mailgun
	 *				3. does the submission have any failed events matching id/day ? if not, create a MailgunEvent record linked to the submission
	 *				4. failed event exposes a resubmit button to resubmit just this message (within 3 days)
	 *				5. Ensure that messages are not resubmitted if they have a "Delivered" status (may have been created in the meantime)
	 */
	public function process() {
	}
	
	/**
	 * @TODO create another job in 24 hours
	 */
	public function onAfterComplete() {
		
	}
	
}
