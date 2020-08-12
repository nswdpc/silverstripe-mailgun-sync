<?php
namespace NSWDPC\SilverstripeMailgunSync;

use SilverStripe\Assets\File;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * @author James Ellis
 * @note provides permissions on the File
 */
class MailgunMimeFile extends File {

	/**
	 * Defines the database table name
	 * @var string
	 */
	private static $table_name = 'MailgunMimeFile';

	/**
	 * Only admin accounts can view this file
	 *
	 * @return boolean
	 */
	public function canView($member = null) {
		if(!$member) $member = Member::currentUser();
		return Permission::check('ADMIN', 'any', $member);
	}

	/**
	 * Only admin accounts can edit this file
	 *
	 * @return boolean
	 */
	public function canEdit($member = null) {
		if(!$member) $member = Member::currentUser();
		return Permission::check('ADMIN', 'any', $member);
	}

	/**
	 * Only admin accounts can create this file
	 *
	 * @return boolean
	 */
	public function canCreate($member = null, $context = []) {
		if(!$member) $member = Member::currentUser();
		return Permission::check('ADMIN', 'any', $member);
	}

	/**
	 * Inherit the permissions from canEdit
	 *
	 * @return boolean
	 */
	public function canDelete($member = null) {
		return $this->canEdit($member);
	}
}
