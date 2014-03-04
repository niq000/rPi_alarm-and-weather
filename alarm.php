<?php

define('APP_PATH', '/path/to/rPi_alarm-and-weather/');

class Alarm {
	public $calendar_url; 	//xml google calendar url
	public $weather_url; 	//json weather url for wunderground.com
	public $now; 		//current unix time
	public $default_unix; 	//unix version of $default_time
	public $default_time; 	//don't accept events after this time to trigger an alarm. trigger alarm at this time if there are no events (M-F)
	public $event_buffer; 	//trigger an alarm 1 hour (-3600 seconds) before the event (give yourself time to take a shower or whatever)
	public $calendar; 	//simplexml object containing google calendar data
	public $weather; 	//weather object containing wunderground data
	public $db; 		//sqlite database
	public $trigger; 	//whether the alarm has been triggered or not. A failsafe so it doesn't get setup twice in a row
	public $user;		//user data from database
	public $espeak; 	//string of jibberish to speak when the alarm goes off

	public function __construct($user_id = null) {
		$this->now = time();
		$this->trigger = false;

		$this->db = new PDO('sqlite:' . APP_PATH . 'alarm.db');

		foreach($this->db->query('SELECT * FROM users WHERE id = ' . $user_id) as $user) {
			$this->user = $user;
			$this->calendar_url = $user['xml_calendar_url'];
			$this->weather_url = $user['json_weather_url'];
			$this->event_buffer = $user['event_buffer'];
			$this->default_time = $user['default_wakeup_time'];
			$this->default_unix = strtotime(date('Y-m-d ', time()) . $this->default_time);

			$this->espeak = 'Good morning ' . $this->user['name'] . '! The current time is ' . date('g:i A', $this->now) . '. ';

			$this->parseCalendar();
			$this->checkAlarm();
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

			$event->trigger_time = $event->actual_time = strtotime($matches[1] . ', ' . $matches[2] . ' ' . $matches[3]); //get unix time

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
				//only use other events if the happen before the default_time (eg: don't let something at 3pm to set off the alarm)
				$this->saveEvent($event);
			}
		}
	}

	public function saveEvent($event) {
		if (!$this->db->query('INSERT INTO events (id, title, trigger_date, actual_date, user_id) VALUES(\'' . $event->id . '\', \'' . $event->title . '\', \'' . date('Y-m-d H:i:s', (int)$event->trigger_time) . '\', \'' . date('Y-m-d H:i:s', (int)$event->actual_time) . '\', ' . $this->user['id'] . ')')) {
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
		foreach($this->db->query("SELECT * FROM events WHERE trigger_date BETWEEN '" . date('Y-m-d H:i:s', $this->now - 30) . "' AND '" . date('Y-m-d H:i:s', $this->now + 30) . "' AND user_id = " . $this->user['id'] . " ORDER BY trigger_date ASC") as $event) {
				if ($event['title'] !== 'alarm')
					$this->espeak .= 'Your first appointment is: ' . $event['title'] . ' at ' . date('g:i A', strtotime($event['actual_date'])) . '. ';
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

		$this->playMusic();
		sleep(30);
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

			$this->espeak .= ' It is currently ' . $temp . ' degrees. Looks like ' . $conditions . '. Visibility is ' . $visibility . ' miles. Winds are currently ' . $wind_speed . ' miles per hour, with gusts up to ' . $wind_gust . ' miles per hour.';
		}
		shell_exec(escapeshellcmd(APP_PATH . 'mpcfade.sh 100 70 .1')); //fade volume out
		shell_exec('espeak -s 130 --stdout "' . escapeshellcmd($this->espeak) . '" | aplay'); //use stdout & aplay because of a rpi bug?
		shell_exec(escapeshellcmd(APP_PATH . 'mpcfade.sh 70 100 .1')); //fade volume in
	}

	public function playMusic() {
		shell_exec(escapeshellcmd('mpc play && ' . APP_PATH . 'mpcfade.sh 50 100 .5')); //fade volume in
	}
}

?>
