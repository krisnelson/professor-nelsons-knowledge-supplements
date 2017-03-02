<?php
namespace PNKS;
/**
 *  Functions of use to most or all of our APIs
 */
//
//
function approximate_cache_time_in_days ( $set_days_to_cache ) {
	// now check to see if expiration time has passed
	// max/min days are +/- 25% so we don't expire all posts at once
	$max_to_cache = $set_days_to_cache + round($set_days_to_cache * .25);
	$min_to_cache = $set_days_to_cache - round($set_days_to_cache * .25); 
	$days_to_cache = rand($min_to_cache, $max_to_cache); // make it random to avoid everything expiring at once
	// so, has our cache expired?
	return intval($days_to_cache);
}
function build_search( $array_of_terms, $query_type ) {
	// $query_type can be "AND" or "OR" (for example)
	$search_query = '';
	$count_terms = 0;
	foreach ($array_of_terms as $term) {
		if($count_terms) { $search_query .= ' ' . $query_type . ' "' . $term . '"'; $count_terms++; }
		else { $search_query = '"' . $term . '"'; $count_terms++; }
    }
    // collapse doubled whitespace 
    $search_query = preg_replace('/\s\s+/', ' ', $search_query);
    return $search_query;
}
function array_merge_alternate($a, $b, $c) {
	$total_elements = count($a) + count($b) + count($c);
	for ($i = 0; $i < $total_elements; $i++) {
	    if(isset($a[$i])) { $newArray[] = $a[$i]; }
	    if(isset($b[$i])) { $newArray[] = $b[$i]; }
	    if(isset($c[$i])) { $newArray[] = $c[$i]; }
	}
	return $newArray;
}

/////////////
// Cache class: based originally on https://github.com/pressjitsu/fragment-cache (GPL 3)

class CacheFragments {
	var $key;
	var $args;

	function __construct($key,  $args = array() ) {
		$this->key = $key;
		$args = wp_parse_args( $args, array(
			'storage' => 'transient', // object-cache, meta
			'unique' => array(),
			'ttl' => 0,

			// Meta storage only
			'meta_type' => '',
			'object_id' => 0,
		) );
		$args['unique'] = md5( json_encode( $args['unique'] ) );
		$this->args = $args;
	}

	function read( ) {
		$cache = $this->_get();

		//error_log("-- Frag Cache args: " . print_r($args,true));
		if ( empty( $cache ) ) { return FALSE; } 
		elseif ( $this->args['ttl'] && (time() - $cache['timestamp']) > $this->args['ttl'] ) { 
			return FALSE; //error_log("-- Frag cache expired.");
		} 
		elseif ( ! hash_equals( $cache['unique'], $this->args['unique'] ) ) { return FALSE; }

		//error_log( "-- Frag Cache found item, returning."); //: " . print_r($cache, true) );
		return $cache['data'];
	}
	private function _get( ) {
		$cache = null;

		switch ( $this->args['storage'] ) {
			case 'transient':
				$cache = get_transient( '_PNKS_Fragment_Cache:' . $this->key );
				break;
			case 'object-cache':
				$cache = wp_cache_get( $this->key, '_PNKS_Fragment_Cache' );
				break;
			case 'meta':
				if ( empty( $this->args['meta_type'] ) || empty( $this->args['object_id'] ) )
					throw new Exception( 'When using meta storage meta_type and object_id are required.' );

				$cache = get_metadata( $this->args['meta_type'], $this->args['object_id'], '_PNKS_Fragment_Cache:' . $this->key, true );
				break;
		}
		return $cache;
	}

	function write( $data ) {
		$value_to_cache = array(
			'data' => $data,
			'timestamp' => time(),
			'unique' => $this->args['unique'],
		);
		$this->_set( $value_to_cache );

		return $data;
	}
	private function _set( $value ) {
		$result_of_cache = FALSE;

		switch ( $this->args['storage'] ) {
			case 'transient':
				$result_of_cache = set_transient( '_PNKS_Fragment_Cache:' . $this->key, $value, $this->args['ttl'] );
				break;
			case 'object-cache':
				$result_of_cache = wp_cache_set( $this->key, $value, '_PNKS_Fragment_Cache', $this->args['ttl'] );
				break;
			case 'meta':
				$result_of_cache = update_metadata( $this->args['meta_type'], $this->args['object_id'], '_PNKS_Fragment_Cache:' . $this->key, $value );
				break;
		}
		//error_log( "*** Cache _set [" . $result_of_cache . "]: " . print_r($value, true) );
		return $result_of_cache;
	}

