<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Sensei Teacher class
 *
 * All functionality pertaining to the teacher role.
 *
 * @package Users
 * @author Automattic
 * @since 1.0.0
 */
class Sensei_Teacher {

	/**
	 * $teacher_role
	 *
	 * Keeps a reference to the teacher role object
	 *
	 * @access protected
	 * @since 1.8.0
	 */
	protected $teacher_role;

	/**
	 * $token
	 *
	 * Keeps a reference to the global sensei token
	 *
	 * @access protected
	 * @since 1.8.0
	 */
	public $token;

	/**
	 * The nonce name when submitting a new message.
	 *
	 * @var string
	 */
	const NONCE_FIELD_NAME = 'sensei_meta_nonce';

	/**
	 * The nonce action name when submitting a new message.
	 */
	const NONCE_ACTION_NAME = 'sensei_save_data';

	/**
	 * Sensei_Teacher::__constructor
	 *
	 * Constructor Function
	 *
	 * @since 1.8.0
	 * @access public
	 */
	public function __construct() {

		add_action( 'add_meta_boxes', [ $this, 'add_teacher_meta_boxes' ], 10, 2 );
		add_action( 'save_post', [ $this, 'save_teacher_meta_box' ] );

		add_filter( 'parse_query', array( $this, 'limit_teacher_edit_screen_post_types' ) );
		add_filter( 'pre_get_posts', array( $this, 'course_analysis_teacher_access_limit' ) );
		add_filter( 'wp_count_posts', array( $this, 'list_table_counts' ), 10, 3 );

		add_action( 'pre_get_posts', array( $this, 'filter_queries' ) );

		// filter the quiz submissions
		add_filter( 'sensei_check_for_activity', array( $this, 'filter_grading_activity_queries' ) );

		// grading totals count only those belonging to the teacher
		add_filter( 'sensei_count_statuses_args', array( $this, 'limit_grading_totals' ) );

		// show the courses owned by a user on his author archive page
		add_filter( 'pre_get_posts', array( $this, 'add_courses_to_author_archive' ) );

		// notify admin when a teacher creates a course
		add_action( 'transition_post_status', array( $this, 'notify_admin_teacher_course_creation' ), 10, 3 );

		// limit the analysis view to only the users taking courses belong to this teacher
		add_filter( 'sensei_analysis_overview_filter_users', array( $this, 'limit_analysis_learners' ), 5, 1 );

		// give teacher access to question post type
		add_filter( 'sensei_lesson_quiz_questions', array( $this, 'allow_teacher_access_to_questions' ), 20, 2 );

		// Teacher column on the courses list on the admin edit screen
		add_filter( 'manage_course_posts_columns', array( $this, 'course_column_heading' ), 10, 1 );
		add_filter( 'manage_course_posts_custom_column', array( $this, 'course_column_data' ), 10, 2 );

		// admin edit messages query limit teacher
		add_filter( 'pre_get_posts', array( $this, 'limit_edit_messages_query' ) );

		// add filter by teacher on courses list
		add_action( 'restrict_manage_posts', array( $this, 'course_teacher_filter_options' ) );
		add_filter( 'request', array( $this, 'teacher_filter_query_modify' ) );

		// Handle media library restrictions
		add_filter( 'request', array( $this, 'restrict_media_library' ), 10, 1 );
		add_filter( 'ajax_query_attachments_args', array( $this, 'restrict_media_library_modal' ), 10, 1 );

		// update lesson owner to course teacher before insert
		add_filter( 'wp_insert_post_data', array( $this, 'update_lesson_teacher' ), 99, 2 );

		// If a Teacher logs in, redirect to /wp-admin/
		add_filter( 'wp_login', array( $this, 'teacher_login_redirect' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'restrict_posts_menu_page' ), 10 );
		add_filter( 'pre_get_comments', array( $this, 'restrict_comment_moderation' ), 10, 1 );

		// If slug changed to custom, try to extract and save teacher id.
		add_action( 'edit_module', [ $this, 'extract_and_save_teacher_to_meta_from_slug' ] );

	}

	/**
	 * Sensei_Teacher::create_teacher_role
	 *
	 * This function checks if the role exist, if not it creates it.
	 * for the teacher role
	 *
	 * @since 1.8.0
	 * @access public
	 * @return void
	 */
	public function create_role() {

		// check if the role exists
		$this->teacher_role = get_role( 'teacher' );

		// if the the teacher is not a valid WordPress role create it
		if ( ! is_a( $this->teacher_role, 'WP_Role' ) ) {
			// create the role
			$this->teacher_role = add_role( 'teacher', __( 'Teacher', 'sensei-lms' ) );
		}

		// add the capabilities before returning
		$this->add_capabilities();

	}

	/**
	 * Sensei_Teacher::add_capabilities
	 *
	 * @since 1.8.0
	 * @access protected
	 */
	protected function add_capabilities() {

		// if this is not a valid WP_Role object exit without adding anything
		if ( ! is_a( $this->teacher_role, 'WP_Role' ) || empty( $this->teacher_role ) ) {
			return;
		}

		/**
		 * Sensei teachers capabilities array filter
		 *
		 * These capabilities will be applied to the teacher role
		 *
		 * @param array $capabilities
		 * keys: (string) $cap_name => (bool) $grant
		 */
		$caps = apply_filters(
			'sensei_teacher_role_capabilities',
			array(
				// General access rules
				'read'                           => true,
				'manage_sensei_grades'           => true,
				'moderate_comments'              => true,
				'upload_files'                   => true,
				'edit_files'                     => true,

				// Lessons
				'publish_lessons'                => true,
				'manage_lesson_categories'       => true,
				'edit_lessons'                   => true,
				'edit_published_lessons'         => true,
				'edit_private_lessons'           => true,
				'read_private_lessons'           => true,
				'delete_published_lessons'       => true,

				// Courses
				'create_courses'                 => true,
				'publish_courses'                => false,
				'manage_course_categories'       => true,
				'edit_courses'                   => true,
				'edit_published_courses'         => true,
				'edit_private_courses'           => true,
				'read_private_courses'           => true,
				'delete_published_courses'       => true,

				// Quiz
				'publish_quizzes'                => true,
				'edit_quizzes'                   => true,
				'edit_published_quizzes'         => true,
				'edit_private_quizzes'           => true,
				'read_private_quizzes'           => true,

				// Questions
				'publish_questions'              => true,
				'edit_questions'                 => true,
				'edit_published_questions'       => true,
				'edit_private_questions'         => true,
				'read_private_questions'         => true,

				// messages
				'publish_sensei_messages'        => true,
				'edit_sensei_messages'           => true,
				'edit_published_sensei_messages' => true,
				'edit_private_sensei_messages'   => true,
				'read_private_sensei_messages'   => true,

				// Comments -
				// Necessary cap so Teachers can moderate comments
				// on their own lessons. We restrict access to other
				// post types in $this->restrict_posts_menu_page()
				'edit_posts'                     => true,

			)
		);

		foreach ( $caps as $cap => $grant ) {

			// load the capability on to the teacher role
			$this->teacher_role->add_cap( $cap, $grant );

		}

	}

