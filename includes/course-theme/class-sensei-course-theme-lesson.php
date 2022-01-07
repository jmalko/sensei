<?php
/**
 * File containing Sensei_Course_Theme_Lesson class.
 *
 * @package sensei-lms
 * @since 3.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sensei_Course_Theme_Lesson class.
 *
 * @since 3.15.0
 */
class Sensei_Course_Theme_Lesson {
	/**
	 * Instance of class.
	 *
	 * @var self
	 */
	private static $instance;

	/**
	 * Sensei_Course_Theme_Lesson constructor. Prevents other instances from being created outside of `self::instance()`.
	 */
	private function __construct() {}

	/**
	 * Fetches an instance of the class.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initializes the class.
	 */
	public function init() {
		if ( 'lesson' !== get_post_type() ) {
			return;
		}

		$this->maybe_add_quiz_results_notice();
		$this->maybe_add_lesson_prerequisite_notice();
		$this->maybe_add_not_enrolled_notice();
	}

	/**
	 * Maybe add lesson quiz results notice.
	 */
	private function maybe_add_quiz_results_notice() {
		$lesson_id = \Sensei_Utils::get_current_lesson();
		$user_id   = wp_get_current_user()->ID;

		if ( empty( $lesson_id ) || empty( $user_id ) ) {
			return;
		}

		$quiz_permalink = Sensei()->lesson->get_quiz_permalink( $lesson_id );

		if ( empty( $quiz_permalink ) ) {
			return;
		}

		if ( ! Sensei()->lesson->is_quiz_submitted( $lesson_id, $user_id ) ) {
			return;
		}

		$user_lesson_status = \Sensei_Utils::user_lesson_status( $lesson_id, $user_id );
		$user_grade         = Sensei_Quiz::get_user_quiz_grade( $lesson_id, $user_id );
		$user_grade         = Sensei_Utils::round( $user_grade, 2 );

		$quiz_id       = Sensei()->lesson->lesson_quizzes( $lesson_id );
		$quiz_passmark = get_post_meta( $quiz_id, '_quiz_passmark', true );
		$quiz_passmark = Sensei_Utils::round( $quiz_passmark, 2 );
		$pass_required = get_post_meta( $quiz_id, '_pass_required', true );

		if ( 'ungraded' === $user_lesson_status->comment_approved ) {
			$text = __( 'Awaiting grade', 'sensei-lms' );
		} elseif ( 'failed' === $user_lesson_status->comment_approved ) {
			// translators: Placeholders are the required grade and the actual grade, respectively.
			$text = sprintf( __( 'You require %1$s%% to pass this lesson\'s quiz. Your grade is %2$s%%.', 'sensei-lms' ), '<strong>' . $quiz_passmark . '</strong>', '<strong>' . $user_grade . '</strong>' );
		} else {
			// translators: Placeholder is the quiz grade.
			$text = sprintf( __( 'Your Grade %s%%', 'sensei-lms' ), '<strong class="sensei-course-theme-lesson-quiz-notice__grade">' . $user_grade . '</strong>' );
		}

		$notices = \Sensei_Context_Notices::instance( 'course_theme_lesson_quiz' );
		$actions = [
			[
				'label' => __( 'View quiz', 'sensei-lms' ),
				'url'   => $quiz_permalink,
				'style' => 'link',
			],
		];

		$notices->add_notice( 'lesson_quiz_results', $text, __( 'Quiz completed', 'sensei-lms' ), $actions );
	}

