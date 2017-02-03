<?php


/**
 * Defines the DPLA widget class (see: https://dp.la)  
 */
class pnks_DPLA_Widget extends WP_Widget {

	/**
	 * Class constructor
	 */
	public function __construct() {
		parent::__construct(
			'pnks-dpla', // Base ID
			__( 'PNKS: Digital Public Library of America', 'pnks' ), // Title
			array( 'description' => __( 'Adds data via DPLA API.', 'pnks' ) )
		);
	}

	/**
	 * Displays the widget content on the front end
	 *
	 * @param array $args     Display arguments including 'before_title', 'after_title', 'before_widget', and 'after_widget'.
	 * @param array $instance Settings for the current widget instance.
	 */
	public function widget( $args, $instance ) {
		// only on single post pages 
		if ( !is_single() ){ return; }
		// Collects from widget input fields.
		$title_unfiltered = ( !empty( $instance['title'] ) ) ? sanitize_text_field( $instance['title'] ) : '';
		$title_filtered = apply_filters( 'widget_title', $title_unfiltered, $instance, $this->id_base );
		$title = !empty( $title_filtered ) ? $args['before_title'] . $title_filtered . $args['after_title'] : '';
		$hide_on_no_results = ( !empty( $instance['hide_on_no_results'] ) ) ? 'true' : 'false';
		$max = ( !empty( $instance['max'] ) ) ? sanitize_text_field( $instance['max'] ) : '';
		$auth = ( !empty( $instance['auth'] ) ) ? sanitize_text_field( $instance['auth'] ) : '';

		// skip out if no auth key 
		if( empty($instance['auth']) ) { 
			echo $args['before_widget'];
			echo $title;
			echo "<h4>No DPLA authorization key.</h4>"; 
			echo $args['after_widget'];
			return; 
		}

		// build and run our query against the DPLA API
		global $wp_query;
		global $wpdb;
		$post_id = $wp_query->post->ID;
		$search_api_url = 'http://api.dp.la/v2/items?sourceResource.type=text&q=';

		// do the search
		$search_results = _dpla_search($post_id, $search_api_url, $auth);
		//echo "<pre>" . var_dump($search_results['docs']) . "</pre>"; 

		// skip all display if there are no results
		if ( $hide_on_no_results == 'true'
				and empty($search_results) ) { return; }

		// start our widget output
		echo $args['before_widget'];
		echo $title;
		echo "<div class='results'>";

		// do we actually have any results
		if ($search_results['docs']) {
			//echo "<pre>" . var_dump($search_results['docs']) . "</pre>";

			// process our results and display them
			$countItems = 0;
			foreach ($search_results['docs'] as $item) {
				// skip over any records with info we don't care about (that lack titles, basically)
				if( empty($item['sourceResource']['title']) ) { next; } 
				elseif( is_array($item['sourceResource']['title']) ) { $title = $item['sourceResource']['title'][0]; }
				elseif( is_string($item['sourceResource']['title']) ) { $title = $item['sourceResource']['title']; }
				else { next; } // if it's something else weird, just skip out

				// limit the items we display based on settings
				$countItems++;
				if ($max and $countItems > $max) { break; }

				// extract first part of the description
				if( is_string($item['sourceResource']['description']) ) { 
					$description = substr($item['sourceResource']['description'], 0, 250) . " ..."; 
				}
				else $description = "Resource provided by Digital Public Library.";

				// and get a date
				if( is_string($item['sourceResource']['date']['0']['displayDate']) ) {
					$date = $item['sourceResource']['date']['0']['displayDate'];
				}
				elseif ( is_string($item['sourceResource']['date']['displayDate']) ) {
					$date = $item['sourceResource']['date']['displayDate'];
				}

				// and a 

				// and show results
				echo "<p><a ";
				echo " title='" . addslashes( strip_tags($description) ). "' ";
				echo " href='" . $item['isShownAt'] . "'>" . $title . "</a>";
				echo " (" . $date . ")";
				echo   "<br/><small><em>via " . $item['provider']['name'] . "</em></small>";
				echo "</p>";

				//echo "<pre>" . var_dump($item['provider']['name']) . "</pre><br/>";

			}
		}
		elseif( get_transient("DPLAThrottled") ) {
			// has CL throttled us?		
			echo "<p><a title='" . strip_tags( get_transient("DPLAThrottled") ) . " [" . $post_id . "]'>" . 
				"Temporarily unavailable.</a></p>";
			//echo "<h5>" . var_dump($search_results) . "</h5>";
		}
		else {
			// otherwise no results to show 
			echo "<p>No relevant items found.</p>"; 
		}
		// show a manual URL based on which search we used (or blank if no results)
		echo "<p><small><em>See the <a title='" . 
			\PNKS\read_cache_key($post_id, "DPLASearchQuery") . 
			"' href='https://dp.la/'>Digital Public Library of America &raquo;</a></em></small></p>";

			/////// debug output
			//echo "<div style='color: #dedede'>";
			//$last_updated = \PNKS\read_cache_key($post_id, "DPLALastUpdated");
			//if($last_updated) {
			//	echo "<br/><small><em>[" . date( DATE_RFC2822, $last_updated ) . "]</em></small>";
			//	echo "<a href=" . $search_api_url . \PNKS\read_cache_key($post_id, "DPLASearchQuery") . '&api_key=' . $auth . "> search query</a>";
			//}
			//echo "</div>";
			//// end debug output
		echo "</div>";
		echo $args['after_widget'];
	}

