<?php
/**
 * Class file for the Object_Sync_Sf_Salesforce_Push class.
 *
 * @file
 */

if ( ! class_exists( 'Object_Sync_Salesforce' ) ) {
	die();
}

/**
 * Push data from WordPress into Salesforce
 */
class Object_Sync_Sf_Salesforce_Push {

	protected $wpdb;
	protected $version;
	protected $login_credentials;
	protected $slug;
	protected $wordpress;
	protected $salesforce;
	protected $mappings;
	protected $logging;
	protected $schedulable_classes;
	protected $queue;
	protected $option_prefix;

	public $debug;

	/**
	* @var string
	*/
	public $schedule_name; // allow for naming the queue in case there are multiple queues

	/**
	* Constructor which sets up push schedule
	*
	* @param object $wpdb
	* @param string $version
	* @param array $login_credentials
	* @param string $slug
	* @param object $wordpress
	* @param object $salesforce
	* @param object $mappings
	* @param object $logging
	* @param array $schedulable_classes
	* @param object $queue
	* @param string $option_prefix
	* @throws \Object_Sync_Sf_Exception
	*/
	public function __construct( $wpdb, $version, $login_credentials, $slug, $wordpress, $salesforce, $mappings, $logging, $schedulable_classes, $queue = '', $option_prefix = '' ) {
		$this->wpdb                = $wpdb;
		$this->version             = $version;
		$this->login_credentials   = $login_credentials;
		$this->slug                = $slug;
		$this->option_prefix       = isset( $option_prefix ) ? $option_prefix : 'object_sync_for_salesforce_';
		$this->wordpress           = $wordpress;
		$this->salesforce          = $salesforce;
		$this->mappings            = $mappings;
		$this->logging             = $logging;
		$this->schedulable_classes = $schedulable_classes;
		$this->queue               = $queue;

		$this->schedule_name = 'salesforce_push';

		// Create action hooks for WordPress objects. We run this after plugins are loaded in case something depends on another plugin.
		add_action( 'plugins_loaded', array( $this, 'add_actions' ) );

		$this->debug = get_option( $this->option_prefix . 'debug_mode', false );

	}

	/**
	* Create the action hooks based on what object maps exist from the admin settings.
	* We do not have any actions for blogroll at this time.
	*
	*/
	public function add_actions() {
		$db_version = get_option( $this->option_prefix . 'db_version', false );
		if ( $db_version === $this->version ) {
			foreach ( $this->mappings->get_fieldmaps() as $mapping ) {
				$object_type = $mapping['wordpress_object'];
				if ( 'user' === $object_type ) {
					if ( defined( 'ultimatemember_plugin_name' ) ) {
						add_action( 'um_user_register', array( $this, 'um_add_user' ), 11, 2 );
					} else {
						add_action( 'user_register', array( $this, 'add_user' ), 11, 1 );
					}
					add_action( 'profile_update', array( $this, 'edit_user' ), 11, 2 );
					add_action( 'delete_user', array( $this, 'delete_user' ) );
				} elseif ( 'post' === $object_type ) {
					add_action( 'save_post', array( $this, 'post_actions' ), 11, 2 );
				} elseif ( 'attachment' === $object_type ) {
					add_action( 'add_attachment', array( $this, 'add_attachment' ) );
					add_action( 'edit_attachment', array( $this, 'edit_attachment' ) );
					add_action( 'delete_attachment', array( $this, 'delete_attachment' ) );
				} elseif ( 'category' === $object_type || 'tag' === $object_type || 'post_tag' === $object_type ) {
					add_action( 'create_term', array( $this, 'add_term' ), 11, 3 );
					add_action( 'edit_terms', array( $this, 'edit_term' ), 11, 2 );
					add_action( 'delete_term', array( $this, 'delete_term' ), 10, 4 );
				} elseif ( 'comment' === $object_type ) {
					add_action( 'comment_post', array( $this, 'add_comment' ), 11, 3 );
					add_action( 'edit_comment', array( $this, 'edit_comment' ) );
					add_action( 'delete_comment', array( $this, 'delete_comment' ) ); // to be clear: this only runs when the comment gets deleted from the trash, either manually or automatically
				} else { // this is for custom post types
					// we still have to use save_post because save_post_type fails to pull in the metadata
					add_action( 'save_post', array( $this, 'post_actions' ), 11, 2 );
				}
			}
		}

		// hook that action-scheduler can call
		add_action( $this->option_prefix . 'push_record', array( $this, 'salesforce_push_sync_rest' ), 10, 4 );

	}

	/**
	* Method for ajax hooks to call for pushing manually
	*
	* @param string $object_type
	* @param int $wordpress_id
	* @param string $http_method
	*
	*/
	public function manual_push( $object_type, $wordpress_id, $http_method ) {
		$object = $this->wordpress->get_wordpress_object_data( $object_type, $wordpress_id );
		// run the WordPress trigger that corresponds to the HTTP method
		switch ( $http_method ) {
			case 'POST':
				$trigger = $this->mappings->sync_wordpress_create;
				break;
			case 'PUT':
				$trigger = $this->mappings->sync_wordpress_update;
				break;
			case 'DELETE':
				$trigger = $this->mappings->sync_wordpress_delete;
				break;
		}
		if ( isset( $trigger ) ) {
			$results = $this->salesforce_push_object_crud( $object_type, $object, $trigger, true );
			foreach ( $results as $result ) {
				if ( 'success' === $result['status'] ) {
					if ( 'POST' === $http_method || 'PUT' === $http_method ) {
						$code = '201';
					} elseif ( 'DELETE' === $http_method ) {
						$code = '204';
					}
				} else {
					$code = '405';
				}
			}
		} else {
			$code   = '405';
			$result = '';
		}
		$result = array(
			'code'   => $code,
			'result' => $results,
		);
		return $result;
	}

	/**
	* Callback method for adding a user
	*
	* @param string $user_id
	*/
	public function add_user( $user_id ) {
		$user = $this->wordpress->get_wordpress_object_data( 'user', $user_id );
		$this->object_insert( $user, 'user' );
	}

	/**
	* Callback method for adding a user via the Ultimate Member plugin
	*
	* @param string $user_id
	*/
	public function um_add_user( $user_id, $form_data = array() ) {
		$this->object_insert( $form_data, 'user' );
	}

	/**
	* Callback method for editing a user
	*
	* @param string $user_id
	* @param object $old_user_data
	*/
	public function edit_user( $user_id, $old_user_data ) {
		$user = $this->wordpress->get_wordpress_object_data( 'user', $user_id );
		$this->object_update( $user, 'user' );
	}

