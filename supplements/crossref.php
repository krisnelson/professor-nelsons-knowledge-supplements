<?php

namespace PNKS\Crossref;

function do_search ( $query, $auth ) { 	// e.g., https://api.crossref.org/works?query=privacy+law&sort=score
	$search_api_url = 'http://api.crossref.org/works' . '?query=' . $query . '&sort=score' ;
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
		//error_log("Crossref results: " . print_r($results, true) );
		// close out our curl request
		curl_close($curl);
	}
	//else { error_log("+ PNKS Crossref: Got results from transient cache"); }
    // get plugin settings
	$settings = (array) get_option( 'pnks-plugin-settings' );
	// check our results
	if( $results['message']['items'] ) { 
		//error_log("PNKS Crossref: Proper results found: " . print_r($results, true) );
		$days_to_cache = \PNKS\approximate_cache_time_in_days($settings['days_to_cache']);
		set_transient( $cache_key, $results, $days_to_cache*86400); // cache for roughly the requested time
		set_transient("PNKS-Crossref-Throttled", "Voluntarily limiting requests for 1 min as of " . date("m/d/Y h:i:sa"), 1*60); 
		return normalize_results($results);
	} 

	// no results
	$days_to_cache = round( \PNKS\approximate_cache_time_in_days($settings['days_to_cache']) / 2 ); // half that time on no results
	set_transient( $cache_key, $results, $days_to_cache*86400); // cache for roughly the requested time
	set_transient("PNKS-Crossref-Throttled", "Voluntarily limiting requests for 1 min as of " . date("m/d/Y h:i:sa"), 1*60); 
	return FALSE;
}
	
function normalize_results ( $results ) {
	// take Crossref materal and normalize it to what PNKS uses
	// title, author, date, url, summary, source_name, source_url
	// see: https://github.com/CrossRef/rest-api-doc/blob/master/api_format.md
	$normalized_results = array();
	// do we actually have any results
	if ($results['message']['items']) {
		// process our results and put into array
		foreach ($results['message']['items'] as $item) {
			$normalized_item = array();
			// skip over any records with info we don't care about (that lack titles, basically)
			if( empty($item['title']) ) { continue; } 
			elseif( is_array($item['title']) ) { $title = $item['title'][0]; }
			elseif( is_string($item['title']) ) { $title = $item['title']; }
			else { continue; } // if it's something else weird, just skip out

			$normalized_item['api'] = 'Crossref';

			$normalized_item['title'] = $title;
			if ( is_array($item['issued']) ) 	{ $normalized_item['date'] = $item['issued']['date-parts'][0][0]; }// print_r($item['issued'], true); } // $item['issued'][0]; }
			if ( is_string($item['DOI']) )		{ $normalized_item['url'] = 'https://doi.org/' . $item['DOI']; }
			if ( is_array($item['author']) ) 	{ 
				// sort of like http://blog.apastyle.org/apastyle/2011/11/the-proper-use-of-et-al-in-apa-style.html
				$normalized_item['author'] = $item['author']['0']['family-name'];
				if ( $item['author']['1']['family-name'] ) { $normalized_item['author'] .= "et al."; } // more than 1 author	
				elseif ( $item['author']['0']['given-name'] ) { $normalized_item['author'] .= ", " . $item['author']['0']['given-name']; } // 1 author only
				//print_r($item['author'][0], true); 
			}
			if( $item['publisher'] ) { $normalized_item['source_name'] = $item['publisher']; }

			//if ( is_array($item['subject']) )	{ $normalized_item['summary'] = print_r($item['subject'][0], true); } // implode( ', ', $item['subject'] ); }

			//error_log( "CR normalized item: " . print_r($normalized_item, true) );

			//error_log( "CR item: " . print_r($item, true) );


			$normalized_results[] = $normalized_item;
		}
		return $normalized_results;
	}
	else { 
		// no results to normalize
		return ''; 
	}
}