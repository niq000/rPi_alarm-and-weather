<?php

include_once('mpc.php');

define('APP_PATH', dirname(__DIR__) . '/');

class Alarm extends Mpc {
  //xml google calendar url
	public $calendar_url;

	//json weather url for wunderground.com
	public $weather_url;

	//current unix time
	public $now;
	
  //unix version of $default_time
	public $default_unix;
	
  //don't accept events after this time to trigger an alarm. Also trigger at this time if there are no events (M-F)
	public $default_time;
	
  //trigger an alarm 1 hour (-3600 seconds) before the event (give yourself time to take a shower or whatever)
	public $event_buffer;
	
  //simplexml object containing google calendar data
	public $calendar;
	
  //weather object containing wunderground data
	public $weather;
	
  //sqlite database
	public $db;
	
  //whether the alarm has been triggered or not. A failsafe so it doesn't get setup twice in a row
	public $trigger;
	
  //user data from database
	public $user;

	//string of jibberish to speak when the alarm goes off
	public $espeak;

  //switch if music is on/off
  public $music;

	public function __construct($user_id = null) {
		$this->now = time();
		$this->trigger = false;

		//$this->db = new PDO('sqlite:' . APP_PATH . 'alarm.db');
		$this->db = new PDO('sqlite:' . APP_PATH . 'alarm.db');

		foreach($this->db->query('SELECT * FROM users WHERE id = ' . $user_id) as $user) {
			$this->user = $user;
			$this->calendar_url = $user['xml_calendar_url'];
			$this->weather_url = $user['json_weather_url'];
			$this->event_buffer = $user['event_buffer'];
			$this->default_time = $user['default_wakeup_time'];
			$this->default_unix = strtotime(date('Y-m-d ', time()) . $this->default_time);

			$this->espeak = 'Good morning ' . $this->user['name'] . '! The current time is ' . date('g:i A', $this->now) . '. ';

			$this->checkAlarm();
			$this->parseCalendar();
		}
	}

	//check for new events and save them if they're new
	public function parseCalendar() {
		$calendar_string = file_get_contents($this->calendar_url);
		if (!$this->calendar = simplexml_load_string($calendar_string))
			return false;

		if (!count($this->calendar->entry))
			return false;

		//clear out the events (easiest way to check for events that have changed/been removed)
		$this->db->query('DELETE FROM events WHERE user_id = ' . $this->user['id']);

		foreach ($this->calendar->entry as $event) {
			//parse the date/time that the event takes place
			preg_match('/^When: \w{3} (\w+ \d+), (\d{4}) (\d+:?\d{0,2}\w+) to/', $event->content, $matches);

			//don't get tricked by events with only a date (no time)
			if (!isset($matches[3]))
				continue;

      //get unix time
			$event->trigger_time = $event->actual_time = strtotime($matches[1] . ', ' . $matches[2] . ' ' . $matches[3]);

			//if the event title isnt "alarm", add the buffer time
			if (strtolower($event->title) !== 'alarm')
				$event->trigger_time +=  $this->user['event_buffer'];

			//no need to worry about events in the past
			if ($event->trigger_time <= $this->now) 
				continue;

			//save "alarm" events no matter what time they occur 
			if (strtolower($event->title) === 'alarm') {
				$this->saveEvent($event);
			} else if ( ($event->trigger_time <= strtotime($matches[1] . ' ' . $matches[2] . ' ' . $this->default_time)) ) {
				//only use other events if they happen before the default_time.
        // eg: don't let something at 3pm to set off the alarm
				$this->saveEvent($event);
			}
		}
	}

	public function saveEvent($event) {
		$query = 'INSERT INTO events (id, title, trigger_date, actual_date, user_id) VALUES(\'' . $event->id . '\', \'' . 
      $event->title . '\', \'' . date('Y-m-d H:i:s', (int)$event->trigger_time) . '\', \'' . 
      date('Y-m-d H:i:s', (int)$event->actual_time) . '\', ' . $this->user['id'] . ')';

		if (!$this->db->query($query)) {
			return false;
		}
		return true;
	}

	public function deleteEvent($id) {
		if (!$this->db->query('DELETE FROM events WHERE id = \'' . $id . '\'')) {
			return false;
		}
		return true;
	}

	//check if its time to trigger the alarm
	public function checkAlarm() {
		//get the closest event in the future, and see if we should sound the alarm
		$query = "SELECT * FROM events WHERE trigger_date BETWEEN '" . date('Y-m-d H:i:s', $this->now - 30) . "' AND '" . 
      date('Y-m-d H:i:s', $this->now + 30) . "' AND user_id = " . $this->user['id'] . " ORDER BY trigger_date ASC";

		foreach($this->db->query($query) as $event) {
			if ($event['title'] !== 'alarm')
				$this->espeak .= 'Your first appointment is: ' . $event['title'] . ' at ' . 
          date('g:i A', strtotime($event['actual_date'])) . '. ';
			$this->triggerAlarm($event['id']);
		}

		//no events to trigger the alarm, but what about the default time to go off?
		if (!$this->trigger) {
			//weekday only
			if ( (date('N', $this->now) < 6) && ($this->now >= $this->default_unix - 30) && ($this->now <= $this->default_unix + 30) ) {
				$this->triggerAlarm();
			}
		}
	}

	//we've decided it's time to trigger the alarm, what will the alarm do?
	public function triggerAlarm($id = null) {
		$this->trigger = true;

		if ($id !== null) //nothing to delete
			$this->deleteEvent($id);

    $this->run('volume 0'); //turn volume down so we can fade it in
		$this->run('play');
    $this->fadeIn(20, 50, 30);
		$this->speakWeather();
	}

	public function speakWeather() { 
		if (!$weather_string = file_get_contents($this->weather_url)) {
			$this->espeak .= ' I apologize but I am unable to check the weather.';
		} else {
			$this->weather = json_decode($weather_string);

			$temp = round($this->weather->current_observation->temp_f);
			$conditions = $this->weather->current_observation->weather;
			$visibility = round($this->weather->current_observation->visibility_mi);
			$wind_speed = round($this->weather->current_observation->wind_mph);
			$wind_gust = round($this->weather->current_observation->wind_gust_mph);

			$this->espeak .= ' It is currently ' . $temp . ' degrees. Conditions are ' . $conditions . '. Visibility is ' . 
        $visibility . ' miles. Winds are currently ' . $wind_speed . ' miles per hour, with gusts up to ' . 
        $wind_gust . ' miles per hour.';
		}

    //use stdout & aplay because of a rpi bug?
		shell_exec('espeak -s 130 --stdout "' . $this->espeak . '" | aplay');
    
    //crank the volume up
    if ($this->playing)
      $this->fadeIn(50, 100, 15);
	}
}

?>
