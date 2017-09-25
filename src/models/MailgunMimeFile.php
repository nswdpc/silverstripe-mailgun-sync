<?php
/**
 * @author James Ellis
 * @note provides permissions on the File
 */
class MailgunMimeFile extends \File {
	
	/**
	 * Only admin accounts can view this file
	 *
	 * @return boolean
	 */
	public function canView($member = null) {
		if(!$member) $member = Member::currentUser();
		return \Permission::check('ADMIN', 'any', $member);
	}

	/**
	 * Only admin accounts can edit this file
	 *
	 * @return boolean
	 */
	public function canEdit($member = null) {
		if(!$member) $member = Member::currentUser();
		return \Permission::check('ADMIN', 'any', $member);
	}
	
	/**
	 * Only admin accounts can create this file
	 *
	 * @return boolean
	 */
	public function canCreate($member = null) {
		if(!$member) $member = Member::currentUser();
		return \Permission::check('ADMIN', 'any', $member);
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
