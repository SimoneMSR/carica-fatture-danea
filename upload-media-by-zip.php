<?php
/*
Plugin Name: Import Danea Invoices from Zip
Plugin URI: http://simonemsr.github.io
Description: Upload a zip file of invoices and attach them to orders
Author: Simone D'Amario
Version: 0.0.1
Author URI: http://simonemsr.github.io

Creative Commons  (CC) 2018  Simone D'Amario

Derived from two WordPress plugins:
- https://wordpress.org/plugins/upload-media-by-zip/ by  Kailey Lampert http://kaileylampert.com/
- https://wordpress.org/plugins/order-attachment-for-woocommerce/ by Phoeniixx http://www.phoeniixx.com/

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

if ( ! function_exists('write_log')) {
   function write_log ( $log )  {
      if ( is_array( $log ) || is_object( $log ) ) {
         error_log( print_r( $log, true ) );
      } else {
         error_log( $log );
      }
   }
}

/**
 * Upload_Media_By_Zip class
 *
 * class used as a namespace
 *
 * @package Upload Media By Zip
 */
class Upload_Media_By_Zip {

	/**
	 * Get hooked into init
	 *
	 * @return void
	 */
	function __construct( ) {

		if ( ! @ is_network_admin() && ( is_plugin_active( 'woocommerce/woocommerce.php' ) || is_plugin_active_for_network( 'woocommerce/woocommerce.php' )  ) ) {
				add_action( 'admin_menu',             array( $this, 'menu' ) );
				add_filter( 'wp_ajax_umbz_get_title', array( $this, 'umbz_get_title') );
				add_action( 'woocommerce_account_view-order_endpoint', array( $this,'show_download_invoice'), 50);
				add_filter( 'woocommerce_my_account_my_orders_actions', array( $this,'show_button_in_orders_table'), 10, 2 );
		}

	}

	/**
	 * Create admin pages in menu
	 *
	 * @return void
	 */
	function menu() {

		add_submenu_page( 'woocommerce', __( 'Carica fatture da Danea', 'upload-media-by-zip' ), __( 'Carica fatture da Danea', 'upload-media-by-zip' ), 'upload_files', __FILE__, array( $this, 'page' ) );

	}

	function umbz_get_title() {
		$p = get_post( absint( $_POST['pid'] ) );
		if ( is_object( $p ) ) {
			wp_send_json_success( $p->post_type .': '. $p->post_title );
		}
		wp_send_json_error();
	}



	/**
	 * The Admin Page
	 *
	 */
	function page() {
		echo '<div class="wrap">';
		echo '<h2>' . __( 'Carica fatture da Danea', 'upload-media-by-zip' ) . '</h2>';
		echo self::handler();
		self::form();
		echo '</div>';
	}

	/**
	 * The upload form
	 *
	 * @param array $args 'action' URL for form action, 'post_id' ID for preset parent ID
	 */
	function form( $args = array() ) {
		$action = '';
		$tab    = false;
		if ( count( $args ) > 0 ) {
			$tab     = true;
			$action  = $args['action'];
			$post_id = $args['post_id'];
		}

		echo '<form action="'. $action .'" method="post" enctype="multipart/form-data">';
			echo '<h3 class="media-title">'. __( 'Upload a zip file and attach invoices to Orders', 'upload-media-by-zip' ) .'</h3>';
		echo '<p><input type="file" name="import-danea-invoices" id="import-danea-invoices" size="50" /></p>';
		echo '<p>'. sprintf( __( 'Maximum upload file size: %s' ), size_format( wp_max_upload_size() ) ) .'</p>';
		echo '<input type="hidden" name="submitted-upload-media" /><input type="hidden" name="action" value="wp_handle_upload" />';

		submit_button( __( 'Carica', 'upload-media-by-zip' ) );

		echo '</form>';
	}

