<?php
/*
Plugin Name: Tutor LMS Course Cloner
Description: Clone Tutor LMS courses including topics, lessons, quizzes, questions, and answers (for the free version) with progress logging.
Version: 2.0
Author: Tefkros, Gemini & ChatGPT
*/

// =====================
// CORE DUPLICATION LOGIC
// =====================

// Duplicate a post and its meta
function duplicate_post_with_meta($post_id, $parent_id = 0, $status = 'draft', $type_override = '') {
    $post = get_post($post_id);
    if (!$post) return false;

    $new_post_id = wp_insert_post([
        'post_title'   => $post->post_title,
        'post_content' => $post->post_content,
        'post_status'  => $status,
        'post_type'    => $type_override ?: $post->post_type,
        'post_author'  => $post->post_author,
        'post_parent'  => $parent_id,
        'menu_order'   => $post->menu_order,
        'post_name'    => sanitize_title($post->post_name . '-copy-' . time()),
    ]);

    if (!$new_post_id) return false;

    $meta = get_post_meta($post_id);
    foreach ($meta as $key => $values) {
        foreach ($values as $value) {
            update_post_meta($new_post_id, $key, maybe_unserialize($value));
        }
    }

    return $new_post_id;
}

// Duplicate quiz with questions and answers
function duplicate_quiz_full_log($quiz_id, $new_parent_id) {
    global $wpdb;

    $new_quiz_id = duplicate_post_with_meta_log($quiz_id, $new_parent_id, 'publish', "Quiz");
    if (!$new_quiz_id) return false;

    $questions = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}tutor_quiz_questions WHERE quiz_id = %d", $quiz_id),
        'ARRAY_A'
    );

    foreach ($questions as $question) {
        $old_question_id = $question['question_id'];
        unset($question['question_id']);
        $question['quiz_id'] = $new_quiz_id;

        $wpdb->insert("{$wpdb->prefix}tutor_quiz_questions", $question);
        $new_question_id = $wpdb->insert_id;
        echo "    ✅ Question $old_question_id → $new_question_id\n";

        $answers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tutor_quiz_question_answers WHERE belongs_question_id = %d",
                $old_question_id
            ),
            'ARRAY_A'
        );

        foreach ($answers as $answer) {
            unset($answer['answer_id']);
            $answer['belongs_question_id'] = $new_question_id;
            $wpdb->insert("{$wpdb->prefix}tutor_quiz_question_answers", $answer);
            echo "        ✅ Answer {$answer['answer_title']} → new ID assigned\n";
        }
    }

    return $new_quiz_id;
}

// Log-enabled duplication for posts
function duplicate_post_with_meta_log($post_id, $parent_id = 0, $status = 'draft', $type_label = '') {
    $new_id = duplicate_post_with_meta($post_id, $parent_id, $status);
    if ($new_id) {
        echo "✅ Duplicated $type_label: $post_id → $new_id\n";
    } else {
        echo "❌ Failed $type_label: $post_id\n";
    }
    return $new_id;
}

// Main duplication function
function duplicate_tutor_course_modular_with_log($original_course_id) {
    global $wpdb;

    $new_course_id = duplicate_post_with_meta_log($original_course_id, 0, 'draft', "Course");
    if (!$new_course_id) return false;

    // Topics
    $topics = get_posts([
        'post_type' => 'topics',
        'post_parent' => $original_course_id,
        'numberposts' => -1,
        'post_status' => 'any',
    ]);

    foreach ($topics as $topic) {
        $new_topic_id = duplicate_post_with_meta_log($topic->ID, $new_course_id, 'publish', "Topic");

        // Lessons
        $lessons = get_posts([
            'post_type' => 'lesson',
            'post_parent' => $topic->ID,
            'numberposts' => -1,
            'post_status' => 'any',
        ]);

        foreach ($lessons as $lesson) {
            duplicate_post_with_meta_log($lesson->ID, $new_topic_id, 'publish', "Lesson");
        }

        // Quizzes under topic
        $quizzes = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_type='tutor_quiz' AND post_parent=%d", $topic->ID)
        );

        foreach ($quizzes as $quiz) {
            duplicate_quiz_full_log($quiz->ID, $new_topic_id);
        }
    }

    return $new_course_id;
}

// =====================
// ADMIN PAGE
// =====================
add_action('admin_menu', function() {
    add_menu_page(
        'Tutor LMS Cloner',
        'Course Cloner',
        'manage_options',
        'tutor-course-cloner',
        'tutor_course_cloner_page',
        'dashicons-welcome-learn-more',
        25
    );
});

function tutor_course_cloner_page() {
    if (!current_user_can('manage_options')) wp_die('Access denied');

    // Handle form submission
    if (isset($_POST['tutor_clone_course_nonce']) && wp_verify_nonce($_POST['tutor_clone_course_nonce'], 'tutor_clone_course')) {
        $original_course_id = intval($_POST['original_course_id']);
        if ($original_course_id) {

            // Capture output for logging
            ob_start();
            echo "<h2>Cloning course ID {$original_course_id}</h2>";

            $new_course_id = duplicate_tutor_course_modular_with_log($original_course_id);

            $log = ob_get_clean();

            if ($new_course_id) {
                echo '<div class="notice notice-success"><p>✅ Course cloned successfully! New Course ID: ' . $new_course_id . '</p></div>';
                echo '<pre style="background:#f4f4f4;padding:10px;border:1px solid #ccc;">' . $log . '</pre>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Course duplication failed.</p></div>';
            }
        }
    }

    // Fetch all courses
    $courses = get_posts([
        'post_type' => 'courses',
        'posts_per_page' => -1,
        'post_status' => ['publish', 'draft'],
        'orderby' => 'title',
        'order' => 'ASC'
    ]);

    ?>
    <div class="wrap">
        <h1>Tutor LMS Course Cloner</h1>
        <form method="post">
            <?php wp_nonce_field('tutor_clone_course', 'tutor_clone_course_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="original_course_id">Select Course to Duplicate</label></th>
                    <td>
                        <select name="original_course_id" id="original_course_id">
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo $course->ID; ?>"><?php echo esc_html($course->post_title); ?> (ID: <?php echo $course->ID; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button('Duplicate Course'); ?>
        </form>
    </div>
    <?php
}
