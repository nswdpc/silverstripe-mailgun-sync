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
		'doTryAgain'
	);

	public function ItemEditForm() {
		$form = parent::ItemEditForm();
		if($this->record instanceof MailgunSubmission) {
			$actions = $form->Actions();
			if ($cms_actions = $this->record->getCMSActions()) {
				foreach ($cms_actions as $action) {
					$actions->push($action);
				}
			}
		}
		return $form;
	}

	public function doTryAgain($data, $form) {
		if($this->record instanceof MailgunSubmission) {
			$submission = $this->record->Resubmit();
			if(!empty($submission->ID)) {
				// resubmitting will return a new submission record
				$form->sessionMessage('Submitted. Updated submission is #' . $submission->ID, 'good');
			} else {
				// TODO: maybe some reasons here
				$form->sessionMessage('Failed, could not resubmit.', 'bad');
			}
		}
		return $this->edit( \Controller::curr()->getRequest() );
	}

}
