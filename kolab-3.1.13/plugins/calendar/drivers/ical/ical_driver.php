<?php

/**
 * iCalendar driver for the Calendar plugin
 *
 * @author Daniel Morlock <daniel.morlock@awesome-it.de>
 *
 * Copyright (C) 2013, Awesome IT GbR <info@awesome-it.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

require_once(dirname(__FILE__) . '/../database/database_driver.php');
require_once(dirname(__FILE__) . '/ical_sync.php');

/**
 * TODO
 * - Database constraint: obj_id, obj_type must be unique.
 * - Postgresql, Sqlite scripts.
 *
 */

class ical_driver extends database_driver
{
    const OBJ_TYPE_ICAL = "ical";
    const OBJ_TYPE_VEVENT = "vevent";
    const OBJ_TYPE_VTODO = "vtodo";

    private $db_calendars = 'calendars';
    private $db_calendars_ical_props = 'calendars_ical_props';
    private $db_events = 'vevent';
    private $db_events_ical_props = 'vevent_ical_props';

    private $cal;
    private $rc;

    static private $debug = null;

    // features this backend supports
    public $alarms = true;
    public $attendees = true;
    public $freebusy = false;
    public $attachments = false;
    public $alarm_types = array('DISPLAY', 'EMAIL');
    public $readonly = true;
    public $last_error;

    private $sync_clients = array();

    // Min. time period to wait until sync check.
    private $sync_period = 86400; // seconds // ToDo: Make configurable on a per user level

    /**
     * Default constructor
     */
    public function __construct($cal)
    {
        $this->cal = $cal;
        $this->rc = $cal->rc;
        $db = $this->rc->get_dbh();
        $this->db_calendars = $this->rc->config->get('db_table_calendars', $db->table_name($this->db_calendars));
        $this->db_calendars_ical_props = $this->rc->config->get('db_table_calendars_ical_props', $db->table_name($this->db_calendars_ical_props));
        $this->db_events = $this->rc->config->get('db_table_events', $db->table_name($this->db_events));
        $this->db_events_ical_props = $this->rc->config->get('db_table_events_ical_props', $db->table_name($this->db_events_ical_props));
        $this->db_attachments = $this->rc->config->get('db_table_attachments', $db->table_name($this->db_attachments));

        parent::__construct($cal);

        // Set debug state
        if (self::$debug === null)
            self::$debug = $this->rc->config->get('calendar_ical_debug', false);

        $this->_init_sync_clients();
    }

    /**
     * Helper method to log debug msg if debug mode is enabled.
     */
    static public function debug_log($msg)
    {
        if (self::$debug === true)
            rcmail::console(__CLASS__.': '.$msg);
    }