	/**
	* Callback method for deleting a user
	*
	* @param string $user_id
	*/
	public function delete_user( $user_id ) {
		// flag that this item has been deleted
		$user = $this->wordpress->get_wordpress_object_data( 'user', $user_id, true );
		$this->object_delete( $user, 'user' );
	}

	/**
	* Callback method for posts of any type
	* This can handle create, update, and delete actions
	*
	* @param string $post_id
	* @param object $post
	*/
	public function post_actions( $post_id, $post ) {

		$post_type = $post->post_type;

		if ( isset( $post->post_status ) && 'auto-draft' === $post->post_status ) {
			return;
		}
		// this plugin does not sync log, revision, or scheduled-action posts with salesforce since they're all included in this plugin for other purposes
		if ( isset( $post->post_type ) && in_array( $post->post_type, array( 'wp_log', 'revision', 'scheduled-action' ), true ) ) {
			return;
		}
		if ( $post->post_modified_gmt === $post->post_date_gmt && 'trash' !== $post->post_status ) {
			$update = 0;
			$delete = 0;
		} elseif ( 'trash' !== $post->post_status ) {
			$update = 1;
			$delete = 0;
		} elseif ( 'trash' === $post->post_status ) {
			$update = 0;
			$delete = 1;
		}

		// add support for woocommerce if it is installed
		if ( defined( 'WC_VERSION' ) ) {
			// statuses to ignore
			if ( isset( $post->post_status ) && in_array( $post->post_status, array( 'wc-pending' ), true ) ) {
				return;
			}
			// statuses to count as new. note that the api will also check to see if it already has been mapped before saving.
			if ( isset( $post->post_status ) && in_array( $post->post_status, array( 'wc-on-hold', 'wc-processing' ), true ) ) {
				$update = 0;
				$delete = 0;
			}
		}

		// if it is NOT a deletion, don't flag it as such
		if ( 1 !== $delete ) {
			$post = $this->wordpress->get_wordpress_object_data( $post->post_type, $post_id );
		} else {
			// otherwise, flag that this item has been deleted
			$post = $this->wordpress->get_wordpress_object_data( $post->post_type, $post_id, true );
		}
		if ( 1 === $update ) {
			$this->object_update( $post, $post_type );
		} elseif ( 1 === $delete ) {
			$this->object_delete( $post, $post_type );
		} else {
			$this->object_insert( $post, $post_type );
		}
	}

	/**
	* Callback method for adding an attachment
	*
	* @param string $post_id
	*/
	public function add_attachment( $post_id ) {
		$attachment = $this->wordpress->get_wordpress_object_data( 'attachment', $post_id );
		$this->object_insert( $attachment, 'attachment' );
	}

	/**
	* Callback method for editing an attachment
	*
	* @param string $post_id
	*/
	public function edit_attachment( $post_id ) {
		$attachment = $this->wordpress->get_wordpress_object_data( 'attachment', $post_id );
		$this->object_update( $attachment, 'attachment' );
	}

	/**
	* Callback method for editing an attachment
	*
	* @param string $post_id
	*/
	public function delete_attachment( $post_id ) {
		// flag that this item has been deleted
		$attachment = $this->wordpress->get_wordpress_object_data( 'attachment', $post_id, true );
		$this->object_delete( $attachment, 'attachment' );
	}

	/**
	* Callback method for adding a term
	*
	* @param string $term_id
	* @param string $tt_id
	* @param string $taxonomy
	*/
	public function add_term( $term_id, $tt_id, $taxonomy ) {
		$term = $this->wordpress->get_wordpress_object_data( $taxonomy, $term_id );
		$this->object_insert( $term, $taxonomy );
	}

	/**
	* Callback method for editing a term
	*
	* @param string $term_id
	* @param string $taxonomy
	*/
	public function edit_term( $term_id, $taxonomy ) {
		$term = $this->wordpress->get_wordpress_object_data( $taxonomy, $term_id );
		$this->object_update( $term, $taxonomy );
	}

	/**
	* Callback method for deleting a term
	*
	* @param int $term (id)
	* @param int $term_taxonomy_id
	* @param string $taxonomy (slug)
	* @param object $deleted_term
	*/
	public function delete_term( $term, $tt_id, $taxonomy, $deleted_term ) {
		$deleted_term = (array) $deleted_term;
		$type         = $deleted_term['taxonomy'];
		$this->object_delete( $deleted_term, $type );
	}

	/**
	* Callback method for adding a comment
	*
	* @param string $comment_id
	* @param int|string $comment_approved
	* @param array $commentdata
	*/
	public function add_comment( $comment_id, $comment_approved, $commentdata = array() ) {
		$comment = $this->wordpress->get_wordpress_object_data( 'comment', $comment_id );
		$this->object_insert( $comment, 'comment' );
	}

	/**
	* Callback method for editing a comment
	*
	* @param string $comment_id
	*/
	public function edit_comment( $comment_id ) {
		$comment = $this->wordpress->get_wordpress_object_data( 'comment', $comment_id );
		$this->object_update( $comment, 'comment' );
	}

	/**
	* Callback method for deleting a comment
	*
	* @param string $comment_id
	*/
	public function delete_comment( $comment_id ) {
		// flag that this item has been deleted
		$comment = $this->wordpress->get_wordpress_object_data( 'comment', $comment_id, true );
		$this->object_delete( $comment, 'comment' );
	}

	/**
	* Insert a new object
	* This calls the overall push crud method, which controls queuing and sending data to the Salesforce class
	*/
	private function object_insert( $object, $type ) {
		$this->salesforce_push_object_crud( $type, $object, $this->mappings->sync_wordpress_create );
	}

	/**
	* Update an existing object
	* This calls the overall push crud method, which controls queuing and sending data to the Salesforce class
	*/
	private function object_update( $object, $type ) {
		$this->salesforce_push_object_crud( $type, $object, $this->mappings->sync_wordpress_update );
	}

	/**
	* Delete an existing object
	* This calls the overall push crud method, which controls queuing and sending data to the Salesforce class
	*/
	private function object_delete( $object, $type ) {
		$this->salesforce_push_object_crud( $type, $object, $this->mappings->sync_wordpress_delete );
	}

