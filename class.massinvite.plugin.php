<?php
use Vanilla\Invalid;

class MassInvitePlugin extends Gdn_Plugin
{
    private const INVITE_CHARSET = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const INVITE_LENGTH = 24;
    private const INVITE_REGEX = '/^[' . self::INVITE_CHARSET . ']{' . self::INVITE_LENGTH . '}$/';

    private $lastCampaign;

    /**
     * Add CSS and JS to all pages.
     */
    public function base_render_before($sender)
    {
        $sender->addCssFile('transient-errors.css', 'plugins/MassInvite');
        $sender->addJsFile('transient-errors.js', 'plugins/MassInvite');
	}

	//
	// ADMIN CONSOLE
	//

	/**
	 * Add our settings page to the admin menu.
	 */
	public function base_getAppSettingsMenuItems_handler($sender)
	{
		$menu =& $sender->EventArguments['SideMenu'];

		$menu->addLink(
			'Users',
			t('Mass Invite'),
			'settings/massinvite',
			'Garden.Settings.Manage'
		);
	}

	/**
	 * Settings page master controller.
	 */
	public function settingsController_massInvite_create($sender, $args)
	{
	    switch($args[0] ?? null) {
            case 'createcampaign':
                return $this->settingsController_massInvite_createCampaign_create($sender);
	    }
	    $sender->permission('Garden.Settings.Manage');

	    $sender->setHighlightRoute('settings/massinvite');
	    $sender->setData('Title', t('Mass Invite'));
	    $sender->setData('Code', $this->generateSecret());
	    $sender->render('settings', '', 'plugins/MassInvite');
	}

	/**
	 * Settings page sub-controller to create a campaign.
	 */
	public function settingsController_massInvite_createCampaign_create($sender)
	{
	    $sender->permission('Garden.Settings.Manage');
	    $sender->setData('Title', t('Create Mass Invite Campaign'));

	    $sender->render('createcampaign', '', 'plugins/MassInvite');
	}

	//
	// USER REGISTRATION
	//

	/**
	 * Route to extend the entry controller with an "invite" route, which
	 * processes an incoming invitation code, validating it and storing it in
	 * the user's session. If the invitation code is not valid, the user is
	 * redirected to the front page of the forum with an error message.
	 *
	 * @route /entry/invite/:code
	 */
	public function entryController_invite_create($sender, $args)
	{
	    Gdn::session()->start();
	    if (isset($args[0]) && preg_match(self::INVITE_REGEX, $args[0])) {
	        if ($this->campaignCanRegister($args[0])) {
                Gdn::session()->stash('massInviteCode', $args[0]);
                redirectTo(Gdn::request()->url('/entry/register', true, false));
            }
            else {
                $this->queueTransientMessage('error', 'The invitation code you entered is invalid or expired.');
            }
	    }

	    redirectTo(Gdn::request()->url('/', true, false));
	}

	/**
	 * Validation handler for the registration process. Pulls the invitation
	 * code from the submitted form and validates it. Bounces the user back to
	 * the registration form if the invitation was revoked since they initiated
	 * the registration process.
	 */
	public function entryController_registerValidation_handler($sender)
	{
	    $sender->UserModel->Validation->addRule('ValidInvitation', function($value, $fieldInfo, array $row) use ($sender)
	        {
	            if (!$this->campaignCanRegister($value)) {
	                return new Invalid(
	                    "The invitation you used is invalid or expired."
                    );
	            }
	        });

	    $sender->UserModel->Validation->applyRule('MassInviteCode', 'ValidInvitation');
	}

	public function userModel_afterInsertUser_handler(\UserModel $sender, array $args)
	{
	    // FIXME this is a really terrible way to track state, but $args comes
	    // from the untouchable $_Schema instance variable in UserModel.
	    if ($this->lastCampaign) {
            $px = Gdn::database()->DatabasePrefix;

            // Associate user with the campaign
            $query = "UPDATE {$px}User SET CampaignID = :campaignID WHERE UserID = :userID;";
            $values = [
                'campaignID' => $this->lastCampaign['CampaignID'],
                'userID' => $args['InsertUserID'],
            ];
            Gdn::database()->query($query, $values);

            // Increment # of uses on the campaign
            $query = "UPDATE {$px}MassInviteCampaigns SET uses = uses + 1 WHERE CampaignID = :campaignID;";
            $values = [
                'campaignID' => $this->lastCampaign['CampaignID'],
            ];
            Gdn::database()->query($query, $values);
        }
	}