    /**
     * Sets ical properties.
     *
     * @param int $obj_id
     * @param int One of OBJ_TYPE_ICAL, OBJ_TYPE_VEVENT or OBJ_TYPE_VTODO.
     * @param array List of ical properties:
     *    url: Absolute URL to iCAL resource.
     *   user: Optional authentication username.
     *   pass: Optional authentication password.
     *
     * @return True on success, false otherwise.
     */
    private function _set_ical_props($obj_id, $obj_type, array $props)
    {
        if ($obj_type == 'ical')
        {
            $db_table = $this->db_calendars_ical_props;
        } else {
            $db_table = $this->db_events_ical_props;
        }
        
        $this->_remove_ical_props($obj_id, $obj_type);

        $password = isset($props["pass"]) ? $props["pass"] : null;
        if ($password) {
            $e = new Encryption(MCRYPT_BlOWFISH, MCRYPT_MODE_CBC);
            $p = $e->encrypt($password, $this->crypt_key);
            $password = base64_encode($p);
        }

        $query = $this->rc->db->query(
            "INSERT INTO " . $db_table . " (obj_id, obj_type, url, user, pass) " .
            "VALUES (?, ?, ?, ?, ?)",
            $obj_id,
            $obj_type,
            $props["url"],
            isset($props["user"]) ? $props["user"] : null,
            $password);

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Gets ical properties.
     *
     * @param int $obj_id
     * @param int One of OBJ_TYPE_ICAL, OBJ_TYPE_VEVENT or OBJ_TYPE_VTODO.
     * @return array List of ical properties or false on error:
     *    url: Absolute URL to iCAL resource.
     *   user: Username for authentication if given, otherwise null.
     *   pass: Password for authentication if given, otherwise null.
     */
    private function _get_ical_props($obj_id, $obj_type)
    {
        if ($obj_type == 'ical')
        {
            $db_table = $this->db_calendars_ical_props;
        } else {
            $db_table = $this->db_events_ical_props;
        }
        
        $result = $this->rc->db->query(
            "SELECT * FROM " . $db_table . " p " .
            "WHERE p.obj_type = ? AND p.obj_id = ? ", $obj_type, $obj_id);

        if ($result && ($prop = $this->rc->db->fetch_assoc($result)) !== false) {
            $password = isset($prop["pass"]) ? $prop["pass"] : null;
            if ($password) {
                $p = base64_decode($password);
                $e = new Encryption(MCRYPT_BlOWFISH, MCRYPT_MODE_CBC);
                $prop["pass"] = $e->decrypt($p, $this->crypt_key);
            }

            return $prop;
        }

        return false;
    }

    /**
     * Removes ical properties.
     *
     * @param int $obj_id
     * @param int One of OBJ_TYPE_ICAL, OBJ_TYPE_VEVENT or OBJ_TYPE_VTODO.
     * @return True on success, false otherwise.
     */
    private function _remove_ical_props($obj_id, $obj_type)
    {
        if ($obj_type == 'ical')
        {
            $db_table = $this->db_calendars_ical_props;
        } else {
            $db_table = $this->db_events_ical_props;
        }
        
        $query = $this->rc->db->query(
            "DELETE FROM " . $db_table . " " .
            "WHERE obj_type = ? AND obj_id = ? ", $obj_type, $obj_id);

        return $this->rc->db->affected_rows($query);
    }

    /**
     * Determines whether the given calendar is in sync regarding the configured sync period.
     *
     * @param int Calender id.
     * @return boolean True if calendar is in sync, true otherwise.
     */
    private function _is_synced($cal_id)
    {
        $now = date(self::DB_DATE_FORMAT);
        $last = date(self::DB_DATE_FORMAT, time() - $this->sync_period);
        
        // Atomic sql: Check for exceeded sync period and update last_change.
        $query = $this->rc->db->query(
            "UPDATE " . $this->db_calendars_ical_props . " " .
            "SET last_change = ? " .
            "WHERE obj_id = ? AND obj_type = ? " .
            "AND last_change <= ?",
            $now, $cal_id, self::OBJ_TYPE_ICAL, $last);

        if($query->rowCount() > 0)
        {
            $is_synced = $this->sync_clients[$cal_id]->is_synced();
            self::debug_log("Calendar \"$cal_id\" ".($is_synced ? "is in sync" : "needs update").".");
            return $is_synced;
        }
        else
        {
            self::debug_log("Sync period active: Assuming calendar \"$cal_id\" to be in sync.");
            return true;
        }
    }
    
    /**
     * Check connection to a iCal ressource
     *
     * @param array Indexed array user, pass, url
     * @return boolean true or false
     */
    private function _check_connection($prop)
    {
        $prop['url'] = self::_encode_url($prop['url']);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $prop['url']);
        curl_setopt($ch, CURLOPT_USERPWD, $prop['user'] . ':' . $prop['pass']);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_exec($ch);
        if(!curl_errno($ch))
        {
            $info = curl_getinfo($ch);
            $code = $info['http_code'];
            if(substr($code, 0, 1) == 2)
            {
                $success = true;
            }
            else
            {
                $success = false;
                $this->last_error = $this->rc->gettext('calendar.connectionfailed');
            }
        }
        else
        {
            $success = false;
            $this->last_error = $this->rc->gettext('calendar.connectionfailed');
        }
        curl_close($ch);
        return $success;
    }

    /**
     * Get a list of available calendars from this source
     *
     * @param bool $active Return only active calendars
     * @param bool $personal Return only personal calendars
     *
     * @return array List of calendars
     */
    public function list_calendars($active = false, $personal = false)
    {
        // Read calendars from database and remove those without iCAL props.
        $calendars = array();
        foreach(parent::list_calendars($active, $personal) as $id => $cal)
        {
            // iCal calendars are readonly!
            $cal["readonly"] = true;

            // But name should be editable!
            $cal["editable_name"] = true;

            if($this->_get_ical_props($id, self::OBJ_TYPE_ICAL) !== false)
                $calendars[$id] = $cal;
        }

        return $calendars;
    }

