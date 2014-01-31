<?php
//$dir = getcwd();
$dir = getcwd().'/';
//print $dir;exit;
//$dir = "C:\\wamp\\www\\testHLS\\";
//$file = "/dog-race.wmv";
$file = "output/dog-race.ts";
$raw_video_path = $dir.$file;//full path of raw video file	
//Show video file meta data
//$ffmpeg_path = "C:\\ffmpeg\\bin\\ffmpeg";		
//$ffmpeg_cmd="$ffmpeg_path -i $raw_video_path";
//exec("$ffmpeg_cmd 2>&1", $output);
//echo "<pre>";
//print_r($output);
//echo "</pre>";
//print $raw_video_path;exit;


require_once("ffmpeg_conversion.php");
$ffmpeg = new FfmpegConversion();
//print_r($ffmpeg);;

// video conversion will run for 3 minutes
$start_time = time();				
$max_time_for_converson = 3*60;

//$save_folder = $ffmpeg->generate_output_filename("dog-race.wmv", NULL, false);
$output_path = $dir."output/";
//print $output_path;
//$videofile = $ffmpeg->convert_to_ts($raw_video_path, $output_path, false, 1200);	// params: source, destination, use2Pass, bitrate(K)

$videofile = $ffmpeg->convert_to_m3u8($raw_video_path, $output_path."hi", false);
print_r($videofile);

//ffmpeg -y -i infile.mp4 -pix_fmt yuv420p -vcodec libx264 -acodec libfaac -r 25 -profile:v baseline -b:v 1500k -maxrate 2000k -force_key_frames 50 -s 640×360 -map 0 -flags -global_header -f segment -segment_list /tmp/index_1500.m3u8 -segment_time 10 -segment_format mpeg_ts -segment_list_type m3u8 /tmp/segment%05d.ts
?>