<?php
namespace NSWDPC\Messaging\Mailgun;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldDetailForm;

/**
 * Admin for Mailgun
 */
class MailgunModelAdmin extends ModelAdmin
{
    private static $url_segment = 'mailgun';

    private static $menu_title = 'Mailgun';

    private static $managed_models = [
        MailgunEvent::class,
    ];

    public function getEditForm($id = null, $fields = null)
    {

        $form = parent::getEditForm($id, $fields);

        $grid = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
        if($grid instanceof GridField) {
            $config = $grid->getConfig();

            $config->removeComponentsByType(GridFieldAddNewButton::class);
            $config->removeComponentsByType(GridFieldPrintButton::class);

            if(! Permission::check( MailgunEvent::PERMISSIONS_DELETE, 'any', Member::currentUser()) ) {
                $config->removeComponentsByType(GridFieldEditButton::class);
                $config->removeComponentsByType(GridFieldDeleteAction::class);
            }
        }

        return $form;
    }
}
