<?php
namespace NSWDPC\SilverstripeMailgunSync;
use Mailgun\Model\Message\SendResponse;
use NSWDPC\SilverstripeMailgunSync\Connector\Message as MessageConnector;
use SilverStripe\Control\Email\Mailer as SilverstripeMailer;
use SilverStripe\Control\Email\Email;
use Mailgun\Mailgun;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use Swift_Message;
use Swift_MimePart;
use Swift_Attachment;
use Swift_Mime_SimpleHeaderSet;

/**
 * Mailgun Mailer, called via $email->send();
 * See: https://docs.silverstripe.org/en/4/developer_guides/email/ for Email documentation.
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

	use Configurable;

	public $alwaysFrom;// when set, override From address, applying From provided to Reply-To header, set original "From" as "Sender" header

	/**
	 * @var array An array of headers that Swift produces and Mailgun probably doesn't need
	 */
	private static $blacklist_headers = [
		'Content-Type',
		'MIME-Version',
		'Date',
		'Message-ID',
	];

	/**
	 * @returns string the mailgun message id
	 */
	public function send($email) {
		/**
		 * @var Swift_Message
		 */
		$message = $email->getSwiftMessage();

		if(!$message instanceof Swift_Message) {
			throw new InvalidRequestException("There is no message associated with this request");
		}

		/**
		 * Work out whether the message is plain or not (HTML+Plain)
		 * For a sendPlain() message, the following will be set
		 * $has_plain_part -> true
		 * $body -> will be set
		 * $plain_body -> will not be set
		 * For a send() message, the following will be set
		 * $has_plain_part -> true
		 * $body -> will be set
		 * $plain_body -> will be set
		 */
		$plain_body = $email->findPlainPart();
		$has_plain_part = $email->hasPlainPart();
		$body = $email->getBody();
		if($plain_body instanceof Swift_MimePart) {
			$plain_body = $plain_body->getBody();
		}

		if($has_plain_part && $body && $plain_body) {
			// an HTML message will have HTML, plain and have a plain part
			$is_html_message = true;
		} else {
			$plain_body = $body;
		}

		$to = $from = [];

		// Handle 'From' headers from Swift_Message
		$message_from = $message->getFrom();
		if(empty($message_from) || !is_array($message_from)) {
			// Mailgun requires a from header
			throw new InvalidRequestException("At least one 'From' entry in a mailbox spec is required");
		}
		foreach($message_from as $from_email=>$from_name) {
			if(!empty($from_name)) {
				$from[] = $from_name . " <" . $from_email . ">";
			} else {
				$from[] = $from_email;
			}
		}

		// Handle 'To' headers from Swift_Message
		$message_to = $message->getTo();
		if(empty($message_to) || !is_array($message_to)) {
			// Mailgun requires a from header
			throw new InvalidRequestException("At least one 'To' entry in a mailbox spec is required");
		}
		foreach($message_to as $to_email => $to_name) {
			if(!empty($to_name)) {
				$to[] = $to_name . " <" . $to_email . ">";
			} else {
				$to[] = $to_email;
			}
		}

		$subject = $message->getSubject();

		if($is_html_message) {
			$message_id = $this->sendHTML(
											implode(",", $to),
											implode(",", $from),
											$subject,
											$body,
											$message->getChildren(),
											$message->getHeaders(),
											$plain_body
										);
		} else {
			$message_id = $this->sendPlain(
											implode(",", $to),
											implode(",", $from),
											$subject,
											$plain_body,
											$message->getChildren(),
											$message->getHeaders()
										);
		}
		return $message_id;
	}

	/**
	* @param string $to
	* @param string $from
	* @param string $subject
	* @param string $content the HTML content
	* @param string $plainContent
	* @param array $attachments an array of attachments, each value is a {@link Swift_Attachment}
	* @param mixed $headers {@link Swift_Mime_SimpleHeaderSet}
	 */
	protected function sendPlain($to, $from, $subject, $plainContent, $attachments = [], $headers = null) {
			return $this->sendMessage($to, $from, $subject, '', $plainContent, $attachments, $headers);
	}

	/**
	* @param string $to
	* @param string $from
	* @param string $subject
	* @param string $content the HTML content
	* @param string $plainContent
	* @param array $attachments an array of attachments, each value is a {@link Swift_Attachment}
	* @param mixed $headers {@link Swift_Mime_SimpleHeaderSet}
	 */
	protected function sendHTML($to, $from, $subject, $htmlContent, $attachments = [], $headers = null, $plainContent = '') {
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
	 * @param string $content the HTML content
	 * @param string $plainContent
	 * @param array $attachments an array of attachments, each value is a {@link Swift_Attachment}
	 * @param Swift_Mime_SimpleHeaderSet|null $headers an array of attachments, each value is a {@link Swift_Attachment}
	 */
	protected function sendMessage($to, $from, $subject, $content, $plainContent, $attachments, $headers) {
			try {

				$connector = new Connector\Message();

				// process attachments
				if(!empty($attachments)) {
					$attachments = $this->prepareAttachments($attachments);
				} else {
					// eensure empty array
					$attachments = [];
				}

				// process headers
				if($headers instanceof Swift_Mime_SimpleHeaderSet) {
					$headers = $this->prepareHeaders($headers);
				} else {
					// ensure empty array
					$headers = [];
				}

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

				//Log::log('Sending...', 'DEBUG');
				$response = $connector->send($parameters, $in);
				$message_id = "";
				if($response && $response instanceof SendResponse) {
					// get a message.id from the response
					$message_id = $this->saveResponse($response);
				}

			} catch (Exception $e) {
				Log::log('Mailgun-Sync / Mailgun error: ' . $e->getMessage(), 'ERROR');
				return false;
			}

			// return a message_id - note that this may not be set in the case of sending via a queued job
			return $message_id;
	}

	/**
	 * @returns array
	 * Prepare headers for use in Mailgun
	 */
	protected function prepareHeaders(Swift_Mime_SimpleHeaderSet $header_set) {
		$list = $header_set->getAll();
		$headers = [];
		foreach($list as $header) {
			// Swift_Mime_Headers_ParameterizedHeader
			$headers[ $header->getFieldName() ] = $header->getFieldBody();
		}
		$blacklist = $this->config()->get('blacklist_headers');
		if(is_array($blacklist)) {
			$blacklist = array_merge(
				$blacklist,
				[ 'From', 'To', 'Subject'] // avoid multiple headers and RFC5322 issues with a From: appearing twice, for instance
			);
			foreach($blacklist as $header_name) {
				unset($headers[ $header_name ]);
			}
		}
		return $headers;
	}

	/**
	 * @note refer to {@link Mailgun\Api\Message::prepareFile()} which is the preferred way of attaching messages from 3.0 onwards as {@link Mailgun\Connection\RestClient} is deprecated
	 * This overrides writing to temp files as Silverstripe {@link Email::attachFileFromString()} already provides the attachments in the following way:
	 *		 'contents' => $data,
	 *		 'filename' => $filename,
	 *		 'mimetype' => $mimetype,
	 * @param array $attachements Each value is a {@link Swift_Attachment}
	 */
	protected function prepareAttachments(array $attachments) {
		$mailgun_attachments = [];
		foreach($attachments as $attachment) {
			if(!$attachment instanceof Swift_Attachment) {
				continue;
			}
			$mailgun_attachments[] = [
				'fileContent' => $attachment->getBody(),
				'filename' => $attachment->getFilename(),
				'mimetype' => $attachment->getContentType()
			];
		}
		$attachments = null;
		unset($attachments);
		return $mailgun_attachments;

		/*
		foreach ($attachments as $k => $attachment) {

			// ensure the content of the attachment is in the key that Mailgun\Api\Message::prepareFile() can handle
			$attachments[$k]['fileContent'] = $attachment['contents'];
			unset($attachments[$k]['contents']);
		}
		return $attachments;
		*/
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
			//Log::log("addCustomParameters: is test mode", 'NOTICE');
			$parameters['o:testmode'] = 'yes';//Adds X-Mailgun-Drop-Message header
		}

		$workaround_testmode = Config::inst()->get('NSWDPC\SilverstripeMailgunSync\Connector\Base', 'workaround_testmode');
		if($workaround_testmode) {
			//Log::log("addCustomParameters: workaround_testmode is ON - this unsets o:testmode while running tests", 'DEBUG');
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
