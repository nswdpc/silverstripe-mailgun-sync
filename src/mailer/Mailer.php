<?php
namespace DPCNSW\SilverstripeMailgunSync;
use Kinglozzer\SilverStripeMailgunner\Mailer as MailgunnerMailer;
use Mailgun\Messages\MessageBuilder;

class Mailer extends MailgunnerMailer {
	
	protected $submission_source = "";
	
	/**
	 * @param int $source_id a \MailgunSubmission.ID
	 */
	public function setSubmissionSource($source_id) {
		$this->submission_source = $source_id;
	}
	
	protected function buildMessage(
			MessageBuilder $builder,
			$to,
			$from,
			$subject,
			$content,
			$plainContent,
			array $attachments,
			array $headers
	) {
		
		parent::buildMessage(
			$builder,
			$to,
			$from,
			$subject,
			$content,
			$plainContent,
			$attachments,
			$headers
		);
		
		// When a submission source is present, set custom data
		if($this->submission_source) {
			\SS_Log::log("buildMessage: adding submission_source - {$this->submission_source}", \SS_Log::DEBUG);
			$builder->addCustomData('s', $this->submission_source);
		}
		
	}
}
