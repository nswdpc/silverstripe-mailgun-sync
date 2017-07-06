<?php
namespace DPCNSW\SilverstripeMailgunSync;
use Kinglozzer\SilverStripeMailgunner\Mailer as MailgunnerMailer;

class Mailer extends MailgunnerMailer {
	
	protected $submission_source = "";
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
			$builder->addCustomData('s', $this->submission_source);
		}
		
	}
}
