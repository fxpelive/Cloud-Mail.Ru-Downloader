<?php
	$links_file = "links.txt";
	$download_to_folder = "downloads";

	$proxy = null;
	$proxy_auth = null;
	//$proxy = "u1.p.webshare.io:80";
	//$proxy = "127.0.0.1:8888";
	//$proxy_auth = "pdmvzoam-1:hcecmi79o9ot";

	$delete_input_file_after_download = true;

	// ======================================================================================================== //

	$main_url = "https://cloud.mail.ru/api/v2/";
	$current_dir = dirname(__FILE__);
	$aria2c = pathcombine($current_dir, "aria2c");
	$links = file($links_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

	
	foreach($links as $link)
	{
		$link = trim($link);
		if (strpos($link, 'http') !== 0) continue;


		if (!preg_match('~/public/([^/]+/[^/]+)~', $link, $match)) {
		//die("Wrong link " . $link . PHP_EOL);
		echo "Wrong link " . $link . PHP_EOL;
		continue;
		}
		$link_id = $match[1];
		$page_id = GetPageId($link);
		if (!$page_id) {
		//die("Page ID not found " . $link . PHP_EOL);
		echo "Page ID not found " . $link . PHP_EOL;
		continue;
		}
		$base_url = GetBaseUrl($page_id);
		if (!$base_url) {
		//die("Can't get base URL " . $link . PHP_EOL);
		echo "Can't get base URL " . $link . PHP_EOL;
		continue;
		}
		
		echo "Start create input file for Aria2c Downloader..." . PHP_EOL;
		$file4aria = pathcombine($current_dir, "input" . time() . ".txt");
		if (file_exists($file4aria)) unlink($file4aria);
		
		if ($files = GetAllFiles($link_id))
		{
			foreach ($files as $file)
			{
				$line = $file->link . PHP_EOL;
				$line .= "\tout=" . $file->output . PHP_EOL;
				$line .= "\tdir=" . $download_to_folder . PHP_EOL;
				file_put_contents($file4aria, $line, FILE_APPEND);
			}
			echo "Running Aria2c for download " . count($files) . " files..." . PHP_EOL;
			StartDownload();
			if ($delete_input_file_after_download) @unlink($file4aria);
		}
		else
		{
			//die("Can't find any file" . PHP_EOL);
			echo "Can't find any file" . PHP_EOL;
			continue;
			
		}
	}
	echo "Done!" . PHP_EOL;

	// ======================================================================================================== //

	class CMFile
	{
		public $link = "";
		public $output = "";
		function __construct($link, $output)
		{
			$this->link = $link;
			$this->output = $output;
		}
	}

	// ======================================================================================================== //

	function GetAllFiles($link, $folder = "")
	{
		global $main_url, $base_url, $page_id;

		$json = json_decode(get("{$main_url}folder?weblink=" . $link . "&x-page-id={$page_id}"), true);
		
		//---
		$need_all=($json["body"]['count']['folders'] + $json["body"]['count']['files']) >=500 ;
		if(!true   or $need_all ) {
		//file_put_contents("R:\json_std.json", json_encode($json,true) );
		$offset=0;
		$limit=50;
		$json=null;
		$items_list=array();
		while(true){
		$params=array('offset' => $offset, 'limit' => $limit);
		$queryParams = http_build_query($params);
		$json_obj = json_decode(get("{$main_url}folder?weblink=" . $link . "&x-page-id={$page_id}". "&".$queryParams), true);
		if(null==$json_obj){ print_r("{$main_url}folder?".$queryParams."\n"); exit("Smth went wrong..."); }
		$count_=$json_obj["body"]['count']['folders'] + $json_obj["body"]['count']['files'];
		$items=$json_obj["body"]["list"];
		$items_list=array_merge($items_list,$items);
		if(is_null($json)){ $json=$json_obj; }
		if ( ($offset > $count_) or  (count($items) == 0)  or ($json_obj["status"]!=200) ){ break; }
		echo $offset.":".$limit."\n";
		
		$offset += $limit;
		}
		$json["body"]["list"]=$items_list;
		//file_put_contents("R:\json_all.json", json_encode($json,true) );
		//print_r($json);exit;
		}
		//---

		$cmfiles = array();
		if (!isset($json["body"]["list"])) return $cmfiles;
		$folder = pathcombine($folder, $json["body"]["name"]);
		foreach ($json["body"]["list"] as $item)
		{
			if ($item["type"] == "folder")
			{
				$files_from_folder = GetAllFiles(pathcombine($link, rawurlencode($item["name"])), $folder);
				$cmfiles = array_merge($cmfiles, $files_from_folder);
			}
			else
			{
				$file_output = windowsbadpath(pathcombine($folder != "/" ? $folder : "", $item["name"]));
				$download_url = pathcombine($base_url, $link, $folder != "/" ? rawurlencode($item["name"]) : "");
				$cmfiles[] = new CMFile($download_url, $file_output);
			}
		}

		return $cmfiles;
	}

	// ======================================================================================================== //

	function StartDownload()
	{
		global $aria2c, $file4aria, $proxy, $proxy_auth;

		$command = "\"$aria2c\"   --max-tries=100 --retry-wait=1 --file-allocation=none --max-connection-per-server=16 --split=16 --min-split-size=1M --max-concurrent-downloads=4 --summary-interval=0 --continue --user-agent=\"Mozilla/5.0 (compatible; Firefox/3.6; Linux)\" --input-file=\"$file4aria\"";
		if ($proxy) $command .= " --all-proxy=" . ($proxy_auth ? "$proxy_auth@" : "") . "$proxy";
		passthru("{$command}");
	}

	// ======================================================================================================== //

	function GetPageId($url)
	{
		$page = get($url);
		//if (preg_match('~"pageId":"([^"]+)"~s', $page, $match)) return $match[1];
		if (preg_match('~pageId[\'"]*:[\'"]*([^"\'\s,]+)~s', $page, $match)) return $match[1];
		return false;
	}

	// ======================================================================================================== //

	function GetBaseUrl($page_id)
	{
		global $main_url;
		$json = json_decode(get("{$main_url}dispatcher?x-page-id={$page_id}"), true);
		if (isset($json["body"]["weblink_get"][0]["url"])) return $json["body"]["weblink_get"][0]["url"];
		return false;
	}

	// ======================================================================================================== //

	function get($url)
	{
		global $proxy, $proxy_auth;

		$http["method"] = "GET";
		if ($proxy) { $http["proxy"] = "tcp://" . $proxy; $http["request_fulluri"] = true; }
		if ($proxy_auth) $http["header"] = "Proxy-Authorization: Basic " . base64_encode($proxy_auth);
		$options['http'] = $http;
		$options['ssl'] = array
		(
			'verify_peer' => false,
			'verify_peer_name' => false
		);
		$context = stream_context_create($options);
		$body = @file_get_contents($url, NULL, $context);
		return $body;
	}

	// ======================================================================================================== //

	function pathcombine()
	{
		$result = "";
		foreach (func_get_args() as $arg)
		{
			if ($arg !== '')
			{
				if ($result && substr($result, -1) != "/") $result .= "/";
				$result .= $arg;
			}
		}
		return $result;
	}

	// ======================================================================================================== //

	function windowsbadpath($filename)
	{
		$bad = array_merge(
			array_map('chr', range(0,31)),
			array("<", ">", ":", '"', "|", "?", "*"));
		return str_replace($bad, "", $filename);
	}

	// ======================================================================================================== //

?>
