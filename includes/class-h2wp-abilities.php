<?php
/**
 * Abilities API bootstrap for hub2wp.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class H2WP_Abilities {

	/**
	 * Management ability names used for debug logging.
	 *
	 * @var string[]
	 */
	private static $management_abilities = array(
		'hub2wp/install-plugin-from-github',
		'hub2wp/install-theme-from-github',
		'hub2wp/clear-cache',
		'hub2wp/run-update-check',
	);

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::is_available() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_action( 'wp_before_execute_ability', array( __CLASS__, 'log_before_execute' ), 10, 2 );
		add_action( 'wp_after_execute_ability', array( __CLASS__, 'log_after_execute' ), 10, 3 );
	}

	/**
	 * Check whether the Abilities API is available.
	 *
	 * @return bool
	 */
	private static function is_available() {
		return class_exists( 'WP_Ability' ) && function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Register ability categories.
	 *
	 * @return void
	 */
	public static function register_categories() {
		wp_register_ability_category(
			'hub2wp-discovery',
			array(
				'label'       => __( 'hub2wp Discovery', 'hub2wp' ),
				'description' => __( 'Inspect GitHub repositories and tracked items managed by hub2wp.', 'hub2wp' ),
			)
		);

		wp_register_ability_category(
			'hub2wp-management',
			array(
				'label'       => __( 'hub2wp Management', 'hub2wp' ),
				'description' => __( 'Install, maintain, and operate tracked extensions managed by hub2wp.', 'hub2wp' ),
			)
		);
	}

	/**
	 * Register abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		wp_register_ability(
			'hub2wp/list-tracked-plugins',
			array(
				'label'               => __( 'List Tracked Plugins', 'hub2wp' ),
				'description'         => __( 'Returns plugins tracked by hub2wp.', 'hub2wp' ),
				'category'            => 'hub2wp-discovery',
				'output_schema'       => self::get_tracked_items_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_list_tracked_plugins' ),
				'permission_callback' => array( __CLASS__, 'can_install_plugins' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
					),
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			'hub2wp/list-tracked-themes',
			array(
				'label'               => __( 'List Tracked Themes', 'hub2wp' ),
				'description'         => __( 'Returns themes tracked by hub2wp.', 'hub2wp' ),
				'category'            => 'hub2wp-discovery',
				'output_schema'       => self::get_tracked_items_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_list_tracked_themes' ),
				'permission_callback' => array( __CLASS__, 'can_install_themes' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
					),
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			'hub2wp/get-repository-details',
			array(
				'label'               => __( 'Get Repository Details', 'hub2wp' ),
				'description'         => __( 'Returns normalized details for a GitHub-hosted plugin or theme repository.', 'hub2wp' ),
				'category'            => 'hub2wp-discovery',
				'input_schema'        => self::get_repository_input_schema(),
				'output_schema'       => self::get_repository_details_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_get_repository_details' ),
				'permission_callback' => array( __CLASS__, 'can_manage_repository_type' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
					),
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			'hub2wp/check-repository-compatibility',
			array(
				'label'               => __( 'Check Repository Compatibility', 'hub2wp' ),
				'description'         => __( 'Checks whether a GitHub repository is compatible with WordPress as a plugin or theme.', 'hub2wp' ),
				'category'            => 'hub2wp-discovery',
				'input_schema'        => self::get_repository_input_schema(),
				'output_schema'       => self::get_compatibility_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_check_repository_compatibility' ),
				'permission_callback' => array( __CLASS__, 'can_manage_repository_type' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
					),
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			'hub2wp/install-plugin-from-github',
			array(
				'label'               => __( 'Install Plugin From GitHub', 'hub2wp' ),
				'description'         => __( 'Installs a GitHub-hosted plugin and registers it for hub2wp update tracking.', 'hub2wp' ),
				'category'            => 'hub2wp-management',
				'input_schema'        => self::get_install_input_schema(),
				'output_schema'       => self::get_tracked_item_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_install_plugin' ),
				'permission_callback' => array( __CLASS__, 'can_install_plugins' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
					),
					'show_in_rest' => false,
				),
			)
		);

		wp_register_ability(
			'hub2wp/install-theme-from-github',
			array(
				'label'               => __( 'Install Theme From GitHub', 'hub2wp' ),
				'description'         => __( 'Installs a GitHub-hosted theme and registers it for hub2wp update tracking.', 'hub2wp' ),
				'category'            => 'hub2wp-management',
				'input_schema'        => self::get_install_input_schema(),
				'output_schema'       => self::get_tracked_item_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_install_theme' ),
				'permission_callback' => array( __CLASS__, 'can_install_themes' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
					),
					'show_in_rest' => false,
				),
			)
		);

		wp_register_ability(
			'hub2wp/clear-cache',
			array(
				'label'               => __( 'Clear hub2wp Cache', 'hub2wp' ),
				'description'         => __( 'Clears cached GitHub API responses used by hub2wp.', 'hub2wp' ),
				'category'            => 'hub2wp-management',
				'output_schema'       => self::get_system_action_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_clear_cache' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
					),
					'show_in_rest' => false,
				),
			)
		);

		wp_register_ability(
			'hub2wp/run-update-check',
			array(
				'label'               => __( 'Run hub2wp Update Check', 'hub2wp' ),
				'description'         => __( 'Runs an immediate update check for tracked GitHub plugins and themes.', 'hub2wp' ),
				'category'            => 'hub2wp-management',
				'output_schema'       => self::get_system_action_output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute_run_update_check' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Execute tracked plugin listing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function execute_list_tracked_plugins() {
		$service = new H2WP_Tracked_Repo_Service();
		return $service->get_tracked_plugins();
	}

	/**
	 * Execute tracked theme listing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function execute_list_tracked_themes() {
		$service = new H2WP_Tracked_Repo_Service();
		return $service->get_tracked_themes();
	}

	/**
	 * Execute repository details lookup.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_get_repository_details( $input ) {
		$service = new H2WP_Repository_Query_Service();
		return $service->get_repository_details( $input['owner'], $input['repo'], self::get_input_repo_type( $input ) );
	}

	/**
	 * Execute repository compatibility check.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function execute_check_repository_compatibility( $input ) {
		$service = new H2WP_Repository_Query_Service();
		return $service->check_repository_compatibility( $input['owner'], $input['repo'], self::get_input_repo_type( $input ) );
	}

	/**
	 * Execute plugin install ability.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_install_plugin( $input ) {
		$result = H2WP_Repo_Manager::install_repository(
			$input['owner'],
			$input['repo'],
			array(
				'repo_type'           => 'plugin',
				'branch'              => isset( $input['branch'] ) ? sanitize_text_field( $input['branch'] ) : '',
				'prioritize_releases' => ! isset( $input['prioritize_releases'] ) || ! empty( $input['prioritize_releases'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::log_management_failure( 'hub2wp/install-plugin-from-github', $input, $result );
		}

		return $result;
	}

	/**
	 * Execute theme install ability.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_install_theme( $input ) {
		$result = H2WP_Repo_Manager::install_repository(
			$input['owner'],
			$input['repo'],
			array(
				'repo_type'           => 'theme',
				'branch'              => isset( $input['branch'] ) ? sanitize_text_field( $input['branch'] ) : '',
				'prioritize_releases' => ! isset( $input['prioritize_releases'] ) || ! empty( $input['prioritize_releases'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::log_management_failure( 'hub2wp/install-theme-from-github', $input, $result );
		}

		return $result;
	}

	/**
	 * Execute cache clear ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function execute_clear_cache() {
		$service = new H2WP_System_Action_Service();
		return $service->clear_cache();
	}

	/**
	 * Execute update check ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function execute_run_update_check() {
		$service = new H2WP_System_Action_Service();
		return $service->run_update_check();
	}

	/**
	 * Permission check for plugin management.
	 *
	 * @return bool
	 */
	public static function can_install_plugins() {
		return current_user_can( 'install_plugins' );
	}

	/**
	 * Permission check for theme management.
	 *
	 * @return bool
	 */
	public static function can_install_themes() {
		return current_user_can( 'install_themes' );
	}

	/**
	 * Permission check for settings/system actions.
	 *
	 * @return bool
	 */
	public static function can_manage_options() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission check based on input repo type.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return bool
	 */
	public static function can_manage_repository_type( $input = null ) {
		$repo_type = self::get_input_repo_type( $input );

		if ( 'theme' === $repo_type ) {
			return current_user_can( 'install_themes' );
		}

		return current_user_can( 'install_plugins' );
	}

	/**
	 * Log ability start.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $input Ability input.
	 * @return void
	 */
	public static function log_before_execute( $ability_name, $input ) {
		if ( ! self::should_log_management_ability( $ability_name ) ) {
			return;
		}

		self::log_debug( sprintf( 'Ability started: %s%s', $ability_name, self::get_repo_context_suffix( $input ) ) );
	}

	/**
	 * Log ability success.
	 *
	 * @param string $ability_name Ability name.
	 * @param mixed  $input Ability input.
	 * @param mixed  $result Ability result.
	 * @return void
	 */
	public static function log_after_execute( $ability_name, $input, $result ) {
		if ( ! self::should_log_management_ability( $ability_name ) ) {
			return;
		}

		self::log_debug( sprintf( 'Ability succeeded: %s%s', $ability_name, self::get_repo_context_suffix( $input ) ) );
	}

	/**
	 * Log a management ability failure.
	 *
	 * @param string   $ability_name Ability name.
	 * @param mixed    $input Ability input.
	 * @param WP_Error $error Error object.
	 * @return void
	 */
	private static function log_management_failure( $ability_name, $input, $error ) {
		if ( ! self::should_log_management_ability( $ability_name ) ) {
			return;
		}

		self::log_debug( sprintf( 'Ability failed: %s%s error=%s', $ability_name, self::get_repo_context_suffix( $input ), $error->get_error_message() ) );
	}

	/**
	 * Check whether logging should run for the ability.
	 *
	 * @param string $ability_name Ability name.
	 * @return bool
	 */
	private static function should_log_management_ability( $ability_name ) {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $ability_name, self::$management_abilities, true );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private static function log_debug( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[hub2wp] ' . $message );
		}
	}

	/**
	 * Get repository context suffix for logs.
	 *
	 * @param mixed $input Ability input.
	 * @return string
	 */
	private static function get_repo_context_suffix( $input ) {
		if ( ! is_array( $input ) || empty( $input['owner'] ) || empty( $input['repo'] ) ) {
			return '';
		}

		return sprintf( ' repo=%s/%s', sanitize_text_field( $input['owner'] ), sanitize_text_field( $input['repo'] ) );
	}

	/**
	 * Get normalized repo type from input.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return string
	 */
	private static function get_input_repo_type( $input ) {
		$repo_type = is_array( $input ) && ! empty( $input['repo_type'] ) ? sanitize_key( $input['repo_type'] ) : 'plugin';
		return in_array( $repo_type, array( 'plugin', 'theme' ), true ) ? $repo_type : 'plugin';
	}

	/**
	 * Get shared repository input schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_repository_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'owner'     => array(
					'type' => 'string',
				),
				'repo'      => array(
					'type' => 'string',
				),
				'repo_type' => array(
					'type' => 'string',
					'enum' => array( 'plugin', 'theme' ),
				),
			),
			'required'             => array( 'owner', 'repo' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get shared install input schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_install_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'owner'               => array(
					'type' => 'string',
				),
				'repo'                => array(
					'type' => 'string',
				),
				'branch'              => array(
					'type' => 'string',
				),
				'prioritize_releases' => array(
					'type' => 'boolean',
				),
			),
			'required'             => array( 'owner', 'repo' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get tracked items output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_tracked_items_output_schema() {
		return array(
			'type'  => 'array',
			'items' => self::get_tracked_item_output_schema(),
		);
	}

	/**
	 * Get tracked item output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_tracked_item_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'                => array( 'type' => 'string' ),
				'repo'                => array( 'type' => 'string' ),
				'repo_type'           => array( 'type' => 'string' ),
				'directory'           => array( 'type' => 'string' ),
				'installed'           => array( 'type' => 'boolean' ),
				'branch'              => array( 'type' => 'string' ),
				'prioritize_releases' => array( 'type' => 'boolean' ),
				'plugin_file'         => array( 'type' => 'string' ),
				'stylesheet'          => array( 'type' => 'string' ),
			),
			'additionalProperties' => true,
		);
	}

	/**
	 * Get repository details output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_repository_details_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'                => array( 'type' => 'string' ),
				'display_name'        => array( 'type' => 'string' ),
				'owner'               => array( 'type' => 'string' ),
				'repo'                => array( 'type' => 'string' ),
				'repo_type'           => array( 'type' => 'string' ),
				'description'         => array( 'type' => 'string' ),
				'readme'              => array( 'type' => 'string' ),
				'html_url'            => array( 'type' => 'string' ),
				'homepage'            => array( 'type' => 'string' ),
				'author'              => array( 'type' => 'string' ),
				'author_url'          => array( 'type' => 'string' ),
				'owner_avatar_url'    => array( 'type' => 'string' ),
				'og_image'            => array( 'type' => 'string' ),
				'updated_at'          => array( 'type' => 'string' ),
				'topics'              => array( 'type' => 'array' ),
				'is_installed'        => array( 'type' => 'boolean' ),
				'prioritize_releases' => array( 'type' => 'boolean' ),
				'uses_releases'       => array( 'type' => 'boolean' ),
				'version_source'      => array( 'type' => 'string' ),
			),
			'additionalProperties' => true,
		);
	}

	/**
	 * Get compatibility output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_compatibility_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'is_compatible' => array( 'type' => 'boolean' ),
				'reason'        => array( 'type' => 'string' ),
				'headers'       => array( 'type' => 'object' ),
				'source_context'=> array( 'type' => 'object' ),
			),
			'additionalProperties' => true,
		);
	}

	/**
	 * Get system action output schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_system_action_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'message'         => array( 'type' => 'string' ),
				'cleared_at'      => array( 'type' => 'integer' ),
				'tracked_plugins' => array( 'type' => 'integer' ),
				'tracked_themes'  => array( 'type' => 'integer' ),
				'ran_at'          => array( 'type' => 'integer' ),
			),
			'additionalProperties' => true,
		);
	}
}
