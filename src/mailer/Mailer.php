<?php
namespace NSWDPC\SilverstripeMailgunSync;
use Mailgun\Model\Message\SendResponse;
use Mailer as SilverstripeMailer;
use Mailgun\Mailgun;

/**
 * This Mailer class is based on Kinglozzer\SilverStripeMailgunner\Mailer and adds
 * 		sendMessage() using v3.0 compatible API methods
 *		prepareAttachments() - avoids writing file attachments to a temp file as {@link Email::attachFileFromString()} already provides the file bytes
 * 		Calling MessageBuilder() and BatchMessage(), which are both deprecated as of 3.0
 *		Removal of BatchMessage in general
 */
class Mailer extends SilverstripeMailer {
	
	protected $submission;// \MailgunSubmission
	protected $is_test_mode = false;// when true, Mailgun receives messages (accepted event) but does not send them to the remote. 'delivered' events are recorded.
	protected $tags = [];//Note 4000 limit: http://mailgun-documentation.readthedocs.io/en/latest/user_manual.html#tagging

	/**
	 * {@inheritdoc}
	 */
	public function sendPlain($to, $from, $subject, $plainContent, $attachments = [], $headers = []) {
			return $this->sendMessage($to, $from, $subject, $htmlContent = '', $plainContent, $attachments, $headers);
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendHTML($to, $from, $subject, $htmlContent, $attachments = [], $headers = [], $plainContent = '') {
			return $this->sendMessage($to, $from, $subject, $htmlContent, $plainContent, $attachments, $headers);
	}
	
	/**
	 * Set the submission source on the 
	 */
	public function setSubmissionSource(\MailgunSubmission $submission) {
		$this->submission = $submission;
	}
	
	public function setIsTestMode($is) {
		$this->is_test_mode = $is;
	}
	
	public function setTags($tags) {
		$this->tags = $tags;
	}
	
	
	/**
	 * Send a message using the Mailgun messages API. If there is a submission source, save the resulting message.id on successful response
	 * @TODO once a message is sent, clear tags and submission? Leave test mode as-is?
	 * @param string $to
	 * @param string $from
	 * @param string $subject
	 * @param string $content
	 * @param string $plainContent
	 * @param array $attachments
	 * @param array $headers
	 */
	protected function sendMessage($to, $from, $subject, $content, $plainContent, $attachments, $headers) {
			try {
				
				$connector = new Connector\Message();
				$attachments = $this->prepareAttachments($attachments);
				
				$parameters = [];
				// add in o: and v: params
				$this->addCustomParameters($parameters, $headers);
			
				// these generic headers override anything passed in as a header
				$parameters = array_merge($parameters, [
					'from' => $from, 
					'to' => $to,
					'subject' => $subject, 
					'text' => $plainContent,
					'html' => $content
				]);
				
				// if Cc and Bcc have been provided
				if(isset($headers['Cc'])) {
					$parameters['cc'] = $headers['Cc'];
				}
				if(isset($headers['Bcc'])) {
					$parameters['bcc'] = $headers['Bcc'];
				}
				
				// Provide Mailgun the Attachments. Keys are 'fileContent' (the bytes) and filename (the file name)
				// If the key filename is not provided, Mailgun will use the name of the file, which may not be what you want displayed
				if(!empty($attachments) && is_array($attachments)) {
					$parameters['attachment'] = $attachments;
				}
				
				\SS_Log::log('Sending...', \SS_Log::DEBUG);
				$response = $connector->send($parameters);
				$message_id = "";
				if($response && $response instanceof SendResponse) {
					// save message.id to the submission
					$message_id = $this->saveResponse($response);
				}
				
			} catch (\Exception $e) {
				// Throwing the exception would break SilverStripe's Email API expectations, so we log
				// errors and show a message (which is hidden in live mode)
				\SS_Log::log('Mailgun-Sync / Mailgun error: ' . $e->getMessage(), \SS_Log::ERR);
				return false;
			}

			// Return format matching {@link Mailer::sendPreparedMessage()}
			// TODO would be nice to get message_id back to email->send()
			return [$to, $subject, $content, $headers, ''];
	}
	
	/**
	 * @note refer to {@link Mailgun\Api\Message::prepareFile()} which is the preferred way of attaching messages from 3.0 onwards as {@link Mailgun\Connection\RestClient} is deprecated
	 * This overrides writing to temp files as Silverstripe {@link Email::attachFileFromString()} already provides the attachments in the following way:
	 *		 'contents' => $data,
	 *		 'filename' => $filename,
	 *		 'mimetype' => $mimetype,
	 */
	protected function prepareAttachments(array $attachments) {
		foreach ($attachments as $k => $attachment) {
			// ensure the content of the attachment is in the key that Mailgun\Api\Message::prepareFile() can handle
			$attachments[$k]['fileContent'] = $attachment['contents'];
			unset($attachments[$k]['contents']);
		}
		return $attachments;
	}
	
	/**
	 * Has a \MailgunSubmission been attached to this Mailer instance?
	 */
	protected function hasSubmission() {
		return ($this->submission instanceof \MailgunSubmission) && !empty($this->submission->ID);
	}
	
	
	/*
		object(Mailgun\Model\Message\SendResponse)[1740]
			private 'id' => string '<message-id.mailgun.org>' (length=92)
			private 'message' => string 'Queued. Thank you.' (length=18)
	*/
	final protected function saveResponse($message) {
		$message_id = $message->getId();
		$message_id = trim($message_id, "<>");// < > surrounding characters seem to affect the message-id usage in event filters
		if($this->hasSubmission()) {
			\SS_Log::log('Saving messageId: ' . $message_id  . " to submission {$this->submission->ID}", \SS_Log::DEBUG);
			$this->submission->MessageId = $message_id;// for submissions with multiple recipients this will be the last message_id returned
			$this->submission->write();
		}
		return $message_id;
	}
	
	/**
	 * TODO parse out any X- prefixed headers  and add them as h:X-My-Header
	 * TODO support all o:options in Mailgun API
	 */
	protected function addCustomParameters(&$parameters, $headers) {
		// When a submission source is present, set custom data
		if($this->hasSubmission()) {
			\SS_Log::log("addCustomParameters: adding submission - {$this->submission->ID}", \SS_Log::DEBUG);
			$parameters['v:s'] = $this->submission->ID;// adds to X-Mailgun-Variables header e.g {"s": "77"}
		}
		
		// setting test mode on/off
		if($this->is_test_mode) {
			\SS_Log::log("addCustomParameters: is test mode", \SS_Log::DEBUG);
			$parameters['o:testmode'] = 'yes';//Adds X-Mailgun-Drop-Message header
		}
		
		$is_running_test = \SapphireTest::is_running_test();
		$workaround_testmode = \Config::inst()->get('NSWDPC\SilverstripeMailgunSync\Connector\Base', 'workaround_testmode');
		if($is_running_test && $workaround_testmode) {
			\SS_Log::log("addCustomParameters: workaround_testmode is ON - this unsets o:testmode while running tests", \SS_Log::DEBUG);
			unset($parameters['o:testmode']);
		}
		
		// if tags are provided, add them
		// tags can be filtered on when polling for events
		if(!empty($this->tags) && is_array($this->tags)) {
			$parameters['o:tag'] = array_values($this->tags);
		}
	}
}