	/**
	* Push objects to Salesforce.
	* This method decides whether to do the processing immediately or queue it to the schedule class (or skip it based on another plugin's activity)
	*
	* @param string $object_type
	*   Type of WordPress object.
	* @param array $object
	*   The WordPress data that needs to be sent to Salesforce.
	* @param int $sf_sync_trigger
	*   The trigger being responded to.
	* @param bool $manual
	*   Are we calling this manually?
	*
	*/
	private function salesforce_push_object_crud( $object_type, $object, $sf_sync_trigger, $manual = false ) {

		$structure               = $this->wordpress->get_wordpress_table_structure( $object_type );
		$wordpress_id_field_name = $structure['id_field'];

		// there is a WordPress object to push
		if ( isset( $object[ $wordpress_id_field_name ] ) ) {
			// todo: we might want to loop through these?
			$mapping_object = $this->mappings->load_all_by_wordpress( $object_type, $object[ $wordpress_id_field_name ] );
			if ( ! empty( $mapping_object ) ) {
				$mapping_object = $mapping_object[0];
			}

			// there is already a mapping object for this WordPress object
			if ( isset( $mapping_object['id'] ) ) {
				$mapping_object_id_transient = $mapping_object['salesforce_id'];
			} else {
				// there is not a mapping object for this WordPress object id yet
				// check for that transient with the currently pulling id
				$mapping_object_id_transient = get_transient( 'salesforce_pulling_object_id' );
			}

			$salesforce_pulling = (int) get_transient( 'salesforce_pulling_' . $mapping_object_id_transient );
			if ( 1 === $salesforce_pulling ) {
				delete_transient( 'salesforce_pulling_' . $mapping_object_id_transient );
				$pulling_id = get_transient( 'salesforce_pulling_object_id' );
				if ( $pulling_id === $mapping_object_id_transient ) {
					delete_transient( 'salesforce_pulling_object_id' );
				}
				return false;
			}
		} else {
			// if we don't have a WordPress object id, we've got no business doing stuff in Salesforce
			$status = 'error';
			if ( isset( $this->logging ) ) {
				$logging = $this->logging;
			} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
				$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
			}

			$title = sprintf(
				// translators: placeholder is the name of the WordPress id field
				esc_html__( 'Error: Salesforce Push: unable to process queue item because it has no WordPress %1$s.', 'object-sync-for-salesforce' ),
				esc_attr( $wordpress_id_field_name )
			);

			$result = array(
				'title'   => $title,
				'message' => print_r( $object, true ), // print this array because if this happens, something weird has happened and we want to log whatever we have
				'trigger' => $sf_sync_trigger,
				'parent'  => 0, // parent id goes here but we don't have one, so make it 0,
				'status'  => $status,
			);

			$logging->setup( $result );
			return $result;
		} // End if().

		// load mappings that match this criteria
		// in this case, it's all mappings that correspond to the posted WordPress object
		$sf_mappings = $this->mappings->get_fieldmaps(
			null, // id field must be null for multiples
			array(
				'wordpress_object' => $object_type,
			)
		);

		$results = array();

		foreach ( $sf_mappings as $mapping ) { // for each mapping of this object
			$map_sync_triggers = $mapping['sync_triggers'];

			$push_allowed = $this->is_push_allowed( $object_type, $object, $sf_sync_trigger, $mapping, $map_sync_triggers );

			if ( false === $push_allowed ) {

				// we need to get the WordPress id here so we can check to see if the object already has a map
				$structure               = $this->wordpress->get_wordpress_table_structure( $object_type );
				$wordpress_id_field_name = $structure['id_field'];

				// this returns the WordPress rows that map to the individual Salesfoce row
				// we don't need to loop through these because we're just generating an error log for push not allowed.
				$mapping_object = $this->mappings->load_all_by_wordpress( $object_type, $object[ $wordpress_id_field_name ] );
				if ( ! empty( $mapping_object ) ) {
					$mapping_object = $mapping_object[0];
				}

				// hook to allow other plugins to define or alter the mapping object
				$mapping_object = apply_filters( $this->option_prefix . 'push_mapping_object', $mapping_object, $object, $mapping );

				// are these objects already connected in WordPress?
				if ( isset( $mapping_object['id'] ) ) {
					$is_new = false;
				} else {
					$is_new = true;
				}

				$status = 'error';
				// create log entry for not allowed push
				if ( isset( $this->logging ) ) {
					$logging = $this->logging;
				} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
					$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
				}

				$op = '';
				switch ( $sf_sync_trigger ) {
					case $this->mappings->sync_wordpress_create:
						if ( true === $is_new ) {
							$op = 'Create';
						}
						break;
					case $this->mappings->sync_wordpress_update:
						if ( false === $is_new ) {
							$op = 'Update';
						}
						break;
					case $this->mappings->sync_wordpress_delete:
						if ( false === $is_new ) {
							$op = 'Delete';
						}
						break;
				}

				$title = sprintf(
					// translators: placeholders are: 1) the name of the current operation, 2) the name of the WordPress object type, 3) the name of the WordPress ID field, 4) the value of the object's ID in WordPress, 5) the name of the Salesforce object
					esc_html__( 'Error: %1$s Salesforce %5$s with WordPress %2$s with %3$s of %4$s was not allowed by this fieldmap.', 'object-sync-for-salesforce' ),
					esc_attr( $op ),
					esc_attr( $mapping['wordpress_object'] ),
					esc_attr( $wordpress_id_field_name ),
					esc_attr( $object[ $wordpress_id_field_name ] ),
					esc_attr( $mapping['salesforce_object'] )
				);

				$result = array(
					'title'   => $title,
					'message' => '',
					'trigger' => $sf_sync_trigger,
					'parent'  => esc_attr( $object[ $wordpress_id_field_name ] ),
					'status'  => 'error',
				);
				if ( '' !== $op ) {
					$logging->setup( $result );
				}
				$results[] = $result;
				continue;
			}

			// push drafts if the setting says so
			// post status is draft, or post status is inherit and post type is not attachment
			if ( ( ! isset( $mapping['push_drafts'] ) || '1' !== $mapping['push_drafts'] ) && isset( $object['post_status'] ) && ( 'draft' === $object['post_status'] || ( 'inherit' === $object['post_status'] && 'attachment' !== $object['post_type'] ) ) ) {
				// skip this object if it is a draft and the fieldmap settings told us to ignore it
				continue;
			}