	function erase() {
		switch ( $this->args['storage'] ) {
			case 'transient':
				return delete_transient( '_PNKS_Fragment_Cache:' . $this->key );
				break;
			case 'object-cache':
				return wp_cache_delete( $this->key, '_PNKS_Fragment_Cache' );
				break;
			case 'meta':
				return delete_metadata( $this->args['meta_type'], $this->args['object_id'], '_PNKS_Fragment_Cache:' . $this->key );
				break;
		}
	}

}


/////


////////////////////////////////
////////////////////////// based originally on https://github.com/pressjitsu/fragment-cache (GPL 3)
/*
Usage:

$cache_args = array(
			'storage' => 'meta',
			'meta_type' => 'post',
			'object_id' => $post_id,
			'ttl' => 86400*7 // convert days (7) to seconds
		);

$PNKSCache = new \PNKS\Fragment_Cache();
$courtlistener = $PNKSCache->read( 'CourtListener', $cache_args );
if ( false === $courtlistener ) {
	$courtlistner = expensive_search();
	$PNKSCache->write( $courtlistener );
} 

*/

class Fragment_Cache {
	private static $key;
	private static $data;
	private static $args;
	private static $lock;

	public static function read( $key, $args = array() ) {
		if ( self::$lock )
			throw new Exception( 'Output started but previous output was not written.' );

		$args = wp_parse_args( $args, array(
			'storage' => 'transient', // object-cache, meta
			'unique' => array(),
			'ttl' => 0,

			// Meta storage only
			'meta_type' => '',
			'object_id' => 0,
		) );

		$args['unique'] = md5( json_encode( $args['unique'] ) );

		$cache = self::_get( $key, $args );

		//error_log("-- Frag Cache args: " . print_r($args,true));
		$serve_cache = true;
		if ( empty( $cache ) ) {
			$serve_cache = false;
		} elseif ( $args['ttl'] && (time() - $cache['timestamp']) > $args['ttl'] ) { 
			$serve_cache = false; //error_log("-- Frag cache expired.");
		} elseif ( ! hash_equals( $cache['unique'], $args['unique'] ) ) {
			$serve_cache = false;
		}

		if ( ! $serve_cache ) {
			self::$key = $key;
			self::$args = $args;
			self::$lock = true;
			return false;
		}


		//error_log( "-- Frag Cache found item, returning."); //: " . print_r($cache, true) );
		return $cache['data'];
	}

	private static function _get( $key, $args ) {
		$cache = null;

		switch ( $args['storage'] ) {
			case 'transient':
				$cache = get_transient( '_PNKS_Fragment_Cache:' . $key );
				break;
			case 'object-cache':
				$cache = wp_cache_get( $key, '_PNKS_Fragment_Cache' );
				break;
			case 'meta':
				if ( empty( $args['meta_type'] ) || empty( $args['object_id'] ) )
					throw new Exception( 'When using meta storage meta_type and object_id are required.' );

				$cache = get_metadata( $args['meta_type'], $args['object_id'], '_PNKS_Fragment_Cache:' . $key, true );
				break;
		}

		return $cache;
	}

	private static function _set( $key, $args, $value ) {
		switch ( $args['storage'] ) {
			case 'transient':
				$cache = set_transient( '_PNKS_Fragment_Cache:' . $key, $value, $args['ttl'] );
				break;
			case 'object-cache':
				$cache = wp_cache_set( $key, $value, '_PNKS_Fragment_Cache', $args['ttl'] );
				break;
			case 'meta':
				$cache = update_metadata( $args['meta_type'], $args['object_id'], '_PNKS_Fragment_Cache:' . $key, $value );
				break;
		}

		return true;
	}

	public static function write( $data ) {
		if ( ! self::$lock )
			throw new Exception( 'Attempt to write to cache but output was not started.' );

		self::$lock = false;

		$cache = array(
			'data' => $data,
			'timestamp' => time(),
			'unique' => self::$args['unique'],
		);

		self::_set( self::$key, self::$args, $cache );

		return $data;
	}
}
//////////////////////


























