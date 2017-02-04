<?php
namespace PNKS;
/**
 *  Functions of use to most or all of our APIs
 */

//////////////
function check_for_cached_results( $post_id, $base_key, $max_days_to_cache, $min_days_to_cache ) {
	// if we're being told to refresh...
	if($_GET['api'] === 'refresh') {
		delete_cache_all($post_id, $base_key);
		return FALSE;
	}

	// how long should we wait to refresh cached items?
	$max_days_to_cache = 14; $min_days_to_cache = 5; // put these into a settings screen eventually
	$days_to_cache = 86400 * rand($min_days_to_cache, $max_days_to_cache); // make it random to avoid everything expiring at once

	// do we have something in the cache?
	$last_updated = read_cache_key($post_id, $base_key . "LastUpdated");
			// fix an old bug  -- delete in a bit
			//if(is_array($last_updated)) { delete_cache_all($post_id, $base_key); return FALSE; }
			// fix an old bug -- delete in a bit
	if( $last_updated ) {
		// is our cache expired?
		if( (time() - $last_updated) > $days_to_cache ) {
			//echo "*** cache expired: " . $days_to_cache;
			delete_cache_all($post_id, $base_key);
			return FALSE;
		}
		//echo "*** Found cached result";
		// not expired, so return our stored results
		return read_cache_key($post_id, $base_key . "Results");
	}
}
function read_cache_key( $post_id, $key ) {
	return get_post_meta($post_id, $key, true);	
}
function write_cache($post_id, $base_key, $search_query, $results ) {
	//echo "*** writing cache";
	update_post_meta( $post_id, $base_key . "SearchQuery", $search_query); // put it into a meta field 
	update_post_meta( $post_id, $base_key . "Results", $results);  
	update_post_meta( $post_id, $base_key . "LastUpdated", time() ); // along with last updated time 
}
function delete_cache_all( $post_id, $base_key ) {
	delete_post_meta($post_id, $base_key . "SearchQuery");
	delete_post_meta($post_id, $base_key . "Results");
	delete_post_meta($post_id, $base_key . "LastUpdated");
}
function build_search( $array_of_terms, $query_type ) {
	// $query_type can be "AND" or "OR" (for example)
	$search_query = '';
	$count_terms = 0;
	foreach ($array_of_terms as $term) {
		if($count_terms) { $search_query .= ' ' . $query_type . ' "' . $term . '"'; $count_terms++; }
		else { $search_query = $term; $count_terms++; }
    }
    return $search_query;
}

/////////// ideas of other ways to do this...
function build_search_query ( $array_of_terms, $query_type ) {
	// $query_type can be "AND" or "OR" (for example)
	$search_query = '';
	$count_terms = 0;
	foreach ($array_of_terms as $term) {
		if($count_terms) { $search_query .= ' ' . $query_type . ' ' . $term; $count_terms++; }
		else { $search_query = $term; $count_terms++; }
    }
    return $search_query;
}
function get_search_results( $curl_array ) {
	$seconds_to_cache = 360; // prevent repeating the same query too many times too quickly
	// check the cache
	$results = get_transient( "PNKSgsr" . get_cache_key($curl_array) );
	// if not cached, do search
    if ($result === false) {
        $result = get_results_via_curl($curl_array);
        set_transient($cache_hash_key, $results, 3600 * 24);
    }
    // return result
    return $result;
}
function get_cache_key( $curl_array ) {
	return sha1( print_r($curl_array, true) ); // get hash of a string dump of the search 
}
function get_results_via_curl( $curl_array ) {
	$curl = curl_init();
			curl_setopt_array( $curl, $curl_array );
	$curl_response = curl_exec($curl);
	// close out our curl request
	curl_close($curl);
	// return our response after converting from JSON
	return json_decode($curl_response, true);
}
