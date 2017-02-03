<?php
namespace PNKS;
/**
 *  Functions of use to most or all of our APIs
 */
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
		if($count_terms) { $search_query .= ' ' . $query_type . ' ' . $term; $count_terms++; }
		else { $search_query = $term; $count_terms++; }
    }
    return $search_query;
}
//function get_number_of_queries($base_key) {
//	return get_transient($base_key . "Queries") || '1';
//}
//function count_query($base_key) { 
//	$num_queries = get_number_of_queries($base_key);
//	$num_queries++;
//	set_transient($base_key . "Queries", $num_queries, 1 * 86400); // reset every 24 hours
//	set_transient($base_key . "StartTime", time()); // reset every 24 hours
//}
//function get_start_of_query_count($base_key) {
//	$start_time =  get_transient($base_key . "QueriesStartTime");
//	return $start_time || time();
//}
//function queries_per_hour($base_key) {
//	$num_queries = get_number_of_queries($base_key);
//	$start_time =  get_start_of_query_count($base_key);
//	$elapsed_hours = (time() - $start_time) * 60;
//	$queries_per_hour = $num_queries / $elapsed_hours;
//	return $queries_per_hour; 
//}