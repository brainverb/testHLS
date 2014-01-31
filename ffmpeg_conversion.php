<?php
class FfmpegConversion{
	private $ffmpeg_path = "/usr/local/bin/ffmpeg";	
	//private $ffmpeg_path = "C:\\ffmpeg\\bin\\ffmpeg";
	private $mp4box_path = "/usr/local/bin/MP4Box";	
	private $segmenter_path = "/usr/local/bin/segmenter";	
	public $settings = array();	
	public $raw_output = "";
	private $debug = true;		
	private $output_path = "";
	//Current status of the ffmpeg encoder {ready, converting}
	private $status = "ready";
	
	function __construct(){
		//default settiongs
		$this->settings["others"]="";
	}
	
	public function get_status(){
		return $this->status;
	}
	
	public function set_status($status){
		if(!empty($status)){
			$this->status = $status;
			return true;
		}
		else
			return false;
	}
	
	public function convert_to_ts($videofile, $dest_path, $use2pass=false, $bitrate=256){		
		$output_file= $dest_path.'dog-video.ts';
		//print $output_file;//exit;
		
		$this->output_path = $dest_path;

		if($use2pass)
		{
			$ffmpeg_cmd = $this->ffmpeg_path." -threads 4 -i \"$videofile\" -y -pass 1 -s 480×320 -vcodec libx264 -b $bitrate"."k -flags +loop+mv4 -flags2 +mixed_refs -cmp 256 -partitions +parti4x4+partp8x8+partb8x8 -subq 5 -trellis 2 -mbd 1 -refs 5 -coder 0 -me_method umh -me_range 90 -keyint_min 25 -sc_threshold 40 -i_qfactor 0.71 -bt 256k -bufsize 2M -rc_eq 'blurCplx^(1-qComp)' -qcomp 0.6 -qmin 10 -qmax 51 -qdiff 4 -level 30 -aspect 4:3 -r 30 -g 30 -vsync 1 -an \"$output_file\"";
			$this->convert($ffmpeg_cmd);
			$ffmpeg_cmd = $this->ffmpeg_path." -threads 4 -i \"$videofile\" -y -pass 2 -acodec libfaac -ar 48000 -ab 128k -ac 2 -s 480×320 -vcodec libx264 -b $bitrate"."k -flags +loop+mv4 -flags2 +mixed_refs -cmp 256 -partitions +parti4x4+partp8x8+partb8x8 -subq 5 -trellis 2 -mbd 1 -refs 5 -coder 0 -me_method umh -me_range 90 -keyint_min 25 -sc_threshold 40 -i_qfactor 0.71 -bt 256k -bufsize 2M -rc_eq 'blurCplx^(1-qComp)' -qcomp 0.6 -qmin 10 -qmax 51 -qdiff 4 -level 30 -aspect 4:3 -r 30 -g 30 -vsync 1 -async 1 \"$output_file\"";
			$this->convert($ffmpeg_cmd);
		}
		else
		{
			$ffmpeg_cmd = $this->ffmpeg_path." -threads 4 -i \"$videofile\" -y -s 480×320 -acodec libfaac -ar 48000 -b:a 128k -ac 2 -vcodec libx264 -b:v $bitrate"."k -flags +loop+mv4 -cmp 256 -partitions +parti4x4+partp8x8+partb8x8 -subq 5 -trellis 2 -mbd 1 -refs 5 -coder 0 -me_method umh -me_range 90 -keyint_min 25 -sc_threshold 40 -i_qfactor 0.71 -bt 256k -bufsize 2M -rc_eq 'blurCplx^(1-qComp)' -qcomp 0.6 -qmin 10 -qmax 51 -qdiff 4 -level 30 -aspect 4:3 -r 30 -g 30 -vsync 1 -async 1 \"$output_file\"";
			$this->convert($ffmpeg_cmd);
		}
		//print $ffmpeg_cmd;
		return $output_file;
	}
	
	public function convert_to_m3u8($videofile, $dest_path, $remove_source=false)
	{
		$tsFilePrefix = substr($videofile,0,strrpos($videofile,"."));
		$tsFilePrefix = substr($tsFilePrefix,strrpos($tsFilePrefix,"/")+1);

		$segmenter_cmd = $this->segmenter_path." $videofile 10 $tsFilePrefix $dest_path/$tsFilePrefix.m3u8 ''";
		print $segmenter_cmd;
		$this->convert($segmenter_cmd);
		
		$playlistContent = file_get_contents($dest_path."/".$tsFilePrefix.".m3u8");
		preg_match_all("/($tsFilePrefix-\\d)/",$playlistContent,$matches);
		foreach($matches[0] as $segmentedFile)
			if(copy($segmentedFile.".ts",$dest_path."/".$segmentedFile.".ts"))
				@unlink($segmentedFile.".ts");
				
		if($remove_source)
			@unlink($videofile);
	}
	
	public function create_adaptive_playlist($sources,$playlistFile)
	{
		$bitrates = split(",",$sources);
		$adaptiveContent = "#EXTM3U\n";
		$indexFile = substr($playlistFile,strrpos($playlistFile,"/")+1);
		foreach($bitrates as $br)
		{
			$adaptiveContent .= "#EXT-X-STREAM-INF:PROGRAM-ID=1, BANDWIDTH=".$br*1024;
			$adaptiveContent .= "\n".$br."/".$indexFile.".m3u8\n";
		}
		//print $playlistFile."<br />".$adaptiveContent."<br />";
		$handle = fopen($playlistFile.".m3u8","w");
		fwrite($handle,utf8_encode($adaptiveContent));
		fclose($handle);
	}
	