	/**
	 * Updates the settings when the widget instance is saved
	 *
	 * @param  array $new_instance New settings for this instance as input by the user via WP_Widget::form().
	 * @param  array $old_instance Old settings for this instance.
	 * @return array Updated settings to save.
	 */
	 public function update( $new_instance, $old_instance ) {
		$instance = $old_instance;
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
   		$instance[ 'hide_on_no_results' ] = $new_instance[ 'hide_on_no_results' ];
		$instance['max'] = sanitize_text_field( $new_instance['max'] );
		$instance['auth'] = sanitize_text_field( $new_instance['auth'] );

		return $instance;
	}

	/**
	 * Displays the widget settings form on the back end
	 *
	 * @param array $instance Current settings.
	 */
	public function form( $instance ) {
		$instance['title'] = !empty( $instance['title'] ) ? esc_attr( $instance['title'] ) : '';
		$instance['hide_on_no_results'] = !empty( $instance['hide_on_no_results'] ) ? esc_attr( $instance['hide_on_no_results'] ) : '';
		$instance['max'] = !empty( $instance['max'] ) ? esc_attr( $instance['max'] ) : '';
		$instance['auth'] = !empty( $instance['auth'] ) ? esc_attr( $instance['auth'] ) : '';
		?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( 'Title:', 'krisnelson' ); ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id('title') ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
			</p>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'auth' ) ); ?>"><?php _e( 'Authorization Token:', 'krisnelson' ); ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id('auth') ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'auth' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['auth'] ); ?>" />
			</p>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'max' ) ); ?>"><?php _e( 'Maximum Results:', 'krisnelson' ); ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id('max') ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'max' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['max'] ); ?>" />
			</p>
			<p>
			    <input class="checkbox" type="checkbox" <?php checked( $instance[ 'hide_on_no_results' ], 'on' ); ?> id="<?php echo $this->get_field_id( 'hide_on_no_results' ); ?>" name="<?php echo $this->get_field_name( 'hide_on_no_results' ); ?>" /> 
			    <label for="<?php echo $this->get_field_id( 'hide_on_no_results' ); ?>">Hide widget when search returns no results.</label>
			</p>


		<?php
	}
}



function _dpla_search( $post_id, $api_url, $auth ) {
	// check cache
	$search_results = \PNKS\check_for_cached_results($post_id, "DPLA");
	if($search_results) { //echo "*** found cached results"; 
		return $search_results; }

	// if we are throttled, we're done now
		//set_transient("CourtListenerThrottled", 'Skipping throttle', 1); 
	if( get_transient("DPLAThrottled") ) { return FALSE; }
	else { // otherwise, set up a short, voluntary throttle
		//set_transient("DPLAThrottled", "Waiting in order to be respectful of DPLA resources. [" . $post_id . "]", 30);
	}

	// prep search from post tags
	$array_of_terms = wp_get_post_tags($post_id, array( 'fields' => 'names' ));
	$search_string = \PNKS\build_search($array_of_terms, "OR"); // do an OR search
	$search_query = urlencode($search_string);
	$search_results = _dpla_curl($api_url . $search_query, $auth); // returns regular array

	if($search_results['docs']) { 
		\PNKS\write_cache($post_id, "DPLA", $search_query, $search_results ); 
		return $search_results; 
	}

	// otherwise return FALSE
	return FALSE;
}
function _dpla_curl( $search_api_url, $auth ) {
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