	/**
	 * Move unzipped content from temp folder to media library
	 *
	 * @param string $dir Directory to loop through
	 * @param integer $parent Page ID to be used as attachment parent
	 * @param string $return String to append results to
	 * @return string Results as <li> items
	 */
	function move_from_dir( $dir, $parent, $return = '' ) {

		$dir  = trailingslashit( $dir );

		$here = glob("$dir*.*" ); //get files

		$dirs = glob("$dir*", GLOB_ONLYDIR|GLOB_MARK ); //get subdirectories

		//start with subs, less confusing
		foreach ( $dirs as $k => $sdir ) {
			$return .= self::move_from_dir( $sdir, $parent, $return );
		}

		$order_id=45197;

		//loop through files and add them to the media library
		foreach ( $here as $invoice ) {
			$invoice_name = basename( $invoice );
			$title    = explode( '.', $invoice_name );
			array_pop( $title );
			$title    = implode( '.', $title );

			$invoice_url = str_replace( WP_CONTENT_DIR, WP_CONTENT_URL, $invoice );
			$file    = array(
				'file'     => $invoice,
				'tmp_name' => $invoice,
				'name'     => $invoice_name
			);
			$invoice_id  = media_handle_sideload( $file, $order_id, $title );
			if ( ! is_wp_error( $invoice_id ) ) {
				$upload_url=wp_get_attachment_url($invoice_id);
				$file = array(
					'attachment_name'=>$invoice_name,
					'order_id'=>$order_id,
					'name'=>$invoice_name,
					'gallery_upload_url'=>$upload_url,
				);
				update_post_meta( $order_id, 'danea_invoice_pdf', $file);	
				$return .= "<li>($invoice_id) ". sprintf( __( '%s uploaded', 'upload-media-by-zip' ), $invoice_name ) ."<input type='hidden' name='srcs[]' value='$invoice_id' /></li>";
			} else {
				$return .= "<li style='color:#a00;'>". sprintf( __( '%s could not be uploaded.', 'upload-media-by-zip' ), "$invoice_name ($dir)" );
				if ( is_file( $invoice ) && unlink( $invoice ) ) {
					$return .= __( ' It has been deleted.', 'upload-media-by-zip' );
				}
				$return .= "</li>";
			}

		}

		//We need check for hidden files and remove them so that the directory can be deleted
		foreach ( glob("$dir.*") as $k => $hidden ) {
			if ( is_file( $hidden ) ) {
				unlink( $hidden );
			}
		}

		//delete any folders that were unzipped
		if ( basename( $dir ) != 'temp') {
			rmdir( $dir );
		}

		return $return;
	}

	/*
	* Shows the Attach invoice accordion in the Order page
	*
	**/

	function show_invoice_box($post){
		$order_id=$post->ID;
		$data =get_post_meta( $order_id, 'danea_invoice_pdf', true );
		
		if(!empty($data)){
			?>
			<div class="accordion">
				<h3><?php echo $data['attachment_name'] ? $data['attachment_name'] :'Attachment';?><input type="button" id="remove_data" value="X" /></h3>
				<div id="phoen_Attachment" class="phoen_new_Attachment"></br>
					<label><?php _e('Attachment Name','phoen_woo_order_Attachment'); ?></label>		
					<input type="text" value="<?php echo $data['attachment_name'];?>" name="attachment_name" /></br>
					<div class="phoe-file-data-box"></br></br>
						<div class="upload_file">
							<label><?php _e('Upload a file (Only .pdf allowed)','phoen_woo_order_Attachment'); ?></label>																
							<input type="file" accept="application/pdf" name="file_upload" class="file_input"  /> </br></br>
							<span><?php echo $data['name'];?></span>
						</div>						
					</div>
				</div>
			</div>
			 
			<?php
			
		}else{
		?>
			<div class="accordion">
				<h3><?php _e('Attachment','phoen_woo_order_Attachment'); ?><input type="button" id="remove_data" value="X" /></h3>
				<div class="phoen_new_Attachment">
					</br>
					<label><?php _e('Attachment Name','phoen_woo_order_Attachment'); ?></label>		
					<input type="text" name="attachment_name" /></br>
					<div class="phoe-file-data-box"></br></br>
						<div class="upload_file">
							<label><?php _e('Upload a file (Only .pdf allowed)','phoen_woo_order_Attachment'); ?></label>	
							<input type="file" accept="application/pdf" name="file_upload" class="file_input"  /> </br></br>
						</div>						
					</div>
				</div>
			</div>
		
		<?php
		}	
		?>
				
		<script type="text/javascript">
			jQuery(document).ready(function(){
				jQuery( function(){
					jQuery('div.accordion').accordion({
						collapsible: true,
					});
				});
				jQuery(document).on('click',  '#remove_data', function(e){	
					e.preventDefault();
					// jQuery(this).remove();
					jQuery(this).parent().parent('div.accordion').remove();
					jQuery(".accordion").accordion("refresh");
					
			})
				
			})
			
			
			
		</script>
		<?php
	}

