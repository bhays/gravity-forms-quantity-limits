<?php
/*
Plugin Name: Gravity Forms Quantiy Limits
Plugin URI: https://github.com/bhays/gravity-forms-limiter
Description: Limit specific Gravity Forms quantity fields
Version: 0.6.2
Author: Ben Hays
Author URI: http://benhays.com

------------------------------------------------------------------------
Copyright 2013 Ben Hays
last updated: March 25, 2013

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

//Version Control
$gf_limit_file = __FILE__;

if ( isset( $plugin ) ) {
	$gf_limit_file = $plugin;
}
else if ( isset( $mu_plugin ) ) {
	$gf_limit_file = $mu_plugin;
}
else if ( isset( $network_plugin ) ) {
	$gf_limit_file = $network_plugin;
}

define( 'GF_LIMIT_FILE', $gf_limit_file );
define( 'GF_LIMIT_PATH', WP_PLUGIN_DIR . '/' . basename( dirname( $gf_limit_file ) ) );

add_action( 'init', array( 'GFLimit', 'init' ) );

register_activation_hook( GF_LIMIT_FILE, array( 'GFLimit', 'add_permissions' ) );

class GFLimit {

	public static $version = '0.6.2';

	private static $min_gravityforms_version = '1.6';
	private static $path = "gravity-forms-limiter/gravity-forms-limiter.php";
	private static $slug = "gravity-forms-limiter";
	
	private static $m_sold_out = "Sorry, this item is sold out.";
	private static $m_validation = "You ordered {ordered} items. There are only {remaining} items left.";
	private static $m_remainder = "{remaining} items remaining.";

	//Plugin starting point. Will load appropriate files
	public static function init() {
		//supports logging
	    add_filter( 'gform_logging_supported', array( 'GFLimit', 'gform_logging_supported' ) );

		if ( basename( $_SERVER['PHP_SELF'] ) == 'plugins.php' ) {

			//loading translations
			load_plugin_textdomain( 'gf-limit', FALSE, dirname( GF_LIMIT_FILE ).'/languages' );

		}

		if ( ! self::is_gravityforms_supported() )
			return;

		//loading data lib
		require_once( GF_LIMIT_PATH . '/inc/data.php' );
			
		// load our limit functions
		require_once( GF_LIMIT_PATH . '/inc/gf_field_sum.php' );

		if ( is_admin() ) {

			//runs the setup when version changes
			self::setup();

			//loading translations
			load_plugin_textdomain( 'gf-limit', FALSE, dirname( GF_LIMIT_FILE ).'/languages' );

			//integrating with Members plugin
			if ( function_exists( 'members_get_capabilities' ) )
				add_filter( 'members_get_capabilities', array( 'GFLimit', 'members_get_capabilities' ) );

			//creates the subnav left menu
			add_filter( 'gform_addon_navigation', array( 'GFLimit', 'gform_addon_navigation' ) );

			if ( self::is_limit_page() ) {

				//enqueueing sack for AJAX requests
				wp_enqueue_script( array( 'sack' ) );
				
				//loading Gravity Forms tooltips
				require_once( GFCommon::get_base_path().'/tooltips.php' );
				add_filter( 'gform_tooltips', array( 'GFLimit', 'gform_tooltips' ) );

			}
			else if ( in_array( RG_CURRENT_PAGE, array( 'admin-ajax.php' ) ) ) {

				add_action( 'wp_ajax_gf_limit_update_feed_active', array( 'GFLimit', 'gf_limit_update_feed_active' ) );
				add_action( 'wp_ajax_gfp_select_limit_form', array( 'GFLimit', 'gfp_select_limit_form' ) );

			}
			else if ( 'gf_entries' == RGForms::get( 'page' ) ) {

			}
		}
		else {
			// Load feed data
			$feeds = GFLimitData::get_feeds();
			
			// Cycle through feeds and add limits to the form
			foreach( $feeds as $k=>$v ) {
				// Enable if active
				if( $v['is_active'] ){
					// Replace variable strings
					// {remaining} for remaining tickets
					// {ordered} for tickets ordered
					$validation = str_replace('{remaining}', '%2$s', $v['meta']['messages']['validation']);
					$validation = str_replace('{ordered}', '%1$s', $validation);
					$remainder = str_replace('{remaining}', '%1$s', $v['meta']['messages']['remainder']);
					$sold_out = $v['meta']['messages']['sold_out'];				
				
					new GWLimitBySum(array(
						'form_id' => $v['form_id'],
						'field_id' => $v['field_id'],
						'limit' => $v['quantity_limit'],
						'limit_message' => '<span class="error-notice">'.$sold_out.'</span>',
						'validation_message' => $validation,
						'remainder_message' => '<span class="remaining">'.$remainder.'</span>',
					));
				}
			}
		}
	}

	public static function gf_limit_update_feed_active() {
		check_ajax_referer( 'gf_limit_update_feed_active', 'gf_limit_update_feed_active' );
		$id   = $_POST['feed_id'];
		$feed = GFLimitData::get_feed( $id );
		GFLimitData::update_feed( $id, $feed['form_id'], $feed['field_id'], $feed['quantity_limit'], $_POST['is_active'], $feed['meta'] );
	}

	// Create left nav menu under Forms
	public static function gform_addon_navigation( $menus ) {

		// Adding submenu if user has access
		$permission = self::has_access( 'gf_limit' );
		if ( ! empty( $permission ) )
			$menus[ ] = array(
				'name'       => 'gf_limit',
				'label'      => __( 'Quantity Limits', 'gf-limit' ),
				'callback'   => array( 'GFLimit', 'limit_page' ),
				'permission' => $permission );

		return $menus;
	}

	// Update or create database tables only when version number changes
	private static function setup() {
		if ( get_option( 'gf_limit_version' ) != self::$version ) {
			require_once( GF_LIMIT_PATH . '/inc/data.php' );
			GFLimitData::update_table();
		}

		update_option( 'gf_limit_version', self::$version );
	}

	//Adds feed tooltips to the list of tooltips
	public static function gform_tooltips( $tooltips ) {
		$limit_tooltips = array(
			'limit_gravity_form'         => '<h6>'.__( 'Gravity Form', 'gf-limit' ).'</h6>'.__( 'Select which Gravity Forms you would like to limit fields on.', 'gf-limit' ),
			'limit_field'                => '<h6>'.__( 'Field', 'gf-limit' ).'</h6>'.__( 'Select the field you would like to limit. Only product fields are listed.', 'gf-limit' ),
			'limit_limit'                => '<h6>'.__( 'Limit', 'gf-limit' ).'</h6>'.__( 'Enter the limit you would like to set for the field.', 'gf-limit' ),
			'limit_message_sold_out'     => '<h6>'.__( 'Sold Out Message', 'gf-limit' ).'</h6>'.__( 'Message to be displayed when item has reached the set limit', 'gf-limit' ),
			'limit_message_validation'   => '<h6>'.__( 'Validation Message', 'gf-limit' ).'</h6>'.__( 'Message to be displayed when someone selects too many of a quantity.', 'gf-limit' ),
			'limit_message_remainder'    => '<h6>'.__( 'Remainder Message', 'gf-limit' ).'</h6>'.__( 'Message to be displayed for remaining items', 'gf-limit' ),
			
		);
		return array_merge( $tooltips, $limit_tooltips );
	}

	public static function limit_page() {
		$view = rgget( 'view' );
		if ( 'edit' == $view )
			self::edit_page( rgget( 'id' ) );
		else
			self::list_page();
	}

//------------------------------------------------------
//------------- FEED LISTS PAGE -----------------
//------------------------------------------------------
	private static function list_page() {
		if ( ! self::is_gravityforms_supported() ) {
			die( __( sprintf( 'Quantity Limit Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.', self::$min_gravityforms_version, '<a href="plugins.php">', '</a>' ), 'gf-limit' ) );
		}

		if ( 'delete' == rgpost( 'action' ) ) {
			check_admin_referer( 'list_action', 'gf_limit_list' );

			$id = absint( $_POST['action_argument'] );
			GFLimitData::delete_feed( $id );
			?>
		<div class="updated fade" style="padding:6px"><?php _e( 'Limit removed.', 'gf-limit' ) ?></div>
		<?php
		}
		else if ( ! empty( $_POST['bulk_action'] ) ) {
			check_admin_referer( 'list_action', 'gf_limit_list' );
			$selected_feeds = $_POST['feed'];
			if ( is_array( $selected_feeds ) ) {
				foreach ( $selected_feeds as $feed_id ) {
					GFLimitData::delete_feed( $feed_id );
				}
			}
			?>
		<div class="updated fade" style="padding:6px"><?php _e( 'Feeds deleted.', 'gf-limit' ) ?></div>
		<?php
		}

		?>
	<div class="wrap">

		<h2><?php
			_e( 'Quantity Limits on Forms', 'gf-limit' );
			?>
			<a class="add-new-h2"
				 href="admin.php?page=gf_limit&view=edit&id=0"><?php _e( 'Add New', 'gf-limit' ) ?></a>

		</h2>

		<form id="feed_form" method="post">
			<?php wp_nonce_field( 'list_action', 'gf_limit_list' ) ?>
			<input type="hidden" id="action" name="action"/>
			<input type="hidden" id="action_argument" name="action_argument"/>

			<div class="tablenav">
				<div class="alignleft actions" style="padding:8px 0 7px 0;">
					<label class="hidden" for="bulk_action"><?php _e( 'Bulk action', 'gf-limit' ) ?></label>
					<select name="bulk_action" id="bulk_action">
						<option value=''> <?php _e( 'Bulk action', 'gf-limit' ) ?> </option>
						<option value='delete'><?php _e( 'Delete', 'gf-limit' ) ?></option>
					</select>
					<?php
					echo '<input type="submit" class="button" value="'.__( 'Apply', 'gf-limit' ).'" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\''.__( 'Delete selected feeds? ', 'gf-limit' ) . __( '\'Cancel\' to stop, \'OK\' to delete.', 'gf-limit' ).'\')) { return false; } return true;"/>';
					?>
				</div>
			</div>
			<table class="widefat fixed" cellspacing="0">
				<thead>
				<tr>
					<th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox"/></th>
					<!-- <th scope="col" id="active" class="manage-column check-column"></th> -->
					<th scope="col" class="manage-column"><?php _e( 'Form', 'gf-limit' ) ?></th>
					<th scope="col" class="manage-column"><?php _e( 'Field', 'gf-limit' ) ?></th>
					<th scope="col" class="manage-column"><?php _e( 'Limit', 'gf-limit' ) ?></th>
					<th scope="col" class="manage-column"><?php _e( 'Current Amount', 'gf-limit' ) ?></th>
				</tr>
				</thead>

				<tfoot>
				<tr>
					<th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox"/></th>
					<!--<th scope="col" id="active" class="manage-column check-column"></th>-->
					<th scope="col" class="manage-column"><?php _e( 'Form', 'gf-limit' ) ?></th>
					<th scope="col" class="manage-column"><?php _e( 'Field', 'gf-limit' ) ?></th>
					<th scope="col" class="manage-column"><?php _e( 'Limit', 'gf-limit' ) ?></th>
					<th scope="col" class="manage-column"><?php _e( 'Current Amount', 'gf-limit' ) ?></th>
				</tr>
				</tfoot>

				<tbody class="list:user user-list">
					<?php

					$feeds = GFLimitData::get_feeds();
					
					if ( is_array( $feeds ) && sizeof( $feeds ) > 0 ) {
						foreach ( $feeds as $feed ) { 
							?>
						<tr class='author-self status-inherit' valign="top">
							<th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $feed['id'] ?>"/></th>
							<!--<td><img
									src="<?php echo self::get_base_url() ?>/images/active<?php echo intval( $feed['is_active'] ) ?>.png"
									alt="<?php echo $feed['is_active'] ? __( 'Active', 'gf-limit' ) : __( 'Inactive', 'gf-limit' );?>"
									title="<?php echo $feed['is_active'] ? __( 'Active', 'gf-limit' ) : __( 'Inactive', 'gf-limit' );?>"
									onclick="ToggleActive(this, <?php echo $feed['id'] ?>); "/></td>-->
							<td class="column-title">
								<a href="admin.php?page=gf_limit&view=edit&id=<?php echo $feed['id'] ?>"
									 title="<?php _e( 'Edit', 'gf-limit' ) ?>"><?php echo $feed['form_title'] ?></a>

								<div class="row-actions">
                                    <span class="edit">
                                    <a title="<?php _e( 'Edit', 'gf-limit' )?>"
									 href="admin.php?page=gf_limit&view=edit&id=<?php echo $feed['id'] ?>"
									 title="<?php _e( 'Edit', 'gf-limit' ) ?>"><?php _e( 'Edit', 'gf-limit' ) ?></a>
                                    |
                                    </span>
                                    <span>
                                    <a title="<?php _e( "Delete", "gf-limit" ) ?>"
									 href="javascript: if(confirm('<?php _e( 'Delete this feed? ', 'gf-limit' ) ?> <?php _e( "\'Cancel\' to stop, \'OK\' to delete.", 'gf-limit' ) ?>')){ DeleteFeed(<?php echo $feed['id'] ?>);}"><?php _e( 'Delete', 'gf-limit' )?></a>
                                    </span>
								</div>
							</td>
							<td class="column-date">
							<?php
								if ( has_action( 'gf_limit_list_feeds_field' ) ) {
									do_action( 'gf_limit_list_feeds_field', $feed );
								}
								else {
									echo $feed['meta']['field_name'];
								}
								?>
							</td>
							<td class="column-date">
							<?php
								if ( has_action( 'gf_limit_list_feeds_limit' ) ) {
									do_action( 'gf_limit_list_feeds_limit', $feed );
								}
								else {
									echo $feed['quantity_limit'];
								}
								?>
							</td>
							<td class="column-date">
							<?php
								if ( has_action( 'gf_limit_list_current_amount' ) ) {
									do_action( 'gf_limit_list_current_amount', $feed );
								}
								else {
									echo GWLimitBySum::get_field_values_sum($feed['form_id'], $feed['field_id']);
								}
								?>
							</td>
						</tr>
							<?php
						}
					}
					else {
						?>
					<tr>
						<td colspan="4" style="padding:20px;">
							<?php echo sprintf( __( "You don't have any Quantity Limits configured. Let's go %screate one%s!", 'gf-limit' ), '<a href="admin.php?page=gf_limit&view=edit&id=0">', '</a>' ); ?>
						</td>
					</tr>
						<?php
					}
					?>
				</tbody>
			</table>
		</form>
	</div>
	<script type="text/javascript">
		function DeleteFeed(id) {
			jQuery("#action_argument").val(id);
			jQuery("#action").val("delete");
			jQuery("#feed_form")[0].submit();
		}
		function ToggleActive(img, feed_id) {
			var is_active = img.src.indexOf("active1.png") >= 0
			if (is_active) {
				img.src = img.src.replace("active1.png", "active0.png");
				jQuery(img).attr('title', '<?php _e( 'Inactive', 'gf-limit' ) ?>').attr('alt', '<?php _e( 'Inactive', 'gf-limit' ) ?>');
			}
			else {
				img.src = img.src.replace("active0.png", "active1.png");
				jQuery(img).attr('title', '<?php _e( 'Active', 'gf-limit' ) ?>').attr('alt', '<?php _e( 'Active', 'gf-limit' ) ?>');
			}

			var mysack = new sack("<?php echo admin_url( "admin-ajax.php" )?>");
			mysack.execute = 1;
			mysack.method = 'POST';
			mysack.setVar("action", "gf_limit_update_feed_active");
			mysack.setVar("gf_limit_update_feed_active", "<?php echo wp_create_nonce( 'gf_limit_update_feed_active' ) ?>");
			mysack.setVar("feed_id", feed_id);
			mysack.setVar("is_active", is_active ? 0 : 1);
			mysack.encVar("cookie", document.cookie, false);
			mysack.onError = function () {
				alert('<?php _e( 'Ajax error while updating feed', 'gf-limit' ) ?>')
			};
			mysack.runAJAX();

			return true;
		}


	</script>
	<?php
	}

//------------------------------------------------------
//------------- EDIT FEED PAGE ------------------
//------------------------------------------------------

	private static function edit_page() { ?>
		<style>
			#limit_submit_container {
				clear: both;
			}

			.limit_col_heading {
				padding-bottom: 2px;
				border-bottom: 1px solid #ccc;
				font-weight: bold;
				width: 120px;
			}

			.limit_field_cell {
				padding: 6px 17px 0 0;
				margin-right: 15px;
			}

			.limit_validation_error {
				background-color: #FFDFDF;
				margin-top: 4px;
				margin-bottom: 6px;
				padding-top: 6px;
				padding-bottom: 6px;
				border: 1px dotted #C89797;
			}

			.limit_validation_error span {
				color: red;
			}

			.left_header {
				float: left;
				width: 200px;
			}

			.margin_vertical_10 {
				margin: 10px 0;
				padding-left: 5px;
	            min-height: 17px;
			}

			.margin_vertical_30 {
				margin: 30px 0;
				padding-left: 5px;
			}

			.width-1 {
				width: 300px;
			}

			.gf_limit_invalid_form {
				margin-top: 30px;
				background-color: #FFEBE8;
				border: 1px solid #CC0000;
				padding: 10px;
				width: 600px;
			}
		</style>

		<script type="text/javascript" src="<?php echo GFCommon::get_base_url()?>/js/gravityforms.js"></script>
		<script type="text/javascript">
			var form = Array();
		</script>

		<div class="wrap">

		<h2><?php _e( 'Quantity Limit Settings', 'gf-limit' ) ?></h2>

			<?php

			//getting setting id (0 when creating a new one)
			$id  = ! empty( $_POST['limit_setting_id'] ) ? $_POST['limit_setting_id'] : absint( $_GET['id'] );
			$config  = empty( $id ) ? array(
				'quantity_limit' => 10,
				'meta'      => array(
					'field_name' => '',
					'messages' => array(
						'sold_out' => self::$m_sold_out,
						'validation' => self::$m_validation,
						'remainder' => self::$m_remainder,
					),
				),
				'is_active' => true ) : GFLimitData::get_feed( $id );
			$is_validation_error = false;
			
			//updating meta information
			if ( rgpost( 'gf_limit_submit' ) ) {
				
				//TODO: Preparing data for insert, add defaults for messages and the like.
				$config['form_id'] = absint( rgpost( 'gf_limit_form' ) );
				$config['field_id'] = rgpost( 'limit_field_id' );
				$config['quantity_limit'] = absint( rgpost( 'limit_limit' ) );
				$config['meta'] = array(
					'field_name'   => rgpost( 'limit_field_name' ),
					'messages'     => array(
						'sold_out'    => rgpost('limit_message_sold_out'),
						'validation'  => rgpost('limit_message_validation'),
						'remainder'   => rgpost('limit_message_remainder')
					),
				);
				
				$config = apply_filters( 'gf_limit_feed_save_config', $config );
				
				// Figure out better validation for multiple feeds
				// this breaks on updates
				// Validate limit as non-negative integer
				//$is_validation_error = GFLimitData::validation_error($config['form_id'], $config['field_id']);
				
				if ( $is_validation_error == FALSE ) {
					
					$id = GFLimitData::update_feed( $id, $config['form_id'], $config['field_id'], $config['quantity_limit'], $config['is_active'], $config['meta'] );
					?>
				<div class="updated fade"
						 style="padding:6px"><?php echo sprintf( __( "Limit set.  %sBack to list.%s", 'gf-limit' ), "<a href='?page=gf_limit'>", '</a>' ) ?></div>
					<?php
				}
				else {
					$validation_error_message = $is_validation_error;
					$is_validation_error = true;
				}
			}

			$form = isset( $config['form_id'] ) && $config['form_id'] ? $form = RGFormsModel::get_form_meta( $config['form_id'] ) : array();
			$settings = get_option('gf_limit_settings');
			?>
		
		<form method="post" action="">
		<input type="hidden" name="limit_setting_id" value="<?php echo $id ?>" />
		<input type="hidden" id="limit_field_name" name="limit_field_name" value="<?php echo $config['meta']['field_name'] ?>"/>
		
		<div class="margin_vertical_10 <?php echo $is_validation_error ? 'limit_validation_error' : '' ?>">
			<?php
			if ( $is_validation_error ) {
				?>
				<span><?php _e( 'There was an issue saving your feed. '.$validation_error_message ); ?></span>
				
				<?php
			}
			?>
		</div>
		<!-- / validation message -->

		<?php

	if ( has_action( 'gf_limit_feed_transaction_type' ) ) {
		do_action( 'gf_limit_feed_transaction_type', $settings, $config );
	}
	else {
				$config['meta']['type']= 'product' ?>

				<input id="gf_limit_type" type="hidden" name="gf_limit_type" value="product">

	<?php } ?>

		<div id="limit_form_container" valign="top" class="margin_vertical_10" <?php echo empty( $config['meta']['type'] ) ? "style='display:none;'" : '' ?>>
			<label for="gf_limit_form" class="left_header"><?php _e( 'Gravity Form', 'gf-limit' ); ?> <?php gform_tooltip( 'limit_gravity_form' ) ?></label>

			<select id="gf_limit_form" name="gf_limit_form"
				onchange="SelectForm(jQuery('#gf_limit_type').val(), jQuery(this).val(), '<?php echo rgar( $config, 'id' ) ?>');">
				<option value=""><?php _e( 'Select a form', 'gf-limit' ); ?> </option>
				<?php

				$active_form     = rgar( $config, 'form_id' );
				$available_forms = GFLimitData::get_available_forms( $active_form );

				foreach ( $available_forms as $current_form ):
					$selected = absint( $current_form->id ) == rgar( $config, 'form_id' ) ? 'selected="selected"' : '';
					?>

					<option value="<?php echo absint( $current_form->id ) ?>" <?php echo $selected; ?>><?php echo esc_html( $current_form->title ) ?></option>

				<?php endforeach; ?>
			</select>
			
			&nbsp;&nbsp;
			
			<img src="<?php echo GFLimit::get_base_url() ?>/images/loading.gif" id="limit_wait" style="display: none;"/>

			<div id="gf_limit_invalid_product_form" class="gf_limit_invalid_form" style="display:none;">
				<?php _e( 'The form selected does not have any Product fields. Please add a Product field to the form and try again.', 'gf-limit' ) ?>
			</div>
		</div>
		<div id="limit_field_group" valign="top" <?php echo strlen( rgars( $config, "meta/type" ) ) == 0 || empty( $config['form_id'] ) ? "style='display:none;'" : '' ?>>


		<?php do_action( 'gf_limit_feed_before_limit_field', $config, $form ); ?>
			<div class="margin_vertical_10">
				<label class="left_header"><?php _e( 'Field', 'gf-limit' ); ?> <?php gform_tooltip( 'limit_field' ) ?></label>

				<div id="form_fields">
					<?php
					if ( ! empty( $form ) )
						echo self::get_quantity_fields( $form, $config );
					?>
				</div>
			</div>
		<?php do_action( 'gf_limit_feed_after_limit_field', $config, $form ); ?>

			<div class="margin_vertical_10">
				<label class="left_header"><?php _e( 'Limit', 'gf-limit' ); ?> <?php gform_tooltip( 'limit_limit' ) ?></label>

				<div id="form_fields">
					<input type="text" name="limit_limit" value="<?php echo $config['quantity_limit'] ?>"/>
				</div>
			</div>

			<div class="margin_vertical_10">
				<label class="left_header"><?php _e( 'Sold out message', 'gf-limit' ); ?> <?php gform_tooltip( 'limit_message_sold_out' ) ?></label>

				<div id="form_fields">
					<input type="text" name="limit_message_sold_out" value="<?php echo $config['meta']['messages']['sold_out'] ?>" class="regular-text"/>
				</div>
			</div>

			<div class="margin_vertical_10">
				<label class="left_header"><?php _e( 'Validation message', 'gf-limit' ); ?> <?php gform_tooltip( 'limit_message_validation' ) ?></label>

				<div id="form_fields">
					<input type="text" name="limit_message_validation" value="<?php echo $config['meta']['messages']['validation'] ?>" class="regular-text"/>
					<p class="description">Use <em>{remaining}</em> to display remaining number of items and <em>{ordered}</em> to display number of ordered items.</p>
				</div>
			</div>

			<div class="margin_vertical_10">
				<label class="left_header"><?php _e( 'Remainder message', 'gf-limit' ); ?> <?php gform_tooltip( 'limit_message_remainder' ) ?></label>

				<div id="form_fields">
					<input type="text" name="limit_message_remainder" value="<?php echo $config['meta']['messages']['remainder'] ?>" class="regular-text"/>
					<p class="description">Use <em>{remaining}</em> to display remaining number of items. If left blank, no remainder message will show.</p>
				</div>
			</div>

		<?php do_action( 'gform_limit_add_option_group', $config, $form ); ?>
		
			<div id="limit_submit_container" class="margin_vertical_30">
				<input type="submit" name="gf_limit_submit"
							 value="<?php echo empty( $id ) ? __( '  Save  ', 'gf-limit' ) : __( 'Update', 'gf-limit' ); ?>"
							 class="button-primary"/>
				<input type="button" value="<?php _e( 'Cancel', 'gf-limit' ); ?>" class="button"
							 onclick="javascript:document.location='admin.php?page=gf_limit'"/>
			</div>
		</div>
		</form>
		</div>

		<script type="text/javascript">

			function SelectType(type) {
				jQuery("#limit_field_group").slideUp();

				jQuery("#limit_field_group input[type=\"text\"], #limit_field_group select").val("");

				jQuery("#limit_field_group input:checked").attr("checked", false);

				if (type) {
					jQuery("#limit_form_container").slideDown();
					jQuery("#gf_limit_form").val("");
				}
				else {
					jQuery("#limit_form_container").slideUp();
				}
			}

			function SelectForm(type, formId, settingId) {
				if (!formId) {
					jQuery("#limit_field_group").slideUp();
					return;
				}

				jQuery("#limit_wait").show();
				jQuery("#limit_field_group").slideUp();

				var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php");
				mysack.execute = 1;
				mysack.method = 'POST';
				mysack.setVar("action", "gfp_select_limit_form");
				mysack.setVar("gfp_select_limit_form", "<?php echo wp_create_nonce( 'gfp_select_limit_form' ) ?>");
				mysack.setVar("type", type);
				mysack.setVar("form_id", formId);
				mysack.setVar("setting_id", settingId);
				mysack.encVar("cookie", document.cookie, false);
				mysack.onError = function () {
					jQuery("#limit_wait").hide();
					alert('<?php _e( 'Ajax error while selecting a form', 'gf-limit' ) ?>')
				};
				mysack.runAJAX();

				return true;
			}

			function EndSelectForm(form_meta, quantity_fields, additional_functions) {
				//setting global form object
				form = form_meta;
				
				if ( ! ( typeof additional_functions === 'null' ) ) {
					var populate_field_options = additional_functions.populate_field_options;
					var post_update_action = additional_functions.post_update_action;
					var show_fields = additional_functions.show_fields;
				}
				else {
					var populate_field_options = '';
					var post_update_action = '';
					var show_fields = '';
				}

				var type = jQuery("#gf_limit_type").val();

				jQuery(".gf_limit_invalid_form").hide();
				if ((type == "product" || type == "subscription") && GetFieldsByType(["product"]).length == 0) {
					jQuery("#gf_limit_invalid_product_form").show();
					jQuery("#limit_wait").hide();
					return;
				}

				jQuery(".limit_field_container").hide();
				jQuery("#form_fields").html(quantity_fields);
				if ( populate_field_options.length > 0 ) {
					var func;
					for ( var i = 0; i < populate_field_options.length; i++ ) {
						func = new Function(populate_field_options[ i ]);
						func();
					}
				}

				var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
				if ( post_update_action.length > 0 ) {
					var func;
					for ( var i = 0; i < post_update_action.length; i++ ) {
						func = new Function('type', 'post_fields', post_update_action[ i ]);
						func(type, post_fields);
					}
				}
				else {
					jQuery("#gf_limit_update_post").attr("checked", false);
					jQuery("#limit_post_update_action").hide();
				}


				//Calling callback functions
				jQuery(document).trigger('limitFormSelected', [form]);

				jQuery("#gf_limit_conditional_enabled").attr('checked', false);

				jQuery("#limit_field_container_" + type).show();
				if ( show_fields.length > 0 ) {
					var func;
					for ( var i = 0; i < show_fields.length; i++ ) {
						func = new Function('type', show_fields[ i ]);
						func(type);
					}
				}
				console.log('Made it here');
				jQuery("#limit_field_group").slideDown();
				jQuery("#limit_wait").hide();
			}


			function GetFieldsByType(types) {
				var fields = new Array();
				for (var i = 0; i < form["fields"].length; i++) {
					if (IndexOf(types, form["fields"][i]["type"]) >= 0)
						fields.push(form["fields"][i]);
				}
				return fields;
			}

			function IndexOf(ary, item) {
				for (var i = 0; i < ary.length; i++)
					if (ary[i] == item)
						return i;

				return -1;
			}

			// Populate name of field in our hidden input
			jQuery('form').on('change', 'select[name=limit_field_id]', function(){
				var val = jQuery('select[name=limit_field_id] option:selected').text();
				jQuery('#limit_field_name').val(val);
			});
			
		</script>
		<?php

		}

		public static function gfp_select_limit_form() {

			check_ajax_referer( 'gfp_select_limit_form', 'gfp_select_limit_form' );

			$type       = $_POST['type'];
			$form_id    = intval( $_POST['form_id'] );
			$setting_id = intval( $_POST['setting_id'] );

			//fields meta
			$form = RGFormsModel::get_form_meta( $form_id );

			$quantity_fields         = self::get_quantity_fields( $form );
			$more_endselectform_args = array( 
											'populate_field_options' => array(),
											'post_update_action' => array(),
											'show_fields' => array()
										);
			$more_endselectform_args = apply_filters( 'gf_limit_feed_endselectform_args', $more_endselectform_args, $form );

			die( "EndSelectForm(" . GFCommon::json_encode( $form ) . ", '" . str_replace( "'", "\'", $quantity_fields ) . "', " . GFCommon::json_encode( $more_endselectform_args ) .");" );
		}

	public static function add_permissions() {
		global $wp_roles;
		$wp_roles->add_cap( 'administrator', 'gf_limit' );
		$wp_roles->add_cap( 'administrator', 'gf_limit_uninstall' );
	}

	//Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
	public static function members_get_capabilities( $caps ) {
		return array_merge( $caps, array( 'gf_limit', 'gf_limit_uninstall' ) );
	}

	public static function get_config( $form ) {
		if ( ! class_exists( 'GFLimitData' ) )
			require_once( GF_LIMIT_PATH . '/inc/data.php' );

		// Getting settings associated with this transaction
		$configs = GFLimitData::get_feed_by_form( $form['id'] );
		if ( ! $configs )
			return false;

		foreach ( $configs as $config ) {
			if ( self::has_limit_condition( $form, $config ) )
				return $config;
		}

		return false;
	}

    private static function has_visible_products( $form ) {
        foreach( $form['fields'] as $field ) {
            if( $field['type'] == "product" && ! RGFormsModel::is_field_hidden( $form, $field, "" ) )
                return true;
        }
        return false;
    }

	public static function uninstall() {
			//loading data lib
			require_once( GF_LIMIT_PATH . '/inc/data.php' );

			if ( ! GFLimit::has_access( 'gf_limit_uninstall' ) )
				die( __( 'You don\'t have adequate permission to uninstall the Quantity Limit Add-On.', 'gf-limit' ) );

			do_action( 'gf_limit_uninstall_condition' );

			//dropping all tables
			GFLimitData::drop_tables();

			//removing options
			delete_option( 'gf_limit_version' );
			delete_option( 'gf_limit_settings' );

			//Deactivating plugin
			$plugin = 'gravity-forms-limiter/gravity-forms-limiter.php';
			deactivate_plugins( $plugin );
			update_option( 'recently_activated', array( $plugin => time() ) + (array) get_option( 'recently_activated' ) );
		}

	private static function is_gravityforms_installed() {
		return class_exists( 'RGForms' );
	}

	private static function is_gravityforms_supported() {
		if ( class_exists( 'GFCommon' ) ) {
			$is_correct_version = version_compare( GFCommon::$version, self::$min_gravityforms_version, '>=' );
			return $is_correct_version;
		}
		else {
			return false;
		}
	}

	private static function get_quantity_fields( $form, $config = null ){

		//getting list of all fields for the selected form
		$form_fields = self::get_form_fields( $form );
		
		$selected_field = $config ? $config['field_id'] : "";
		
		$ret = self::get_mapped_field_list('field_id', $selected_field, $form_fields);
		
		return $ret;
	}

	public static function has_access( $required_permission ) {
		$has_members_plugin = function_exists( 'members_get_capabilities' );
		$has_access         = $has_members_plugin ? current_user_can( $required_permission ) : current_user_can( 'level_7' );
		if ( $has_access )
			return $has_members_plugin ? $required_permission : 'level_7';
		else
			return false;
	}

	private static function get_mapped_field_list( $variable_name, $selected_field, $fields ) {
		$field_name = 'limit_' . $variable_name;
		$str        = "<select name='$field_name' id='$field_name'><option value=''></option>";
		foreach ( $fields as $field ) {
			$field_id    = $field[ 0 ];
			$field_label = esc_html( GFCommon::truncate_middle( $field[ 1 ], 40 ) );

			$selected = $field_id == $selected_field ? "selected='selected'" : "";
			$str .= "<option value='" . $field_id . "' " . $selected . ">" . $field_label . '</option>';
		}
		$str .= '</select>';
		return $str;
	}

	public static function get_product_options( $form, $selected_field, $form_total ) {
	    $str    = "<option value=''>" . __( 'Select a field', 'gf-limit' ).'</option>';
		$fields = GFCommon::get_fields_by_type( $form, array( 'product' ) );
		foreach ( $fields as $field ) {
			$field_id    = $field['id'];
			$field_label = RGFormsModel::get_label( $field );

			$selected = $field_id == $selected_field ? "selected='selected'" : "";
			$str .= "<option value='" . $field_id . "' " . $selected . ">" . $field_label . '</option>';
		}

        if( $form_total ) {
            $selected = $selected_field == 'all' ? "selected='selected'" : "";
            $str .= "<option value='all' " . $selected . ">" . __( 'Form Total', 'gf-limit' ) ."</option>";
        }

		return $str;
	}

	private static function get_form_fields( $form ) {
		$fields = array();
		
		if ( is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
			
				// Limit to products as single product fields only
				if( $field['type'] == 'product' && $field['inputType'] == 'singleproduct' ) {

					// Set label for as the field
					$label = $field['label'];
					if ( is_array( rgar( $field, 'inputs' ) ) ) {
						foreach ( $field['inputs'] as $input ){
							if( $input['label'] == 'Quantity' ){
								$fields[ ] = array( $input['id'], $label );
							}
						}
					}
					else if ( ! rgar( $field, 'displayOnly' ) ) {
						$fields[ ] = array( $field['id'], $label);
					}
				}
			}
		}
		return $fields;
	}

	private static function get_form_fields_full( $form ) {
		$fields = array();

		if ( is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				if ( is_array( rgar( $field, 'inputs' ) ) ) {

					foreach ( $field['inputs'] as $input )
						$fields[ ] = array( $input['id'], GFCommon::get_label( $field, $input['id'] ) );
				}
				else if ( ! rgar( $field, 'displayOnly' ) ) {
					$fields[ ] = array( $field['id'], GFCommon::get_label( $field ) );
				}
			}
		}
		return $fields;
	}


	public static function is_limit_page() {
		$current_page = trim( strtolower( RGForms::get( 'page' ) ) );
		return in_array( $current_page, array( 'gf_limit' ) );
	}

	//Returns the url of the plugin's root folder
	public static function get_base_url() {
		return plugins_url( null, GF_LIMIT_FILE );
	}

	//Returns the physical path of the plugin's root folder
	private static function get_base_path() {
		$folder = basename( dirname( __FILE__ ) );
		return WP_PLUGIN_DIR . '/' . $folder;
	}

    function gform_logging_supported( $plugins ) {
	$plugins[ self::$slug ] = 'More Quantity Limits';
		return $plugins;
	}

    private static function log_error( $message ) {
        if( class_exists( 'GFLogging' ) ) {
            GFLogging::include_logger();
            GFLogging::log_message( self::$slug, $message, KLogger::ERROR );
        }
    }

	private static function log_debug( $message ) {
		if(class_exists( 'GFLogging' ) ) {
			GFLogging::include_logger();
			GFLogging::log_message( self::$slug, $message, KLogger::DEBUG );
		}
	}
}

if ( ! function_exists( 'rgget' ) ) {
	function rgget( $name, $array = null ) {
		if ( ! isset( $array ) )
			$array = $_GET;

		if ( isset( $array[ $name ] ) )
			return $array[ $name ];

		return "";
	}
}

if ( ! function_exists( 'rgpost' ) ) {
	function rgpost( $name, $do_stripslashes = true ) {
		if ( isset( $_POST[ $name ] ) )
			return $do_stripslashes ? stripslashes_deep( $_POST[ $name ] ) : $_POST[ $name ];

		return '';
	}
}

if ( ! function_exists( 'rgar' ) ) {
	function rgar( $array, $name ) {
		if ( isset( $array[ $name ] ) )
			return $array[ $name ];

		return '';
	}
}

if ( ! function_exists( 'rgars' ) ) {
	function rgars( $array, $name ) {
		$names = explode( '/', $name );
		$val   = $array;
		foreach ( $names as $current_name ) {
			$val = rgar( $val, $current_name );
		}
		return $val;
	}
}

if ( ! function_exists( 'rgempty' ) ) {
	function rgempty( $name, $array = null ) {
		if ( ! $array )
			$array = $_POST;

		$val = rgget( $name, $array );
		return empty( $val );
	}
}

if ( ! function_exists( 'rgblank' ) ) {
	function rgblank( $text ) {
		return empty( $text ) && strval( $text ) != '0';
	}
}