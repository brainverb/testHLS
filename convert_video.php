<?php
function update_conversion_status($status){	
	$con =  get_mysql_connection();
	$query = "update conversion_status set status='$status', time = UNIX_TIMESTAMP() where id=1";
	@mysql_query($query, $con) or die("update_conversion_status($status): ".mysql_error());
	if(mysql_affected_rows($con)>0) return true; else return false;
}

function is_converted($video_filename, $converted_video_type){	
	$con =  get_mysql_connection();
	$video_filename = trim($video_filename);
	$converted_video_type = trim($converted_video_type);
	
	$query =  "select * from converted_videos where videoLocation = '$video_filename' and convertedVideoType='$converted_video_type'";	
	$result = @mysql_query($query, $con);
	if(@mysql_num_rows($result)>0)
		return true;
	else
		return false;
}

function add_to_converted_list($video_filename, $converted_video_type){	
	$con =  get_mysql_connection();
	$video_filename = trim($video_filename);
	$converted_video_type = trim($converted_video_type);
	
	$query = "insert into converted_videos (videoLocation, convertedVideoType) value('$video_filename', '$converted_video_type')";							
	@mysql_query($query, $con);		
}

function s3upload_m3u8($s3, $tsFilePrefix, $inputfilename, $s3filename, $s3path, $logpath)
{
	if($s3!=NULL){		
		//upload all segmented file
		$playlistContent = file_get_contents($inputfilename);
		preg_match_all("/($tsFilePrefix-\\d)/",$playlistContent,$matches);
		foreach($matches[0] as $segmentedFile)
		{
			$segmentfile = $logpath.'/'.$segmentedFile.'.ts';
			$s3segmentfile = $s3path.'/'.$segmentedFile.'.ts';
			s3upload($s3, $segmentfile, $s3segmentfile, $logpath);			
		}
		s3upload($s3, $inputfilename, $s3filename, $logpath);
	}
}

function s3upload($s3, $inputfilename, $s3filename, $logpath)
{
	if($s3!=NULL){
		if(file_exists($inputfilename)==true)
		{	
			if($s3->upload($inputfilename, $s3filename))
				$s3_log = $inputfilename." file has successfully been uploaded into S3";
			else
				$s3_log = $inputfilename." file has not been upload into S3";		
		}
		else{
			$s3_log = $inputfilename." file doesn't exists";		
		}
		write_log($s3_log, $logpath);
	}
}
function write_log($content, $log_path=NULL){		
	if(is_dir($log_path)===false){
		$log_path = $_SERVER['DOCUMENT_ROOT']."/ffmpeg_apple";
	}
	@mkdir($log_path, 0777);				
	$fp = @fopen($log_path."/ffmpeg_log.txt", "a");
	if(is_resource($fp)){
		$data = "===========================================================";
		$data .= "\n". date('l jS \of F Y h:i:s A');
		$data .= "\n===========================================================";
		$data .= "\n".$content;		
		$data .= "\n\n";
		@fwrite($fp, $data);
		@fclose($fp);
	}		
}

//want to convted a video file agian?
$reconvert = false;
if(isset($_GET['reconvert'])){
	if($_GET['reconvert']=='yes')
		$reconvert = true;
	else
		$reconvert = false;
}

