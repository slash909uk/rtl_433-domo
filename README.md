# rtl_433-domo
PHP script to filter and transform RTL433 SDR MQTT output events into Domoticz/MQTT messages.

Make sure to execute the rtl_433 daemon with MQTT output like this:
rtl_433 -M level -F mqtt://<MQTTserverIP> <port>

This script uses my phpSyslog and phpMqtt classes in my repos
