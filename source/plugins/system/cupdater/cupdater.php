<?php

/**
 * @author Daniel Dimitrov <daniel@compojoom.com>
 * @copyright    Copyright (C) 2008 - 2013 compojoom.com. All rights reserved.
 * @license        GNU General Public License version 2 or later
 */

// no direct access
defined('_JEXEC') or die;

/**
 * Joomla! Update notification plugin
 * This plugin checks at specific intervals for new updates
 * and sends a notification if an update is found
 *
 * Special thanks to O.Schwab <service@castle4us.de> for coming with the idea
 * in the first place :)
 *
 * @package        Joomla.Plugin
 * @subpackage    System.cupdater
 */
class plgSystemCupdater extends JPlugin
{
    private $recipients = array();

    /**
     * @return bool
     */
    public function onAfterRender()
    {
        if (!$this->doIHaveToRun()) {
            return false;
        }

//      so we are running??? Then let us load some languages
        $lang = JFactory::getLanguage();
        $lang->load('plg_system_cupdater', JPATH_ADMINISTRATOR, 'en-GB', true);
        $lang->load('plg_system_cupdater', JPATH_ADMINISTRATOR, $lang->getDefault(), true);
        $lang->load('plg_system_cupdater', JPATH_ADMINISTRATOR, null, true);


        // clear the cache - otherwise we get problems in backend
        $cache = JFactory::getCache();
        $cache->clean('com_plugins');

		// note for Joomla 3
		// the JHttpTransportCurl class is throwing an exception when it is unable to get the content out
		// of an url. The exception is not caught in the Joomla updater class and this leads to a nasty
		// exception thrown out at the user. That is why we catch it here and send an email to the
		// people that should be notified about that
		// normally joomla should disable such urls, but since they don't react on the Exception this will
		// happen each time the update script is run
		// that is why the administrator will need to find the url causing the problem and disable it for the server
		try {
			$updates = $this->checkForUpdates();
		} catch (UnexpectedValueException $e) {
			// let us send a mail to the user
			$title = JText::_('PLG_CUPDATER_ERROR_UPDATE');
			$body = JText::_('PLG_CUPDATER_ERROR_UPDATE_DESC');
			$body .= "\n" . JText::_('PLG_CUPDATER_DISCLAIMER') . "\n";
			$this->sendMail($title, $body);
			$this->setLastRunTimestamp();
			// and exit...
			return false;
		}

        if (count($updates)) {
            if (count($updates) == 1) {
                $title = JText::_('PLG_CUPDATER_FOUND_ONE_UPDATE');
            } else {
                $title = JText::sprintf('PLG_CUPDATER_FOUND_UPDATES', count($updates));
            }

            $body = '';
            foreach ($updates as $value) {
                $body .= JText::_('PLG_CUPDATER_UPDATE_FOR') . ': '
                    . $value->name . ' ' . JText::_('PLG_CUPDATER_VERSION') . ': '
                    . $value->version . ' ' . JText::_('PLG_CUPDATER_FOUND') . "\n";
            }
            $body .= "\n" . JText::_('PLG_CUPDATER_TO_APPLY_UPDATE') . ': '
                . JURI::base() . ' ' . JText::_('PLG_CUPDATER_AND_GO_TO') . "\n";

        } else if ((!count($updates)) && (($this->params->get('mailto_noresult') == 0))) {
            $title = JText::_('PLG_CUPDATER_FOUND_NO_UPDATE_TITLE');
            $body = JText::_('PLG_CUPDATER_FOUND_NO_UPDATE') . ' ' . JURI::base() . "\n";
        }

        if (count($updates) ||
            ((!count($updates)) && (($this->params->get('mailto_noresult') == 0)))) {

            $body .= $this->nextUpdateText();
            $body .= "\n" . JText::_('PLG_CUPDATER_DISCLAIMER') . "\n";

            $this->sendMail($title, $body);
        }

        $this->setLastRunTimestamp();

        return true;
    }