	/**
	 * Maybe add lesson prerequisite notice.
	 */
	private function maybe_add_lesson_prerequisite_notice() {
		$lesson_id = \Sensei_Utils::get_current_lesson();
		$course_id = Sensei()->lesson->get_course_id( $lesson_id );

		if ( ! Sensei_Course::is_user_enrolled( $course_id ) ) {
			return;
		}

		$user_id             = get_current_user_id();
		$lesson_prerequisite = \Sensei_Lesson::find_first_prerequisite_lesson( $lesson_id, $user_id );

		if ( $lesson_prerequisite > 0 ) {
			$user_lesson_status = \Sensei_Utils::user_lesson_status( $lesson_prerequisite, $user_id );

			$prerequisite_lesson_link = '<a href="'
				. esc_url( get_permalink( $lesson_prerequisite ) )
				. '" title="'
				// translators: Placeholder is the lesson prerequisite title.
				. sprintf( esc_attr__( 'You must first complete: %1$s', 'sensei-lms' ), get_the_title( $lesson_prerequisite ) )
				. '">'
				. esc_html__( 'prerequisites', 'sensei-lms' )
				. '</a>';

			$text = ! empty( $user_lesson_status ) && 'ungraded' === $user_lesson_status->comment_approved
				// translators: Placeholder is the link to the prerequisite lesson.
				? sprintf( esc_html__( 'You will be able to view this lesson once the %1$s are completed and graded.', 'sensei-lms' ), $prerequisite_lesson_link )
				// translators: Placeholder is the link to the prerequisite lesson.
				: sprintf( esc_html__( 'Please complete the %1$s to view this lesson content.', 'sensei-lms' ), $prerequisite_lesson_link );

			$notices = \Sensei_Context_Notices::instance( 'course_theme_locked_lesson' );
			$notices->add_notice( 'locked_lesson', $text, __( 'You don\'t have access to this lesson', 'sensei-lms' ), [], 'lock' );
		}
	}

	/**
	 * Maybe add not enrolled notice.
	 *
	 * @return void
	 */
	private function maybe_add_not_enrolled_notice() {
		$lesson_id = \Sensei_Utils::get_current_lesson();
		$course_id = Sensei()->lesson->get_course_id( $lesson_id );

		if ( Sensei_Course::is_user_enrolled( $course_id ) ) {
			return;
		}

		$notices = \Sensei_Context_Notices::instance( 'course_theme_locked_lesson' );

		// Course prerequisite notice.
		if ( ! Sensei_Course::is_prerequisite_complete( $course_id ) ) {
			$notices->add_notice(
				'locked_lesson',
				Sensei()->course::get_course_prerequisite_message( $course_id ),
				__( 'You don\'t have access to this lesson', 'sensei-lms' ),
				[],
				'lock'
			);

			return;
		}

		// Logged-out notice.
		if ( ! is_user_logged_in() ) {
			$user_can_register = get_option( 'users_can_register' );

			// Take course URL.
			$course_url      = add_query_arg( 'take_course_sign_in', '1', get_permalink( $course_id ) );
			$take_course_url = $user_can_register ? sensei_user_registration_url( true, $course_url ) : sensei_user_login_url( $course_url );

			// Sign in URL.
			$current_link = get_permalink();
			$sign_in_url  = $user_can_register ? sensei_user_registration_url( true, $current_link ) : sensei_user_login_url( $current_link );

			$actions = [
				[
					'label' => __( 'Take course', 'sensei-lms' ),
					'url'   => $take_course_url,
					'style' => 'primary',
				],
				[
					'label' => __( 'Sign in', 'sensei-lms' ),
					'url'   => $sign_in_url,
					'style' => 'secondary',
				],
			];

			$notices->add_notice(
				'locked_lesson',
				__( 'Please register or sign in to access the course content.', 'sensei-lms' ),
				__( 'You don\'t have access to this lesson', 'sensei-lms' ),
				$actions,
				'lock'
			);

			return;
		}

		// Not enrolled notice.
		$nonce   = wp_nonce_field( 'woothemes_sensei_start_course_noonce', 'woothemes_sensei_start_course_noonce', false, false );
		$actions = [
			'<form method="POST" action="' . esc_url( get_permalink( $course_id ) ) . '">
				<input type="hidden" name="course_start" value="1" />
				' . $nonce . '
				<button type="submit" class="sensei-course-theme__button is-primary">' . esc_html__( 'Take course', 'sensei-lms' ) . '</button>
			</form>',
		];

		$notices->add_notice(
			'locked_lesson',
			__( 'Please register for this course to access the content.', 'sensei-lms' ),
			__( 'You don\'t have access to this lesson', 'sensei-lms' ),
			$actions,
			'lock'
		);
	}
}