	/**
	 * Sensei_Teacher::teacher_meta_box
	 *
	 * Add the teacher meta_box to the course post type edit screen
	 *
	 * @since 1.8.0
	 * @access public
	 * @parameter string $post_type
	 * @parameter WP_Post $post
	 * @return void
	 */
	public function add_teacher_meta_boxes( $post ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_meta_box(
			'sensei-teacher',
			__( 'Teacher', 'sensei-lms' ),
			array( $this, 'teacher_meta_box_content' ),
			'course',
			'side',
			'core',
			[
				'__block_editor_compatible_meta_box' => true,
				'__back_compat_meta_box'             => true,
			]
		);

	}

	/**
	 * Sensei_Teacher::teacher_meta_box_content
	 *
	 * Render the teacher meta box markup
	 *
	 * @since 1.8.0
	 * @access public
	 * @parameters
	 */
	public function teacher_meta_box_content( $post ) {
		wp_nonce_field( self::NONCE_ACTION_NAME, self::NONCE_FIELD_NAME );

		// get the current author
		$current_author = $post->post_author;

		// get the users authorised to author courses
		$users = $this->get_teachers_and_authors_with_fields( [ 'ID', 'display_name' ] );

		?>
		<input type="hidden" name="post_author_override" value="<?php echo intval( $current_author ); ?>" />
		<input type="hidden" name="course_module_custom_slugs" />
		<select name="sensei-course-teacher-author" class="sensei course teacher">

			<?php foreach ( $users as $user_data ) { ?>

					<?php
						$user_id           = $user_data->ID;
						$user_display_name = $user_data->display_name;
					?>
					<option <?php selected( $current_author, $user_id, true ); ?> value="<?php echo esc_attr( $user_id ); ?>" >
						<?php echo esc_html( $user_display_name ); ?>
					</option>

				<?php
			}
			?>

		</select>

		<?php
	}

	/**
	 * Sensei_Teacher::get_teachers_and_authors
	 *
	 * Get a list of users who can author courses, lessons and quizes.
	 *
	 * @since 1.8.0
	 * @access private
	 * @parameters
	 * @return array $users user id array
	 */
	public function get_teachers_and_authors() {
		$edit_course_roles = [];
		foreach ( wp_roles()->roles as $role_slug => $role ) {
			if ( ! empty( $role['capabilities']['edit_courses'] ) ) {
				$edit_course_roles[] = $role_slug;
			}
		}

		return get_users(
			[
				'blog_id'  => $GLOBALS['blog_id'],
				'fields'   => 'any',
				'role__in' => $edit_course_roles,
			]
		);
	}

	/**
	 * Get list of users who can author courses, lessons, and quizzes.
	 * Optionally specify the fields to return from the DB.
	 *
	 * @since 3.13.4
	 * @access private
	 *
	 * @param  string|array $fields Fields to return from DB. Defaults to 'ID'.
	 * @return array
	 */
	public function get_teachers_and_authors_with_fields( $fields = 'ID' ) {
		$ids = $this->get_teachers_and_authors();

		return get_users(
			[
				'blog_id' => $GLOBALS['blog_id'],
				'include' => $ids,
				'fields'  => $fields,
			]
		);
	}

	/**
	 * Sensei_Teacher::save_teacher_meta_box
	 *
	 * Save the new teacher / author from the meta box form.
	 *
	 * Hooked into admin_init
	 *
	 * @since 1.8.0
	 * @access public
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public function save_teacher_meta_box( $course_id ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Do not change the nonce.
		if ( empty( $_POST[ self::NONCE_FIELD_NAME ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD_NAME ] ), self::NONCE_ACTION_NAME ) ) {
			return;
		}

		// check if this is a post from saving the teacher, if not exit early
		if ( ! isset( $_POST['sensei-course-teacher-author'] ) || ! isset( $_POST['post_ID'] ) ) {
			return;
		}

		// If a custom slug is of a module that belongs to another teacher from another course, don't process farther.
		if ( isset( $_POST['course_module_custom_slugs'] ) ) {
			$module_custom_slugs = json_decode( sanitize_text_field( wp_unslash( $_POST['course_module_custom_slugs'] ) ) );
			foreach ( $module_custom_slugs as $module_custom_slug ) {
				$course_name = self::is_module_in_use_by_different_course_and_teacher( $module_custom_slug, $course_id, absint( $_POST['sensei-course-teacher-author'] ) );
				if ( $course_name ) {
					return;
				}
			}
		}

		// don't fire this hook again
		remove_action( 'save_post', array( $this, 'save_teacher_meta_box' ) );

		$new_teacher = absint( $_POST['sensei-course-teacher-author'] );
		$this->save_teacher( $course_id, $new_teacher );
	}

	/**
	 * Sensei_Teacher::save_teacher
	 *
	 * Save the new teacher / author to course and all lessons
	 *
	 * @access public
	 * @param int $course_id  Course ID.
	 * @param int $new_teacher  Course ID.
	 * @return void
	 */
	public function save_teacher( $course_id, $new_teacher ) {
		$post = get_post( $course_id );

		// get the current teacher/author
		$current_teacher = absint( $post->post_author );

		// loop through all post lessons to update their authors as well
		$this->update_course_lessons_author( $course_id, $new_teacher );

		// do not do any processing if the selected author is the same as the current author
		if ( $current_teacher == $new_teacher ) {
			return;
		}

		// save the course  author
		$post_updates = array(
			'ID'          => $post->ID,
			'post_author' => $new_teacher,
		);

		wp_update_post( $post_updates );

		// ensure the modules are update so that then new teacher has access to them.
		self::update_course_modules_author( $course_id, $new_teacher );

		// notify the new teacher
		$this->teacher_course_assigned_notification( $new_teacher, $course_id );
	}