    /**
     * @return string - our next update text
     */
    private function nextUpdateText()
    {
        $body = JText::_("PLG_CUPDATER_NEXT_UPDATE_CHECK_WILL_BE") . ' ';

        switch ($this->params->get('notification_period')) {
            case "24":
                $body .= JText::_('PLG_CUPDATER_TOMORROW') . '.';
                break;
            case "168":
                $body .= JText::_('PLG_CUPDATER_IN_ONE_WEEK') . '.';
                break;
            case "336":
                $body .= JText::sprintf('PLG_CUPDATER_IN_WEEKS', 2) . '.';
                break;
            case "672":
                $body .= JText::sprintf('PLG_CUPDATER_WEEKS', 4) . '.';
                break;
        }

        return $body;
    }

    /**
     * @param $title - the title of the mail
     * @param $body - the body of the mail
     */
    private function sendMail($title, $body)
    {
        $app = JFactory::getApplication();
        $recipients = $this->getRecipients();
        $mail = JFactory::getMailer();
        $mail->addRecipient($recipients);
        $mail->setSender(array($app->getCfg('mailfrom'), $app->getCfg('fromname')));
        $mail->setSubject($title);
        $mail->setBody($body);
        $mail->Send();
    }

    /**
     *
     * @return array - all recepients
     */
    private function getRecipients()
    {
        $emails = array();

        if (!count($this->recipients)) {
            if ($this->params->get('mailto_admins', 0)) {
                $groups = $this->params->get('mailto_admins');
                $tmp_emails = $this->getEmailsForUsersInGroups($groups);

                if (!is_array($emails)) {
                    $emails = array();
                }
                $emails = array_merge($tmp_emails, $emails);
            }

            if ((int)$this->params->get('mailto_custom') == 1 && $this->params->get('custom_email') != "") {
                $tmp_emails = explode(';', $this->params->get('custom_email'));

                if (!is_array($emails)) {
                    $emails = array();
                }
                $emails = array_merge($tmp_emails, $emails);
            }

            $tmp = array();
            foreach ($emails AS $r)
            {
                if (in_array($r, $tmp) || trim($r) == "") {
                    continue;
                }
                else {
                    $this->recipients[] = $r;
                    $tmp[] = $r;
                }
            }
        }

        return $this->recipients;
    }

    /**
     * checks if there are updates available and
     * @return object - the updates
     */
    private function checkForUpdates()
    {
        jimport('joomla.updater.updater');
        $updater = JUpdater::getInstance();
        $updater->findUpdates(0, 0);

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*');
        $query->from('#__updates')->where('extension_id != 0');
        $db->setQuery($query);

        return $db->loadObjectList();
    }

    /**
     * "Do I have to run?" - the age old question. Let it be answered by checking the
     * last execution timestamp, stored in the component's configuration.
     *
     * this function is copied from the asexpirationnotify plugin so all credits go to
     * Nicholas K. Dionysopoulos / AkeebaBackup.com
     * @return bool
     */
    private function doIHaveToRun()
    {
        $params = $this->params;
        $lastRunUnix = $params->get('plg_cupdate_timestamp', 0);
        $dateInfo = getdate($lastRunUnix);
        $nextRunUnix = mktime(0, 0, 0, $dateInfo['mon'], $dateInfo['mday'], $dateInfo['year']);
        $nextRunUnix += $params->get('notification_period', 24) * 3600;
        $now = time();
        return ($now >= $nextRunUnix);
    }

    /**
     * Saves the timestamp of this plugin's last run
     * this function is copied from the asexpirationnotify plugin so all credits go to
     * Nicholas K. Dionysopoulos / AkeebaBackup.com
     *
     */
    private function setLastRunTimestamp()
    {
        $lastRun = time();
        $params = $this->params;
        $params->set('plg_cupdate_timestamp', $lastRun);

        $db = JFactory::getDBO();

        $data = $params->toString('JSON');
        $query = $db->getQuery(true);
        $query->update('#__extensions');
        $query->set('params = ' . $db->Quote($data));
        $query->where('element = "cupdater" AND type = "plugin"');
        $db->setQuery($query);
        $db->query();
    }

    /**
     *
     * @param array $groups - the user group
     * @return mixed
     */
    private function getEmailsForUsersInGroups(array $groups)
    {

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('a.email');
        $query->from('#__user_usergroup_map AS map');
        $query->leftJoin('#__users AS a ON a.id = map.user_id');
        $query->where('map.group_id IN (' . implode(',', $groups) . ')');

        $db->setQuery($query);
        return $db->loadColumn();
    }

}