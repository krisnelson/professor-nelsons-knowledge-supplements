<?php
require_once('courtlistener-lib.php');
/**
 * Defines the Court Listener widget class
 */
class pnks_CourtListener_Widget extends WP_Widget {

	/**
	 * Class constructor
	 */
	public function __construct() {
		parent::__construct(
			'pnks-courtlistener', // Base ID
			__( 'PNKS: Court Listener', 'pnks' ), // Title
			array( 'description' => __( 'Adds data via Court Listener API.', 'pnks' ) )
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
			echo "<h4>No Court Listener authorization key.</h4>"; 
			echo $args['after_widget'];
			return; 
		}

		// build and run our query against the CourtListener API
		global $wp_query;
		global $wpdb;
		$post_id = $wp_query->post->ID;

		// do the search
		$search_results = \PNKS\CourtListener\get_search_results($post_id, $auth);
		// skip all display if there are no results
				//echo var_dump($search_results);

		if ( $hide_on_no_results == 'true'
				and empty($search_results) ) { return; }

		// start our widget output
		echo $args['before_widget'];
		echo $title;
		echo "<div class='results'>";

		//echo var_dump($search_results);
		// do we actually have any results?
		//echo "<h5>" . var_dump($search_results) . "</h5>";

		if ($search_results['results']) {
			// process our results and display them
			$countItems = 0;
			foreach ($search_results['results'] as $case) {
				$countItems++;
				if ($max and $countItems > $max) { break; }
				echo "<p><a ";
				echo " title='" . addslashes( strip_tags($case['snippet']) ) . "' ";
				echo " href='https://www.courtlistener.com/" . $case['absolute_url'] . "'>" . $case['caseName'] . "</a>, " . $case['citation']['0'] . " (" . date( 'Y', strtotime($case['dateFiled']) ) . ")</p>";
			}
		}
		//elseif( $search_results['detail'] ) {
			// has CL throttled us?		
			//echo "<p><strong>Court Listener is limiting searches:</strong> " . $search_results['detail'] . "</p>";
			//echo "<h5>" . var_dump($search_results) . "</h5>";
		//}
		elseif( get_transient("CourtListenerThrottled") ) {
			// has CL throttled us?		
			echo "<p><a title='" . strip_tags( get_transient("CourtListenerThrottled") ) . " [" . $post_id . "]'>" . 
				"Temporarily unavailable.</a></p>";
			//echo "<h5>" . var_dump($search_results) . "</h5>";
		}
		else {
			// otherwise no results to show 
			echo "<p>No relevant cases found.</p>"; 
		}
		// show a manual URL based on which search we used (or blank if no results)
		echo "<p><small><em><a href='https://www.courtlistener.com/?q=" . 
			\PNKS\read_cache_key($post_id, "CourtListenerSearchQuery") . 
			"'>Search Court Listener &raquo;</a></em></small></p>";

			/////// debug output
			//echo "<div style='color: #dedede'>";
			//$last_updated = \PNKS\read_cache_key($post_id, "CourtListenerLastUpdated");
			//if($last_updated) {
			//	echo "<br/><small><em>[" . date( DATE_RFC2822, $last_updated ) . "]</em></small>";
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


