<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'Pie_WCWL_Admin_Ajax' ) ) {
	/**
	 * Class Pie_WCWL_Admin_Ajax
	 */
	class Pie_WCWL_Admin_Ajax {

		/**
		 * Initialise ajax class
		 */
		public function init() {
			$this->setup_text_strings();
			add_action( 'wp_ajax_wcwl_get_products', array( $this, 'get_all_products_ajax' ) );
			add_action( 'wp_ajax_wcwl_update_counts', array( $this, 'update_waitlist_counts_ajax' ) );
			add_action( 'wp_ajax_wcwl_update_meta', array( $this, 'update_waitlist_meta_ajax' ) );
			add_action( 'wp_ajax_wcwl_add_user_to_waitlist', array( $this, 'process_add_user_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_remove_waitlist', array( $this, 'process_waitlist_remove_users_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_email_instock', array( $this, 'process_send_instock_mail_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_dismiss_archive_notice', array( $this, 'permanently_dismiss_archive_notice_for_user_ajax' ) );
			add_action( 'wp_ajax_wcwl_remove_archive', array( $this, 'process_archive_remove_users_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_return_to_waitlist', array( $this, 'process_return_users_to_waitlist_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_update_waitlist_options', array( $this, 'update_waitlist_options_ajax' ) );
			add_action( 'wp_ajax_wcwl_generate_csv', array( $this, 'generate_csv_ajax' ) );

			
			
		     add_action('woocommerce_product_set_stock', array( $this, 'processStockChange' ) );
		     add_action('woocommerce_variation_set_stock', array( $this, 'processStockChange' ) );
		}

		/**
		 * Return all product IDs
		 */
		public function get_all_products_ajax() {
			if ( ! wp_verify_nonce( $_POST['wcwl_get_products'], 'wcwl-ajax-get-products-nonce' ) ) {
				die( $this->nonce_not_verified_text );
			}
			$products = WooCommerce_Waitlist_Plugin::return_all_product_ids();
			echo json_encode( $products );
			die();
		}

		/**
		 * Update waitlists for the given products - 10 at a time
		 */
		public function update_waitlist_counts_ajax() {
			if ( ! wp_verify_nonce( $_POST['wcwl_update_counts'], 'wcwl-ajax-update-counts-nonce' ) ) {
				die( $this->nonce_not_verified_text );
			}
			$products = $_POST['products'];
			foreach ( $products as $product ) {
				$count = $this->get_waitlist_count( absint( $product ) );
				echo sprintf( __( 'Product %d - count updated to %d | ', 'woocommerce-waitlist' ), $product, $count );
			}
			update_option( '_' . WCWL_SLUG . '_counts_updated', true );
			die();
		}

		/**
		 * Return number of users on requested waitlist and update meta so it can be quickly retrieved in the future
		 *
		 * @param  int $product product ID
		 *
		 * @access private
		 * @static
		 * @return int
		 */
		protected function get_waitlist_count( $product ) {
			$product  = wc_get_product( $product );
			$waitlist = array();
			if ( $product->has_child() ) {
				foreach ( $product->get_children() as $child_id ) {
					$current_waitlist = get_post_meta( $child_id, WCWL_SLUG, true );
					$current_waitlist = is_array( $current_waitlist ) ? $current_waitlist : array();
					$waitlist         = array_merge( $waitlist, $current_waitlist );
				}
			} else {
				$waitlist = get_post_meta( $product->get_id(), WCWL_SLUG, true );
			}
			$count = empty( $waitlist ) ? 0 : count( $waitlist );
			update_post_meta( $product->get_id(), '_' . WCWL_SLUG . '_count', $count );
			delete_post_meta( $product->get_id(), WCWL_SLUG . '_count' );

			return $count;
		}

		/**
		 * Update all metadata relating to waitlists
		 */
		public function update_waitlist_meta_ajax() {
			if ( ! wp_verify_nonce( $_POST['wcwl_update_meta'], 'wcwl-ajax-update-meta-nonce' ) ) {
				die( $this->nonce_not_verified_text );
			}
			$products = $_POST['products'];
			foreach ( $products as $product ) {
				$product_id = absint( $product );
				$archives   = get_post_meta( $product_id, 'wcwl_waitlist_archive', true );
				if ( ! is_array( $archives ) ) {
					$archives = array();
				}
				self::fix_multiple_entries_for_days( $archives, $product_id );
				$product  = wc_get_product( $product_id );
				$waitlist = new Pie_WCWL_Waitlist( $product );
				$waitlist->save_waitlist();
				echo sprintf( __( 'Meta updated for Product %d | ', 'woocommerce-waitlist' ), $product->get_id() );
			}
			update_option( '_' . WCWL_SLUG . '_metadata_updated', true );
			die();
		}

		/**
		 * Fix any duplicate entries for certain days when displaying the waitlist archives
		 * We check for the old timestamp as array key. If meta is old we adjust it over to the new dates
		 * Update meta afterwards to make sure everything remains updated
		 *
		 * @param $archives
		 * @param $product_id
		 *
		 * @return array
		 */
		public static function fix_multiple_entries_for_days( $archives, $product_id ) {
			$updated_archives = array();
			foreach ( $archives as $date => $archive ) {
				$date = strtotime( date( "Ymd", $date ) );
				if ( ! empty( $archive ) ) {
					foreach ( $archive as $user_id ) {
						$updated_archives[ $date ][ $user_id ] = $user_id;
					}
					$updated_archives[ $date ] = array_unique( $updated_archives[ $date ] );
				}
			}
			krsort( $updated_archives );
			update_post_meta( $product_id, 'wcwl_waitlist_archive', $updated_archives );

			return $updated_archives;
		}

		/**
		 * Handle the request to add user to waitlist
		 */
		public function process_add_user_request_ajax() {
			$this->verify_nonce( $_POST['wcwl_add_user_nonce'], 'wcwl-add-user-nonce' );
			$product  = $this->setup_product( absint( $_POST['product_id'] ) );
			$waitlist = new Pie_WCWL_Waitlist( $product );
			$emails   = $this->organise_emails( $_POST['emails'] );
			$users    = array();
			foreach ( $emails as $email ) {
				if ( ! email_exists( $email ) ) {
					add_filter( 'pre_option_woocommerce_registration_generate_password', array( WooCommerce_Waitlist_Plugin::instance(), 'return_option_setting_yes' ), 10 );
					add_filter( 'pre_option_woocommerce_registration_generate_username', array( WooCommerce_Waitlist_Plugin::instance(), 'return_option_setting_yes', ), 10 );
					$user_id = wc_create_new_customer( $email );
					remove_filter( 'pre_option_woocommerce_registration_generate_password', array( WooCommerce_Waitlist_Plugin::instance(), 'return_option_setting_yes', ), 10 );
					remove_filter( 'pre_option_woocommerce_registration_generate_username', array( WooCommerce_Waitlist_Plugin::instance(), 'return_option_setting_yes', ), 10 );
					if ( is_wp_error( $user_id ) ) {
						continue;
					}
				}
				$user = get_user_by( 'email', $email );
				$waitlist->register_user( $user );
				$users[] = $this->generate_required_userdata( $user, 'waitlist' );
			}
			die( $this->generate_response( 'success', __( 'The waitlist has been updated', 'woocommerce-waitlist' ), $users ) );
		}

		/**
		 * Process the given emails to add user to the waitlist
		 *
		 * @param $emails
		 *
		 * @return array
		 */
		public function organise_emails( $emails ) {
			$processed_emails = array();
			if ( is_array( $emails ) ) {
				foreach ( $emails as $email ) {
					$processed_emails[] = sanitize_email( $email );
				}
			} else {
				$processed_emails[] = sanitize_email( $emails );
			}

			return $processed_emails;
		}

		/**
		 * Return users from the archive to the waitlist
		 */
		public function process_return_users_to_waitlist_request_ajax() {
			$this->verify_action_request();
			$product  = $this->setup_product( absint( $_POST['product_id'] ) );
			$waitlist = new Pie_WCWL_Waitlist( $product );
			$users    = array();
			foreach ( $_POST['users'] as $user ) {
				if ( $user ) {
					$lang        = '';
					$user_object = get_user_by( 'id', absint( $user['id'] ) );
					$languages   = get_user_meta( $user['id'], 'wcwl_languages', true );
					if ( $languages ) {
						$lang = isset( $languages[$product->get_id()] ) ? $languages[$product->get_id()] : '';
					}
					$waitlist->register_user( $user_object, $lang );
					$users[] = $this->generate_required_userdata( $user_object, 'waitlist' );
				}
			}
			if ( count( $_POST['users'] ) > 1 ) {
				die( $this->generate_response( 'success', __( 'The selected users have been added to the waitlist', 'woocommerce-waitlist' ), $users ) );
			} else {
				die( $this->generate_response( 'success', __( 'The selected user has been added to the waitlist', 'woocommerce-waitlist' ), $users ) );
			}
		}

		/**
		 * Handle the request to remove users from the waitlist
		 */
		public function process_waitlist_remove_users_request_ajax() {
			$this->verify_action_request();
			$product  = $this->setup_product( absint( $_POST['product_id'] ) );
			$waitlist = new Pie_WCWL_Waitlist( $product );
			$users    = array();
			foreach ( $_POST['users'] as $user ) {
				$user_object = get_user_by( 'id', absint( $user['id'] ) );
				$response    = $waitlist->unregister_user( $user_object );
				$waitlist->maybe_add_user_to_archive( $user['id'] );
				if ( ! $response ) {
					die( $this->generate_response( 'error', sprintf( __( 'There was an error when trying to remove %s from the waitlist', 'woocommerce-waitlist' ), $user_object->user_email ) ) );
				}
				$users[] = $this->generate_required_userdata( $user_object, 'archive' );
			}
			if ( count( $users ) > 1 ) {
				die( $this->generate_response( 'success', __( 'The selected users have been removed from the waitlist', 'woocommerce-waitlist' ), $users ) );
			} else {
				die( $this->generate_response( 'success', __( 'The selected user has been removed from the waitlist', 'woocommerce-waitlist' ), $users ) );
			}
		}

		/**
		 * Handle the request to email in stock notifications to given users
		 */
		public function process_send_instock_mail_request_ajax() {
			//die('mail');
			$this->verify_action_request();
			$product = $this->setup_product( absint( $_POST['product_id'] ) );
			$users   = array();
			foreach ( $_POST['users'] as $user ) {
				WC_Emails::instance();
				$user_id = absint( $user['id'] );
				do_action( 'wcwl_mailout_send_email', $user_id, $product->get_id(), true );
				//do_action('processStockChange', true);

				$user_object = get_user_by( 'id', $user_id );
				//die(var_dump($user_object));
				$users[]     = $this->generate_required_userdata( $user_object, 'archive' );
			}
			die( $this->generate_response( 'success', __( 'The selected users have been sent an in stock notification', 'woocommerce-waitlist' ), $users ) );
		}


// gl start
	   /**
		* @param WC_Product $product
		*/
	 //  function processStockChange(WC_Product $product)
	 function processStockChange(WC_Product $product)
	   {
		
		//die('dsa');

		$camp_product = get_option('rc_product_id');
		//die($camp_product);
		$camp_product_obj  = wc_get_product( $camp_product );
		//die($camp_product_obj);
			  $product_obj  = wc_get_product( $product );
			 // $variation_attributes = $product->get_variation_attributes();
			  //var_dump($variation_attributes);
			$waitlist = get_post_meta($product_obj->get_id(), 'woocommerce_waitlist', true);
			$sURL    = site_url(); // WordPress function

			// $product_link = 'http://demo.runnerscamp.org/product/2022-camp-registration/?attribute_gender=Boy&attribute_age=6&attribute_camp=Camp+1';
			$product_link = get_permalink($product_obj);

			// $product_link = $sURL.'/product/'.$camp_product_obj->slug.'/?attribute_gender='.$product_obj->attributes["gender"].'&attribute_age='.$product_obj->attributes["age"].'&attribute_camp='.$product_obj->attributes["camp"].'';

			$email_content = '<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%">
			<tbody><tr>
			<td align="center" valign="top">
									<div id="m_-2035736652434526627template_header_image">
										<p style="margin-top:0"><img src="https://ci4.googleusercontent.com/proxy/4pz0MLYxQHCesb-klWIzVKYgb3bX2IflHc9YZlPQCxrJaFM_UTeRs0EK_M8LEyxG1P91ojsWimrOEPMDFgX1A_h1_sSj-AGnX2ZDDnKueZXMvgsU4UUUB9Crt_H9S-BKlKj9ier2=s0-d-e1-ft#https://wake.runnerscamp.org/wp-content/uploads/2021/11/RCI-Final-22.png" alt="Runners Camp" style="border:none;display:inline-block;font-size:14px;font-weight:bold;height:auto;outline:none;text-decoration:none;text-transform:capitalize;vertical-align:middle;max-width:100%;margin-left:0;margin-right:0" border="0" class="CToWUd" data-bit="iit"></p>						</div><table border="0" cellpadding="0" cellspacing="0" width="600" id="m_-2035736652434526627template_container" style="background-color:#fff;border:1px solid #e4e4e4;border-radius:3px" bgcolor="#fff"><tbody><tr><td align="center" valign="top">
												
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="m_-2035736652434526627template_header" style="background-color:#3e69c5;color:#fff;border-bottom:0;font-weight:bold;line-height:100%;vertical-align:middle;font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;border-radius:3px 3px 0 0" bgcolor="#3e69c5"><tbody><tr>
			<td id="m_-2035736652434526627header_wrapper" style="padding:36px 48px;display:block">
															<h1 style="font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;font-size:30px;font-weight:300;line-height:150%;margin:0;text-align:left;color:#fff;background-color:inherit" bgcolor="inherit">'.$camp_product_obj->name.' is now back in stock at RunnersCamp</h1>
														</td>
													</tr></tbody></table>
			
			</td>
										</tr>
			<tr>
			<td align="center" valign="top">
												
												<table border="0" cellpadding="0" cellspacing="0" width="600" id="m_-2035736652434526627template_body"><tbody><tr>
			<td valign="top" id="m_-2035736652434526627body_content" style="background-color:#fff" bgcolor="#fff">
															
															<table border="0" cellpadding="20" cellspacing="0" width="100%"><tbody><tr>
			<td valign="top" style="padding:48px 48px 32px">
																		<div id="m_-2035736652434526627body_content_inner" style="color:#858585;font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left" align="left"><span class="im">
			<p style="margin:0 0 16px">Hi There,</p>
			
			<p style="margin:0 0 16px">
				'.$camp_product_obj->name.' is now back in stock at Runners Camp. You have been sent this email because your email address was registered on a waitlist for this product.</p>
			
			
			</span><p style="margin:0 0 16px">You have been removed from the waitlist for this product</p>															</div>
																	</td>
																</tr></tbody></table>
			
			</td>
													</tr></tbody></table>
			
			</td>
										</tr>
			</tbody></table>
			</td>
							</tr>
			<tr>
			<td align="center" valign="top">
									
									<table border="0" cellpadding="10" cellspacing="0" width="600" id="m_-2035736652434526627template_footer"><tbody><tr>
			<td valign="top" style="padding:0;border-radius:6px">
												<table border="0" cellpadding="10" cellspacing="0" width="100%"><tbody><tr>
			<td colspan="2" valign="middle" id="m_-2035736652434526627credit" style="border-radius:6px;border:0;color:#a3a3a3;font-family:&quot;Helvetica Neue&quot;,Helvetica,Roboto,Arial,sans-serif;font-size:12px;line-height:150%;text-align:center;padding:24px 0" align="center">
															<p style="margin:0 0 16px">Runners Camp International</p>
														</td>
													</tr></tbody></table>
			</td>
										</tr></tbody></table>
			
			</td>
							</tr>
			</tbody></table>';
		$subject = 'First Come First Serve!';

		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		$headers[] = 'From: Runners Camp <admin@runerscamp.staging-server.online>';
		$headers[] = 'Reply-To: Runners Camp  <admin@runerscamp.staging-server.online>';
		
			//die($product_link);
			
			foreach($waitlist as $key => $val){
				$pattern = '/[int()]/';
				$data = preg_split( $pattern, $key );
				$userid = implode(" ",$data);
				//die($data[0]);
				$author_obj = get_user_by('id', $userid);
				//die(var_dump($author_obj->user_email));
				$email_status = wp_mail( $author_obj->user_email , $subject, $email_content, $headers );
				//$user_info = get_userdata($author_obj);
				// die(var_dump($product_link));
				
			}
			

			
		   
	   }

	//    gl end

		/**
		 * Remove selected users from given archive
		 */
		public function process_archive_remove_users_request_ajax() {
			$this->verify_action_request();
			$product_id = absint( $_POST['product_id'] );
			$archive    = get_post_meta( $product_id, 'wcwl_waitlist_archive', true );
			foreach ( $_POST['users'] as $user ) {
				$user_id = absint( $user['id'] );
				$date    = absint( $user['date'] );
				if ( ! $user_id ) {
					$key = array_search( $user_id, $archive[ $date ] );
					unset( $archive[ $date ][ $key ] );
				} else {
					unset( $archive[ $date ][ $user_id ] );
					if ( empty( $archive[ $date ] ) ) {
						unset( $archive[ $date ] );
					}
				}
			}
			update_post_meta( $product_id, 'wcwl_waitlist_archive', $archive );
			die( $this->generate_response( 'success', __( 'Selected users have been removed', 'woocommerce-waitlist' ), $_POST['users'] ) );
		}

		/**
		 * Update waitlist options
		 */
		public function update_waitlist_options_ajax() {
			$this->verify_nonce( $_POST['wcwl_update_nonce'], 'wcwl-update-nonce' );
			if ( is_array( $_POST['options'] ) ) {
				update_post_meta( absint( $_POST['product_id'] ), 'wcwl_options', $_POST['options'] );
				die( $this->generate_response( 'success', __( 'Waitlist options have been updated for this product', 'woocommerce-waitlist' ) ) );
			} else {
				die( $this->generate_response( 'error', __( 'Something went wrong with your request. Options not recognised', 'woocommerce-waitlist' ) ) );
			}
		}

		/**
		 * Verify request is valid by checking posted users and nonce
		 */
		protected function verify_action_request() {
			$this->verify_nonce( $_POST['wcwl_action_nonce'], 'wcwl-action-nonce' );
			if ( ! isset( $_POST['users'] ) || empty( $_POST['users'] ) ) {
				die( $this->generate_response( 'error', __( 'No users selected', 'woocommerce-waitlist' ) ) );
			}
		}

		/**
		 * Retrieve the product from the given ID and output an error notice if not found
		 *
		 * @param $product_id
		 *
		 * @return false|null|WC_Product
		 */
		protected function setup_product( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				die( $this->generate_response( 'error', __( 'Invalid product ID', 'woocommerce-waitlist' ) ) );
			}

			return $product;
		}

		/**
		 * Verify the given nonce is valid and output error message if not
		 *
		 * @param $nonce
		 * @param $nonce_name
		 *
		 * @return bool
		 */
		protected function verify_nonce( $nonce, $nonce_name ) {
			if ( ! wp_verify_nonce( $nonce, $nonce_name ) ) {
				die( $this->generate_response( 'error', $this->nonce_not_verified_text ) );
			}

			return true;
		}

		/**
		 * Gather required information for user
		 *
		 * @param $user
		 * @param $table
		 *
		 * @return array
		 */
		protected function generate_required_userdata( $user, $table ) {
			if ( $user ) {
				$data = array(
					'id'        => $user->ID,
					'link'      => get_edit_user_link( $user->ID ),
					'email'     => $user->user_email,
					'join_date' => date( 'd M, y' ),
				);
			} else {
				$data = array(
					'id'        => 0,
					'link'      => '',
					'email'     => '',
					'join_date' => '',
				);
			}
			if ( 'archive' == $table ) {
				$data['date'] = strtotime( date( 'Ymd' ) );
			}

			return $data;
		}

		/**
		 * Generate a meaningful response to easily handle the ajax request
		 *
		 * @param        $type
		 * @param        $message
		 * @param array  $users
		 *
		 * @return mixed|string|void
		 */
		protected function generate_response( $type, $message, $users = array() ) {
			$data = array(
				'type'    => $type,
				'message' => $message,
				'archive' => get_option( 'woocommerce_waitlist_archive_on' ),
			);
			if ( 'success' == $type ) {
				$data['users'] = $users;
			}

			return json_encode( $data );
		}

		/**
		 * Generate CSV with all product waitlist data (10 at a time)
		 */
		public function generate_csv_ajax() {
			$string   = '';
			$products = $_POST['products'];
			foreach ( $products as $product ) {
				$product_id = absint( $product );
				$product    = wc_get_product( $product_id );
				if ( WooCommerce_Waitlist_Plugin::is_variation( $product ) || WooCommerce_Waitlist_Plugin::is_simple( $product ) ) {
					$waitlist = get_post_meta( $product_id, 'woocommerce_waitlist', true );
					$archives = $this->get_formatted_archives( $product_id );
					if ( $this->no_users( $waitlist ) && $this->no_users( $archives ) ) {
						continue;
					}
					$product_name = str_replace( array( '"', '#' ), array( '""', '' ), wp_kses_decode_entities( $product->get_formatted_name() ) );
					$string .= $product_id . ',"' . $product_name . '",';
					if ( $this->no_users( $waitlist ) ) {
						$string .= ',';
					} else {
						$emails = '"';
						foreach ( $waitlist as $user_id => $timestamp ) {
							$user = get_user_by( 'id', $user_id );
							$emails .= $user->user_email;
							end( $waitlist );
							if ( $user_id !== key( $waitlist ) ) {
								$emails .= ',';
							} else {
								$emails .= '",';
							}
						}
						$string .= $emails;
					}
					if ( $this->no_users( $archives ) ) {
						$string .= "\r\n";
					} else {
						$emails = '"';
						foreach ( $archives as $key => $user_id ) {
							$user = get_user_by( 'id', $user_id );
							$emails .= $user->user_email;
							end( $archives );
							if ( $key !== key( $archives ) ) {
								$emails .= ',';
							} else {
								$emails .= '"' . "\r\n";
							}
						}
						$string .= $emails;
					}
				} else {
					continue;
				}
			}
			echo $string;
			die();
		}

		/**
		 * Retrieve and format the products archive
		 *
		 * @param $product_id
		 *
		 * @return array
		 */
		public function get_formatted_archives( $product_id ) {
			$archives       = get_post_meta( $product_id, 'wcwl_waitlist_archive', true );
			$archived_users = array();
			if ( $this->no_users( $archives ) ) {
				return $archived_users;
			}
			foreach ( $archives as $timestamp => $user_ids ) {
				if ( ! empty( $user_ids ) ) {
					$archived_users = array_merge( $archived_users, $user_ids );
				}
			}

			return array_unique( $archived_users );
		}

		/**
		 * Are there any users on the given list?
		 *
		 * @param $list
		 *
		 * @return bool
		 */
		public function no_users( $list ) {
			if ( ! is_array( $list ) || empty( $list ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Required text for ajax requests
		 */
		protected function setup_text_strings() {
			$this->nonce_not_verified_text = __( 'Nonce Not Verified', 'woocommerce-waitlist' );
		}
	}
}
