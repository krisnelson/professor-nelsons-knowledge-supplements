<?php
namespace PNKS;
/**
 *  Functions of use to most or all of our APIs
 */
//
//
// Go and get our external data
function get_supplemental_knowledge( $post_id ) {
	///$results = get_cached($post_id);
	$settings = (array) get_option( 'pnks-plugin-settings' );
	// prep search from post tags
	$array_of_terms = wp_get_post_tags($post_id, array( 'fields' => 'names' ));
	$search_query = urlencode( build_search($array_of_terms, "OR") ); // do an OR search
	$knowledge = array();
	// Court Listener
	if( isset($settings['courtlistener_auth']) ) {
		// we have auth and we have no cached results, so go get data
		$results = \PNKS\CourtListener\do_search($search_query, $settings['courtlistener_auth']);
		if( is_array($results) )
		{
			$knowledge = array_merge_alternate($results, $knowledge);
		}
	}
	// DPLA
	if( isset($settings['dpla_auth']) ) {
		// we have auth, so check DPLA
		$results = \PNKS\DPLA\do_search($search_query, $settings['dpla_auth']);
		if( is_array($results) )
		{
			$knowledge = array_merge_alternate($results, $knowledge);
		}
	}

	return $knowledge;
}
function array_merge_alternate($a, $b) {
	//$count_of_a = count($a);
	//$count_of_b = count($b);
	//if($count_of_a > $count_of_b) { $max_elements = $count_of_a; }
	//elseif($count_of_b > $count_of_a ) { $max_elements = $count_of_b; }
	//else { $max_elements = $count_of_a; }
	$total_elements = count($a) + count($b);

	for ($i = 0; $i < $total_elements; $i++) {
	    if($a[$i]) { $newArray[] = $a[$i]; }
	    if($b[$i]) { $newArray[] = $b[$i]; }
	}
	return $newArray;
}
function build_search( $array_of_terms, $query_type ) {
	// $query_type can be "AND" or "OR" (for example)
	$search_query = '';
	$count_terms = 0;
	foreach ($array_of_terms as $term) {
		if($count_terms) { $search_query .= ' ' . $query_type . ' "' . $term . '"'; $count_terms++; }
		else { $search_query = '"' . $term . '"'; $count_terms++; }
    }
    return $search_query;
}




//
//
// Go and get our external data
function OLDget_supplemental_knowledge( $post_id ) {

	if( are_throttled() ) {
		// we're throttled currently, so only return what's in the cache
		return get_cached($post_id);
	}

	$results = get_cached($post_id);
	$settings = (array) get_option( 'pnks-plugin-settings' );
	// prep search from post tags
	$array_of_terms = wp_get_post_tags($post_id, array( 'fields' => 'names' ));
	$search_query = urlencode( build_search($array_of_terms, "OR") ); // do an OR search

	// Court Listener
	if( isset($settings['courtlistener_auth']) ) {
		// check Court Listener
		if( !isset($results['CourtListener']) ) {
			// we have auth and we have no cached results, so go get data
			$results['courtlistener'] = \PNKS\CourtListener\do_search($search_query, $settings['courtlistener_auth']);
		}
	}
	// DPLA
	if( isset($settings['dpla_auth']) ) {
		// we have auth, so check DPLA
		if( !isset($results['DPLA']) ) {
			// we have no cached results, so go get data
			$results['dpla'] = \PNKS\DPLA\do_search($search_query, $settings['dpla_auth']);
		}
	}

	// check to see if our searches resulted in being throttled
	if( are_throttled() )
	{
		// this means we didn't get any results from at least 1 provider (because they limited/throttled us) 
		// so return our cached results instead
		return get_cached($post_id);
	}
	else {
		// voluntarily throttle ourselves for a short time
		set_throttle(60 ,"Voluntary delaying next remote request for 60 seconds.");
		// and return our newly generated results
		put_into_cache($post_id, $results);
		return $results;
	}
}
function put_into_cache( $post_id, $results ) {
	update_post_meta( $post_id, "_PNKS-Cached-Results", $results);
	update_post_meta( $post_id, "PNKS-Last-Updated", time() ); // along with last updated time 
	return $results;
}
function get_cached( $post_id ) {
	// if we're throttled, just return what we've alreay cached
	if( are_throttled() ) { return get_post_meta($post_id, "_PNKS-Cached-Results", true); }

	// if we're being told to refresh...
	if( $_GET['pnks'] === 'refresh' ) {
		delete_post_meta($post_id, "_PNKS-Cached-Results");
		delete_post_meta($post_id, "PNKS-Last-Updated");
		return FALSE; // and say we have nothing in the cache
	}

	// do we have something in the cache that's recent enough?
	$last_updated = get_post_meta($post_id, "PNKS-Last-Updated");
	if( is_numeric($last_updated) && $last_updated ) {
		// how long should we wait to refresh cached items?
		$settings = (array) get_option( 'pnks-plugin-settings' );
		$days_to_cache = $settings['days_to_cache']||'30';
		$max_days_to_cache = $days_to_cache + ($days_to_cache * .25);
		$min_days_to_cache = $days_to_cache - ($days_to_cahce * .25); 
		$days_to_cache = 86400 * rand($min_days_to_cache, $max_days_to_cache); // make it random to avoid everything expiring at once
		// so, has our cache expired?
		if( (intval(time() - $last_updated) > intval($days_to_cache)) ) {
			// yes, it has
			delete_post_meta($post_id, "_PNKS-Cached-Results");
			delete_post_meta($post_id, "PNKS-Last-Updated");
			return FALSE; // so report we have nothing
		}
		else {
			// not expired, so return our stored results
			return get_post_meta($post_id, "_PNKS-Cached-Results", true);
		}
	}
	else { 
		// nothing valid in our cache... clear it just in case
		delete_post_meta($post_id, "_PNKS-Cached-Results");
		delete_post_meta($post_id, "PNKS-Last-Updated");
		return FALSE; // and say we've got nothing
	} 
}
function set_throttle( $seconds, $message ) {
	return set_transient("PNKSThrottled", $message||"Voluntarily delaying next external request.", $seconds||'360');
}
function are_throttled() {
	return get_transient("PNKSThrottled");
}











///////////////////
function check_for_cached_results( $post_id, $base_key ) {
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
