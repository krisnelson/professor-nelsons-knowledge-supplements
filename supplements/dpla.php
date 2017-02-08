<?php

namespace PNKS\DPLA;

function do_search ( $query, $auth ) {
	$search_api_url = 'http://api.dp.la/v2/items' . '?api_key=' . $auth . '&sourceResource.type=text' . '&q=' . $query  ;
	$cache_key = sha1($search_api_url);
	$results = get_transient( $cache_key );
	//error_log("Doing DPLA search for " . $search_api_url);
 	// are there no cached results and are we not previously throttled?
	if ( !$results and !get_transient('PNKS-DPLA-Throttled') ) {
		error_log("PNKS DPLA: Doing actual remote search as nothing cached and not throttled.");
		$curl = curl_init();
		curl_setopt_array( $curl, array(
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

	if( $results['docs'] ) { 
		//error_log("PNKS DPLA: Proper results found: " . print_r($results, true) );
		set_transient("PNKS-DPLA-Throttled", "Voluntarily limiting requests (last actual results cached).", 60); // 1 minute throttle 
		return normalize_results($results);
	} 
	elseif ( $results['message'] ) {
		error_log( "PNKS DPLA: Error message: " . print_r($results['message'], true) );
		set_transient("PNKS-DPLA-Throttled", "Error returned from DPLA: " . $results['message'], 60); // 1 minute throttle 		
		return FALSE;
	}
	else {
		// no results
		error_log( "PNKS DPLA: No results from DPLA for (" . $search_api_url . "): " . print_r($results, true) );
		set_transient("PNKS-DPLA-Throttled", "Voluntarily limiting requests (no results from DPLA for (" . $search_api_url . ") .", 60); // 1 minute throttle 
		return FALSE;
	}
}
	
function normalize_results ( $results ) {
	// take DPLAs materal and normalize it to what PNKS uses
	// title, author, date, url, summary, source_name, source_url

	// do we actually have any results
	if ($results['docs']) {
		// process our results and put into array
		foreach ($results['docs'] as $item) {
			// skip over any records with info we don't care about (that lack titles, basically)
			if( empty($item['sourceResource']['title']) ) { next; } 
			elseif( is_array($item['sourceResource']['title']) ) { $title = $item['sourceResource']['title'][0]; }
			elseif( is_string($item['sourceResource']['title']) ) { $title = $item['sourceResource']['title']; }
			else { next; } // if it's something else weird, just skip out

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
		return ''; 
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
