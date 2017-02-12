<?php

namespace PNKS\DPLA;

function do_search ( $query, $auth ) {
	$search_api_url = 'http://api.dp.la/v2/items' . '?api_key=' . $auth . '&sourceResource.type=text' . '&q=' . $query  ;
	$cache_key = sha1($search_api_url);
    // reset cache if asked
	if($_GET['pnks'] === 'reset') { delete_transient( $cache_key ); }
	// get cached results, if available
	$results = get_transient( $cache_key );
	// do we have anything cached? 
    if ( false === $results ) {
	    if( get_transient('PNKS-Crossref-Throttled') ) { 
	    	//error_log("** PNKS Crossref currently throttled: " . get_transient('PNKS-CourtListener-Throttled') ); 
	    	return; } // bail if throttled
    	// invoke API
		$curl = curl_init();
		curl_setopt_array( $curl, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => $search_api_url
		));
		$curl_response = curl_exec($curl);
		$results = json_decode($curl_response, true); // get response out of JSON format
		//error_log( print_r($results, true) );
		// close out our curl request
		curl_close($curl);
	}
	//else { error_log("+ PNKS DPLA: Got results from transient cache"); }
	// get plugin settings
	$settings = (array) get_option( 'pnks-plugin-settings' );
	// check our results
	if( $results['docs'] ) { 
		$days_to_cache = \PNKS\approximate_cache_time_in_days($settings['days_to_cache']);
		set_transient( $cache_key, $results, $days_to_cache*86400); // cache for roughly the requested time
		//error_log("PNKS DPLA: Proper results found: " . print_r($results, true) );
		set_transient("PNKS-DPLA-Throttled", "Voluntarily limiting requests for 1 min as of " . date("m/d/Y h:i:sa"), 1*60); 
		return normalize_results($results);
	} 
	elseif ( $results['message'] ) {
		// note the error
		error_log( "PNKS DPLA: Error message: " . print_r($results['message'], true) );
	}
	
	// no results
	$days_to_cache = round( \PNKS\approximate_cache_time_in_days($settings['days_to_cache']) / 2 ); // half that time on no results
	set_transient( $cache_key, $results, $days_to_cache*86400); // cache for roughly the requested time
	//error_log( "PNKS DPLA: No results from DPLA for (" . $search_api_url . "): " . print_r($results, true) );
	set_transient("PNKS-DPLA-Throttled", "Voluntarily limiting requests for 1 min as of " . date("m/d/Y h:i:sa"), 1*60); 
	return FALSE;
}
	
function normalize_results ( $results ) {
	// take DPLAs materal and normalize it to what PNKS uses
	// title, author, date, url, summary, source_name, source_url
	$normalized_results = array();
	// do we actually have any results
	if ($results['docs']) {
		// process our results and put into array
		foreach ($results['docs'] as $item) {
			$normalized_item = array();
			// skip over any records with info we don't care about (that lack titles, basically)
			if( empty($item['sourceResource']['title']) ) { continue; } 
			elseif( is_array($item['sourceResource']['title']) ) { $title = $item['sourceResource']['title'][0]; }
			elseif( is_string($item['sourceResource']['title']) ) { $title = $item['sourceResource']['title']; }
			else { continue; } // if it's something else weird, just skip out

			// extract first part of the description
			if( is_string($item['sourceResource']['description']) ) { 
				$summary = substr($item['sourceResource']['description'], 0, 250) . " ..."; 
			}
			else $summary = "Resource provided by Digital Public Library.";

			// and get a date
			if( is_string($item['sourceResource']['date']['0']['displayDate']) ) {
				$date = $item['sourceResource']['date']['0']['displayDate'];
			}
			elseif ( is_string($item['sourceResource']['date']['displayDate']) ) {
				$date = $item['sourceResource']['date']['displayDate'];
			}
			$normalized_item['api'] = 'DPLA';
			$normalized_item['title'] = $title;
			$normalized_item['summary'] = $summary;
			$normalized_item['date'] = $date;
			$normalized_item['url'] = $item['isShownAt'];
			$normalized_item['source_name'] = $item['provider']['name'];

			$normalized_results[] = $normalized_item;
		}
		return $normalized_results;
	}
	else { 
		// no results to normalize
		return FALSE; 
	}
}



////////////////////////
function get_search_results( $post_id, $auth ) {
	$search_api_url = 'http://api.dp.la/v2/items?sourceResource.type=text&q=';
	// check cache
	$search_results = \PNKS\check_for_cached_results($post_id, "DPLA");
	if($search_results) { //echo "*** found cached results"; 
		return $search_results; }

	// if we are throttled, we're done now 
	if( get_transient("PNKSThrottled") ) { return FALSE; }
	else { // otherwise, set up a short, voluntary throttle
		set_transient("PNKSThrottled", "Waiting in order to be respectful of resources. [" . $post_id . "]", 30);
	}

	// prep search from post tags
	$array_of_terms = wp_get_post_tags($post_id, array( 'fields' => 'names' ));
	$search_string = \PNKS\build_search($array_of_terms, "OR"); // do an OR search
	$search_query = urlencode($search_string);
	$search_results = \PNKS\DPLA\get_results_via_curl($api_url . $search_query, $auth); // returns regular array

	if($search_results['docs']) { 
		\PNKS\write_cache($post_id, "DPLA", $search_query, $search_results ); 
		return $search_results; 
	}

	// otherwise return FALSE
	return FALSE;
}
function get_results_via_curl( $search_api_url, $auth ) {
	$curl = curl_init();
		curl_setopt_array( $curl, array(
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_URL => $search_api_url . '&api_key=' . $auth
			));
	$curl_response = curl_exec($curl);
	// close out our curl request
	curl_close($curl);
	// return our response after converting from JSON
	return json_decode($curl_response, true);
}