	public function convert_to_mp4($videofile, $dest_path, $use2pass=false, $enableInterleaving=false){
		$output_file= $dest_path.'/'.$this->generate_output_filename($videofile,".mp4");
		$this->output_path = $dest_path;
		if($use2pass)
		{
			$ffmpeg_cmd = $this->ffmpeg_path." -threads 4 -i \"$videofile\" -y -pass 1 -s 480×320 -vcodec libx264 -b 512k -f mp4 -flags +loop+mv4 -flags2 +mixed_refs -cmp 256 -partitions +parti4x4+partp8x8+partb8x8 -subq 5 -trellis 2 -mbd 1 -refs 5 -coder 0 -me_method umh -me_range 90 -keyint_min 25 -sc_threshold 40 -i_qfactor 0.71 -bt 256k -bufsize 2M -rc_eq 'blurCplx^(1-qComp)' -qcomp 0.6 -qmin 10 -qmax 51 -qdiff 4 -level 30 -aspect 4:3 -r 30 -g 150 -vsync 1 -an /dev/null";
			$this->convert($ffmpeg_cmd);
			$ffmpeg_cmd = $this->ffmpeg_path." -threads 4 -i \"$videofile\" -y -pass 2 -acodec libfaac -ar 48000 -ab 128k -ac 1 -s 480×320 -vcodec libx264 -b 512k -f mp4 -flags +loop+mv4 -flags2 +mixed_refs -cmp 256 -partitions +parti4x4+partp8x8+partb8x8 -subq 5 -trellis 2 -mbd 1 -refs 5 -coder 0 -me_method umh -me_range 90 -keyint_min 25 -sc_threshold 40 -i_qfactor 0.71 -bt 256k -bufsize 2M -rc_eq 'blurCplx^(1-qComp)' -qcomp 0.6 -qmin 10 -qmax 51 -qdiff 4 -level 30 -aspect 4:3 -r 30 -g 150 -vsync 1 -async 1 \"$output_file\"";
			$this->convert($ffmpeg_cmd);
		}
		else
		{
			$ffmpeg_cmd = $this->ffmpeg_path." -threads 4 -i \"$videofile\" -y -acodec libfaac -ar 48000 -ab 128k -ac 1 -s 480×320 -vcodec libx264 -b 512k -f mp4 -flags +loop+mv4 -flags2 +mixed_refs -cmp 256 -partitions +parti4x4+partp8x8+partb8x8 -subq 5 -trellis 2 -mbd 1 -refs 5 -coder 0 -me_method umh -me_range 90 -keyint_min 25 -sc_threshold 40 -i_qfactor 0.71 -bt 256k -bufsize 2M -rc_eq 'blurCplx^(1-qComp)' -qcomp 0.6 -qmin 10 -qmax 51 -qdiff 4 -level 30 -aspect 4:3 -r 30 -g 150 -vsync 1 -async 1 \"$output_file\"";
			$this->convert($ffmpeg_cmd);
		}
		
		if($enableInterleaving)
		{
			$mp4box_cmd = $this->mp4box_path." -inter 500 -hint $output_file";
			$this->convert($mp4box_cmd);
		}
	}
	
	private function convert($ffmpeg_cmd){		
		$this->status = "converting";
		exec("$ffmpeg_cmd 2>&1", $output);
		$this->status = "ready";
		$this->raw_output =  $this->format_raw_output($output);		
		if($this->debug)
			$this->write_log($this->raw_output, $this->output_path);		
	}
	
	//was video conveted?
	//Check if the raw output has "video:44kb"(should be different)
	private function is_converted($raw_output){		
		//find video size		
		$pattern = '/video:(\d*)/';
		preg_match($pattern, $raw_output, $matches);
		$success = false;
		if(count($matches)>1){
			if(!empty($matches[1])){
				$success = true;
			}
		}		
		return $success;
	}
	
	//ffmpeg return raw output as an array, so, make it as a string
	private function format_raw_output($raw_output){
		$data = "";
		if(!empty($raw_output)){
			if(is_array($raw_output)){
				if(count($raw_output)){
					foreach($raw_output as $line){
						$data .= "\n". $line;
					}
				}
			}
			else{
				$data .= "\n". $raw_output;
			}			
		}
		return $data;	
	}
	
	public function generate_output_filename($input_file, $output_file_type=".mp4", $incude_extension=true){
		$output_filename="";		
		if(strrpos($input_file,".")!== false){
			$sep = '/';
			$output_filename = trim(substr($input_file, strrpos($input_file, $sep)+1, strrpos($input_file, ".") - strrpos($input_file, $sep)-1 ) );
			if($incude_extension and !empty($output_file_type)){					
				if($output_file_type[0]=='.')
					$output_filename .= $output_file_type;
				else
					$output_filename .= ".".$output_file_type;
			}
		}		
		if(empty($output_filename))
			return false;
		else
			return $output_filename;
	}
	
	private function get_random_string(){
		$chars=array();
		$randomString = "";
		for($i=97; $i<123; $i++){ $chars[]=chr($i); }
		for($i=65; $i<91; $i++){ $chars[]=chr($i); }
		for($i=48; $i<58; $i++){ $chars[]=chr($i); }
		for($i=0;$i<20;$i++)
			$randomString .= $chars[rand(0,58)];
		return $randomString;
	}
	
	public function write_log($content, $log_path=NULL){		
		if(is_dir($log_path)===false){
			$log_path = $_SERVER['DOCUMENT_ROOT']."/ouput";
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
}
?>