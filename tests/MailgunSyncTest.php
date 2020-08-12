<?php
namespace NSWDPC\SilverstripeMailgunSync;

use NSWDPC\SilverstripeMailgunSync\Connector;
use NSWDPC\SilverstripeMailgunSync\Connector\Message as MessageConnector;
use Mailgun\Model\Message\SendResponse;
use NSWDPC\SilverstripeMailgunSync\Mailer as MailgunSyncMailer;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Email\Mailer;
use SilverStripe\Control\Email\Email;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use Exception;

/**
 * Tests for mailgun-sync, see README.md for more
 * @author James Ellis <james.ellis@dpc.nsw.gov.au>
 * @note send and sendMime are passed o:testmode = 'yes' when running a test unless the config option workaround_testmode is enabled
 */

class MailgunSyncTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
    TestMailgunFormSubmission::class
  ];

    //protected static $fixture_file = null;//'MailgunSyncTest.yml';

    private static $to_address = "";// an email address
  private static $to_name = "";// optional the recipient name
  private static $cc_address = "";
    private static $from_address = "";
    private static $from_name = "";// option the from name (e.g for 'Joe <joe@example.com>' formatting)
    private static $test_body = "<h1>Header provider strategic</h1>"
                            . "<p>consulting support conversation advertisements policy promotional request.</p>"
                            . "<p>Option purpose programming</p>";
    private static $sleep_time = 10;
    private static $test_attachment = "attachments/test_attachment.pdf";// using the module as the root

    public function setUp()
    {
        parent::setUp();

        // Avoid using TestMailer
        $this->mailer = new MailgunSyncMailer;
        Injector::inst()->registerService($this->mailer, Mailer::class);

        // modify some config values for tests
        // never send via a job
        Config::inst()->update(Connector\Base::class, 'send_via_job', 'no');
        // testing sleep time between event polling checks, increase this if Mailgun is slow
    // Config::inst()->update( MailgunSyncTest::class, 'sleep_time', 30);
    }

    public function tearDown()
    {
        Config::inst()->update(Connector\Base::class, 'workaround_testmode', false);
        try {
            // ensure the to_address is removed from any supression list
            $email_address = Config::inst()->get(__CLASS__, 'to_address');
            $bounce_connector = new Connector\Bounce();
            $remove_response = $bounce_connector->remove($email_address);
        } catch (Exception $e) {
        }

        parent::tearDown();
    }

    /**
     * When polling for events, the 'Delivered'/'Failed' event happens close to but a non-predictable time after the message is 'Accepted', even when in testmode
     *         This sleep ensures that we can capture both events for relevant tests - default is 10s but can be changed if required via config
     */
    private function sleepAfterDelivery()
    {
        $time = Config::inst()->get(__CLASS__, 'sleep_time');
        Log::log("Sleeping for {$time}s", 'DEBUG');
        if (!$time || $time < 0) {
            $time = 10;
        }
        sleep($time);
        Log::log("Done sleeping", 'DEBUG');
    }

    /**
     * Retrieve the absolute path to our file attachment
     */
    private function getTestAttachment()
    {
        $relative_path = Config::inst()->get(__CLASS__, 'test_attachment');
        $path = realpath(dirname(__FILE__) . "/{$relative_path}");
        if (is_file($path) && is_readable($path)) {
            return $path;
        }

        throw new Exception("The path {$path} is not accessible or not a valid file");
    }

    /**
     * Immediate polling of a just-sent message (in the last minute), pass the local event and the message-id returned from Mailgun
     */
    private function pollImmediateAcceptedDelivered(MailgunEvent $event, $message_id, $tags = "")
    {

    // attempt to track a delivered event
        $connector = new Connector\Event();
        $timeframe = 'now -5 minute';// allow for sleeps
        $begin = Connector\Base::DateTime($timeframe);
        $event_filter = MailgunEvent::DELIVERED . " OR " . MailgunEvent::ACCEPTED;
        $resubmit = false;

        // filter on the message-id returned
        $extra_params = [
      'message-id' => $message_id,// the message id of the message just delivered
      'recipient' => $event->Recipient,// match against the recipient
    ];

        if ($tags) {
            $extra_params['tags'] = $tags;// filter on these tags as well (can be an expression e.g 'fixed OR broken')
        }

        Log::log("Polling for '{$event_filter}' event of message {$message_id} to {$event->Recipient}. Tags=" .  (isset($extra_params['tags']) ? $extra_params['tags'] : ""), 'DEBUG');

        // this should return 2 events - the delivered and accepted
        $events = $connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);

        $this->assertEquals(2, count($events));

        $resubmitted_event = null;
        $matched_events = 0;
        foreach ($events as $event_record) {
            switch ($event_record->EventType) {
        case MailgunEvent::DELIVERED:
        case MailgunEvent::ACCEPTED:
          $matched_events++;
          break;
        default:
          // :(
          break;
      }
        }

        $this->assertEquals(2, $matched_events);

        Log::log("Both events found", 'DEBUG');

        return true;
    }

    /**
     * submitCheck- submits a message and checks for event based on $event_filter (express or event type)
     * @note when testing for failure events it seems o:testmode happily marks a message as 'delivered' even though the recipient is in the bounce list.
     *           Set the config option workaround_testmode = true to deal with this (and set it to false after failure tests!)
     */
    private function submitAndCheckEvent($subject, $attachments, $event_filter)
    {
        // create a submission
        $record = TestMailgunFormSubmission::create();

        $to_address = Config::inst()->get(__CLASS__, 'to_address');
        $to_name = Config::inst()->get(__CLASS__, 'to_name');
        $this->assertNotEmpty($to_address);

        $record->To = $to_address;

        $record->Cc = Config::inst()->get(__CLASS__, 'cc_address');

        $from_address = Config::inst()->get(__CLASS__, 'from_address');
        $from_name = Config::inst()->get(__CLASS__, 'from_name');
        $this->assertNotEmpty($from_address);

        $record->From = $from_address;

        $record->Subject = $subject;
        $record->Body = Config::inst()->get(__CLASS__, 'test_body');

        $id = $record->write();

        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                $record->addAttachment($attachment);
            }
        }

        $submitted = $record->SubmitMessage();// true = submit in test mode

        // grab the MailgunSubmission
        $submission = MailgunSubmission::getMailgunSubmission($record);

        $this->assertTrue(isset($submission->SubmissionID) && $submission->SubmissionID == $record->ID);

        $this->assertNotEmpty($record->MessageId, "TestMailgunFormSubmission has no Mailgun MessageId value");

        // Wait for test mode delivered event, 10s seems to be the minimum wait time for the event
        $this->sleepAfterDelivery();

        // attempt to track an event based on the $event_filter
        $connector = new Connector\Event();
        $timeframe = 'now -5 minutes';
        $begin = Connector\Base::DateTime($timeframe);
        $resubmit = false;//don't resubmit

        Log::log("TestMailgunFormSubmission has MessageId: {$record->MessageId}", 'DEBUG');

        // Poll for events based on the message id returned and the recipient of the message
        // FROM THE DOCS:
        // recipient  An email address of a particular recipient. Even though a message may be addressed to several recipients,
        //             delivery is tracked on per recipient basis and every event pertains to only one recipient.
        $extra_params = [
      'message-id' => $record->MessageId,
      'recipient' => $to_address,// must be an email address
    ];

        Log::log("submitAndCheckEvent - Begin: {$begin}, Filter: {$event_filter},  Params:" . json_encode($extra_params), 'DEBUG');

        $events = $connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);

        $filtered_event = null;// based on the event_filter
        if (empty(!$events)) {
            foreach ($events as $event) {
                if ($event->SubmissionID == $submission->ID) {
                    Log::log("Matched Event #{$event->ID}", 'DEBUG');
                    $filtered_event = $event;
                    break;
                }
            }
        }

        $this->assertNotNull($filtered_event, "Filtered event is null. Filter={$event_filter}");

        // this is used for other tests
        return $filtered_event;
    }

    /**
     * submit a message and check for delivery event
     */
    private function submitCheckDelivered($subject, $attachments)
    {
        return $this->submitAndCheckEvent($subject, $attachments, MailgunEvent::DELIVERED);
    }

    /**
     * Submit a message and check for failure
     */
    private function submitCheckFailed($subject, $attachments)
    {
        return $this->submitAndCheckEvent($subject, $attachments, MailgunEvent::FAILED);
    }


    //-- TESTS


    /**
     * test mailer delivery only, no sync or event checking, just that we get the expected response
     */
    public function testMailerDelivery()
    {
        $to_address = Config::inst()->get(__CLASS__, 'to_address');
        $to_name = Config::inst()->get(__CLASS__, 'to_name');
        $this->assertNotEmpty($to_address);

        $from_address = Config::inst()->get(__CLASS__, 'from_address');
        $from_name = Config::inst()->get(__CLASS__, 'from_name');
        $this->assertNotEmpty($from_address);

        $subject = "test_mailer_delivery";

        $from = [
      $from_address => $from_name,
    ];
        $to = [
      $to_address => $to_name,
    ];
        $email = Email::create($from, $to, $subject);

        $this->assertTrue(Injector::inst()->get(Mailer::class) instanceof MailgunSyncMailer, "Mailer is not the MailgunSync Mailer");

        if ($cc = Config::inst()->get(__CLASS__, 'cc_address')) {
            $email->setCc($cc);
        }

        $email->setBody(Config::inst()->get(__CLASS__, 'test_body'));

        // ensure test mode
        $email->getSwiftMessage()->getHeaders()->addTextHeader('X-MSE-TEST', 1);

        // send the email
        // returns array($to, $subject, $content, $headers, '');
        $result = $email->send();

        $this->assertTrue($result != 0, "Email send result is {$result}");
    }

    /**
     * test API delivery only
     */
    public function testAPIDelivery()
    {
        $connector = new Connector\Message();
        $to = $to_address = Config::inst()->get(__CLASS__, 'to_address');
        $to_name = Config::inst()->get(__CLASS__, 'to_name');
        if ($to_name) {
            $to = $to_name . ' <' . $to_address . '>';
        }
        $this->assertNotEmpty($to_address);
        $from = $from_address = Config::inst()->get(__CLASS__, 'from_address');
        $from_name = Config::inst()->get(__CLASS__, 'from_name');
        if ($from_name) {
            $from = $from_name . ' <' . $from_address . '>';
        }
        $this->assertNotEmpty($from_address);
        $subject = "test_api_delivery";

        Log::log("Sending to {$to_address}", 'DEBUG');

        $parameters = [
      'o:testmode' => 'yes',
      'o:tag' => array('api_test'),
      'from' => $from,
      'to' => $to,
      'subject' => $subject,
      'text' => '',
      'html' => Config::inst()->get(__CLASS__, 'test_body')
    ];

        if ($cc = Config::inst()->get(__CLASS__, 'cc_address')) {
            $parameters['cc'] = $cc;
        }

        $response = $connector->send($parameters);

        $this->assertTrue($response && ($response instanceof SendResponse));

        $message_id = $response->getId();
        $message_id = MessageConnector::cleanMessageId($message_id);

        $this->assertNotEmpty($message_id, "Response has no message id");

        Log::log("API DELIVERY OK {$message_id}", 'DEBUG');
    }

    /**
     * test whether a message can be delivered
     */
    public function testThatAMessageIsDelivered($subject = "test_message_is_delivered", $attachments = [])
    {
        return $this->submitCheckDelivered($subject, $attachments);
    }

    /**
     * This test first attempts to deliver a message, then resubmit that message
     */
    public function testThatAMessageResubmits()
    {
        $delivered_event = $this->testThatAMessageIsDelivered("test_message_is_resubmitted");
        // event delivery
        $this->assertNotNull($delivered_event);

        // resubmit the event
        $message_id = $delivered_event->Resubmit();

        //var_dump($message_id);

        $this->assertNotEmpty($message_id);

        $this->sleepAfterDelivery();

        // poll for relevant events
        // assert that the recipient of the original delivered event has both events for the just resubmitted message
        $tags = MailgunEvent::TAG_RESUBMIT;
        $this->pollImmediateAcceptedDelivered($delivered_event, $message_id, $tags);
    }

    // This is a test to simply sync successful events, (Accepted and Delivered)
    public function testThatICanSyncEvents()
    {
        $delivered_event = $this->testThatAMessageIsDelivered("test_sync_email");

        // event delivery
        $this->assertNotNull($delivered_event);

        $message_id = $delivered_event->MessageId;

        $this->assertNotEmpty($message_id);

        $this->sleepAfterDelivery();

        $this->pollImmediateAcceptedDelivered($delivered_event, $message_id);
    }

    // test that a message can download and be stored
    public function testThatTheMessageDownloads($attachments = [])
    {
        $delivered_event = $this->testThatAMessageIsDelivered("test_message_download", $attachments);
        $this->assertNotNull($delivered_event);

        // Message connector downloads
        $connector = new Connector\Message();
        $result = $connector->storeTestMessage($delivered_event);

        $this->assertTrue(!empty($result['Content']) && !empty($result['File']) && ($result['File'] instanceof File) && !empty($result['File']->ID));
        $file_contents = $result['File']->getString();
        // content in the file should be the same as the content downloaded
        $this->assertEquals($file_contents, $result['Content']);

        return $delivered_event;
    }

    // test that a resubmitted message using file contents can resubmit
    public function testThatADownloadedMessageResubmits($attachments = [])
    {
        // deliver a message and download it
        $delivered_event = $this->testThatTheMessageDownloads($attachments);
        $this->assertNotNull($delivered_event);

        // resubmit via the Message connector
        $connector = new Connector\Message();
        // returns the message_id of the resubmitted event
        // allow_redeliver is true otherwise the default is to block already delivered messages
        // use_local_file_contents: false - use the remote message body, falling back to local copy if it exists
        $message_id = $connector->resubmit($delivered_event, true, false);

        $this->assertNotEmpty($message_id);

        $this->sleepAfterDelivery();

        // assert that the recipient of the original delivered event has both events for the just resubmitted message
        $tags = MailgunEvent::TAG_RESUBMIT;
        $this->pollImmediateAcceptedDelivered($delivered_event, $message_id, $tags);
    }

    /**
     * Test delivery of a message with an attachment
     */
    public function testThatAMessageWithAttachmentIsDelivered()
    {
        $attachments = [
      $this->getTestAttachment()
    ];
        $this->testThatAMessageIsDelivered("test_message_attachment_is_delivered", $attachments);
    }

    /**
     * Test resubmit of a message with an attachment
     */
    public function testThatAMessageWithAttachmentResubmits()
    {
        $attachments = [
      $this->getTestAttachment()
    ];
        $this->testThatADownloadedMessageResubmits($attachments);
    }

    /**
     * Test for failure by:
     * 1. adding the recipient to the suppression list in Mailgun
     * 2. creating a test submission and delivering, test for failure
     * 3. try to resubmit (test for failure)
     * 4. remove recipient from suppression list and test for failure
     */
    public function testForFailureAndResubmit()
    {
        // resubmit via the Message connector
        $email_address = Config::inst()->get(__CLASS__, 'to_address');

        // failure testing workaround
        Config::inst()->update(Connector\Base::class, 'workaround_testmode', true);

        $this->assertNotEmpty($email_address);

        /**
         * Here we simulate an address that will cause a failure to deliver, by adding them to the bounce list.
         */
        $connector = new Connector\Bounce();
        $code = 550;
        $error = "test_failure";
        try {
            Log::log("Attempting bounce removal.. {$email_address} ..", 'DEBUG');
            // first remove the email address so that it can be added
            // if the email address is not in the bounce list, Mailgun will return a 404 along with a \Mailgun\Exception\HttpClientException
            $remove_response = $connector->remove($email_address);
        } catch (Exception $e) {
        }

        Log::log("Attempting bounce create.. {$email_address} ..", 'DEBUG');
        $add_response = $connector->add($email_address, $code, $error);
        $this->assertTrue($add_response instanceof \Mailgun\Model\Suppression\Bounce\CreateResponse);

        // try to deliver a message...
        $attachments = [
      $this->getTestAttachment()
    ];
        Log::log("Attempting delivery.. {$email_address} ..", 'DEBUG');
        // testThatAMessageIsDelivered will assert that delivered event is NULL
        $failed_event = $this->submitCheckFailed("test_message_failure_resubmit", $attachments);

        // our failed event should be failed
        $this->assertTrue(($failed_event instanceof MailgunEvent) && $failed_event->EventType == MailgunEvent::FAILED);

        // turn off workaround
        Config::inst()->update(Connector\Base::class, 'workaround_testmode', false);

        // now remove the address from the block list
        $remove_response = $connector->remove($email_address);
        $this->assertTrue($remove_response instanceof \Mailgun\Model\Suppression\Bounce\DeleteResponse);

        // and attempt to resubmit the failed message via the Message connector
        $connector = new Connector\Message();
        // returns the message_id of the resubmitted event
        // when tests run, resubmits are always done in Mailgun testmode
        $message_id = $connector->resubmit($failed_event);

        $this->assertNotEmpty($message_id);

        $this->sleepAfterDelivery();

        // assert that the recipient of the original delivered event has both events for the just resubmitted message
        $tags = MailgunEvent::TAG_RESUBMIT;
        $this->pollImmediateAcceptedDelivered($failed_event, $message_id, $tags);
    }

    /**
     * Test a 3 day event process
     * - push the test address into the supression list
     * - send a submission, check for a failed event
     * - resubmit failed event (original + 1 day)
     * - poll and check for failed events
     * - resubmit the new failed event (original + 2 days)
     * - poll and check for failed events
     * - test for existence of the MIME content against events linked to the submission
     * - content should match
     * - remove supression record
     * - resubmit most recent failed event
     * - poll for delivered event
     */
    public function testFailedDeliveredEventProcessing()
    {

    // Set the number of retries before download
        Config::inst()->update(Connector\Base::class, 'resubmit_failures', 2);
        // Set that we can download MIME files locally
        Config::inst()->update(Connector\Base::class, 'sync_local_mime', true);
        // Up the sleep time between submit and event polling
        Config::inst()->update(MailgunSyncTest::class, 'sleep_time', 10);
        // turn testmode off for the failure checking part
        Config::inst()->update(Connector\Base::class, 'workaround_testmode', true);

        // resubmit via the Message connector
        $email_address = Config::inst()->get(__CLASS__, 'to_address');

        $this->assertNotEmpty($email_address);

        // Simulate an address that will cause a failure event, by adding them to the bounce list.
        $bounce_connector = new Connector\Bounce();
        $code = 550;
        $error = "test_failed_events_job";
        try {
            Log::log("Attempting bounce removal.. {$email_address} ..", 'DEBUG');
            // first remove the email address so that it can be added
            // if the email address is not in the bounce list, Mailgun will return a 404 along with a \Mailgun\Exception\HttpClientException
            $remove_response = $bounce_connector->remove($email_address);
        } catch (Exception $e) {
        }

        Log::log("Attempting bounce create.. {$email_address} ..", 'DEBUG');
        $add_response = $bounce_connector->add($email_address, $code, $error);

        $this->assertTrue(($add_response instanceof \Mailgun\Model\Suppression\Bounce\CreateResponse) && $add_response->getAddress() ==  $email_address, "Bounce not created or address is not {$email_address}");

        // try to deliver a message...
        $attachments = [
      $this->getTestAttachment()
    ];
        Log::log("Attempting delivery.. {$email_address} ..", 'DEBUG');
        // this should return a single failed event, DOES NOT auto resubmit on failure
        $failed_event = $this->submitCheckFailed("test_message_failed_event_job", $attachments);
        // at this point one FAILED event exists remotely

        // our failed event should be failed - first instance
        $this->assertTrue(($failed_event instanceof MailgunEvent) && $failed_event->EventType == MailgunEvent::FAILED, "Event does not exist or is not FAILED");

        Log::log("testFailedDeliveredEventProcessing failed event #{$failed_event->ID}", 'DEBUG');

        // 1st resubmission of the original failed event
        $failed_event->AutomatedResubmit();
        // 2 failed events should exist remotely

        Log::log("testFailedDeliveredEventProcessing post AutomatedResubmit()", 'DEBUG');

        $this->sleepAfterDelivery();

        // turn workaround off for testmode as address is about to be removed
        Config::inst()->update(Connector\Base::class, 'workaround_testmode', false);
        // ensure testmode is back on
        Config::inst()->update(Connector\Base::class, 'api_testmode', true);

        // now remove the address from the block list
        $remove_response = $bounce_connector->remove($email_address);
        $this->assertTrue($remove_response instanceof \Mailgun\Model\Suppression\Bounce\DeleteResponse);

        // #1 Poll for failed events from resubmission of failed event, resubmit - similar to the FailedEventsJob
        $event_connector = new Connector\Event();
        $timeframe = 'now -5 minutes';
        $begin = Connector\Base::DateTime($timeframe);
        $resubmit = true;// this will resubmit any failed events (which should deliver as the bounce supression record was removed)
        $event_filter = MailgunEvent::FAILED;
        $extra_params = [
      'message-id' => $failed_event->MessageId,
      'recipient' => $failed_event->Recipient,// must be an email address
    ];
        Log::log("testFailedEventProcessing polling - Begin: {$begin}, Filter: {$event_filter},  Params:" . json_encode($extra_params), 'DEBUG');

        $events = $event_connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);

        // We should now have 2 local failed events to match the remote ones -  the original failure and the resubmit
        $this->assertEquals(count($events), 2, "There are no events with filter {$event_filter}");

        // resubmit should have failed - should now have a downloaded MIME file attached to the new Event as 2 failures for submission
        $resubmitted_event = null;
        foreach ($events as $event) {
            if ($event->ID != $failed_event->ID) {
                $resubmitted_event = $event;
                break;
            }
        }

        // must be another failed MailgunEvent
        $this->assertTrue(!empty($resubmitted_event->ID) && ($resubmitted_event instanceof MailgunEvent));

        Log::log("testFailedEventProcessing resubmitted event {$resubmitted_event->ID}", 'DEBUG');

        // As we've gone over the failure limit, a local file would have downloaded, check it
        // the event should have a MIME attachment with contents matching the original email
        $content = $resubmitted_event->MimeMessageContent();

        // must have some content
        $this->assertNotEmpty($content, "MimeMessageContent is empty");

        // sleep to pick up delivered event (via resubmit=true above)
        $this->sleepAfterDelivery();

        // #2 poll for delivered event, resubmitted from #1 Poll before
        // when we polled above for failed events, the most recent one would have been resubmitted
        // check for delivery
        $event_connector = new Connector\Event();
        $timeframe = 'now -5 minutes';
        $begin = Connector\Base::DateTime($timeframe);
        $resubmit = false;
        $event_filter = MailgunEvent::DELIVERED;
        $extra_params = [
      'message-id' => $resubmitted_event->MessageId,
      'recipient' => $resubmitted_event->Recipient,// must be an email address
    ];
        Log::log("testFailedEventProcessing polling - Begin: {$begin}, Filter: {$event_filter},  Params:" . json_encode($extra_params), 'DEBUG');

        $events = $event_connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);

        $this->assertEquals(count($events), 1, "There are no events with filter {$event_filter}");

        $delivered_event = $events[0];

        $this->assertTrue(!empty($delivered_event->ID) && ($delivered_event instanceof MailgunEvent), "Delivered event is not a valid event");

        // turn testmode workaround back on
        Config::inst()->update(Connector\Base::class, 'workaround_testmode', true);
    }
}
