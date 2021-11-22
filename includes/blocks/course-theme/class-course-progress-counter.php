<?php
/**
 * File containing the Course_Progress class.
 *
 * @package sensei
 * @since
 */

namespace Sensei\Blocks\Course_Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use \Sensei_Blocks;

/**
 * Class Course_Progress is responsible for rendering the '< Prev Lesson | Next Lesson >' blocks.
 */
class Course_Progress_Counter {
	/**
	 * Course_Progress constructor.
	 */
	public function __construct() {
		Sensei_Blocks::register_sensei_block(
			'sensei-lms/course-theme-course-progress-counter',
			[
				'render_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * Renders the block.
	 *
	 * @access private
	 *
	 * @return string The block HTML.
	 */
	public function render() : string {
		$course_id = \Sensei_Utils::get_current_course();
		if ( ! $course_id ) {
			return '';
		}

		$stats  = \Sensei()->course->get_progress_stats( $course_id );
		$output = sprintf(
			/* translators: Placeholder %1$d is the completed lessons count, %2$d is the total lessons count and %3$d is the percentage of completed lessons. */
			__( '%1$d of %2$d lessons complete (%3$d%%)', 'sensei-lms' ),
			$stats['completed_lessons_count'],
			$stats['lessons_count'],
			$stats['completed_lessons_percentage']
		);

		return ( "
			<div class='sensei-course-theme-course-progress'>
				{$output}
			</div>
		" );
	}
}
