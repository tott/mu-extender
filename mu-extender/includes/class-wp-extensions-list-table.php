<?php
/**
 * Extension List Table class.
 *
 * @package WordPress
 * @subpackage List_Table
 * @since 3.1.0
 * @access private
 */
class WP_Extension_List_Table extends WP_List_Table {

	function __construct( $args = array() ) {
		global $status, $page;

		parent::__construct( array(
			'plural' => 'extensions',
			'screen' => isset( $args['screen'] ) ? $args['screen'] : null,
		) );

		$page = $this->get_pagenum();
	}

	function get_table_classes() {
		return array( 'widefat', $this->_args['plural'] );
	}

	function ajax_user_can() {
		return current_user_can('activate_extensions');
	}

	function display_tablenav( $which ) {
	?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">

			<div class="alignleft actions bulkactions">
				<?php $this->bulk_actions(); ?>
			</div>
	<?php
			$this->extra_tablenav( $which );
			$this->pagination( $which );
	?>

			<br class="clear" />
		</div>
	<?php
		}

	function prepare_items() {
		global $status, $extensions, $totals, $page, $orderby, $order, $s, $_wp_column_headers;

		wp_reset_vars( array( 'orderby', 'order', 's' ) );

		$extensions = array(
			'all' => apply_filters( 'all_extensions', MU_Extender::instance()->get_extensions() ),
		);

		$screen = $this->screen;

		set_transient( 'extension_slugs', array_keys( $extensions['all'] ), DAY_IN_SECONDS );

		foreach ( (array) $extensions['all'] as $extension_file => $extension_data ) {
			// Filter into individual sections
		}

		$totals = array();
		foreach ( $extensions as $type => $list )
			$totals[ $type ] = count( $list );

		if ( empty( $extensions[ $status ] ) && !in_array( $status, array( 'all', 'search' ) ) )
			$status = 'all';

		$this->items = array();
		foreach ( $extensions[ $status ] as $extension_file => $extension_data ) {
			// Translate, Don't Apply Markup, Sanitize HTML
			$_data = _get_plugin_data_markup_translate( $extension_file, $extension_data, false, true );
			$this->items[$extension_file] = apply_filters( 'wpext_extension_data_filter', $_data, $extension_file );
		}

		$total_this_page = $totals[ $status ];

		if ( $orderby ) {
			$orderby = ucfirst( $orderby );
			$order = strtoupper( $order );
			uasort( $this->items, array( $this, '_order_callback' ) );
		}

		$extensions_per_page = $this->get_items_per_page( str_replace( '-', '_', $screen->id . '_per_page' ), 999 );

		$start = ( $page - 1 ) * $extensions_per_page;

		if ( $total_this_page > $extensions_per_page )
			$this->items = array_slice( $this->items, $start, $extensions_per_page );

		$this->set_pagination_args( array(
			'total_items' => $total_this_page,
			'per_page' => $extensions_per_page,
		) );
	}

	function _search_callback( $extension ) {
		static $term;
		if ( is_null( $term ) )
			$term = wp_unslash( $_REQUEST['s'] );

		foreach ( $extension as $value )
			if ( stripos( $value, $term ) !== false )
				return true;

		return false;
	}

	function _order_callback( $extension_a, $extension_b ) {
		global $orderby, $order;

		$a = $extension_a[$orderby];
		$b = $extension_b[$orderby];

		if ( $a == $b )
			return 0;

		if ( 'DESC' == $order )
			return ( $a < $b ) ? 1 : -1;
		else
			return ( $a < $b ) ? -1 : 1;
	}

	function no_items() {
		global $extensions;

		if ( !empty( $extensions['all'] ) )
			_e( 'No extensions found.' );
		else
			_e( 'You do not appear to have any extensions available at this time.' );
	}

	function get_columns() {
		global $status;

		return array(
			//'cb'          => !in_array( $status, array( 'mustuse', 'dropins' ) ) ? '<input type="checkbox" />' : '',
			'name'        => __( 'Plugin' ),
			'description' => __( 'Description' ),
			'status' => __( 'Status' ),
		);
	}

	function get_sortable_columns() {
		return array();
	}