//if ffmpeg is converting 2 video files
if(is_ffmpeg_ready()){	
	//ffmpeg is ready to convert file
	$svr_root = $_SERVER['DOCUMENT_ROOT']."/ffmpeg_apple";
	
	require_once("FfmpegAppleS3.php");	
	require_once("ffmpeg_conversion.php");
	$ffmpeg = new FfmpegConversion();
	
	//read watch folder for video files	
	include "read_video_files.php";
	$query = "select * from watch_folder";
	$result = @mysql_query($query); 
	
	// video conversion will run for 3 minutes
	$start_time = time();				
	$max_time_for_converson = 3*60;
	//change converter stauts, it is busy
	update_conversion_status("converting");					
	while ($row = @mysql_fetch_array($result))	
	{
		$watch_folder ="";
		$watch_folder = $row['folder_name'];
		//initialize S3		
		$s3 = NULL;
		if(!empty($row['S3_AccessKey']) and !empty($row['S3_SecretKey']) )
			$s3= new FfmpegAppleS3(trim($row['S3_AccessKey']), trim($row['S3_SecretKey']) );
		else{
			$s3_log = "Invalid S3 information for watch folder ".$watch_folder."\n\n";
			echo $s3_log;
			$ffmpeg->write_log($s3_log);
			$s3_log = "";
		}
		
		if(strlen($watch_folder)>0)
			if($watch_folder[0]!="/" and $watch_folder[0]!="\\")
				$watch_folder = "/".$watch_folder;
    	$path = $svr_root.$watch_folder;
		if(is_dir($path)){ 
			$files = array();		
			$files = getAllVideoFile($path,false);
			/*echo '<pre>';
			echo $path;
			print_r($files);	
			echo '</pre>';*/
			//set output folder
			$output_folder = $watch_folder;
			if(count($files)){				
				foreach($files as $video_filename){															
					//Save converted video to a new folder same as input file name i.e "GrandmaToThe Rescue.mpg", converted file should be saved in "GrandmaToThe Rescue" folder
					$save_folder = $ffmpeg->generate_output_filename($video_filename, NULL, false);
					$output_path = $svr_root.$output_folder."/".$save_folder;						
					if(!is_dir($output_path))
						@mkdir($output_path, 0777);
					// create mp4 version
					$output_ext = ".mp4";
					if(is_converted($video_filename, $output_ext)===false or $reconvert)
					{
						$ffmpeg->convert_to_mp4($video_filename, $output_path, true, true);	// params: source, destination, use2pass, enableInterleaving
						add_to_converted_list($video_filename, $output_ext); //update conveted file list
						//save into s3
						$input_filename = $output_path.'/'.$ffmpeg->generate_output_filename($video_filename, ".mp4", true);
						$s3_filename = $ffmpeg->generate_output_filename($video_filename, NULL, false).'/'.$ffmpeg->generate_output_filename($video_filename, ".mp4", true);
						s3upload($s3, $input_filename, $s3_filename, $output_path);
					}
					//video already conveted
					else
					{						
						$log_content = $video_filename." has already been converted";
						$ffmpeg->write_log($log_content, $output_path);
					}
					
					//convert to .ts
					$output_ext = ".ts";
					if(is_converted($video_filename, $output_ext)===false or $reconvert)
					{
						$output_path_high = $output_path."/512";
						if(!is_dir($output_path_high))
							@mkdir($output_path_high, 0777);
						// create high bitrate ts and segment it to High folder
						$videofile = $ffmpeg->convert_to_ts($video_filename, $output_path_high, true, 512);	// params: source, destination, use2Pass, bitrate(K)
						$ffmpeg->convert_to_m3u8($videofile, $output_path_high, true);	// params: sourceTS, destination of m3u8, remove source after segmentation						
						//save .m3u8 into s3
						$input_filename = $output_path_high.'/'.$ffmpeg->generate_output_filename($video_filename, ".m3u8", true);
						$s3_path = $ffmpeg->generate_output_filename($video_filename, NULL, false).'/512';
						$s3_filename = $ffmpeg->generate_output_filename($video_filename, NULL, false).'/512/'.$ffmpeg->generate_output_filename($video_filename, ".m3u8", true);
						$tsFilePrefix = $ffmpeg->generate_output_filename($video_filename, NULL, false);
						s3upload_m3u8($s3, $tsFilePrefix,  $input_filename, $s3_filename, $s3_path, $output_path_high);
						
						$output_path_mid = $output_path."/256";
						if(!is_dir($output_path_mid))
							@mkdir($output_path_mid, 0777);
						// create high bitrate ts and segment it to Mid folder
						$videofile = $ffmpeg->convert_to_ts($video_filename, $output_path_mid, true, 256);	// params: source, destination, use2Pass, bitrate(K)
						$ffmpeg->convert_to_m3u8($videofile, $output_path_mid, true);	// params: sourceTS, destination of m3u8, remove source after segmentation
						//save .m3u8 into s3
						$input_filename = $output_path_high.'/'.$ffmpeg->generate_output_filename($video_filename, ".m3u8", true);
						$s3_path = $ffmpeg->generate_output_filename($video_filename, NULL, false).'/256';
						$s3_filename = $ffmpeg->generate_output_filename($video_filename, NULL, false).'/256/'.$ffmpeg->generate_output_filename($video_filename, ".m3u8", true);
						$tsFilePrefix = $ffmpeg->generate_output_filename($video_filename, NULL, false);
						s3upload_m3u8($s3, $tsFilePrefix,  $input_filename, $s3_filename, $s3_path, $output_path_high);
						
						$output_path_low = $output_path."/96";
						if(!is_dir($output_path_low))
							@mkdir($output_path_low, 0777);
						// create high bitrate ts and segment it to Low folder
						$videofile = $ffmpeg->convert_to_ts($video_filename, $output_path_low, true, 96);	// params: source, destination, use2Pass, bitrate(K)
						$ffmpeg->convert_to_m3u8($videofile, $output_path_low, true);	// params: sourceTS, destination of m3u8, remove source after segmentation
						//save .m3u8 into s3
						$input_filename = $output_path_high.'/'.$ffmpeg->generate_output_filename($video_filename, ".m3u8", true);
						$s3_path = $ffmpeg->generate_output_filename($video_filename, NULL, false).'/96';
						$s3_filename = $ffmpeg->generate_output_filename($video_filename, NULL, false).'/96/'.$ffmpeg->generate_output_filename($video_filename, ".m3u8", true);
						$tsFilePrefix = $ffmpeg->generate_output_filename($video_filename, NULL, false);
						s3upload_m3u8($s3, $tsFilePrefix,  $input_filename, $s3_filename, $s3_path, $output_path_high);
						
						$adaptivePlaylist = substr($videofile,0,-3);
						$adaptivePlaylist = $output_path."/".substr($adaptivePlaylist,strrpos($adaptivePlaylist,"/")+1);
						
						// Creating bitrate specific variant/adaptive playlist from above three m3u8 indexes
						$ffmpeg->create_adaptive_playlist("512,256,96", $adaptivePlaylist);	//params: comma separated sources/bitrates m3u8's, index playlist file
						//save into s3
						$input_filename = $output_path.'/'.$ffmpeg->generate_output_filename($video_filename, ".m3u8", true);
						$s3_filename = $ffmpeg->generate_output_filename($video_filename, NULL, false).'/'.$ffmpeg->generate_output_filename($video_filename, ".m3u8", true);
						s3upload($s3, $input_filename, $s3_filename, $output_path);
						
						//update conveted file list
						add_to_converted_list($video_filename, $output_ext);						
						//break;
					}
					//video already conveted
					else
					{						
						$log_content = $video_filename." has already been converted";
						$ffmpeg->write_log($log_content, $output_path);
					}
					
					sleep(1);
					
					//stop next conversion if previous conversions runs for max time
					$running_time = time();
					$time_period = $running_time - $start_time;
					if($time_period> $max_time_for_converson) 
						break;
				}								
			}
		}
		else{
			//watch folder not found
			$ffmpeg->write_log("Watch folder \"$path\" not found");
		}
		
		//stop next conversion if previous conversions runs for max time
		$running_time = time();
		$time_period = $running_time - $start_time;
		if($time_period> $max_time_for_converson) 
			break;
	}
	//Total time in seconds needed to convert
	$running_time = time();
	$time_period = $running_time - $start_time;
	$log_content = "Total Time: ".$time_period." secs";
	$ffmpeg->write_log($log_content);	
	//reset converter
	update_conversion_status("ready");			
}else{
	echo "ffmpeg is converting a file, please try again later";
}
?>