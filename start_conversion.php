<?php
set_time_limit(0);

include "mysql.php";

$con = get_mysql_connection();

function is_ffmpeg_ready(){
	$con =  get_mysql_connection();
	$sql = "select status from conversion_status where status='ready'";
	$result = @mysql_query($sql, $con);
	if(@mysql_num_rows($result)>0) return true; else return false;
}

if(is_ffmpeg_ready())
	include "convert_video.php";
else
	echo "ffmpeg is not ready";
?>