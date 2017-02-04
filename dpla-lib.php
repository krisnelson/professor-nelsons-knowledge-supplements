<?php

namespace PNKS\DPLA;

function get_search_results( $post_id, $auth ) {
	$search_api_url = 'http://api.dp.la/v2/items?sourceResource.type=text&q=';
	// check cache
	$search_results = \PNKS\check_for_cached_results($post_id, "DPLA");
	if($search_results) { //echo "*** found cached results"; 
		return $search_results; }

	// if we are throttled, we're done now 
	if( get_transient("DPLAThrottled") ) { return FALSE; }
	else { // otherwise, set up a short, voluntary throttle
		set_transient("DPLAThrottled", "Waiting in order to be respectful of DPLA resources. [" . $post_id . "]", 30);
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