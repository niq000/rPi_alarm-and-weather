# Raspberry pi - Alarm & Weather
Raspberry Pi, alarm clock via google calendar, and weather conditions via wunderground.com

**Requirements:**
- php5
- sqlite3
- mpd / mpc
- espeak (to hear the time, weather, etc...)
- google calendar (so we know when the alarm will go off)
- wunderground.com api key if you want weather data (espeak weather info when the alarm goes off)

**Alarm will go off under the following circumstances:**
- Monday through Friday default_wakeup_time (eg 8am)
- Any event with a title of "alarm"
- An event, minus the event_buffer, that is earlier than default_wakeup_time
- event_buffer is used to give yourself time to shower/commute (eg 1.5 hours), so your alarm goes off 1.5 hours _BEFORE_ the calendar event. This also works on weekends

**Install / Usage:**
Add user information to the sqlite database
```sql
INSERT INTO users (name, default_wakeup_time, event_buffer, xml_calendar_url, json_weather_url) VALUES ('jacob jingleheimer smith', '08:00:00', -5400, 'http://www.google.com/calendar/feeds/_your_google_username_%40gmail.com/private-aaaaaa9a9a9a9a9a9a9a9a9a9a/basic', 'http://api.wunderground.com/api/abc1234567890/conditions/q/XX/Some_City.json');
```

Setup a cron job something like:
```
*/10 * * * * /usr/bin/php /path/to/examples/cron.php
```
