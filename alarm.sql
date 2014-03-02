CREATE TABLE events ( 
	id varchar(150) UNIQUE, 
	title varchar(15), 
	trigger_date datetime,
	actual_date datetime,
	user_id INTEGER,
	FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE TABLE users (
	id integer primary key autoincrement,
	name varchar(15),
	event_buffer int(5),
	default_wakeup_time time,
	xml_calendar_url varchar(150),
	json_weather_url varchar(100)
);
