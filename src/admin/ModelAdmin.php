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
		
		$grid = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
		$config = $grid->getConfig();
		
		$config->removeComponentsByType('GridFieldAddNewButton');
		//$config->removeComponentsByType('GridFieldEditButton');
		$config->removeComponentsByType('GridFieldPrintButton');
		$config->removeComponentsByType('GridFieldDeleteAction');
		
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
 */
class ModelAdmin_ItemRequest extends \GridFieldDetailForm_ItemRequest {

	private static $allowed_actions = array (
		'edit',
		'view',
		'ItemEditForm',
		'doTryAgain'
	);

	/**
	 * Pushes CMS actions from managed models onto the button list
	 * @TODO BUG when viewing events within a submission, this does not appear. How does save etc work on current record
	 */
	public function ItemEditForm() {
		$form = parent::ItemEditForm();
		$actions = $form->Actions();
		$cms_actions = $this->record->getCMSActions();
		if($cms_actions) {
			foreach ($cms_actions as $action) {
				$actions->push($action);
			}
		}
		return $form;
	}

	/**
	 * Provide ability to resubmit a message via a \MailgunEvent record
	 */
	public function doTryAgain($data, $form) {
		if($this->record instanceof \MailgunEvent) {
			if($submission = $this->record->Resubmit()) {
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
