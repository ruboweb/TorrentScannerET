<?php
	/*
		Configuration paramenters
		-------------------------
	*/
	
	// URL to search content
	function getUrlBaseToSearch() {
		return "http://www.elitetorrent.net/resultados/";
	}
	
	// URL to search content by page
	function getUrlBaseToSearchByPage() {
		return "/pag:";
	}
	
	// URL to download files
	function getUrlToDownloadFile() {
		return "http://www.elitetorrent.net/get-torrent/";
	}
	
	// Database file
	function getDataBaseLocation() {
		return "db/torrents.db";
	}
	
	// Push script location
	function getPushScriptLocation() {
		return "./push.sh ";
	}
	
	// Download output directory
	function getDownloadOutputDirectory() {
		return "/server/.watch/";
	}

	/*
		Main method
		-----------
	*/
	if (isset($argv)) {
		main($argv);	
	}
	
	// Main method
	function main($argv) {
		if (!isset($argv[1])) {
			showHelp();
			return;
		}
		
    	if ($argv[1] == "-films") {
			echo "searching films....";
			return;			
    	}
		
		if ($argv[1] == "-f") {
			$verbose = 0;
			$downaload = 0;
			$quality = 0;
			
			
			if ($argv[1] == "-f" && isset($argv[2])) {
				if (in_array("-v", $argv)) {
					$verbose = 1;	
				}
				
				if (in_array("-d", $argv)) {
					$downaload = 1;	
				}
				
				if (in_array("-q", $argv)) {
					$quality = 1;	
				}
							
				searchFilm($argv[2], $verbose, $downaload, $quality);
				return;
			}
		}
		
       	if ($argv[1] == "-series") {
    		scanSeries();
			return;
    	}

		if ($argv[1] == "-s") {
			$verbose = 0;
			$downaload = 0;
			
			
			if ($argv[1] == "-s" && isset($argv[2])) {
				if (in_array("-v", $argv)) {
					$verbose = 1;	
				}
				
				if (in_array("-d", $argv)) {
					$downaload = 1;	
				}
							
				searchSerie($argv[2], $verbose, $downaload);
				return;
			}
		}
		
		showHelp();
		return;
	}
	
	// Show help to use API
	function showHelp() {
		print("\nMissin parameter:\n");
		print("Ussage: php scanner.php OPTION ... [TITLE]\n\n");
		
		print(" Options availables:\n\n");
		

		print("  Search Series:\n");
		print("  --------------\n");
		print("   $ -s TITLE : To search Serie with specific TITLE\n");
		print("            [-v] Enable verbose mode\n");
		print("            [-d] Download all detected torrents\n");
		print("\n");

		print("  Search Films:\n");
		print("  -------------\n");
		print("   $ -f TITLE : To search Film with specific TITLE \n");
		print("            [-q] Quality mode on (!screener)\n");
		print("            [-v] Enable verbose mode\n");
		print("            [-d] Download all detected torrents\n");
		print("\n");
		
		print("  Scan actions:\n");
		print("  -------------\n");
		print("   $ -films   : To scan enabled films stored in database\n");
		print("   $ -series  : To scan enabled series stored in database\n");
	}
	
	
	/*
		Search Series
		-------------
	*/
	
	// Gets all episodes of a series. Can show (-v) and/or downaload (-d) it
	function searchSerie($title, $verbose = false, $downaload = false) {
		// Array data episodes
		$episodes = array();
		
		// number of pages from result
		$pages = getNumberOfPagesFromResults($title);
		
		// search episodes
		for ($i = $pages; $i > 0; $i--) {
			$episodes = array_merge($episodes, parseHTMLDataFromSerie($title, $i));
		}
		
		if ($verbose){
			orderEpisodes($episodes, "episode");
			foreach($episodes as $e){
				print("   > " . $e["name"] . " : " . $e["episode"] . " : " . $e["torrent"]."\n");
			}
		}
		
		if ($downaload) {
			foreach($episodes as $e){
				exec("curl -s ". $e["torrent"] . " > " . getDownloadOutputDirectory() . $e["name"] . "-" . $e["episode"] . ".torrent");
			}
		}
		return $episodes;
	}
	
	// Scan all active series in database to find a new episode
	function scanSeries() {
		$series = getSeriesFromDataBase("ACTIVE");
		
		foreach($series as $s) {
			$episodes = searchSerie($s['search']);
						
			// new episode
			$new = has_next_episode($s['lastEpisode'], $episodes);
			if ($new != null) {
				// notify
				if ($s['notify']){
					notify($new);
				}
			
				// download
				if ($s['download']){
					download($new);
				}
				updateEpisode($new['episode'], $s['id']);
			}
		}		
	}

	// returm if there are more episodes
	function has_next_episode($episodeNumber, $episodes) {
		$seasion = explode("x", $episodeNumber)[0]; 
		$episode = explode("x", $episodeNumber)[1]; 
					
		// in the same seasion
		$newEpisode = $episode + 1;
		if ($newEpisode < 10) {
			$newEpisode = "0".$newEpisode;
		}		
		$e = existEpisodeInData($seasion."x".$newEpisode, $episodes);
		
		if ($e != null ){
			return $e;
		}
		
		// in new seasion
		$newSeacion = $seasion + 1;		
		$e = existEpisodeInData($newSeacion."x01", $episodes);
		if ($e != null ){
			return $e;
		}
		
		return null;
	}
	
	// Find episode in array data
	function existEpisodeInData($episodeNumber, $episodes) { 
		$field = "episode";
		$idx = -1;
			
		foreach ($episodes as $item) {
			$idx++;
			if ($item["episode"] == $episodeNumber) {
				return $episodes[$idx]; 
			}
		}
		return null; 
	}
	
	// Order episodes
	function orderEpisodes(&$episodes, $field, $order = SORT_ASC) { 
		$aux = array();
		
		foreach ($episodes as $key=> $row) {
			$aux[$key] = is_object($row) ? $aux[$key] = $row->$field : $row[$field];
			$aux[$key] = strtolower($aux[$key]);
		}
		array_multisort($aux, $order, $episodes);
	}
	
	
	/*
		Search Films
		------------
	*/
	// Gets all titles of a Film. Can show (-v) and/or downaload (-d) it quality premium (-q)
	function searchFilm($title, $verbose = false, $downaload = false, $quality = false) {
		// search titles
		$titles = parseHTMLDataFromFilm($title);
		$titlesOptimized = array();
		
		if ($quality) {
			foreach($titles as $t){
				if (strpos ($t["name"],  "screener") === false) {
					array_push($titlesOptimized, $t);
				}
			}
		} else {
			$titlesOptimized = $titles;	
		}
		
		if ($verbose){
			foreach($titlesOptimized as $t){
				print("   > " . $t["name"] . " : " . $t["torrent"] . "\n");
			}
		}
		
		if ($downaload) {
			foreach($titlesOptimized as $e){
				exec("curl -s ". $e["torrent"] . " > " . getDownloadOutputDirectory() . $e["name"] . ".torrent");
			}
		}
				
		return $titlesOptimized;
	}

	/*
		Scraping methods
		----------------
	*/

	//Return the number of additional pages fron results. 
	function getNumberOfPagesFromResults($search) {
		$elementsPerPage = 48;
		
		// get html
		$html =  makeSearchRequest($search);
				
		// create dom
		$doc = new DOMDocument();
		@$doc->loadHTML($html);
				
		// parse dom, number of episodes/page
		try {
			$h3s = $doc->getElementsByTagName('h3');
			$totalResults = str_replace(")","",explode("(total ", $h3s[0]->nodeValue)[1]);
			$pages = ceil ($totalResults / $elementsPerPage);
			return $pages;
		} catch (Exception $e) {
			return 1;
		}
	}
		
	//Parse HTML to extract and return Serie data 
	function parseHTMLDataFromSerie($search, $page) {
		$episodes = array();
		
		// get html
		$html =  makeSearchRequest($search, $page);
				
		// create dom
		$doc = new DOMDocument();
		@$doc->loadHTML($html);
		
		// local vars
		$re = '/[[:digit:]]+x[[:digit:]][[:digit:]]+/'; // like 1x01, 2x20, 10x200,....
			
		$torrentName = "";
		$torrentFile = "";
		$torrentEpisode = ""; 
				
		// parse dom, episode data
		$lis = $doc->getElementsByTagName('li');
		
		for ($i = 0; $i < $lis->length; $i++) {			
			$data = explode("/", $lis->item($i)->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->nodeValue);
			
			$torrentFile = $data[2];
			$torrentName = $data[3];
			
			preg_match_all($re, $torrentName, $torrentEpisode);
			if (sizeof($torrentEpisode[0]) > 0) {
				$episode = array(
					'name' => $search,
					'episode' => $torrentEpisode[0][0],
					'torrent' => getUrlToDownloadFile().$torrentFile
				);
				
				array_push($episodes, $episode);
			}
		}
		
		return $episodes;
	}
	
	//Parse HTML to extract and return Film data 
	function parseHTMLDataFromFilm($search) {
		$titles = array();
		
		// get html
		$html =  makeSearchRequest($search);
				
		// create dom
		$doc = new DOMDocument();
		@$doc->loadHTML($html);
		
		// local vars
		$re = '/[[:digit:]]+x[[:digit:]][[:digit:]]+/'; // like 1x01, 2x20, 10x200,....		
		$torrentName = "";
		$torrentFile = "";
					
		// parse dom, episode data
		$lis = $doc->getElementsByTagName('li');

		for ($i = 0; $i < $lis->length; $i++) {			
			$data = explode("/", $lis->item($i)->getElementsByTagName('a')->item(0)->attributes->getNamedItem('href')->nodeValue);
			
			$torrentFile = $data[2];
			$torrentName = $data[3];
			
			$res = preg_match($re, $torrentName);
			
			if(!$res){
				$title = array(
					'name' => $torrentName,
					'torrent' => getUrlToDownloadFile().$torrentFile
				);	
				array_push($titles, $title);
			}
		}
		
		return $titles;
	}
	/*
		Connection Utils
		----------------
	*/
	
	//Open url and gets the page 
	function makeSearchRequest($search, $pag = 0) {		
		$url = getUrlBaseToSearch().$search.getUrlBaseToSearchByPage().$pag;

	    $ch = curl_init();
	    
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    
	    $data = curl_exec($ch);
	    
	    curl_close($ch);
	    
	    return $data;
	}	
	
	/*
		Data Base Utils
		---------------
	*/
	
	// get all series from database
	function getSeriesFromDataBase($allOrActive) {
		$query = "";
		if ($allOrActive == "ALL") {
			$query = "select * from serie";
		} 
		
		if ($allOrActive == "ACTIVE") {
			$query = "select * from serie where (notify = 1 or download = 1)";
		}
		
		$db = new SQLite3(getDataBaseLocation());
		$results = $db->query($query);
		
		$data = array();
        while($row = $results->fetchArray(SQLITE3_ASSOC)){ 
          array_push($data, $row);
        } 

		$db->close();
		return $data;
	}
	
	// update episode
	function updateEpisode($episode, $id){
		$last = "'".$episode."'";

		$db = new SQLite3(getDataBaseLocation());
		$db->exec("update serie set lastEpisode = ".$last." where id = ". $id);
				
		$db->close();
	}
	
	
	/*
		Data Base Utils
		---------------
	*/
	
	// Push notification
	function notify($new) {
		exec(getPushScriptLocation() . $new['name'] . " " . $new['episode']);
	}
	
	// Download torrent
	function download ($new) {
		exec("curl -s ". $new['torrent'] . " > " . getDownloadOutputDirectory() . $new['name'] . "-" . $new['episode'] . ".torrent");
	}
	
?>