    /**
     * Initializes calendar sync clients.
     *
     * @param array $cal_ids Optional list of calendar ids. If empty, caldav_driver::list_calendars()
     *              will be used to retrieve a list of calendars.
     */
    private function _init_sync_clients($cal_ids = array())
    {
        if(sizeof($cal_ids) == 0) $cal_ids = array_keys($this->list_calendars());
        foreach($cal_ids as $cal_id)
        {
            $props = $this->_get_ical_props($cal_id, self::OBJ_TYPE_ICAL);
            if ($props !== false) {
                self::debug_log("Initialize sync client for calendar " . $cal_id);
                $this->sync_clients[$cal_id] = new ical_sync($cal_id, $props);
            }
        }
    }

    /**
     * Encodes directory- and filenames using rawurlencode().
     *
     * @see http://stackoverflow.com/questions/7973790/urlencode-only-the-directory-and-file-names-of-a-url
     * @param string Unencoded URL to be encoded.
     * @return Encoded URL.
     */
    private static function _encode_url($url)
    {
        // Don't encode if "%" is already used.
        if (strstr($url, "%") === false) {
            return preg_replace_callback('#://([^/]+)/([^?]+)#', function ($matches) {
                return '://' . $matches[1] . '/' . join('/', array_map('rawurlencode', explode('/', $matches[2])));
            }, $url);
        } else return $url;
    }

    /**
     * Callback function to produce driver-specific calendar create/edit form
     *
     * @param string Request action 'form-edit|form-new'
     * @param array  Calendar properties (e.g. id, color)
     * @param array  Edit form fields
     *
     * @return string HTML content of the form
     */
    public function calendar_form($action, $calendar, $formfields)
    {
        $cal_id = $calendar["id"];
        $props = $this->_get_ical_props($cal_id, self::OBJ_TYPE_ICAL);

        $input_ical_url = new html_inputfield(array(
            "name" => "ical_url",
            "id" => "ical_url",
            "size" => 45,
            "placeholder" => "http://dav.mydomain.tld/calendars/john.doh@mydomain.tld",
        ));

        $formfields["ical_url"] = array(
            "label" => $this->cal->gettext("url"),
            "value" => $input_ical_url->show($props["url"]),
            "id" => "ical_url",
        );

        $input_ical_user = new html_inputfield( array(
            "name" => "ical_user",
            "id" => "ical_user",
            "size" => 30,
            "placeholder" => "john.doh@mydomain.tld",
        ));

        $formfields["ical_user"] = array(
            "label" => $this->cal->gettext("username"),
            "value" => $input_ical_user->show($props["user"]),
            "id" => "ical_user",
        );

        $input_ical_pass = new html_passwordfield( array(
            "name" => "ical_pass",
            "id" => "ical_pass",
            "size" => 30,
            "placeholder" => "******",
        ));

        $formfields["ical_pass"] = array(
            "label" => $this->cal->gettext("password"),
            "value" => $input_ical_pass->show(null), // Don't send plain text password to GUI
            "id" => "ical_pass",
        );

        return parent::calendar_form($action, $calendar, $formfields);
    }

    /**
     * Extracts ical properties and creates calendar.
     *
     * @see database_driver::create_calendar()
     */
    public function create_calendar($prop)
    {
        $props['user'] = $prop['ical_user'];
        $props['pass'] = $prop['ical_pass'];
        $props['url']  = $prop['ical_url'];
        
        if(!$this->_check_connection($props)) {
          return false;
        }
        
        if(!isset($props['color']))
            $props['color'] = 'cc0000';
            
        $result = false;
        if (($obj_id = parent::create_calendar($prop)) !== false) {
            $props = array(
                'url' => self::_encode_url($prop['ical_url']),
                'user' => $prop['ical_user'],
                'pass' => $prop['ical_pass']
            );

            $result = $this->_set_ical_props($obj_id, self::OBJ_TYPE_ICAL, $props);
        }

        // Re-read calendars to internal buffer.
        $this->_read_calendars();

        // Initial sync of newly created calendars.
        $this->_init_sync_clients(array($obj_id));
        $this->_sync_calendar($obj_id);

        return $result;
    }

    /**
     * Extracts ical properties and updates calendar.
     *
     * @see database_driver::edit_calendar()
     */
    public function edit_calendar($prop)
    {
        $prev_prop = $this->_get_ical_props($prop['id'], self::OBJ_TYPE_ICAL);
        
        $props['user'] = $prop['ical_user'];
        $props['pass'] = $prop['ical_pass'] ? $prop['ical_pass'] : $prev_prop['pass'];
        $props['url']  = $prop['ical_url'];
        
        if(!$this->_check_connection($props)) {
          return false;
        }
        
        if (parent::edit_calendar($prop) !== false) {

            // Don't change the password if not specified
            if(!$prop['ical_pass']) {
                if($prev_prop) $prop['ical_pass'] = $prev_prop['pass'];
            }

            return $this->_set_ical_props($prop['id'], self::OBJ_TYPE_ICAL, array(
                'url' => self::_encode_url($prop['ical_url']),
                'user' => $prop['ical_user'],
                'pass' => $prop['ical_pass']
            ));
        }

        return false;
    }

