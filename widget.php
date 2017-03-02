<?php
/**
 * Defines the DPLA widget class (see: https://dp.la)  
 */
class pnks_Multi_Widget extends WP_Widget {

	/**
	 * Class constructor
	 */
	public function __construct() {
		parent::__construct(
			'pnks-multiwidget', // Base ID
			__( 'PN Knowledge Supplements', 'pnks' ), // Title
			array( 'description' => __( 'Adds data via various APIs.', 'pnks' ) )
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
		//error_log("=============  title: " . $title);
		$show_courtlistener = ( !empty( $instance['courtlistener'] ) ) ? 'true' : 'false';
		//error_log("show cl: " . $show_courtlistener);
		$show_dpla = ( !empty( $instance['dpla'] ) ) ? 'true' : 'false';
		//error_log("show dpla: " . $show_dpla);
		$show_crossref = ( !empty( $instance['crossref'] ) ) ? 'true' : 'false';
		//error_log("show cr: " . $show_crossref);
		$hide_on_no_results = ( !empty( $instance['hide_on_no_results'] ) ) ? 'true' : 'false';
		$max_items = ( !empty( $instance['max'] ) ) ? sanitize_text_field( $instance['max'] ) : '10';
		$leading_css = ( !empty( $instance['leading_css'] ) ) ? sanitize_text_field( $instance['leading_css'] ) : '';


		// build and run our queries against the various APIs
		global $wp_query;
		global $wpdb;
		$post_id = $wp_query->post->ID;
		// overall plugin settings
		$settings = (array) get_option( 'pnks-plugin-settings' );
		
		// do the search
		//$knowledge = \PNKS\get_supplemental_knowledge($post_id);
		//var_dump($knowledge); 
		//error_log("+ KNOWLEDGE results in widget: " . print_r($knowledge, true));

		// get data from the requested supplemental knowledge sources (APIs)
		$courtlistener = array(); $dpla = array(); $crossref = array();
		// prep search from post tags
		$array_of_terms = wp_get_post_tags($post_id, array( 'fields' => 'names' ));
		// now see which sources we should query--use meta caching here
		$cache_args = array(
			'storage' => 'meta',
			'meta_type' => 'post',
			'object_id' => $post_id,
			'ttl' => 86400*\PNKS\approximate_cache_time_in_days($settings['days_to_cache']) // convert to seconds
		);
		// reset our cached items if requested
		// now go check cache/external APIs
		if($show_courtlistener === 'true') { 
			$PNKSCache = new \PNKS\CacheFragments( 'CourtListener', $cache_args );
			if ( isset($_GET['pnks']) and $_GET['pnks'] === 'reset' ) { $PNKSCache->erase(); }
			$courtlistener = $PNKSCache->read();
			if ( false === $courtlistener ) {
				$query = urlencode( \PNKS\build_search($array_of_terms, 'OR') ); 
				$courtlistener = \PNKS\CourtListener\do_search( $query, $settings['courtlistener_auth'] ); 	
				if ( $courtlistener !== false ) { $PNKSCache->write( $courtlistener ); }
			} //else error_log("found courtlistener in frag cache");
			unset($PNKSCache);
		}
		if($show_dpla === 'true') { 
			$PNKSCache = new \PNKS\CacheFragments( 'DPLA', $cache_args );
			if ( isset($_GET['pnks']) and $_GET['pnks'] === 'reset' ) { $PNKSCache->erase(); }
			$dpla = $PNKSCache->read();
			if ( false === $dpla ) {
				$query = urlencode( \PNKS\build_search($array_of_terms, 'OR') ); 
				$dpla = \PNKS\DPLA\do_search( $query, $settings['dpla_auth'] ); 
				if ( $dpla !== false ) { $PNKSCache->write( $dpla ); }
			} //else error_log("found DPLA in frag cache");
			unset($PNKSCache);
		}
		if($show_crossref === 'true') { 
			$PNKSCache = new \PNKS\CacheFragments( 'Crossref', $cache_args );
			if ( isset($_GET['pnks']) and $_GET['pnks'] === 'reset' ) { $PNKSCache->erase(); }
			$crossref = $PNKSCache->read();
			if ( false === $crossref ) {
				$query = urlencode( \PNKS\build_search($array_of_terms, '') ); 
				$crossref = \PNKS\Crossref\do_search( $query, true ); 	
				if ( $crossref !== false ) { $PNKSCache->write( $crossref ); }
			} //else error_log("found crossref in frag cache");
			unset($PNKSCache);
		}


		$knowledge = \PNKS\array_merge_alternate($courtlistener, $dpla, $crossref);
		//error_log( "=== PNKS widget merged knowledge: \n " . print_r($knowledge, true) );

		// skip all display if there are no results
		if ( $hide_on_no_results === 'true'
					and ( !$knowledge or !is_array($knowledge) ) 
				) { return; }

		// start our widget output:
		// title, author, date, url, summary, author, cite, source_name, source_url
		echo $args['before_widget'];
		if($leading_css) {
			echo "<style>" . $leading_css . "</style>";
		}
		echo $title;
		echo "<div class='results'>";

		if( !$knowledge or !is_array($knowledge) ) { echo "<p>No items found.</p>"; }
		else {
			$output = '';
			$output = '<ul class="pnks">';

			$countItems = 0;
			//$foundCL = false; $foundDPLA = false; $foundCR = false;
			//var_dump($knowledge);
			foreach ($knowledge as $item) {
				if( !is_array($item) ) { continue; }

				//error_log( print_r($item['api'], true) );

				//if( $item['api'] === 'Court Listener' and $show_courtlistener === 'false') { continue; }
				//elseif( $item['api'] === 'DPLA' and $show_dpla === 'false') { continue; }
				//elseif( $item['api'] === 'Crossref' and $show_crossref === 'false') { continue; }

				if( $countItems >= $max_items or $countItems > 1000) { break; }

				$output .= "<li class='pnks'>";
				if ( $item['url'] ) 	{ $output .= "<a class='pnks' target='_blank' "; }
				if ( $item['summary'] )	{ $output .= " title='" . addslashes( strip_tags($item['summary']) ). "' "; }
				if ( $item['url'] ) 	{ $output .= " href='" . $item['url'] . "'>"; }
				if ( $item['title'] )	{ $output .= $item['title']; } else { $output.= 'No title'; } 
				if ( $item['url'] ) 	{ $output .= "</a>"; }
				$output .= "<small>";
				if ( $item['cite'] ) 	{ $output .= ", " . $item['cite']; }
 				if ( $item['author'] )	{ $output .= " by " . $item['author']; }
				if ( $item['source_name'] )	{ $output .= " <em>via " . $item['source_name'] . "</em>"; }
				if ( $item['date'] ) 	{ $output .= " (" . $item['date'] . ")"; }
				//$output .= " <small>(" . $item['api'] . ")</small>";
				$output .= "</small>";
				$output .= "</li>";

				//if($item['api'] === 'Court Listener') {$foundCL = true; }
				//elseif($item['api'] === 'DPLA') {$foundDPLA = true; }
				//elseif($item['api'] === 'Crossref') {$foundCR = true; }


				$countItems++;
			}
			$output .= "</ul>";

			echo $output;
		}

		if( $foundCL === false and $show_courtlistener === 'true' and get_transient("PNKS-CourtListener-Throttled") ) {
			echo "<br/><p><small><em><a title='" . strip_tags( get_transient("PNKS-CourtListener-Throttled") ) . "'>" . 
				"Court Listener temporarily unavailable.</a></em></small></p>";		
		}
		if( $foundDPLA === false and $show_dpla === 'true' and get_transient("PNKS-DPLA-Throttled") ) {
			echo "<br/><p><small><em><a title='" . strip_tags( get_transient("PNKS-DPLA-Throttled") ) . "'>" . 
				"DPLA temporarily unavailable.</a></em></small></p>";		
		}
		if( $foundCR === false and $show_crossref === 'true' and get_transient("PNKS-Crossref-Throttled") ) {
			echo "<br/><p><small><em><a title='" . strip_tags( get_transient("PNKS-Crossref-Throttled") ) . "'>" . 
				"Crossref.org temporarily unavailable.</a></em></small></p>";		
		}

		//if ($show_courtlistener !== 'false') { echo "<br/><small>Showing Court Listener</small>"; }
		//if ($show_dpla !== 'false') { echo "<br/><small>Showing DPLA</small>"; }
		//if ($show_crossref !== 'false') { echo "<br/><small>Showing Crossref.org</small>"; }


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
		$instance['courtlistener'] = sanitize_text_field( $new_instance['courtlistener'] );
		$instance['dpla'] = sanitize_text_field( $new_instance['dpla'] );
		$instance['crossref'] = sanitize_text_field( $new_instance['crossref'] );
   		$instance['hide_on_no_results' ] = $new_instance[ 'hide_on_no_results' ];
		$instance['max'] = sanitize_text_field( $new_instance['max'] );
		$instance['leading_css'] = sanitize_text_field( $new_instance['leading_css'] );

		return $instance;
	}

	/**
	 * Displays the widget settings form on the back end
	 *
	 * @param array $instance Current settings.
	 */
	public function form( $instance ) {
		$instance['title'] = !empty( $instance['title'] ) ? esc_attr( $instance['title'] ) : '';
		$instance['courtlistener'] = !empty( $instance['courtlistener'] ) ? esc_attr( $instance['courtlistener'] ) : '';
		$instance['dpla'] = !empty( $instance['dpla'] ) ? esc_attr( $instance['dpla'] ) : '';
		$instance['crossref'] = !empty( $instance['crossref'] ) ? esc_attr( $instance['crossref'] ) : '';
		$instance['hide_on_no_results'] = !empty( $instance['hide_on_no_results'] ) ? esc_attr( $instance['hide_on_no_results'] ) : '';
		$instance['max'] = !empty( $instance['max'] ) ? esc_attr( $instance['max'] ) : '';
		$instance['leading_css'] = !empty( $instance['leading_css'] ) ? esc_attr( $instance['leading_css'] ) : '';
		?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( 'Title:', 'pnks' ); ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id('title') ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
			</p>

			<!-- which external sources for this widget? -->
			<p>
			    <input class="checkbox" type="checkbox" <?php checked( $instance[ 'courtlistener' ], 'on' ); ?> id="<?php echo $this->get_field_id( 'courtlistener' ); ?>" name="<?php echo $this->get_field_name( 'courtlistener' ); ?>" /> 
			    <label for="<?php echo $this->get_field_id( 'courtlistener' ); ?>">Search Court Listener.</label>
			</p>

			<p>
			    <input class="checkbox" type="checkbox" <?php checked( $instance[ 'dpla' ], 'on' ); ?> id="<?php echo $this->get_field_id( 'dpla' ); ?>" name="<?php echo $this->get_field_name( 'dpla' ); ?>" /> 
			    <label for="<?php echo $this->get_field_id( 'dpla' ); ?>">Search Digital Public Library of America.</label>
			</p>		
			<p>
			    <input class="checkbox" type="checkbox" <?php checked( $instance[ 'crossref' ], 'on' ); ?> id="<?php echo $this->get_field_id( 'crossref' ); ?>" name="<?php echo $this->get_field_name( 'crossref' ); ?>" /> 
			    <label for="<?php echo $this->get_field_id( 'crossref' ); ?>">Search Crossref.org.</label>
			</p>			
			<!-- --- -->

			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'max' ) ); ?>"><?php _e( 'Maximum Results:', 'pnks' ); ?></label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id('max') ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'max' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['max'] ); ?>" />
			</p>
			<p>
			    <input class="checkbox" type="checkbox" <?php checked( $instance[ 'hide_on_no_results' ], 'on' ); ?> id="<?php echo $this->get_field_id( 'hide_on_no_results' ); ?>" name="<?php echo $this->get_field_name( 'hide_on_no_results' ); ?>" /> 
			    <label for="<?php echo $this->get_field_id( 'hide_on_no_results' ); ?>">Hide widget when search returns no results.</label>
			</p>

			<p> <strong> ADVANCED CUSTOMIZATION </strong>
				You can put custom CSS styles in here. The output of the widget starts with a "ul" of class "pnks" and then each
				items is an "li" also of class "pnks." Additionally, each link ("a") also has a class of "pnks." So you can style "ul.pnks," "li.pnks," and "a.pnks" if you want to target what this widget spits out (but note that it will affect ALL PNKS widgets on the page, at least for now).
			</p>
			<textarea id="<?php echo $this->get_field_id( 'leading_css' ); ?>" 
				name="<?php echo $this->get_field_name( 'leading_css' ); ?>">
					<?php echo esc_attr( $instance['leading_css'] ); ?>

			</textarea>

		<?php
	}
}