//////////////
// Go and get our external data
function get_supplemental_knowledge( $post_id, $api_name ) {
	//delete_cache_all( $post_id, "CourtListener" );
	//delete_cache_all( $post_id, "DPLA" );
	//delete_cache_all( $post_id, "Crossref" );

	$settings = (array) get_option( 'pnks-plugin-settings' );
	$days_to_cache = $settings['days_to_cache']||'30'; // default to 30 days if not set

	// prep search from post tags
	$array_of_terms = wp_get_post_tags($post_id, array( 'fields' => 'names' ));
	//$search_query = urlencode( build_search($array_of_terms, "OR") ); // do an OR search
	//$courtlistener = array(); $dpla = array(); $crossref = array(); 

	// 1. Court Listener
	if( $api_name === 'CourtListener' and is_string($settings['courtlistener_auth']) ) {
		// we have auth and we have no cached results, so go get data
		$has_cache_expired = has_cache_expired( $post_id, "CourtListener", $settings['days_to_cache'] );
		$courtlistener = read_cache( $post_id, "CourtListener" );
		if($has_cache_expired === true or $courtlistener == false) {
			// our cache has expired, so try to get new data
			//error_log("== CL cache expired or cache not good.");
			$query = urlencode( build_search($array_of_terms, 'OR') ); // do an OR search
			$courtlistener_external = \PNKS\CourtListener\do_search( $query, $settings['courtlistener_auth'] );
			// if we successfully retreived results, write them to the cache and use them
			if( is_array($courtlistener_external) and $courtlistener_external[0]['api'] ) { 
					$courtlistener = write_cache( $post_id, "CourtListener", $query, $courtlistener_external ); 
					//error_log("== Writing to cache."); 
			} 
			//else { error_log("== NO RESULTS TO PUT IN CACHE."); }
		}
		return $courtlistener;
		// otherwise get what we can from our cache, even if it is possible old and expired
		//else { $courtlistener = read_cache( $post_id, "CourtListener" ); }
		//error_log("*** CL results: " . print_r($courtlistener, true));
		//if( is_array($courtlistener) ) { $knowledge = array_merge($knowledge, $courtlistener); }
	}
	// 2. DPLA
	elseif( $api_name === 'DPLA' and is_string($settings['dpla_auth']) ) {
		$has_cache_expired = has_cache_expired( $post_id, "DPLA", $settings['days_to_cache'] );
		$dpla = read_cache( $post_id, "DPLA" );
		if($has_cache_expired === true or $dpla == false) {
			// our cache has expired, so try to get new data
			//error_log("== DPLA cache expired or cache not good.");
			$query = urlencode( build_search($array_of_terms, 'OR') ); // do an OR search
			$dpla_external = \PNKS\DPLA\do_search( $query, $settings['dpla_auth'] );
			// if we successfully retreived results, write them to the cache and use them
			if( is_array($dpla_external) and $dpla_external[0]['api'] ) { 
				$dpla = write_cache( $post_id, "DPLA", $query, $dpla_external ); 
				//error_log("== Writing to cache."); 
			} 
			//else { error_log("== NO RESULTS TO PUT IN CACHE."); }
		}
		return $dpla;
	}
	// 3. Crossref.org -- no Auth/Key needed
	elseif( $api_name === 'Crossref' ) {
		// check Crossref.org
		$has_cache_expired = has_cache_expired( $post_id, "Crossref", $settings['days_to_cache'] );
		$crossref = read_cache( $post_id, "Crossref" );
		if($has_cache_expired === true or $courtlistener == false) {
			// our cache has expired, so try to get new data
			//error_log("== Crossref cache expired or cache not good.");
			$query = urlencode( build_search($array_of_terms, 'OR') ); // do an OR search
			$crossref_external = \PNKS\Crossref\do_search( $query, true );
			// if we successfully retreived results, write them to the cache
			if( is_array($crossref_external) and $crossref_external[0]['api'] ) { 
				$crossref = write_cache( $post_id, "Crossref", $query, $crossref_external ); 
				//error_log("== Writing to cache."); 
			} 
			//else { error_log("== NO RESULTS TO PUT IN CACHE."); }
		}
		return $crossref;		
	}
	else { return; }

	//error_log("*** CL results: " . print_r($courtlistener, true));
	//error_log("*** DPLA results: " . print_r($dpla, true));
	//error_log("*** CR results: " . print_r($crossref, true));

	// now merge them together, alternating between all three arrays
	//$knowledge = array_merge_alternate($courtlistener, $dpla, $crossref);
	//var_dump($knowledge); 
	//$knowledge = array_merge($courtlistener, $dpla);
	//$knowledge = array_merge($knowledge, $crossref);
	//error_log("*** KNOWLEDGE results: " . print_r($knowledge, true));

	//return $knowledge;
}