	/**
	 * Check if the module with the slug provided is
	 * a part of any other course taught by a different teacher.
	 *
	 * @since 4.6.0
	 * @access private
	 *
	 * @param  string $module_slug Slug of the module to check for.
	 * @param  int    $course_id   Slugs of the modules to check for.
	 * @param  int    $teacher_id  Slugs of the modules to check for.
	 *
	 * @return string|boolean Returns the name of the first course it finds a match for, false otherwise.
	 */
	public static function is_module_in_use_by_different_course_and_teacher( $module_slug, $course_id, $teacher_id ) {
		$existing_module_by_slug = get_term_by( 'slug', $module_slug, 'module' );
		if ( $existing_module_by_slug ) {
			$args           = array(
				'post_type'      => 'course',
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => -1,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'module',
						'field'    => 'id',
						'terms'    => $existing_module_by_slug->term_id,
					),
				),
			);
			$module_courses = get_posts( $args );
			foreach ( $module_courses as $module_course ) {
				if ( intval( $module_course->post_author ) !== intval( $teacher_id ) &&
					intval( $course_id ) !== $module_course->ID ) {
					if ( user_can( $module_course->post_author, 'manage_options' ) && user_can( $teacher_id, 'manage_options' ) ) {
						continue;
					}
					return $module_course->post_title;
				}
			}
		}
		return false;
	}

	/**
	 * Update all the course terms set(selected) on the given course. Moving course term ownership to
	 * the new author. Making sure the course terms are maintained.
	 *
	 * This function also checks if terms are shared, with other courses
	 *
	 * @param $course_id
	 * @param $new_teacher_id
	 * @return void
	 */
	public static function update_course_modules_author( $course_id, $new_teacher_id ) {
		if ( empty( $course_id ) || empty( $new_teacher_id ) ) {
			return;
		}

		if ( ! taxonomy_exists( 'module' ) ) {
			// If for some reason the `module` taxonomy is not present (normally it is), register it now.
			// This mitigates a Jetpack-introduced bug.
			Sensei()->modules->setup_modules_taxonomy();
		}

		remove_filter( 'get_terms', array( Sensei()->modules, 'append_teacher_name_to_module' ), 70 );
		$modules = Sensei()->modules->get_course_modules( $course_id );
		add_filter( 'get_terms', array( Sensei()->modules, 'append_teacher_name_to_module' ), 70, 3 );

		if ( empty( $modules ) ) {
			return;
		}

		$lessons      = Sensei()->course->course_lessons( $course_id, null );
		$module_order = [];

		foreach ( $modules as $term ) {
			$term_author = Sensei_Core_Modules::get_term_author( $term->slug );

			if ( ! $term_author || intval( $new_teacher_id ) !== intval( $term_author->ID ) ) {
				$new_slug       = $new_teacher_id . '-' . sanitize_title( trim( $term->name ) );
				$search_slugs   = array();
				$search_slugs[] = $new_slug;

				// First, try to recycle an existing module.
				if ( user_can( $new_teacher_id, 'manage_options' ) ) {
					$admin_slug = sanitize_title( trim( $term->name ) );
					array_unshift( $search_slugs, $admin_slug );
					$new_slug = $admin_slug;
				}

				// Search for term to recycle.
				$new_term = false;

				foreach ( $search_slugs as $search_slug ) {
					$search_term = get_term_by( 'slug', $search_slug, 'module', ARRAY_A );

					if ( $search_term && ! is_wp_error( $search_term ) ) {
						$new_term = $search_term;
						break;
					}
				}

				if ( empty( $new_term ) ) {
					// Create new term and set it.
					$new_term = wp_insert_term(
						$term->name,
						'module',
						array(
							'slug' => $new_slug,
						)
					);
				}

				if ( is_wp_error( $new_term ) ) {
					// Something happened. Let's leave the module alone.
					continue;
				}

				$term_id        = $new_term['term_id'];
				$module_order[] = $term_id;

				Sensei_Core_Modules::update_module_teacher_meta( $term_id, $new_teacher_id );

				// Set the terms selected on the course.
				wp_set_object_terms( $course_id, $term_id, 'module', true );

				// Remove old term.
				if ( intval( $term_id ) !== intval( $term->term_id ) ) {
					wp_remove_object_terms( $course_id, $term->term_id, 'module' );
				}

				foreach ( $lessons as $lesson ) {
					if ( has_term( $term->term_id, 'module', $lesson ) ) {
						// Add the new term, the false at the end says to replace all terms on this module
						// with the new term.
						wp_set_object_terms( $lesson->ID, $term_id, 'module', false );

						$order_module     = 0;
						$old_order_module = get_post_meta( $lesson->ID, '_order_module_' . intval( $term->term_id ), true );

						if ( $old_order_module ) {
							$order_module = $old_order_module;

							if ( intval( $term->term_id ) !== intval( $term_id ) ) {
								delete_post_meta( $lesson->ID, '_order_module_' . intval( $term->term_id ) );
							}
						}

						update_post_meta( $lesson->ID, '_order_module_' . intval( $term_id ), $order_module );
					}
				}

				// Clean up module if no longer used.
				Sensei()->modules->remove_if_unused( $term->term_id );
			}
		}

		Sensei_Course_Structure::instance( $course_id )->save_module_order( $module_order );
	}

	/**
	 * Update all course lessons and their quiz with a new author.
	 *
	 * @since 1.8.0
	 * @access public
	 *
	 * @param int $course_id  The course id.
	 * @param int $new_author The new authors id.
	 *
	 * @return bool True when the author is updated.
	 */
	public function update_course_lessons_author( $course_id, $new_author ) {

		if ( empty( $course_id ) || empty( $new_author ) ) {
			return false;
		}

		// Get a list of course lessons.
		$lessons = Sensei()->course->course_lessons( $course_id, 'any' );

		if ( empty( $lessons ) || ! is_array( $lessons ) ) {
			return false;
		}

		// Temporarily remove the author match filter.
		remove_filter( 'wp_insert_post_data', array( $this, 'update_lesson_teacher' ), 99 );

		// Update each lesson and quiz author.
		foreach ( $lessons as $lesson ) {

			// Don't update if the author is tha same as the new author.
			if ( $new_author == $lesson->post_author ) {
				continue;
			}

			// Update lesson author.
			wp_update_post(
				array(
					'ID'          => $lesson->ID,
					'post_author' => $new_author,
				)
			);

			// Update quiz author.
			$lesson_quiz_id = Sensei()->lesson->lesson_quizzes( $lesson->ID );
			if ( ! empty( $lesson_quiz_id ) ) {
				Sensei()->quiz->update_quiz_author( (int) $lesson_quiz_id, (int) $new_author );
			}
		}

		// Reinstate the author match filter.
		add_filter( 'wp_insert_post_data', array( $this, 'update_lesson_teacher' ), 99, 2 );

		return true;
	}



	/**
	 * Sensei_Teacher::course_analysis_teacher_access_limit
	 *
	 * Alter the query so that users can only see their courses on the analysis page
	 *
	 * @since 1.8.0
	 * @access public
	 * @parameters $query
	 * @return array $users user id array
	 */
	public function course_analysis_teacher_access_limit( $query ) {

		if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $query;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return $query;
		}

		$screen            = get_current_screen();
		$sensei_post_types = array( 'course', 'lesson', 'question' );

		// exit early for the following conditions.
		$limit_screen_ids = array( 'sensei-lms_page_' . Sensei_Analysis::PAGE_SLUG, 'sensei-lms_page_module-order' );

		if ( ! $this->is_admin_teacher() || empty( $screen ) || ! in_array( $screen->id, $limit_screen_ids )
			|| ! in_array( $query->query['post_type'], $sensei_post_types ) ) {
			return $query;
		}

		global $current_user;
		// set the query author to the current user to only show those those posts
		$query->set( 'author', $current_user->ID );
		return $query;

	}


	/**
	 * Sensei_Teacher::limit_teacher_edit_screen_post_types
	 *
	 * Determine if we're in admin and the current logged in use is a teacher
	 *
	 * @since 1.8.0
	 * @access public
	 * @parameters array $wp_query
	 * @return bool $is_admin_teacher
	 */
	public function is_admin_teacher() {
		if ( ! function_exists( 'is_user_logged_in' ) ) {
			return false;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}
		$is_admin_teacher = false;

		if ( is_admin() && self::is_a_teacher( get_current_user_id() ) ) {

			$is_admin_teacher = true;

		}

		return $is_admin_teacher;

	}

	/**
	 * Show correct post counts on list table for Sensei post types
	 *
	 * @since 1.8.0
	 *
	 * @param  object $counts Default status counts
	 * @param  string $type   Current post type
	 * @param  string $perm   User permission level
	 * @return object         Modified status counts
	 */
	public function list_table_counts( $counts, $type, $perm ) {
		global $current_user;

		if ( ! in_array( $type, array( 'course', 'lesson', 'question' ) ) ) {
			return $counts;
		}

		if ( ! $this->is_admin_teacher() ) {
			return $counts;
		}

		$args = array(
			'post_type'      => $type,
			'author'         => $current_user->ID,
			'posts_per_page' => -1,
		);

		 // Get all available statuses
		$stati = get_post_stati();

		// Update count object
		foreach ( $stati as $status ) {
			$args['post_status'] = $status;
			$posts               = get_posts( $args );
			$counts->$status     = count( $posts );
		}

		return $counts;
	}

	/**
	 * Filter the post queries to show
	 * only lesson /course and users that belong
	 * to the current logged teacher.
	 *
	 * @since 1.8.0
	 */
	public function filter_queries( $query ) {
		global $current_user;

		if ( ! $this->is_admin_teacher() ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( empty( $screen ) ) {
			return $query;
		}
		switch ( $screen->id ) {
			case 'sensei-lms_page_sensei_grading':
			case 'sensei-lms_page_' . Sensei_Analysis::PAGE_SLUG:
			case 'sensei-lms_page_sensei_learners':
			case 'lesson':
			case 'course':
			case 'question':
			case 'lesson_page_module-order':
				/**
				 * sensei_filter_queries_set_author
				 * Filter the author Sensei set for queries
				 *
				 * @since 1.8.0
				 *
				 * @param int $user_id
				 * @param string $screen_id
				 */
				$query->set( 'author', apply_filters( 'sensei_filter_queries_set_author', $current_user->ID, $screen->id ) );
				break;
		}
	}

	/**
	 * Limit grading quizzes to only those within courses belonging to the current teacher
	 * . This excludes the admin user.
	 *
	 * @since 1.8.0
	 * @hooked into the_comments
	 * @param array $comments
	 *
	 * @return array $comments
	 */
	public function filter_grading_activity_queries( $comments ) {

		if ( ! is_admin() || ! $this->is_admin_teacher() || is_numeric( $comments ) || ! is_array( $comments ) ) {
			return $comments;
		}

		// check if we're on the grading screen
		$screen = get_current_screen();

		if ( empty( $screen ) || 'sensei-lms_page_sensei_grading' != $screen->id ) {
			return $comments;
		}

		// get the course and determine if the current teacher is the owner
		// if not remove it from the list of comments to be returned
		foreach ( $comments as $key => $comment ) {
			$lesson    = get_post( $comment->comment_post_ID );
			$course_id = Sensei()->lesson->get_course_id( $lesson->ID );
			$course    = get_post( $course_id );
			/**
			 * Allows to change the list of teacher IDs with grading access allowed for a given course ID.
			 *
			 * @hook   sensei_grading_allowed_user_ids
			 * @since  4.9.0
			 *
			 * @param {int[]} $user_ids The list of user IDs with access granted. By default the course author.
			 * @param {int} $course_id The course ID.
			 */
			$allowed_user_ids = apply_filters( 'sensei_grading_allowed_user_ids', [ intval( $course->post_author ) ], $course_id );
			if ( ! isset( $course->post_author ) || ! in_array( intval( get_current_user_id() ), $allowed_user_ids, true ) ) {
				// remove this as the teacher should see this.
				unset( $comments[ $key ] );
			}
		}
		return $comments;

	}

	/**
	 * Limit the grading screen totals to only show lessons in the course
	 * belonging to the currently logged in teacher. This only applies to
	 * the teacher role.
	 *
	 * @since 1.8.0
	 *
	 * @hooked into sensei_count_statuses_args
	 * @param array $args
	 *
	 * @return array  $args
	 */
	public function limit_grading_totals( $args ) {

		if ( ! is_admin() || ! $this->is_admin_teacher() || ! is_array( $args ) ) {
			return $args;
		}

		// get the teachers courses
		// the query is already filtered to only the teacher
		$courses = Sensei()->course->get_all_courses();

		if ( empty( $courses ) || ! is_array( $courses ) ) {
			return $args;
		}

		// setup the lessons quizzes  to limit the grading totals to
		$quiz_scope = array();
		foreach ( $courses as $course ) {

			$course_lessons = Sensei()->course->course_lessons( $course->ID );

			if ( ! empty( $course_lessons ) && is_array( $course_lessons ) ) {

				foreach ( $course_lessons as $lesson ) {

					array_push( $quiz_scope, $lesson->ID );

				}
			}
		}

		$args['post__in'] = $quiz_scope;

		return $args;
	}

	/**
	 * It ensures that the author archive shows course by the current user.
	 *
	 * This function is hooked into the pre_get_posts filter
	 *
	 * @param WP_Query $query
	 * @return WP_Query $query
	 */
	public function add_courses_to_author_archive( $query ) {

		if ( is_admin() || ! $query->is_author() ) {
			return $query;
		}

		// this should only apply to users with the teacher role
		$current_page_user = get_user_by( 'login', $query->get( 'author_name' ) );
		if ( ! $current_page_user || ! in_array( 'teacher', $current_page_user->roles ) ) {

			return $query;

		}

		// Change post types depending on what is set already
		$current_post_types = $query->get( 'post_type' );
		if ( empty( $current_post_types ) ) {

			// if empty it means post by default, so add post so that it also includes that for now
			$new_post_types = array( 'post', 'course' );

		} elseif ( is_array( $current_post_types ) ) {

			// merge the post types instead of overwriting it
			$new_post_types = array_merge( $current_post_types, array( 'course' ) );

		} else {

			// in this instance it is probably just one post type in string format
			$new_post_types = array( $current_post_types, 'course' );

		}

		// change the query before returning it
		$query->set( 'post_type', $new_post_types );

		/**
		 * Change the query on the teacher author archive template
		 *
		 * @since 1.8.4
		 * @param WP_Query $query
		 */
		return apply_filters( 'sensei_teacher_archive_query', $query );

	}

	/**
	 * Notify teacher when someone assigns a course to their account.
	 *
	 * @since 1.8.0
	 *
	 * @param $teacher_id
	 * @param $course_id
	 * @return bool
	 */
	public function teacher_course_assigned_notification( $teacher_id, $course_id ) {

		if ( 'course' != get_post_type( $course_id ) || ! get_userdata( $teacher_id ) ) {
			return false;
		}

		// if new user is the same as the current logged user, they don't need an email
		if ( $teacher_id == get_current_user_id() ) {
			return true;
		}

		// load the email class
		$email = new Sensei_Email_Teacher_New_Course_Assignment();
		$email->trigger( $teacher_id, $course_id );

		return true;
	}

	/**
	 * Email the admin when a teacher creates a new course
	 *
	 * This function hooks into wp_insert_post
	 *
	 * @since 1.8.0
	 * @param int $course_id
	 * @return bool
	 */
	public function notify_admin_teacher_course_creation( $new_status, $old_status, $post ) {

		$course_id = $post->ID;

		if ( 'publish' == $old_status || 'course' != get_post_type( $course_id ) || 'auto-draft' == get_post_status( $course_id )
			|| 'trash' == get_post_status( $course_id ) || 'draft' == get_post_status( $course_id ) ) {

			return false;

		}

		/**
		 * Filter the option to send admin notification emails when teachers creation
		 * course.
		 *
		 * @since 1.8.0
		 *
		 * @param bool $on default true
		 */
		if ( ! apply_filters( 'sensei_notify_admin_new_course_creation', true ) ) {
			return false;
		}

		// setting up the data needed by the email template
		global $sensei_email_data;
		$template  = 'admin-teacher-new-course-created';
		$course    = get_post( $course_id );
		$teacher   = new WP_User( $course->post_author );
		$recipient = get_option( 'admin_email', true );

		// don't send if the course is created by admin
		if ( $recipient == $teacher->user_email || current_user_can( 'manage_options' ) ) {
			return false;
		}

		do_action( 'sensei_before_mail', $recipient );

		/**
		 * Filter the email Header for the admin-teacher-new-course-created template
		 *
		 * @since 1.8.0
		 * @param string $template
		 */
		$heading = apply_filters( 'sensei_email_heading', __( 'New course created.', 'sensei-lms' ), $template );

		/**
		 * Filter the email subject for the the
		 * admin-teacher-new-course-created template
		 *
		 * @since 1.8.0
		 * @param string $subject default New course assigned to you
		 * @param string $template
		 */
		$subject = apply_filters(
			'sensei_email_subject',
			'[' . get_bloginfo( 'name', 'display' ) . '] ' . __( 'New course created by', 'sensei-lms' ) . ' ' . $teacher->display_name,
			$template
		);

		// course edit link
		$course_edit_link = admin_url( 'post.php?post=' . $course_id . '&action=edit' );

		// Construct data array
		$email_data = array(
			'template'         => $template,
			'heading'          => $heading,
			'teacher'          => $teacher,
			'course_id'        => $course_id,
			'course_name'      => $course->post_title,
			'course_edit_link' => $course_edit_link,
		);

		/**
		 * Filter the sensei email data for the admin-teacher-new-course-created template
		 *
		 * @since 1.8.0
		 * @param array $email_data
		 * @param string $template
		 */
		$sensei_email_data = apply_filters( 'sensei_email_data', $email_data, $template );

		// Send mail
		Sensei()->emails->send( $recipient, $subject, Sensei()->emails->get_content( $template ) );

		do_action( 'sensei_after_sending_email' );

	}

	/**
	 * Limit the analysis view to only the users taking courses belong to this teacher.
	 *
	 * Hooked into `sensei_analysis_overview_filter_users`.
	 *
	 * @param array $args WP_User_Query arguments.
	 *
	 * @return array Learners query args.
	 */
	public function limit_analysis_learners( $args ) {

		// show default for none teachers
		if ( ! Sensei()->teacher->is_admin_teacher() ) {
				return $args;
		}

		$learner_ids_for_courses_with_edit_permission = $this->get_learner_ids_for_courses_with_edit_permission();

		// if there are no students taking the courses by this teacher don't show them any of the other users
		if ( empty( $learner_ids_for_courses_with_edit_permission ) ) {

			$args['include'] = array( 0 );

		} else {

			$args['include'] = $learner_ids_for_courses_with_edit_permission;

		}

		return $args;

	}

	/**
	 * Get the learner IDs enrolled in courses where the current user has permission
	 * to edit (author or admin).
	 *
	 * @since 3.9.0 Method extracted from `Sensei_Teacher::limit_analysis_learners`.
	 *
	 * @return int[] Learner IDs.
	 */
	public function get_learner_ids_for_courses_with_edit_permission() {
		// for teachers all courses only return those which belong to the teacher
		// as they don't have access to course belonging to other users
		$courses_with_edit_permission = Sensei()->course->get_all_courses();

		if ( empty( $courses_with_edit_permission ) || ! is_array( $courses_with_edit_permission ) ) {
			return [];
		}

		$learner_ids_for_courses_with_edit_permission = [];

		foreach ( $courses_with_edit_permission as $course ) {

			$course_learner_ids = array();
			$activity_comments  = Sensei_Utils::sensei_check_for_activity(
				array(
					'post_id' => $course->ID,
					'type'    => 'sensei_course_status',
					'field'   => 'user_id',
				),
				true
			);

			if ( empty( $activity_comments ) || ( is_array( $activity_comments ) && ! ( count( $activity_comments ) > 0 ) ) ) {
				continue; // skip to the next course as there are no users on this course
			}

			// it could be an array of comments or a single comment
			if ( is_array( $activity_comments ) ) {

				foreach ( $activity_comments as $comment ) {

					$user = get_userdata( $comment->user_id );

					if ( empty( $user ) ) {
						// next comment in this array
						continue;
					}

					$course_learner_ids[] = $user->ID;
				}
			} else {

				$user                 = get_userdata( $activity_comments->user_id );
				$course_learner_ids[] = $user->ID;

			}

			// add learners on this course to the all courses learner list
			$learner_ids_for_courses_with_edit_permission = array_merge( $learner_ids_for_courses_with_edit_permission, $course_learner_ids );

		}

		return $learner_ids_for_courses_with_edit_permission;
	}

	/**
	 * Give teacher full admin access to the question post type
	 * in certain cases.
	 *
	 * @since 1.8.0
	 * @param $questions
	 * @return mixed
	 */
	public function allow_teacher_access_to_questions( $questions, $quiz_id ) {

		if ( ! $this->is_admin_teacher() ) {
			return $questions;
		}

		$screen = get_current_screen();

		// don't run this filter within this functions call to Sensei()->lesson->lesson_quiz_questions
		remove_filter( 'sensei_lesson_quiz_questions', array( $this, 'allow_teacher_access_to_questions' ), 20 );

		if ( ! empty( $screen ) && 'lesson' == $screen->post_type ) {

			$admin_user = get_user_by( 'email', get_bloginfo( 'admin_email' ) );
			if ( ! empty( $admin_user ) ) {

				$current_teacher_id = get_current_user_id();

				// set current user to admin so teacher can view all questions
				wp_set_current_user( $admin_user->ID );
				$questions = Sensei()->lesson->lesson_quiz_questions( $quiz_id );

				// set the teacher as the current use again
				wp_set_current_user( $current_teacher_id );
			}
		}
		// attach the filter again for other funtion calls to Sensei()->lesson->lesson_quiz_questions
		add_filter( 'sensei_lesson_quiz_questions', array( $this, 'allow_teacher_access_to_questions' ), 20, 2 );

		return $questions;
	}

	/**
	 * Give the teacher role access to questions from the question bank
	 *
	 * @since 1.8.0
	 * @param $wp_query
	 * @return mixed
	 */
	public function give_access_to_all_questions( $wp_query ) {

		if ( ! $this->is_admin_teacher() || ! function_exists( 'get_current_screen' ) || 'question' != $wp_query->get( 'post_type' ) ) {

			return $wp_query;
		}

		$screen = get_current_screen();
		if ( ( isset( $screen->id ) && 'lesson' == $screen->id )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {

			$admin_user = get_user_by( 'email', get_bloginfo( 'admin_email' ) );
			if ( ! empty( $admin_user ) ) {

				$current_teacher_id = get_current_user_id();

				// set current user to admin so teacher can view all questions
				wp_set_current_user( $admin_user->ID );

				// run new query as admin
				$wp_query = new WP_Query( $wp_query->query );

				// set the teache as current use again
				wp_set_current_user( $current_teacher_id );

			}
		}

		return $wp_query;
	}

	/**
	 * Add new column heading to the course admin edit list
	 *
	 * @since 1.8.0
	 * @param $columns
	 * @return array
	 */
	public function course_column_heading( $columns ) {

		if ( $this->is_admin_teacher() ) {
			return $columns;
		}
		$new_columns = array(
			'teacher' => __( 'Teacher', 'sensei-lms' ),
		);
		return array_merge( $columns, $new_columns );

	}

	/**
	 * Print out  teacher column data
	 *
	 * @since 1.8.0
	 * @param $column
	 * @param $course_id
	 */
	public function course_column_data( $column, $course_id ) {

		if ( $this->is_admin_teacher() || 'teacher' != $column ) {
			return;
		}

		$course  = get_post( $course_id );
		$teacher = get_userdata( $course->post_author );

		if ( ! $teacher ) {
			return;
		}

		echo '<a href="' . esc_url( get_edit_user_link( $teacher->ID ) ) . '" >' . esc_html( $teacher->display_name ) . '</a>';

	}

	/**
	 * Return only courses belonging to the given teacher.
	 *
	 * @since 1.8.0
	 *
	 * @param int  $teacher_id
	 * @param bool $return_ids_only
	 *
	 * @return array $teachers_courses
	 */
	public function get_teacher_courses( $teacher_id, $return_ids_only = false ) {

		$teachers_courses = array();

		if ( empty( $teacher_id ) ) {
			$teacher_id = get_current_user_id();
		}

		$all_courses = Sensei()->course->get_all_courses();

		if ( empty( $all_courses ) ) {
			return $all_courses;
		}

		foreach ( $all_courses as $course ) {

			if ( $course->post_author != $teacher_id ) {
				continue;
			}

			if ( $return_ids_only ) {

				$teachers_courses[] = $course->ID;

			} else {

				$teachers_courses[] = $course;

			}
		}

		return $teachers_courses;

	}

	/**
	 * Limit the message display to only those sent to the current teacher
	 *
	 * @since 1.8.0
	 *
	 * @param $query
	 * @return mixed
	 */
	public function limit_edit_messages_query( $query ) {
		if ( ! $this->is_admin_teacher() || 'sensei_message' != $query->get( 'post_type' ) ) {
			return $query;
		}

		$teacher = wp_get_current_user();

		$query->set( 'meta_key', '_receiver' );
		$meta_query_args = array(
			'key'     => '_receiver',
			'value'   => $teacher->get( 'user_login' ),
			'compare' => '=',
		);

		$query->set( 'meta_query', $meta_query_args );

		return $query;
	}


	/**
	 * Add options to filter courses by teacher
	 *
	 * @since 1.8.0
	 *
	 * @return void
	 */
	public function course_teacher_filter_options() {
		global $typenow;

		if ( ! is_admin() || 'course' != $typenow || ! current_user_can( 'manage_sensei' ) ) {
			return;
		}

		// get all roles
		$roles = get_editable_roles();

		// get roles with the course edit capability
		// and then get the users with those roles
		$role_users_who_can_edit_courses = array();
		foreach ( $roles as $role_item ) {

			$role = get_role( strtolower( $role_item['name'] ) );

			if ( is_a( $role, 'WP_Role' ) && $role->has_cap( 'edit_courses' ) ) {
				$role_users_who_can_edit_courses[] = $role->name;
			}
		}

		$user_query_args = array(
			'role__in' => $role_users_who_can_edit_courses,
			'fields'   => array( 'ID', 'display_name' ),
		);

		$users_who_can_edit_courses = get_users( $user_query_args );

		// Create the select element with the given users who can edit course
		$selected       = isset( $_GET['course_teacher'] ) ? $_GET['course_teacher'] : '';
		$course_options = '';
		foreach ( $users_who_can_edit_courses as $user ) {
			$course_options .= '<option value="' . esc_attr( $user->ID ) . '" ' . selected( $selected, $user->ID, false ) . '>' . esc_html( $user->display_name ) . '</option>';
		}

		$output  = '<select name="course_teacher" id="dropdown_course_teachers">';
		$output .= '<option value="">' . esc_html__( 'Show all teachers', 'sensei-lms' ) . '</option>';
		$output .= $course_options;
		$output .= '</select>';

		echo wp_kses(
			$output,
			array(
				'option' => array(
					'selected' => array(),
					'value'    => array(),
				),
				'select' => array(
					'id'   => array(),
					'name' => array(),
				),
			)
		);
	}

	/**
	 * Modify the main query on the admin course list screen
	 *
	 * @since 1.8.0
	 *
	 * @param $query
	 * @return $query
	 */
	public function teacher_filter_query_modify( $query ) {
		global $typenow;

		if ( ! is_admin() && 'course' != $typenow || ! current_user_can( 'manage_sensei' ) ) {
			return $query;
		}
		$course_teacher = isset( $_GET['course_teacher'] ) ? $_GET['course_teacher'] : '';

		if ( empty( $course_teacher ) ) {
			return $query;
		}

		$query['author'] = $course_teacher;
		return $query;
	}

	/**
	 * Only show current teacher's media in the media library
	 *
	 * @param  array $request Default request arguments
	 * @return array          Modified request arguments
	 */
	public function restrict_media_library( $request = array() ) {

		if ( ! is_admin() ) {
			return $request;
		}

		if ( ! $this->is_admin_teacher() ) {
			return $request;
		}

		$screen = get_current_screen();

		if ( in_array( $screen->id, array( 'upload', 'course', 'lesson', 'question' ) ) ) {
			$teacher = intval( get_current_user_id() );

			if ( $teacher ) {
				$request['author__in'] = array( $teacher );
			}
		}

		return $request;
	}

	/**
	 * Only show current teacher's media in the media library modal on the course/lesson/quesion edit screen
	 *
	 * @param  array $query Default query arguments
	 * @return array        Modified query arguments
	 */
	public function restrict_media_library_modal( $query = array() ) {

		if ( ! is_admin() ) {
			return $query;
		}

		if ( ! $this->is_admin_teacher() ) {
			return $query;
		}

		$teacher = intval( get_current_user_id() );

		if ( $teacher ) {
			$query['author__in'] = array( $teacher );
		}

		return $query;
	}

	/**
	 * When saving the lesson, update the teacher if the lesson belongs to a course
	 *
	 * @since 1.8.0
	 *
	 * @param int $lesson_id
	 */
	public function update_lesson_teacher( $data, $postarr = array() ) {

		if ( 'lesson' != $data['post_type'] ) {
			return $data;
		}

		$lesson_id = isset( $postarr['ID'] ) ? $postarr['ID'] : null;

		if ( empty( $lesson_id ) || ! $lesson_id ) {
			return $data;
		}

		$course_id = Sensei()->lesson->get_course_id( $lesson_id );

		if ( empty( $course_id ) || ! $course_id ) {
			return $data;
		}

		$course = get_post( $course_id );

		$data['post_author'] = $course->post_author;

		return $data;
	}

	/**
	 * Sensei_Teacher::limit_teacher_edit_screen_post_types
	 *
	 * Limit teachers to only see their courses, lessons and questions
	 *
	 * @since 1.8.0
	 * @access public
	 * @parameters array $wp_query
	 * @return WP_Query $wp_query
	 */
	public function limit_teacher_edit_screen_post_types( $wp_query ) {
		global $current_user;

		// exit early
		if ( ! $this->is_admin_teacher() ) {
			return $wp_query;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return $wp_query;
		}

		$screen = get_current_screen();

		if ( empty( $screen ) ) {
			return $wp_query;
		}

		// for any of these conditions limit what the teacher will see
		$limit_screens = array(
			'edit-lesson',
			'edit-course',
			'edit-question',
			'admin_page_course-order',
			'admin_page_lesson-order',
		);

		if ( in_array( $screen->id, $limit_screens ) ) {

			// set the query author to the current user to only show those those posts
			$wp_query->set( 'author', $current_user->ID );
		}

		return $wp_query;

	}


	/**
	 * Sensei_Teacher::teacher_login_redirect
	 *
	 * Redirect teachers to /wp-admin/ after login
	 *
	 * @since 1.8.7
	 * @access public
	 * @param string $user_login
	 * @param object $user
	 * @return void
	 */
	public function teacher_login_redirect( $user_login, $user ) {
		// If Jetpack's redirection cookie is set, let Jetpack handle redirection.
		if ( ! empty( $_COOKIE['jetpack_sso_redirect_to'] ) ) {
			return;
		}

		if ( user_can( $user, 'edit_courses' ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification -- We are not making any changes based on this.
			if ( isset( $_REQUEST['redirect_to'] ) ) {

				// phpcs:ignore WordPress.Security.NonceVerification -- We are not making any changes based on this.
				wp_safe_redirect( wp_unslash( $_REQUEST['redirect_to'] ), 303 );

				exit;

			} else {

				wp_redirect( admin_url(), 303 );

				exit;

			}
		}

	}



	/**
	 * Sensei_Teacher::restrict_posts_menu_page()
	 *
	 * Remove the Posts menu page for teachers and restrict access to it.
	 * We have to do this because we give teachers the 'edit_posts' cap
	 * so they can 'moderate_comments' as well.
	 *
	 * @since 1.8.7
	 * @access public
	 * @parameters void
	 * @return void
	 */

	public function restrict_posts_menu_page() {

		global $pagenow, $typenow;

		$user = wp_get_current_user();

		/**
		 * Filter the option to hide the Posts menu page.
		 *
		 * @since 1.8.7
		 *
		 * @param bool $restrict default true
		 */

		$restrict = apply_filters( 'sensei_restrict_posts_menu_page', true );

		if ( in_array( 'teacher', (array) $user->roles ) && ! current_user_can( 'delete_posts' ) && $restrict ) {

			remove_menu_page( 'edit.php' );

			if ( $pagenow == 'edit.php' || $pagenow == 'post-new.php' ) {

				if ( $typenow == '' || $typenow == 'post' || $typenow == 'page' ) {

					wp_die( 'You do not have sufficient permissions to access this page.' );

				}
			}
		}

	}

	/**
	 * Sensei_Teacher::restrict_comment_moderation()
	 *
	 * Restrict commendation moderation for teachers
	 * so they can only moderate comments made to posts they own.
	 *
	 * @since 1.8.7
	 * @access public
	 * @parameters obj $clauses
	 * @return WP_Comment_Query  $clauses
	 */
	public function restrict_comment_moderation( $clauses ) {

		global $pagenow;

		if ( self::is_a_teacher( get_current_user_id() ) && $pagenow == 'edit-comments.php' ) {

			$clauses->query_vars['post_author'] = get_current_user_id();

		}

		return $clauses;

	}

	/**
	 * Determine if a user is a teacher by ID
	 *
	 * @param int $user_id
	 *
	 * @return bool
	 */
	public static function is_a_teacher( $user_id ) {

		$user = get_user_by( 'id', $user_id );

		if ( isset( $user->roles ) && in_array( 'teacher', $user->roles ) ) {

			return true;

		} else {

			return false;

		}

	}

	/**
	 * The archive title on the teacher archive filter
	 *
	 * @since 1.9.0
	 */
	public static function archive_title() {

		$author      = get_user_by( 'id', get_query_var( 'author' ) );
		$author_name = $author->display_name;
		?>
			<h2 class="teacher-archive-title">

				<?php
				// translators: Placeholder is the author name.
				echo esc_html( sprintf( __( 'All courses by %s', 'sensei-lms' ), $author_name ) );
				?>

			</h2>
		<?php

	}

	/**
	 * Removing course meta on the teacher archive page
	 *
	 * @since 1.9.0
	 */
	public static function remove_course_meta_on_teacher_archive() {

		remove_action( 'sensei_course_content_inside_before', array( Sensei()->course, 'the_course_meta' ) );

	}

	/**
	 * Try to extract teacher id from module slug to term meta
	 * if the meta does not exist already
	 *
	 * @since 4.6.0
	 * @access private
	 *
	 * @param int $term_id ID of the term being edited.
	 * @return void
	 */
	public function extract_and_save_teacher_to_meta_from_slug( $term_id ) {
		$term_meta = get_term_meta( $term_id, 'module_author', true );

		if ( $term_meta ) {
			return;
		}

		$term       = get_term( $term_id, 'module' );
		$split_slug = explode( '-', $term->slug );

		if ( count( $split_slug ) > 1 && is_numeric( $split_slug[0] ) ) {
			$user = get_user_by( 'id', $split_slug[0] );
			$user && Sensei_Core_Modules::update_module_teacher_meta( $term_id, $user->ID );
		}
	}
}