    /**
     * Deletes ical properties and the appropriate calendar.
     *
     * @see database_driver::remove_calendar()
     */
    public function remove_calendar($prop)
    {
        if (parent::remove_calendar($prop)) {
            $this->_remove_ical_props($prop["id"], self::OBJ_TYPE_ICAL);
            return true;
        }

        return false;
    }

    /**
     * Performs ical updates on given events.
     *
     * @param array ical and event properties to update. See ical_sync::get_updates().
     * @return array List of event ids.
     */
    private function _perform_updates($updates)
    {
        $event_ids = array();

        $num_created = 0;
        $num_updated = 0;

        foreach ($updates as $update) {
            // local event -> update event
            if (isset($update["local_event"])) {
                // let edit_event() do all the magic
                if (parent::edit_event($update["remote_event"] + (array)$update["local_event"])) {

                    $event_id = $update["local_event"]["id"];
                    array_push($event_ids, $event_id);

                    $num_updated++;

                    self::debug_log("Updated event \"$event_id\".");

                } else {
                    self::debug_log("Could not perform event update: " . print_r($update, true));
                }
            } // no local event -> create event
            else {
                $event_id = parent::new_event($update["remote_event"]);
                if ($event_id) {

                    array_push($event_ids, $event_id);

                    $num_created++;

                    self::debug_log("Created event \"$event_id\".");

                } else {
                    self::debug_log("Could not perform event creation: " . print_r($update, true));
                }
            }
        }

        self::debug_log("Created $num_created new events, updated $num_updated event.");
        return $event_ids;
    }

    /**
     * Return all events from the given calendar.
     *
     * @param int Calendar id.
     * @return array
     */
    private function _load_all_events($cal_id)
    {
        return parent::load_events(0, PHP_INT_MAX, null, array($cal_id), 0);
    }

    /**
     * Synchronizes events of given calendar.
     *
     * @param int Calendar id.
     */
    private function _sync_calendar($cal_id)
    {
        self::debug_log("Syncing calendar id \"$cal_id\".");

        $cal_sync = $this->sync_clients[$cal_id];
        $events = array();

        // Ignore recurrence events
        foreach ($this->_load_all_events($cal_id) as $event) {
            if ($event["recurrence_id"] == 0) {
                array_push($events, $event);
            }
        }

        $updates = $cal_sync->get_updates($events);
        if($updates)
        {
            list($updates, $synced_event_ids) = $updates;
            $updated_event_ids = $this->_perform_updates($updates);

            // Delete events that are not in sync or updated.
            foreach ($events as $event) {
                if (array_search($event["id"], $updated_event_ids) === false &&
                    array_search($event["id"], $synced_event_ids) === false)
                {
                    // Assume: Event was not updated, so delete!
                    parent::remove_event($event, true);
                    self::debug_log("Remove event \"" . $event["id"] . "\".");
                }
            }
        }

        self::debug_log("Successfully synced calendar id \"$cal_id\".");
    }


    /**
     * Synchronizes events and loads them.
     *
     * @see database_driver::load_events()
     */
    public function load_events($start, $end, $query = null, $cal_ids = null, $virtual = 1, $modifiedsince = null, $force = false)
    {
        foreach ($this->sync_clients as $cal_id => $cal_sync) {
            if ($force || !$this->_is_synced($cal_id))
                $this->_sync_calendar($cal_id);
        }
        if ($force) {
            return true;
        }
        else {
            return parent::load_events($start, $end, $query, $cal_ids, $virtual, $modifiedsince);
        }
    }

    public function new_event($event)
    {
        return false;
    }

    public function edit_event($event, $old_event = null)
    {
        return false;
    }

    public function remove_event($event, $force = true)
    {
        return false;
    }
    
    /**
     * Get a list of pending alarms to be displayed to the user
     *
     * @see calendar_driver::pending_alarms()
     */
    public function pending_alarms($time, $calendars = null)
    {
        // handled by database driver (don't return duplicates)
        return array();
    }
}