///////////////////
function has_cache_expired( $post_id, $api_name, $approx_days_to_cache ) {
	// we're being told to reset, so immediately clear everything out
	$base_key = '_PNKS_Cached_' . $api_name; // the unique name for each category of meta data, based on the API
	if($_GET['pnks'] === 'reset') {
		delete_post_meta( $post_id, "PNKS_LastUpdated" );
		delete_post_meta( $post_id, $base_key . "SearchQuery" );
		delete_post_meta( $post_id, $base_key . "Results" );
		delete_post_meta( $post_id, $base_key . "LastUpdated" );
		return TRUE; // nothing in our cache
	}
	//// if we have no valid results stored, say it's expired
	////if( !is_array(read_cache($post_id, $api_name)) { return TRUE; } // not valid, so say has expired

	// get our global last updated time
	$last_updated_cached = get_post_meta( $post_id, "PNKS_LastUpdated" );
	$last_updated = $last_updated_cached[0];
	if( !is_string($last_updated) ) { return TRUE; } // no global last updated time, so say expired
	// and our API-specific one
	//$last_updated_cached = get_post_meta( $post_id, $base_key . "LastUpdated" );
	//$last_updated_api = $last_updated_cached[0];
	//if( !is_string($last_updated_api) ) { return TRUE; } // no API-specific last updated time, so say expired 

	// use the oldest to see if this set of results has expired
	//if($last_updated_global < $last_updated_api) { $last_updated = $last_updated_global; } // global is older (in secs since epoch)
	//else { $last_updated = $last_updated_api; } // otherwise use the API-specific value
	//error_log("= last updated found: for " . $base_key . " " . print_r($last_updated, true));
	
	// now check to see if expiration time has passed
	$approx_days_to_cache = approximate_cache_time_in_days($approx_days_to_cache);
	$seconds_to_cache = $approx_days_to_cache * 86400;
	$current_time = time();
	// so, has our cache expired?
	if( ($current_time - $last_updated) > $seconds_to_cache ) {
		// cache has expired
		error_log("= cache " . $post_id . " expired -- decided to cache for " . $approx_days_to_cache . " days -- last updated [" . 
			$last_updated . "] now [" . $current_time . "]");
		return TRUE; // expired
	}
	else {
		return FALSE; // still time, 
	}
}
function read_cache( $post_id, $api_name ) {
	$base_key = '_PNKS_Cached_' . $api_name; // the unique name for each category of meta data, based on the API
	//error_log("==== read_cache for " . $base_key);
	// not expired, so return our stored results
	$results = get_post_meta($post_id, $base_key . "Results", false);
	//error_log("= returning_cache_data for " . $base_key . ": " . print_r($results, true));
	if( is_array($results) and $results[0]['api']) { return $results; } // we've got cached material
	else { return FALSE; } // nothing in our cache
}
function write_cache( $post_id, $api_name, $search_query, $results ) {
	$base_key = '_PNKS_Cached_' . $api_name;
	//error_log("+ write_cache: " . $base_key);
	update_post_meta( $post_id, $base_key . "SearchQuery", $search_query ); // put it into a meta field 
	update_post_meta( $post_id, $base_key . "Results", $results );  
	update_post_meta( $post_id, $base_key . "LastUpdated", $search_query ); // put it into a meta field 
	update_post_meta( $post_id, "PNKS_LastUpdated", time() ); // along with last updated time 
	return $results;
}


function delete_cache_all( $post_id, $api_name ) {
	// delete out existing cache--but NOT if we're currently throttled because
	// that means we should hold on desperately to the cache we have, even
	// if it's outdated or bad
	$base_key = '_PNKS_Cached_' . $api_name;
	delete_post_meta( $post_id, "PNKS_LastUpdated" );
	delete_post_meta( $post_id, $base_key . "SearchQuery" );
	delete_post_meta( $post_id, $base_key . "Results" );
	delete_post_meta( $post_id, $base_key . "LastUpdated" );
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

/////////////////
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



