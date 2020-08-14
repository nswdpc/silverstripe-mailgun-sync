<?php
namespace NSWDPC\Messaging\Mailgun;

use Mailgun\Mailgun;
use SilverStripe\ORM\DataObject;
use Mailgun\Model\Event\Event as MailgunEventModel;
use NSWDPC\Messaging\Mailgun\Connector\Message as MessageConnector;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\FormAction;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\ORM\FieldType\DBField;
use DateTime;
use DateTimeZone;
use Exception;

/**
 * @author James Ellis
 * @note each record is an event linked to a submission
 * @note refer to http://mailgun-documentation.readthedocs.io/en/latest/api-events.html#event-structure for information about the uniqueness of Event Ids
 * @see https://mailgun.uservoice.com/forums/156243-general/suggestions/5511691-add-a-unique-id-to-every-event-api-entry
 */
class MailgunEvent extends DataObject implements PermissionProvider
{

    private static $default_sort = "Timestamp DESC";// try to sort by most recent event first

    private static $singular_name = "Event";
    private static $plural_name = "Events";

    private static $table_name = "MailgunEvent";

    const ACCEPTED = 'accepted';
    const REJECTED = 'rejected';
    const DELIVERED = 'delivered';
    const FAILED = 'failed';
    const OPENED = 'opened';
    const CLICKED = 'clicked';
    const UNSUBSCRIBED = 'unsubscribed';
    const COMPLAINED = 'complained';
    const STORED = 'stored';

    const TAG_RESUBMIT = 'resubmit';

    const FAILURE_TEMPORARY = 'temporary';
    const FAILURE_PERMANENT = 'permanent';

    const PERMISSIONS_VIEW = 'MAILGUNEVENT_VIEW';
    const PERMISSIONS_DELETE = 'MAILGUNEVENT_DELETE';

    private static $db = [
        /**
         * @note Mailgun says "Event id. It is guaranteed to be unique within a day.
         * It can be used to distinguish events that have already been retrieved
         * When requests with overlapping time ranges are made."
         */
        'EventId' => 'Varchar(255)',
        'MessageId' => 'Varchar(255)',// remote message id for event
        'Severity' => 'Varchar(16)',// permanent or temporary, for failures
        'EventType' => 'Varchar(32)',// Mailgun event string see http://mailgun-documentation.readthedocs.io/en/latest/api-events.html#event-types
        'UTCEventDate' => 'Date',// based on timestamp returned, the UTC Y-m-d date
        'Timestamp' => 'Decimal(16,6)',// The time when the event was generated in the system provided as Unix epoch seconds.
        'Recipient' => 'Varchar(255)', // the Recipient value is used to re-send the message, an email address
        'Reason' => 'Varchar(255)', // reason e.g old

        // fields containing delivery status information
        'DeliveryStatusMessage' => 'Text', // reason text e.g for failures 'mailbox full', 'spam' etc
        'DeliveryStatusDescription' => 'Text', // verbose reason for delivery status
        'DeliveryStatusCode' => 'Int', // smtp reason e.g 550
        'DeliveryStatusAttempts' => 'Int',
        'DeliveryStatusSession' => 'Decimal(24,16)',// number of seconds, this can be a big number to high precision
        'DeliveryStatusMxHost' => 'Varchar(255)',

        'StorageURL' => 'Text',// storage URL for message at Mailgun (NB: max 3 days for paid accounts, message may have been deleted by MG)
        'DecodedStorageKey' => 'Text',  // JSON encoded storage key
    ];

    private static $summary_fields = [
        'ID' => '#',
        'EventType' => 'Event',
        'Severity' => 'Severity',
        'DeliveryStatusAttempts' => 'Delivery Attempts',
        'DeliveryStatusCode' => 'Code',
        'Recipient' => 'Recipient',
        'MessageId' => 'Msg Id',
        'LocalDateTime' => 'Date',
    ];

    /**
     * Defines a default list of filters for the search context
     * @var array
     */
    private static $searchable_fields = [
        'Reason',
        'Severity',
        'EventType',
        'DeliveryStatusCode',
        'Recipient',
        'MessageId',
    ];