	/**
	 * Handle the initial zip upload
	 *
	 * @return string HTML Results or Error message
	 */
	function handler() {

		wp_enqueue_script('wp-util');
		?><script type="text/javascript">
		
		/* <![CDATA[ */
		jQuery(document).ready(function($){

			var wp = window.wp;

			$('input[name="post_parent"]').keyup( function() {

				var id = $(this).val(),
				    $page_title = $( document.getElementById( 'page_title' ) );

				if ( '' == id ) {
					$page_title.html('');
					return;
				}

				$page_title.html('...');

				wp.ajax.send( 'umbz_get_title', {
					data: {
						pid: id
					},
					success: function( data ) {
						$page_title.html( data );
					},
					error: function( data ) {
						$page_title.html( 'invalid ID' );
					}
				} );

			});

			$('#close_box').on( 'click', function() {
				$(this).parent().parent().hide();
			});

		});
		/* ]]> */
</script><?php

		if ( isset( $_FILES[ 'import-danea-invoices' ][ 'name' ] ) && ! empty( $_FILES[ 'import-danea-invoices' ][ 'name' ] ) ) {

			$parent = isset( $_POST['post_parent'] ) ? (int) $_POST['post_parent'] : 0;
			$overrides = array(
				'mimes'  => array('zip' => 'application/zip'),
				'ext'    => array('zip'),
				'type'   => true,
				'action' => 'wp_handle_upload'
			);
			$upl_id = media_handle_upload( 'import-danea-invoices', $parent, array(), $overrides );
			if ( is_wp_error( $upl_id ) ) {
				return '<div class="error"><p>'. $upl_id->errors['upload_error']['0'] .'</p></div>';
			}
			$file = str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, wp_get_attachment_url( $upl_id ) );

			/*
				If the zipped file cannot be unzipped
				try again after uncommenting the lines
				below marked 1, 2, and 3
			*/
			///*1*/	function __return_direct() { return 'direct'; }
			///*2*/	add_filter( 'filesystem_method', '__return_direct' );
			WP_Filesystem();
			///*3*/	remove_filter( 'filesystem_method', '__return_direct' );

			$to = plugins_url( 'temp', __FILE__ );
			$to = str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $to );

			$upl_name = get_the_title( $upl_id );

			$return  = '';
			$return .= '<div class="updated">';
			$return .= '<ul style="list-style-type: disc; padding: 10px 35px;">';
			$return .= '<li id="close_box" style="list-style-type:none;cursor:pointer;float:right;">X</li>';
			$return .= '<li>'. $upl_name .' uploaded</li>';
			if ( ! is_wp_error( unzip_file( $file, $to ) ) ) {
				$return .= '<li>'. sprintf( __( '%s extracted', 'upload-media-by-zip' ), $upl_name ) .'</li>';
				$dirs    = array();

				$return .= self::move_from_dir( $to, $parent );

				//delete zip file
				wp_delete_attachment( $upl_id );
				$return .= '<li>'. $upl_name .' import is completed</li>';
			} else {
				wp_delete_attachment( $upl_id );
				$return .= '<li>'. sprintf( __( '%s could not be extracted and has been deleted', 'upload-media-by-zip' ), $upl_name ) .'</li>';
			}
			$return .= '</ul>';
			$return .= '</div>';

			return $return;
		}

	}

	/**
	*
	*  Show the Downlaod button in the order page
	*
	*/
	function show_download_invoice($order_id) {
		
		$data =get_post_meta( $order_id, 'danea_invoice_pdf', true );
		
		$order = wc_get_order( $order_id );
		
		$order_user_id = $order->get_user_id();
		
		$user_id=get_current_user_id();
		
		if(!empty($data) && $order_user_id==$user_id){
			echo '<div>';
			echo '<h2>Fattura</h2>';
			echo '<h5>'.$data['attachment_name'].'</h5>';
			echo '<span>'.$data['name'].'</span>';
			echo '<a class="button" href="'. $data['gallery_upload_url'] .'" download>Download</a>';	
			echo '</div>';
		}
		
	}

	function show_button_in_orders_table( $actions, $order ) {

		$data =get_post_meta( $order->get_order_number(), 'danea_invoice_pdf', true );
		if( isset($data['gallery_upload_url'])){
			$actions['help'] = array(
		        // adjust URL as needed
		        'url'  => $data['gallery_upload_url'],
		        'name' => __( 'Fattura', 'my-textdomain' ),
		    );
		}


    return $actions;
}

}//end class
$upload_media_by_zip = new Upload_Media_By_Zip( );
