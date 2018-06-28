<?php
namespace NSWDPC\SilverstripeMailgunSync;
use Mailgun\Model\Message\SendResponse;
use NSWDPC\SilverstripeMailgunSync\Connector\Message as MessageConnector;
use SilverStripe\Control\Email\Mailer as SilverstripeMailer;
use SilverStripe\Control\Email\Email;
use Mailgun\Mailgun;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Config\Config;

/**
 * This Mailer class is based on Kinglozzer\SilverStripeMailgunner\Mailer and adds
 * 		sendMessage() using v3.0 compatible API methods
 *		prepareAttachments() - avoids writing file attachments to a temp file as {@link Email::attachFileFromString()} already provides the file bytes
 * 		Remove calling MessageBuilder() and BatchMessage(), which are both deprecated as of 3.0
 *		Removal of BatchMessage in general
 *
 * HEADERS
 * Some specific headers can be set on the Email instance and are handled in addCustomParameters()
 * These headers are unset on use and not sent to Mailgun
 * X-MSE-O:TAGS:
 *		Sets o:tags parameter
 *		JSON encoded string of tags to send with the email, only the array values are sent to Mailgun
 * 		Note 4000 limit: http://mailgun-documentation.readthedocs.io/en/latest/user_manual.html#tagging
 * X-MSE-TEST:
 *		Sets o:testmode parameter
 * 		When true, Mailgun receives messages (accepted event) but does not send them to the remote. 'delivered' events are recorded.
 * X-MSE-SID:
 *		Sets v:s parameter
 * 		Provide a MailgunSubmission.ID value for inclusion in the message headers
 * X-MSE-IN:
 * 		When the email is sent via a queued job, this value will set the StartAfter datetime for the Queued Job, if not set 'now +1 minute' is used
 *
 * Mailgun Message-ID return value
 * A header 'X-Mailgun-MessageID' will be returned by sendMessage() in the 'headers' index.
 * This value may be empty (if the response was invalid or the message-id was not returned)
 */
class Mailer implements SilverstripeMailer {

	public $alwaysFrom;// when set, override From address, applying From provided to Reply-To header, set original "From" as "Sender" header

	public function send($email) {}

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
	 * These four methods are retained for BC but are now unused
	 * Set the relevant headers on the Email instance instead
	 */
	public function setSubmissionSource(MailgunSubmission $submission) {}
	public function setIsTestMode($is) {}
	public function setTags($tags) {}
	public function setSender($email, $name = "") {}

	/**
	 * Send a message using the Mailgun messages API. If there is a submission source, save the resulting message.id on successful response
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

				// Store a value for sending in the future
				$in = '';
				if(isset($headers['X-MSE-IN'])) {
					$in = $headers['X-MSE-IN'];
					unset($headers['X-MSE-IN']);
				}

				// check if alwaysFrom is set
				if($this->alwaysFrom) {
					$parameters['h:Reply-To'] = $from;// set the from as a replyto
					$from = $this->alwaysFrom;
					$headers['Sender'] = $from;// set in addCustomParameters below
				}

				// add in o: and v: params
				$this->addCustomParameters($parameters, $headers);

				// ensure text/plain part is set
				if(!$plainContent) {
					$plainContent = Convert::xml2raw($content);
				}

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
					unset($parameters['h:Cc']);//avoid double Cc header
				}
				if(isset($headers['Bcc'])) {
					$parameters['bcc'] = $headers['Bcc'];
					unset($parameters['h:Bcc']);//avoid sending double Bcc header
				}

				// Provide Mailgun the Attachments. Keys are 'fileContent' (the bytes) and filename (the file name)
				// If the key filename is not provided, Mailgun will use the name of the file, which may not be what you want displayed
				if(!empty($attachments) && is_array($attachments)) {
					$parameters['attachment'] = $attachments;
				}

				//\SS_Log::log('Sending...', \SS_Log::DEBUG);
				$response = $connector->send($parameters, $in);
				$message_id = "";
				if($response && $response instanceof SendResponse) {
					// get a message.id from the response
					$message_id = $this->saveResponse($response);
				}
				// provide a message-id value that can be picked up from the result of email->send()
				$headers['X-Mailgun-MessageID'] = $message_id;

			} catch (Exception $e) {
				\SS_Log::log('Mailgun-Sync / Mailgun error: ' . $e->getMessage(), \SS_Log::ERR);
				return false;
			}

			// Return format matching {@link Mailer::sendPreparedMessage()}
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

	/*
		object(Mailgun\Model\Message\SendResponse)[1740]
			private 'id' => string '<message-id.mailgun.org>' (length=92)
			private 'message' => string 'Queued. Thank you.' (length=18)
	*/
	final protected function saveResponse($message) {
		$message_id = $message->getId();
		$message_id = MessageConnector::cleanMessageId($message_id);
		return $message_id;
	}

	/**
	 * TODO support all o:options in Mailgun API
	 */
	protected function addCustomParameters(&$parameters, $headers) {

		// When a submission source is present, set custom data
		$submission_id = isset($headers['X-MSE-SID']) ? $headers['X-MSE-SID'] : false;
		unset($headers['X-MSE-SID']);//no longer required
		if($submission_id) {
			$parameters['v:s'] = $submission_id;// adds to X-Mailgun-Variables header e.g {"s": "77"}
		}

		// setting test mode on/off
		$is_test_mode = isset($headers['X-MSE-TEST']) ? $headers['X-MSE-TEST'] : false;
		unset($headers['X-MSE-TEST']);// no longer required
		if($is_test_mode) {
			\SS_Log::log("addCustomParameters: is test mode", \SS_Log::NOTICE);
			$parameters['o:testmode'] = 'yes';//Adds X-Mailgun-Drop-Message header
		}

		$is_running_test = SapphireTest::is_running_test();
		$workaround_testmode = Config::inst()->get('NSWDPC\SilverstripeMailgunSync\Connector\Base', 'workaround_testmode');
		if($is_running_test && $workaround_testmode) {
			//\SS_Log::log("addCustomParameters: workaround_testmode is ON - this unsets o:testmode while running tests", \SS_Log::DEBUG);
			unset($parameters['o:testmode']);
		}

		// if tags are provided, add them
		// tags can be filtered on when polling for events
		// tags are an array, values are used - e.g ['ball','black','sport'], keys are ignored
		$tags = isset($headers['X-MSE-O:TAGS']) ? json_decode($headers['X-MSE-O:TAGS'], true) : [];
		unset($headers['X-MSE-O:TAGS']);// no longer required
		if(!empty($tags) && is_array($tags)) {
			$parameters['o:tag'] = array_values($tags);
		}

		// add all remaining headers
		if(is_array($headers)) {
			foreach($headers as $header => $header_value) {
				$parameters['h:' . $header] = $header_value;
			}
		}

	}
}