	/**
	 * Pre-validation for the registration page. This hook blocks access to the
	 * registration page if the user does not have an invite code in their
	 * session. This is needed because $args isn't correctly populated here.
	 */
	public function entryController_extendedRegistrationFields_handler($sender)
	{
	    if ($sender->Form->isPostBack()) {
	        $massInviteCode = $sender->Form->getFormValue('MassInviteCode');
	    }
	    else if ($code = Gdn::session()->stash('massInviteCode', null)) {
            $massInviteCode = $code;
        }
        else {
            $code = array_reverse(preg_grep('/^.+$/', explode('/', $_SERVER['REQUEST_URI'])))[0];
            if (preg_match(self::INVITE_REGEX, $code)) {
                $massInviteCode = $code;
            }
        }

	    if (!is_string($massInviteCode)) {
	        $this->queueTransientMessage('error', 'You need to be invited to register on this site.');
	        redirectTo(Gdn::request()->url('/', true, false));
	    }

        if (!$this->campaignCanRegister($massInviteCode)) {
	        $this->queueTransientMessage('error', 'The invitation code you entered is invalid or expired.');
	        redirectTo(Gdn::request()->url('/', true, false));
	    }

	    echo $sender->Form->input('MassInviteCode', 'hidden', ['value' => $massInviteCode]);
	}

	/**
	 * Header overrides for the signIn controller. Browsers sometimes cache
	 * this causing invitation related errors not to be displayed.
	 */
	public function entryController_signIn_before($sender)
	{
	    $sender->setHeader('Cache-Control', 'no-cache');
	    $sender->setHeader('Pragma', 'no-cache');
	    $sender->setHeader('Expires', 'Thu, 1 Jan 1970 00:00:00 +00:00');
	}

	/**
	 * Event handler to inject errors from the session into the page body.
	 * Despite being at the end of the DOM, these are displayed at the top of
	 * the window using a CSS fixed positioning rule.
	 */
	public function base_AfterBody_handler($sender)
	{
	    $messages = Gdn::session()->stash('mi_transient', null, true) ?: [];
	    if (count($messages) < 1) {
	        return;
	    }

	    echo '<div class="FloatingError">';
	    foreach ($messages as $msg) {
	        echo <<<EOF
	            <div class="Messages Errors">
	                <ul>
	                    <li>{$msg['message']}</li>
                    </ul>
	            </div>
EOF;
	    }
	    echo '</div>';
	}

	/**
	 * Queue an error message to be shown on the next loaded page.
	 */
	private function queueTransientMessage(string $type, string $message)
	{
	    $messages = Gdn::session()->stash('mi_transient', null) ?: [];
	    $messages[] = [
	        'type' => $type,
	        'message' => $message,
        ];
        Gdn::session()->stash('mi_transient', $messages);
	}

	/**
	 * Check if a campaign is currently eligible to register.
	 *
	 * @param string
	 *   Campaign secret code
	 */
	private function campaignCanRegister(string $secret): bool
	{
	    $campaign = $this->fetchCampaign($secret);
	    if ($campaign === null) {
	        return false;
	    }

	    if ($campaign['Active'] !== 1) {
	        return false;
	    }

	    if ($campaign['NotBefore'] !== null && time() < strtotime($campaign['NotBefore'])) {
	        return false;
	    }

	    if ($campaign['NotAfter'] !== null && time() > strtotime($campaign['NotAfter'])) {
	        return false;
	    }

	    if ($campaign['MaximumUses'] !== null && $campaign['Uses'] > $campaign['MaximumUses']) {
	        return false;
	    }

	    return true;
	}

	/**
	 * Fetch a campaign from the database.
	 *
	 * @param string
	 *   Campaign secret
	 * @return ?array
	 */
	private function fetchCampaign(string $secret)
	{
	    $result = Gdn::sql()->select([
	            'CampaignID',
	            'NotBefore',
	            'NotAfter',
	            'MaximumUses',
	            'Uses',
	            'Active'
            ])
            ->from('MassInviteCampaigns')
            ->where('Secret', $secret)
            ->get()
            ->resultArray();

        if (count($result) === 1) {
            return $this->lastCampaign = $result[0];
        }

        return null;
	}

	public function structure()
	{
	    $structure = Gdn::structure();
	    $structure->table('MassInviteCampaigns')
	        ->primaryKey('CampaignID')
	        ->column('Name', 'varchar(255)', false, false)
	        ->column('Secret', 'char(' . self::INVITE_LENGTH . ')', false, false)
	        ->column('NotBefore', 'datetime', true, false)
	        ->column('NotAfter', 'datetime', true, false)
	        ->column('MaximumUses', 'int(11)', true, false)
	        ->column('Uses', 'int(11)', 0, false)
	        ->column('Active', 'tinyint(1)', 0, false)
	        ->set(true, false);

        $structure->table('User')
            ->column('CampaignID', 'int(11)', true, false)
            ->set(false, false);
	}

	public function setup()
	{
	    $this->structure();
	}

	/**
	 * Generate a new, random invite secret.
	 *
	 * @param int
	 *   Length. Don't change this, various things in the database and in this
	 *   file require it to be static.
	 */
	private function generateSecret($length = self::INVITE_LENGTH): string
	{
	    $charset = self::INVITE_CHARSET;
	    $buf = '';
	    for ($i = 0; $i < $length; $i++) {
	        $buf .= substr($charset, mt_rand(0, strlen($charset)-1), 1);
	    }
	    return $buf;
	}
}