			if ( isset( $mapping['push_async'] ) && ( '1' === $mapping['push_async'] ) && false === $manual ) {
				// this item is async and we want to save it to the queue

				// if we determine that the below code does not perform well, worst case scenario is we could save $data to a custom table, and pass the id to the callback method.
				/*$data = array(
					'object_type'     => $object_type,
					'object'          => $object,
					'mapping'         => $mapping['id'],
					'sf_sync_trigger' => $sf_sync_trigger,
				);*/

				// add a queue action to push data to salesforce
				// this means we don't need the frequency for this method anymore, i think
				$this->queue->add(
					$this->schedulable_classes[ $this->schedule_name ]['callback'],
					array(
						'object_type'     => $object_type,
						'object'          => filter_var( $object[ $wordpress_id_field_name ], FILTER_VALIDATE_INT ),
						'mapping'         => filter_var( $mapping['id'], FILTER_VALIDATE_INT ),
						'sf_sync_trigger' => $sf_sync_trigger,
					),
					$this->schedule_name
				);

				$title = sprintf(
					// translators: placeholders are: 1) the name of the WordPress object type, 2) the name of the WordPress ID field, 3) the value of the object's ID in WordPress, 4) the name of the Salesforce object
					esc_html__( 'Success: Add to queue: Push WordPress %1$s with %2$s of %3$s to Salesforce %4$s.', 'object-sync-for-salesforce' ),
					esc_attr( $mapping['wordpress_object'] ),
					esc_attr( $wordpress_id_field_name ),
					esc_attr( $object[ $wordpress_id_field_name ] ),
					esc_attr( $mapping['salesforce_object'] )
				);

				$result    = array(
					'title'   => $title,
					'message' => '',
					'trigger' => $sf_sync_trigger,
					'parent'  => esc_attr( $object[ $wordpress_id_field_name ] ),
					'status'  => 'success',
				);
				$results[] = $result;
			} else {
				// this one is not async. do it immediately.
				$push      = $this->salesforce_push_sync_rest( $object_type, $object, $mapping, $sf_sync_trigger );
				$results[] = $push;
			} // End if().
		} // End foreach().
		return $results;
	}

	/**
	* Sync WordPress objects and Salesforce objects using the REST API.
	*
	* @param string $object_type
	*   Type of WordPress object.
	* @param array|int $object|$wordpress_id
	*   The WordPress object data or its ID value.
	* @param array $mapping|$mapping_id
	*   Salesforce field mapping data array or ID.
	* @param int $sf_sync_trigger
	*   Trigger for this sync.
	*
	* @return true or exit the method
	*
	*/
	public function salesforce_push_sync_rest( $object_type, $object, $mapping, $sf_sync_trigger ) {

		// when using async, this task receives the WordPress object id value as an integer. otherwise, it receives the WordPress object data
		if ( is_int( $object ) ) {
			$wordpress_id = $object;
			// if this is NOT a deletion, try to get all of the object's data
			if ( $sf_sync_trigger != $this->mappings->sync_wordpress_delete ) {
				$object = $this->wordpress->get_wordpress_object_data( $object_type, $wordpress_id );
			} else {
				// otherwise, flag it as a delete and limit what we try to get
				$object = $this->wordpress->get_wordpress_object_data( $object_type, $wordpress_id, true );
			}
		}

		if ( is_int( $mapping ) ) {
			$mapping_id = $mapping;
			$mapping    = $this->mappings->get_fieldmaps( $mapping_id );
		}

		// If Salesforce is not authorized, don't do anything.
		// it's unclear to me if we need to do something else here or if this is sufficient. This is all Drupal does.
		if ( true !== $this->salesforce['is_authorized'] ) {
			return;
		}

		$sfapi = $this->salesforce['sfapi'];

		// we need to get the WordPress id here so we can check to see if the object already has a map
		$structure               = $this->wordpress->get_wordpress_table_structure( $object_type );
		$wordpress_id_field_name = $structure['id_field'];

		// this returns the row that maps the individual WordPress row to the individual Salesfoce row
		// todo: we might need to loop through these?
		$mapping_object = $this->mappings->load_all_by_wordpress( $object_type, $object[ $wordpress_id_field_name ] );
		if ( ! empty( $mapping_object ) ) {
			$mapping_object = $mapping_object[0];
		}

		// hook to allow other plugins to define or alter the mapping object
		$mapping_object = apply_filters( $this->option_prefix . 'push_mapping_object', $mapping_object, $object, $mapping );

		// we already have the data from WordPress at this point; we just need to work with it in Salesforce
		$synced_object = array(
			'wordpress_object' => $object,
			'mapping_object'   => $mapping_object,
			'queue_item'       => false,
			'mapping'          => $mapping,
		);

		$op     = '';
		$result = '';

		// deleting mapped objects
		// these are bit operators, so we leave out the strict
		if ( $sf_sync_trigger == $this->mappings->sync_wordpress_delete ) {
			if ( isset( $mapping_object['id'] ) ) {
				$op = 'Delete';

				$mapping_objects = $this->mappings->load_all_by_salesforce( $mapping_object['salesforce_id'] );

				// only delete if there are no additional mapping objects for this record.
				if ( 1 === count( $mapping_objects ) ) {

					$frequencies = $this->queue->get_frequencies();
					$seconds     = reset( $frequencies )['frequency'] + 60;

					// right here we should set the pushing transient
					set_transient( 'salesforce_pushing_' . $mapping_object['salesforce_id'], 1, $seconds );
					set_transient( 'salesforce_pushing_object_id', $mapping_object['salesforce_id'] );

					try {
						$api_result = $sfapi->object_delete( $mapping['salesforce_object'], $mapping_object['salesforce_id'] );
					} catch ( Object_Sync_Sf_Exception $e ) {
						$status = 'error';
						// create log entry for failed delete
						if ( isset( $this->logging ) ) {
							$logging = $this->logging;
						} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
							$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
						}

						$title = sprintf(
							// translators: placeholders are: 1) what operation is happening, 2) the name of the Salesforce object, 3) the Salesforce Id value, 4) the name of the WordPress object type, 5) the WordPress id field name, 6) the WordPress object id value
							esc_html__( 'Error: %1$s Salesforce %2$s %3$s (WordPress %4$s with %5$s of %6$s)', 'object-sync-for-salesforce' ),
							esc_attr( $op ),
							esc_attr( $mapping['salesforce_object'] ),
							esc_attr( $mapping_object['salesforce_id'] ),
							esc_attr( $mapping['wordpress_object'] ),
							esc_attr( $wordpress_id_field_name ),
							esc_attr( $object[ "$wordpress_id_field_name" ] )
						);

						$result = array(
							'title'   => $title,
							'message' => $e->getMessage(),
							'trigger' => $sf_sync_trigger,
							'parent'  => $object[ "$wordpress_id_field_name" ],
							'status'  => $status,
						);

						$logging->setup( $result );

						// hook for push fail
						do_action( $this->option_prefix . 'push_fail', $op, $sfapi->response, $synced_object, $wordpress_id_field_name );

					}

					if ( ! isset( $e ) ) {
						// create log entry for successful delete if the result had no errors
						$status = 'success';
						if ( isset( $this->logging ) ) {
							$logging = $this->logging;
						} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
							$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
						}

						$title = sprintf(
							// translators: placeholders are: 1) what operation is happening, 2) the name of the Salesforce object, 3) the Salesforce Id value, 4) the name of the WordPress object type, 5) the WordPress id field name, 6) the WordPress object id value
							esc_html__( 'Success: %1$s Salesforce %2$s %3$s (WordPress %4$s with %5$s of %6$s)', 'object-sync-for-salesforce' ),
							esc_attr( $op ),
							esc_attr( $mapping['salesforce_object'] ),
							esc_attr( $mapping_object['salesforce_id'] ),
							esc_attr( $mapping['wordpress_object'] ),
							esc_attr( $wordpress_id_field_name ),
							esc_attr( $object[ "$wordpress_id_field_name" ] )
						);

						$result = array(
							'title'   => $title,
							'message' => '',
							'trigger' => $sf_sync_trigger,
							'parent'  => $object[ "$wordpress_id_field_name" ],
							'status'  => $status,
						);

						$logging->setup( $result );

						// hook for push success
						do_action( $this->option_prefix . 'push_success', $op, $sfapi->response, $synced_object, $mapping_object['salesforce_id'], $wordpress_id_field_name );
					}
				} else {
					$more_ids = __( '<p>The Salesforce record was not deleted because there are multiple WordPress IDs that match this Salesforce ID. They are:</p>', 'object-sync-for-salesforce' );

					$more_ids .= '<ul>';
					foreach ( $mapping_objects as $match ) {
						$more_ids .= '<li>' . $match['wordpress_id'] . '</li>';
					}
					$more_ids .= '</ul>';

					$more_ids .= __( '<p>The map row between this WordPress object and the Salesforce object, as stored in the WordPress database, will be deleted, and this WordPress object has been deleted, but Salesforce will remain untouched.</p>', 'object-sync-for-salesforce' );

					$status = 'notice';
					if ( isset( $this->logging ) ) {
						$logging = $this->logging;
					} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
						$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
					}

					$title = sprintf(
						// translators: placeholders are: 1) what operation is happening, 2) the name of the Salesforce object, 3) the Salesforce Id value, 4) the name of the WordPress object type, 5) the WordPress id field name, 6) the WordPress object id value
						esc_html__( 'Notice: %1$s on Salesforce %2$s with Id of %3$s was stopped because there are other Salesforce records mapped to WordPress %4$s with %5$s of %6$s', 'object-sync-for-salesforce' ),
						esc_attr( $op ),
						esc_attr( $mapping['salesforce_object'] ),
						esc_attr( $mapping_object['salesforce_id'] ),
						esc_attr( $mapping['wordpress_object'] ),
						esc_attr( $wordpress_id_field_name ),
						esc_attr( $object[ "$wordpress_id_field_name" ] )
					);

					$result = array(
						'title'   => $title,
						'message' => $more_ids,
						'trigger' => $sf_sync_trigger,
						'parent'  => $object[ "$wordpress_id_field_name" ],
						'status'  => $status,
					);

					$logging->setup( $result );

				} // End if().

				// right here we should change the pushing_object_id transient to the Salesforce Id value
				if ( isset( $api_result['code'] ) && (int) 204 === $api_result['code'] ) {
					set_transient( 'salesforce_pushing_' . $mapping_object['salesforce_id'], 1 );
					set_transient( 'salesforce_pushing_object_id', $mapping_object['salesforce_id'] );
				}

				// delete the map row from WordPress after the Salesforce row has been deleted
				// we delete the map row even if the Salesforce delete failed, because the WordPress object is gone
				$this->mappings->delete_object_map( $mapping_object['id'] );

			} // End if(). there is no map row

			return $result;
		} // End if().

		// are these objects already connected in WordPress?
		if ( isset( $mapping_object['id'] ) ) {
			$is_new = false;
		} else {
			$is_new = true;
		}

		// map the WordPress values to salesforce fields
		$params = $this->mappings->map_params( $mapping, $object, $sf_sync_trigger, false, $is_new, $wordpress_id_field_name );

		// hook to allow other plugins to modify the $params array
		// use hook to map fields between the WordPress and Salesforce objects
		// returns $params.
		$params = apply_filters( $this->option_prefix . 'push_params_modify', $params, $mapping, $object, $sf_sync_trigger, false, $is_new );

		// if we don't get any params, there are no fields that should be sent to Salesforce
		if ( empty( $params ) ) {
			return;
		}

		// if there is a prematch WordPress field - ie email - on the fieldmap object
		if ( isset( $params['prematch'] ) && is_array( $params['prematch'] ) ) {
			$prematch_field_wordpress  = $params['prematch']['wordpress_field'];
			$prematch_field_salesforce = $params['prematch']['salesforce_field'];
			$prematch_value            = $params['prematch']['value'];
			unset( $params['prematch'] );
		}

		// if there is an external key field in Salesforce - ie mailchimp user id - on the fieldmap object
		if ( isset( $params['key'] ) && is_array( $params['key'] ) ) {
			$key_field_wordpress  = $params['key']['wordpress_field'];
			$key_field_salesforce = $params['key']['salesforce_field'];
			$key_value            = $params['key']['value'];
			unset( $params['key'] );
		}

		$frequencies  = $this->queue->get_frequencies();
		$seconds      = reset( $frequencies )['frequency'] + 60;
		$saved_params = filter_var( get_option( $this->option_prefix . 'missing_required_data_id_' . $object[ $wordpress_id_field_name ], false ), FILTER_VALIDATE_BOOLEAN );

		// start the is_new stuff
		if ( true === $is_new || true === $saved_params ) {
			if ( true === $saved_params ) {
				delete_option( $this->option_prefix . 'missing_required_data_id_' . $object[ $wordpress_id_field_name ] );
			}
			// right here we should set the pushing transient
			// this means we have to create the mapping object here as well, and update it with the correct IDs after successful response
			// create the mapping object between the rows
			$temporary_map_id  = $this->mappings->generate_temporary_id( 'push' );
			$mapping_object_id = $this->create_object_map( $object, $wordpress_id_field_name, $temporary_map_id, $mapping, true );
			set_transient( 'salesforce_pushing_' . $temporary_map_id, 1, $seconds );
			set_transient( 'salesforce_pushing_object_id', $temporary_map_id );
			$mapping_object = $this->mappings->get_object_maps(
				array(
					'id' => $mapping_object_id,
				)
			);

			// setup SF record type. CampaignMember objects get their Campaign's type
			// i am still a bit confused about this
			if ( $mapping['salesforce_record_type_default'] !== $this->mappings->salesforce_default_record_type && empty( $params['RecordTypeId'] ) && ( 'CampaignMember' !== $mapping['salesforce_object'] ) ) {
				$params['RecordTypeId'] = $mapping['salesforce_record_type_default'];
			}

			try {

				// hook to allow other plugins to modify the $salesforce_id string here
				// use hook to change the object that is being matched to developer's own criteria
				// ex: match a Salesforce Contact based on a connected email address object
				// returns a $salesforce_id.
				// it should keep NULL if there is no match
				// the function that calls this hook needs to check the mapping to make sure the WordPress object is the right type
				$salesforce_id = apply_filters( $this->option_prefix . 'find_sf_object_match', null, $object, $mapping, 'push' );

				// hook to allow other plugins to do something right before Salesforce data is saved
				// ex: run WordPress methods on an object if it exists, or do something in preparation for it if it doesn't
				do_action( $this->option_prefix . 'pre_push', $salesforce_id, $mapping, $object, $wordpress_id_field_name, $params );

				// hook to allow other plugins to change params on update actions only
				// use hook to map fields between the WordPress and Salesforce objects
				// returns $params.
				$params = apply_filters( $this->option_prefix . 'push_update_params_modify', $params, $salesforce_id, $mapping, $object, $mapping['wordpress_object'] );

				if ( isset( $prematch_field_wordpress ) || isset( $key_field_wordpress ) || null !== $salesforce_id ) {

					// if either prematch criteria exists, make the values queryable

					if ( isset( $prematch_field_wordpress ) ) {
						// a prematch has been specified, attempt an upsert().
						// prematch values with punctuation need to be escaped
						$encoded_prematch_value = rawurlencode( $prematch_value );
						// for at least 'email' fields, periods also need to be escaped:
						// https://developer.salesforce.com/forums?id=906F000000099xPIAQ
						$encoded_prematch_value = str_replace( '.', '%2E', $encoded_prematch_value );
					}

					if ( isset( $key_field_wordpress ) ) {
						// an external key has been specified, attempt an upsert().
						// external key values with punctuation need to be escaped
						$encoded_key_value = rawurlencode( $key_value );
						// for at least 'email' fields, periods also need to be escaped:
						// https://developer.salesforce.com/forums?id=906F000000099xPIAQ
						$encoded_key_value = str_replace( '.', '%2E', $encoded_key_value );
					}

					if ( isset( $prematch_field_wordpress ) ) {
						$upsert_key   = $prematch_field_salesforce;
						$upsert_value = $encoded_prematch_value;
					} elseif ( isset( $key_field_wordpress ) ) {
						$upsert_key   = $key_field_salesforce;
						$upsert_value = $encoded_key_value;
					}

					if ( null !== $salesforce_id ) {
						$upsert_key   = 'Id';
						$upsert_value = $salesforce_id;
					}

					$op = 'Upsert';

					$api_result = $sfapi->object_upsert( $mapping['salesforce_object'], $upsert_key, $upsert_value, $params );

					// Handle upsert responses.
					switch ( $sfapi->response['code'] ) {
						// On Upsert:update retrieved object.
						case '204':
							$sf_object       = $sfapi->object_readby_external_id(
								$mapping['salesforce_object'],
								$upsert_key,
								$upsert_value,
								array(
									'cache' => false,
								)
							);
							$salesforce_data = $sf_object['data'];
							break;
						// Handle duplicate records.
						case '300':
							$api_result['data']['errorCode'] = $sfapi->response['error'] . ' (' . $upsert_key . ':' . $upsert_value . ')';
							break;
					}
				} else {
					// No key or prematch field exists on this field map object, create a new object in Salesforce.
					$op         = 'Create';
					$api_result = $sfapi->object_create( $mapping['salesforce_object'], $params );
				} // End if().
			} catch ( Object_Sync_Sf_Exception $e ) {
				// create log entry for failed create or upsert
				$status = 'error';

				if ( isset( $this->logging ) ) {
					$logging = $this->logging;
				} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
					$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
				}

				$title = sprintf(
					// translators: placeholders are: 1) what operation is happening, 2) the name of the Salesforce object, 3) the Salesforce Id value if there is one, 4) the name of the WordPress object type, 5) the WordPress id field name, 6) the WordPress object id value
					esc_html__( 'Error: %1$s Salesforce %2$s %3$s (WordPress %4$s with %5$s of %6$s)', 'object-sync-for-salesforce' ),
					esc_attr( $op ),
					esc_attr( $mapping['salesforce_object'] ),
					isset( $salesforce_id ) ? ' ' . esc_attr( $salesforce_id ) : '',
					esc_attr( $mapping['wordpress_object'] ),
					esc_attr( $wordpress_id_field_name ),
					esc_attr( $object[ "$wordpress_id_field_name" ] )
				);

				$result = array(
					'title'   => $title,
					'message' => $e->getMessage(),
					'trigger' => $sf_sync_trigger,
					'parent'  => $object[ "$wordpress_id_field_name" ],
					'status'  => $status,
				);

				$logging->setup( $result );

				// hook for push fail
				do_action( $this->option_prefix . 'push_fail', $op, $sfapi->response, $synced_object );

				return;
			} // End try().

			// Salesforce api call was successful
			// this means the object has already been created/updated in Salesforce
			// this is not redundant because this is where it creates the object mapping rows in WordPress if the object does not already have one (we are still inside $is_new === TRUE here)

			if ( empty( $api_result['data']['errorCode'] ) ) {

				if ( ! isset( $salesforce_data ) ) {
					// if we didn't set $salesforce_data already, set it now
					$sf_object       = $sfapi->object_read(
						$mapping['salesforce_object'],
						$api_result['data']['id'],
						array(
							'cache' => false,
						)
					);
					$salesforce_data = $sf_object['data'];
				}

				$salesforce_id = $salesforce_data['Id'];
				$status        = 'success';

				if ( isset( $this->logging ) ) {
					$logging = $this->logging;
				} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
					$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
				}

				$title = sprintf(
					// translators: placeholders are: 1) what operation is happening, 2) the name of the Salesforce object, 3) the Salesforce Id value, 4) the name of the WordPress object type, 5) the WordPress id field name, 6) the WordPress object id value
					esc_html__( 'Success: %1$s Salesforce %2$s %3$s (WordPress %4$s with %5$s of %6$s)', 'object-sync-for-salesforce' ),
					esc_attr( $op ),
					esc_attr( $mapping['salesforce_object'] ),
					esc_attr( $salesforce_id ),
					esc_attr( $mapping['wordpress_object'] ),
					esc_attr( $wordpress_id_field_name ),
					esc_attr( $object[ "$wordpress_id_field_name" ] )
				);

				$result = array(
					'title'   => $title,
					'message' => '',
					'trigger' => $sf_sync_trigger,
					'parent'  => $object[ "$wordpress_id_field_name" ],
					'status'  => $status,
				);

				$logging->setup( $result );

				// right here we should change the pushing transient to the LastModifiedDate for the Salesforce object.
				if ( isset( $salesforce_data['LastModifiedDate'] ) ) {
					set_transient( 'salesforce_pushing_' . $salesforce_id, strtotime( $salesforce_data['LastModifiedDate'] ) );
					set_transient( 'salesforce_pushing_object_id', $salesforce_id );
				}

				// update that mapping object
				$mapping_object['salesforce_id']     = $salesforce_id;
				$mapping_object['last_sync_message'] = esc_html__( 'Mapping object updated via function: ', 'object-sync-for-salesforce' ) . __FUNCTION__;
				$mapping_object_updated              = $this->mappings->update_object_map( $mapping_object, $mapping_object['id'] );

				// save the mapping object to the synced object
				$synced_object['mapping_object'] = $mapping_object;

				// hook for push success
				do_action( $this->option_prefix . 'push_success', $op, $sfapi->response, $synced_object, $salesforce_id, $wordpress_id_field_name );
			} else {

				// create log entry for failed create or upsert
				// this is part of the drupal module but i am failing to understand when it would ever fire, since the catch should catch the errors
				// if we see this in the log entries, we can understand what it does, but probably not until then
				$status = 'error';
				if ( isset( $this->logging ) ) {
					$logging = $this->logging;
				} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
					$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
				}

				$title = sprintf(
					// translators: placeholders are: 1) error code the Salesforce API returned, 2) what operation is happening, 3) the name of the WordPress object type, 4) the WordPress id field name, 5) the WordPress object id value
					esc_html__( '%1$s error syncing: %2$s to Salesforce (WordPress %3$s with %4$s of %5$s)', 'object-sync-for-salesforce' ),
					esc_attr( $api_result['data']['errorCode'] ),
					esc_attr( $op ),
					esc_attr( $mapping['wordpress_object'] ),
					esc_attr( $wordpress_id_field_name ),
					esc_attr( $object[ "$wordpress_id_field_name" ] )
				);

				$body = sprintf(
					// translators: placeholders are 1) the name of the Salesforce object type, 2) the error message returned from the Salesforce APIs
					'<p>' . esc_html__( 'Object: %1$s', 'object-sync-for-salesforce' ) . '</p><p>' . esc_html__( 'Message: %2$s', 'object-sync-for-salesforce' ) . '</p>',
					esc_attr( $mapping['salesforce_object'] ),
					esc_html( $api_result['data']['message'] )
				);

				$result = array(
					'title'   => $title,
					'message' => $body,
					'trigger' => $sf_sync_trigger,
					'parent'  => $object[ "$wordpress_id_field_name" ],
					'status'  => $status,
				);

				$logging->setup( $result );

				// hook for push fail
				do_action( $this->option_prefix . 'push_fail', $op, $sfapi->response, $synced_object );

				return $result;
			} // End if().
		} else {
			// $is_new is false here; we are updating an already mapped object

			// right here we should set the pushing transient
			set_transient( 'salesforce_pushing_' . $mapping_object['salesforce_id'], 1, $seconds );
			set_transient( 'salesforce_pushing_object_id', $mapping_object['salesforce_id'] );

			// there is an existing object link
			// if the last sync is greater than the last time this object was updated, skip it
			// this keeps us from doing redundant syncs
			$mapping_object['object_updated'] = current_time( 'mysql' );
			if ( $mapping_object['last_sync'] > $mapping_object['object_updated'] ) {
				$status = 'notice';
				if ( isset( $this->logging ) ) {
					$logging = $this->logging;
				} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
					$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
				}

				$title = sprintf(
					// translators: placeholders are: 1) what operation is happening, 2) the name of the WordPress object type, 3) the WordPress id field name, 4) the WordPress object id value, 5) the Salesforce Id value
					esc_html__( 'Notice: %1$s: Did not sync WordPress %2$s with %3$s of %4$s with Salesforce Id %5$s because the last sync timestamp was greater than the object updated timestamp.', 'object-sync-for-salesforce' ),
					esc_attr( $op ),
					esc_attr( $mapping['wordpress_object'] ),
					esc_attr( $wordpress_id_field_name ),
					esc_attr( $object[ "$wordpress_id_field_name" ] ),
					esc_attr( $mapping_object['salesforce_id'] )
				);

				$body = sprintf(
					// translators: placeholders are 1) when a sync on this mapping last occured, 2) when the object was last updated
					'<p>' . esc_html__( 'Last sync time: %1$s', 'object-sync-for-salesforce' ) . '</p><p>' . esc_html__( 'Object updated time: %2$s', 'object-sync-for-salesforce' ) . '</p>',
					esc_attr( $mapping_object['last_sync'] ),
					esc_html( $mapping_object['object_updated'] )
				);

				$result = array(
					'title'   => $title,
					'message' => $body,
					'trigger' => $sf_sync_trigger,
					'parent'  => 0, // parent id goes here but we don't have one, so make it 0,
					'status'  => $status,
				);

				$logging->setup( $result );
				return $result;
			}

			// try to make a Salesforce update call
			try {

				// hook to allow other plugins to do something right before Salesforce data is saved
				// ex: run WordPress methods on an object if it exists, or do something in preparation for it if it doesn't
				do_action( $this->option_prefix . 'pre_push', $mapping_object['salesforce_id'], $mapping, $object, $wordpress_id_field_name, $params );

				// hook to allow other plugins to change params on update actions only
				// use hook to map fields between the WordPress and Salesforce objects
				// returns $params.
				$params = apply_filters( $this->option_prefix . 'push_update_params_modify', $params, $mapping_object['salesforce_id'], $mapping, $object, $mapping['wordpress_object'] );

				$op         = 'Update';
				$api_result = $sfapi->object_update( $mapping['salesforce_object'], $mapping_object['salesforce_id'], $params );

				$mapping_object['last_sync_status']  = $this->mappings->status_success;
				$mapping_object['last_sync_message'] = esc_html__( 'Mapping object updated via function: ', 'object-sync-for-salesforce' ) . __FUNCTION__;

				$status = 'success';
				if ( isset( $this->logging ) ) {
					$logging = $this->logging;
				} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
					$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
				}

				$title = sprintf(
					// translators: placeholders are: 1) what operation is happening, 2) the name of the Salesforce object, 3) the Salesforce Id value, 4) the name of the WordPress object type, 5) the WordPress id field name, 6) the WordPress object id value
					esc_html__( 'Success: %1$s Salesforce %2$s %3$s (WordPress %4$s with %5$s of %6$s)', 'object-sync-for-salesforce' ),
					esc_attr( $op ),
					esc_attr( $mapping['salesforce_object'] ),
					esc_attr( $mapping_object['salesforce_id'] ),
					esc_attr( $mapping['wordpress_object'] ),
					esc_attr( $wordpress_id_field_name ),
					esc_attr( $object[ "$wordpress_id_field_name" ] )
				);

				$result = array(
					'title'   => $title,
					'message' => '',
					'trigger' => $sf_sync_trigger,
					'parent'  => 0, // parent id goes here but we don't have one, so make it 0,
					'status'  => $status,
				);

				$logging->setup( $result );

				// hook for push success
				do_action( $this->option_prefix . 'push_success', $op, $sfapi->response, $synced_object, $mapping_object['salesforce_id'], $wordpress_id_field_name );

			} catch ( Object_Sync_Sf_Exception $e ) {
				// create log entry for failed update
				$status = 'error';
				if ( isset( $this->logging ) ) {
					$logging = $this->logging;
				} elseif ( class_exists( 'Object_Sync_Sf_Logging' ) ) {
					$logging = new Object_Sync_Sf_Logging( $this->wpdb, $this->version );
				}

				$title = sprintf(
					// translators: placeholders are: 1) what operation is happening, 2) the name of the Salesforce object, 3) the Salesforce Id value, 4) the name of the WordPress object type, 5) the WordPress id field name, 6) the WordPress object id value
					esc_html__( 'Error: %1$s Salesforce %2$s %3$s (WordPress %4$s with %5$s of %6$s)', 'object-sync-for-salesforce' ),
					esc_attr( $op ),
					esc_attr( $mapping['salesforce_object'] ),
					esc_attr( $mapping_object['salesforce_id'] ),
					esc_attr( $mapping['wordpress_object'] ),
					esc_attr( $wordpress_id_field_name ),
					esc_attr( $object[ "$wordpress_id_field_name" ] )
				);

				$result = array(
					'title'   => $title,
					'message' => $e->getMessage(),
					'trigger' => $sf_sync_trigger,
					'parent'  => $object[ "$wordpress_id_field_name" ],
					'status'  => $status,
				);

				$logging->setup( $result );

				$mapping_object['last_sync_status']  = $this->mappings->status_error;
				$mapping_object['last_sync_message'] = $e->getMessage();

				// hook for push fail
				do_action( $this->option_prefix . 'push_fail', $op, $sfapi->response, $synced_object );

			} // End try().

			if ( ! isset( $salesforce_data ) ) {
				// if we didn't set $salesforce_data already, set it now
				$sf_object       = $sfapi->object_read(
					$mapping['salesforce_object'],
					$mapping_object['salesforce_id'],
					array(
						'cache' => false,
					)
				);
				$salesforce_data = $sf_object['data'];
			}

			// right here we should change the pushing transient to the LastModifiedDate for the Salesforce object.
			if ( isset( $salesforce_data['LastModifiedDate'] ) ) {
				set_transient( 'salesforce_pushing_' . $mapping_object['salesforce_id'], strtotime( $salesforce_data['LastModifiedDate'] ) );
				set_transient( 'salesforce_pushing_object_id', $mapping_object['salesforce_id'] );
			}

			// tell the mapping object - whether it is new or already existed - how we just used it
			$mapping_object['last_sync_action'] = 'push';
			$mapping_object['last_sync']        = current_time( 'mysql' );

			// update that mapping object
			$map_result = $this->mappings->update_object_map( $mapping_object, $mapping_object['id'] );

		} // End if(). this is the end of the if is_new stuff

		return $result;

	}

	/**
	* Create an object map between a WordPress object and a Salesforce object
	*
	* @param array $wordpress_object
	*   Array of the WordPress object's data
	* @param string $id_field_name
	*   How this object names its primary field. ie Id or comment_id or whatever
	* @param string $salesforce_id
	*   Unique identifier for the Salesforce object
	* @param array $field_mapping
	*   The row that maps the object types together, including which fields match which other fields
	*
	* @return int $wpdb->insert_id
	*   This is the database row for the map object
	*
	*/
	private function create_object_map( $wordpress_object, $id_field_name, $salesforce_id, $field_mapping, $pending = false ) {

		if ( true === $pending ) {
			$action = 'pending';
		} else {
			$action = 'created';
		}

		// Create object map and save it
		$mapping_object = $this->mappings->create_object_map(
			array(
				'wordpress_id'      => $wordpress_object[ $id_field_name ], // wordpress unique id
				'salesforce_id'     => $salesforce_id, // salesforce unique id. we don't care what kind of object it is at this point
				'wordpress_object'  => $field_mapping['wordpress_object'], // keep track of what kind of wp object this is
				'last_sync'         => current_time( 'mysql' ),
				'last_sync_action'  => 'push',
				'last_sync_status'  => $this->mappings->status_success,
				'last_sync_message' => sprintf(
					// translators: placeholder is for the action that occurred on the mapping object (pending or created)
					esc_html__( 'Mapping object %1$s via function: ', 'object-sync-for-salesforce' ) . __FUNCTION__,
					esc_attr( $action )
				),
				'action'            => $action,
			)
		);

		return $mapping_object;

	}

	/**
	* Find out if push is allowed for this record
	*
	* @param string $type
	*   WordPress object type
	* @param array $object
	*   Array of the WordPress object's data
	* @param string $sf_sync_trigger
	*   The current operation's trigger
	* @param array $mapping
	*   the fieldmap that maps the two object types
	* @param array $map_sync_triggers
	*
	* @return bool $push_allowed
	*   Whether all this stuff allows the $api_result to be pushed to Salesforce
	*
	*/
	private function is_push_allowed( $object_type, $object, $sf_sync_trigger, $mapping, $map_sync_triggers ) {

		// default is push is allowed
		$push_allowed = true;

		// if the current fieldmap does not allow the wp create trigger, we need to check if there is an object map for the WordPress object ID. if not, set push_allowed to false.
		if ( ! in_array( $this->mappings->sync_wordpress_create, $map_sync_triggers ) ) {
			$structure               = $this->wordpress->get_wordpress_table_structure( $object_type );
			$wordpress_id_field_name = $structure['id_field'];
			// we only need to check against the first mapping object, if it exists. we don't need to loop through them.
			$object_map = $this->mappings->load_all_by_wordpress( $object_type, $object[ $wordpress_id_field_name ] )[0];
			if ( empty( $object_map ) ) {
				$push_allowed = false;
			}
		}

		// these are bit operators, so we leave out the strict
		if ( ! in_array( $sf_sync_trigger, $map_sync_triggers ) ) {
			$push_allowed = false;
		}

		// hook to allow other plugins to prevent a push per-mapping.
		$push_allowed = apply_filters( $this->option_prefix . 'push_object_allowed', $push_allowed, $object_type, $object, $sf_sync_trigger, $mapping );

		// example to keep from pushing the user with ID of 1
		/*
		add_filter( 'object_sync_for_salesforce_push_object_allowed', 'check_user', 10, 5 );
		// can always reduce this number if all the arguments are not necessary
		function check_user( $push_allowed, $object_type, $object, $sf_sync_trigger, $mapping ) {
			if ( 'user' === $object_type && 1 === $object['ID'] ) { // do not add user 1 to salesforce
				$push_allowed = false;
			}
			return $push_allowed;
		}
		*/

		return $push_allowed;
	}

}
