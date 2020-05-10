<?php

class WPF_Intercom_2 {

	// Work in progress. Going to stick it out with the v1.4 API for as long as possible but this is available for people who want to try it.

	/**
	 * Contains API params
	 */

	public $params;

	/**
	 * Lets pluggable functions know which features are supported by the CRM
	 */

	public $supports;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */

	public function __construct() {

		$this->slug     = 'intercom';
		$this->name     = 'Intercom (v2.0)';
		$this->supports = array();

		// Set up admin options
		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/admin/class-admin.php';
			new WPF_Intercom_2_Admin( $this->slug, $this->name, $this );
		}

	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */

	public function init() {

		//add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
		add_filter( 'wpf_woocommerce_customer_data', array( $this, 'set_country_names' ), 10, 2 );

	}

	/**
	 * Formats POST data received from webhooks into standard format
	 *
	 * @access public
	 * @return array
	 */

	public function format_post_data( $post_data ) {

		if( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		$post_data['contact_id'] = $payload->data->item->user->id;

		return $post_data;

	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 * @return HTTP Response
	 */

	public function handle_http_response( $response, $args, $url ) {

		if( strpos( $url, 'intercom' ) !== false ) {

			$body_json = json_decode( wp_remote_retrieve_body( $response ) );

			if( isset( $body_json->errors ) ) {

				$response = new WP_Error( 'error', $body_json->errors['0']->code );

			}

		}

		return $response;

	}


	/**
	 * Use full country names instead of abbreviations with WooCommerce
	 *
	 * @access public
	 * @return array Customer data
	 */

	public function set_country_names( $customer_data, $order ) {

		if( isset( $customer_data['billing_country'] ) ) {
			$customer_data['billing_country'] = WC()->countries->countries[ $customer_data['billing_country'] ];
		}

		if( isset( $customer_data['shipping_country'] ) ) {
			$customer_data['shipping_country'] = WC()->countries->countries[ $customer_data['shipping_country'] ];
		}

		return $customer_data;

	}

	/**
	 * Gets params for API calls
	 *
	 * @access  public
	 * @return  array Params
	 */

	public function get_params( $access_key = null ) {

		// Get saved data from DB
		if ( empty( $access_key ) ) {
			$access_key = wp_fusion()->settings->get( 'intercom_key' );
		}

		$this->params = array(
			'timeout'     => 30,
			'headers'     => array(
				'Accept' 			=> 'application/json',
				'Authorization'   	=> 'Bearer ' . $access_key,
				'Content-Type' 		=> 'application/json',
				'Intercom-Version'  => '2.0',
			)
		);

		return $this->params;
	}


	/**
	 * Initialize connection
	 *
	 * @access  public
	 * @return  bool
	 */

	public function connect( $access_key = null, $test = false ) {

		if ( $test == false ) {
			return true;
		}

		if ( ! $this->params ) {
			$this->get_params( $access_key );
		}

		$request  = 'https://api.intercom.io/me';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( isset( $response->errors ) ) {
			return new WP_Error( $response->errors[0]->code, $response->errors[0]->message );
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */

	public function sync() {

		if ( is_wp_error( $this->connect() ) ) {
			return false;
		}

		$this->sync_tags();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;

	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */

	public function sync_tags() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$available_tags = array();

		$request  = 'https://api.intercom.io/tags';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach( $response->data as $tag ) {
			$available_tags[ $tag->id ] = $tag->name;
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */

	public function sync_crm_fields() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$request  = 'https://api.intercom.io/data_attributes?model=contact';
		$response = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		foreach( $response->data as $field ) {

			if( $field->api_writable == true ) {
				$crm_fields[ $field->name ] = $field->label;
			}

		}

		asort( $crm_fields );

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function get_contact_id( $email_address ) {

		$params = $this->get_params();

		$query = array(
			'query' => array(
				'field'    => 'email',
				'operator' => '=',
				'value'    => $email_address,
			),
		);

		$params['body'] = json_encode( $query );

		$request  = 'https://api.intercom.io/contacts/search';
		$response = wp_remote_post( $request, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->data ) ) {
			return false;
		}

		return $response->data[0]->id;
		
	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 * @return void
	 */

	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$user_tags = array();

		$request      = 'https://api.intercom.io/contacts/' . $contact_id;
		$response     = wp_remote_get( $request, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if( empty( $response->tags->data ) ) {
			return $user_tags;
		}

		foreach( $response->tags->data as $tag ) {
			$user_tags[] = $tag->id;
		}

		return $user_tags;
	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function apply_tags( $tags, $contact_id ) {

		$url    = 'https://api.intercom.io/contacts/' . $contact_id . '/tags';
		$params = $this->get_params();

		foreach( $tags as $tag ) {

			// Maybe convert tag names to labels

			if ( ! is_numeric( $tag ) ) {
				$tag = wp_fusion()->user->get_tag_id( $tag );
			}

			$params['body'] = json_encode( array( 'id' => $tag ) );

			$response = wp_remote_post( $url, $params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

		}

		return true;
	}

	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 * @return bool
	 */

	public function remove_tags( $tags, $contact_id ) {

		$params           = $this->get_params();
		$params['method'] = 'DELETE';

		foreach ( $tags as $tag ) {

			// Maybe convert tag names to labels

			if ( ! is_numeric( $tag ) ) {
				$tag = wp_fusion()->user->get_tag_id( $tag );
			}

			$url      = 'https://api.intercom.io/contacts/' . $contact_id . '/tags/' . $tag;
			$response = wp_remote_request( $url, $params );

			if( is_wp_error( $response ) ) {
				return $response;
			}

		}

		return true;

	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 * @return int Contact ID
	 */

	public function add_contact( $data, $map_meta_fields = true ) {

		$user_id = false;

		if ( isset( $data['user_id'] ) ) {
			$user_id = $data['user_id'];
		}

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		// General cleanup and restructuring
		$body = array( 'email' => $data['email'] );
		unset( $data['email'] );

		if( isset( $data['phone'] ) ) {
			$body['phone'] = $data['phone'];
			unset( $data['phone'] );
		}

		if( isset( $data['name'] ) ) {
			$body['name'] = $data['name'];
			unset( $data['name'] );
		}

		if( ! empty( $data ) ) {

			// All other custom fields

			$body['custom_attributes'] = $data;

		}

		// As of 2.0 we need to differentiate between Users and Leads

		if ( false == $user_id ) {

			// Try getting user ID via email

			$user = get_user_by( 'email', $data['email'] );

			if ( $user ) {
				$user_id = $user->ID;
			}

		}

		if ( false != $user_id ) {
			$body['external_id'] = $user_id;
			$body['role']        = 'user';
		} else {
			$body['role'] = 'lead';
		}

		$url 				= 'https://api.intercom.io/contacts';
		$params 			= $this->params;
		$params['body'] 	= json_encode( $body );

		$response = wp_remote_post( $url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		return $body->id;

	}

	/**
	 * Update contact
	 *
	 * @access public
	 * @return bool
	 */

	public function update_contact( $contact_id, $data, $map_meta_fields = true ) {

		$user_id = false;

		if ( isset( $data['user_id'] ) ) {
			$user_id = $data['user_id'];
		}

		if ( ! $this->params ) {
			$this->get_params();
		}

		if ( $map_meta_fields == true ) {
			$data = wp_fusion()->crm_base->map_meta_fields( $data );
		}

		if( empty( $data ) ) {
			return false;
		}

		// General cleanup and restructuring
		$body = array( 'id' => $contact_id );

		if( isset( $data['email'] ) ) {
			$body['email'] = $data['email'];
			unset( $data['email'] );
		}

		if( isset( $data['phone'] ) ) {
			$body['phone'] = $data['phone'];
			unset( $data['phone'] );
		}

		if( isset( $data['name'] ) ) {
			$body['name'] = $data['name'];
			unset( $data['name'] );
		}
		
		if( ! empty( $data ) ) {

			// All other custom fields
			$body['custom_attributes'] = $data;

		}

		// As of 2.0 we need to differentiate between Users and Leads

		if ( false == $user_id ) {

			// Try getting user ID via email

			$user = get_user_by( 'email', $data['email'] );

			if ( $user ) {
				$user_id = $user->ID;
			}

		}

		if ( false != $user_id ) {
			$body['external_id'] = $user_id;
			$body['role']        = 'user';
		} else {
			$body['role'] = 'lead';
		}

		$url              = 'https://api.intercom.io/contacts';
		$params           = $this->params;
		$params['body']   = json_encode( $body );
		$params['method'] = 'PUT';

		$response = wp_remote_request( $url, $params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 * @return array User meta data that was returned
	 */

	public function load_contact( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$url      = 'https://api.intercom.io/contacts/' . $contact_id;
		$response = wp_remote_get( $url, $this->params );

		if( is_wp_error( $response ) ) {
			return $response;
		}

		$user_meta      = array();
		$contact_fields = wp_fusion()->settings->get( 'contact_fields' );
		$body_json      = json_decode( $response['body'], true );

		foreach ( $contact_fields as $field_id => $field_data ) {

			// Core fields
			if ( isset( $body_json[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json[ $field_data['crm_field'] ];
			}

			// Custom attributes
			if ( isset( $body_json['custom_attributes'][ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $body_json['custom_attributes'][ $field_data['crm_field'] ];
			}

		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @access public
	 * @return array Contact IDs returned
	 */

	public function load_contacts( $tag_query ) {

		$params = $this->get_params();

		// $query = array(
		// 	'query' => array(
		// 		'field'    => 'tag_id',
		// 		'operator' => 'IN',
		// 		'value'    => $tag,
		// 	),
		// );

		// $params['body'] = json_encode( $query );

		// $contact_ids = array();
		// $param       = false;
		// $proceed     = true;

		// while ( $proceed == true ) {

		// 	$params['body'] = json_encode( $query );

		// 	$request  = 'https://api.intercom.io/contacts/search';
		// 	$response = wp_remote_post( $request, $params );

		// 	if( is_wp_error( $response ) ) {
		// 		return $response;
		// 	}

		// 	$response = json_decode( wp_remote_retrieve_body( $response ) );

		// 	if ( ! empty( $response->data ) ) {

		// 		foreach ( $response->data as $contact ) {

		// 			$contact_ids[] = $contact->id;

		// 		}

		// 	} else {

		// 		$proceed = false;

		// 	}

		// 	//debug
		// 	$proceed = false;

		// }

		// Not bothering with this yet

		$contact_ids = array();

		return $contact_ids;

	}

}