	function get_views() {
		global $totals, $status;

		$status_links = array();
		foreach ( $totals as $type => $count ) {
			if ( !$count )
				continue;

			switch ( $type ) {
				case 'all':
					$text = _nx( 'All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', $count, 'extensions' );
					break;
			}
		}

		return $status_links;
	}

	function get_bulk_actions() {
		global $status;

		$actions = array();
		return $actions;
	}

	function bulk_actions() {
		global $status;

		if ( in_array( $status, array( 'mustuse', 'dropins' ) ) )
			return;

		parent::bulk_actions();
	}

	function extra_tablenav( $which ) {
		global $status;

		return;
	}

	function current_action() {
		if ( isset($_POST['clear-recent-list']) )
			return 'clear-recent-list';

		return parent::current_action();
	}

	function display_rows() {
		global $status;
		foreach ( $this->items as $extension_file => $extension_data )
			$this->single_row( array( $extension_file, $extension_data ) );
	}

	function single_row( $item ) {
		global $status, $page, $s, $totals;
		list( $extension_file, $extension_data ) = $item;
		$context = $status;
		$screen = $this->screen;

		$extension_features = MU_Extender::instance()->extension_can( $extension_file );
		$actions = array();
		foreach( $extension_features as $feature => $val ) {
			if ( $feature == 'DEFINE_DEACTIVATION' ) {
				continue;
			}
			$active_feature = str_ireplace( '_DEACTIVATION', '_ACTIVATION', $feature );
			$actions[ $feature ] = '';
			$actions[ $active_feature ] = '';
		}

		$is_active = MU_Extender::instance()->is_extension_active( $extension_file, true );

		if ( true === $is_active || ( is_array( $is_active ) && ! in_array( 'DEFINE_DEACTIVATION', $is_active ) ) ) {
			if ( $is_active === true ) {
				foreach( $actions as $feature => $val ) {
					if ( ! preg_match( '#_DEACTIVATION$#i', $feature ) ) {
						continue;
					}
					$nonce = wp_create_nonce( $feature . '_' . $extension_file );
					$actions[$feature] = '<a href="?page=mu-extender&action=' . $feature . '&amp;extension=' . $extension_file . '&amp;extension_status=' . $context . '&amp;paged=' . $page . '&amp;s=' . $s . '&amp;activation_nonce=' . $nonce . '" title="' . esc_attr__( MU_Extender::instance()->get_deactivation_texts($feature) ) . '">' . MU_Extender::instance()->get_deactivation_texts($feature) . '</a>';
				}
			} else {
				foreach( $actions as $feature => $val ) {
					$deactivation_feature = str_ireplace( '_ACTIVATION', '_DEACTIVATION', $feature );
					if ( ! preg_match( '#_ACTIVATION$#i', $feature ) || ! in_array( $deactivation_feature, $is_active ) ) {
						if ( isset( $actions[$deactivation_feature] ) && ! in_array( $deactivation_feature, $is_active ) ) {
							$nonce = wp_create_nonce( $deactivation_feature . '_' . $extension_file );
							$actions[$deactivation_feature] = '<a href="?page=mu-extender&action=' . $deactivation_feature . '&amp;extension=' . $extension_file . '&amp;extension_status=' . $context . '&amp;paged=' . $page . '&amp;s=' . $s . '&amp;activation_nonce=' . $nonce . '" title="' . esc_attr__( MU_Extender::instance()->get_deactivation_texts($deactivation_feature) ) . '">' . MU_Extender::instance()->get_deactivation_texts($deactivation_feature) . '</a>';
						}
						continue;
					}
					$nonce = wp_create_nonce( $feature . '_' . $extension_file );
					$actions[$feature] = '<a href="?page=mu-extender&action=' . $feature . '&amp;extension=' . $extension_file . '&amp;extension_status=' . $context . '&amp;paged=' . $page . '&amp;s=' . $s . '&amp;activation_nonce=' . $nonce . '" title="' . esc_attr__( MU_Extender::instance()->get_activation_texts($feature) ) . '">' . MU_Extender::instance()->get_activation_texts($feature) . '</a>';
				}
			} // end if $is_active
		}

		$actions = apply_filters( $prefix . 'extension_action_links', array_filter( $actions ), $extension_file, $extension_data, $context );
		$actions = apply_filters( $prefix . "extension_action_links_$extension_file", $actions, $extension_file, $extension_data, $context );

		$class = $is_active ? 'active' : 'inactive';
		$checkbox_id =  "checkbox_" . md5($extension_data['Name']);
		$checkbox = "<label class='screen-reader-text' for='" . $checkbox_id . "' >" . sprintf( __( 'Select %s' ), $extension_data['Name'] ) . "</label>"
				. "<input type='checkbox' name='checked[]' value='" . esc_attr( $extension_file ) . "' id='" . $checkbox_id . "' />";

		$extension_name = $extension_file;
		if ( $extension_file != $extension_data['Name'] )
			$extension_name = $extension_data['Name'] . '<br/>' . $extension_name;

		if ( $extension_data['Description'] )
			$description .= '<p>' . $extension_data['Description'] . '</p>';

		$id = sanitize_title( $extension_name );
		if ( ! empty( $totals['upgrade'] ) && ! empty( $extension_data['update'] ) )
			$class .= ' update';

		echo "<tr id='$id' class='$class'>";

		list( $columns, $hidden ) = $this->get_column_info();
		foreach ( $columns as $column_name => $column_display_name ) {
			$style = '';
			if ( in_array( $column_name, $hidden ) )
				$style = ' style="display:none;"';

			switch ( $column_name ) {
				case 'cb':
					echo "<th scope='row' class='check-column'>$checkbox</th>";
					break;
				case 'name':
					echo "<td class='extension-title'$style><strong>$extension_name</strong>";
					echo $this->row_actions( $actions, true );
					echo "</td>";
					break;
				case 'description':
					echo "<td class='column-description desc'$style>
						<div class='extension-description'>$description</div>";
					echo "<div class='$class second extension-version-author-uri'>";

					$extension_meta = array();
					if ( !empty( $extension_data['Version'] ) )
						$extension_meta[] = sprintf( __( 'Version %s' ), $extension_data['Version'] );
					if ( !empty( $extension_data['Author'] ) ) {
						$author = $extension_data['Author'];
						if ( !empty( $extension_data['AuthorURI'] ) )
							$author = '<a href="' . $extension_data['AuthorURI'] . '" title="' . esc_attr__( 'Visit author homepage' ) . '">' . $extension_data['Author'] . '</a>';
						$extension_meta[] = sprintf( __( 'By %s' ), $author );
					}
					if ( ! empty( $extension_data['PluginURI'] ) )
						$extension_meta[] = '<a href="' . $extension_data['PluginURI'] . '" title="' . esc_attr__( 'Visit extension site' ) . '">' . __( 'Visit extension site' ) . '</a>';

					$extension_meta = apply_filters( 'extension_row_meta', $extension_meta, $extension_file, $extension_data, $status );
					echo implode( ' | ', $extension_meta );

					echo "</div>";

					if ( ! empty( $extension_data['extension_prefix'] ) ) {
						echo "<div class='$class extension-prefix'><strong>Extension Prefix:</strong> " . $extension_data['extension_prefix'] . "</div>";
					}

					if ( ! empty( $extension_data['extension_features'] ) ) {
						echo "<div class='$class extension-features'><strong>Supports:</strong> ";
						echo implode( ' | ', array_keys( $extension_data['extension_features'] ) );
						echo "</div>";
					}

					echo "</td>";
					break;
				case 'status':
					if ( true === $is_active ) {
						$status = 'Active';
					} else {
						$status = implode( "<br/>", $is_active );
					}
					echo "<td class='extension-status'$style><strong>$status</strong>";
					echo "</td>";
					break;
				default:
					echo "<td class='$column_name column-$column_name'$style>";
					do_action( 'manage_extensions_custom_column', $column_name, $extension_file, $extension_data );
					echo "</td>";
			}
		}

		echo "</tr>";

		do_action( 'after_extension_row', $extension_file, $extension_data, $status );
		do_action( "after_extension_row_$extension_file", $extension_file, $extension_data, $status );
	}
}
