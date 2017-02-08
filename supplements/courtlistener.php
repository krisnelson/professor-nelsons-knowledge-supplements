<?php

namespace PNKS\CourtListener;

function do_search ( $query, $auth ) {
	$search_api_url = 'https://www.courtlistener.com/api/rest/v3/search/' . '?q=' . $query;
    $cache_key = sha1($search_api_url);
    $results = get_transient( $cache_key );

 	// are there no cached results and are we not previously throttled?
    if ( !$results and !get_transient('PNKS-CourtListener-Throttled') ) {
    	error_log("PNKS CL: Doing actual remote search as nothing cached and not throttled.");
		$curl = curl_init();
		curl_setopt_array( $curl, array(
			CURLOPT_HTTPHEADER,array('Authorization: Token ' . $auth),
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $search_api_url
		));
		$curl_response = curl_exec($curl);
		$results = json_decode($curl_response, true); // get response out of JSON format
		//error_log( print_r($results, true) );
		set_transient( $cache_key, $results, 6*3600); // 6 hour cache of actual results
		// close out our curl request
		curl_close($curl);
	}

	if( $results['results'] ) { 
		//error_log("PNKS CL: Proper results found: " . print_r($results, true) );
		set_transient("PNKS-CourtListener-Throttled", "Voluntarily limiting requests.", 60*2); // 2 minute throttle 
		return normalize_results($results);
	} 
	elseif( $results['detail']) { 
		// we've been throttled by CL: "expected available in ddd.d seconds"
		if( !get_transient("PNKS-CourtListener-Throttled") ) {
			// we haven't recorded that we've been throttled by CL, so do that now
			preg_match('/(\d+)/', $results['detail'], $seconds);
			$seconds = $seconds[1] + 5; 
			set_transient("PNKS-CourtListener-Throttled", $results['detail'], $seconds); 
			error_log("PNKS CL: Just noted throttle by Court Listener for " . round($seconds/60) . " minutes: " . $results['detail'] );
		}
		return FALSE;
	}
	else {
		// no results
		error_log( "PNKS CL: No results from Court Listener for (" . $search_api_url . "): " . print_r($results, true) );
		set_transient("PNKS-CourtListener-Throttled", "Voluntarily limiting requests.", 60*2); // 2 minute throttle 
		return FALSE;
	}

	//error_log( print_r($results, true) );
}
function normalize_results ( $results ) {
	// take DPLAs materal and normalize it to what PNKS uses
	// title, author, date, url, summary, source_name, source_url
	if ($results['results']) {
		// process our results and put into array
		foreach ($results['results'] as $case) {
			$normalized_item['api'] = 'Court Listener';
			$normalized_item['title'] = $case['caseName'];
			$normalized_item['summary'] = strip_tags($case['snippet']);
			$normalized_item['date'] = date( 'Y', strtotime($case['dateFiled']) );
			$normalized_item['url'] = "https://www.courtlistener.com/" . $case['absolute_url'];
			$normalized_item['source_name'] = "Court Listener";
			$normalized_item['source_url'] = "https://www.courtlistener.com``";

			$normalized_results[] = $normalized_item;
		}
		return $normalized_results;
	}
	else { 
		// no results to normalize
		return ''; 
	}
}









////////////////////////
function get_search_results( $post_id, $auth ) {
	$search_api_url = 'https://www.courtlistener.com/api/rest/v3/search/?q=';
	// check cache
	//echo "checking for cached results";
	$search_results = \PNKS\check_for_cached_results($post_id, "CourtListener");
	if($search_results) { //echo "*** found cached results"; 
		return $search_results; }

	// if we are throttled, we're done now
		//set_transient("CourtListenerThrottled", 'Skipping throttle', 1); 
	if( get_transient("CourtListenerThrottled") ) { return FALSE; }
	else { // otherwise, set up a short, voluntary throttle
		set_transient("CourtListenerThrottled", "Waiting in order to be respectful of Court Listener resources. [" . $post_id . "]", 30);
	}

	//echo "prepping search";
	// prep search from post tags
	$array_of_terms = wp_get_post_tags($post_id, array( 'fields' => 'names' ));
	$search_string = \PNKS\build_search($array_of_terms, "OR"); // do an OR search
	$search_query = urlencode($search_string);
	$search_results = get_results_via_curl($api_url . $search_query, $auth); // returns regular array
	//echo var_dump($search_results);
	if($search_results['detail']) {
		// we've been throttled by CL: "expected available in ddd.d seconds"
		preg_match('/(\d+)/', $search_results['detail'], $seconds);
		$seconds = $seconds[1] + 5; 
		//echo "waiting " . $seconds . "seconds";
		set_transient("CourtListenerThrottled", $search_results['detail'], $seconds); 
		return FALSE; 
	} 
	elseif($search_results['results']) { 
		\PNKS\write_cache($post_id, "CourtListener", $search_query, $search_results ); 
		return $search_results; 
	}
	
	// no results with tags? try categories
	$array_of_terms = wp_get_post_categories($post_id, array( 'fields' => 'names' ));
	$search_string = \PNKS\build_search($array_of_terms, "OR"); // do an OR search
	$search_query = urlencode($search_string);
	$search_results = \PNKS\CourtListener\get_results_via_curl($api_url . $search_query, $auth); // returns regular array
	//echo var_dump($search_results);
	if($search_results['detail']) {
		// we've been throttled by CL: "expected available in ddd.d seconds"
		preg_match('/(\d+)/', $search_results['detail'], $seconds);
		$seconds = $seconds[1] + 5; 
		//echo "waiting " . $seconds . "seconds";
		set_transient("CourtListenerThrottled", $search_results['detail'], $seconds); 
		return FALSE; 
	} 
	elseif($search_results['results']) { 
		\PNKS\write_cache($post_id, "CourtListener", $search_query, $search_results ); 
		return $search_results; 
	}

	//echo "no results";
	// otherwise return FALSE
	return FALSE;
}
function get_results_via_curl( $search_api_url, $auth ) {
	//\PNKS\count_query("CourtListener"); 	// count queries
	$curl = curl_init();
			curl_setopt_array( $curl, array(
				CURLOPT_HTTPHEADER,array('Authorization: Token ' . $auth),
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_URL => $search_api_url
			));
	$curl_response = curl_exec($curl);
	//echo "curl response" . var_dump($curl_response); 
	// close out our curl request
	curl_close($curl);
	// return our response after converting from JSON
	return json_decode($curl_response, true);
}

