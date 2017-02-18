<?php

namespace PNKS\CourtListener;

function do_search ( $query, $auth ) {
	$search_api_url = 'https://www.courtlistener.com/api/rest/v3/search/' . '?q=' . $query;
    $cache_key = sha1($search_api_url);
    // reset cache if asked
	if($_GET['pnks'] === 'reset') { delete_transient( $cache_key ); }
	// get cached results, if available
	$results = get_transient( $cache_key );
	// do we have anything cached? 
    if ( false === $results ) {
	    if( get_transient('PNKS-CourtListener-Throttled') ) { 
	    	//error_log("** PNKS CL currently throttled: " . get_transient('PNKS-CourtListener-Throttled') ); 
	    	return; } // bail if throttled
	    // invoke API
		$curl = curl_init();
		curl_setopt_array( $curl, array(
			CURLOPT_HTTPHEADER, array( 'Authorization: Token ' . $auth ),
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $search_api_url
		));
		$curl_response = curl_exec($curl);
		$results = json_decode($curl_response, true); // get response out of JSON format
		//error_log( print_r($results, true) );
		// close out our curl request
		curl_close($curl);
	}
	//else { error_log("+ PNKS CL: Got results from transient cache"); }
    // get plugin settings
	$settings = (array) get_option( 'pnks-plugin-settings' );
	// check our results
	if( $results['results'] ) { 
		set_transient( $cache_key, $results, 1*86400 ); // cache for a day
		set_transient( "PNKS-CourtListener-Throttled", "Voluntarily limiting requests for 5 mins as of " . date("m/d/Y h:i:sa"), 5*60 ); 
		//error_log("PNKS CL: Proper results found (caching for " . $days_to_cache . " days): " . print_r($results, true) );
		return normalize_results($results);
	} 
	elseif( $results['detail']) { 
		// we've been throttled by CL: "expected available in ddd.d seconds"
		if( false === get_transient("PNKS-CourtListener-Throttled") ) {
			// we haven't recorded that we've been throttled by CL, so do that now (check so we don't throttle forever)
			preg_match('/(\d+)/', $results['detail'], $seconds);
			$seconds = $seconds[1] + 5; 
			set_transient( "PNKS-CourtListener-Throttled", "for " . round($seconds/60) . "mins as of " . date("m/d/Y h:i:sa") . ": " . $results['detail'], $seconds ); 
			error_log("PNKS CL: Just noted throttle by Court Listener for " . round($seconds/60) . " minutes: " . $results['detail'] );
		}
		return FALSE;
	}
	// no results
	set_transient( $cache_key, $results, 1*86400); // cache for a day
	set_transient( "PNKS-CourtListener-Throttled", "Voluntarily limiting requests for 5 mins as of " . date("m/d/Y h:i:sa"), 5*60 ); 
	//error_log( "PNKS CL: Apparently no results from Court Listener (cached for " . $days_to_cache . " days) for search (" . $search_api_url . "): " . print_r($results, true) );
	return FALSE;
}
function normalize_results ( $results ) {
	// take DPLAs materal and normalize it to what PNKS uses
	// title, author, date, url, summary, source_name, source_url
	$normalized_results = array();
	if ($results['results']) {
		// process our results and put into array
		foreach ($results['results'] as $case) {
			//error_log( "CL case: " . print_r($case, true) );
			$normalized_item = array();
			$normalized_item['api'] = 'Court Listener';
			$normalized_item['title'] = $case['caseName'];
			$normalized_item['summary'] = strip_tags($case['snippet']);
			$normalized_item['date'] = date( 'Y', strtotime($case['dateFiled']) );
			$normalized_item['cite'] = $case['citation'][0];
			$normalized_item['url'] = "https://www.courtlistener.com/" . $case['absolute_url'];
			//$normalized_item['source_name'] = "Court Listener";
			//$normalized_item['source_url'] = "https://www.courtlistener.com``";

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

