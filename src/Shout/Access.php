<?php

namespace Shoutbox\Shout;

use Concrete\Core\User\Group\Group;
use Concrete\Core\User\User;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Who may read, shout and moderate: group rules from the settings; administrators always moderate.
 */
class Access
{
    /** @var Settings */
    protected $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function mayRead(User $user): bool
    {
        return $this->settings->isEnabled() && ($this->isAdmin($user) || $this->inGroups($user, $this->settings->getReadGroupIDs()));
    }

    public function mayShout(User $user): bool
    {
        return $this->settings->isEnabled() && $user->isRegistered()
            && ($this->isAdmin($user) || $this->inGroups($user, $this->settings->getShoutGroupIDs()));
    }

    public function canModerate(User $user): bool
    {
        return $this->settings->isEnabled() && $user->isRegistered()
            && ($this->isAdmin($user) || $this->inGroups($user, $this->settings->getModeratorGroupIDs()));
    }

    /**
     * Sender label of an announcement: the first moderator group the user belongs to ("Catering"), else the
     * display name.
     */
    public function moderatorLabel(User $user): string
    {
        foreach ($this->settings->getModeratorGroupIDs() as $gID) {
            $group = Group::getByID($gID);
            if ($group && $user->inGroup($group)) {
                return (string) $group->getGroupDisplayName(false);
            }
        }
        $info = $user->getUserInfoObject();

        return $info ? (string) $info->getUserDisplayName() : (string) $user->getUserName();
    }

    public static function isAdmin(User $user): bool
    {
        if (!$user->isRegistered()) {
            return false;
        }
        if ($user->isSuperUser()) {
            return true;
        }
        $admins = Group::getByID(ADMIN_GROUP_ID);

        return $admins && $user->inGroup($admins);
    }

    /**
     * @param int[] $gIDs
     */
    protected function inGroups(User $user, array $gIDs): bool
    {
        foreach ($gIDs as $gID) {
            if ($gID === GUEST_GROUP_ID) {
                return true;
            }
            if ($gID === REGISTERED_GROUP_ID) {
                if ($user->isRegistered()) {
                    return true;
                }
                continue;
            }
            $group = Group::getByID($gID);
            if ($group && $user->isRegistered() && $user->inGroup($group)) {
                return true;
            }
        }

        return false;
    }
}
