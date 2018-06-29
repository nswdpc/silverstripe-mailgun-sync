<?php
namespace NSWDPC\SilverstripeMailgunSync;

use SilverStripe\Admin\ModelAdmin as SS_ModelAdmin;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;

/**
 * Admin for MailgunSync
 */
class MailgunModelAdmin extends SS_ModelAdmin {

	private static $url_segment = 'mailgunsync';
	private static $menu_title = 'Mailgun';

	/**
	 * @var array
	 */
	private static $allowed_actions = [
			'EditForm'
	];

	private static $managed_models = [
		MailgunSubmission::class,
		MailgunEvent::class,
	];

	public function init() {
		parent::init();
		$this->showImportForm = false;
	}

	/**
	 * Provide an alternative access check to handle older SS 50chr Permission code limits
	 */
	public function alternateAccessCheck() {
		$member = Member::currentUser();
		return Permission::check('MAILGUNEVENT_VIEW', 'any', $member) || Permission::check('MAILGUNSUBMISSION_VIEW', 'any', $member);
	}

	public function getEditForm($id = null, $fields = null) {
		$form = parent::getEditForm($id, $fields);

		$grid = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
		$config = $grid->getConfig();

		$config->removeComponentsByType(GridFieldAddNewButton::class);
		//$config->removeComponentsByType('GridFieldEditButton');
		$config->removeComponentsByType(GridFieldPrintButton::class);
		$config->removeComponentsByType(GridFieldDeleteAction::class);

		$config = $form->Fields()
								->fieldByName($this->sanitiseClassName($this->modelClass))
								->getConfig();

		$field = $config->getComponentByType(GridFieldDetailForm::class);
		$field->setItemRequestClass( ModelAdmin_ItemRequest::class );
		return $form;
	}

	/**
	 * Returns managed models based on permissions of current user
	 */
	public function getManagedModels() {
		$models = $this->stat('managed_models');
		// Normalize models to have their model class in array key
		foreach($models as $k => $v) {
			unset($models[$k]);
			$sng = singleton($v);
			$view = $sng->CanView();
			if($view) {
				$models[$v] = array('title' => $sng->i18n_singular_name());
			}
		}
		return $models;
	}

}

/**
 * @note handle item requests for managed models
 */
class ModelAdmin_ItemRequest extends GridFieldDetailForm_ItemRequest {

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
	 * Provide ability to resubmit a message via a MailgunEvent record
	 */
	public function doTryAgain($data, $form) {
		if($this->record instanceof MailgunEvent) {
			if($submission = $this->record->Resubmit()) {
				// resubmitting will return a new submission record
				$form->sessionMessage('Resubmitted', 'good');
			} else {
				// TODO: maybe some reasons here
				$form->sessionMessage('Failed, could not resubmit.', 'bad');
			}
		}
		return $this->edit( Controller::curr()->getRequest() );
	}

}