    private static $indexes = [
        'Created' => true,
        'LastEdited' => true,
        'EventType' => true,
        'EventId' => true,
        'MessageId' => true,
        'UTCEventDate' => true,
        'EventLookup' => [ 'type' => 'index', 'columns' => ["MessageId","Timestamp","Recipient","EventType"] ],
        'Recipient' => true,
    ];

    /**
     * @return array
     */
    public function providePermissions()
    {
        return [
            self::PERMISSIONS_VIEW => [
                'name' => 'View Mailgun events',
                'category' => 'Mailgun'
            ],
            self::PERMISSIONS_DELETE => [
                'name' => 'Delete Mailgun events',
                'category' => 'Mailgun'
            ]
        ];
    }

    /**
     * Set up permissions, assign to group
     */
    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();
        $this->createGroupsAndPermissions();
    }

    /**
     * Set permission groups
     */
    final private function createGroupsAndPermissions()
    {
        $manager_code = 'mailgun-managers';
        $manager_group = Group::get()->filter('Code', $manager_code)->first();
        if (empty($manager_group->ID)) {
            $manager_group = Group::create();
            $manager_group->Code = $manager_code;
        }
        $manager_group->Title = "Mailgun Managers";
        $manager_group_id = $manager_group->write();
        if ($manager_group_id) {
            $permissions = $manager_group->Permissions()->filter('Code', [ self::PERMISSIONS_DELETE, self::PERMISSIONS_VIEW ]);
            $codes = $permissions->column('Code');
            if(!in_array( self::PERMISSIONS_DELETE, $codes)) {
                Permission::grant($manager_group_id, self::PERMISSIONS_DELETE);
            }
            if(!in_array( self::PERMISSIONS_VIEW, $codes)) {
                Permission::grant($manager_group_id, self::PERMISSIONS_VIEW);
            }
        }
    }

    /**
     * Allow for easy visual matching between this and the Mailgin App Logs screen
     */
    public function getTitle()
    {
        return DBField::create_field('Varchar', "#{$this->ID} - {$this->LocalDateTime()} - {$this->EventType} - {$this->Recipient}");
    }

    /**
     * Returns the age of the event, in seconds
     */
    public function Age()
    {
        if ($this->Timestamp == 0) {
            return false;
        }
        $age = time() - $this->Timestamp;
        return $age;
    }

    /**
     * Events can't be edited
     */
    public function canEdit($member = null)
    {
        return false;
    }

    /**
     * Apply permission check on deleting events
     */
    public function canDelete($member = null)
    {
        if (!$member) {
            $member = Member::currentUser();
        }
        return Permission::check(self::PERMISSIONS_DELETE, 'any', $member);
    }

    /**
     * Allow viewing by members with this permission
     */
    public function canView($member = null)
    {
        if (!$member) {
            $member = Member::currentUser();
        }
        return Permission::check(self::PERMISSIONS_VIEW, 'any', $member);
    }

    /**
     * Most of the fields here are readonly
     */
    public function getCmsFields()
    {
        $fields = parent::getCmsFields();

        $fields->removeByName(['DecodedStorageKey']);

        foreach ($fields->dataFields() as $field) {
            $fields->makeFieldReadonly($field);
        }

        $fields->dataFieldByName('DeliveryStatusMessage')->setTitle('Message');
        $fields->dataFieldByName('DeliveryStatusDescription')->setTitle('Description');
        $fields->dataFieldByName('DeliveryStatusCode')->setTitle('SMTP Code');
        $fields->dataFieldByName('DeliveryStatusAttempts')->setTitle('Delivery attempts by Mailgun');
        $fields->dataFieldByName('DeliveryStatusSession')->setTitle('Session time (seconds)');
        $fields->dataFieldByName('DeliveryStatusMxHost')->setTitle('MX Host');
        if ($this->EventType == self::FAILED && $this->Severity == self::FAILURE_TEMPORARY) {
            $fields->dataFieldByName('Severity')->setRightTitle('Temporary failures will be retried by Mailgun');
        }

        $fields->dataFieldByName('UTCEventDate')->setTitle('Event Date (UTC)');
        $fields->dataFieldByName('StorageURL')->setRightTitle('Only applicable for 3 days after the event date');

        $fields->dataFieldByName('Timestamp')->setRightTitle($this->UTCDateTime());

        // show a list of related events sharing the same MessageId
        $siblings = $this->getSiblingEvents();
        if ($siblings && $siblings->count() > 0) {
            $config = GridFieldConfig_RecordEditor::create();
            $config->removeComponentsByType('GridFieldEditButton');
            $gridfield = GridField::create('SiblingEventsGridField', 'Siblings', $siblings, $config);
            $literal_field = LiteralField::create('SiblingEventNote', '<p class="message">This tab shows events sharing the same Mailgun message-id '
                                                                                                                                        . '<code>'. htmlspecialchars($this->MessageId) . '</code></p>');
            $fields->addFieldsToTab('Root.RelatedEvents', [$literal_field, $gridfield ]);
        }

        return $fields;
    }

    /**
     * Events that are sibling to this event (sharing the smae MessageId)
     * @return DataList
     */
    public function getSiblingEvents()
    {
        $events = MailgunEvent::get()->filter('MessageId', $this->MessageId)->sort('Timestamp ASC');
        return $events;
    }

    /**
     * UTC date/time based on Timestamp of this event
     * @return string
     */
    public function UTCDateTime()
    {
        return $this->RecordDateTime("UTC");
    }

    /**
     * Local date/time based on Timestamp of this event
     * @return string
     */
    public function LocalDateTime()
    {
        return $this->RecordDateTime("Australia/Sydney");
    }

    /**
     * Return RFC2822 formatted string of event timestamp
     * @return string
     */
    private function RecordDateTime($timezone = "UTC")
    {
        if (!$this->Timestamp) {
            return "";
        }
        $dt = new DateTime();
        $dt->setTimestamp($this->Timestamp);
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt->format(DateTime::RFC2822);
    }

    /**
     * Combining all event types that are related to a user action
     */
    public static function UserActionStatus()
    {
        return [ self::OPENED, self::CLICKED, self::UNSUBSCRIBED, self::COMPLAINED ];
    }

    public function IsFailed()
    {
        return $this->EventType == self::FAILED;
    }

    /**
     * @deprecated use IsFailed() in order to match API event naming
     */
    public function IsFailure()
    {
        return $this->IsFailed();
    }

    // Mailgun has not even attempted to deliver these
    public function IsRejected()
    {
        return $this->EventType == self::REJECTED;
    }

    /**
     * Helper method to determin if event is failed || rejected
     * @return boolean
     */
    public function IsFailedOrRejected()
    {
        return $this->IsFailed() || $this->IsRejected();
    }

    /**
     * @return boolean
     */
    public function IsDelivered()
    {
        return $this->EventType == self::DELIVERED;
    }

    /**
     * @return boolean
     */
    public function IsAccepted()
    {
        return $this->EventType == self::ACCEPTED;
    }

    /**
     * @return boolean
     */
    public function IsUserEvent()
    {
        return in_array($this->EventType, self::UserActionStatus());
    }

    /**
     * Helper method to create a UTC Date from a timestamp
     * @return string
     */
    private static function CreateUTCDate($timestamp)
    {
        return self::CreateUTCDateTime($timestamp, "Y-m-d");
    }

    /**
     * Helper method to create a UTC DateTime from a timestamp
     * @return string
     */
    private static function CreateUTCDateTime($timestamp, $format = "Y-m-d H:i:s")
    {
        $dt = new DateTime();
        $dt->setTimestamp($timestamp);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * GetByMessageDetails - retrieve an event based on the message/timestamp/recipient/event type
     */
    private static function GetByMessageDetails($message_id, $timestamp, $recipient, $event_type)
    {
        if (!$message_id || !$timestamp || !$recipient || !$event_type) {
            return false;
        }
        $event = MailgunEvent::get()->filter(['MessageId' => $message_id, 'Timestamp' => $timestamp, 'Recipient' => $recipient, 'EventType' => $event_type ])->first();
        if (!empty($event->ID)) {
            return $event;
        }
        return false;
    }

    /**
     * Return message header from the  {@link Mailgun\Model\Event\Event}
     * @return string
     * @param string $header the header to retrieve
     */
    private function getMessageHeader(MailgunEventModel $event, $header)
    {
        $message = $event->getMessage();
        $value = isset($message['headers'][$header]) ? $message['headers'][$header] : '';
        return $value;
    }

    /**
     * Based on a delivery status returned from Mailgun, grab relevant details for this record
     * @param array $delivery_status
     */
    private function saveDeliveryStatus(array $delivery_status)
    {
        $this->DeliveryStatusMessage = isset($delivery_status['message']) ? $delivery_status['message'] : '';
        $this->DeliveryStatusDescription = isset($delivery_status['description']) ? $delivery_status['description'] : '';
        $this->DeliveryStatusCode = isset($delivery_status['code']) ? $delivery_status['code'] : '';
        $this->DeliveryStatusAttempts = isset($delivery_status['attempt-no']) ? $delivery_status['attempt-no'] : '';
        $this->DeliveryStatusSession = isset($delivery_status['session-seconds']) ? $delivery_status['session-seconds'] : '';
        $this->DeliveryStatusMxHost = isset($delivery_status['mx-host']) ? $delivery_status['mx-host'] : '';
        return true;
    }

    /**
     * Given a Mailgun\Model\Event\Event, store if possible
     * @param Mailgun\Model\Event\Event $event
     * @return MailgunEvent|boolean
     */
    public function storeEvent(MailgunEventModel $event)
    {

        $this->extend('onBeforeStoreMailgunEvent', $event);

        $mailgun_event_id = $event->getId();
        $event_type = $event->getEvent();
        $variables = $event->getUserVariables();
        $timestamp = $event->getTimestamp();
        $status = $event->getDeliveryStatus();
        $storage = $event->getStorage();
        $tags = $event->getTags();
        $recipient = $event->getRecipient();

        // get message id from headers
        $mailgun_message_id = "";
        $message = $event->getMessage();
        if (!empty($message['headers']['message-id'])) {
            $mailgun_message_id = $message['headers']['message-id'];
        }

        $mailgun_event = MailgunEvent::create();
        $mailgun_event->EventId = $mailgun_event_id;// webhooks do not provide a mailgun event id
        $mailgun_event->MessageId = $mailgun_message_id;
        $mailgun_event->Timestamp = $timestamp;
        $mailgun_event->UTCEventDate = self::CreateUTCDate($timestamp);
        $mailgun_event->Severity = $event->getSeverity();
        $mailgun_event->EventType = $event_type;
        $mailgun_event->Recipient = $recipient;// if the message is sent to Someone <someone@example.com>, the $recipient value will be someone@example.com
        $mailgun_event->Reason = $event->getReason();// doesn't appear to be set for 'rejected' events
        $mailgun_event->saveDeliveryStatus($status);
        $mailgun_event->StorageURL = isset($storage['url']) ? $storage['url'] : '';
        $mailgun_event->DecodedStorageKey = "";// no need to store this
        $mailgun_event_id = $mailgun_event->write();
        if (!$mailgun_event_id) {
            // could not create record
            return false;
        }
        $this->extend('onAfterStoreMailgunEvent', $event, $mailgun_event);
        return $mailgun_event;
    }

    /**
     * Retrieve the number of failures for a particular recipient/message for this event's linked submission
     * Failures are determined to be 'failed' or 'rejected' events
     */
    public function GetRecipientFailures()
    {
        $events = MailgunEvent::get()
                                ->filter('MessageId', $this->MessageId) // Failures for this specific message
                                ->filter('Recipient', $this->Recipient) // Recipient is an email address
                                ->filterAny('EventType', [ self::FAILED, self::REJECTED ])
                                ->count();
        return $events;
    }

}
