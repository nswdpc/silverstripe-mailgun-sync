<?php
namespace DPCNSW\SilverstripeMailgunSync;

/**
 * Admin for MailgunSync
 */
class ModelAdmin extends \ModelAdmin {
	private static $url_segment = 'mailgunsync';
	private static $menu_title = 'Mailgun';
	private static $managed_models = array(
		'MailgunSubmission',
		'MailgunEvent',
		'TestMailgunFormSubmission',
	);

	public function init() {
		parent::init();
		$this->showImportForm = false;
	}

	public function getEditForm($id = null, $fields = null) {
		$form = parent::getEditForm($id, $fields);
		/*
		$grid = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
		$grid->getConfig()->removeComponentsByType('GridFieldEditButton');
		$grid->getConfig()->removeComponentsByType('GridFieldDeleteAction');
		*/
		
		$config = $form->Fields()
								->fieldByName($this->sanitiseClassName($this->modelClass))
								->getConfig();
		
		$field = $config->getComponentByType('GridFieldDetailForm');
		$field->setItemRequestClass('DPCNSW\SilverstripeMailgunSync\ModelAdmin_ItemRequest');
		return $form;
	}
}


/**
 * @note handle item requests for managed models
 * @todo if the message is more than 3 days old, it's pointless having a resubmit button here (but make 3 days configurable)
 * @todo what if certain records provide their own resubmit handling (maybe check for a  property) ?
 */
class ModelAdmin_ItemRequest extends \GridFieldDetailForm_ItemRequest {

	private static $allowed_actions = array (
		'edit',
		'view',
		'ItemEditForm',
		'doTestSubmit',
		'doTryAgain'
	);

	/**
	 * @todo when viewing events via a submission, but does not appear. How does save etc work on current record
	 */
	public function ItemEditForm() {
		$form = parent::ItemEditForm();
		if($this->record instanceof \MailgunEvent || $this->record instanceof \TestMailgunFormSubmission) {
			$actions = $form->Actions();
			if ($cms_actions = $this->record->getCMSActions()) {
				foreach ($cms_actions as $action) {
					$actions->push($action);
				}
			}
		}
		return $form;
	}
	
	/**
	 * doTestSubmit - submits a TestMailgunFormSubmission to Mailgun
	 */
	public function doTestSubmit($data, $form) {
		if($this->record instanceof \TestMailgunFormSubmission) {
			// TODO save message first ?
			$submission = $this->record->SubmitMessage();
			if(!empty($submission->ID)) {
				// resubmitting will return a new submission record
				$form->sessionMessage('Submitted message', 'good');
			} else {
				// TODO: maybe some reasons here
				$form->sessionMessage('Failed, could not submit.', 'bad');
			}
		}
		return $this->edit( \Controller::curr()->getRequest() );
	}

	public function doTryAgain($data, $form) {
		if($this->record instanceof \MailgunEvent) {
			$submission = $this->record->Resubmit();
			if(!empty($submission->ID)) {
				// resubmitting will return a new submission record
				$form->sessionMessage('Resubmitted', 'good');
			} else {
				// TODO: maybe some reasons here
				$form->sessionMessage('Failed, could not resubmit.', 'bad');
			}
		}
		return $this->edit( \Controller::curr()->getRequest() );
	}

}
